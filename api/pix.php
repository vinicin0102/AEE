<?php
declare(strict_types=1);

/**
 * Cria uma cobrança PIX na ZuckPay.
 *
 * POST { plano, extras?, pedido?, nome, cpf, email, telefone, rastreio? }
 * -> { transactionId, qrcode, qrcode_image, checkout_url, expiracao, valor, itens }
 */

require __DIR__ . '/_bootstrap.php';

$config = carregarConfig();
aplicarCors($config);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['erro' => 'Método não permitido.']);
}

// Plano, extras, preço (do servidor) e dados do comprador validados.
$p = montarPedido($config, corpoJson());

$externalId = ($p['plano']['prefixo'] ?? 'AEE') . '-' . $p['planoId'] . '-' . $p['pedido'];
$payload = $p['payload'] + ['external_id_client' => $externalId];

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
    'plano'         => $p['planoId'],
    'metodo'        => 'pix',
    // Só o primeiro nome, usado nos pop-ups de compras recentes.
    'primeiro_nome' => primeiroNome($p['nome']),
    'extras'        => array_keys($p['extras']),
    'itens'         => $p['itens'],
    'valor'         => $p['valor'],
]);

// Devolve só o que o navegador precisa. Nada de credencial, nada de valor líquido.
responder(200, [
    'transactionId' => (string) $resposta['transactionId'],
    'qrcode'        => (string) ($resposta['qrcode'] ?? $resposta['pix_code'] ?? ''),
    'qrcode_image'  => (string) ($resposta['qrcode_image'] ?? ''),
    'checkout_url'  => (string) ($resposta['checkout_url'] ?? ''),
    'expiracao'     => (int) ($resposta['calendar']['expiration'] ?? 1200),
    'valor'         => $p['valor'],
    'plano'         => $p['plano']['nome'],
    'itens'         => $p['itens'],
    'pedido'        => $p['pedido'],
]);
