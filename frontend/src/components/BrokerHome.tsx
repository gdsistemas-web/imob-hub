import {useEffect, useState} from 'react';
import {Link} from 'react-router-dom';
import {api, money, stages} from '../lib/api';
import {usePwaInstall} from '../lib/pwa';
import type {AppUser} from './AppLayout';
import {Icon} from './Icon';

type Opp = {id: string; name: string; stage: string; region?: string; received_at?: string | null; service_started_at?: string | null; distributed_at?: string};
type AgendaItem = {id: string; opportunity_id?: string | null; title: string; item_type: string; starts_at: string; status: string};
const typeLabel: Record<string, string> = {visit: 'Visita', task: 'Tarefa', proposal: 'Proposta', commitment: 'Compromisso', nurturing: 'Nutrição'};
const iso = (d: Date) => d.toLocaleDateString('sv-SE'); // AAAA-MM-DD no fuso local
const when = (v: string) => {
  const d = new Date(v.replace(' ', 'T')), today = new Date();
  const day = d.toDateString() === today.toDateString() ? 'Hoje' : d.toDateString() === new Date(Date.now() + 864e5).toDateString() ? 'Amanhã' : new Intl.DateTimeFormat('pt-BR', {weekday: 'short', day: '2-digit'}).format(d);
  return `${day} · ${new Intl.DateTimeFormat('pt-BR', {hour: '2-digit', minute: '2-digit'}).format(d)}`;
};

/** Início do corretor: o que precisa de ação agora, agenda dos próximos dias e atalhos. É a tela de abertura do app. */
export function BrokerHome({user}: {user: AppUser}) {
  const [opps, setOpps] = useState<Opp[]>([]);
  const [agenda, setAgenda] = useState<AgendaItem[]>([]);
  const [chats, setChats] = useState(0);
  const [listings, setListings] = useState<Record<string, number>>({});
  const [metrics, setMetrics] = useState<any>(null);
  const [feedback, setFeedback] = useState('');
  const [credit, setCredit] = useState<Record<string, number>>({});
  const pwa = usePwaInstall();

  const load = () => {
    api('/my-opportunities').then(r => setOpps(r.data)).catch(() => {});
    api(`/agenda?from=${iso(new Date())}&to=${iso(new Date(Date.now() + 3 * 864e5))}`).then(r => setAgenda(r.data.filter((x: AgendaItem) => !['done', 'cancelled', 'completed', 'accepted', 'rejected'].includes(x.status)))).catch(() => {});
    api('/conversations').then(r => setChats(r.pending || 0)).catch(() => {});
    api('/properties?mine=1&per_page=1').then(r => setListings(r.counts || {})).catch(() => {});
    api('/commercial/metrics').then(r => setMetrics(r.data)).catch(() => {});
    api('/credit?queue=draft').then(r => setCredit(r.counts || {})).catch(() => {});
  };
  useEffect(load, []);

  async function status(id: string, s: 'received' | 'started') {
    try { await api(`/leads/${id}/broker-status`, {method: 'POST', body: JSON.stringify({status: s})}); setFeedback(s === 'received' ? 'Recebimento confirmado.' : 'Atendimento iniciado.'); load() }
    catch (e: any) { setFeedback(e.message) }
  }

  const waiting = opps.filter(o => !o.received_at || !o.service_started_at);
  const answered = credit.awaiting_contract || 0;
  const active = opps.filter(o => !['won', 'lost'].includes(o.stage));
  const first = user.name.split(' ')[0];
  const greeting = new Date().getHours() < 12 ? 'Bom dia' : new Date().getHours() < 18 ? 'Boa tarde' : 'Boa noite';

  return <div className="broker-home">
    <header className="bh-head">
      <div><h1>{greeting}, {first}!</h1><p className="muted">{new Intl.DateTimeFormat('pt-BR', {weekday: 'long', day: 'numeric', month: 'long'}).format(new Date())}</p></div>
    </header>

    {pwa.canInstall && <div className="install-banner"><Icon name="phone" /><div><strong>Instale o app do corretor</strong><small>Abre direto na sua carteira, em tela cheia, com atalho na tela inicial.</small></div><button onClick={pwa.install}>Instalar</button></div>}
    {pwa.iosHint && <div className="install-banner"><Icon name="phone" /><div><strong>Instale no iPhone</strong><small>Toque em Compartilhar e depois em “Adicionar à Tela de Início”.</small></div><button className="secondary" onClick={pwa.dismissIos}>Ok</button></div>}
    {feedback && <p className="notice">{feedback}</p>}

    <nav className="quick-actions" aria-label="Atalhos">
      <Link to="/imoveis/novo"><Icon name="building" /><span>Cadastrar imóvel</span></Link>
      <Link to="/fichas/nova"><Icon name="file" /><span>Ficha de interesse</span></Link>
      <Link to="/conversas"><Icon name="chat" /><span>Conversas</span>{chats > 0 && <b>{chats}</b>}</Link>
      <Link to="/agenda-operacional"><Icon name="calendar" /><span>Agenda</span></Link>
    </nav>

    <section className="bh-section">
      <h2>Precisa de você <small>{waiting.length + (listings.rejected || 0) + chats + (credit.pending_docs || 0) + answered}</small></h2>
      <ul className="bh-list">
        {chats > 0 && <li><Link to="/conversas" className="bh-row"><span className="dot orange" /><div><strong>{chats} conversa{chats > 1 ? 's' : ''} aguardando atendimento</strong><small>Clientes do site e do WhatsApp esperando resposta</small></div><Icon name="chat" /></Link></li>}
        {!!credit.pending_docs && <li><Link to="/fichas?aba=pending" className="bh-row"><span className="dot red" /><div><strong>{credit.pending_docs} ficha{credit.pending_docs > 1 ? 's' : ''} com pendência</strong><small>O analista pediu documentos ou ajustes</small></div><Icon name="file" /></Link></li>}
        {answered > 0 && <li><Link to="/fichas?aba=decided" className="bh-row"><span className="dot green" /><div><strong>{answered} ficha{answered > 1 ? 's' : ''} aprovada{answered > 1 ? 's' : ''} sem contrato</strong><small>Repasse a resposta ao cliente e emita o contrato</small></div><Icon name="check" /></Link></li>}
        {!!listings.rejected && <li><Link to="/imoveis?aba=mine" className="bh-row"><span className="dot red" /><div><strong>{listings.rejected} anúncio{listings.rejected > 1 ? 's' : ''} reprovado{listings.rejected > 1 ? 's' : ''}</strong><small>Veja o motivo, corrija e reenvie</small></div><Icon name="building" /></Link></li>}
        {waiting.map(o => <li key={o.id} className="bh-row">
          <span className="dot blue" />
          <Link to={'/comercial/' + o.id}><strong>{o.name}</strong><small>{!o.received_at ? 'Nova oportunidade — confirme o recebimento' : 'Recebida — inicie o atendimento'} · {o.region || stages[o.stage]}</small></Link>
          {!o.received_at ? <button onClick={() => status(o.id, 'received')}>Confirmar</button> : <button className="secondary" onClick={() => status(o.id, 'started')}>Iniciar</button>}
        </li>)}
        {!waiting.length && !chats && !listings.rejected && !credit.pending_docs && !answered && <li className="bh-empty"><Icon name="check" /> Tudo em dia por aqui.</li>}
      </ul>
    </section>

    <section className="bh-section">
      <h2>Próximos compromissos <Link to="/agenda-operacional">Ver agenda</Link></h2>
      <ul className="bh-list">
        {agenda.slice(0, 6).map(a => <li key={a.item_type + a.id}>
          <Link to={a.opportunity_id ? '/comercial/' + a.opportunity_id : '/agenda-operacional'} className="bh-row">
            <span className={'dot type-' + a.item_type} />
            <div><strong>{a.title}</strong><small>{typeLabel[a.item_type] || a.item_type} · {when(a.starts_at)}</small></div>
          </Link>
        </li>)}
        {!agenda.length && <li className="bh-empty">Nenhum compromisso nos próximos 3 dias.</li>}
      </ul>
    </section>

    <section className="bh-section">
      <h2>Meus anúncios <Link to="/imoveis?aba=mine">Ver todos</Link></h2>
      <div className="bh-chips">
        {([['draft', 'Rascunhos'], ['pending_review', 'Em análise'], ['rejected', 'Reprovados'], ['approved', 'Publicados']] as const).map(([k, l]) => <Link key={k} to="/imoveis?aba=mine" className={'bh-chip ' + k}><b>{listings[k] || 0}</b><span>{l}</span></Link>)}
      </div>
    </section>

    {metrics && <section className="bh-section">
      <h2>Seus números <Link to="/indicadores">Painel completo</Link></h2>
      <div className="bh-stats">
        <div><b>{active.length}</b><span>Oportunidades ativas</span></div>
        <div><b>{metrics.visits_completed || 0}</b><span>Visitas realizadas</span></div>
        <div><b>{metrics.proposals_created || 0}</b><span>Propostas</span></div>
        <div><b>{Number(metrics.sales_closed || 0) + Number(metrics.rentals_closed || 0)}</b><span>Negócios fechados</span></div>
        <div className="wide"><b>{money(Number(metrics.sales_value || 0) + Number(metrics.rentals_value || 0))}</b><span>Volume fechado</span></div>
      </div>
    </section>}
  </div>;
}
