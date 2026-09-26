import {useEffect,useRef,useState} from 'react';
import {api} from '../lib/api';
import {Icon} from './Icon';

type ConversationSummary={id:string;provider:string;phone?:string;contact_name?:string;property_title?:string|null;property_reference?:string|null;opportunity_id?:string|null;status:string;assigned_to?:string|null;assigned_name?:string|null;last_message?:string|null;updated_at:string};
type ConversationMessage={id:string;direction:'in'|'out';body:string;created_at:string;sender_name?:string|null};
type ConversationDetail=ConversationSummary&{messages:ConversationMessage[];contact_email?:string|null;contact_phone?:string|null};
const providerLabel=(p:string)=>({site_chat:'Site',simulator:'WhatsApp',whatsapp:'WhatsApp'} as Record<string,string>)[p]||p;

const statusLabel=(status:string)=>({human:'Aguardando atendimento',waiting:'Aguardando cliente',active:'Em automação',closed:'Encerrada'} as Record<string,string>)[status]||status;
const time=(v:string)=>new Intl.DateTimeFormat('pt-BR',{hour:'2-digit',minute:'2-digit'}).format(new Date(v.replace(' ','T')));

export function ConversationInbox(){
  const [items,setItems]=useState<ConversationSummary[]>([]);
  const [filter,setFilter]=useState<'human'|'active'|'closed'|'all'>('human');
  const [selected,setSelected]=useState<ConversationDetail|null>(null);
  const [input,setInput]=useState('');
  const [busy,setBusy]=useState(false);
  const [feedback,setFeedback]=useState('');
  const bottom=useRef<HTMLDivElement>(null);

  const load=()=>api('/conversations').then(r=>setItems(r.data));
  useEffect(()=>{void load();const t=setInterval(load,10000);return()=>clearInterval(t)},[]);
  useEffect(()=>{if(!selected||selected.status==='closed')return;const t=setInterval(()=>api('/conversations/'+selected.id).then(r=>setSelected(cur=>cur?.id===r.data.id?r.data:cur)).catch(()=>{}),5000);return()=>clearInterval(t)},[selected?.id,selected?.status]);
  useEffect(()=>{if(selected)bottom.current?.scrollIntoView({behavior:'smooth',block:'nearest'})},[selected?.messages.length]);

  async function open(id:string){try{const r=await api('/conversations/'+id);setSelected(r.data);setFeedback('')}catch(e:any){setFeedback(e.message)}}
  async function assign(){if(!selected)return;setBusy(true);try{const r=await api(`/conversations/${selected.id}/assign`,{method:'POST'});setSelected(r.data);void load()}catch(e:any){setFeedback(e.message)}finally{setBusy(false)}}
  async function resolve(){if(!selected||!confirm('Encerrar este atendimento?'))return;setBusy(true);try{const r=await api(`/conversations/${selected.id}/resolve`,{method:'POST'});setSelected(r.data);void load()}catch(e:any){setFeedback(e.message)}finally{setBusy(false)}}
  async function send(e:React.FormEvent){
    e.preventDefault();
    if(!selected||!input.trim()||busy)return;
    const text=input.trim();setInput('');setBusy(true);
    try{const r=await api(`/conversations/${selected.id}/reply`,{method:'POST',body:JSON.stringify({message:text})});setSelected(r.data);void load()}
    catch(e:any){setFeedback(e.message)}finally{setBusy(false)}
  }

  const filtered=items.filter(x=>filter==='all'||x.status===filter||(filter==='active'&&(x.status==='active'||x.status==='waiting')));
  const tabs:{key:typeof filter;label:string}[]=[{key:'human',label:'Aguardando'},{key:'active',label:'Automação'},{key:'closed',label:'Encerradas'},{key:'all',label:'Todas'}];

  return <div className="conversation-page">
    <header className="studio-header">
      <div><span className="eyebrow">Atendimento</span><h1>Central de Conversas</h1><p>Chat do site e conversas que a automação transferiu. Assuma a conversa para responder.</p></div>
    </header>
    {feedback&&<p className="notice">{feedback}</p>}
    <div className={"conversation-layout inbox-layout"+(selected?" has-selected":"")}>
      <aside className="card inbox-list">
        <div className="inbox-tabs">{tabs.map(t=><button key={t.key} className={filter===t.key?'active':''} onClick={()=>setFilter(t.key)}>{t.label}</button>)}</div>
        <div className="queue inbox-queue">
          {filtered.map(item=><button key={item.id} className={`queue-item inbox-item ${selected?.id===item.id?'active':''}`} onClick={()=>open(item.id)}>
            <span className="inbox-avatar">{(item.contact_name||item.phone||'??').slice(0,2).toUpperCase()}</span>
            <div className="inbox-item-text"><strong>{item.contact_name||item.phone||'Contato sem nome'}</strong><small><em className={'inbox-origin '+item.provider}>{providerLabel(item.provider)}</em>{item.property_reference?` · ${item.property_reference}`:''}</small><small>{item.last_message||'Sem mensagens'}</small></div>
            <span className={`run-status ${item.status}`}>{statusLabel(item.status)}</span>
          </button>)}
          {!filtered.length&&<p className="empty-inline">Nenhuma conversa nesse filtro.</p>}
        </div>
      </aside>
      <section className="messenger">
        <div className="messenger-top">
          <button type="button" className="inbox-back" onClick={()=>setSelected(null)} aria-label="Voltar para a lista">‹</button><span className="contact-avatar"><Icon name="building"/></span>
          <div><strong>{selected?(selected.contact_name||selected.phone||'Contato'):'Selecione uma conversa'}</strong><small><i/> {selected?statusLabel(selected.status):'Nenhuma conversa aberta'}{selected?.assigned_name&&` · Atendido por ${selected.assigned_name}`}</small>{selected&&<small className="inbox-context">{providerLabel(selected.provider)}{selected.contact_phone?` · ${selected.contact_phone}`:''}{selected.contact_email?` · ${selected.contact_email}`:''}{selected.property_reference&&<> · Imóvel <b>{selected.property_reference}</b> – {selected.property_title}</>}</small>}</div>
          {selected&&selected.status!=='closed'&&<button className="secondary" onClick={resolve} disabled={busy}>Encerrar</button>}
        </div>
        <div className="chat-wallpaper">
          {!selected&&<div className="chat-welcome"><span>💬</span><h2>Nenhuma conversa selecionada</h2><p>Escolha um item na lista ao lado para ver o histórico.</p></div>}
          {selected?.messages.map(m=><div key={m.id} className={`message-row ${m.direction==='in'?'customer':'bot'}`}><div className="message-bubble"><p>{m.body}</p><small>{time(m.created_at)}{m.sender_name?` · ${m.sender_name}`:''}</small></div></div>)}
          <div ref={bottom}/>
        </div>
        <form className="inbox-composer" onSubmit={send}>
          {selected&&!selected.assigned_to&&selected.status!=='closed'&&<button type="button" className="secondary" onClick={assign} disabled={busy}>Assumir</button>}
          <input value={input} onChange={e=>setInput(e.target.value)} placeholder={!selected?'Selecione uma conversa':selected.status==='closed'?'Conversa encerrada':'Digite sua resposta'} disabled={!selected||selected.status==='closed'||busy} aria-label="Mensagem"/>
          <button className="send-message" disabled={!selected||!input.trim()||selected.status==='closed'||busy} aria-label="Enviar">➤</button>
        </form>
      </section>
    </div>
  </div>
}
