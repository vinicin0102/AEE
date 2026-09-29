<?php
declare(strict_types=1);

/**
 * Cria uma cobrança PIX na ZuckPay.
 *
 * POST { plano, extras?, nome, cpf, email, telefone, rastreio? }
 * -> { transactionId, qrcode, qrcode_image, checkout_url, expiracao, valor, itens }
 */

require __DIR__ . '/_bootstrap.php';

$config = carregarConfig();
aplicarCors($config);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['erro' => 'Método não permitido.']);
}

/**
 * Os planos (e principalmente o preço) vêm do config.php, no servidor.
 * O valor enviado pelo navegador é ignorado de propósito: se ele fosse
 * aceito, qualquer pessoa poderia editar o request e pagar R$ 0,01.
 */
$planos = is_array($config['planos'] ?? null) ? $config['planos'] : [];

$corpo = corpoJson();

$planoId = is_string($corpo['plano'] ?? null) ? $corpo['plano'] : '';
if (!isset($planos[$planoId])) {
    responder(400, ['erro' => 'Plano inválido.']);
}
$plano = $planos[$planoId];

/**
 * Extras (order bumps): o navegador manda só os ids. Id desconhecido ou de
 * outro plano é recusado, e o valor de cada um vem do config.php.
 */
$extrasDisponiveis = is_array($plano['extras'] ?? null) ? $plano['extras'] : [];
$extrasPedidos = $corpo['extras'] ?? [];
if (!is_array($extrasPedidos) || count($extrasPedidos) > count($extrasDisponiveis)) {
    responder(400, ['erro' => 'Extras inválidos.']);
}

$extras = [];
foreach ($extrasPedidos as $extraId) {
    if (!is_string($extraId) || !isset($extrasDisponiveis[$extraId])) {
        responder(400, ['erro' => 'Extras inválidos.']);
    }
    $extras[$extraId] = $extrasDisponiveis[$extraId];
}

$valorTotal = (float) $plano['valor'];
$itens = [$plano['nome']];
foreach ($extras as $extra) {
    $valorTotal += (float) $extra['valor'];
    $itens[] = $extra['nome'];
}
$valorTotal = round($valorTotal, 2);

$nome     = trim((string) ($corpo['nome'] ?? ''));
$cpf      = preg_replace('/\D/', '', (string) ($corpo['cpf'] ?? '')) ?? '';
$email    = trim((string) ($corpo['email'] ?? ''));
$telefone = preg_replace('/\D/', '', (string) ($corpo['telefone'] ?? '')) ?? '';

$erros = [];
if (mb_strlen($nome) < 3 || mb_strlen($nome) > 100) {
    $erros['nome'] = 'Informe seu nome completo.';
}
if (!cpfValido($cpf)) {
    $erros['cpf'] = 'CPF inválido.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    $erros['email'] = 'E-mail inválido.';
}
if (strlen($telefone) < 10 || strlen($telefone) > 11) {
    $erros['telefone'] = 'Telefone inválido. Use DDD + número.';
}
if ($erros !== []) {
    responder(422, ['erro' => 'Dados inválidos.', 'campos' => $erros]);
}

/**
 * Idempotência: o navegador manda o mesmo "pedido" se o comprador clicar
 * duas vezes ou recarregar. Com external_id_client repetido, a ZuckPay
 * devolve a cobrança existente em vez de criar outra.
 */
$pedido = (string) ($corpo['pedido'] ?? '');
$pedido = preg_replace('/[^A-Za-z0-9-]/', '', $pedido) ?? '';
if (strlen($pedido) < 8 || strlen($pedido) > 60) {
    $pedido = bin2hex(random_bytes(12));
}

$externalId = ($plano['prefixo'] ?? 'AEE') . '-' . $planoId . '-' . $pedido;

$payload = [
    'nome'               => $nome,
    'cpf'                => $cpf,
    'valor'              => $valorTotal,
    'email'              => $email,
    'telefone'           => $telefone,
    'urlnoty'            => $config['webhook_url'],
    'descricao'          => mb_substr(implode(' + ', $itens), 0, 250),
    'external_id_client' => $externalId,
];

// Vincula a venda ao produto cadastrado no painel (opcional na API).
if (!empty($plano['product_id'])) {
    $payload['product_id'] = (int) $plano['product_id'];
}

$rastreio = is_array($corpo['rastreio'] ?? null) ? $corpo['rastreio'] : [];
$permitidos = [
    'utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term',
    'fbc', 'fbp', 'fbclid', 'gclid', 'ttclid', 'wbraid', 'gbraid',
    'kclid', 'click_id', 'src', 'sck',
];
foreach ($permitidos as $chave) {
    $valor = $rastreio[$chave] ?? null;
    if (is_string($valor) && $valor !== '') {
        $payload[$chave] = mb_substr($valor, 0, 255);
    }
}

[$status, $resposta] = chamarZuckpay($config, 'POST', '/qrcode', $payload);

if ($status === 429) {
    registrarErro('pix', 'rate limit da ZuckPay');
    responder(429, ['erro' => 'Muitas tentativas em pouco tempo. Aguarde alguns minutos e tente de novo.']);
}

if ($status === 403) {
    registrarErro('pix', 'HTTP 403 — provável IP whitelist bloqueando o servidor');
    responder(502, ['erro' => 'Pagamento indisponível no momento. Já estamos verificando.']);
}

if ($status !== 200 || empty($resposta['transactionId'])) {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    registrarErro('pix', 'ref=' . $ref . ' HTTP ' . $status . ' ' . json_encode($resposta, JSON_UNESCAPED_UNICODE));

    $saida = [
        'erro' => 'Não foi possível gerar o PIX agora. Tente novamente em instantes.',
        'ref'  => $ref,
    ];

    // Com debug ligado, devolve o motivo real para facilitar a investigação.
    if (!empty($config['debug'])) {
        $saida['debug'] = [
            'http'     => $status,
            'resposta' => $resposta,
            'enviado'  => array_diff_key($payload, ['cpf' => 1, 'email' => 1, 'telefone' => 1]),
        ];
    }

    responder(502, $saida);
}

// Guarda o que foi comprado, para o webhook saber quais extras entregar.
registrarPedido($config, $externalId, [
    'plano'  => $planoId,
    // Só o primeiro nome, usado nos pop-ups de compras recentes.
    'primeiro_nome' => primeiroNome($nome),
    'extras' => array_keys($extras),
    'itens'  => $itens,
    'valor'  => $valorTotal,
]);

// Devolve só o que o navegador precisa. Nada de credencial, nada de valor líquido.
responder(200, [
    'transactionId' => (string) $resposta['transactionId'],
    'qrcode'        => (string) ($resposta['qrcode'] ?? $resposta['pix_code'] ?? ''),
    'qrcode_image'  => (string) ($resposta['qrcode_image'] ?? ''),
    'checkout_url'  => (string) ($resposta['checkout_url'] ?? ''),
    'expiracao'     => (int) ($resposta['calendar']['expiration'] ?? 1200),
    'valor'         => $valorTotal,
    'plano'         => $plano['nome'],
    'itens'         => $itens,
    'pedido'        => $pedido,
]);
