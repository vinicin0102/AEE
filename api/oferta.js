/**
 * GET /api/oferta?token=... -> { token, fim, agora, oferta: {plano: centavos}, normal: {plano: centavos} }
 *
 * Dá (ou confirma) o prazo da oferta por tempo limitado do visitante. Um token
 * válido mantém o prazo original — recarregar a página não reinicia a
 * contagem. O preço cobrado é decidido em lib/core.js (precoPlano) com base
 * neste mesmo token, então o cronômetro da página corresponde ao que o
 * servidor cobra.
 */
import { rota, json, emitirOferta, lerOferta, fimOferta } from '../lib/core.js';
import { PLANOS } from '../lib/config.js';

export const GET = rota(async (request) => {
  const recebido = new URL(request.url).searchParams.get('token') || '';
  const token = lerOferta(recebido) ? recebido : emitirOferta();
  const precos = (campo) => Object.fromEntries(Object.entries(PLANOS).map(([id, p]) => [id, p[campo] ?? p.centavos]));
  return json(200, { token, fim: fimOferta(token), agora: Date.now(), oferta: precos('centavos'), normal: precos('normal') });
});
