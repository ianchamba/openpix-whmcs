<?php

namespace WHMCS\Database {
    class Capsule {
        public static $invoiceRow = [];

        public static function table($table) {
            return new FakeQuery($table);
        }
    }

    class FakeQuery {
        private $table;
        private $invoiceId;
        private $requiresIncompleteCharge = false;

        public function __construct($table) {
            $this->table = $table;
        }

        public function where($column, $operator = null, $value = null) {
            if ($column instanceof \Closure) {
                $this->requiresIncompleteCharge = true;
                $column($this);
                return $this;
            }

            if ($column === 'id') {
                $this->invoiceId = func_num_args() === 2 ? $operator : $value;
            }

            return $this;
        }

        public function whereNull($column) {
            return $this;
        }

        public function orWhere($column, $value) {
            return $this;
        }

        public function orWhereNull($column) {
            return $this;
        }

        public function first() {
            if ($this->table !== 'tblinvoices' || !$this->matchesInvoice()) {
                return null;
            }

            return (object) Capsule::$invoiceRow;
        }

        public function update(array $data) {
            if ($this->table !== 'tblinvoices' || !$this->matchesInvoice()) {
                return 0;
            }

            $hasCompleteCharge = !empty(Capsule::$invoiceRow['paymentLinkID'])
                && !empty(Capsule::$invoiceRow['brCode']);

            if ($this->requiresIncompleteCharge && $hasCompleteCharge) {
                return 0;
            }

            Capsule::$invoiceRow = array_merge(Capsule::$invoiceRow, $data);
            return 1;
        }

        private function matchesInvoice() {
            return (int) (Capsule::$invoiceRow['id'] ?? 0) === (int) $this->invoiceId;
        }
    }
}

namespace OpenPix\PhpSdk {
    class Client {
        public static $createCalls = 0;
        public static $payloads = [];
        public static $returnExistingFlags = [];
        public static $chargesByCorrelation = [];

        public static function create(string $appId, string $baseUri = ''): self {
            return new self();
        }

        public function charges(): FakeCharges {
            return new FakeCharges();
        }
    }

    class FakeCharges {
        public function create(array $data, bool $returnExisting = true): array {
            Client::$createCalls++;
            Client::$payloads[] = $data;
            Client::$returnExistingFlags[] = $returnExisting;

            $correlationId = (string) $data['correlationID'];
            if (!isset(Client::$chargesByCorrelation[$correlationId])) {
                Client::$chargesByCorrelation[$correlationId] = [
                    'charge' => [
                        'paymentLinkID' => 'link-' . $correlationId,
                    ],
                    'brCode' => 'brcode-' . $correlationId,
                ];
            }

            return Client::$chargesByCorrelation[$correlationId];
        }

        public function delete(string $id): array {
            return ['status' => 'OK', 'id' => $id];
        }
    }
}

namespace {
    define('WHMCS', true);
    define('ROOTDIR', dirname(__DIR__));

    $GLOBALS['openpix_test_hooks'] = [];
    $GLOBALS['openpix_test_logs'] = [];
    $GLOBALS['openpix_test_custom_hooks'] = [];
    $GLOBALS['openpix_test_invoice'] = [];

    function add_hook($name, $priority, $callback) {
        $GLOBALS['openpix_test_hooks'][$name][] = $callback;
    }

    function localAPI($command, array $params = []) {
        if ($command === 'GetInvoice') {
            return $GLOBALS['openpix_test_invoice'];
        }

        if ($command === 'GetClientsDetails') {
            return [
                'result' => 'success',
                'client' => [
                    'firstname' => 'Cliente',
                    'lastname' => 'Teste',
                    'fullname' => 'Cliente Teste',
                    'email' => 'cliente@example.com',
                    'phonenumber' => '11999999999',
                    'customfields' => [
                        ['id' => 41, 'value' => '12345678909'],
                    ],
                ],
            ];
        }

        if ($command === 'LogActivity') {
            $GLOBALS['openpix_test_logs'][] = $params['description'] ?? '';
            return ['result' => 'success'];
        }

        if ($command === 'SendEmail') {
            return ['result' => 'success'];
        }

        return ['result' => 'error'];
    }

    function getGatewayVariables($module) {
        return [
            'type' => 'gateway',
            'paymentmethod' => $module,
            'apiKey' => 'test-app-id',
            'taxIdFieldId' => 41,
            'enableOverdue' => 'no',
            'enableDiscount' => 'no',
        ];
    }

    function run_hook($name, array $vars = []) {
        $GLOBALS['openpix_test_custom_hooks'][] = [
            'name' => $name,
            'vars' => $vars,
        ];
    }

    function openPixTestAssert($condition, $message) {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function openPixTestInvoice($status, $paymentMethod = 'openpix') {
        return [
            'result' => 'success',
            'invoiceid' => 3231,
            'userid' => 65,
            'duedate' => '2026-07-31',
            'status' => $status,
            'paymentmethod' => $paymentMethod,
            'total' => '19.90',
            'balance' => '19.90',
            'items' => [
                'item' => [
                    [
                        'type' => 'Hosting',
                        'description' => 'Plano de hospedagem',
                    ],
                ],
            ],
        ];
    }

    require ROOTDIR . '/includes/hooks/openpix.php';

    openPixTestAssert(isset($GLOBALS['openpix_test_hooks']['InvoiceCreated'][0]), 'InvoiceCreated não foi registrado.');
    openPixTestAssert(isset($GLOBALS['openpix_test_hooks']['InvoiceCancelled'][0]), 'InvoiceCancelled deixou de ser registrado.');

    $invoiceCreated = $GLOBALS['openpix_test_hooks']['InvoiceCreated'][0];

    \WHMCS\Database\Capsule::$invoiceRow = ['id' => 3231, 'paymentLinkID' => '', 'brCode' => ''];
    $GLOBALS['openpix_test_invoice'] = openPixTestInvoice('Draft');
    $invoiceCreated(['invoiceid' => 3231]);
    openPixTestAssert(\OpenPix\PhpSdk\Client::$createCalls === 0, 'Fatura Draft não deve criar cobrança.');

    $GLOBALS['openpix_test_invoice'] = openPixTestInvoice('Unpaid', 'paypal');
    $invoiceCreated(['invoiceid' => 3231]);
    openPixTestAssert(\OpenPix\PhpSdk\Client::$createCalls === 0, 'Outro gateway não deve criar cobrança OpenPix.');

    $GLOBALS['openpix_test_invoice'] = openPixTestInvoice('Paid');
    $invoiceCreated(['invoiceid' => 3231]);
    openPixTestAssert(\OpenPix\PhpSdk\Client::$createCalls === 0, 'Fatura paga não deve criar cobrança.');

    \WHMCS\Database\Capsule::$invoiceRow = [
        'id' => 3231,
        'paymentLinkID' => 'existing-link',
        'brCode' => 'existing-brcode',
    ];
    $GLOBALS['openpix_test_invoice'] = openPixTestInvoice('Unpaid');
    $invoiceCreated(['invoiceid' => 3231]);
    openPixTestAssert(\OpenPix\PhpSdk\Client::$createCalls === 0, 'Cobrança persistida não deve chamar a API.');

    \WHMCS\Database\Capsule::$invoiceRow = ['id' => 3231, 'paymentLinkID' => '', 'brCode' => ''];
    $invoiceCreated(['invoiceid' => 3231]);

    openPixTestAssert(\OpenPix\PhpSdk\Client::$createCalls === 1, 'Nova fatura deve chamar a API uma vez.');
    openPixTestAssert(\OpenPix\PhpSdk\Client::$returnExistingFlags[0] === true, 'return_existing deve estar habilitado.');
    openPixTestAssert(\OpenPix\PhpSdk\Client::$payloads[0]['correlationID'] === '3231', 'correlationID deve ser o ID da fatura.');
    openPixTestAssert(\OpenPix\PhpSdk\Client::$payloads[0]['value'] === 1990, 'O saldo deve ser convertido para centavos.');
    openPixTestAssert(\OpenPix\PhpSdk\Client::$payloads[0]['customer']['taxID'] === '12345678909', 'CPF/CNPJ não foi enviado.');
    openPixTestAssert(\WHMCS\Database\Capsule::$invoiceRow['paymentLinkID'] === 'link-3231', 'paymentLinkID não foi persistido.');
    openPixTestAssert(\WHMCS\Database\Capsule::$invoiceRow['brCode'] === 'brcode-3231', 'brCode não foi persistido.');
    openPixTestAssert(count($GLOBALS['openpix_test_custom_hooks']) === 1, 'OpenpixInvoiceGenerated deve executar uma vez.');

    $invoiceCreated(['invoiceid' => 3231]);
    openPixTestAssert(\OpenPix\PhpSdk\Client::$createCalls === 1, 'Segunda execução não deve duplicar a cobrança.');
    openPixTestAssert(count($GLOBALS['openpix_test_custom_hooks']) === 1, 'Segunda execução não deve duplicar a notificação.');

    OpenPixSaveChargeData(3231, 'link-3231', 'brcode-3231');
    openPixTestAssert(count($GLOBALS['openpix_test_custom_hooks']) === 1, 'Persistência repetida não deve duplicar a notificação.');

    echo "InvoiceCreated hook tests passed.\n";
}
