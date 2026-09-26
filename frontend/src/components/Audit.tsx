import {useEffect, useState} from 'react';
import {api, date} from '../lib/api';
import {Icon} from './Icon';
import {Pagination} from './Pagination';

function AuditModal({event, onClose}: {event: any; onClose: () => void}) {
  let metadata = '';
  try { metadata = event.metadata ? JSON.stringify(JSON.parse(event.metadata), null, 2) : '' } catch { metadata = event.metadata || '' }
  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title"><span className="integration-icon on"><Icon name="file" /></span><div><h2>{event.action}</h2><p className="muted">{event.entity_type} · {date(event.created_at)}</p></div></div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <div className="modal-body">
        <dl className="modal-meta">
          <dt>Usuário</dt><dd>{event.user_name || 'Sistema'}</dd>
          <dt>Resumo</dt><dd>{event.summary}</dd>
          <dt>Entidade</dt><dd>{event.entity_type} · {event.entity_id}</dd>
        </dl>
        {metadata && <div><h3>Metadados</h3><pre className="audit-metadata">{metadata}</pre></div>}
      </div>
    </div>
  </div>;
}

export function Audit() {
  const [items, setItems] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(20);
  const [page, setPage] = useState(1);
  const [users, setUsers] = useState<any[]>([]);
  const [actions, setActions] = useState<string[]>([]);
  const [entityTypes, setEntityTypes] = useState<string[]>([]);
  const [q, setQ] = useState('');
  const [userId, setUserId] = useState('');
  const [action, setAction] = useState('');
  const [entityType, setEntityType] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [open, setOpen] = useState<any>(null);

  const load = () => api(`/audit?q=${encodeURIComponent(q)}&user_id=${userId}&action=${action}&entity_type=${entityType}&from=${from}&to=${to}&page=${page}`)
    .then(r => { setItems(r.data); setTotal(r.total); setPerPage(r.per_page); setActions(r.actions || []); setEntityTypes(r.entity_types || []) });
  useEffect(() => { void load() }, [page]);
  useEffect(() => { void api('/team').then(r => setUsers(r.data)).catch(() => {}) }, []);
  function search() { page === 1 ? load() : setPage(1) }

  return <>
    <header><div><h1>Auditoria gerencial</h1><p className="muted">{total} evento(s) encontrado(s)</p></div></header>
    <div className="filters">
      <input placeholder="Buscar no resumo" value={q} onChange={e => setQ(e.target.value)} onKeyDown={e => e.key === 'Enter' && search()} />
      <select value={userId} onChange={e => { setUserId(e.target.value); setPage(1) }}><option value="">Todos os usuários</option>{users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}</select>
      <select value={action} onChange={e => { setAction(e.target.value); setPage(1) }}><option value="">Todas as ações</option>{actions.map(a => <option key={a} value={a}>{a}</option>)}</select>
      <select value={entityType} onChange={e => { setEntityType(e.target.value); setPage(1) }}><option value="">Todas as entidades</option>{entityTypes.map(t => <option key={t} value={t}>{t}</option>)}</select>
      <input type="date" value={from} onChange={e => setFrom(e.target.value)} />
      <input type="date" value={to} onChange={e => setTo(e.target.value)} />
      <button onClick={search}>Buscar</button>
    </div>
    <section className="dash-card">
      <div className="compact-table">
        <table>
          <thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>Resumo</th></tr></thead>
          <tbody>{items.map((x, i) => <tr key={i} onClick={() => setOpen(x)}>
            <td>{date(x.created_at)}</td>
            <td><span className="lead-avatar">{(x.user_name || 'SI').slice(0, 2).toUpperCase()}</span>{x.user_name || 'Sistema'}</td>
            <td><span className="badge qualification">{x.action}</span></td>
            <td>{x.entity_type}</td>
            <td>{x.summary}</td>
          </tr>)}</tbody>
        </table>
        {!items.length && <div className="empty">Nenhum evento auditável.</div>}
      </div>
    </section>
    <Pagination page={page} perPage={perPage} total={total} onChange={setPage} />
    {open && <AuditModal event={open} onClose={() => setOpen(null)} />}
  </>;
}
