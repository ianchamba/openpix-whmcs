<?php
if (!defined("WHMCS")) {
    die("Acesso restrito.");
}

require_once __DIR__ . '/../openpix/vendor/autoload.php';

use OpenPix\PhpSdk\Client;

function OpenPixHooksLog($message) {
    try {
        localAPI('LogActivity', [
            'description' => "[OpenPix] {$message}",
        ]);
    } catch (\Throwable $e) {
        // Uma falha de log nunca deve interromper a publicação da fatura.
    }
}

function OpenPixHooksGetInvoiceData($invoiceId) {
    $result = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);

    if (($result['result'] ?? '') !== 'success') {
        OpenPixHooksLog("Fatura #{$invoiceId} não encontrada. Nenhuma ação realizada.");
        return null;
    }

    return $result;
}

function OpenPixHooksLoadGatewayFunctions() {
    if (!defined('ROOTDIR')) {
        OpenPixHooksLog('ROOTDIR não está disponível para carregar as funções do gateway.');
        return false;
    }

    if (!function_exists('getGatewayVariables')) {
        require_once ROOTDIR . '/includes/gatewayfunctions.php';
    }

    return function_exists('getGatewayVariables');
}

function OpenPixHooksLoadGatewayModule() {
    if (!OpenPixHooksLoadGatewayFunctions()) {
        return false;
    }

    require_once ROOTDIR . '/modules/gateways/openpix.php';

    if (!function_exists('OpenPixGetExistingCharge') || !function_exists('OpenPixProcessNewCharge')) {
        OpenPixHooksLog('As funções de geração do gateway não puderam ser carregadas.');
        return false;
    }

    return true;
}

function OpenPixHooksGetClientDetails($clientId, $invoiceId) {
    $result = localAPI('GetClientsDetails', [
        'clientid' => $clientId,
        'stats' => false,
    ]);

    if (($result['result'] ?? '') !== 'success') {
        OpenPixHooksLog("Não foi possível obter o cliente da fatura #{$invoiceId}.");
        return null;
    }

    $clientDetails = isset($result['client']) && is_array($result['client'])
        ? $result['client']
        : $result;

    if (empty($clientDetails['fullname'])) {
        $clientDetails['fullname'] = trim(
            ($clientDetails['firstname'] ?? '') . ' ' . ($clientDetails['lastname'] ?? '')
        );
    }

    if (!isset($clientDetails['customfields']) || !is_array($clientDetails['customfields'])) {
        $clientDetails['customfields'] = [];
    }

    return $clientDetails;
}

function OpenPixHooksGenerateInvoiceCharge($invoiceId) {
    $invoiceId = (int) $invoiceId;

    if ($invoiceId <= 0) {
        OpenPixHooksLog('InvoiceCreated recebido sem um ID de fatura válido.');
        return false;
    }

    $invoiceData = OpenPixHooksGetInvoiceData($invoiceId);
    if (!$invoiceData) {
        return false;
    }

    if (($invoiceData['paymentmethod'] ?? '') !== 'openpix') {
        return false;
    }

    OpenPixHooksLog("InvoiceCreated acionado para a fatura OpenPix #{$invoiceId}.");

    if (strcasecmp((string) ($invoiceData['status'] ?? ''), 'Unpaid') !== 0) {
        $status = $invoiceData['status'] ?? 'desconhecido';
        OpenPixHooksLog("Fatura #{$invoiceId} está com status {$status}. Ignorando geração automática.");
        return false;
    }

    $amount = (float) ($invoiceData['balance'] ?? 0);
    if ($amount <= 0) {
        OpenPixHooksLog("Fatura #{$invoiceId} não possui saldo pendente. Ignorando.");
        return false;
    }

    try {
        if (!OpenPixHooksLoadGatewayModule()) {
            return false;
        }

        if (OpenPixGetExistingCharge($invoiceData)) {
            OpenPixHooksLog("Fatura #{$invoiceId} já possui cobrança Pix. Nenhuma duplicação será criada.");
            return true;
        }

        $gatewayParams = getGatewayVariables('openpix');
        if (empty($gatewayParams['type']) || empty($gatewayParams['apiKey'])) {
            OpenPixHooksLog("Gateway OpenPix inativo ou sem API Key para a fatura #{$invoiceId}.");
            return false;
        }

        $clientDetails = OpenPixHooksGetClientDetails((int) $invoiceData['userid'], $invoiceId);
        if (!$clientDetails) {
            return false;
        }

        $params = array_merge($gatewayParams, [
            'invoiceid' => $invoiceId,
            'amount' => number_format($amount, 2, '.', ''),
            'clientdetails' => $clientDetails,
        ]);

        $chargeData = OpenPixProcessNewCharge($params);
        $created = !empty($chargeData['paymentLinkID']) && !empty($chargeData['brCode']);

        if ($created) {
            OpenPixHooksLog("Cobrança Pix garantida automaticamente para a fatura #{$invoiceId}.");
            return true;
        }

        OpenPixHooksLog("Não foi possível gerar automaticamente a cobrança da fatura #{$invoiceId}.");
        return false;
    } catch (\Throwable $e) {
        OpenPixHooksLog("Erro inesperado ao gerar a cobrança da fatura #{$invoiceId}: " . $e->getMessage());
        return false;
    }
}

function OpenPixHooksValidateInvoiceBelongsToGateway($invoiceId) {
    $result = OpenPixHooksGetInvoiceData($invoiceId);

    if (!$result) {
        return false;
    }
    
    if ($result['paymentmethod'] !== 'openpix') {
        localAPI('LogActivity', ['description' => "[OpenPix] Fatura #{$invoiceId} não pertence ao gateway OpenPix. Nenhuma ação realizada."]);
        return false;
    }
    
    return true;
}

function OpenPixHooksGetGatewayApiKey() {
    if (!OpenPixHooksLoadGatewayFunctions()) {
        return null;
    }

    $gatewayParams = getGatewayVariables("openpix");
    
    if (empty($gatewayParams['apiKey'])) {
        localAPI('LogActivity', ['description' => "[OpenPix] ERRO: Chave API não encontrada através do WHMCS."]);
        return null;
    }
    
    return $gatewayParams['apiKey'];
}

function OpenPixHooksDeleteCharge($invoiceId, $apiKey) {
    try {
        $client = Client::create($apiKey);
        $result = $client->charges()->delete((string) $invoiceId);
        
        localAPI('LogActivity', ['description' => "[OpenPix] Sucesso ao cancelar cobrança via SDK para fatura #{$invoiceId}"]);
        localAPI('LogActivity', ['description' => "[OpenPix] Resposta do SDK: " . json_encode($result, JSON_UNESCAPED_UNICODE)]);
        
        return [
            'success' => true,
            'data' => $result
        ];
        
    } catch (\Throwable $e) {
        localAPI('LogActivity', ['description' => "[OpenPix] Erro no SDK ao cancelar: " . $e->getMessage()]);
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function OpenPixHooksProcessCancelResponse($invoiceId, $result) {
    if ($result['success']) {
        localAPI('LogActivity', ['description' => "[OpenPix] Fatura #{$invoiceId} cancelada com sucesso na OpenPix."]);
        return true;
    } else {
        localAPI('LogActivity', ['description' => "[OpenPix] ERRO: Falha ao cancelar a fatura #{$invoiceId}. Erro: " . $result['error']]);
        return false;
    }
}

function OpenPixHooksCancelInvoice($invoiceId) {
    localAPI('LogActivity', ['description' => "[OpenPix] Hook 'InvoiceCancelled' acionado para a fatura #{$invoiceId}"]);
    
    if (!OpenPixHooksValidateInvoiceBelongsToGateway($invoiceId)) {
        return;
    }
    
    $apiKey = OpenPixHooksGetGatewayApiKey();
    if (!$apiKey) {
        return;
    }
    
    $result = OpenPixHooksDeleteCharge($invoiceId, $apiKey);
    OpenPixHooksProcessCancelResponse($invoiceId, $result);
}

add_hook('InvoiceCancelled', 1, function ($vars) {
    OpenPixHooksCancelInvoice($vars['invoiceid']);
});

add_hook('InvoiceCreated', 1, function ($vars) {
    try {
        OpenPixHooksGenerateInvoiceCharge($vars['invoiceid'] ?? 0);
    } catch (\Throwable $e) {
        OpenPixHooksLog('Falha não tratada no hook InvoiceCreated: ' . $e->getMessage());
    }
});
?>
