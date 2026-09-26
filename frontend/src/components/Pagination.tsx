export function Pagination({page, perPage, total, onChange}: {page: number; perPage: number; total: number; onChange: (page: number) => void}) {
  const totalPages = Math.max(1, Math.ceil(total / perPage));
  if (totalPages <= 1) return null;

  const pages: (number | '…')[] = [];
  for (let p = 1; p <= totalPages; p++) {
    if (p === 1 || p === totalPages || Math.abs(p - page) <= 1) pages.push(p);
    else if (pages[pages.length - 1] !== '…') pages.push('…');
  }

  return <div className="pagination">
    <span className="pagination-info">{total} registro(s) · página {page} de {totalPages}</span>
    <div className="pagination-controls">
      <button type="button" className="secondary" disabled={page <= 1} onClick={() => onChange(page - 1)}>‹ Anterior</button>
      {pages.map((p, i) => p === '…'
        ? <span key={'e' + i} className="pagination-ellipsis">…</span>
        : <button type="button" key={p} className={p === page ? 'active' : 'secondary'} onClick={() => onChange(p)}>{p}</button>)}
      <button type="button" className="secondary" disabled={page >= totalPages} onClick={() => onChange(page + 1)}>Próxima ›</button>
    </div>
  </div>;
}
