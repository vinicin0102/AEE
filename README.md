# Central AEE

Página de vendas do **Central AEE**, o kit para o professor de Atendimento
Educacional Especializado, hospedada na **Vercel**, com checkout da
**ZuckPay**: PIX e cartão de crédito nacional (BRL, parcelado, 5% de
desconto no cartão).

```
index.html                página + checkout (modal)
img/                      depoimentos de clientes
lib/config.js             planos, extras e preços (em centavos), desconto, parcelas
lib/core.js               chamada à ZuckPay, validações, Redis, assinatura do webhook
api/pix.js                POST /api/pix             cria a cobrança PIX
api/cartao.js             POST /api/cartao          cobra no cartão (card_raw)
api/status.js             GET  /api/status          situação do pagamento
api/webhook.js            POST /api/webhook         confirmação da ZuckPay
api/vendas-recentes.js    GET  /api/vendas-recentes compras reais para os pop-ups
api/diagnostico.js        GET  /api/diagnostico     confere a configuração (com token)
```

Sem dependências: as funções usam só o Node (20+) da Vercel.

## Planos

**Básico R$ 12,90** (Documentação, Avaliações, Planejamento, Registros,
Fichas de acompanhamento) e **Completo R$ 27,90** (tudo + Família e escola +
Recursos pedagógicos). No checkout, o comprador preenche os dados e escolhe
PIX ou cartão; só então aparecem os extras (order bumps) e o botão de pagar.

Preços, extras, desconto e parcelas ficam em `lib/config.js` (quem cobra é o
servidor). O `index.html` só exibe: se mudar algo lá, mude também `PLANOS`,
`EXTRAS`, `MAX_PARCELAS` e `DESCONTO_CARTAO` no fim do HTML e os textos dos
cards de plano.

## Oferta por tempo limitado (15 min)

Faixa vermelha no topo com cronômetro de `OFERTA_MINUTOS` (15) contado da
primeira visita. É real: `/api/oferta` entrega um prazo **assinado** pelo
servidor, salvo no navegador (recarregar não reinicia), e a cobrança
(`precoPlano` em `lib/core.js`) confere esse prazo:

- dentro do prazo: preço de oferta (`centavos` em `lib/config.js`: Básico
  R$ 12,90, Completo R$ 27,90), com o preço normal riscado nos cards;
- depois do prazo, sem prazo ou com prazo adulterado: preço normal (`normal`:
  Básico R$ 19,90, Completo R$ 37,90). A faixa passa a dizer "Oferta
  encerrada" e os cards mostram o preço normal.

Para mudar o prazo ou os preços, edite `lib/config.js` e os valores iniciais
em `PLANOS` no fim do `index.html`.

## Variáveis de ambiente (Vercel → Settings → Environment Variables)

| Variável | Obrigatória | Para quê |
|---|---|---|
| `CLIENT_ID`, `CLIENT_SECRET` | sim | Credenciais da API ZuckPay. |
| `WEBHOOK_SECRET` | recomendada | *Integrações > Webhook Secret* no painel. Com ela, o webhook recusa POST que não venha da ZuckPay. |
| `SITE_URL` | recomendada | Ex.: `https://seudominio.com.br`. Base da URL do webhook enviada em cada cobrança. |
| `PRODUCT_ID_BASICO`, `PRODUCT_ID_COMPLETO` | opcional | Ids dos produtos no painel (vincula as vendas nos relatórios). |
| `ZUCKPAY_API_BASE` | opcional | Padrão `https://www.zuckpay.com.br/conta/v3`. Troque para o host sem `www` se o diagnóstico acusar redirecionamento. |
| `KV_REST_API_URL`, `KV_REST_API_TOKEN` | recomendada | Redis (Upstash). Criadas sozinhas ao conectar o banco (abaixo). |
| `DEBUG_TOKEN` | opcional | Libera `/api/diagnostico?token=...`. Sem ela, responde 404. |
| `DEBUG` | não em produção | `1` devolve o erro real da ZuckPay para a página. Desligue depois. |
| `VENDAS_RECENTES` | opcional | `0` desliga os pop-ups de compras. |

Depois de mudar variáveis, faça um **Redeploy**.

### Redis (Upstash) — por que e como

A Vercel não tem disco persistente. O Redis guarda:

- as **compras reais** mostradas nos pop-ups;
- o **limite de tentativas no cartão** por IP (anti *card testing*);
- a **deduplicação do webhook** (a ZuckPay reenvia notificações);
- o cache curto do status e os dados de cada pedido.

Sem ele o site vende normalmente, mas os pop-ups mostram só os avisos do
produto, o cartão fica sem limite de tentativas e o webhook pode processar a
mesma venda mais de uma vez. Para ativar: **Vercel → Storage → Upstash for
Redis (Marketplace) → Create**, conecte ao projeto `aee` e faça um Redeploy.
O plano gratuito basta.

## Conferir a configuração

Com `DEBUG_TOKEN` definida, abra `https://SEU-SITE/api/diagnostico?token=SEU_TOKEN`.
Ele mostra (com credenciais mascaradas) se a ZuckPay aceitou as credenciais,
se há redirecionamento de host, se o cartão nacional está habilitado na conta,
se o Redis responde e o que falta configurar. Não cria cobrança nenhuma.

Os erros aparecem em **Vercel → Logs** com um código `ref` — o mesmo que a
página mostra ao comprador.

## Cartão de crédito

Fluxo nacional (`POST /v3/card/charge` com `card_raw`), até `MAX_PARCELAS`
(padrão 3x; acima de 1x a operadora cobra juros do comprador), com 5% de
desconto sobre o total.

- **Aprovado** (`PAID`): confirmação na hora.
- **Em análise** (`PENDING`): a página consulta `/api/status` até resolver.
- **3D Secure** (`PENDING_3DS`): o comprador vai ao banco; o resultado chega pelo webhook.
- **Recusado**: mostra o motivo do banco e oferece o PIX.

**Chave geral:** `CARTAO_ATIVO` em `lib/config.js`. Hoje está `false`: o site
vende só no PIX, mesmo que a ZuckPay ative o cartão. Para ligar, mude para
`true` (o cartão então aparece quando a ZuckPay confirmar que está ativo).

**Cartão só aparece quando está ativo.** A página consulta
`/api/formas-pagamento`, que pergunta à ZuckPay (`GET /v3/card/keys`, cache de
5 min). Se a ZuckPay disser que o cartão está desativado (`enabled:false`, ou
403/503 como "Pagamento via cartão está desativado."), a página mostra só o
PIX — sem a aba de cartão e sem o "5% OFF" — e o PIX já vem selecionado. Quando
a ZuckPay ativar o cartão na conta, ele volta a aparecer sozinho. O
`/api/diagnostico` mostra a resposta do `/card/keys` em `card_keys`.

Os dados do cartão passam pela função só para serem repassados à ZuckPay:
nunca são gravados, registrados em log ou devolvidos, nem com `DEBUG=1`. O CVV
é apagado do formulário após cada tentativa. O número passa por Luhn antes da
API, e cada tentativa usa um `external_id_client` próprio (clique duplo não
cobra duas vezes).

## Webhook

Cadastre `https://SEU-SITE/api/webhook` no painel da ZuckPay. Cada venda é
confirmada em duas camadas: assinatura HMAC (`X-ZuckPay-Signature`, com
`WEBHOOK_SECRET`, janela anti-replay de 5 min) e reconsulta do status na API —
o corpo do POST nunca é a fonte da verdade.

O `external_id_client` carrega plano, forma de pagamento e extras em códigos
curtos (ex.: `AEE-cc-tf-<pedido>`), então o webhook sabe o que foi comprado
mesmo sem Redis. Cada venda confirmada aparece nos logs como `[venda] {...}`.

**Pendente — entrega do produto:** o `TODO` em `api/webhook.js` é onde entra o
envio do material (e-mail com o link, liberação de acesso).

**Proteção da Vercel:** o projeto está com *Vercel Authentication* nas
deploys que não são de domínio próprio. Se o webhook receber 401 da Vercel,
use um domínio próprio ou libere a proteção em *Settings → Deployment
Protection*.

## Meta Pixel

Pixel `1082049094820977` no `<head>`. Eventos: `PageView`, `ViewContent`,
`InitiateCheckout` (abrir o checkout), `AddPaymentInfo` (escolher PIX ou
cartão), `Purchase` (pagamento confirmado, com `eventID` `purchase-<transactionId>`
para deduplicar com a API de Conversões, se for usada no futuro). Os dados de
UTM e os cookies `_fbc`/`_fbp` também vão para a ZuckPay em cada cobrança.

## Notificações de prova social

Pequenas notificações no canto (inferior esquerdo no desktop; centralizadas e
acima da barra fixa no celular), uma por vez, no ciclo
**COMPRA → AVALIAÇÃO → COMPRA → …**: 7 s visíveis, 6,5 s de intervalo,
animação de 300 ms. Pausam com o checkout ou uma prévia aberta.

- **Compra aprovada**: só compras **reais**, confirmadas pelo webhook
  (`/api/vendas-recentes`, últimos 7 dias). Mostra o primeiro nome, o plano e
  há quanto tempo — "Mariana garantiu o Plano Completo da Central AEE · ✓
  Pagamento confirmado · há 3 min". Cada venda aparece uma vez; sem vendas
  reais, o ciclo segue só com avaliações. Sem Redis não há lista de vendas.
- **Avaliação**: os 8 depoimentos reais da página (clientes com autorização),
  com a foto recortada do card, cargo e 5 estrelas. O texto é trecho literal
  do depoimento; "…" marca trecho omitido. Editar em `AVALIACOES`, no fim do
  `index.html`.

Nunca adicione compradores, cidades, horários, valores ou contagens
inventados: notificação de compra falsa é propaganda enganosa (CDC, art. 37).

## Pendências de conteúdo (marcadas com `TROCAR` no HTML)

1. **Prévias da seção "Veja o que você recebe"**: páginas de exemplo em HTML.
   Troque pelas capturas reais das páginas entregues e remova a legenda
   "Prévias ilustrativas".
2. **Quantidade de materiais**: 150 modelos em Word no Plano Completo
   (informado pelo produtor). Se mudar, atualize a apresentação, o card do
   Plano Completo e o texto da oferta.
3. **Links do rodapé**: Termos de Uso, Política de Privacidade e Contato
   apontam para `#`.

Os depoimentos publicados são de clientes reais, com autorização.
