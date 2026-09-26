import {useEffect, useRef, useState} from 'react';
import {Link} from 'react-router-dom';
import {api} from '../lib/api';
import {Icon} from '../components/Icon';
import {useSite, type PublicProperty} from './SiteLayout';

type Msg = {id: string; from: 'visitor' | 'agent' | 'system'; body: string; created_at: string; agent: string | null};
type ChatState = {status: 'open' | 'closed'; agent: string | null; messages: Msg[]};
type Context = Pick<PublicProperty, 'id' | 'reference_code' | 'title'>;

const TOKEN_KEY = 'site_chat_token', SEEN_KEY = 'site_chat_seen';
const store = {
  get: (k: string) => { try { return localStorage.getItem(k) } catch { return null } },
  set: (k: string, v: string) => { try { localStorage.setItem(k, v) } catch { /* modo privado */ } },
  del: (k: string) => { try { localStorage.removeItem(k) } catch { /* modo privado */ } },
};
const hour = (v: string) => new Intl.DateTimeFormat('pt-BR', {hour: '2-digit', minute: '2-digit'}).format(new Date(v.replace(' ', 'T')));

export function ChatWidget() {
  const info = useSite();
  const [open, setOpen] = useState(() => new URLSearchParams(location.search).has('chat'));
  const [token, setToken] = useState<string | null>(() => store.get(TOKEN_KEY));
  const [chat, setChat] = useState<ChatState | null>(null);
  const [context, setContext] = useState<Context | null>(null);
  const [input, setInput] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [seen, setSeen] = useState(() => Number(store.get(SEEN_KEY) || 0));
  const bottom = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const onOpen = (e: Event) => {
      const property = (e as CustomEvent<Context | undefined>).detail || null;
      setContext(property); setOpen(true); setError('');
      if (property) setInput(`Olá! Tenho interesse no imóvel ${property.reference_code} – ${property.title}.`);
    };
    window.addEventListener('site:open-chat', onOpen);
    return () => window.removeEventListener('site:open-chat', onOpen);
  }, []);

  useEffect(() => {
    if (!token) return;
    let alive = true;
    const poll = () => api(`/public/chat/${token}`).then(r => alive && setChat(r.data)).catch(e => { if (alive && /não encontrada/i.test(e.message)) reset() });
    void poll();
    const t = setInterval(poll, open ? 4000 : 20000);
    return () => { alive = false; clearInterval(t) };
  }, [token, open]);

  const agentCount = chat?.messages.filter(m => m.from !== 'visitor').length || 0;
  useEffect(() => { if (open && chat) { setSeen(agentCount); store.set(SEEN_KEY, String(agentCount)) } }, [open, agentCount]);
  useEffect(() => { if (open) bottom.current?.scrollIntoView({block: 'end'}) }, [open, chat?.messages.length]);
  const unread = !open && chat ? Math.max(0, agentCount - seen) : 0;

  function reset() { store.del(TOKEN_KEY); store.del(SEEN_KEY); setToken(null); setChat(null); setSeen(0) }

  async function start(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(e.currentTarget));
    setBusy(true); setError('');
    try {
      const r = await api('/public/chat', {method: 'POST', body: JSON.stringify({...d, consent: d.consent === 'on', property_id: context?.id})});
      store.set(TOKEN_KEY, r.data.token); setToken(r.data.token); setChat(r.data); setInput('');
    } catch (x: any) { setError(x.message) } finally { setBusy(false) }
  }

  async function send(e: React.FormEvent) {
    e.preventDefault();
    if (!token || !input.trim()) return;
    const text = input.trim(); setInput(''); setBusy(true); setError('');
    try { setChat((await api(`/public/chat/${token}/messages`, {method: 'POST', body: JSON.stringify({message: text})})).data) }
    catch (x: any) { setError(x.message); setInput(text) } finally { setBusy(false) }
  }

  return <>
    <button type="button" className={'chat-fab ' + (open ? 'is-open' : '')} onClick={() => setOpen(o => !o)} aria-expanded={open} aria-controls="site-chat" aria-label={open ? 'Fechar chat' : 'Abrir chat com um corretor'}>
      <Icon name={open ? 'close' : 'chat'} />{!open && <span>Fale com um corretor</span>}{unread > 0 && <b aria-label={`${unread} novas mensagens`}>{unread}</b>}
    </button>
    {open && <section id="site-chat" className="chat-panel" role="dialog" aria-label="Chat com a equipe de vendas">
      <header>
        <div><strong>{info?.company.name || 'Atendimento'}</strong><small>{chat?.agent ? `${chat.agent} está te atendendo` : info?.site.business_hours || 'Equipe de corretores'}</small></div>
        <button type="button" onClick={() => setOpen(false)} aria-label="Fechar chat"><Icon name="close" /></button>
      </header>

      {!token && <form className="chat-start" onSubmit={start}>
        <p>Deixe seus dados e sua dúvida. Um corretor responde por aqui mesmo.</p>
        {context && <p className="chat-context">Sobre: <b>{context.reference_code}</b> – {context.title}</p>}
        <label>Nome<input name="name" required autoComplete="name" /></label>
        <label>Celular com DDD<input name="phone" type="tel" inputMode="tel" autoComplete="tel" placeholder="(11) 90000-0000" /></label>
        <label>E-mail (opcional)<input name="email" type="email" autoComplete="email" /></label>
        <label>Mensagem<textarea name="message" rows={3} required value={input} onChange={e => setInput(e.target.value)} /></label>
        <input name="website" tabIndex={-1} autoComplete="off" className="hp" aria-hidden="true" />
        <label className="chat-consent"><input type="checkbox" name="consent" required /> <span>Autorizo o contato por telefone, WhatsApp ou e-mail sobre este atendimento. <Link to="/privacidade">Privacidade</Link></span></label>
        {error && <p className="chat-error" role="alert">{error}</p>}
        <button disabled={busy}>{busy ? 'Enviando…' : 'Iniciar conversa'}</button>
      </form>}

      {token && <>
        <div className="chat-log" aria-live="polite">
          {chat?.messages.map(m => <div key={m.id} className={'chat-msg ' + m.from}>
            <p>{m.body}</p><small>{m.from === 'agent' && m.agent ? `${m.agent} · ` : ''}{hour(m.created_at)}</small>
          </div>)}
          {chat?.status === 'closed' && <div className="chat-closed">Atendimento encerrado. <button type="button" className="site-link-button" onClick={reset}>Iniciar nova conversa</button></div>}
          <div ref={bottom} />
        </div>
        {error && <p className="chat-error" role="alert">{error}</p>}
        {chat?.status !== 'closed' && <form className="chat-compose" onSubmit={send}>
          <input value={input} onChange={e => setInput(e.target.value)} placeholder="Escreva sua mensagem" aria-label="Mensagem" maxLength={2000} />
          <button disabled={busy || !input.trim()} aria-label="Enviar">➤</button>
        </form>}
      </>}
    </section>}
  </>;
}
