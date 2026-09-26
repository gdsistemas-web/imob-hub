import {useEffect, useState} from 'react';
import {Link, useParams} from 'react-router-dom';
import {api, date, money, stages} from '../lib/api';
import {Icon} from './Icon';
import {Pagination} from './Pagination';
import {SearchAutocomplete} from './Autocomplete';

type Lead = {
  id: string; name: string; email?: string; phone?: string; stage: string; interest_type: string;
  region?: string; min_value?: string; max_value?: string; source_name: string; created_at: string;
  history?: any[]; property_interest?: string;
};

function LeadDetailBody({id, onChanged}: {id: string; onChanged?: () => void}) {
  const [lead, setLead] = useState<Lead>();
  const [error, setError] = useState('');
  const load = () => api('/leads/' + id).then(r => setLead(r.data));
  useEffect(() => { void load() }, [id]);

  async function move(stage: string) {
    let loss_reason;
    if (stage === 'lost') loss_reason = prompt('Motivo da perda:') || '';
    try { await api(`/leads/${id}/stage`, {method: 'PUT', body: JSON.stringify({stage, loss_reason})}); load(); onChanged?.() }
    catch (e: any) { setError(e.message) }
  }
  async function task(e: any) {
    e.preventDefault();
    const form = e.currentTarget;
    try {
      await api(`/leads/${id}/tasks`, {method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(form)))});
      form.reset(); load(); onChanged?.();
    } catch (x: any) { setError(x.message) }
  }

  if (!lead) return <p className="muted">Carregando…</p>;
  return <>
    {error && <p className="error">{error}</p>}
    <dl className="modal-meta">
      <dt>Etapa</dt><dd><span className={'badge ' + lead.stage}>{stages[lead.stage]}</span></dd>
      <dt>Interesse</dt><dd>{lead.interest_type === 'purchase' ? 'Compra' : 'Locação'}</dd>
      <dt>Região</dt><dd>{lead.region || '—'}</dd>
      <dt>Faixa de valor</dt><dd>{money(lead.min_value)} – {money(lead.max_value)}</dd>
      <dt>Imóvel</dt><dd>{lead.property_interest || '—'}</dd>
    </dl>
    <div>
      <h3>Mover no funil</h3>
      <div className="stage-buttons">{Object.entries(stages).map(([k, v]) =>
        <button key={k} className="secondary" disabled={k === lead.stage} onClick={() => move(k)}>{v}</button>)}</div>
    </div>
    <form onSubmit={task}>
      <h3>Novo follow-up</h3>
      <div className="grid">
        <label>Título<input name="title" required placeholder="Ex.: ligar para confirmar visita" /></label>
        <label>Data e hora<input name="due_at" type="datetime-local" required /></label>
      </div>
      <button>Criar tarefa</button>
    </form>
    <div>
      <h3>Histórico</h3>
      <div className="timeline">{lead.history?.map((h, i) =>
        <div key={i}><i /><p><strong>{h.description}</strong><small>{h.user_name || 'Integração'} · {date(h.created_at)}</small></p></div>)}
        {!lead.history?.length && <p className="muted">Sem histórico ainda.</p>}
      </div>
    </div>
  </>;
}

export function LeadModal({id, onClose, onChanged}: {id: string; onClose: () => void; onChanged?: () => void}) {
  const [lead, setLead] = useState<Lead>();
  useEffect(() => { void api('/leads/' + id).then(r => setLead(r.data)) }, [id]);
  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal modal-wide" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title">
          <span className="integration-icon on"><Icon name="leads" /></span>
          <div><h2>{lead?.name || 'Lead'}</h2><p className="muted">{lead ? `${lead.source_name} · ${lead.email || lead.phone || '—'}` : ' '}</p></div>
        </div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <div className="modal-body">
        <LeadDetailBody id={id} onChanged={() => { onChanged?.(); void api('/leads/' + id).then(r => setLead(r.data)) }} />
      </div>
    </div>
  </div>;
}

export function LeadDetailPage() {
  const {id} = useParams();
  const [lead, setLead] = useState<Lead>();
  useEffect(() => { void api('/leads/' + id).then(r => setLead(r.data)) }, [id]);
  return <>
    <header>
      <div><h1>{lead?.name || 'Lead'}</h1><p className="muted">{lead ? `${lead.source_name} · ${lead.email || lead.phone || '—'}` : ' '}</p></div>
      <Link className="button secondary" to="/leads">Voltar</Link>
    </header>
    <div className="detail"><section className="card">{id && <LeadDetailBody id={id} onChanged={() => void api('/leads/' + id).then(r => setLead(r.data))} />}</section></div>
  </>;
}

export function Leads() {
  const [items, setItems] = useState<Lead[]>([]);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(20);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [stage, setStage] = useState('');
  const [openId, setOpenId] = useState<string | null>(null);
  const load = () => api(`/leads?q=${encodeURIComponent(q)}&stage=${stage}&page=${page}`).then(r => { setItems(r.data); setTotal(r.total); setPerPage(r.per_page) });
  useEffect(() => { void load() }, [page, stage]);
  function search() { page === 1 ? load() : setPage(1) }

  return <>
    <header>
      <div><h1>Leads e oportunidades</h1><p className="muted">{total} registro(s) encontrado(s)</p></div>
      <Link className="button" to="/leads/novo">Novo lead</Link>
    </header>
    <div className="filters">
      <SearchAutocomplete
        value={q}
        onChange={setQ}
        onSubmit={search}
        placeholder="Buscar nome, e-mail ou telefone"
        fetcher={term => api(`/leads?q=${encodeURIComponent(term)}`).then(r => r.data)}
        renderItem={(x: Lead) => <><strong>{x.name}</strong><small>{x.email || x.phone || x.source_name}</small></>}
        onPick={(x: Lead) => setOpenId(x.id)}
      />
      <select value={stage} onChange={e => { setStage(e.target.value); setPage(1) }}>
        <option value="">Todas as etapas</option>
        {Object.entries(stages).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
      </select>
      <button onClick={search}>Buscar</button>
    </div>
    <section className="dash-card">
      <div className="compact-table">
        <table>
          <thead><tr><th>Contato</th><th>Origem</th><th>Interesse</th><th>Etapa</th><th>Faixa</th><th>Entrada</th></tr></thead>
          <tbody>{items.map(x =>
            <tr key={x.id} onClick={() => setOpenId(x.id)}>
              <td><span className="lead-avatar">{x.name.slice(0, 2).toUpperCase()}</span><div><strong>{x.name}</strong><small>{x.email || x.phone}</small></div></td>
              <td>{x.source_name}</td>
              <td>{x.interest_type === 'purchase' ? 'Compra' : 'Locação'} · {x.region || '—'}</td>
              <td><span className={'badge ' + x.stage}>{stages[x.stage]}</span></td>
              <td>{money(x.min_value)} – {money(x.max_value)}</td>
              <td>{date(x.created_at)}</td>
            </tr>)}
          </tbody>
        </table>
        {!items.length && <div className="empty">Nenhum lead encontrado.</div>}
      </div>
    </section>
    <Pagination page={page} perPage={perPage} total={total} onChange={setPage} />
    {openId && <LeadModal id={openId} onClose={() => setOpenId(null)} onChanged={load} />}
  </>;
}
