import {useEffect, useMemo, useState} from 'react';
import {api} from '../lib/api';
import {Icon} from './Icon';

const typeColor: Record<string, string> = {task: '#2a78d6', visit: '#eb6834', proposal: '#1baf7a', nurturing: '#eda100', commitment: '#e87ba4'};
const typeLabel: Record<string, string> = {task: 'Tarefa', visit: 'Visita', proposal: 'Proposta', nurturing: 'Nutrição', commitment: 'Compromisso'};
const ymd = (d: Date) => d.toISOString().slice(0, 10);
const timeOf = (v: string) => new Date(v.replace(' ', 'T')).toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
const dayOf = (v: string) => v.replace(' ', 'T').slice(0, 10);

function buildGrid(cursor: Date) {
  const start = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
  const end = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0);
  const gridStart = new Date(start); gridStart.setDate(gridStart.getDate() - gridStart.getDay());
  const gridEnd = new Date(end); gridEnd.setDate(gridEnd.getDate() + (6 - gridEnd.getDay()));
  const days: Date[] = [];
  for (let d = new Date(gridStart); d <= gridEnd; d.setDate(d.getDate() + 1)) days.push(new Date(d));
  return {days, gridStart, gridEnd, monthStart: start, monthEnd: end};
}

function NewCommitmentModal({defaultDate, onClose, onCreated}: {defaultDate: string; onClose: () => void; onCreated: () => void}) {
  const [msg, setMsg] = useState('');
  async function save(e: any) {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.currentTarget));
    try { await api('/calendar-events', {method: 'POST', body: JSON.stringify(body)}); onCreated(); onClose() }
    catch (x: any) { setMsg(x.message) }
  }
  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title"><span className="integration-icon on" style={{background: '#fce4ec', color: '#e87ba4'}}><Icon name="calendar" /></span><div><h2>Novo compromisso</h2><p className="muted">Cria um item avulso na agenda</p></div></div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <form className="modal-body" onSubmit={save}>
        {msg && <p className="error">{msg}</p>}
        <label>Título<input name="title" required placeholder="Ex.: Reunião interna" /></label>
        <label>Data e hora<input name="starts_at" type="datetime-local" required defaultValue={defaultDate} /></label>
        <label>Observações<textarea name="notes" /></label>
        <input type="hidden" name="event_type" value="commitment" />
        <button>Criar compromisso</button>
      </form>
    </div>
  </div>;
}

function AgendaItemModal({item, onClose, onChanged}: {item: any; onClose: () => void; onChanged: () => void}) {
  const [msg, setMsg] = useState('');
  async function act(action: string) {
    const body: any = {action};
    if (action === 'reschedule') { const v = prompt('Nova data (AAAA-MM-DD HH:MM):'); if (!v) return; body.due_at = v }
    if (action === 'cancel') { const v = prompt('Motivo do cancelamento:'); if (!v) return; body.reason = v }
    try { await api('/tasks/' + item.id, {method: 'PUT', body: JSON.stringify(body)}); onChanged(); onClose() }
    catch (x: any) { setMsg(x.message) }
  }
  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title"><span className="integration-icon on" style={{background: '#eef2f7', color: typeColor[item.item_type]}}><i className="calendar-dot" style={{background: typeColor[item.item_type]}} /></span><div><h2>{item.title}</h2><p className="muted">{typeLabel[item.item_type] || item.item_type} · {new Date(item.starts_at.replace(' ', 'T')).toLocaleString('pt-BR')}</p></div></div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <div className="modal-body">
        {msg && <p className="error">{msg}</p>}
        <dl className="modal-meta"><dt>Status</dt><dd>{item.status}</dd></dl>
        {item.item_type === 'task' && item.status === 'pending' && <div className="modal-actions" style={{justifyContent: 'flex-start'}}>
          <button type="button" onClick={() => act('complete')}>Concluir</button>
          <button type="button" className="secondary" onClick={() => act('reschedule')}>Reagendar</button>
          <button type="button" className="secondary" onClick={() => act('cancel')}>Cancelar</button>
        </div>}
      </div>
    </div>
  </div>;
}

export function AgendaOperational() {
  const [cursor, setCursor] = useState(() => { const d = new Date(); d.setDate(1); d.setHours(0, 0, 0, 0); return d });
  const [items, setItems] = useState<any[]>([]);
  const [visibleTypes, setVisibleTypes] = useState<Record<string, boolean>>({task: true, visit: true, proposal: true, nurturing: true, commitment: true});
  const [dayList, setDayList] = useState<string | null>(null);
  const [openItem, setOpenItem] = useState<any>(null);
  const [newAt, setNewAt] = useState<string | null>(null);

  const grid = useMemo(() => buildGrid(cursor), [cursor]);
  const load = () => Promise.all([
    api(`/agenda?from=${ymd(grid.gridStart)}&to=${ymd(grid.gridEnd)}`),
    api(`/agenda?from=${ymd(grid.gridStart)}&to=${ymd(grid.gridEnd)}&type=commitment`),
  ]).then(([a, b]) => setItems([...a.data, ...b.data]));
  useEffect(() => { void load() }, [cursor]);

  const byDay = useMemo(() => {
    const map = new Map<string, any[]>();
    for (const it of items) { if (!visibleTypes[it.item_type]) continue; const k = dayOf(it.starts_at); if (!map.has(k)) map.set(k, []); map.get(k)!.push(it) }
    for (const list of map.values()) list.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
    return map;
  }, [items, visibleTypes]);

  const today = ymd(new Date());
  const monthLabel = cursor.toLocaleDateString('pt-BR', {month: 'long', year: 'numeric'});

  return <>
    <header>
      <div><h1>Tarefas e agenda</h1><p className="muted">Tarefas, visitas, propostas, nutrição e compromissos em um só calendário</p></div>
      <button onClick={() => setNewAt(ymd(new Date()) + 'T09:00')}>+ Novo compromisso</button>
    </header>
    <div className="calendar-toolbar">
      <div className="calendar-nav">
        <button type="button" className="secondary" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))}>‹</button>
        <strong>{monthLabel}</strong>
        <button type="button" className="secondary" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))}>›</button>
        <button type="button" className="secondary" onClick={() => { const d = new Date(); d.setDate(1); setCursor(d) }}>Hoje</button>
      </div>
      <div className="calendar-legend">
        {Object.entries(typeLabel).map(([k, l]) => <button type="button" key={k} className={'calendar-legend-item' + (visibleTypes[k] ? '' : ' off')} onClick={() => setVisibleTypes(v => ({...v, [k]: !v[k]}))}>
          <i style={{background: typeColor[k]}} />{l}
        </button>)}
      </div>
    </div>
    <div className="calendar-grid">
      {['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'].map(d => <div key={d} className="calendar-weekday">{d}</div>)}
      {grid.days.map(d => {
        const key = ymd(d);
        const dayItems = byDay.get(key) || [];
        const inMonth = d.getMonth() === cursor.getMonth();
        return <div key={key} className={'calendar-cell' + (inMonth ? '' : ' other-month') + (key === today ? ' today' : '')}>
          <span className="calendar-date">{d.getDate()}</span>
          <div className="calendar-items">
            {dayItems.slice(0, 3).map(it => <button type="button" key={it.item_type + it.id} className="calendar-chip" onClick={() => setOpenItem(it)}>
              <i style={{background: typeColor[it.item_type]}} />{timeOf(it.starts_at)} {it.title}
            </button>)}
            {dayItems.length > 3 && <button type="button" className="calendar-more" onClick={() => setDayList(key)}>+{dayItems.length - 3} mais</button>}
          </div>
        </div>;
      })}
    </div>
    {dayList && <div className="modal-overlay" onClick={() => setDayList(null)}>
      <div className="modal" onClick={e => e.stopPropagation()}>
        <header className="modal-header">
          <div className="integration-modal-title"><div><h2>{new Date(dayList + 'T00:00').toLocaleDateString('pt-BR', {dateStyle: 'full'})}</h2></div></div>
          <button type="button" className="modal-close" onClick={() => setDayList(null)} aria-label="Fechar"><Icon name="close" /></button>
        </header>
        <div className="modal-body">
          {(byDay.get(dayList) || []).map(it => <button type="button" key={it.item_type + it.id} className="calendar-chip calendar-chip-wide" onClick={() => { setOpenItem(it); setDayList(null) }}>
            <i style={{background: typeColor[it.item_type]}} />{timeOf(it.starts_at)} · {typeLabel[it.item_type]} · {it.title}
          </button>)}
        </div>
      </div>
    </div>}
    {openItem && <AgendaItemModal item={openItem} onClose={() => setOpenItem(null)} onChanged={load} />}
    {newAt && <NewCommitmentModal defaultDate={newAt} onClose={() => setNewAt(null)} onCreated={load} />}
  </>;
}
