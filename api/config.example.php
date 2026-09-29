<?php
/**
 * Copie este arquivo para config.php e preencha com os dados reais.
 * config.php está no .gitignore e NUNCA deve ser versionado.
 */

// Extras (order bumps) do Central AEE, compartilhados pelos dois planos.
$extrasAee = [
    'relatorios' => ['nome' => 'Banco de Relatórios e Pareceres',   'valor' => 7.90],
    'atividades' => ['nome' => 'Banco de Atividades Adaptadas',     'valor' => 9.90],
    'tea'        => ['nome' => 'Kit Professor TEA',                 'valor' => 9.90],
    'rotina'     => ['nome' => 'Kit Rotina Visual',                 'valor' => 7.90],
    'pasta'      => ['nome' => 'Pasta do Aluno',                    'valor' => 12.90],
    '30dias'     => ['nome' => 'Primeiros 30 Dias no AEE',          'valor' => 12.90],
    'frases'     => ['nome' => 'Banco de Frases para Documentação', 'valor' => 7.90],
];

return [
    // Credenciais da ZuckPay (painel > Integrações > API keys)
    'client_id'     => getenv('ZUCKPAY_CLIENT_ID')     ?: 'seu_client_id',
    'client_secret' => getenv('ZUCKPAY_CLIENT_SECRET') ?: 'seu_client_secret',

    /**
     * Base da API.
     *
     * ATENÇÃO: as duas fontes da ZuckPay divergem. A documentação completa
     * usa https://www.zuckpay.com.br; a tela de Credenciais API mostra
     * https://zuckpay.com.br (sem www). Com o host errado a API responde um
     * redirecionamento e o POST autenticado não é reenviado — a cobrança
     * nunca chega.
     *
     * Rode o api/diagnostico.php: ele detecta o redirect e testa as duas
     * variantes, dizendo qual funciona na sua conta.
     *
     * Para testes: https://www.zuckpay.com.br/conta/dev/api/pix
     */
    'api_base' => 'https://www.zuckpay.com.br/conta/v3/pix',

    /**
     * Cartão de crédito nacional (BRL). Mesmo host do api_base; se omitido,
     * é derivado dele (/v3/pix -> /v3/card).
     */
    'card_base' => 'https://www.zuckpay.com.br/conta/v3/card',

    // Parcelas oferecidas no cartão (1 a 12). Acima de 1x a operadora cobra
    // juros do comprador; em produto barato, poucas parcelas bastam.
    'max_parcelas' => 3,

    // Anti "card testing": tentativas de cartão por IP dentro da janela (s).
    'limite_cartao' => ['tentativas' => 5, 'janela' => 1800],

    /**
     * Planos vendidos na página.
     *
     * O preço fica AQUI, no servidor. O navegador envia apenas o id do plano
     * e os ids dos extras — um valor vindo do cliente é sempre ignorado.
     *
     * product_id: id do produto cadastrado no painel da ZuckPay. É opcional
     * na API, mas preenchê-lo vincula a venda ao produto nos relatórios.
     */
    'planos' => [
        /**
         * Central AEE: dois planos com os mesmos extras.
         *
         * extras: complementos (order bumps) oferecidos no checkout. O
         * navegador envia apenas os ids marcados; o valor de cada extra é
         * somado aqui, no servidor. Ids e preços precisam bater com
         * PLANOS / EXTRAS do index.html (que só exibe).
         *
         * prefixo: início do external_id_client, para separar as vendas de
         * cada produto nos relatórios.
         */
        'aee-basico' => [
            'nome'       => 'Central AEE — Plano Básico',
            'valor'      => 12.90,
            'product_id' => 0, // TROCAR pelo id do produto no painel da ZuckPay
            'prefixo'    => 'AEE',
            'extras'     => $extrasAee,
        ],
        'aee-completo' => [
            'nome'       => 'Central AEE — Plano Completo',
            'valor'      => 27.90,
            'product_id' => 0, // TROCAR pelo id do produto no painel da ZuckPay
            'prefixo'    => 'AEE',
            'extras'     => $extrasAee,
        ],
    ],

    // URL pública que a ZuckPay chama quando o pagamento muda de status.
    // Cadastre-a também em Integrações > Webhooks no painel.
    'webhook_url' => 'https://SEU-DOMINIO.com.br/api/webhook.php',

    /**
     * Webhook Secret — gerado no painel em Integrações > Webhook Secret.
     * É DIFERENTE do client_secret.
     *
     * Com ele preenchido, api/webhook.php valida o header
     * X-ZuckPay-Signature e recusa qualquer POST que não venha da ZuckPay.
     * Vazio, os postbacks continuam chegando sem assinatura e a validação
     * fica só por reconsulta à API.
     */
    'webhook_secret' => '',

    // Origens autorizadas a chamar estes endpoints (CORS).
    'allowed_origins' => [
        'https://SEU-DOMINIO.com.br',
        'https://www.SEU-DOMINIO.com.br',
    ],

    /**
     * Pop-ups de compras recentes (api/vendas-recentes.php).
     *
     * Mostram só vendas reais, lidas do log de pagamentos confirmados:
     * primeiro nome, plano e há quanto tempo. Nada de e-mail, CPF ou
     * sobrenome. false desliga o endpoint (responde lista vazia).
     */
    'vendas_recentes' => true,

    // Onde gravar o log de pagamentos confirmados.
    'log_path' => __DIR__ . '/../storage/pagamentos.log',

    /**
     * Modo diagnóstico.
     *
     * Com true, os endpoints devolvem a mensagem de erro real da ZuckPay em
     * vez da mensagem genérica — útil para descobrir por que o PIX não gera.
     * DESLIGUE depois de resolver: mensagens de erro podem revelar detalhes
     * da conta.
     */
    'debug' => false,

    /**
     * Token do api/diagnostico.php. Troque por uma string aleatória.
     * Sem ele o diagnóstico responde 404.
     */
    'debug_token' => '',
];
