import {useEffect, useMemo, useState, type ReactNode} from 'react';
import {Link, useNavigate, useParams, useSearchParams} from 'react-router-dom';
import {api, date, money} from '../lib/api';
import {compressPhoto} from '../lib/property';
import {creditStatus, employmentLabel, eventText, formatCpf, guaranteeLabel, installment, maritalLabel, methodLabel, regimeLabel, type CreditApp, type Method, type Person} from '../lib/credit';
import type {AppUser} from './AppLayout';
import {Icon} from './Icon';
import {IssueContractButton, contractStatus} from './Contracts';

const val = (v: any) => v === null || v === undefined ? '' : String(v);

/* ---------- Nova ficha ---------- */

export function NewCredit({user}: {user: AppUser}) {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const [opps, setOpps] = useState<any[]>([]);
  const [props, setProps] = useState<any[]>([]);
  const [mode, setMode] = useState<'wallet' | 'new'>(user.role === 'broker' ? 'wallet' : 'new');
  const [form, setForm] = useState({opportunity_id: params.get('oportunidade') || '', property_id: params.get('imovel') || '', payment_method: '' as Method | '', name: '', phone: '', email: ''});
  const [q, setQ] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => { if (user.role === 'broker') api('/my-opportunities').then(r => setOpps(r.data.filter((o: any) => !['won', 'lost'].includes(o.stage)))) }, []);
  useEffect(() => {
    const t = setTimeout(() => api(`/properties?listing_status=approved&available=1&per_page=30${q ? '&q=' + encodeURIComponent(q) : ''}`).then(r => setProps(r.data)), q ? 250 : 0);
    return () => clearTimeout(t);
  }, [q]);
  const property = props.find(p => p.id === form.property_id);
  const methods = (Object.keys(methodLabel) as Method[]).filter(m => !property || property.purpose === 'both' || (property.purpose === 'rental') === (m === 'rental'));
  const set = (k: string, v: string) => setForm(f => ({...f, [k]: v}));

  async function create(e: React.FormEvent) {
    e.preventDefault(); setError('');
    if (!form.property_id || !form.payment_method) { setError('Escolha o imóvel e a modalidade.'); return }
    setBusy(true);
    try {
      const body: any = {property_id: form.property_id, payment_method: form.payment_method};
      if (mode === 'wallet') { if (!form.opportunity_id) throw new Error('Escolha o cliente da sua carteira.'); body.opportunity_id = form.opportunity_id }
      else body.client = {name: form.name, phone: form.phone, email: form.email};
      const r = await api('/credit', {method: 'POST', body: JSON.stringify(body)});
      navigate(`/fichas/${r.data.id}`, {replace: true});
    } catch (x: any) { setError(x.message) } finally { setBusy(false) }
  }

  return <div className="property-editor">
    <header><div><Link to="/fichas" className="back-link">← Fichas</Link><h1>Nova ficha de interesse</h1><p className="muted">Escolha o cliente, o imóvel e como ele pretende pagar. Os dados pessoais vêm na próxima tela.</p></div></header>
    <form className="card editor-step" onSubmit={create}>
      <h2>Cliente</h2>
      {user.role === 'broker' && <div className="segmented" role="radiogroup">
        <button type="button" className={mode === 'wallet' ? 'selected' : ''} aria-checked={mode === 'wallet'} role="radio" onClick={() => setMode('wallet')}>Da minha carteira</button>
        <button type="button" className={mode === 'new' ? 'selected' : ''} aria-checked={mode === 'new'} role="radio" onClick={() => setMode('new')}>Cliente novo</button>
      </div>}
      {mode === 'wallet' ? <label>Cliente<select value={form.opportunity_id} onChange={e => set('opportunity_id', e.target.value)}><option value="">Selecione</option>{opps.map(o => <option key={o.id} value={o.id}>{o.name} · {o.region || o.interest_type}</option>)}</select></label>
        : <div className="grid"><label>Nome completo *<input value={form.name} onChange={e => set('name', e.target.value)} autoComplete="off" /></label><label>Celular *<input type="tel" value={form.phone} onChange={e => set('phone', e.target.value)} autoComplete="off" /></label><label>E-mail<input type="email" value={form.email} onChange={e => set('email', e.target.value)} autoComplete="off" /></label></div>}

      <h2>Imóvel</h2>
      <input placeholder="Buscar por referência, título ou bairro" value={q} onChange={e => setQ(e.target.value)} aria-label="Buscar imóvel" />
      <div className="pick-list" role="radiogroup" aria-label="Imóvel">
        {props.map(p => <button type="button" role="radio" aria-checked={form.property_id === p.id} key={p.id} className={form.property_id === p.id ? 'selected' : ''} onClick={() => { set('property_id', p.id); if (p.purpose !== 'both') set('payment_method', p.purpose === 'rental' ? 'rental' : '') }}>
          {p.cover_url ? <img src={p.cover_url} alt="" /> : <span className="pick-noimg"><Icon name="building" /></span>}
          <span><strong>{p.reference_code} · {p.title}</strong><small>{p.neighborhood || p.region || ''} · {p.purpose === 'rental' ? `${money(p.price)}/mês` : money(p.price)}{p.purpose === 'both' && p.rental_price ? ` · aluguel ${money(p.rental_price)}` : ''}</small></span>
        </button>)}
        {!props.length && <p className="muted">Nenhum imóvel publicado encontrado.</p>}
      </div>

      <h2>Modalidade</h2>
      <div className="type-picker" role="radiogroup">{methods.map(m => <button type="button" role="radio" aria-checked={form.payment_method === m} key={m} className={form.payment_method === m ? 'selected' : ''} onClick={() => set('payment_method', m)}>{methodLabel[m]}</button>)}</div>
      {error && <p className="error" role="alert">{error}</p>}
      <button disabled={busy}>{busy ? 'Criando…' : 'Criar ficha e continuar'}</button>
    </form>
  </div>;
}

/* ---------- Ficha (corretor edita / analista decide) ---------- */

const STEPS = ['Condições', 'Proponente', 'Composição e fiador', 'Documentos', 'Revisão e envio'];

export function CreditPage({user}: {user: AppUser}) {
  const {id} = useParams();
  const [app, setApp] = useState<CreditApp | null>(null);
  const [form, setForm] = useState<any>({});
  const [step, setStep] = useState(0);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ok: boolean; text: string} | null>(null);
  const back = user.role === 'broker' ? '/fichas' : '/analises';

  const apply = (a: CreditApp, text?: string) => { setApp(a); setForm({...a, co_applicant: a.co_applicant || {}, guarantor: a.guarantor || {}}); if (text) setNotice({ok: true, text}) };
  useEffect(() => { api(`/credit/${id}`).then(r => apply(r.data)).catch(e => setNotice({ok: false, text: e.message})) }, [id]);

  async function run(fn: () => Promise<any>, text?: string) {
    setBusy(true); setNotice(null);
    try { const r = await fn(); if (r?.data) apply(r.data, text); return true } catch (e: any) { setNotice({ok: false, text: e.message}); return false } finally { setBusy(false) }
  }
  const save = (extra: any = {}) => run(() => api(`/credit/${id}`, {method: 'PUT', body: JSON.stringify({
    ...pick(form, ['offer_value', 'down_payment', 'fgts_value', 'financing_term_months', 'bank_preference', 'rent_value', 'guarantee_type', 'lease_months', 'move_in_date', 'notes', 'payment_method']),
    applicant: form.applicant, co_applicant: hasName(form.co_applicant) ? form.co_applicant : null, guarantor: hasName(form.guarantor) ? form.guarantor : null, ...extra,
  })}), 'Ficha salva.');

  if (!app) return notice ? <p className="error">{notice.text}</p> : <p className="muted">Carregando ficha…</p>;
  const editing = app.can_edit;

  return <div className="property-editor credit-page">
    <header>
      <div>
        <Link to={back} className="back-link">← {user.role === 'broker' ? 'Fichas' : 'Análises'}</Link>
        <h1>{app.applicant_name || 'Ficha de interesse'}</h1>
        <p className="muted">{app.code} · <span className={'badge credit-' + app.status}>{creditStatus[app.status]}</span> · {methodLabel[app.payment_method]} · corretor {app.broker_name}{app.analyst_name ? ` · analista ${app.analyst_name}` : ''}</p>
      </div>
    </header>

    <DecisionBanner app={app} user={user} />
    {['approved', 'approved_conditions'].includes(app.status) && user.role !== 'analyst' && <div className="contract-cta card">
      {app.contract ? <><div><strong>Contrato {app.contract.code}</strong><small>{contractStatus[app.contract.status] || app.contract.status}</small></div><Link className="button" to={'/contratos/' + app.contract.id}>Abrir contrato</Link></>
        : <><div><strong>Pronto para o contrato</strong><small>O contrato sai preenchido com os dados desta ficha, do imóvel e da imobiliária.</small></div><IssueContractButton creditId={app.id} /></>}
    </div>}
    {notice && <p className={(notice.ok ? 'notice' : 'error') + ' notice-box'} role={notice.ok ? 'status' : 'alert'}>{notice.text}</p>}

    <PropertyStrip app={app} />

    {editing ? <>
      <ol className="stepper steps-5">{STEPS.map((s, i) => <li key={s}><button type="button" className={i === step ? 'current' : ''} onClick={() => setStep(i)} aria-current={i === step ? 'step' : undefined}><i>{i + 1}</i><span>{s}</span></button></li>)}</ol>
      <fieldset className="card editor-step" disabled={busy}>
        {step === 0 && <Conditions form={form} setForm={setForm} app={app} />}
        {step === 1 && <><h2>Proponente</h2><PersonFields value={form.applicant || {}} onChange={v => setForm({...form, applicant: v})} full /></>}
        {step === 2 && <>
          <h2>Cônjuge ou compositor de renda</h2>
          <p className="muted">Preencha se o cliente for casado(a)/em união estável ou se outra pessoa vai somar renda. Deixe o nome em branco se não houver.</p>
          <PersonFields value={form.co_applicant || {}} onChange={v => setForm({...form, co_applicant: v})} relation />
          {app.payment_method === 'rental' && form.guarantee_type === 'guarantor' && <><h2 className="spaced">Fiador</h2><PersonFields value={form.guarantor || {}} onChange={v => setForm({...form, guarantor: v})} full /></>}
        </>}
        {step === 3 && <Documents app={app} editable onChange={a => apply(a)} onError={t => setNotice({ok: false, text: t})} />}
        {step === 4 && <>
          <h2>Revisão e envio</h2>
          <Summary app={app} />
          <label>Observações para o analista<textarea rows={3} value={val(form.notes)} onChange={e => setForm({...form, notes: e.target.value})} placeholder="Ex.: cliente já tem carta de crédito pré-aprovada; prefere assinar até dia 10." /></label>
          <label className="check-line consent"><input type="checkbox" checked={!!app.consent_at} onChange={e => e.target.checked && save({consent: true})} disabled={!!app.consent_at} /> O cliente autorizou a análise de crédito e o uso destes dados e documentos para esta negociação (LGPD).</label>
          {app.missing.length ? <div className="checklist"><strong>Falta para enviar ({app.missing.length})</strong><ul>{app.missing.map(m => <li key={m}>{m}</li>)}</ul><small>Salve para atualizar a lista.</small></div> : <div className="checklist ok"><Icon name="check" /> Tudo pronto para envio.</div>}
          <History app={app} />
        </>}
      </fieldset>
      <footer className="editor-actions">
        <button type="button" className="secondary" disabled={busy || step === 0} onClick={() => setStep(s => s - 1)}>Voltar</button>
        <div>
          <CancelButton app={app} user={user} busy={busy} onCancel={reason => run(() => api(`/credit/${id}/cancel`, {method: 'POST', body: JSON.stringify({reason})}), 'Ficha cancelada.')} />
          {step < STEPS.length - 1 && <button type="button" disabled={busy} onClick={async () => { if (step === 3 || await save()) setStep(s => s + 1) }}>{step === 3 ? 'Continuar' : 'Salvar e continuar'}</button>}
          {step === STEPS.length - 1 && <>
            <button type="button" className="secondary" disabled={busy} onClick={() => save()}>Salvar</button>
            <button type="button" disabled={busy || app.missing.length > 0} onClick={async () => { if (await save()) await run(() => api(`/credit/${id}/submit`, {method: 'POST'}), app.status === 'pending_docs' ? 'Ficha reenviada ao analista.' : 'Ficha enviada para análise. Você será avisado da resposta.') }}>{app.status === 'pending_docs' ? 'Reenviar ao analista' : 'Enviar para análise'}</button>
          </>}
        </div>
      </footer>
    </> : <div className="credit-read">
      <div className="credit-cols">
        <section className="card"><h2>Condições</h2><Summary app={app} /></section>
        {user.role !== 'broker' && <Affordability app={app} />}
      </div>
      <section className="card"><h2>Proponente</h2><PersonView p={app.applicant} /></section>
      {app.co_applicant?.name && <section className="card"><h2>Cônjuge / compositor de renda</h2><PersonView p={app.co_applicant} /></section>}
      {app.guarantor?.name && <section className="card"><h2>Fiador</h2><PersonView p={app.guarantor} /></section>}
      <section className="card"><Documents app={app} editable={user.role !== 'broker' && !['draft', 'cancelled'].includes(app.status)} onChange={a => apply(a)} onError={t => setNotice({ok: false, text: t})} /></section>
      {app.notes && <section className="card"><h2>Observações do corretor</h2><p className="pre">{app.notes}</p></section>}
      {app.status === 'submitted' && user.role !== 'broker' && <section className="card review-panel"><h2>Análise</h2><p className="muted">Assuma a ficha para registrar a decisão. O corretor é avisado.</p><button disabled={busy} onClick={() => run(() => api(`/credit/${id}/claim`, {method: 'POST'}), 'Ficha assumida. Ela está na aba “Comigo”.')}>Assumir análise</button></section>}
      {app.can_decide && <DecisionPanel app={app} busy={busy} onDecide={body => run(() => api(`/credit/${id}/decision`, {method: 'POST', body: JSON.stringify(body)}), 'Decisão registrada e enviada ao corretor.')} />}
      <section className="card"><History app={app} open /></section>
      {user.role === 'broker' && <div className="editor-actions"><span /><div><CancelButton app={app} user={user} busy={busy} onCancel={reason => run(() => api(`/credit/${id}/cancel`, {method: 'POST', body: JSON.stringify({reason})}), 'Ficha cancelada.')} /></div></div>}
    </div>}
  </div>;
}

const pick = (o: any, keys: string[]) => Object.fromEntries(keys.filter(k => k in o).map(k => [k, o[k]]));
const hasName = (p?: Person | null) => !!p && !!(p.name || '').trim();

function DecisionBanner({app, user}: {app: CreditApp; user: AppUser}) {
  const s = app.status;
  if (s === 'approved' || s === 'approved_conditions') return <div className="status-banner approved"><strong>{s === 'approved' ? 'Crédito aprovado' : 'Aprovado com condições'}</strong>{app.approved_value && <> · valor aprovado {money(app.approved_value)}{app.business_type === 'rental' ? '/mês' : ''}</>}
    {app.approved_conditions && <p>Condições: {app.approved_conditions}</p>}{app.decision_notes && <p>{app.decision_notes}</p>}
    {user.role === 'broker' && <p className="next-step">Próximo passo: repasse a resposta ao cliente e emita o contrato para assinatura.</p>}</div>;
  if (s === 'rejected') return <div className="status-banner rejected"><strong>Crédito reprovado.</strong>{app.decision_notes && <p>Motivo: {app.decision_notes}</p>}</div>;
  if (s === 'pending_docs') return <div className="status-banner pending" role={user.role === 'broker' ? 'alert' : undefined}><strong>Pendência solicitada pelo analista.</strong>{app.decision_notes && <p>{app.decision_notes}</p>}{user.role === 'broker' && <p>Resolva a pendência e reenvie na última etapa.</p>}</div>;
  if (s === 'submitted' || s === 'in_analysis') return <div className="status-banner pending"><strong>{s === 'submitted' ? 'Aguardando um analista assumir.' : `Em análise${app.analyst_name ? ' com ' + app.analyst_name : ''}.`}</strong> {user.role === 'broker' && 'A ficha fica bloqueada até a resposta.'}</div>;
  if (s === 'cancelled') return <div className="status-banner archived"><strong>Ficha cancelada.</strong></div>;
  return null;
}

function PropertyStrip({app}: {app: CreditApp}) {
  const p = app.property;
  return <div className="credit-strip card">
    <div><small>Imóvel</small><strong>{p.reference_code} · {p.title}</strong><span>{[p.neighborhood, p.city].filter(Boolean).join(', ')}</span></div>
    <div><small>Anunciado por</small><strong>{app.business_type === 'rental' ? `${money(p.rental_price || p.price)}/mês` : money(p.price)}</strong>{Number(p.condo_fee) > 0 && <span>Condomínio {money(p.condo_fee)}</span>}</div>
    {app.client && <div><small>Cliente</small><strong>{app.client.name}</strong><span>{app.client.phone || app.client.email}</span></div>}
  </div>;
}

function Conditions({form, setForm, app}: {form: any; setForm: (f: any) => void; app: CreditApp}) {
  const set = (k: string, v: any) => setForm({...form, [k]: v});
  const m: Method = form.payment_method;
  const financed = Math.max(0, Number(form.offer_value || 0) - Number(m === 'down_payment_financing' ? form.down_payment || 0 : 0) - Number(form.fgts_value || 0));
  const moneyField = (k: string, label: string, hint?: string) => <label key={k}>{label}<input type="number" inputMode="decimal" min="0" step="0.01" value={val(form[k])} onChange={e => set(k, e.target.value)} />{hint && <small className="field-hint">{hint}</small>}</label>;
  return <>
    <h2>Condições da proposta</h2>
    {app.business_type === 'purchase' && <div className="type-picker" role="radiogroup">{(['cash', 'financing', 'down_payment_financing'] as Method[]).map(x => <button type="button" role="radio" aria-checked={m === x} key={x} className={m === x ? 'selected' : ''} onClick={() => set('payment_method', x)}>{methodLabel[x]}</button>)}</div>}
    {app.business_type === 'purchase' ? <div className="grid">
      {moneyField('offer_value', 'Valor da proposta (R$) *', `Anunciado por ${money(app.property.price)}`)}
      {m === 'down_payment_financing' && moneyField('down_payment', 'Entrada em dinheiro (R$) *')}
      {m !== 'cash' && moneyField('fgts_value', 'Uso do FGTS (R$)')}
      {m !== 'cash' && <label>Prazo do financiamento (meses) *<input type="number" inputMode="numeric" min="12" max="420" value={val(form.financing_term_months)} onChange={e => set('financing_term_months', e.target.value)} /><small className="field-hint">Até 420 meses (35 anos).</small></label>}
      {m !== 'cash' && <label>Banco de preferência<input value={val(form.bank_preference)} onChange={e => set('bank_preference', e.target.value)} placeholder="Caixa, Itaú, Bradesco…" /></label>}
      {m !== 'cash' && <div className="computed"><small>Valor a financiar</small><strong>{money(financed)}</strong></div>}
    </div> : <div className="grid">
      {moneyField('rent_value', 'Aluguel proposto (R$/mês) *', `Anunciado por ${money(app.property.rental_price || app.property.price)}`)}
      <label>Garantia *<select value={val(form.guarantee_type)} onChange={e => set('guarantee_type', e.target.value)}><option value="">Selecione</option>{Object.entries(guaranteeLabel).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>
      <label>Prazo do contrato (meses)<input type="number" inputMode="numeric" min="1" max="120" value={val(form.lease_months)} onChange={e => set('lease_months', e.target.value)} placeholder="30" /></label>
      <label>Mudança prevista<input type="date" value={val(form.move_in_date)} onChange={e => set('move_in_date', e.target.value)} /></label>
    </div>}
  </>;
}

function PersonFields({value, onChange, full, relation}: {value: Person; onChange: (p: Person) => void; full?: boolean; relation?: boolean}) {
  const set = (k: keyof Person, v: string) => onChange({...value, [k]: v});
  const text = (k: keyof Person, label: string, type = 'text', mode?: 'numeric' | 'decimal' | 'tel' | 'email') => <label key={k}>{label}<input type={type} inputMode={mode} autoComplete="off" value={val(value[k])} onChange={e => set(k, e.target.value)} /></label>;
  const cpfOk = !value.cpf || validCpf(value.cpf);
  return <div className="grid">
    {text('name', 'Nome completo')}
    <label>CPF<input inputMode="numeric" autoComplete="off" value={val(value.cpf)} onChange={e => set('cpf', e.target.value)} aria-invalid={!cpfOk} />{!cpfOk && <small className="field-hint error-text">CPF inválido</small>}</label>
    {relation && <label>Relação com o proponente<select value={val(value.relationship)} onChange={e => set('relationship', e.target.value)}><option value="">Selecione</option><option value="spouse">Cônjuge / companheiro(a)</option><option value="relative">Parente</option><option value="other">Outro</option></select></label>}
    {full && <>{text('rg', 'RG')}{text('rg_issuer', 'Órgão emissor')}{text('birth_date', 'Nascimento', 'date')}{text('nationality', 'Nacionalidade')}
      <label>Estado civil<select value={val(value.marital_status)} onChange={e => set('marital_status', e.target.value)}><option value="">Selecione</option>{Object.entries(maritalLabel).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>
      {['married', 'stable_union'].includes(value.marital_status || '') && <label>Regime de bens<select value={val(value.marriage_regime)} onChange={e => set('marriage_regime', e.target.value)}><option value="">Selecione</option>{Object.entries(regimeLabel).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>}
    </>}
    {text('profession', 'Profissão')}
    <label>Vínculo de trabalho<select value={val(value.employment_type)} onChange={e => set('employment_type', e.target.value)}><option value="">Selecione</option>{Object.entries(employmentLabel).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>
    {text('employer', 'Empresa / fonte de renda')}
    {text('monthly_income', 'Renda mensal bruta (R$)', 'number', 'decimal')}
    {text('other_income', 'Outras rendas (R$)', 'number', 'decimal')}
    {full && <>{text('phone', 'Celular', 'tel', 'tel')}{text('email', 'E-mail', 'email', 'email')}{text('zip_code', 'CEP', 'text', 'numeric')}{text('address', 'Endereço residencial')}{text('city', 'Cidade')}{text('state', 'UF')}</>}
  </div>;
}

function validCpf(v: string) {
  const d = v.replace(/\D/g, '');
  if (d.length !== 11 || /^(\d)\1{10}$/.test(d)) return false;
  for (let t = 9; t < 11; t++) { let s = 0; for (let i = 0; i < t; i++) s += Number(d[i]) * (t + 1 - i); if (Number(d[t]) !== ((10 * s) % 11) % 10) return false }
  return true;
}

function PersonView({p}: {p: Person}) {
  const rows: [string, ReactNode][] = [
    ['Nome', p.name], ['CPF', formatCpf(p.cpf)], ['RG', [p.rg, p.rg_issuer].filter(Boolean).join(' / ')], ['Nascimento', p.birth_date ? new Date(p.birth_date + 'T12:00').toLocaleDateString('pt-BR') : ''],
    ['Estado civil', [maritalLabel[p.marital_status || ''], regimeLabel[p.marriage_regime || '']].filter(Boolean).join(' · ')], ['Profissão', p.profession], ['Vínculo', employmentLabel[p.employment_type || '']], ['Empresa', p.employer],
    ['Renda mensal', p.monthly_income ? money(p.monthly_income) : ''], ['Outras rendas', Number(p.other_income) > 0 ? money(p.other_income) : ''], ['Contato', [p.phone, p.email].filter(Boolean).join(' · ')], ['Endereço', [p.address, p.city && `${p.city}/${p.state || ''}`].filter(Boolean).join(', ')],
  ];
  return <dl className="modal-meta summary-list">{rows.filter(([, v]) => v).map(([k, v]) => <div key={k} className="dl-row"><dt>{k}</dt><dd>{v}</dd></div>)}</dl>;
}

function Summary({app}: {app: CreditApp}) {
  const rows: [string, ReactNode][] = app.business_type === 'rental' ? [
    ['Aluguel proposto', app.rent_value ? `${money(app.rent_value)}/mês` : '—'], ['Garantia', guaranteeLabel[app.guarantee_type || ''] || '—'], ['Prazo', app.lease_months ? `${app.lease_months} meses` : '—'], ['Mudança', app.move_in_date ? new Date(app.move_in_date + 'T12:00').toLocaleDateString('pt-BR') : '—'], ['Renda familiar', money(app.household_income)],
  ] : [
    ['Modalidade', methodLabel[app.payment_method]], ['Valor da proposta', money(app.offer_value)], ...(app.payment_method === 'down_payment_financing' ? [['Entrada', money(app.down_payment)] as [string, ReactNode]] : []),
    ...(app.payment_method !== 'cash' ? [['FGTS', money(app.fgts_value)], ['A financiar', money(app.financing_value)], ['Prazo', app.financing_term_months ? `${app.financing_term_months} meses` : '—'], ['Banco', app.bank_preference || '—']] as [string, ReactNode][] : []),
    ['Renda familiar', money(app.household_income)],
  ];
  return <dl className="modal-meta summary-list">{rows.map(([k, v]) => <div key={k} className="dl-row"><dt>{k}</dt><dd>{v}</dd></div>)}</dl>;
}

/** Calculadora do analista: comprometimento de renda pela Tabela Price (compra) ou pelo custo mensal (locação). */
function Affordability({app}: {app: CreditApp}) {
  const [rate, setRate] = useState(11.5);
  const income = Number(app.household_income || 0);
  const p = app.property;
  const monthly = useMemo(() => app.business_type === 'rental'
    ? Number(app.rent_value || 0) + Number(p.condo_fee || 0) + Number(p.iptu_yearly || 0) / 12
    : app.payment_method === 'cash' ? 0 : installment(Number(app.financing_value || 0), rate, Number(app.financing_term_months || 0)), [app, rate]);
  const ratio = income ? monthly / income * 100 : 0;
  const limit = 30;
  if (app.payment_method === 'cash') return <section className="card afford"><h2>Capacidade de pagamento</h2><p className="muted">Venda à vista: confirme a origem dos recursos (extratos, venda de outro imóvel, consórcio).</p><dl className="modal-meta summary-list"><div className="dl-row"><dt>Valor</dt><dd>{money(app.offer_value)}</dd></div><div className="dl-row"><dt>Renda familiar</dt><dd>{money(income)}</dd></div></dl></section>;
  return <section className="card afford">
    <h2>Capacidade de pagamento</h2>
    {app.business_type === 'purchase' && <label className="inline-rate">Taxa anual (% a.a.)<input type="number" step="0.1" min="0" value={rate} onChange={e => setRate(Number(e.target.value))} /></label>}
    <div className={'afford-meter ' + (ratio > limit ? 'over' : ratio > limit * .85 ? 'near' : 'ok')}>
      <div><small>{app.business_type === 'rental' ? 'Custo mensal (aluguel + condomínio + IPTU)' : 'Parcela estimada (Price)'}</small><strong>{money(monthly)}</strong></div>
      <div><small>Comprometimento da renda</small><strong>{income ? `${ratio.toFixed(1)}%` : 'informe a renda'}</strong></div>
      <progress max={60} value={Math.min(60, ratio)} aria-label="Comprometimento da renda" />
      <small>Referência de mercado: até {limit}% da renda familiar bruta{app.business_type === 'rental' ? ' (equivale a renda ≈ 3,3× o custo mensal)' : ''}.</small>
    </div>
  </section>;
}

function Documents({app, editable, onChange, onError}: {app: CreditApp; editable: boolean; onChange: (a: CreditApp) => void; onError: (t: string) => void}) {
  const [kind, setKind] = useState(app.required_documents.find(k => !app.documents.some(d => d.label === k)) || 'other');
  const [busy, setBusy] = useState(false);
  const labels = app.labels.documents;
  async function upload(files: FileList | null) {
    if (!files?.length) return;
    setBusy(true);
    try {
      let last: any;
      for (const f of Array.from(files)) {
        const fd = new FormData();
        if (f.type.startsWith('image/')) { const {blob} = await compressPhoto(f, 2400); fd.append('file', blob, f.name.replace(/\.\w+$/, '') + '.jpg') } else fd.append('file', f);
        fd.append('kind', kind);
        last = await api(`/credit/${app.id}/documents`, {method: 'POST', body: fd});
      }
      onChange(last.data);
      const next = last.data.required_documents.find((k: string) => !last.data.documents.some((d: any) => d.label === k));
      if (next) setKind(next);
    } catch (e: any) { onError(e.message) } finally { setBusy(false) }
  }
  async function open(docId: string) {
    try {
      const r = await fetch(`/api/credit/${app.id}/documents/${docId}?inline=1`, {headers: {Authorization: `Bearer ${localStorage.getItem('token')}`}});
      if (!r.ok) throw new Error((await r.json()).message);
      window.open(URL.createObjectURL(await r.blob()), '_blank', 'noopener');
    } catch (e: any) { onError(e.message) }
  }
  async function remove(docId: string) { if (!confirm('Remover este documento?')) return; try { onChange((await api(`/credit/${app.id}/documents/${docId}`, {method: 'DELETE'})).data) } catch (e: any) { onError(e.message) } }
  return <>
    <h2>Documentos</h2>
    <ul className="doc-checklist">{app.required_documents.map(k => { const ok = app.documents.some(d => d.label === k); return <li key={k} className={ok ? 'ok' : ''}>{ok ? <Icon name="check" /> : <i />}{labels[k]}</li> })}</ul>
    {editable && <div className="optional-add">
      <select value={kind} onChange={e => setKind(e.target.value)} aria-label="Tipo de documento">{Object.entries(labels).map(([k, l]) => <option key={k} value={k}>{l}{app.required_documents.includes(k) ? ' *' : ''}</option>)}</select>
      <label className={'button upload-button' + (busy ? ' disabled' : '')}>{busy ? 'Enviando…' : 'Anexar arquivo ou foto'}<input type="file" accept="application/pdf,image/*" multiple hidden disabled={busy} onChange={e => { upload(e.target.files); e.target.value = '' }} /></label>
    </div>}
    <ul className="doc-list">
      {app.documents.map(d => <li key={d.id}>
        <Icon name="file" /><button type="button" className="link-button" onClick={() => open(d.id)}><strong>{labels[d.label] || d.label}</strong><small>{d.original_name} · {(d.size_bytes / 1024).toFixed(0)} KB · {d.uploaded_by_name} · {date(d.created_at)}</small></button>
        {app.can_edit && <button type="button" className="thumb-remove-inline" onClick={() => remove(d.id)} aria-label="Remover documento"><Icon name="close" /></button>}
      </li>)}
      {!app.documents.length && <li className="muted">Nenhum documento anexado.</li>}
    </ul>
    <p className="muted small">Os arquivos ficam em armazenamento privado e cada acesso é registrado na auditoria.</p>
  </>;
}

function DecisionPanel({app, busy, onDecide}: {app: CreditApp; busy: boolean; onDecide: (b: any) => void}) {
  const [decision, setDecision] = useState<'approve' | 'approve_conditions' | 'pending_docs' | 'reject'>('approve');
  const [notes, setNotes] = useState(''); const [conditions, setConditions] = useState('');
  const [value, setValue] = useState(val(app.business_type === 'rental' ? app.rent_value : app.financing_value || app.offer_value));
  const needNotes = decision === 'reject' || decision === 'pending_docs';
  const ok = (!needNotes || notes.trim()) && (decision !== 'approve_conditions' || conditions.trim());
  return <section className="card review-panel">
    <h2>Decisão</h2>
    <div className="type-picker" role="radiogroup">{([['approve', 'Aprovar'], ['approve_conditions', 'Aprovar com condições'], ['pending_docs', 'Solicitar pendência'], ['reject', 'Reprovar']] as const).map(([k, l]) => <button type="button" role="radio" aria-checked={decision === k} key={k} className={(decision === k ? 'selected ' : '') + k} onClick={() => setDecision(k)}>{l}</button>)}</div>
    <div className="grid">
      {decision.startsWith('approve') && <label>Valor aprovado (R$){app.business_type === 'rental' ? ' / mês' : ''}<input type="number" step="0.01" min="0" value={value} onChange={e => setValue(e.target.value)} /></label>}
    </div>
    {decision === 'approve_conditions' && <label>Condições *<textarea rows={2} value={conditions} onChange={e => setConditions(e.target.value)} placeholder="Ex.: entrada mínima de R$ 130.000; fiador com imóvel quitado." /></label>}
    <label>{decision === 'pending_docs' ? 'O que falta? *' : decision === 'reject' ? 'Motivo *' : 'Observações para o corretor'}<textarea rows={3} value={notes} onChange={e => setNotes(e.target.value)} /></label>
    <div className="review-actions"><button type="button" className={decision === 'reject' ? 'danger' : decision === 'pending_docs' ? '' : 'publish'} disabled={busy || !ok} onClick={() => onDecide({decision, notes, conditions, approved_value: value})}>Registrar decisão</button></div>
  </section>;
}

function CancelButton({app, user, busy, onCancel}: {app: CreditApp; user: AppUser; busy: boolean; onCancel: (reason: string) => void}) {
  if (app.status === 'cancelled' || app.status === 'rejected' || user.role === 'analyst') return null;
  return <button type="button" className="danger-link" disabled={busy} onClick={() => { const r = prompt('Motivo do cancelamento da ficha:'); if (r && r.trim()) onCancel(r.trim()) }}>Cancelar ficha</button>;
}

function History({app, open}: {app: CreditApp; open?: boolean}) {
  if (!app.events.length) return null;
  return <details className="history" open={open}><summary>Histórico da ficha</summary><ul>{app.events.map((e, i) => <li key={i}><b>{eventText[e.action] || e.action}</b> · {e.user_name} · {date(e.created_at)}{e.notes && <p>{e.notes}</p>}</li>)}</ul></details>;
}
