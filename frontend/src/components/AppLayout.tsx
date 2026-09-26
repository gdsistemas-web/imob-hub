import {useEffect,useState,type ReactNode} from 'react';
import {Link,useLocation,useNavigate} from 'react-router-dom';
import {api} from '../lib/api';
import {enableAppManifest} from '../lib/pwa';
import {Icon} from './Icon';
import {SearchAutocomplete} from './Autocomplete';

export type AppUser={id:string;name:string;email:string;role:string};
type NavItem={to:string;label:string;icon:string};

function navigation(user:AppUser){
  const main:NavItem[]=[
    {to:'/painel',label:'Dashboard',icon:'dashboard'},
    {to:'/leads',label:'Leads',icon:'leads'},
    {to:'/conversas',label:'Conversas',icon:'chat'},
    {to:'/imoveis',label:'Imóveis',icon:'building'},
    {to:'/agenda',label:'Visitas',icon:'calendar'},
    {to:'/operacao',label:'Negociações',icon:'briefcase'},
    {to:'/documentos',label:'Documentos',icon:'file'},
    {to:'/agenda-operacional',label:'Tarefas e agenda',icon:'check'},
    {to:'/relatorios',label:'Relatórios',icon:'chart'},
  ];
  const allowed=main.filter(item=>{
    if(item.to==='/imoveis'||item.to==='/agenda')return ['broker','admin','manager'].includes(user.role);
    if(item.to==='/operacao'||item.to==='/relatorios')return ['admin','manager'].includes(user.role);
    if(item.to==='/conversas')return ['admin','manager','sdr','broker'].includes(user.role);
    if(item.to==='/documentos')return false;
    return true;
  });
  if(user.role==='analyst')return [{to:'/analises',label:'Análises de crédito',icon:'check'},{to:'/notificacoes',label:'Notificações',icon:'bell'}];
  if(['admin','manager','sdr'].includes(user.role))allowed.splice(2,0,{to:'/sdr',label:'Atendimento SDR',icon:'users'});
  if(['admin','manager'].includes(user.role))allowed.splice(allowed.findIndex(i=>i.to==='/operacao')+1,0,{to:'/analises',label:'Análises de crédito',icon:'check'});
  if(user.role==='broker')allowed.splice(allowed.findIndex(i=>i.to==='/imoveis')+1,0,{to:'/fichas',label:'Fichas de interesse',icon:'file'});
  if(['admin','manager','broker'].includes(user.role))allowed.splice(allowed.findIndex(i=>i.to===(user.role==='broker'?'/fichas':'/analises'))+1,0,{to:'/contratos',label:'Contratos',icon:'pen'});
  if(user.role==='broker')allowed.splice(2,0,{to:'/minhas-oportunidades',label:'Oportunidades',icon:'briefcase'});
  return allowed;
}

/** Abas fixas no rodapé do celular: as telas que cada perfil usa o dia todo. */
function tabsFor(user:AppUser):NavItem[]{
  const home={to:'/painel',label:'Início',icon:'home'},chat={to:'/conversas',label:'Conversas',icon:'chat'};
  if(user.role==='broker')return [home,{to:'/minhas-oportunidades',label:'Carteira',icon:'briefcase'},{to:'/imoveis',label:'Imóveis',icon:'building'},chat,{to:'/agenda-operacional',label:'Agenda',icon:'calendar'}];
  if(user.role==='analyst')return [{to:'/analises',label:'Análises',icon:'check'},{to:'/notificacoes',label:'Avisos',icon:'bell'}];
  if(user.role==='sdr')return [home,{to:'/sdr',label:'Atendimento',icon:'users'},{to:'/leads',label:'Leads',icon:'leads'},chat,{to:'/agenda-operacional',label:'Agenda',icon:'calendar'}];
  return [home,{to:'/leads',label:'Leads',icon:'leads'},{to:'/imoveis',label:'Imóveis',icon:'building'},chat,{to:'/operacao',label:'Negócios',icon:'briefcase'}];
}

const configNav:NavItem[]=[
  {to:'/distribuicao',label:'Equipe e distribuição',icon:'users'},
  {to:'/integracoes',label:'Integrações',icon:'plug'},
  {to:'/fluxos',label:'Automação',icon:'settings'},
  {to:'/auditoria',label:'Auditoria',icon:'file'},
  {to:'/configuracoes',label:'Empresa e site',icon:'globe'},
];

export function AppLayout({user,setUser,children}:{user:AppUser;setUser:(user:null)=>void;children:ReactNode}){
  const [open,setOpen]=useState(false),[account,setAccount]=useState(false),[unread,setUnread]=useState(0),[pendingChats,setPendingChats]=useState(0),[globalQuery,setGlobalQuery]=useState('');
  const location=useLocation(),navigate=useNavigate();
  const hasInbox=['admin','manager','sdr','broker'].includes(user.role);
  useEffect(()=>{api('/notifications').then(r=>setUnread(r.unread||0)).catch(()=>{})},[location.pathname]);
  useEffect(()=>{if(!hasInbox)return;const load=()=>api('/conversations').then(r=>setPendingChats(r.pending||0)).catch(()=>{});void load();const timer=setInterval(load,20000);return()=>clearInterval(timer)},[location.pathname,hasInbox]);
  useEffect(()=>setOpen(false),[location.pathname]);
  useEffect(()=>enableAppManifest(),[]);
  async function logout(){await api('/logout',{method:'POST'}).catch(()=>{});localStorage.removeItem('token');setUser(null);navigate('/login')}
  const active=(to:string)=>location.pathname.startsWith(to);
  const focused=/^\/(imoveis\/|comercial\/|fichas\/|analises\/|contratos\/)/.test(location.pathname); // telas de tarefa usam a tela toda, sem abas
  const role:{[key:string]:string}={admin:'Administrador',manager:'Gestor',sdr:'SDR',broker:'Corretor',analyst:'Analista de crédito'};
  return <div className="app-shell">
    <button className="mobile-menu" onClick={()=>setOpen(true)} aria-label="Abrir menu"><Icon name="menu"/></button>
    {open&&<button className="sidebar-backdrop" onClick={()=>setOpen(false)} aria-label="Fechar menu"/>}
    <aside className={`app-sidebar ${open?'is-open':''}`}>
      <Link to="/painel" className="hub-logo"><span className="hub-mark"><i/><Icon name="building"/></span><strong>IMOB<span>HUB</span></strong></Link>
      <nav className="sidebar-nav" aria-label="Menu principal">{navigation(user).map(item=><Link key={item.to} className={active(item.to)?'active':''} to={item.to}><Icon name={item.icon}/><span>{item.label}</span></Link>)}</nav>
      {['admin','manager'].includes(user.role)&&<><p className="nav-caption">Configurações</p><nav className="sidebar-nav config-nav">{configNav.map(item=><Link key={item.to} className={active(item.to)?'active':''} to={item.to}><Icon name={item.icon}/><span>{item.label}</span></Link>)}</nav></>}
      <div className="vln-signature"><span><Icon name="chart"/></span><div><strong>VLN INFO</strong><small>Tecnologia que conecta pessoas a grandes negócios.</small></div></div>
    </aside>
    <div className="app-main">
      <div className="topbar">
        {user.role!=='analyst'?<div className="global-search"><Icon name="search"/><SearchAutocomplete
          value={globalQuery}
          onChange={setGlobalQuery}
          onSubmit={()=>{if(globalQuery.trim()){navigate('/leads?q='+encodeURIComponent(globalQuery.trim()));setGlobalQuery('')}}}
          placeholder="Buscar leads, clientes, imóveis, propostas..."
          fetcher={term=>api(`/leads?q=${encodeURIComponent(term)}`).then(r=>r.data)}
          renderItem={(x:any)=><><strong>{x.name}</strong><small>{x.email||x.phone||x.source_name}</small></>}
          onPick={(x:any)=>{navigate('/leads/'+x.id);setGlobalQuery('')}}
        /><kbd>⌘ K</kbd></div>:<div/>}
        <div className="top-actions">{hasInbox&&<Link to="/conversas" className="icon-button" aria-label={`${pendingChats} conversas aguardando atendimento`}><Icon name="chat"/>{pendingChats>0&&<b>{pendingChats>9?'9+':pendingChats}</b>}</Link>}<Link to="/notificacoes" className="icon-button" aria-label={`${unread} notificações não lidas`}><Icon name="bell"/>{unread>0&&<b>{unread>9?'9+':unread}</b>}</Link><button className="user-trigger" onClick={()=>setAccount(!account)} aria-expanded={account}><span className="avatar">{user.name.split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()}</span><span><strong>{user.name}</strong><small>{role[user.role]||user.role}</small></span><i>⌄</i></button>{account&&<div className="account-menu"><span>{user.email}</span><button onClick={logout}>Sair da conta</button></div>}</div>
      </div>
      <main className={'page-content'+(focused?'':' with-tabbar')}>{children}</main>
    </div>
    {!focused&&<nav className="tabbar" aria-label="Navegação rápida">{tabsFor(user).map(item=><Link key={item.to} to={item.to} className={active(item.to)?'active':''} aria-current={active(item.to)?'page':undefined}><Icon name={item.icon}/><span>{item.label}</span>{item.to==='/conversas'&&pendingChats>0&&<b>{pendingChats>9?'9+':pendingChats}</b>}</Link>)}</nav>}
  </div>
}
