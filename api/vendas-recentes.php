<?php
declare(strict_types=1);

/**
 * Compras recentes e reais, para os pop-ups da página de vendas.
 *
 * GET ?planos=aee-basico,aee-completo
 * -> { vendas: [ { nome, plano, minutos } ] }   (mais recente primeiro)
 *
 * Lê o log de pagamentos confirmados gravado pelo webhook. Sem vendas, a
 * lista vem vazia e a página não mostra pop-up nenhum. Só sai o primeiro
 * nome: e-mail, CPF, telefone e valor nunca deixam o servidor.
 */

require __DIR__ . '/_bootstrap.php';

$config = carregarConfig();
aplicarCors($config);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    responder(405, ['erro' => 'Método não permitido.']);
}

header('Cache-Control: public, max-age=60');

if (empty($config['vendas_recentes'])) {
    responder(200, ['vendas' => []]);
}

// Só planos que existem no config; o resto do parâmetro é ignorado.
$planos = is_array($config['planos'] ?? null) ? $config['planos'] : [];
$pedidos = array_filter(explode(',', (string) ($_GET['planos'] ?? '')));
$filtro = array_values(array_intersect($pedidos, array_keys($planos)));
if ($filtro === []) {
    responder(200, ['vendas' => []]);
}

$log = (string) ($config['log_path'] ?? '');
if ($log === '' || !is_file($log)) {
    responder(200, ['vendas' => []]);
}

// Lê só o fim do arquivo: as vendas recentes estão lá.
$handle = fopen($log, 'rb');
$tamanho = (int) filesize($log);
$ler = min($tamanho, 256 * 1024);
fseek($handle, $tamanho - $ler);
$trecho = (string) fread($handle, $ler);
fclose($handle);

$linhas = array_reverse(explode("\n", trim($trecho)));
$limite = time() - 7 * 86400;
$vendas = [];

foreach ($linhas as $linha) {
    $venda = json_decode($linha, true);
    if (!is_array($venda) || !in_array($venda['plano'] ?? '', $filtro, true)) {
        continue;
    }
    $nome = (string) ($venda['primeiro_nome'] ?? '');
    $quando = strtotime((string) ($venda['registrado_em'] ?? '')) ?: 0;
    if ($nome === '' || $quando < $limite) {
        continue;
    }
    $vendas[] = [
        'nome'    => $nome,
        'plano'   => (string) $planos[$venda['plano']]['nome'],
        'minutos' => max(1, intdiv(time() - $quando, 60)),
    ];
    if (count($vendas) >= 10) {
        break;
    }
}

responder(200, ['vendas' => $vendas]);
