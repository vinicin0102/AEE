/**
 * Planos, extras e ajustes do checkout.
 *
 * Os PREÇOS ficam aqui, no servidor, em centavos. O navegador envia só os ids
 * do plano e dos extras; um valor vindo do cliente é sempre ignorado — sem
 * isso, bastaria editar o request para pagar R$ 0,01.
 *
 * Os ids e preços precisam bater com PLANOS / EXTRAS do index.html, que só
 * exibe os valores.
 */

// Cada plano e extra tem um código de 1 letra, usado no external_id_client
// para o webhook saber o que foi comprado mesmo sem banco de dados.
export const PLANOS = {
  // centavos = preço da oferta (dentro do prazo); normal = depois do prazo.
  'aee-basico':   { codigo: 'b', nome: 'Central AEE — Plano Básico',   centavos: 1290, normal: 1990, productIdEnv: 'PRODUCT_ID_BASICO' },
  'aee-completo': { codigo: 'c', nome: 'Central AEE — Plano Completo', centavos: 2790, normal: 3790, productIdEnv: 'PRODUCT_ID_COMPLETO' },
};

export const EXTRAS = {
  relatorios: { codigo: 'r', nome: 'Banco de Relatórios e Pareceres',   centavos: 790 },
  atividades: { codigo: 'a', nome: 'Banco de Atividades Adaptadas',     centavos: 990 },
  tea:        { codigo: 't', nome: 'Kit Professor TEA',                 centavos: 990 },
  rotina:     { codigo: 'v', nome: 'Kit Rotina Visual',                 centavos: 790 },
  pasta:      { codigo: 'p', nome: 'Pasta do Aluno',                    centavos: 1290 },
  '30dias':   { codigo: 'd', nome: 'Primeiros 30 Dias no AEE',          centavos: 1290 },
  frases:     { codigo: 'f', nome: 'Banco de Frases para Documentação', centavos: 790 },
};

// Liga/desliga o cartão no site. Com false, a página mostra só o PIX e
// /api/cartao recusa qualquer tentativa, mesmo que a ZuckPay tenha o cartão
// ativo. Com true, o cartão aparece quando a ZuckPay confirma que está ativo.
export const CARTAO_ATIVO = false;

// Oferta por tempo limitado: cada visitante tem este prazo, contado da
// primeira visita, para comprar pelo preço de oferta. O prazo é assinado pelo
// servidor (api/oferta.js) e conferido na cobrança: depois dele, vale o
// preço normal. Recarregar a página não reinicia a contagem.
export const OFERTA_MINUTOS = 15;

// Desconto no cartão (sobre o total, extras incluídos). 0.05 = 5%.
export const DESCONTO_CARTAO = 0.05;

// Parcelas oferecidas no cartão (1 a 12). Acima de 1x a operadora cobra
// juros do comprador. Mude também MAX_PARCELAS no index.html.
export const MAX_PARCELAS = 3;

// Anti "card testing": tentativas de cartão por IP dentro da janela (s).
// Só vale com o Redis configurado (ver README).
export const LIMITE_CARTAO = { tentativas: 5, janela: 1800 };

// Prefixo do external_id_client, para separar as vendas nos relatórios.
export const PREFIXO = 'AEE';
