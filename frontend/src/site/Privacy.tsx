import {useEffect} from 'react';
import {useSite} from './SiteLayout';

/** Texto-base de privacidade (LGPD). Deve ser revisado pelo jurídico da imobiliária antes da publicação. */
export function Privacy() {
  const info = useSite();
  const c = info?.company;
  const name = c?.legal_name || c?.name || 'a imobiliária';
  useEffect(() => { document.title = 'Política de privacidade' }, []);
  return <div className="site-wrap site-text">
    <h1>Política de privacidade</h1>
    <p>Esta política explica como {name}{c?.cnpj ? `, CNPJ ${c.cnpj},` : ''} trata os dados pessoais enviados por este site, conforme a Lei Geral de Proteção de Dados (Lei 13.709/2018).</p>
    <h2>Quais dados coletamos</h2>
    <p>Nome, telefone, e-mail, as mensagens que você escreve no chat ou nos formulários e o imóvel que despertou seu interesse. Também registramos o endereço IP para proteger o site contra abuso.</p>
    <h2>Para que usamos</h2>
    <p>Para responder ao seu contato, apresentar imóveis compatíveis com o que você procura, agendar visitas e, se você seguir com a negociação, preparar propostas e contratos. A base legal é o seu consentimento e, quando houver negociação, os procedimentos preliminares de um contrato.</p>
    <h2>Com quem compartilhamos</h2>
    <p>Seus dados ficam restritos à nossa equipe de atendimento e aos corretores responsáveis. Só compartilhamos com terceiros quando necessário para a negociação (por exemplo, instituições financeiras na análise de crédito ou plataformas de assinatura de contratos) ou por obrigação legal.</p>
    <h2>Por quanto tempo guardamos</h2>
    <p>Enquanto durar o atendimento e pelo prazo exigido pela legislação aplicável aos negócios imobiliários.</p>
    <h2>Seus direitos</h2>
    <p>Você pode pedir acesso, correção, portabilidade ou exclusão dos seus dados, e revogar o consentimento a qualquer momento{c?.email ? <> pelo e-mail <a href={`mailto:${c.email}`}>{c.email}</a></> : ' pelos nossos canais de contato'}.</p>
  </div>;
}
