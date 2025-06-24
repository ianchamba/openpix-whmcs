<?php
// Configurações iniciais
header('Content-Type: application/json');

// Verifica se o correlationID foi enviado
if (empty($_POST['correlationID'])) {
    echo json_encode(['error' => 'correlationID não fornecido']);
    exit;
}

// Recupera o correlationID
$correlationID = $_POST['correlationID'];

// Configurações da API OpenPix
// Importante: Estas configurações devem estar em um arquivo de configuração separado
// ou usando variáveis de ambiente em um ambiente de produção
$apiKey = getenv('OPENPIX_API_KEY') ?: ''; // Substitua pela sua API key real ou use variável de ambiente

// URL corrigida da API OpenPix - correlationID como parte do caminho
$apiUrl = "https://api.openpix.com.br/api/v1/charge/" . urlencode($correlationID);

// Inicializa cURL
$ch = curl_init();

// Configura as opções do cURL
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: " . $apiKey,
        "Accept: application/json"
    ]
]);

// Executa a requisição
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Verifica erros de cURL
if (curl_errno($ch)) {
    echo json_encode([
        'error' => 'Erro na requisição cURL: ' . curl_error($ch),
        'status' => 'ERROR'
    ]);
    curl_close($ch);
    exit;
}

// Fecha a conexão cURL
curl_close($ch);

// Verifica código de resposta HTTP
if ($httpCode != 200) {
    echo json_encode([
        'error' => 'Erro na API OpenPix. Código HTTP: ' . $httpCode,
        'status' => 'ERROR'
    ]);
    exit;
}

// Processa a resposta
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'error' => 'Erro ao decodificar resposta JSON',
        'status' => 'ERROR'
    ]);
    exit;
}

// Extrai o status do pagamento
$result = [];
if (isset($data['charge']['status'])) {
    $result['status'] = $data['charge']['status'];
} else {
    $result['status'] = 'UNKNOWN';
    $result['error'] = 'Status não encontrado na resposta';
}

// Você pode incluir mais informações da resposta se necessário
// $result['additionalInfo'] = ...

// Retorna o resultado como JSON
echo json_encode($result);
exit;
