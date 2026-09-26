import {useEffect, useState} from 'react';
import {Link, useParams} from 'react-router-dom';
import {api, date, money, stages} from '../lib/api';
import type {AppUser} from './AppLayout';
import {Icon} from './Icon';
import {Pagination} from './Pagination';
import {SearchAutocomplete} from './Autocomplete';

function PropertySelect({items}: {items: any[]}) {
  return <label>Imóvel<select name="property_id" required>
    <option value="">Selecione</option>
    {items.filter(x => ['available', 'reserved'].includes(x.status)).map(x => <option key={x.id} value={x.id}>{x.reference_code} · {x.title} · {money(x.price)}</option>)}
  </select></label>;
}

function NegotiationBody({id, canManage, onChanged}: {id: string; canManage: boolean; onChanged?: () => void}) {
  const [d, setD] = useState<any>();
  const [props, setProps] = useState<any[]>([]);
  const [msg, setMsg] = useState('');
  const load = () => Promise.all([api('/commercial/' + id).then(r => setD(r.data)), api('/properties?listing_status=approved&available=1&per_page=100').then(r => setProps(r.data))]);
  useEffect(() => { void load() }, [id]);

  async function send(path: string, e: any) {
    e.preventDefault();
    if (path === 'close' && !confirm('Confirma o encerramento desta oportunidade?')) return;
    const form = e.currentTarget;
    try {
      await api(`/commercial/${id}/${path}`, {method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(form)))});
      setMsg('Ação registrada.'); form.reset(); load(); onChanged?.();
    } catch (x: any) { setMsg(x.message) }
  }
  async function reopen() {
    const reason = prompt('Motivo da reabertura:');
    if (!reason) return;
    await api(`/commercial/${id}/reopen`, {method: 'POST', body: JSON.stringify({reason})});
    load(); onChanged?.();
  }

  if (!d) return <p className="muted">Carregando…</p>;
  return <>
    <div className="negotiation-toolbar">
      <span className={'badge ' + d.stage}>{stages[d.stage]}</span>
      <Link className="button secondary" to={`/documentos/${id}`}>Documentos</Link>
      {canManage && ['won', 'lost'].includes(d.stage) && <button className="secondary" onClick={reopen}>Reabrir</button>}
    </div>
    {msg && <p className="notice">{msg}</p>}
    <section className="card"><h3>Necessidades do SDR</h3><p>{d.needs || 'Não informadas'} · {d.property_type || 'Tipo não informado'} · {money(d.min_value)} – {money(d.max_value)}</p></section>
    <div className="commercial-grid">
      <form className="card" onSubmit={e => send('interaction', e)}>
        <h3>Registrar atendimento</h3>
        <label>Canal<select name="type"><option value="call">Ligação</option><option value="message">Mensagem</option><option value="meeting">Reunião</option><option value="note">Observação</option></select></label>
        <label>Registro<textarea name="description" required /></label>
        <label>Próximo follow-up<input name="next_contact_at" type="datetime-local" /></label>
        <button>Registrar</button>
      </form>
      <form className="card" onSubmit={e => send('properties', e)}>
        <h3>Imóvel apresentado</h3>
        <PropertySelect items={props} />
        <label>Observações<textarea name="notes" /></label>
        <button>Registrar apresentação</button>
      </form>
      <form className="card" onSubmit={e => send('visits', e)}>
        <h3>Agendar visita</h3>
        <PropertySelect items={props} />
        <label>Data e hora<input name="scheduled_at" type="datetime-local" required /></label>
        <label>Local<input name="location" required /></label>
        <label>Observações<textarea name="notes" /></label>
        <button>Agendar</button>
      </form>
      <form className="card" onSubmit={e => send('proposals', e)}>
        <h3>Nova proposta</h3>
        <PropertySelect items={props} />
        <label>Tipo<select name="proposal_type"><option value="purchase">Compra</option><option value="rental">Locação</option></select></label>
        <label>Valor<input name="amount" type="number" min="0.01" step="0.01" required /></label>
        <label>Data<input name="proposal_date" type="date" required /></label>
        <label>Validade<input name="valid_until" type="date" /></label>
        <label>Condições<textarea name="commercial_terms" /></label>
        <button>Criar proposta</button>
      </form>
      <form className="card" onSubmit={e => send('negotiations', e)}>
        <h3>Negociação</h3>
        <label>Contraproposta<input name="amount" type="number" step="0.01" /></label>
        <label>Observações<textarea name="notes" required /></label>
        <button>Registrar negociação</button>
      </form>
      <form className="card danger-zone" onSubmit={e => send('close', e)}>
        <h3>Fechamento</h3>
        <label>Resultado<select name="outcome"><option value="won">Ganho</option><option value="lost">Perdido</option></select></label>
        <PropertySelect items={props} />
        <label>Negócio<select name="business_type"><option value="purchase">Venda</option><option value="rental">Locação</option></select></label>
        <label>Valor final<input name="final_amount" type="number" step="0.01" /></label>
        <label>Data<input name="closing_date" type="date" required /></label>
        <label>Motivo da perda<input name="loss_reason" /></label>
        <label>Referência do contrato<input name="contract_reference" /></label>
        <label>Observações<textarea name="notes" /></label>
        <button>Encerrar oportunidade</button>
      </form>
    </div>
    <div className="detail">
      <section className="card"><h3>Visitas</h3>{d.visits.map((x: any, i: number) => <p key={i}><strong>{x.property_title}</strong><small>{date(x.scheduled_at)} · {x.status}</small></p>)}{!d.visits.length && <p className="muted">Nenhuma visita.</p>}</section>
      <section className="card"><h3>Propostas</h3>{d.proposals.map((x: any, i: number) => <p key={i}><strong>{x.property_title} — {money(x.amount)}</strong><small>{x.status} · {date(x.created_at)}</small></p>)}{!d.proposals.length && <p className="muted">Nenhuma proposta.</p>}</section>
    </div>
    <section className="card">
      <h3>Histórico completo</h3>
      <div className="timeline">{d.history.map((h: any, i: number) => <div key={i}><i /><p><strong>{h.description}</strong><small>{h.user_name || 'Sistema'} · {date(h.created_at)}</small></p></div>)}</div>
    </section>
  </>;
}

export function NegotiationModal({id, canManage, onClose, onChanged}: {id: string; canManage: boolean; onClose: () => void; onChanged: () => void}) {
  const [name, setName] = useState('');
  useEffect(() => { void api('/commercial/' + id).then(r => setName(r.data.name)) }, [id]);
  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal modal-xl" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title"><span className="integration-icon on"><Icon name="briefcase" /></span><div><h2>{name || 'Negociação'}</h2><p className="muted">Workspace comercial completo</p></div></div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <div className="modal-body"><NegotiationBody id={id} canManage={canManage} onChanged={onChanged} /></div>
    </div>
  </div>;
}

export function NegotiationPage({user}: {user: AppUser}) {
  const {id} = useParams();
  const canManage = ['admin', 'manager'].includes(user.role);
  return <>
    <header><h1>Negociação</h1><Link className="button secondary" to="/operacao">Voltar</Link></header>
    <div className="card">{id && <NegotiationBody id={id} canManage={canManage} />}</div>
  </>;
}

export function Negotiations({user}: {user: AppUser}) {
  const [items, setItems] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(24);
  const [page, setPage] = useState(1);
  const [brokers, setBrokers] = useState<any[]>([]);
  const [q, setQ] = useState('');
  const [stage, setStage] = useState('');
  const [brokerId, setBrokerId] = useState('');
  const [openId, setOpenId] = useState<string | null>(null);
  const canManage = ['admin', 'manager'].includes(user.role);
  const load = () => api(`/commercial/overview?q=${encodeURIComponent(q)}&stage=${stage}&broker_id=${brokerId}&page=${page}`).then(r => { setItems(r.data); setTotal(r.total); setPerPage(r.per_page) });
  useEffect(() => { void api('/brokers').then(r => setBrokers(r.data)) }, []);
  useEffect(() => { void load() }, [stage, brokerId, page]);
  function search() { page === 1 ? load() : setPage(1) }

  return <>
    <header>
      <div><h1>Negociações</h1><p className="muted">{total} oportunidade(s) em acompanhamento comercial</p></div>
    </header>
    <div className="filters">
      <SearchAutocomplete
        value={q}
        onChange={setQ}
        onSubmit={search}
        placeholder="Buscar por nome, e-mail ou telefone"
        fetcher={term => api(`/commercial/overview?q=${encodeURIComponent(term)}`).then(r => r.data)}
        renderItem={(x: any) => <><strong>{x.name}</strong><small>{stages[x.stage]} · {x.broker_name || 'Sem corretor'}</small></>}
        onPick={(x: any) => setOpenId(x.id)}
      />
      <select value={stage} onChange={e => { setStage(e.target.value); setPage(1) }}>
        <option value="">Todas as etapas</option>
        {Object.entries(stages).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
      </select>
      <select value={brokerId} onChange={e => { setBrokerId(e.target.value); setPage(1) }}>
        <option value="">Todos os corretores</option>
        {brokers.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
      </select>
      <button onClick={search}>Buscar</button>
    </div>
    <div className="negotiation-grid">
      {items.map(x => <article key={x.id} className="negotiation-card" onClick={() => setOpenId(x.id)}>
        <div className="negotiation-card-top">
          <span className="lead-avatar">{x.name.slice(0, 2).toUpperCase()}</span>
          <div><strong>{x.name}</strong><small>{x.broker_name || 'Sem corretor'}</small></div>
        </div>
        <div className="negotiation-card-foot">
          <span className={'badge ' + x.stage}>{stages[x.stage]}</span>
          <small>Atualizado {date(x.updated_at)}</small>
        </div>
      </article>)}
      {!items.length && <div className="card empty">Nenhuma oportunidade encontrada.</div>}
    </div>
    <Pagination page={page} perPage={perPage} total={total} onChange={setPage} />
    {openId && <NegotiationModal id={openId} canManage={canManage} onClose={() => setOpenId(null)} onChanged={load} />}
  </>;
}
