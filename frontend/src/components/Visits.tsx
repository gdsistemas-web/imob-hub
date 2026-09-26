import {useEffect, useState} from 'react';
import {api, date} from '../lib/api';
import {Icon} from './Icon';

type VisitStatus = {id: string; key: string; label: string; color: string; sort_order: number; blocks_schedule: number | boolean; requires_reason: number | boolean};
type Visit = {id: string; contact_name: string; property_title: string; broker_name: string; scheduled_at: string; location: string; status: string; notes?: string; result?: string; feedback?: string; cancellation_reason?: string};

function NewStatusModal({onClose, onCreated}: {onClose: () => void; onCreated: () => void}) {
  const [label, setLabel] = useState('');
  const [color, setColor] = useState('#2563eb');
  const [msg, setMsg] = useState('');
  async function save(e: any) {
    e.preventDefault();
    try { await api('/visit-statuses', {method: 'POST', body: JSON.stringify({label, color})}); onCreated(); onClose() }
    catch (x: any) { setMsg(x.message) }
  }
  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title"><span className="integration-icon on"><Icon name="calendar" /></span><div><h2>Nova coluna</h2><p className="muted">Cria um novo status para o quadro de visitas</p></div></div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <form className="modal-body" onSubmit={save}>
        {msg && <p className="error">{msg}</p>}
        <label>Nome da coluna<input value={label} onChange={e => setLabel(e.target.value)} required placeholder="Ex.: Em confirmação" /></label>
        <label>Cor<input type="color" value={color} onChange={e => setColor(e.target.value)} /></label>
        <button>Criar coluna</button>
      </form>
    </div>
  </div>;
}

function VisitModal({visit, statuses, onClose, onChanged}: {visit: Visit; statuses: VisitStatus[]; onClose: () => void; onChanged: () => void}) {
  const [status, setStatus] = useState(visit.status);
  const [notes, setNotes] = useState(visit.notes || '');
  const [result, setResult] = useState(visit.result || '');
  const [feedback, setFeedback] = useState(visit.feedback || '');
  const [msg, setMsg] = useState('');
  const [busy, setBusy] = useState(false);

  async function save(e: any) {
    e.preventDefault();
    setBusy(true);
    const target = statuses.find(s => s.key === status);
    let cancellation_reason: string | undefined;
    if (target?.requires_reason) {
      cancellation_reason = prompt('Motivo:') || '';
      if (!cancellation_reason) { setBusy(false); return }
    }
    try {
      await api(`/visits/${visit.id}`, {method: 'PUT', body: JSON.stringify({status, notes, result, feedback, cancellation_reason})});
      setMsg('Visita atualizada.'); onChanged();
    } catch (x: any) { setMsg(x.message) } finally { setBusy(false) }
  }

  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title"><span className="integration-icon on"><Icon name="home" /></span><div><h2>{visit.property_title}</h2><p className="muted">{visit.contact_name} · {date(visit.scheduled_at)}</p></div></div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <form className="modal-body" onSubmit={save}>
        {msg && <p className="notice">{msg}</p>}
        <dl className="modal-meta">
          <dt>Corretor</dt><dd>{visit.broker_name}</dd>
          <dt>Local</dt><dd>{visit.location}</dd>
        </dl>
        <label>Status<select value={status} onChange={e => setStatus(e.target.value)}>
          {statuses.map(s => <option key={s.id} value={s.key}>{s.label}</option>)}
        </select></label>
        <label>Resultado<input value={result} onChange={e => setResult(e.target.value)} placeholder="Ex.: Cliente gostou do imóvel" /></label>
        <label>Feedback<textarea value={feedback} onChange={e => setFeedback(e.target.value)} /></label>
        <label>Observações<textarea value={notes} onChange={e => setNotes(e.target.value)} /></label>
        <button disabled={busy}>Salvar</button>
      </form>
    </div>
  </div>;
}

export function Visits() {
  const [items, setItems] = useState<Visit[]>([]);
  const [statuses, setStatuses] = useState<VisitStatus[]>([]);
  const [open, setOpen] = useState<Visit | null>(null);
  const [newStatus, setNewStatus] = useState(false);
  const [msg, setMsg] = useState('');
  const [dragId, setDragId] = useState<string | null>(null);

  const load = () => api('/visits').then(r => setItems(r.data));
  const loadStatuses = () => api('/visit-statuses').then(r => setStatuses(r.data));
  useEffect(() => { void load(); void loadStatuses() }, []);

  async function moveTo(visit: Visit, key: string) {
    if (visit.status === key) return;
    const target = statuses.find(s => s.key === key);
    let cancellation_reason: string | undefined;
    if (target?.requires_reason) {
      cancellation_reason = prompt('Motivo:') || '';
      if (!cancellation_reason) return;
    }
    try { await api(`/visits/${visit.id}`, {method: 'PUT', body: JSON.stringify({status: key, notes: visit.notes, result: visit.result, feedback: visit.feedback, cancellation_reason})}); load() }
    catch (x: any) { setMsg(x.message) }
  }

  async function removeStatus(s: VisitStatus) {
    if (!confirm(`Remover a coluna "${s.label}"?`)) return;
    try { await api(`/visit-statuses/${s.id}`, {method: 'DELETE'}); loadStatuses() }
    catch (x: any) { setMsg(x.message) }
  }

  return <>
    <header>
      <div><h1>Visitas</h1><p className="muted">Arraste os cartões entre as colunas para atualizar o status</p></div>
      <button className="secondary" onClick={() => setNewStatus(true)}>+ Nova coluna</button>
    </header>
    {msg && <p className="notice">{msg}</p>}
    <div className="kanban-board">
      {statuses.map(s => {
        const cards = items.filter(v => v.status === s.key);
        return <div key={s.id} className="kanban-column"
          onDragOver={e => e.preventDefault()}
          onDrop={() => { if (dragId) { const v = items.find(x => x.id === dragId); if (v) void moveTo(v, s.key) } }}>
          <div className="kanban-column-head" style={{borderTopColor: s.color}}>
            <span><i style={{background: s.color}} />{s.label}</span>
            <div><small>{cards.length}</small>{!cards.length && <button type="button" className="kanban-remove" onClick={() => removeStatus(s)} aria-label="Remover coluna"><Icon name="close" /></button>}</div>
          </div>
          <div className="kanban-column-body">
            {cards.map(v => <article key={v.id} className="kanban-card" draggable
              onDragStart={() => setDragId(v.id)} onDragEnd={() => setDragId(null)}
              onClick={() => setOpen(v)}>
              <strong>{v.property_title}</strong>
              <small>{v.contact_name}</small>
              <small>{date(v.scheduled_at)} · {v.broker_name}</small>
            </article>)}
            {!cards.length && <p className="kanban-empty">Nenhuma visita.</p>}
          </div>
        </div>;
      })}
    </div>
    {open && <VisitModal visit={open} statuses={statuses} onClose={() => setOpen(null)} onChanged={() => { load(); setOpen(null) }} />}
    {newStatus && <NewStatusModal onClose={() => setNewStatus(false)} onCreated={loadStatuses} />}
  </>;
}
