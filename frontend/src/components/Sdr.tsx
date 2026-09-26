import {useEffect, useState} from 'react';
import {useParams, Link} from 'react-router-dom';
import {api, date} from '../lib/api';
import {Icon} from './Icon';
import {Pagination} from './Pagination';

const triageLabel: Record<string, string> = {pending: 'Pendente', contacted: 'Contatado', qualified: 'Qualificado', nurturing: 'Nutrição', no_interest: 'Sem interesse', invalid: 'Inválido', duplicate: 'Duplicado'};
const timingLabel = (x: any) => x.contact_timing === 'overdue' ? 'Vencido' : x.contact_timing === 'today' ? 'Hoje' : (triageLabel[x.triage_status] || x.triage_status);
const initials = (name: string) => name.trim().slice(0, 2).toUpperCase();

function SdrWorkBody({id, onChanged}: {id: string; onChanged?: () => void}) {
  const [lead, setLead] = useState<any>();
  const [feedback, setFeedback] = useState('');
  const load = () => api('/leads/' + id).then(r => setLead(r.data));
  useEffect(() => { void load() }, [id]);

  async function send(path: string, e: any) {
    e.preventDefault();
    try {
      await api(`/leads/${id}/${path}`, {method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(e.currentTarget)))});
      setFeedback('Ação registrada com sucesso.'); load(); onChanged?.();
    } catch (x: any) { setFeedback(x.message) }
  }

  if (!lead) return <p className="muted">Carregando…</p>;
  return <>
    {feedback && <p className="notice">{feedback}</p>}
    <form onSubmit={e => send('contact', e)}>
      <h3>Registrar contato</h3>
      <div className="grid">
        <label>Resultado<select name="result"><option value="attempt">Tentativa</option><option value="completed">Contato concluído</option></select></label>
        <label>Próximo contato<input name="next_contact_at" type="datetime-local" /></label>
        <label className="wide">Observações<textarea name="notes" /></label>
      </div>
      <button>Registrar contato</button>
    </form>
    <form onSubmit={e => send('qualify', e)}>
      <h3>Qualificação</h3>
      <div className="grid">
        <label>Resultado<select name="result"><option value="qualified">Qualificado</option><option value="nurturing">Nutrição</option><option value="commitment">Compromissos</option><option value="no_interest">Sem interesse</option><option value="invalid">Inválido</option><option value="duplicate">Duplicado</option></select></label>
        <label>Interesse<select name="interest_type"><option value="purchase">Compra</option><option value="rental">Locação</option></select></label>
        <label>Tipo de imóvel<input name="property_type" /></label>
        <label>Região<input name="region" defaultValue={lead.region} /></label>
        <label>Valor mínimo<input name="min_value" type="number" defaultValue={lead.min_value} /></label>
        <label>Valor máximo<input name="max_value" type="number" defaultValue={lead.max_value} /></label>
        <label>Momento da decisão<input name="decision_timing" /></label>
        <label>Próximo contato<input name="next_contact_at" type="datetime-local" /></label>
        <label className="wide">Motivo da nutrição/encerramento<input name="reason" /></label>
        <label className="wide">Necessidades<textarea name="needs" /></label>
        <label className="wide">Observações<textarea name="notes" /></label>
      </div>
      <button>Concluir triagem</button>
    </form>
  </>;
}

export function SdrModal({id, onClose, onChanged}: {id: string; onClose: () => void; onChanged?: () => void}) {
  const [lead, setLead] = useState<any>();
  useEffect(() => { void api('/leads/' + id).then(r => setLead(r.data)) }, [id]);
  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal modal-wide" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title">
          <span className="integration-icon on"><Icon name="users" /></span>
          <div><h2>{lead?.name || 'Triagem'}</h2><p className="muted">{lead ? `${lead.email || lead.phone || '—'} · ${lead.source_name}` : ' '}</p></div>
        </div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <div className="modal-body"><SdrWorkBody id={id} onChanged={onChanged} /></div>
    </div>
  </div>;
}

export function SdrWorkPage() {
  const {id} = useParams();
  const [lead, setLead] = useState<any>();
  useEffect(() => { void api('/leads/' + id).then(r => setLead(r.data)) }, [id]);
  return <>
    <header>
      <div><h1>Triagem — {lead?.name || ''}</h1><p className="muted">{lead ? `${lead.email || lead.phone || '—'} · ${lead.source_name}` : ' '}</p></div>
      <Link className="button secondary" to="/sdr">Voltar</Link>
    </header>
    <div className="card">{id && <SdrWorkBody id={id} />}</div>
  </>;
}

export function WorkQueue({mode}: {mode: 'inbox' | 'nurturing'}) {
  const [items, setItems] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(20);
  const [page, setPage] = useState(1);
  const [deadline, setDeadline] = useState('');
  const [openId, setOpenId] = useState<string | null>(null);
  const endpoint = mode === 'inbox' ? `/sdr/inbox?deadline=${deadline}&page=${page}` : `/sdr/nurturing?page=${page}`;
  const load = () => api(endpoint).then(r => { setItems(r.data); setTotal(r.total); setPerPage(r.per_page) });
  useEffect(() => { void load() }, [mode, deadline, page]);

  return <>
    <header>
      <div><h1>{mode === 'inbox' ? 'Caixa de entrada SDR' : 'Fila de nutrição'}</h1><p className="muted">{total} oportunidade(s)</p></div>
      {mode === 'inbox' && <select className="compact" value={deadline} onChange={e => { setDeadline(e.target.value); setPage(1) }}>
        <option value="">Todos os prazos</option><option value="near">Próximos do prazo</option><option value="overdue">Atrasados</option>
      </select>}
    </header>
    <div className="sdr-queue">
      {items.map(x => {
        const overdue = x.contact_timing === 'overdue' || (!x.first_service_at && new Date(x.first_contact_due_at) < new Date());
        return <button key={x.id} className={'sdr-card' + (overdue ? ' overdue' : x.contact_timing === 'today' ? ' today' : '')} onClick={() => setOpenId(x.id)}>
          <div className="sdr-card-main">
            <span className="lead-avatar">{initials(x.name)}</span>
            <div><strong>{x.name}</strong><small>{x.source_name || x.sdr_name || 'Sem origem'} · entrada {date(x.created_at)}</small></div>
          </div>
          <div className="sdr-card-meta">
            <span className={'badge ' + (x.contact_timing || 'qualification')}>{timingLabel(x)}</span>
            <small>{mode === 'nurturing' ? 'Próximo: ' + date(x.next_contact_at) : 'Prazo: ' + date(x.first_contact_due_at)}</small>
          </div>
        </button>;
      })}
      {!items.length && <div className="card empty">Nenhuma oportunidade nesta fila.</div>}
    </div>
    <Pagination page={page} perPage={perPage} total={total} onChange={setPage} />
    {openId && <SdrModal id={openId} onClose={() => setOpenId(null)} onChanged={load} />}
  </>;
}
