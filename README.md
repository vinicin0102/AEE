# Central AEE

Página de vendas do **Central AEE**, o kit para o professor de Atendimento
Educacional Especializado.

Página estática (HTML + CSS + JS puro, sem framework) com checkout PIX da
**ZuckPay** feito em PHP. Requisitos: PHP 8+ com a extensão cURL.

```
index.html               página + modal de checkout PIX
api/pix.php              cria a cobrança (plano + extras, preço calculado no servidor)
api/status.php           consulta o pagamento
api/webhook.php          confirma o pagamento e registra plano + extras comprados
api/vendas-recentes.php  compras reais recentes, para os pop-ups da página
api/diagnostico.php      checagem da integração (protegido por token)
api/_bootstrap.php       validação, CORS e chamada autenticada à API
api/config.example.php   modelo de configuração
tools/testar-webhook.php testa a validação de assinatura do webhook (CLI)
img/                     depoimentos de clientes
storage/                 log de pagamentos (não versionado)
```

## Planos

**Básico R$ 12,90** (Documentação, Avaliações, Planejamento,
Registros, Fichas de acompanhamento) e **Completo R$ 27,90** (tudo + Família e
escola + Recursos pedagógicos). Os extras aparecem só dentro do checkout e
valem para os dois planos.

## Antes de publicar

1. **`api/config.php`** — `cp api/config.example.php api/config.php`,
   preencha as credenciais (veja **Configuração** abaixo) e troque os
   `product_id` pelos ids dos produtos no painel da ZuckPay.
2. **Preços** — quem cobra é o servidor (`config.php`). O `index.html` só
   exibe: se mudar um preço, mude no `config.php`, em `PLANOS` / `EXTRAS` no
   fim do HTML e no texto dos cards de plano. O PIX mostra sempre o valor do
   servidor.
3. **FAQ** — preencha `entrega`, `formatos`, `acesso` e `programas` no bloco
   `FAQ` do HTML. Vazio = texto genérico, que não promete nada específico.
4. **Entrega** — o `TODO` em `api/webhook.php` é onde entra o envio do
   material. O log de pagamentos já grava `plano` e `extras` de cada venda
   (ex.: `"extras":["tea","pasta"]`), para saber o que entregar.

## Pop-ups de compras

Mostram **só vendas reais**: `api/vendas-recentes.php` lê o log de pagamentos
confirmados pelo webhook e devolve primeiro nome, plano e há quanto tempo
(últimos 7 dias, até 10). Cada venda aparece uma vez por visita; sem vendas,
não aparece pop-up nenhum. E-mail, CPF, telefone e valor nunca saem do
servidor. Para desligar: `'vendas_recentes' => false` no `config.php`.

Não use nomes ou horários inventados: pop-up de compra falsa é propaganda
enganosa (CDC, art. 37).

## Como os extras funcionam

O comprador escolhe o plano e marca os extras dentro do checkout; o total é
atualizado ali. Ao gerar o PIX, o navegador envia só
os ids (`["tea","pasta"]`). O `pix.php` recusa id desconhecido, soma os
valores do `config.php` e gera **um único PIX** com tudo. A descrição da
cobrança fica, por exemplo, "Central AEE + Kit Professor TEA + Pasta do Aluno".

## Pendências de conteúdo (marcadas com `TROCAR` no HTML)

1. **Prévias da seção "Veja o que você recebe"**: são desenhos em CSS da
   estrutura genérica dos modelos. Troque pelas capturas reais das páginas
   entregues e remova a legenda "Prévias ilustrativas".
2. **Quantidade de materiais**: a página diz "Dezenas de materiais". Só troque
   por um número (ex.: "+100") quando o conteúdo final tiver essa quantidade.
3. **Links do rodapé**: Termos de Uso, Política de Privacidade e Contato
   apontam para `#`.
4. **Pixel/UTM**: há um comentário no `<head>` para os scripts deste
   produto. O checkout já dispara `InitiateCheckout` e `Purchase` se o pixel
   (`fbq`) estiver carregado, e repassa UTMs e cookies `_fbc`/`_fbp` à ZuckPay.

Os depoimentos publicados são de clientes reais, com autorização. Não há
número de compradores, avaliações agregadas nem contagem regressiva: nada
disso deve ser adicionado sem dados reais.

## Configuração

```bash
cp api/config.example.php api/config.php
```

Preencha `client_id`, `client_secret`, `webhook_url`, `webhook_secret` e
`allowed_origins`. O **Webhook Secret** é gerado no painel em
*Integrações > Webhook Secret* e é diferente do Client Secret; sem ele os
postbacks chegam sem assinatura e só resta a verificação por reconsulta.

O `api_base` precisa usar **exatamente o host da sua tela de Credenciais API**
(com ou sem `www`). Com o host errado a ZuckPay responde um redirecionamento,
e um POST autenticado não é reenviado no redirect — a cobrança nunca chega.
Este é o motivo mais comum de "o PIX não gera".
`api/config.php` está no `.gitignore` — **nunca** versione esse arquivo.
Em produção prefira variáveis de ambiente (`ZUCKPAY_CLIENT_ID` /
`ZUCKPAY_CLIENT_SECRET`), que o `config.example.php` já lê.

Requisitos: PHP 8+ com a extensão cURL. O front chama `/api`; se a pasta não
ficar na raiz do site, ajuste a constante `API` no fim do `index.html`.

## Como funciona

1. O visitante clica em um dos planos e preenche nome, CPF, e-mail e telefone.
2. `api/pix.php` valida os dados e chama `POST /conta/v3/pix/qrcode`.
3. A página mostra o QR Code e o copia-e-cola, e consulta `api/status.php`
   a cada 4s até o pagamento ser confirmado.
4. A ZuckPay chama `api/webhook.php`, que confirma o pagamento e registra a venda.

Planos e extras: veja **Planos** acima. Preços e `product_id` ficam no
`config.php`.

## Conferir o webhook

Depois de configurar o `webhook_secret`, rode **no servidor**:

```bash
php tools/testar-webhook.php
```

Ele monta POSTs assinados como a ZuckPay faz e confere que o endpoint aceita
o legítimo e recusa assinatura falsa, replay e requisição sem header:

```
[ok]   assinatura válida        (esperado: 200) -> HTTP 200
[ok]   assinatura falsa         (esperado: 401) -> HTTP 401
[ok]   replay de 10 minutos     (esperado: 401) -> HTTP 401
[ok]   sem header de assinatura (esperado: 401) -> HTTP 401
```

O segredo é lido do `config.php`; nunca passe por argumento, porque a linha de
comando fica visível para outros processos e no histórico do shell.

## Se o PIX não gerar

1. Defina um `debug_token` no `config.php` e abra:
   `https://seu-dominio.com.br/api/diagnostico.php?token=SEU_TOKEN`

   Ele confere PHP, cURL, credenciais (mascaradas), planos e faz uma cobrança
   de teste de R$ 1,00, mostrando a resposta real da ZuckPay. Os diagnósticos
   possíveis:

   | Resultado | Causa provável |
   |---|---|
   | `REDIRECIONAMENTO` | `api_base` com o host errado (`www` sobrando ou faltando). O diagnóstico mostra o endereço certo em `va_para` e testa a variante em `alternativa`. |
   | `IP BLOQUEADO` | Credenciais válidas, mas o IP do servidor não está na IP Whitelist da ZuckPay. O diagnóstico mostra o IP a liberar. |
   | `RATE LIMIT` | 5 tentativas por 30 minutos. Aguarde. |
   | `FALHA DE CONEXAO` | A hospedagem bloqueia conexões de saída, ou DNS. |
   | `NAO AUTORIZADO` | `client_id`/`client_secret` errados, revogados ou sem permissão para PIX. |
   | `ENDPOINT NAO ENCONTRADO` | `api_base` incorreto. |
   | `OK` | A integração funciona — o problema está no front ou no caminho `/api`. |

2. Se der `OK` no diagnóstico mas o botão da página continuar falhando, o
   problema é o caminho: abra o console do navegador (F12) e veja se o
   `POST /api/pix.php` retorna 404. Nesse caso a pasta `api/` não está onde o
   front espera — ajuste a constante `API` no fim do `index.html`.

3. Ligue `'debug' => true` no `config.php` para que a página mostre o motivo
   real da falha em vez da mensagem genérica. **Desligue depois**, junto com o
   `debug_token`.

## Decisões de segurança

Estas escolhas são deliberadas — mudá-las abre brecha real:

- **O `client_secret` nunca vai para o navegador.** A documentação da ZuckPay
  mostra um exemplo em JavaScript com `btoa(clientId + ':' + clientSecret)`
  rodando no front. Seguir aquele exemplo publica a credencial no código-fonte
  da página: qualquer visitante poderia criar cobranças, listar transações e
  consultar o saldo da conta. Por isso a chamada é feita em PHP, no servidor.
- **O preço é definido no servidor.** `api/pix.php` recebe só o *id* do plano
  (`aee-basico` / `aee-completo`) e os ids dos extras e busca o valor na constante `PLANOS`. Um `valor`
  enviado pelo navegador é ignorado — sem isso, bastaria editar a requisição
  para comprar o Completo por R$ 0,01.
- **As respostas são filtradas.** A API devolve `amount_liquid`, e-mail do
  comprador e outros campos internos; os endpoints repassam apenas o necessário.
- **O webhook é verificado em duas camadas.** Primeiro a assinatura HMAC do
  header `X-ZuckPay-Signature` (`HMAC-SHA256("<timestamp>.<corpo_raw>",
  webhook_secret)`), com janela anti-replay de 5 minutos — prova que o POST
  veio da ZuckPay. Depois o `transactionId` é reconsultado na API — prova que
  o pagamento está pago agora. O corpo do POST nunca é a fonte da verdade, então
  um `"status":"PAID"` forjado não libera nada.
- **Entradas são validadas**: CPF com dígito verificador, e-mail, telefone e
  limite de tamanho. Parâmetros de atribuição passam por whitelist.
- **A entrega roda uma vez só.** A ZuckPay reenvia notificações. Antes de
  entregar, `webhook.php` cria um arquivo-marcador com `fopen(..., 'x')`, que
  falha se já existir — duas notificações simultâneas não passam as duas.
- **Cobranças não duplicam.** Cada abertura do checkout gera um `pedido`, usado
  como `external_id_client`. Clicar duas vezes devolve a mesma cobrança em vez
  de criar outra.

## Rate limit

A ZuckPay responde **429 após 5 tentativas em 30 minutos**. Por isso:

- `status.php` guarda a última consulta por 8 segundos, então várias abas ou
  recarregamentos não geram chamadas repetidas.
- Quando a API devolve 429, a resposta pede à página para esperar 30s em vez
  dos 5s normais; o front respeita esse intervalo.
- `webhook.php` responde na hora a `payment_refused`, `payment_pending` e
  `checkout_abandoned`, sem gastar uma chamada de verificação.
