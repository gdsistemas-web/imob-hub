import {useEffect, useRef, useState} from 'react';
import {useSearchParams} from 'react-router-dom';
import {api} from '../lib/api';
import type {AppUser} from './AppLayout';

type Field = {key: string; label: string; type?: 'textarea' | 'checkbox' | 'email'; hint?: string; wide?: boolean};
const SECTIONS: Record<string, {title: string; description: string; fields: Field[]}> = {
  company: {title: 'Empresa', description: 'Aparece no rodapé do site, no chat e será usada no preenchimento dos contratos.', fields: [
    {key: 'name', label: 'Nome fantasia *'}, {key: 'legal_name', label: 'Razão social'}, {key: 'cnpj', label: 'CNPJ'}, {key: 'creci', label: 'CRECI jurídico'},
    {key: 'phone', label: 'Telefone'}, {key: 'whatsapp', label: 'WhatsApp', hint: 'Com DDD. Ativa o botão de WhatsApp nos imóveis.'}, {key: 'email', label: 'E-mail de contato', type: 'email'},
    {key: 'address', label: 'Endereço', wide: true}, {key: 'city', label: 'Cidade'}, {key: 'state', label: 'UF'},
  ]},
  site: {title: 'Site e chat', description: 'Textos da página inicial e comportamento do chat de atendimento.', fields: [
    {key: 'hero_title', label: 'Título da página inicial', wide: true}, {key: 'hero_subtitle', label: 'Subtítulo', type: 'textarea', wide: true},
    {key: 'about', label: 'Sobre a imobiliária (rodapé)', type: 'textarea', wide: true}, {key: 'business_hours', label: 'Horário de atendimento', wide: true},
    {key: 'chat_enabled', label: 'Chat do site ativo', type: 'checkbox'},
    {key: 'chat_greeting', label: 'Mensagem automática ao iniciar o chat', type: 'textarea', wide: true, hint: 'Deixe em branco para não enviar.'},
  ]},
  contracts: {title: 'Contratos', description: 'Padrões usados para pré-preencher cada contrato (o corretor pode ajustar caso a caso) e o texto dos modelos.', fields: [
    {key: 'foro', label: 'Foro (comarca)', hint: 'Ex.: São Paulo/SP. Em branco, usa a cidade do imóvel.'}, {key: 'commission_percent', label: 'Comissão de venda padrão (%)'},
    {key: 'commission_payer', label: 'Comissão paga pelo', hint: 'vendedor ou comprador'}, {key: 'signal_percent', label: 'Sinal/arras padrão (% do preço)'},
    {key: 'penalty_percent', label: 'Multa por descumprimento (%)'}, {key: 'deed_days', label: 'Prazo para escritura (dias)'},
    {key: 'lease_months', label: 'Prazo padrão da locação (meses)'}, {key: 'lease_due_day', label: 'Dia de vencimento do aluguel'},
    {key: 'lease_index', label: 'Índice de reajuste', hint: 'Ex.: IGP-M/FGV ou IPCA/IBGE'}, {key: 'lease_penalty_rents', label: 'Multa rescisória (aluguéis)'},
    {key: 'deposit_months', label: 'Caução (nº de aluguéis, máx. 3)'},
  ]},
};

export function Settings({user}: {user: AppUser}) {
  const [params, setParams] = useSearchParams();
  const tab = params.get('aba') && SECTIONS[params.get('aba')!] ? params.get('aba')! : 'company';
  const [data, setData] = useState<Record<string, any> | null>(null);
  const [msg, setMsg] = useState<{ok: boolean; text: string} | null>(null);
  const [busy, setBusy] = useState(false);
  const readOnly = user.role !== 'admin';
  const section = SECTIONS[tab];

  useEffect(() => { setData(null); setMsg(null); api(`/settings/${tab}`).then(r => setData(r.data)).catch(e => setMsg({ok: false, text: e.message})) }, [tab]);

  async function save(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setMsg(null);
    try { const r = await api(`/settings/${tab}`, {method: 'PUT', body: JSON.stringify(data)}); setData(r.data); setMsg({ok: true, text: 'Configurações salvas.'}) }
    catch (x: any) { setMsg({ok: false, text: x.message}) } finally { setBusy(false) }
  }

  return <>
    <header><div><h1>Configurações</h1><p className="muted">{readOnly ? 'Somente o administrador altera estas informações.' : 'Dados usados no site público, no chat e nos contratos.'}</p></div><a className="button secondary" href="/" target="_blank" rel="noreferrer">Ver site</a></header>
    <div className="listing-tabs" role="tablist">{Object.entries(SECTIONS).map(([k, s]) => <button key={k} role="tab" aria-selected={k === tab} className={k === tab ? 'active' : ''} onClick={() => setParams({aba: k})}>{s.title}</button>)}</div>
    <form className="card settings-form" onSubmit={save}>
      <h2>{section.title}</h2><p className="muted">{section.description}</p>
      {msg && <p className={msg.ok ? 'notice' : 'error'}>{msg.text}</p>}
      {data && <fieldset disabled={readOnly || busy}>
        <div className="grid">{section.fields.map(f => f.type === 'checkbox'
          ? <label key={f.key} className="check-line wide"><input type="checkbox" checked={!!data[f.key]} onChange={e => setData({...data, [f.key]: e.target.checked})} /> {f.label}</label>
          : <label key={f.key} className={f.wide ? 'wide' : ''}>{f.label}
            {f.type === 'textarea' ? <textarea rows={3} value={data[f.key] ?? ''} onChange={e => setData({...data, [f.key]: e.target.value})} /> : <input type={f.type || 'text'} value={data[f.key] ?? ''} onChange={e => setData({...data, [f.key]: e.target.value})} />}
            {f.hint && <small className="field-hint">{f.hint}</small>}
          </label>)}</div>
        {!readOnly && <button disabled={busy}>{busy ? 'Salvando…' : 'Salvar'}</button>}
      </fieldset>}
    </form>
    {tab === 'contracts' && <TemplatesEditor readOnly={readOnly} />}
  </>;
}

type Template = {key: string; name: string; body: string; customized: boolean; updated_at: string | null; updated_by: string | null};

/** Editor dos modelos de contrato: texto com marcação simples e variáveis {{...}}, prévia em PDF e restauração do padrão. */
function TemplatesEditor({readOnly}: {readOnly: boolean}) {
  const [list, setList] = useState<Template[]>([]);
  const [vars, setVars] = useState<Record<string, Record<string, string>>>({});
  const [key, setKey] = useState('sale_cash');
  const [body, setBody] = useState('');
  const [msg, setMsg] = useState<{ok: boolean; text: string} | null>(null);
  const [busy, setBusy] = useState(false);
  const area = useRef<HTMLTextAreaElement>(null);
  const current = list.find(t => t.key === key);
  const dirty = !!current && body !== current.body;

  const load = (r: any) => { setList(r.data); setVars(r.variables) };
  useEffect(() => { api('/contract-templates').then(load) }, []);
  useEffect(() => { if (current) setBody(current.body) }, [key, list]);

  function insert(v: string) {
    const el = area.current; if (!el || readOnly) return;
    const token = `{{${v}}}`, start = el.selectionStart, end = el.selectionEnd;
    setBody(b => b.slice(0, start) + token + b.slice(end));
    requestAnimationFrame(() => { el.focus(); el.selectionStart = el.selectionEnd = start + token.length });
  }
  async function preview() {
    setMsg(null);
    try {
      const r = await fetch(`/api/contract-templates/${key}/preview`, {method: 'POST', headers: {'Content-Type': 'application/json', Authorization: `Bearer ${localStorage.getItem('token')}`}, body: JSON.stringify({body})});
      if (!r.ok) throw new Error((await r.json()).message);
      window.open(URL.createObjectURL(await r.blob()), '_blank', 'noopener');
    } catch (e: any) { setMsg({ok: false, text: e.message}) }
  }
  async function save(reset = false) {
    if (reset && !confirm('Restaurar o texto padrão deste modelo? As alterações feitas aqui serão perdidas.')) return;
    setBusy(true); setMsg(null);
    try { load(await api(`/contract-templates/${key}`, {method: 'PUT', body: JSON.stringify({body: reset ? null : body})})); setMsg({ok: true, text: reset ? 'Modelo restaurado ao padrão.' : 'Modelo salvo. Os próximos contratos já usam este texto.'}) }
    catch (e: any) { setMsg({ok: false, text: e.message}) } finally { setBusy(false) }
  }

  return <section className="card settings-form templates-editor">
    <h2>Modelos de contrato</h2>
    <p className="muted">Modelos-base com as cláusulas usuais do mercado. <strong>Revise com o jurídico da imobiliária antes de usar.</strong> Marcação: <code># Título</code>, <code>## CLÁUSULA — NOME</code> (numerada automaticamente), <code>- item</code>, linha em branco entre parágrafos e <code>{'{{assinaturas}}'}</code> onde entram as assinaturas.</p>
    <div className="listing-tabs" role="tablist">{list.map(t => <button key={t.key} role="tab" aria-selected={t.key === key} className={t.key === key ? 'active' : ''} onClick={() => { if (!dirty || confirm('Descartar as alterações não salvas?')) setKey(t.key) }}>{t.name}{t.customized && <b title="Personalizado">✎</b>}</button>)}</div>
    {msg && <p className={msg.ok ? 'notice' : 'error'}>{msg.text}</p>}
    <div className="template-layout">
      <textarea ref={area} value={body} onChange={e => setBody(e.target.value)} readOnly={readOnly} spellCheck={false} aria-label="Texto do modelo" />
      <aside className="var-list" aria-label="Variáveis disponíveis">
        <strong>Variáveis</strong><small>{readOnly ? 'Disponíveis nos modelos.' : 'Clique para inserir no cursor.'}</small>
        {Object.entries(vars).map(([group, items]) => <div key={group}><h3>{group}</h3>{Object.entries(items).map(([k, l]) => <button type="button" key={k} onClick={() => insert(k)} title={`{{${k}}}`} disabled={readOnly}>{l}</button>)}</div>)}
      </aside>
    </div>
    <div className="template-actions">
      <small className="muted">{current?.customized ? `Personalizado${current.updated_by ? ' por ' + current.updated_by : ''}` : 'Texto padrão do sistema'}{dirty ? ' · alterações não salvas' : ''}</small>
      <div>
        <button type="button" className="secondary" onClick={preview}>Pré-visualizar PDF</button>
        {!readOnly && current?.customized && <button type="button" className="secondary" disabled={busy} onClick={() => save(true)}>Restaurar padrão</button>}
        {!readOnly && <button type="button" disabled={busy || !dirty} onClick={() => save()}>Salvar modelo</button>}
      </div>
    </div>
  </section>;
}
