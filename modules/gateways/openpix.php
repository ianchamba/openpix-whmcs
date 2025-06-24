<?php

if (!defined("WHMCS")) {
    error_log("Erro: Tentativa de acesso direto ao arquivo do gateway.");
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function pix_MetaData() {
    return [
        'DisplayName' => 'PIX Payment Gateway',
        'APIVersion' => '1.1',
    ];
}

function openpix_config() {
    return [
        "FriendlyName" => [
            "Type" => "System",
            "Value" => "PIX",
        ],
        "apiEndpoint" => [
            "Type" => "System",
            "Value" => "https://api.openpix.com.br/api/v1/charge",
        ],
        "apiKey" => [
            "FriendlyName" => "Chave API",
            "Type" => "password",
            "Size" => "50",
            "Description" => "Chave de acesso à API do PIX.",
        ],
        "Icon" => [
            "Type" => "System",
            "Value" => "openpix",
        ],
        "_link" => function($params) {
            if (!defined('WHMCS')) {
                return []; // Impede acesso externo
            }
            return [
                "apiKey" => $params['apiKey'] ?? null,
            ];
        }
    ];
}

function openpix_link($params) {
    $invoiceId = $params['invoiceid'];

    // Verifica o status da fatura antes de exibir o QR Code
    $invoiceData = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if ($invoiceData->status === 'Paid') {
        error_log("Fatura #{$invoiceId} já está paga. Nenhuma ação necessária.");
        return '<p>Esta fatura já está marcada como paga.</p>';
    }

    $amount = str_replace([',', '.'], '', $params['amount']);
    $callbackUrl = $params['systemurl'] . 'modules/gateways/callback/openpix.php?invoiceid=' . $invoiceId;

    // Recuperar o(s) nome(s) do(s) produto(s) da fatura
    $products = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->pluck('description')
        ->toArray();

    // Formata o comentário com o nome dos produtos
    $comment = implode(', ', $products);
    
    $comment = preg_replace('/\x{1F1E7}\x{1F1F7}/u', '', $comment);
    $comment = preg_replace('/\x{1F1FA}\x{1F1F8}/u', '', $comment);
    $comment = preg_replace('/\s+/', ' ', $comment);
    $comment = trim($comment);

    // Verificar se já existe um paymentLinkID e código Pix salvos nos novos campos
    if (!empty($invoiceData->paymentLinkID) && !empty($invoiceData->brCode)) {
        error_log("Fatura #{$invoiceId} já possui um QR Code associado.");
        $existingPaymentLinkID = $invoiceData->paymentLinkID;
        $existingBrCode = $invoiceData->brCode;
        $qrCodeUrl = "https://api.openpix.com.br/openpix/charge/brcode/image/{$existingPaymentLinkID}.png?size=1024";
        $brCode = $existingBrCode;
    } else {

        $taxId = '';
        foreach ($params['clientdetails']['customfields'] as $customfield) {
            if ($customfield['id'] == 41) {
                $taxId = $customfield['value'];
                break;
            }
        }
        
        if (strlen($comment) > 135) {
            $comment = substr($comment, 0, 135);
        }

        $data = [
            'correlationID' => $invoiceId,
            'value' => $amount,
            'comment' => $comment,
            'customer' => [
                'name' => $params['clientdetails']['fullname'],
                'taxID' => $taxId,
                'email' => $params['clientdetails']['email'],
                'phone' => $params['clientdetails']['phonenumber'],
            ],
            'additionalInfo' => [
                ['key' => 'Invoice', 'value' => $invoiceId],
                ['key' => 'Order', 'value' => $invoiceId],
            ],
        ];

        error_log("Dados enviados para API Openpix: " . json_encode($data));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.openpix.com.br/api/v1/charge");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $params['apiKey'],
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        if ($response === false) {
            error_log("Erro ao comunicar com a API Openpix: " . curl_error($ch));
        } else {
            error_log("Resposta da API Openpix: " . $response);
        }
        curl_close($ch);

        $responseArray = json_decode($response, true);
        $paymentLinkID = $responseArray['charge']['paymentLinkID'] ?? null;
        $brCode = $responseArray['charge']['brCode'] ?? null;

        if ($paymentLinkID && $brCode) {
            error_log("QR Code gerado com sucesso para a fatura #{$invoiceId}. PaymentLinkID: {$paymentLinkID}");
            $qrCodeUrl = "https://api.openpix.com.br/openpix/charge/brcode/image/{$paymentLinkID}.png?size=300";
            Capsule::table('tblinvoices')->where('id', $invoiceId)->update([
                'paymentLinkID' => $paymentLinkID,
                'brCode' => $brCode,
            ]);
            
            // Somente executa o hook quando a resposta da API contém um brCode válido
            run_hook('OpenpixInvoiceGenerated', ['invoiceId' => $invoiceId]);
            error_log("Hook 'OpenpixInvoiceGenerated' executado para a fatura #{$invoiceId}");
        } else {
            error_log("Erro ao gerar QR Code. Resposta da API: " . json_encode($responseArray));
            $qrCodeUrl = '#';
        }
    }

    // Construção do HTML com QR Code, verificação automática de pagamento e estilo ajustado
$htmlOutput = '
<style>
.openpix-container {
    width: 100% !important;
    max-width: 350px !important;
    margin: 0 auto !important;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif !important;
    background-color: #ffffff !important;
    border-radius: 12px !important;
    padding: 20px !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08) !important;
}

.qrcode-wrapper {
    text-align: center !important;
    background-color: #ffffff !important;
    border-radius: 8px !important;
    padding: 5px !important;
    margin-bottom: 20px !important;
    box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05) !important;
}

.qrcode-wrapper img {
    display: block !important;
    max-width: 100% !important;
    width: auto !important;
    height: auto !important;
    margin: 0 auto !important;
}

.pix-instructions {
    font-size: 14px !important;
    color: #424242 !important;
    text-align: center !important;
    margin-bottom: 15px !important;
    line-height: 1.4 !important;
}

.pix-code {
    margin-bottom: 15px !important;
}

.pix-code textarea {
    width: 100% !important;
    padding: 14px !important;
    border: 1px solid #e0e0e0 !important;
    border-radius: 8px !important;
    background-color: #f9f9f9 !important;
    font-family: monospace !important;
    font-size: 13px !important;
    min-height: 65px !important;
    resize: none !important;
    overflow: auto !important;
    box-sizing: border-box !important;
    margin-bottom: 10px !important;
}

.copy-button {
    width: 100% !important;
    background-color: #0066FF !important;
    color: white !important;
    border: none !important;
    border-radius: 6px !important;
    padding: 10px 12px !important;
    cursor: pointer !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    transition: background-color 0.2s ease !important;
    text-align: center !important;
}

.copy-button:hover {
    background-color: #0052cc !important;
}

.copy-message {
    display: none !important;
    text-align: center !important;
    color: #00a152 !important;
    font-size: 14px !important;
    margin-top: 8px !important;
    font-weight: 500 !important;
}

.payment-info {
    background-color: #f5f9ff !important;
    padding: 12px !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    color: #2c5282 !important;
    border-left: 4px solid #4299e1 !important;
    margin-top: 15px !important;
}

.payment-info p {
    margin: 0 !important;
    line-height: 1.4 !important;
    color: #2c5282 !important;
}

.payment-status {
    margin-top: 15px !important;
    padding: 12px !important;
    border-radius: 8px !important;
    font-size: 14px !important;
    text-align: center !important;
    font-weight: 500 !important;
}

.status-waiting {
    background-color: #FFF8E1 !important;
    color: #F57F17 !important;
    border-left: 4px solid #FFB300 !important;
}

.status-completed {
    background-color: #E8F5E9 !important;
    color: #2E7D32 !important;
    border-left: 4px solid #4CAF50 !important;
}

.status-expired {
    background-color: #FFEBEE !important;
    color: #C62828 !important;
    border-left: 4px solid #EF5350 !important;
}

.status-spinner {
    display: inline-block !important;
    width: 12px !important;
    height: 12px !important;
    border: 2px solid currentColor !important;
    border-right-color: transparent !important;
    border-radius: 50% !important;
    animation: spin 0.75s linear infinite !important;
    margin-right: 8px !important;
    vertical-align: middle !important;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<div class="openpix-container">
    <div class="qrcode-wrapper">
        <img src="' . htmlspecialchars($qrCodeUrl) . '" alt="QR Code de pagamento">
    </div>
    
    <p class="pix-instructions">Escaneie o QR Code ou copie o código abaixo:</p>
    
    <div class="pix-code">
        <textarea id="pixCode" readonly>' . htmlspecialchars($brCode ?? 'Erro ao obter código') . '</textarea>
        <button onclick="copyPixCode()" id="copyBtn" class="copy-button">Copiar Código PIX</button>
    </div>
    
    <div id="copyMessage" class="copy-message">
        Código copiado com sucesso!
    </div>
    
    <div id="paymentStatus" class="payment-status status-waiting">
        <span class="status-spinner"></span> Aguardando pagamento...
    </div>
    
    <div class="payment-info">
        <p><strong>Importante:</strong> O pagamento será confirmado automaticamente após ser processado.</p>
    </div>
</div>

<script>
function copyPixCode() {
    const pixCodeElement = document.getElementById("pixCode");
    pixCodeElement.select();
    document.execCommand("copy");
    
    const copyBtn = document.getElementById("copyBtn");
    const copyMessage = document.getElementById("copyMessage");
    
    copyBtn.innerHTML = "Copiado!";
    copyBtn.style.backgroundColor = "#00a152";
    copyMessage.style.display = "block";
    
    setTimeout(function() {
        copyBtn.innerHTML = "Copiar Código PIX";
        copyBtn.style.backgroundColor = "#0066FF";
        copyMessage.style.display = "none";
    }, 2000);
    
    // Para compatibilidade com navegadores modernos
    if (navigator.clipboard) {
        navigator.clipboard.writeText(pixCodeElement.value)
            .catch(err => console.error("Erro ao copiar: ", err));
    }
}

// Função para verificar o status do pagamento usando AJAX para o servidor
let checkCount = 0;
const maxChecks = 720; // 1 hora (720 checks de 5 segundos)
let paymentCompleted = false;

function checkPaymentStatus() {
    if (paymentCompleted || checkCount >= maxChecks) {
        return;
    }
    
    checkCount++;
    
    const statusElement = document.getElementById("paymentStatus");
    const correlationID = "' . $invoiceId . '";
    
    // Cria um timestamp para evitar cache
    const timestamp = new Date().getTime();
    
    // Usando AJAX para fazer a requisição para um endpoint no servidor
    const xhr = new XMLHttpRequest();
    xhr.open("POST", "modules/gateways/openpix/verify.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.status === "COMPLETED") {
                        paymentCompleted = true;
                        statusElement.className = "payment-status status-completed";
                        statusElement.innerHTML = "✅ Pagamento confirmado! Atualizando...";
                        
                        // Recarrega a página após 3 segundos quando o pagamento for confirmado
                        setTimeout(function() {
                            window.location.reload();
                        }, 3000);
                    } 
                    else if (response.status === "ACTIVE") {
                        statusElement.className = "payment-status status-waiting";
                        statusElement.innerHTML = "<span class=\"status-spinner\"></span> Aguardando pagamento...";
                    } 
                    else {
                        statusElement.className = "payment-status status-expired";
                        statusElement.innerHTML = "⚠️ Pagamento expirado ou cancelado";
                        paymentCompleted = true; // Para parar as verificações
                    }
                } catch (error) {
                    console.error("Erro ao processar resposta:", error);
                }
            } else {
                console.error("Erro na requisição:", xhr.status);
            }
            
            // Continua verificando mesmo em caso de erro
            if (!paymentCompleted) {
                setTimeout(checkPaymentStatus, 5000); // Verificar novamente em 5 segundos
            }
        }
    };
    
    xhr.send("correlationID=" + encodeURIComponent(correlationID));
}

// Inicia a verificação assim que a página carregar
document.addEventListener("DOMContentLoaded", function() {
    // Aguardar 2 segundos antes da primeira verificação
    setTimeout(checkPaymentStatus, 2000);
});
</script>';

return $htmlOutput;
}
