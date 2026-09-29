<?php
declare(strict_types=1);

/**
 * Cobrança no cartão de crédito nacional (BRL) na ZuckPay.
 *
 * POST { plano, extras?, pedido?, nome, cpf, email, telefone, rastreio?,
 *        cartao: { numero, titular, mes, ano, cvv }, parcelas? }
 * -> { status: PAID | PENDING | PENDING_3DS, transactionId, valor, parcelas, redirect? }
 *
 * Os dados do cartão passam por este servidor só para serem repassados à
 * ZuckPay (fluxo card_raw). Eles NUNCA são gravados, registrados em log ou
 * devolvidos — nem em modo debug. Exige HTTPS no site.
 */

require __DIR__ . '/_bootstrap.php';

$config = carregarConfig();
aplicarCors($config);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['erro' => 'Método não permitido.']);
}

// Anti card testing: poucas tentativas por IP (ajustável no config).
$limite = is_array($config['limite_cartao'] ?? null) ? $config['limite_cartao'] : [];
if (!dentroDoLimite($config, 'cartao', (int) ($limite['tentativas'] ?? 5), (int) ($limite['janela'] ?? 1800))) {
    responder(429, ['erro' => 'Muitas tentativas com cartão. Aguarde alguns minutos ou pague com PIX.']);
}

$corpo = corpoJson();
$p = montarPedido($config, $corpo);

/* ---------- cartão ---------- */
$cartao  = is_array($corpo['cartao'] ?? null) ? $corpo['cartao'] : [];
$numero  = preg_replace('/\D/', '', (string) ($cartao['numero'] ?? '')) ?? '';
$titular = trim(preg_replace('/\s+/', ' ', (string) ($cartao['titular'] ?? '')) ?? '');
$mes     = preg_replace('/\D/', '', (string) ($cartao['mes'] ?? '')) ?? '';
$ano     = preg_replace('/\D/', '', (string) ($cartao['ano'] ?? '')) ?? '';
$cvv     = preg_replace('/\D/', '', (string) ($cartao['cvv'] ?? '')) ?? '';
unset($corpo['cartao'], $cartao); // não deixa o cartão circulando no resto do script

if (strlen($ano) === 2) {
    $ano = '20' . $ano;
}

$erros = [];
if (strlen($numero) < 13 || strlen($numero) > 19 || !luhnValido($numero)) {
    $erros['cartao-numero'] = 'Número do cartão inválido.';
}
if (mb_strlen($titular) < 3 || mb_strlen($titular) > 60) {
    $erros['cartao-titular'] = 'Informe o nome como está no cartão.';
}
$mesInt = (int) $mes;
$anoInt = (int) $ano;
$fimValidade = mktime(0, 0, 0, $mesInt + 1, 1, $anoInt);
if ($mesInt < 1 || $mesInt > 12 || strlen($ano) !== 4 || $fimValidade <= time() || $anoInt > (int) date('Y') + 20) {
    $erros['cartao-validade'] = 'Validade inválida.';
}
if (strlen($cvv) < 3 || strlen($cvv) > 4) {
    $erros['cartao-cvv'] = 'CVV inválido.';
}

$maxParcelas = max(1, min(12, (int) ($config['max_parcelas'] ?? 3)));
$parcelas = (int) ($corpo['parcelas'] ?? 1);
if ($parcelas < 1 || $parcelas > $maxParcelas) {
    $erros['cartao-parcelas'] = 'Número de parcelas inválido.';
}

if ($erros !== []) {
    responder(422, ['erro' => 'Confira os dados do cartão.', 'campos' => $erros]);
}

$externalId = ($p['plano']['prefixo'] ?? 'AEE') . '-' . $p['planoId'] . '-cartao-' . $p['pedido'];

// Grava o pedido ANTES de cobrar: numa aprovação imediata o webhook pode
// chegar antes desta resposta, e precisa saber o que foi comprado.
registrarPedido($config, $externalId, [
    'plano'         => $p['planoId'],
    'metodo'        => 'cartao',
    'primeiro_nome' => primeiroNome($p['nome']),
    'extras'        => array_keys($p['extras']),
    'itens'         => $p['itens'],
    'valor'         => $p['valor'],
    'parcelas'      => $parcelas,
]);

$payload = $p['payload'] + [
    'external_id_client' => $externalId,
    'currency'           => 'BRL',
    'country'            => 'BR',
    'installments'       => $parcelas,
    'card_raw'           => [
        'number'      => $numero,
        'holder_name' => mb_strtoupper($titular),
        'exp_month'   => str_pad((string) $mesInt, 2, '0', STR_PAD_LEFT),
        'exp_year'    => $ano,
        'cvv'         => $cvv,
    ],
];
unset($numero, $cvv);

[$status, $resposta] = chamarZuckpay($config, 'POST', '/charge', $payload, baseCartao($config));
unset($payload);

if ($status === 429) {
    registrarErro('cartao', 'rate limit da ZuckPay');
    responder(429, ['erro' => 'Muitas tentativas em pouco tempo. Aguarde alguns minutos ou pague com PIX.']);
}

$situacao = strtoupper((string) ($resposta['status'] ?? ''));

// Recusa do banco: devolve o motivo (é seguro e ajuda o comprador).
if ($situacao === 'FAILED' || $situacao === 'REFUSED') {
    $motivo = mb_substr(trim((string) ($resposta['failureMessage'] ?? '')), 0, 160);
    if ($motivo !== '' && !preg_match('/[.!?]$/u', $motivo)) {
        $motivo .= '.';
    }
    responder(402, [
        'erro' => 'Pagamento recusado' . ($motivo !== '' ? ': ' . $motivo : '.') . ' Confira os dados, tente outro cartão ou pague com PIX.',
    ]);
}

if ($status !== 200 || empty($resposta['transactionId']) || !in_array($situacao, ['PAID', 'PENDING', 'PENDING_3DS'], true)) {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    // A resposta da ZuckPay não contém o cartão; o payload (que contém) nunca é registrado.
    registrarErro('cartao', 'ref=' . $ref . ' HTTP ' . $status . ' ' . json_encode($resposta, JSON_UNESCAPED_UNICODE));

    $saida = ['erro' => 'Não foi possível processar o cartão agora. Tente novamente ou pague com PIX.', 'ref' => $ref];
    if (!empty($config['debug'])) {
        $saida['debug'] = ['http' => $status, 'resposta' => $resposta];
    }
    responder(502, $saida);
}

$saida = [
    'status'        => $situacao,
    'transactionId' => (string) $resposta['transactionId'],
    'valor'         => (float) ($resposta['amountBrl'] ?? $resposta['amount'] ?? $p['valor']),
    'parcelas'      => (int) ($resposta['installments'] ?? $parcelas),
    'plano'         => $p['plano']['nome'],
];

// 3D Secure: o banco pede autenticação; a página redireciona o comprador.
if ($situacao === 'PENDING_3DS') {
    $url = (string) ($resposta['threeDSecureUrl'] ?? '');
    if (!preg_match('#^https://#i', $url)) {
        registrarErro('cartao', 'PENDING_3DS sem URL https válida para ' . $saida['transactionId']);
        responder(502, ['erro' => 'Não foi possível concluir a autenticação do cartão. Tente novamente ou pague com PIX.']);
    }
    $saida['redirect'] = $url;
}

responder(200, $saida);
