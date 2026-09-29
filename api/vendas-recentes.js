/**
 * GET /api/vendas-recentes?planos=aee-basico,aee-completo
 * -> { vendas: [ { nome, plano, metodo, minutos } ] }   (mais recente primeiro)
 *
 * Só vendas reais confirmadas pelo webhook (guardadas no Redis). Sai apenas o
 * primeiro nome: e-mail, CPF, telefone e valor nunca deixam o servidor.
 * Sem Redis, a lista vem vazia e a página mostra só os avisos do produto.
 */
import { rota, json, kv, env } from '../lib/core.js';
import { PLANOS } from '../lib/config.js';

export const GET = rota(async (request) => {
  const cabecalho = { 'Cache-Control': 'public, s-maxage=60' };
  if (env('VENDAS_RECENTES') === '0') return json(200, { vendas: [] }, cabecalho);

  const filtro = (new URL(request.url).searchParams.get('planos') || '').split(',').filter((id) => Object.hasOwn(PLANOS, id));
  const brutas = (await kv('LRANGE', 'vendas', 0, 199)) || [];
  const limite = Date.now() - 7 * 86400 * 1000;
  const vendas = [];

  for (const linha of brutas) {
    let v;
    try { v = JSON.parse(linha); } catch { continue; }
    const quando = Date.parse(v.registrado_em || '');
    if (!filtro.includes(v.plano) || !v.primeiro_nome || !(quando >= limite)) continue;
    vendas.push({
      nome: v.primeiro_nome,
      plano: PLANOS[v.plano].nome,
      metodo: v.metodo === 'pix' || v.metodo === 'cartao' ? v.metodo : null,
      minutos: Math.max(1, Math.floor((Date.now() - quando) / 60000)),
    });
    if (vendas.length >= 10) break;
  }

  return json(200, { vendas }, cabecalho);
});
