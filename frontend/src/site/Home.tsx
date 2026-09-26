import {useEffect, useState} from 'react';
import {Link, useSearchParams} from 'react-router-dom';
import {api, money} from '../lib/api';
import {Icon} from '../components/Icon';
import {openChat, useSite, type PublicProperty} from './SiteLayout';

type Filters = {types: {value: string; total: number}[]; cities: {city: string; total: number; neighborhoods?: string[]}[]};
const PRICE_STEPS = {purchase: [300000, 500000, 750000, 1000000, 1500000, 2500000], rental: [1500, 2500, 3500, 5000, 8000, 12000]};

export function Home() {
  const info = useSite();
  const [params, setParams] = useSearchParams();
  const purpose = params.get('finalidade') === 'alugar' ? 'rental' : 'purchase';
  const [filters, setFilters] = useState<Filters | null>(null);
  const [items, setItems] = useState<PublicProperty[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);

  useEffect(() => { api('/public/filters').then(r => setFilters(r.data)).catch(() => {}) }, []);
  useEffect(() => { document.title = info?.company.name ? `${info.company.name} · Imóveis para comprar e alugar` : 'Imóveis' }, [info]);

  const query = new URLSearchParams({purpose});
  const map: Record<string, string> = {tipo: 'type', cidade: 'city', bairro: 'neighborhood', max: 'max_price', quartos: 'bedrooms', ordem: 'sort', busca: 'q'};
  for (const [k, v] of Object.entries(map)) { const x = params.get(k); if (x) query.set(v, x) }
  const key = query.toString();

  useEffect(() => { setPage(1) }, [key]);
  useEffect(() => {
    setLoading(true);
    api(`/public/properties?${key}&page=${page}&per_page=12`).then(r => {
      setItems(prev => page === 1 ? r.data : [...prev, ...r.data]); setTotal(r.total);
    }).finally(() => setLoading(false));
  }, [key, page]);

  function update(changes: Record<string, string>) {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(changes)) v ? next.set(k, v) : next.delete(k);
    if ('cidade' in changes) next.delete('bairro');
    if ('finalidade' in changes) next.delete('max');
    setParams(next, {replace: true});
  }

  function search(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(e.currentTarget)) as Record<string, string>;
    update({tipo: d.tipo, cidade: d.cidade, max: d.max, quartos: d.quartos});
    document.getElementById('imoveis')?.scrollIntoView({behavior: 'smooth'});
  }

  const city = filters?.cities.find(c => c.city === params.get('cidade'));
  const noun = purpose === 'rental' ? 'para alugar' : 'à venda';

  return <>
    <section className="site-hero">
      <div className="site-wrap">
        <h1>{info?.site.hero_title || ' '}</h1>
        <p>{info?.site.hero_subtitle}</p>
        <form className="hero-search" onSubmit={search} key={key}>
          <div className="hero-tabs" role="tablist" aria-label="Finalidade">
            {([['comprar', 'Comprar', 'purchase'], ['alugar', 'Alugar', 'rental']] as const).map(([v, l, p]) => <button key={v} type="button" role="tab" aria-selected={purpose === p} className={purpose === p ? 'on' : ''} onClick={() => update({finalidade: v})}>{l}</button>)}
          </div>
          <div className="hero-fields">
            <label>Tipo<select name="tipo" defaultValue={params.get('tipo') || ''}><option value="">Todos</option>{filters?.types.map(t => <option key={t.value} value={t.value}>{t.value}</option>)}</select></label>
            <label>Cidade<select name="cidade" defaultValue={params.get('cidade') || ''}><option value="">Todas</option>{filters?.cities.map(c => <option key={c.city} value={c.city}>{c.city}</option>)}</select></label>
            <label>Até<select name="max" defaultValue={params.get('max') || ''}><option value="">Qualquer valor</option>{PRICE_STEPS[purpose].map(v => <option key={v} value={v}>{money(v)}</option>)}</select></label>
            <label>Quartos<select name="quartos" defaultValue={params.get('quartos') || ''}><option value="">Qualquer</option>{[1, 2, 3, 4].map(n => <option key={n} value={n}>{n}+</option>)}</select></label>
            <button><Icon name="search" /> Buscar</button>
          </div>
        </form>
      </div>
    </section>

    <section className="site-wrap site-results" id="imoveis" aria-busy={loading}>
      <div className="results-head">
        <div>
          <h2>Imóveis {noun}{city ? ` em ${city.city}` : ''}</h2>
          <p>{loading && page === 1 ? 'Buscando…' : `${total} ${total === 1 ? 'imóvel encontrado' : 'imóveis encontrados'}`}</p>
        </div>
        <div className="results-tools">
          {city?.neighborhoods && <select aria-label="Bairro" value={params.get('bairro') || ''} onChange={e => update({bairro: e.target.value})}><option value="">Todos os bairros</option>{city.neighborhoods.map(n => <option key={n} value={n}>{n}</option>)}</select>}
          <select aria-label="Ordenar" value={params.get('ordem') || ''} onChange={e => update({ordem: e.target.value})}><option value="">Mais recentes</option><option value="price_asc">Menor preço</option><option value="price_desc">Maior preço</option></select>
        </div>
      </div>
      <div className="site-grid">{items.map(p => <PropertyCard key={p.id} p={p} purpose={purpose} />)}</div>
      {!loading && !items.length && <div className="site-empty">
        <p>Nenhum imóvel com esses filtros agora.</p>
        <button type="button" onClick={() => openChat()}>Conte o que procura para um corretor</button>
      </div>}
      {items.length < total && <div className="load-more"><button type="button" className="ghost" disabled={loading} onClick={() => setPage(p => p + 1)}>{loading ? 'Carregando…' : 'Ver mais imóveis'}</button></div>}
    </section>

    <section className="site-cta">
      <div className="site-wrap">
        <div><h2>Não encontrou o que procura?</h2><p>Conte para um corretor o bairro, o orçamento e o que não pode faltar. A gente busca para você.</p></div>
        <button type="button" onClick={() => openChat()}>Falar com um corretor</button>
      </div>
    </section>
  </>;
}

export function PropertyCard({p, purpose}: {p: PublicProperty; purpose?: 'purchase' | 'rental'}) {
  const showRent = purpose === 'rental' || (!purpose && p.purpose === 'rental');
  const price = showRent ? p.rent : p.sale ?? p.rent;
  const facts = [p.area_built || p.area_total ? `${Math.round(Number(p.area_built || p.area_total))} m²` : null, p.bedrooms ? `${p.bedrooms} quarto${p.bedrooms > 1 ? 's' : ''}` : null, p.parking_spaces ? `${p.parking_spaces} vaga${p.parking_spaces > 1 ? 's' : ''}` : null].filter(Boolean);
  return <Link to={`/imovel/${p.id}`} className="site-card">
    <div className="site-card-photo">{p.cover_url ? <img src={p.cover_url} alt="" loading="lazy" /> : <span><Icon name="building" /></span>}<em>{p.property_type}</em></div>
    <div className="site-card-body">
      <small>{[p.neighborhood, p.city].filter(Boolean).join(', ')}</small>
      <h3>{p.title}</h3>
      {facts.length > 0 && <p className="site-facts">{facts.join(' · ')}</p>}
      <strong>{money(price)}{showRent || (p.purpose === 'rental') ? <span>/mês</span> : null}</strong>
      {showRent && p.condo_fee && Number(p.condo_fee) > 0 && <small>+ condomínio {money(p.condo_fee)}</small>}
    </div>
  </Link>;
}
