const token=()=>localStorage.getItem('token');
export async function api(path:string,options:RequestInit={}){const headers=new Headers(options.headers);if(!(options.body instanceof FormData))headers.set('Content-Type','application/json');if(token())headers.set('Authorization',`Bearer ${token()}`);const r=await fetch('/api'+path,{...options,headers});const j=await r.json();if(!r.ok)throw new Error(j.message||'Erro na requisição');return j;}
export const money=(v?:string|number|null)=>v?new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v)):'—';
export const date=(v?:string)=>v?new Intl.DateTimeFormat('pt-BR',{dateStyle:'short',timeStyle:'short'}).format(new Date(v.replace(' ','T'))):'—';
export const stages:{[k:string]:string}={qualification:'Qualificação',service:'Atendimento',visit:'Visita',proposal:'Proposta',negotiation:'Negociação',won:'Ganho',lost:'Perdido',nurturing:'Nutrição'};
