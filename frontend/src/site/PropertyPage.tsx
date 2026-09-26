import {useEffect, useState} from 'react';
import {Link, useParams} from 'react-router-dom';
import {api, money} from '../lib/api';
import {Icon} from '../components/Icon';
import {PropertyCard} from './Home';
import {openChat, useSite, whatsappLink, type PublicProperty} from './SiteLayout';

export function PropertyPage() {
  const {id} = useParams();
  const info = useSite();
  const [p, setP] = useState<PublicProperty | null>(null);
  const [error, setError] = useState('');
  const [photo, setPhoto] = useState<number | null>(null);

  useEffect(() => {
    setP(null); setError('');
    api(`/public/properties/${id}`).then(r => setP(r.data)).catch(e => setError(e.message));
  }, [id]);
  useEffect(() => { if (p) document.title = `${p.title} · ${info?.company.name || 'Imóveis'}` }, [p, info]);

  if (error) return <div className="site-wrap site-notfound"><h1>Imóvel indisponível</h1><p>{error}</p><Link to="/" className="site-button">Ver outros imóveis</Link></div>;
  if (!p) return <div className="site-wrap site-loading">Carregando imóvel…</div>;

  const rental = p.purpose === 'rental';
  const monthly = [Number(p.condo_fee || 0), Number(p.iptu_yearly || 0) / 12];
  const place = [p.street && `${p.street}${p.street_number ? ', ' + p.street_number : ''}`, p.neighborhood, p.city && `${p.city}${p.state ? '/' + p.state : ''}`].filter(Boolean).join(' · ');
  const facts: [string, string][] = [
    ['Área útil', p.area_built ? `${Number(p.area_built)} m²` : ''], ['Área total', p.area_total && p.area_total !== p.area_built ? `${Number(p.area_total)} m²` : ''],
    ['Quartos', p.bedrooms ? String(p.bedrooms) : ''], ['Suítes', p.suites ? String(p.suites) : ''], ['Banheiros', p.bathrooms ? String(p.bathrooms) : ''], ['Vagas', p.parking_spaces != null ? String(p.parking_spaces) : ''],
    ['Andar', p.floor_number ? `${p.floor_number}º` : ''], ['Construção', p.year_built ? String(p.year_built) : ''],
  ].filter(([, v]) => v) as [string, string][];
  const tags = [
    p.furnished === 'yes' && 'Mobiliado', p.furnished === 'semi' && 'Semimobiliado', Number(p.accepts_pets) === 1 && 'Aceita pets',
    Number(p.accepts_financing) === 1 && 'Aceita financiamento', Number(p.accepts_exchange) === 1 && 'Aceita permuta',
  ].filter(Boolean) as string[];
  const wa = info?.company.whatsapp ? whatsappLink(info.company.whatsapp, `Olá! Tenho interesse no imóvel ${p.reference_code} – ${p.title}. ${location.href}`) : null;

  return <div className="site-wrap property-page">
    <nav className="crumbs" aria-label="Você está em"><Link to="/">Imóveis</Link> › <Link to={`/?finalidade=${rental ? 'alugar' : 'comprar'}${p.city ? '&cidade=' + encodeURIComponent(p.city) : ''}#imoveis`}>{p.city || 'Todos'}</Link> › <span>{p.reference_code}</span></nav>

    <Gallery photos={p.photos} title={p.title} onOpen={setPhoto} />

    <div className="property-layout">
      <article>
        <p className="property-kicker">{p.property_type} {rental ? 'para alugar' : p.purpose === 'both' ? 'à venda ou para alugar' : 'à venda'}</p>
        <h1>{p.title}</h1>
        <p className="property-place">{place}{p.condo_name ? ` · ${p.condo_name}` : ''}</p>
        {facts.length > 0 && <dl className="property-facts">{facts.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>}
        {tags.length > 0 && <ul className="property-tags">{tags.map(t => <li key={t}>{t}</li>)}</ul>}
        {p.description && <section><h2>Sobre o imóvel</h2>{p.description.split(/\n+/).map((line, i) => <p key={i}>{line}</p>)}</section>}
        {!!p.features?.length && <section><h2>Diferenciais</h2><ul className="feature-list">{p.features.map(f => <li key={f}><Icon name="check" />{f}</li>)}</ul></section>}
        {!!p.condo_features?.length && <section><h2>Condomínio</h2><ul className="feature-list">{p.condo_features.map(f => <li key={f}><Icon name="check" />{f}</li>)}</ul></section>}
        <p className="property-ref">Código do imóvel: {p.reference_code}</p>
      </article>

      <aside className="price-box" aria-label="Valores e contato">
        {p.sale && <div className="price-main"><small>Venda</small><strong>{money(p.sale)}</strong></div>}
        {p.rent && <div className={p.sale ? 'price-secondary' : 'price-main'}><small>Aluguel</small><strong>{money(p.rent)}<span>/mês</span></strong></div>}
        <dl className="price-lines">
          {Number(p.condo_fee) > 0 && <><dt>Condomínio</dt><dd>{money(p.condo_fee)}</dd></>}
          {Number(p.iptu_yearly) > 0 && <><dt>IPTU</dt><dd>{money(Number(p.iptu_yearly) / 12)}/mês</dd></>}
          {p.rent && (monthly[0] > 0 || monthly[1] > 0) && <><dt className="total">Total mensal</dt><dd className="total">{money(Number(p.rent) + monthly[0] + monthly[1])}</dd></>}
        </dl>
        <InterestForm property={p} />
        <div className="price-actions">
          {info?.site.chat_enabled && <button type="button" className="ghost" onClick={() => openChat(p)}><Icon name="chat" /> Conversar agora</button>}
          {wa && <a className="site-button whatsapp" href={wa} target="_blank" rel="noreferrer"><Icon name="phone" /> WhatsApp</a>}
        </div>
      </aside>
    </div>

    {!!p.similar?.length && <section className="similar"><h2>Você também pode gostar</h2><div className="site-grid">{p.similar.map(s => <PropertyCard key={s.id} p={s} />)}</div></section>}
    {photo !== null && <Lightbox photos={p.photos} index={photo} onChange={setPhoto} />}
  </div>;
}

function Gallery({photos, title, onOpen}: {photos: PublicProperty['photos']; title: string; onOpen: (i: number) => void}) {
  if (!photos.length) return <div className="gallery gallery-empty"><Icon name="building" /></div>;
  return <div className="gallery-wrap">
    <div className={'gallery count-' + Math.min(photos.length, 5)}>
      {photos.map((ph, i) => <button type="button" key={ph.url} className={'ph' + (i === 0 ? ' main' : '')} onClick={() => onOpen(i)} aria-label={`Ampliar foto ${i + 1}${ph.label ? ': ' + ph.label : ''}`}>
        <img src={ph.url} alt={i === 0 ? title : ph.label || ''} loading={i === 0 ? 'eager' : 'lazy'} />
        {i === 4 && photos.length > 5 && <span className="more">+{photos.length - 5} fotos</span>}
      </button>)}
    </div>
    <button type="button" className="gallery-all" onClick={() => onOpen(0)}>Ver as {photos.length} fotos</button>
  </div>;
}

function Lightbox({photos, index, onChange}: {photos: PublicProperty['photos']; index: number; onChange: (i: number | null) => void}) {
  const go = (d: number) => onChange((index + d + photos.length) % photos.length);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onChange(null); if (e.key === 'ArrowRight') go(1); if (e.key === 'ArrowLeft') go(-1) };
    window.addEventListener('keydown', onKey); document.body.style.overflow = 'hidden';
    return () => { window.removeEventListener('keydown', onKey); document.body.style.overflow = '' };
  });
  const ph = photos[index];
  return <div className="lightbox" role="dialog" aria-label="Fotos do imóvel" onClick={() => onChange(null)}>
    <button type="button" className="lb-close" aria-label="Fechar"><Icon name="close" /></button>
    <button type="button" className="lb-nav prev" aria-label="Foto anterior" onClick={e => { e.stopPropagation(); go(-1) }}>‹</button>
    <figure onClick={e => e.stopPropagation()}><img src={ph.url} alt={ph.label || ''} /><figcaption>{ph.label ? `${ph.label} · ` : ''}{index + 1} de {photos.length}</figcaption></figure>
    <button type="button" className="lb-nav next" aria-label="Próxima foto" onClick={e => { e.stopPropagation(); go(1) }}>›</button>
  </div>;
}

function InterestForm({property}: {property: PublicProperty}) {
  const [state, setState] = useState<'idle' | 'sending' | 'done'>('idle');
  const [error, setError] = useState('');
  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(e.currentTarget));
    setState('sending'); setError('');
    try { await api('/public/leads', {method: 'POST', body: JSON.stringify({...d, consent: d.consent === 'on', property_id: property.id})}); setState('done') }
    catch (x: any) { setError(x.message); setState('idle') }
  }
  if (state === 'done') return <div className="interest-done" role="status"><Icon name="check" /><div><strong>Recebemos seu interesse!</strong><p>Um corretor vai entrar em contato em breve.</p></div></div>;
  return <form className="interest-form" onSubmit={submit}>
    <h2>Tenho interesse</h2>
    <input name="name" required placeholder="Seu nome" aria-label="Seu nome" autoComplete="name" />
    <input name="phone" type="tel" inputMode="tel" placeholder="Celular com DDD" aria-label="Celular com DDD" autoComplete="tel" />
    <input name="email" type="email" placeholder="E-mail" aria-label="E-mail" autoComplete="email" />
    <textarea name="message" rows={3} aria-label="Mensagem" defaultValue={`Olá! Gostaria de mais informações sobre o imóvel ${property.reference_code}.`} />
    <input name="website" tabIndex={-1} autoComplete="off" className="hp" aria-hidden="true" />
    <label className="chat-consent"><input type="checkbox" name="consent" required /> <span>Autorizo o contato sobre este imóvel. <Link to="/privacidade">Privacidade</Link></span></label>
    {error && <p className="chat-error" role="alert">{error}</p>}
    <button disabled={state === 'sending'}>{state === 'sending' ? 'Enviando…' : 'Quero ser contatado'}</button>
  </form>;
}
