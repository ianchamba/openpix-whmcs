<?php

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

// Função para retornar resposta em JSON com código HTTP adequado
function returnJsonResponse($message, $success = true, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
    } else {
        echo json_encode([
            'error' => true,
            'message' => $message
        ]);
    }
    exit;
}

$gatewayModuleName = 'openpix';
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Verifica se o módulo de gateway está ativo
if (!$gatewayParams['type']) {
    error_log("Erro: Módulo de gateway inativo.");
    returnJsonResponse("Módulo de gateway inativo", false, 400);
}

// Recupera o valor da apiKey configurada no openpix_config
$expectedApiKey = trim($gatewayParams['apiKey']);

// Obtém todos os cabeçalhos enviados no webhook
$headers = getallheaders();

// Recupera a API Key do header ou do parâmetro ?authorization=
$receivedApiKey = '';
if (!empty($headers['X-Openpix-Authorization'])) {
    $receivedApiKey = trim($headers['X-Openpix-Authorization']);
} elseif (!empty($_GET['authorization'])) {
    $receivedApiKey = trim($_GET['authorization']);
}

if (empty($receivedApiKey) || $receivedApiKey !== $expectedApiKey) {
    error_log("Erro: Webhook recebido com chave API inválida. Esperado: {$expectedApiKey}, recebido: {$receivedApiKey}");
    returnJsonResponse("Unauthorized", false, 401);
}

// Lê e decodifica o JSON de entrada
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Erro ao decodificar JSON no webhook: " . json_last_error_msg() . " | Payload: {$rawInput}");
    returnJsonResponse("Erro ao decodificar JSON", false, 400);
}

error_log("Dados recebidos no webhook: " . print_r($input, true));

// Função para extrair o Invoice ID do array additionalInfo
function getInvoiceIdFromCharge(array $charge): ?string {
    if (isset($charge['additionalInfo']) && is_array($charge['additionalInfo'])) {
        foreach ($charge['additionalInfo'] as $info) {
            if (isset($info['key'], $info['value']) && $info['key'] === 'Invoice') {
                return $info['value'];
            }
        }
    }
    return null;
}

// Função para verificar se a fatura existe
function invoiceExists($invoiceId) {
    $result = select_query('tblinvoices', 'id', ['id' => $invoiceId]);
    return mysql_num_rows($result) > 0;
}

$event = $input['event'] ?? '';
$charge = $input['charge'] ?? [];

// Extrai o invoiceId
$invoiceId = getInvoiceIdFromCharge($charge);
if (!$invoiceId) {
    error_log("Erro: Invoice ID não encontrado no evento {$event}.");
    returnJsonResponse("Invoice ID não encontrado", false, 400);
}

// Verifica se a fatura existe
if (!invoiceExists($invoiceId)) {
    error_log("Erro: Fatura ID {$invoiceId} não existe no sistema.");
    returnJsonResponse("Fatura não encontrada no sistema", false, 404);
}

if ($event === 'OPENPIX:CHARGE_EXPIRED') {
    // Verifica status atual da fatura
    $result = select_query('tblinvoices', 'status', ['id' => $invoiceId]);
    $data = mysql_fetch_assoc($result);
    if ($data && strtolower($data['status']) === 'paid') {
        error_log("Fatura ID {$invoiceId} já está paga. Nenhuma ação necessária.");
        returnJsonResponse("Fatura já paga", true, 200);
    }

    // Cancela a fatura
    update_query('tblinvoices', ['status' => 'Cancelled'], ['id' => $invoiceId]);
    run_hook('InvoiceCancelled', ['invoiceid' => $invoiceId]);
    logTransaction($gatewayModuleName, $input, 'Fatura cancelada por expiração do pagamento.');
    error_log("Fatura ID {$invoiceId} cancelada por expiração.");
    returnJsonResponse("Fatura cancelada com sucesso", true, 200);
}

// Para eventos diferentes de expiração, tratamos pagamento
$transactionId = $charge['transactionID'] ?? '';
$amountPaid    = isset($charge['value']) ? $charge['value'] / 100 : 0;
$paymentStatus = $charge['status'] ?? '';

error_log("Processando webhook - Evento: {$event}, Invoice ID: {$invoiceId}, Transaction ID: {$transactionId}, Valor: {$amountPaid}, Status: {$paymentStatus}");

if ($event === 'OPENPIX:CHARGE_COMPLETED') {
    // Verifica se a fatura já está paga antes de processar
    $result = select_query('tblinvoices', 'status', ['id' => $invoiceId]);
    $data = mysql_fetch_assoc($result);
    
    if ($data && strtolower($data['status']) === 'paid') {
        error_log("Fatura ID {$invoiceId} já está paga. Nenhuma ação necessária.");
        logTransaction($gatewayModuleName, $input, 'Fatura já paga. Ignorando pagamento duplicado.');
        returnJsonResponse("Fatura já paga", false, 409); // Use 409 Conflict para indicar que já foi processada
    } else {
        addInvoicePayment($invoiceId, $transactionId, $amountPaid, 0, $gatewayModuleName);
        logTransaction($gatewayModuleName, $input, 'Pagamento confirmado');
        error_log("Pagamento confirmado para fatura ID: {$invoiceId}.");
        returnJsonResponse("Pagamento confirmado", true, 200);
    }
} else {
    logTransaction($gatewayModuleName, $input, 'Pagamento pendente ou falhou');
    error_log("Pagamento pendente/falhou para fatura ID: {$invoiceId}. Status: {$paymentStatus}");
    returnJsonResponse("Pagamento pendente ou falhou", true, 200);
}
