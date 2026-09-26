import {useEffect, useState} from 'react';
import {useNavigate, useSearchParams} from 'react-router-dom';
import {api, date, money} from '../lib/api';
import {commercialLabel, listingLabel, purposeLabel, type ListingStatus, type Property} from '../lib/property';
import type {AppUser} from './AppLayout';
import {Icon} from './Icon';
import {Pagination} from './Pagination';

type Tab = {key: string; label: string; query: string; count?: keyof Counts};
type Counts = Record<ListingStatus, number>;

function tabsFor(role: string): Tab[] {
  if (['admin', 'manager'].includes(role)) return [
    {key: 'pending', label: 'Aguardando aprovação', query: 'listing_status=pending_review', count: 'pending_review'},
    {key: 'approved', label: 'Publicados', query: 'listing_status=approved', count: 'approved'},
    {key: 'draft', label: 'Rascunhos', query: 'listing_status=draft', count: 'draft'},
    {key: 'rejected', label: 'Reprovados', query: 'listing_status=rejected', count: 'rejected'},
    {key: 'archived', label: 'Arquivados', query: 'listing_status=archived', count: 'archived'},
    {key: 'all', label: 'Todos', query: ''},
  ];
  if (role === 'broker') return [
    {key: 'mine', label: 'Meus anúncios', query: 'mine=1'},
    {key: 'approved', label: 'Vitrine', query: 'listing_status=approved'},
  ];
  return [{key: 'approved', label: 'Vitrine', query: 'listing_status=approved'}];
}

export function Properties({user}: {user: AppUser}) {
  const tabs = tabsFor(user.role);
  const [params, setParams] = useSearchParams();
  const tab = tabs.find(t => t.key === params.get('aba')) || tabs[0];
  const [items, setItems] = useState<Property[]>([]);
  const [counts, setCounts] = useState<Counts | null>(null);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(20);
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [purpose, setPurpose] = useState('');
  const navigate = useNavigate();
  const canCreate = ['admin', 'manager', 'broker'].includes(user.role);
  const moderator = ['admin', 'manager'].includes(user.role);

  useEffect(() => setPage(1), [tab.key, q, purpose]);
  useEffect(() => {
    const t = setTimeout(() => {
      const qs = [tab.query, `page=${page}`, q && `q=${encodeURIComponent(q)}`, purpose && `purpose=${purpose}`].filter(Boolean).join('&');
      api(`/properties?${qs}`).then(r => { setItems(r.data); setTotal(r.total); setPerPage(r.per_page); setCounts(r.counts) });
    }, q ? 250 : 0);
    return () => clearTimeout(t);
  }, [tab.key, page, q, purpose]);

  return <>
    <header>
      <div><h1>Imóveis</h1><p className="muted">{total} imóvel(is) nesta lista</p></div>
      {canCreate && <button onClick={() => navigate('/imoveis/novo')}>Novo imóvel</button>}
    </header>
    <div className="listing-tabs" role="tablist">
      {tabs.map(t => <button key={t.key} role="tab" aria-selected={t.key === tab.key} className={(t.key === tab.key ? 'active ' : '') + (t.key === 'pending' && counts?.pending_review ? 'attention' : '')} onClick={() => setParams({aba: t.key})}>
        {t.label}{t.count && counts ? <b>{counts[t.count]}</b> : null}
      </button>)}
    </div>
    <div className="filters">
      <input placeholder="Buscar por título, referência ou bairro" value={q} onChange={e => setQ(e.target.value)} />
      <select value={purpose} onChange={e => setPurpose(e.target.value)} aria-label="Finalidade"><option value="">Venda e locação</option><option value="purchase">Venda</option><option value="rental">Locação</option></select>
    </div>
    <div className="property-grid">
      {items.map(x => <article key={x.id} className="property-card" onClick={() => navigate('/imoveis/' + x.id)} tabIndex={0} onKeyDown={e => e.key === 'Enter' && navigate('/imoveis/' + x.id)}>
        <div className="property-card-photo">
          {x.cover_url ? <img src={x.cover_url} alt={x.title} loading="lazy" /> : <span className="property-card-noimg"><Icon name="building" /></span>}
          {x.listing_status === 'approved'
            ? <span className={'badge status-' + x.status}>{commercialLabel[x.status] || x.status}</span>
            : <span className={'badge listing-' + x.listing_status}>{listingLabel[x.listing_status]}</span>}
        </div>
        <div className="property-card-body">
          <strong>{x.title}</strong>
          <small>{x.reference_code} · {x.neighborhood || x.region || purposeLabel[x.purpose]}</small>
          <b>{money(x.price)}{x.purpose === 'both' && x.rental_price ? <small> · aluguel {money(x.rental_price)}</small> : null}</b>
          {moderator && x.listing_status === 'pending_review' && <small className="card-meta">Enviado por {x.creator_name || '—'} · {date(x.submitted_at || undefined)}</small>}
          {x.listing_status === 'rejected' && x.review_notes && <small className="card-meta rejected">Motivo: {x.review_notes}</small>}
          {x.listing_status === 'draft' && <small className="card-meta">{x.photo_count} foto(s) · continue o cadastro</small>}
        </div>
      </article>)}
      {!items.length && <div className="card empty">{tab.key === 'pending' ? 'Nenhum anúncio aguardando aprovação.' : tab.key === 'mine' ? 'Você ainda não cadastrou imóveis.' : 'Nenhum imóvel encontrado.'}</div>}
    </div>
    <Pagination page={page} perPage={perPage} total={total} onChange={setPage} />
  </>;
}
