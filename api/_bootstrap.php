<?php
declare(strict_types=1);

/**
 * Utilitários compartilhados pelos endpoints da API.
 * Este arquivo não responde nada sozinho.
 */

function carregarConfig(): array
{
    $caminho = __DIR__ . '/config.php';
    if (!is_file($caminho)) {
        responder(500, ['erro' => 'Servidor não configurado.']);
    }
    return require $caminho;
}

function responder(int $status, array $dados): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function aplicarCors(array $config): void
{
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origem !== '' && in_array($origem, $config['allowed_origins'], true)) {
        header('Access-Control-Allow-Origin: ' . $origem);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function corpoJson(): array
{
    $bruto = file_get_contents('php://input');
    $dados = json_decode((string) $bruto, true);
    return is_array($dados) ? $dados : [];
}

/** Registra no log de erros do servidor, sem devolver detalhes ao cliente. */
function registrarErro(string $contexto, string $detalhe): void
{
    error_log(sprintf('[zuckpay][%s] %s', $contexto, $detalhe));
}

/** Valida CPF incluindo os dígitos verificadores. */
function cpfValido(string $cpf): bool
{
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($posicao = 9; $posicao < 11; $posicao++) {
        $soma = 0;
        for ($i = 0; $i < $posicao; $i++) {
            $soma += (int) $cpf[$i] * (($posicao + 1) - $i);
        }
        $digito = ((10 * $soma) % 11) % 10;
        if ((int) $cpf[$posicao] !== $digito) {
            return false;
        }
    }
    return true;
}

/**
 * Chamada autenticada à API da ZuckPay.
 * O client_secret nunca sai daqui — não é devolvido ao navegador em hipótese alguma.
 *
 * Redirecionamentos NÃO são seguidos de propósito: seguir um 3xx num POST
 * autenticado reenviaria o Authorization para o host de destino, e o corpo
 * costuma ser descartado no caminho. Em vez disso devolvemos o Location para
 * que o api_base seja corrigido.
 *
 * $base troca a URL base (ex.: a do cartão); sem ela, usa api_base (PIX).
 *
 * @return array{0:int,1:array,2:string} [status http, corpo decodificado, destino do redirect]
 */
function chamarZuckpay(array $config, string $metodo, string $caminho, ?array $payload = null, ?string $base = null): array
{
    $url = rtrim($base ?? $config['api_base'], '/') . $caminho;
    $autorizacao = 'Basic ' . base64_encode($config['client_id'] . ':' . $config['client_secret']);

    $cabecalhos = ['Accept: application/json', 'Authorization: ' . $autorizacao];
    $ch = curl_init($url);
    $opcoes = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($metodo === 'POST') {
        $opcoes[CURLOPT_POST] = true;
        $opcoes[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $cabecalhos[] = 'Content-Type: application/json';
    }

    $opcoes[CURLOPT_HTTPHEADER] = $cabecalhos;
    $opcoes[CURLOPT_HEADER] = true;
    curl_setopt_array($ch, $opcoes);

    $bruto    = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tamCab   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($bruto === false) {
        registrarErro('curl', $erroCurl);
        return [0, [], ''];
    }

    $cabecalhosResposta = substr((string) $bruto, 0, $tamCab);
    $resposta           = substr((string) $bruto, $tamCab);

    $destino = '';
    if ($status >= 300 && $status < 400
        && preg_match('/^Location:\s*(.+)$/mi', $cabecalhosResposta, $m)) {
        $destino = trim($m[1]);
        registrarErro('redirect', 'HTTP ' . $status . ' -> ' . $destino);
    }

    $decodificado = json_decode($resposta, true);
    if (!is_array($decodificado)) {
        registrarErro('resposta', 'HTTP ' . $status . ' com corpo não-JSON');
        return [$status, [], $destino];
    }

    return [$status, $decodificado, $destino];
}

/**
 * Valida o header X-ZuckPay-Signature.
 *
 * Formato: t=<timestamp>,v1=<hmac_sha256_hex>
 * Cálculo:  HMAC-SHA256("<timestamp>.<corpo_raw>", webhook_secret)
 *
 * @return array{0:bool,1:string} [válida, motivo da recusa]
 */
function assinaturaWebhookValida(string $header, string $corpoRaw, string $segredo): array
{
    if ($header === '') {
        return [false, 'header X-ZuckPay-Signature ausente'];
    }

    parse_str(strtr($header, ',', '&'), $partes);
    $ts = (string) ($partes['t'] ?? '');
    $v1 = (string) ($partes['v1'] ?? '');

    if ($ts === '' || $v1 === '' || !ctype_digit($ts)) {
        return [false, 'header malformado'];
    }

    // Anti-replay: rejeita assinaturas velhas ou com data no futuro.
    if (abs(time() - (int) $ts) > 300) {
        return [false, 'timestamp fora da janela de 5 minutos'];
    }

    $esperado = hash_hmac('sha256', $ts . '.' . $corpoRaw, $segredo);

    if (!hash_equals($esperado, $v1)) {
        return [false, 'assinatura não confere'];
    }

    return [true, ''];
}

/** Diretório de estado (cache e registro de vendas). */
function diretorioEstado(array $config): string
{
    $dir = dirname((string) ($config['log_path'] ?? __DIR__ . '/../storage/pagamentos.log'));
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return $dir;
}

/**
 * Cache curto das consultas de status.
 *
 * A ZuckPay aplica rate limit (429). Como a página consulta em intervalos
 * curtos enquanto o comprador paga, várias abas ou recarregamentos poderiam
 * estourar o limite. Guardamos a última resposta por alguns segundos.
 *
 * @return array|null resposta em cache, ou null se não houver/estiver velha
 */
function cacheStatusLer(array $config, string $transactionId, int $validadeSegundos = 8): ?array
{
    $arquivo = diretorioEstado($config) . '/status-' . sha1($transactionId) . '.json';

    if (!is_file($arquivo) || (time() - (int) filemtime($arquivo)) > $validadeSegundos) {
        return null;
    }

    $dados = json_decode((string) @file_get_contents($arquivo), true);
    return is_array($dados) ? $dados : null;
}

function cacheStatusGravar(array $config, string $transactionId, array $dados): void
{
    $arquivo = diretorioEstado($config) . '/status-' . sha1($transactionId) . '.json';
    @file_put_contents($arquivo, json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Idempotência da entrega: diz se este transactionId já foi registrado.
 *
 * A ZuckPay reenvia a mesma notificação, então a entrega do produto precisa
 * acontecer uma única vez. Usa um arquivo-marcador criado atomicamente:
 * duas notificações simultâneas não conseguem passar as duas.
 */
function transacaoJaRegistrada(array $config, string $transactionId): bool
{
    $marcador = diretorioEstado($config) . '/pago-' . sha1($transactionId) . '.flag';

    // 'x' falha se o arquivo já existir — é o teste e a criação num passo só.
    $handle = @fopen($marcador, 'x');

    if ($handle === false) {
        return true;
    }

    fwrite($handle, date('c'));
    fclose($handle);
    return false;
}

/** Primeiro nome, só com letras, no máximo 20 caracteres ("maria clara" -> "Maria"). */
function primeiroNome(string $nome): string
{
    $primeiro = preg_split('/\s+/u', trim($nome))[0] ?? '';
    $primeiro = preg_replace('/[^\p{L}\'-]/u', '', $primeiro) ?? '';
    return mb_convert_case(mb_substr($primeiro, 0, 20), MB_CASE_TITLE, 'UTF-8');
}

/**
 * Guarda os itens de um pedido (plano + extras) pelo external_id_client.
 * O webhook lê este arquivo para saber o que entregar. Uma nova tentativa
 * com o mesmo pedido sobrescreve a anterior, que é o que o comprador escolheu por último.
 */
function registrarPedido(array $config, string $externalId, array $dados): void
{
    $arquivo = diretorioEstado($config) . '/pedido-' . sha1($externalId) . '.json';
    @file_put_contents($arquivo, json_encode($dados + ['criado_em' => date('c')], JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Lê os itens gravados por registrarPedido(), ou null se não houver. */
function lerPedido(array $config, string $externalId): ?array
{
    if ($externalId === '') {
        return null;
    }
    $arquivo = diretorioEstado($config) . '/pedido-' . sha1($externalId) . '.json';
    $dados = is_file($arquivo) ? json_decode((string) @file_get_contents($arquivo), true) : null;
    return is_array($dados) ? $dados : null;
}

/** Acrescenta a venda ao log de pagamentos. */
function registrarPagamento(array $config, array $dados): void
{
    diretorioEstado($config);
    @file_put_contents(
        (string) $config['log_path'],
        json_encode($dados, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/** Base da API de cartão: card_base do config, ou derivada do api_base (/pix -> /card). */
function baseCartao(array $config): string
{
    return (string) ($config['card_base'] ?? preg_replace('#/pix/?$#', '/card', (string) $config['api_base']));
}

/**
 * Valida plano, extras e dados do comprador e calcula o valor no servidor.
 * Usado por pix.php e cartao.php; responde 400/422 e encerra se algo não bate.
 *
 * O preço vem SEMPRE do config.php. Um valor enviado pelo navegador é
 * ignorado: se fosse aceito, bastaria editar o request para pagar R$ 0,01.
 *
 * @return array{planoId:string,plano:array,extras:array,itens:array,valor:float,
 *               nome:string,cpf:string,email:string,telefone:string,pedido:string,
 *               payload:array}
 */
function montarPedido(array $config, array $corpo): array
{
    $planos = is_array($config['planos'] ?? null) ? $config['planos'] : [];

    $planoId = is_string($corpo['plano'] ?? null) ? $corpo['plano'] : '';
    if (!isset($planos[$planoId])) {
        responder(400, ['erro' => 'Plano inválido.']);
    }
    $plano = $planos[$planoId];

    // Extras (order bumps): só ids. Id desconhecido ou de outro plano é recusado.
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

    $valor = (float) $plano['valor'];
    $itens = [$plano['nome']];
    foreach ($extras as $extra) {
        $valor += (float) $extra['valor'];
        $itens[] = $extra['nome'];
    }
    $valor = round($valor, 2);

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

    /*
     * Idempotência: o navegador manda o mesmo "pedido" se o comprador clicar
     * duas vezes ou recarregar. Com external_id_client repetido, a ZuckPay
     * devolve a cobrança existente em vez de criar (ou cobrar) outra.
     */
    $pedido = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($corpo['pedido'] ?? '')) ?? '';
    if (strlen($pedido) < 8 || strlen($pedido) > 60) {
        $pedido = bin2hex(random_bytes(12));
    }

    $payload = [
        'nome'      => $nome,
        'cpf'       => $cpf,
        'valor'     => $valor,
        'email'     => $email,
        'telefone'  => $telefone,
        'urlnoty'   => $config['webhook_url'],
        'descricao' => mb_substr(implode(' + ', $itens), 0, 250),
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
        $v = $rastreio[$chave] ?? null;
        if (is_string($v) && $v !== '') {
            $payload[$chave] = mb_substr($v, 0, 255);
        }
    }

    return compact('planoId', 'plano', 'extras', 'itens', 'valor', 'nome', 'cpf', 'email', 'telefone', 'pedido', 'payload');
}

/**
 * Limite de tentativas por IP numa janela de tempo (arquivo em storage/).
 * Devolve false quando o limite estourou. Protege o cartão contra "card
 * testing": robôs que usam o formulário para testar cartões roubados.
 */
function dentroDoLimite(array $config, string $acao, int $maximo, int $janelaSegundos): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $arquivo = diretorioEstado($config) . '/limite-' . $acao . '-' . sha1($ip) . '.json';

    $handle = @fopen($arquivo, 'c+');
    if ($handle === false) {
        return true; // sem storage não bloqueia a venda; o erro fica no log do PHP
    }
    flock($handle, LOCK_EX);
    $tentativas = json_decode((string) stream_get_contents($handle), true);
    $agora = time();
    $tentativas = array_values(array_filter(
        is_array($tentativas) ? $tentativas : [],
        fn ($t) => is_int($t) && $t > $agora - $janelaSegundos
    ));

    $permitido = count($tentativas) < $maximo;
    if ($permitido) {
        $tentativas[] = $agora;
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($tentativas));
    flock($handle, LOCK_UN);
    fclose($handle);

    return $permitido;
}

/** Algoritmo de Luhn: descarta número de cartão digitado errado antes de ir à API. */
function luhnValido(string $numero): bool
{
    $soma = 0;
    $dobrar = false;
    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $d = (int) $numero[$i];
        if ($dobrar) {
            $d *= 2;
            if ($d > 9) {
                $d -= 9;
            }
        }
        $soma += $d;
        $dobrar = !$dobrar;
    }
    return $soma % 10 === 0;
}
