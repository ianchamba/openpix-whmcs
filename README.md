---

# Módulo de Pagamento OpenPix para WHMCS

Este é um módulo gratuito para integrar o método de pagamento OpenPix ao WHMCS. Ele permite que clientes façam pagamentos via Pix utilizando a API da OpenPix.

## Funcionalidades

- Integração com a API OpenPix
- Geração de cobranças Pix diretamente no WHMCS
- Webhook para confirmação automática de pagamentos
- Simplicidade e facilidade na configuração

## Instalação

1. Acesse o painel administrativo do WHMCS e vá até **Configurações > Módulos de Pagamento**.

2. Ative o módulo OpenPix e insira suas credenciais da OpenPix.

3. Configure o webhook no painel da OpenPix apontando para:  
`https://seu-dominio.com/modules/gateways/callback/openpix.php`

## Configuração da API

Certifique-se de ter sua chave de API da OpenPix. Você pode obtê-la no painel de administração da OpenPix.

- **Endpoint para criar cobranças**: `https://api.openpix.com.br/api/v1/charge`
- **Token de autorização**: Insira seu token na configuração do módulo.
- **Authorization**: Insira um cabeçalho no seu Webhook com o nome de Authorization, o valor é seu AppID.
- 

## Estrutura do Repositório

- **modules/gateways/openpix.php**: Arquivo principal do gateway.  
- **modules/gateways/callback/openpix.php**: Script para tratamento dos webhooks.

## Contribuições

Contribuições são muito bem-vindas! Se você deseja melhorar o módulo ou reportar problemas, sinta-se à vontade para abrir uma **issue** ou enviar um **pull request**.

Se quiser colaborar diretamente, pode me chamar no Discord: **@ianchamba**.

## Licença

Este projeto é gratuito e está sob a licença GNU. Sinta-se à vontade para usá-lo e modificá-lo como quiser. Não há intenção de venda ou comercialização.

---

**Contato**  
Discord: **@ianchamba**  
GitHub: [GitHub](https://github.com/ianchamba)  
OpenPix: [https://openpix.com.br/](https://openpix.com.br/)

---
