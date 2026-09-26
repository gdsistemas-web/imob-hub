import {createContext, useContext, useEffect, useState, type ReactNode} from 'react';
import {Link, useLocation} from 'react-router-dom';
import {api} from '../lib/api';
import {ChatWidget} from './ChatWidget';
import './site.css';

export type SiteInfo = {
  company: {name: string; legal_name: string; cnpj: string; creci: string; phone: string; whatsapp: string; email: string; address: string; city: string; state: string};
  site: {hero_title: string; hero_subtitle: string; about: string; chat_enabled: boolean; chat_greeting: string; business_hours: string};
};
export type PublicPhoto = {url: string; label: string | null; width: number | null; height: number | null};
export type PublicProperty = {
  id: string; reference_code: string; title: string; property_type: string; purpose: 'purchase' | 'rental' | 'both'; sale: string | null; rent: string | null;
  condo_fee: string | null; iptu_yearly: string | null; area_total: string | null; area_built: string | null; bedrooms: number | null; suites: number | null; bathrooms: number | null; parking_spaces: number | null;
  floor_number: number | null; year_built: number | null; furnished: string | null; accepts_pets: number | null; accepts_financing: number | null; accepts_exchange: number | null;
  neighborhood: string | null; city: string | null; state: string | null; condo_name: string | null; street?: string; street_number?: string;
  description?: string | null; features?: string[]; condo_features?: string[]; photos: PublicPhoto[]; cover_url: string | null; similar?: PublicProperty[];
};

const SiteContext = createContext<SiteInfo | null>(null);
export const useSite = () => useContext(SiteContext);

export const openChat = (property?: Pick<PublicProperty, 'id' | 'reference_code' | 'title'>) => window.dispatchEvent(new CustomEvent('site:open-chat', {detail: property}));
export const whatsappLink = (number: string, text: string) => `https://wa.me/${number.replace(/\D/g, '').replace(/^(?!55)/, '55')}?text=${encodeURIComponent(text)}`;

export function SiteLayout({loggedIn, children}: {loggedIn: boolean; children: ReactNode}) {
  const [info, setInfo] = useState<SiteInfo | null>(null);
  const location = useLocation();
  useEffect(() => { api('/public/site').then(r => setInfo(r.data)).catch(() => {}) }, []);
  useEffect(() => { window.scrollTo(0, 0) }, [location.pathname]);
  const c = info?.company;
  return <SiteContext.Provider value={info}>
    <div className="site">
      <header className="site-header">
        <div className="site-wrap">
          <Link to="/" className="site-brand" aria-label="Página inicial"><span className="site-mark" aria-hidden="true">{(c?.name || 'I').trim()[0]}</span><span>{c?.name || ' '}</span></Link>
          <nav className="site-nav" aria-label="Navegação do site">
            <Link to="/?finalidade=comprar#imoveis">Comprar</Link>
            <Link to="/?finalidade=alugar#imoveis">Alugar</Link>
            <a href="#contato" onClick={e => { e.preventDefault(); document.getElementById('contato')?.scrollIntoView({behavior: 'smooth'}) }}>Contato</a>
            <Link to={loggedIn ? '/painel' : '/login'} className="site-login">{loggedIn ? 'Ir para o painel' : 'Área do corretor'}</Link>
          </nav>
        </div>
      </header>
      <main>{children}</main>
      <footer className="site-footer" id="contato">
        <div className="site-wrap site-footer-grid">
          <div>
            <strong className="site-footer-brand">{c?.name}</strong>
            {info?.site.about && <p>{info.site.about}</p>}
          </div>
          <div>
            <h2>Fale com a gente</h2>
            <ul>
              {c?.phone && <li><a href={`tel:${c.phone.replace(/[^\d+]/g, '')}`}>{c.phone}</a></li>}
              {c?.whatsapp && <li><a href={whatsappLink(c.whatsapp, 'Olá! Vim pelo site.')} target="_blank" rel="noreferrer">WhatsApp {c.whatsapp}</a></li>}
              {c?.email && <li><a href={`mailto:${c.email}`}>{c.email}</a></li>}
              {info?.site.business_hours && <li>{info.site.business_hours}</li>}
              {info?.site.chat_enabled && <li><button type="button" className="site-link-button" onClick={() => openChat()}>Conversar agora pelo chat</button></li>}
            </ul>
          </div>
          <div>
            <h2>Endereço</h2>
            <p>{[c?.address, c?.city && `${c.city}${c.state ? '/' + c.state : ''}`].filter(Boolean).join(' · ') || '—'}</p>
          </div>
        </div>
        <div className="site-wrap site-legal">
          <span>{[c?.legal_name || c?.name, c?.cnpj && `CNPJ ${c.cnpj}`, c?.creci && `CRECI ${c.creci}`].filter(Boolean).join(' · ')}</span>
          <Link to="/privacidade">Política de privacidade</Link>
        </div>
      </footer>
      {info?.site.chat_enabled && <ChatWidget />}
    </div>
  </SiteContext.Provider>;
}
