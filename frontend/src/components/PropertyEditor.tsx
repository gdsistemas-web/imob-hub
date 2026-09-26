import {Fragment, useEffect, useMemo, useRef, useState, type ReactNode} from 'react';
import {Link, useNavigate, useParams, useSearchParams} from 'react-router-dom';
import {api, date, money} from '../lib/api';
import {CONDO_FEATURES, FEATURES, commercialLabel, compressPhoto, eventLabel, listingLabel, suggestTitle, type GuideSlot, type Photo, type Property, type PropertyType} from '../lib/property';
import type {AppUser} from './AppLayout';
import {Icon} from './Icon';

const STEPS = [
  {key: 'basics', label: 'Tipo e valores'},
  {key: 'address', label: 'Endereço'},
  {key: 'details', label: 'Características'},
  {key: 'owner', label: 'Proprietário'},
  {key: 'photos', label: 'Fotos'},
  {key: 'publish', label: 'Revisão e envio'},
] as const;

const EDITABLE = ['reference_code', 'title', 'property_type', 'purpose', 'status', 'price', 'rental_price', 'condo_fee', 'iptu_yearly', 'accepts_financing', 'accepts_exchange', 'commission_percent', 'exclusive_listing', 'available_from',
  'zip_code', 'street', 'street_number', 'complement', 'neighborhood', 'city', 'state', 'condo_name', 'show_full_address',
  'area_built', 'area_total', 'bedrooms', 'suites', 'bathrooms', 'parking_spaces', 'floor_number', 'year_built', 'furnished', 'accepts_pets', 'features', 'condo_features',
  'owner_name', 'owner_document', 'owner_phone', 'owner_email', 'registry_number', 'registry_office', 'iptu_number', 'keys_location', 'description'];

type Form = Record<string, any>;
const blank: Form = {property_type: '', purpose: 'purchase', features: [], condo_features: []};
const val = (v: any) => v === null || v === undefined ? '' : String(v);

export function PropertyEditor({user}: {user: AppUser}) {
  const {id} = useParams();
  const navigate = useNavigate();
  const [types, setTypes] = useState<PropertyType[]>([]);
  const [p, setP] = useState<Property | null>(null);
  const [form, setForm] = useState<Form>(blank);
  const [search] = useSearchParams();
  const [step, setStep] = useState(() => Math.min(STEPS.length - 1, Math.max(0, Number(search.get('etapa') || 1) - 1)));
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{kind: 'ok' | 'error'; text: string} | null>(null);
  const moderator = ['admin', 'manager'].includes(user.role);

  useEffect(() => { api('/property-types').then(r => setTypes(r.data)) }, []);
  useEffect(() => {
    if (!id) { setP(null); setForm(blank); setStep(0); return }
    api(`/properties/${id}`).then(r => { setP(r.data); setForm(r.data) }).catch(e => setNotice({kind: 'error', text: e.message}));
  }, [id]);

  const type = types.find(t => t.value === form.property_type);
  const group = type?.group || 'residential';
  const readOnly = !!p && !p.can_edit;
  const set = (k: string, v: any) => setForm(f => ({...f, [k]: v}));

  function applyServer(data: Property, text?: string) {
    setP(data); setForm(data);
    if (text) setNotice({kind: 'ok', text});
  }

  async function run<T>(fn: () => Promise<T>): Promise<T | undefined> {
    setBusy(true); setNotice(null);
    try { return await fn() } catch (e: any) { setNotice({kind: 'error', text: e.message}); return undefined } finally { setBusy(false) }
  }

  async function save(): Promise<Property | undefined> {
    if (readOnly) return p || undefined;
    const body = Object.fromEntries(EDITABLE.filter(k => k in form).map(k => [k, form[k]]));
    return run(async () => {
      if (!p) {
        const r = await api('/properties', {method: 'POST', body: JSON.stringify(body)});
        applyServer(r.data);
        navigate(`/imoveis/${r.data.id}`, {replace: true});
        return r.data as Property;
      }
      const r = await api(`/properties/${p.id}`, {method: 'PUT', body: JSON.stringify(body)});
      applyServer(r.data, r.data.listing_status === 'pending_review' && p.listing_status === 'approved' ? 'Alterações salvas. O anúncio voltou para análise da gestão.' : 'Alterações salvas.');
      return r.data as Property;
    });
  }

  async function next() {
    if (step === 0 && (!form.property_type || !form.purpose)) { setNotice({kind: 'error', text: 'Escolha o tipo e a finalidade do imóvel.'}); return }
    const saved = await save();
    if (saved) { setStep(s => Math.min(STEPS.length - 1, s + 1)); window.scrollTo({top: 0, behavior: 'smooth'}) }
  }

  async function submit() {
    const saved = await save();
    if (!saved) return;
    await run(async () => { const r = await api(`/properties/${saved.id}/submit`, {method: 'POST'}); applyServer(r.data, 'Anúncio enviado para aprovação. Você será avisado quando for analisado.') });
  }

  async function review(decision: 'approve' | 'reject' | 'archive', notes?: string) {
    if (!p) return;
    await run(async () => {
      const r = await api(`/properties/${p.id}/review`, {method: 'POST', body: JSON.stringify({decision, notes})});
      applyServer(r.data, {approve: 'Anúncio aprovado e publicado.', reject: 'Anúncio devolvido ao corretor com o motivo informado.', archive: 'Anúncio arquivado.'}[decision]);
    });
  }

  async function publishNow() {
    const saved = await save();
    if (!saved) return;
    await run(async () => {
      if (saved.listing_status !== 'pending_review') await api(`/properties/${saved.id}/submit`, {method: 'POST'});
      const r = await api(`/properties/${saved.id}/review`, {method: 'POST', body: JSON.stringify({decision: 'approve'})});
      applyServer(r.data, 'Anúncio publicado.');
    });
  }

  if (id && !p && !notice) return <p className="muted">Carregando imóvel…</p>;

  return <div className="property-editor">
    <header>
      <div>
        <Link to="/imoveis" className="back-link">← Imóveis</Link>
        <h1>{p ? p.title : 'Novo imóvel'}</h1>
        {p && <p className="muted">{p.reference_code} · <span className={'badge listing-' + p.listing_status}>{listingLabel[p.listing_status]}</span> · <span className={'badge status-' + p.status}>{commercialLabel[p.status]}</span>{p.creator_name ? ` · cadastrado por ${p.creator_name}` : ''}</p>}
      </div>
    </header>

    {p && <StatusBanner p={p} moderator={moderator} />}
    {p?.can_review && p.listing_status === 'pending_review' && <ReviewPanel p={p} busy={busy} onReview={review} />}

    <ol className="stepper" aria-label="Etapas do cadastro">
      {STEPS.map((s, i) => {
        const done = !!p && (i === 4 ? !p.missing?.some(m => m.startsWith('Foto:')) : i === 5 ? p.listing_status !== 'draft' : false);
        return <li key={s.key}><button type="button" className={(i === step ? 'current ' : '') + (done ? 'done' : '')} disabled={!p && i > 0} onClick={() => setStep(i)} aria-current={i === step ? 'step' : undefined}>
          <i>{done && i !== step ? <Icon name="check" /> : i + 1}</i><span>{s.label}</span>
        </button></li>;
      })}
    </ol>

    {notice && <p className={notice.kind === 'error' ? 'error notice-box' : 'notice notice-box'} role={notice.kind === 'error' ? 'alert' : 'status'}>{notice.text}</p>}

    <fieldset className="card editor-step" disabled={readOnly || busy}>
      {step === 0 && <>
        <h2>Tipo e valores</h2>
        <div className="type-picker" role="radiogroup" aria-label="Tipo do imóvel">
          {types.map(t => <button type="button" key={t.value} role="radio" aria-checked={form.property_type === t.value} className={form.property_type === t.value ? 'selected' : ''} onClick={() => set('property_type', t.value)}>{t.value}</button>)}
        </div>
        <div className="segmented" role="radiogroup" aria-label="Finalidade">
          {([['purchase', 'Venda'], ['rental', 'Aluguel'], ['both', 'Venda e aluguel']] as const).map(([v, l]) => <button type="button" key={v} role="radio" aria-checked={form.purpose === v} className={form.purpose === v ? 'selected' : ''} onClick={() => set('purpose', v)}>{l}</button>)}
        </div>
        <div className="grid">
          <Field label={form.purpose === 'rental' ? 'Valor do aluguel (R$) *' : 'Valor de venda (R$) *'}><input type="number" inputMode="decimal" min="0" step="0.01" value={val(form.price)} onChange={e => set('price', e.target.value)} /></Field>
          {form.purpose === 'both' && <Field label="Valor do aluguel (R$) *"><input type="number" inputMode="decimal" min="0" step="0.01" value={val(form.rental_price)} onChange={e => set('rental_price', e.target.value)} /></Field>}
          {type?.condo && <Field label="Condomínio mensal (R$) *" hint="Use 0 se não houver."><input type="number" inputMode="decimal" min="0" step="0.01" value={val(form.condo_fee)} onChange={e => set('condo_fee', e.target.value)} /></Field>}
          <Field label="IPTU anual (R$)"><input type="number" inputMode="decimal" min="0" step="0.01" value={val(form.iptu_yearly)} onChange={e => set('iptu_yearly', e.target.value)} /></Field>
          {form.purpose !== 'rental' && <Field label="Aceita financiamento?"><YesNo value={form.accepts_financing} onChange={v => set('accepts_financing', v)} /></Field>}
          {form.purpose !== 'rental' && <Field label="Aceita permuta?"><YesNo value={form.accepts_exchange} onChange={v => set('accepts_exchange', v)} /></Field>}
          <Field label="Comissão combinada (%)"><input type="number" inputMode="decimal" min="0" max="100" step="0.1" value={val(form.commission_percent)} onChange={e => set('commission_percent', e.target.value)} /></Field>
          <Field label="Disponível a partir de"><input type="date" value={val(form.available_from)} onChange={e => set('available_from', e.target.value)} /></Field>
          {moderator && p && <Field label="Referência"><input value={val(form.reference_code)} onChange={e => set('reference_code', e.target.value)} /></Field>}
          {moderator && p && <Field label="Status comercial"><select value={form.status} onChange={e => set('status', e.target.value)}>{Object.entries(commercialLabel).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>}
        </div>
        <label className="check-line"><input type="checkbox" checked={Number(form.exclusive_listing) === 1} onChange={e => set('exclusive_listing', e.target.checked ? 1 : 0)} /> Captação com exclusividade</label>
      </>}

      {step === 1 && <AddressStep form={form} set={set} setForm={setForm} />}

      {step === 2 && <>
        <h2>Características</h2>
        <div className="grid">
          {group !== 'land' && <Field label="Área útil/construída (m²) *"><input type="number" inputMode="decimal" min="0" step="0.01" value={val(form.area_built)} onChange={e => set('area_built', e.target.value)} /></Field>}
          <Field label={group === 'land' ? 'Área total (m²) *' : 'Área total (m²)'}><input type="number" inputMode="decimal" min="0" step="0.01" value={val(form.area_total)} onChange={e => set('area_total', e.target.value)} /></Field>
          {(group === 'residential' || group === 'rural') && <>
            <Field label="Quartos *"><Counter value={form.bedrooms} onChange={v => set('bedrooms', v)} /></Field>
            <Field label="Suítes"><Counter value={form.suites} onChange={v => set('suites', v)} /></Field>
          </>}
          {group !== 'land' && <>
            <Field label="Banheiros *" hint="Inclua lavabos e banheiros das suítes."><Counter value={form.bathrooms} onChange={v => set('bathrooms', v)} /></Field>
            <Field label="Vagas de garagem *"><Counter value={form.parking_spaces} onChange={v => set('parking_spaces', v)} /></Field>
          </>}
          {type?.condo && group !== 'land' && <Field label="Andar"><input type="number" inputMode="numeric" value={val(form.floor_number)} onChange={e => set('floor_number', e.target.value)} /></Field>}
          {group !== 'land' && <Field label="Ano de construção"><input type="number" inputMode="numeric" min="1800" max="2100" value={val(form.year_built)} onChange={e => set('year_built', e.target.value)} /></Field>}
          {group !== 'land' && <Field label="Mobília"><select value={val(form.furnished)} onChange={e => set('furnished', e.target.value || null)}><option value="">Não informado</option><option value="no">Sem mobília</option><option value="semi">Semimobiliado</option><option value="yes">Mobiliado</option></select></Field>}
          {form.purpose !== 'purchase' && <Field label="Aceita pets?"><YesNo value={form.accepts_pets} onChange={v => set('accepts_pets', v)} /></Field>}
        </div>
        {group !== 'land' && <Chips title="Diferenciais do imóvel" options={FEATURES} value={form.features || []} onChange={v => set('features', v)} />}
        {type?.condo && <Chips title="Itens do condomínio" options={CONDO_FEATURES} value={form.condo_features || []} onChange={v => set('condo_features', v)} />}
      </>}

      {step === 3 && <>
        <h2>Proprietário e documentação</h2>
        <p className="muted privacy-note"><Icon name="file" /> Estes dados ficam visíveis apenas para você e para a gestão. Nunca aparecem no site.</p>
        <div className="grid">
          <Field label="Nome do proprietário *"><input autoComplete="off" value={val(form.owner_name)} onChange={e => set('owner_name', e.target.value)} /></Field>
          <Field label="CPF/CNPJ"><input inputMode="numeric" autoComplete="off" value={val(form.owner_document)} onChange={e => set('owner_document', e.target.value)} /></Field>
          <Field label="Telefone *"><input type="tel" autoComplete="off" value={val(form.owner_phone)} onChange={e => set('owner_phone', e.target.value)} /></Field>
          <Field label="E-mail"><input type="email" autoComplete="off" value={val(form.owner_email)} onChange={e => set('owner_email', e.target.value)} /></Field>
          <Field label="Matrícula do imóvel"><input value={val(form.registry_number)} onChange={e => set('registry_number', e.target.value)} /></Field>
          <Field label="Cartório de registro"><input value={val(form.registry_office)} onChange={e => set('registry_office', e.target.value)} /></Field>
          <Field label="Inscrição do IPTU"><input value={val(form.iptu_number)} onChange={e => set('iptu_number', e.target.value)} /></Field>
          <Field label="Onde estão as chaves"><input placeholder="Ex.: portaria, com o proprietário, na imobiliária" value={val(form.keys_location)} onChange={e => set('keys_location', e.target.value)} /></Field>
        </div>
      </>}

      {step === 4 && p && <PhotoStep p={p} readOnly={readOnly} onChange={applyServer} onError={t => setNotice({kind: 'error', text: t})} />}

      {step === 5 && p && <>
        <h2>Anúncio</h2>
        <Field label="Título do anúncio *">
          <div className="inline-input"><input maxLength={180} value={val(form.title)} onChange={e => set('title', e.target.value)} /><button type="button" className="secondary" onClick={() => set('title', suggestTitle(form))}>Sugerir</button></div>
        </Field>
        <Field label="Descrição *" hint={`${(form.description || '').trim().length} caracteres (mínimo 60). Destaque localização, estado de conservação e diferenciais.`}>
          <textarea rows={7} maxLength={6000} value={val(form.description)} onChange={e => set('description', e.target.value)} />
        </Field>
        <Checklist p={p} />
        <Summary p={p} />
        {!!p.events?.length && <details className="history"><summary>Histórico do anúncio</summary><ul>{p.events.map((e, i) => <li key={i}><b>{eventLabel[e.action] || e.action}</b> · {e.user_name} · {date(e.created_at)}{e.notes && <p>{e.notes}</p>}</li>)}</ul></details>}
      </>}
    </fieldset>

    <footer className="editor-actions">
      <button type="button" className="secondary" disabled={busy || step === 0} onClick={() => setStep(s => s - 1)}>Voltar</button>
      <div>
        {readOnly && step < STEPS.length - 1 && <button type="button" className="secondary" onClick={() => setStep(s => s + 1)}>Próximo</button>}
        {!readOnly && step < STEPS.length - 1 && <button type="button" disabled={busy} onClick={next}>{busy ? 'Salvando…' : step === 0 && !p ? 'Criar rascunho e continuar' : 'Salvar e continuar'}</button>}
        {!readOnly && step === STEPS.length - 1 && <>
          <button type="button" className="secondary" disabled={busy} onClick={() => save()}>Salvar</button>
          {p && ['draft', 'rejected'].includes(p.listing_status) && <button type="button" disabled={busy} onClick={submit}>Enviar para aprovação</button>}
          {moderator && p && p.listing_status !== 'approved' && <button type="button" className="publish" disabled={busy} onClick={publishNow}>Publicar agora</button>}
        </>}
        {moderator && p && p.listing_status !== 'archived' && step === STEPS.length - 1 && <button type="button" className="danger-link" disabled={busy} onClick={() => confirm('Arquivar este anúncio? Ele sai do site e da vitrine.') && review('archive')}>Arquivar</button>}
      </div>
    </footer>
  </div>;
}

function StatusBanner({p, moderator}: {p: Property; moderator: boolean}) {
  if (p.listing_status === 'rejected') return <div className="status-banner rejected" role="alert"><strong>Anúncio reprovado.</strong> Corrija e envie novamente.{p.review_notes && <p>Motivo: {p.review_notes}</p>}</div>;
  if (p.listing_status === 'pending_review') return <div className="status-banner pending"><strong>Aguardando aprovação.</strong> {moderator ? 'Confira a ficha e as fotos e decida abaixo.' : 'Enquanto estiver em análise, o cadastro fica bloqueado para edição.'}</div>;
  if (p.listing_status === 'approved' && !moderator) return <div className="status-banner approved"><strong>Publicado.</strong> Qualquer alteração sua faz o anúncio voltar para análise.</div>;
  if (p.listing_status === 'archived') return <div className="status-banner archived"><strong>Arquivado.</strong> Não aparece no site nem na vitrine.{p.review_notes && <p>{p.review_notes}</p>}</div>;
  return null;
}

function ReviewPanel({p, busy, onReview}: {p: Property; busy: boolean; onReview: (d: 'approve' | 'reject', notes?: string) => void}) {
  const [notes, setNotes] = useState('');
  return <section className="card review-panel" aria-label="Moderação do anúncio">
    <h2>Análise do anúncio</h2>
    <p className="muted">Enviado por {p.creator_name || '—'} em {date(p.submitted_at || undefined)}. {p.missing?.length ? `Ainda há ${p.missing.length} pendência(s).` : 'Checklist completo.'}</p>
    <div className="review-photos">{p.photos.map(ph => <a key={ph.id} href={ph.url} target="_blank" rel="noreferrer" title={slotLabel(p, ph)}><img src={ph.url} alt={slotLabel(p, ph)} loading="lazy" /><small>{slotLabel(p, ph)}</small></a>)}</div>
    <label>Observações para o corretor<textarea rows={3} value={notes} onChange={e => setNotes(e.target.value)} placeholder="Obrigatório para reprovar. Ex.: refazer a foto da cozinha com a luz acesa." /></label>
    <div className="review-actions">
      <button type="button" className="danger" disabled={busy || !notes.trim()} onClick={() => onReview('reject', notes)}>Reprovar e devolver</button>
      <button type="button" className="publish" disabled={busy || !!p.missing?.length} onClick={() => onReview('approve', notes || undefined)}>Aprovar e publicar</button>
    </div>
  </section>;
}

function slotLabel(p: Property, ph: Photo) {
  return p.guide?.required.find(s => s.slot === ph.slot)?.label || p.guide?.optional.find(o => o.category === ph.category)?.label || 'Outra';
}

function AddressStep({form, set, setForm}: {form: Form; set: (k: string, v: any) => void; setForm: (fn: (f: Form) => Form) => void}) {
  const [cepMsg, setCepMsg] = useState('');
  async function lookup(raw: string) {
    const cep = raw.replace(/\D/g, '');
    if (cep.length !== 8) return;
    setCepMsg('Buscando endereço…');
    try {
      const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`).then(x => x.json());
      if (r.erro) { setCepMsg('CEP não encontrado. Preencha manualmente.'); return }
      setForm(f => ({...f, street: r.logradouro || f.street, neighborhood: r.bairro || f.neighborhood, city: r.localidade || f.city, state: r.uf || f.state}));
      setCepMsg('Endereço preenchido pelo CEP. Confira o número e o complemento.');
    } catch { setCepMsg('Não foi possível consultar o CEP agora. Preencha manualmente.') }
  }
  return <>
    <h2>Endereço</h2>
    <div className="grid">
      <Field label="CEP *" hint={cepMsg}><input inputMode="numeric" autoComplete="postal-code" maxLength={9} value={val(form.zip_code)} onChange={e => set('zip_code', e.target.value)} onBlur={e => lookup(e.target.value)} /></Field>
      <Field label="Rua *"><input autoComplete="address-line1" value={val(form.street)} onChange={e => set('street', e.target.value)} /></Field>
      <Field label="Número *"><input value={val(form.street_number)} onChange={e => set('street_number', e.target.value)} /></Field>
      <Field label="Complemento"><input placeholder="Apto, bloco, casa…" value={val(form.complement)} onChange={e => set('complement', e.target.value)} /></Field>
      <Field label="Bairro *"><input value={val(form.neighborhood)} onChange={e => set('neighborhood', e.target.value)} /></Field>
      <Field label="Cidade *"><input value={val(form.city)} onChange={e => set('city', e.target.value)} /></Field>
      <Field label="UF *"><input maxLength={2} value={val(form.state)} onChange={e => set('state', e.target.value.toUpperCase())} /></Field>
      <Field label="Nome do condomínio/edifício"><input value={val(form.condo_name)} onChange={e => set('condo_name', e.target.value)} /></Field>
    </div>
    <label className="check-line"><input type="checkbox" checked={Number(form.show_full_address) === 1} onChange={e => set('show_full_address', e.target.checked ? 1 : 0)} /> Mostrar endereço completo no site (se desmarcado, aparece só bairro e cidade)</label>
  </>;
}

function PhotoStep({p, readOnly, onChange, onError}: {p: Property; readOnly: boolean; onChange: (d: Property) => void; onError: (t: string) => void}) {
  const guide = p.guide!;
  const [uploading, setUploading] = useState<Record<string, number>>({});
  const [optional, setOptional] = useState(guide.optional[0]?.category || 'other');
  const requiredSlots = new Set(guide.required.map(s => s.slot));
  const done = guide.required.filter(s => p.photos.some(ph => ph.slot === s.slot)).length;
  const extras = p.photos.filter(ph => !requiredSlots.has(ph.slot));

  async function upload(slot: string, files: FileList | null) {
    if (!files?.length) return;
    for (const file of Array.from(files)) {
      setUploading(u => ({...u, [slot]: (u[slot] || 0) + 1}));
      try {
        const {blob, width, height} = await compressPhoto(file);
        if (Math.max(width, height) < 800 || Math.min(width, height) < 600) throw new Error(`"${file.name}" é pequena demais (${width}×${height}). Use ao menos 800×600.`);
        const fd = new FormData();
        fd.append('file', blob, file.name.replace(/\.\w+$/, '') + '.jpg');
        fd.append('slot', slot);
        const r = await api(`/properties/${p.id}/photos`, {method: 'POST', body: fd});
        onChange(r.data);
      } catch (e: any) { onError(e.message) } finally { setUploading(u => ({...u, [slot]: (u[slot] || 1) - 1})) }
    }
  }
  async function remove(ph: Photo) {
    if (!confirm('Remover esta foto?')) return;
    try { onChange((await api(`/properties/${p.id}/photos/${ph.id}`, {method: 'DELETE'})).data) } catch (e: any) { onError(e.message) }
  }
  async function cover(ph: Photo) {
    try { onChange((await api(`/properties/${p.id}/photos/${ph.id}/cover`, {method: 'POST'})).data) } catch (e: any) { onError(e.message) }
  }

  return <>
    <h2>Fotos</h2>
    <div className="photo-progress" aria-live="polite">
      <div><strong>{done} de {guide.required.length}</strong> fotos obrigatórias</div>
      <progress max={guide.required.length} value={done} />
    </div>
    <details className="photo-tips"><summary>Dicas para boas fotos</summary><ul>
      <li>Fotografe de dia, com cortinas abertas e luzes acesas.</li>
      <li>Segure o celular na horizontal, na altura do peito, do canto do cômodo.</li>
      <li>Arrume o ambiente: camas feitas, bancadas livres, tampas de vaso abaixadas.</li>
      <li>Não fotografe pessoas, documentos, placas de carro nem objetos pessoais.</li>
    </ul></details>

    <h3>Obrigatórias</h3>
    <div className="slot-grid">
      {guide.required.map(s => <SlotCard key={s.slot} slot={s} photos={p.photos.filter(ph => ph.slot === s.slot)} busy={!!uploading[s.slot]} readOnly={readOnly} onFiles={f => upload(s.slot, f)} onRemove={remove} onCover={cover} />)}
    </div>

    <h3>Fotos adicionais</h3>
    <p className="muted">Valorize o anúncio: sacadas, varanda gourmet, vista, áreas do condomínio e outros diferenciais.</p>
    {!readOnly && <div className="optional-add">
      <select value={optional} onChange={e => setOptional(e.target.value)} aria-label="Ambiente da foto adicional">{guide.optional.map(o => <option key={o.category} value={o.category}>{o.label}</option>)}</select>
      <PhotoButtons busy={!!uploading[optional]} onFiles={f => upload(optional, f)} />
    </div>}
    <div className="photo-grid">
      {extras.map(ph => <PhotoThumb key={ph.id} ph={ph} label={slotLabel(p, ph)} readOnly={readOnly} onRemove={remove} onCover={cover} />)}
      {!extras.length && <p className="muted">Nenhuma foto adicional.</p>}
    </div>
  </>;
}

function SlotCard({slot, photos, busy, readOnly, onFiles, onRemove, onCover}: {slot: GuideSlot; photos: Photo[]; busy: boolean; readOnly: boolean; onFiles: (f: FileList | null) => void; onRemove: (p: Photo) => void; onCover: (p: Photo) => void}) {
  return <article className={'slot-card ' + (photos.length ? 'filled' : '')}>
    <header><strong>{photos.length ? <Icon name="check" /> : null}{slot.label}</strong><small>{slot.hint}</small></header>
    {photos.map(ph => <PhotoThumb key={ph.id} ph={ph} readOnly={readOnly} onRemove={onRemove} onCover={onCover} />)}
    {!readOnly && <PhotoButtons busy={busy} compact={photos.length > 0} onFiles={onFiles} />}
  </article>;
}

function PhotoThumb({ph, label, readOnly, onRemove, onCover}: {ph: Photo; label?: string; readOnly: boolean; onRemove: (p: Photo) => void; onCover: (p: Photo) => void}) {
  return <figure className="photo-thumb">
    <img src={ph.url} alt={label || ''} loading="lazy" />
    {ph.is_cover && <span className="badge status-available">Capa</span>}
    {label && <figcaption>{label}</figcaption>}
    {!readOnly && <div className="thumb-actions">
      {!ph.is_cover && <button type="button" onClick={() => onCover(ph)}>Usar como capa</button>}
      <button type="button" className="thumb-remove" onClick={() => onRemove(ph)} aria-label="Remover foto"><Icon name="close" /></button>
    </div>}
  </figure>;
}

function PhotoButtons({busy, compact, onFiles}: {busy: boolean; compact?: boolean; onFiles: (f: FileList | null) => void}) {
  const camera = useRef<HTMLInputElement>(null), gallery = useRef<HTMLInputElement>(null);
  if (busy) return <div className="photo-buttons"><span className="uploading">Enviando…</span></div>;
  return <div className={'photo-buttons ' + (compact ? 'compact' : '')}>
    <button type="button" onClick={() => camera.current?.click()}>{compact ? '+ Câmera' : 'Tirar foto'}</button>
    <button type="button" className="secondary" onClick={() => gallery.current?.click()}>{compact ? '+ Galeria' : 'Galeria'}</button>
    <input ref={camera} type="file" accept="image/*" capture="environment" hidden onChange={e => { onFiles(e.target.files); e.target.value = '' }} />
    <input ref={gallery} type="file" accept="image/jpeg,image/png,image/webp" multiple hidden onChange={e => { onFiles(e.target.files); e.target.value = '' }} />
  </div>;
}

function Checklist({p}: {p: Property}) {
  if (!p.missing?.length) return <div className="checklist ok"><Icon name="check" /> Tudo pronto para envio.</div>;
  return <div className="checklist"><strong>Pendências para enviar ({p.missing.length})</strong><ul>{p.missing.map(m => <li key={m}>{m}</li>)}</ul><small>Salve para atualizar a lista.</small></div>;
}

function Summary({p}: {p: Property}) {
  const rows: [string, ReactNode][] = [
    ['Tipo', `${p.property_type} · ${p.purpose === 'rental' ? 'Aluguel' : p.purpose === 'both' ? 'Venda e aluguel' : 'Venda'}`],
    ['Valor', p.purpose === 'both' ? `${money(p.price)} · aluguel ${money(p.rental_price)}` : money(p.price)],
    ['Endereço', [p.street, p.street_number, p.complement, p.neighborhood, p.city && `${p.city}/${p.state}`].filter(Boolean).join(', ') || '—'],
    ['Área', p.area_built ? `${Number(p.area_built)} m² úteis` : p.area_total ? `${Number(p.area_total)} m²` : '—'],
    ['Cômodos', [p.bedrooms != null && `${p.bedrooms} quarto(s)`, p.suites ? `${p.suites} suíte(s)` : '', p.bathrooms != null && `${p.bathrooms} banheiro(s)`, p.parking_spaces != null && `${p.parking_spaces} vaga(s)`].filter(Boolean).join(' · ') || '—'],
    ['Fotos', `${p.photo_count}`],
  ];
  return <dl className="modal-meta summary-list">{rows.map(([k, v]) => <Fragment key={k}><dt>{k}</dt><dd>{v}</dd></Fragment>)}</dl>;
}

function Field({label, hint, children}: {label: string; hint?: string; children: ReactNode}) {
  return <label>{label}{children}{hint && <small className="field-hint">{hint}</small>}</label>;
}

function YesNo({value, onChange}: {value: any; onChange: (v: number | null) => void}) {
  return <select value={value === null || value === undefined || value === '' ? '' : String(Number(value))} onChange={e => onChange(e.target.value === '' ? null : Number(e.target.value))}>
    <option value="">Não informado</option><option value="1">Sim</option><option value="0">Não</option>
  </select>;
}

function Counter({value, onChange}: {value: any; onChange: (v: number | null) => void}) {
  const n = value === null || value === undefined || value === '' ? null : Number(value);
  return <div className="counter">
    <button type="button" aria-label="Diminuir" onClick={() => onChange(Math.max(0, (n ?? 0) - 1))}>−</button>
    <input type="number" inputMode="numeric" min="0" max="99" value={n ?? ''} onChange={e => onChange(e.target.value === '' ? null : Number(e.target.value))} />
    <button type="button" aria-label="Aumentar" onClick={() => onChange((n ?? 0) + 1)}>+</button>
  </div>;
}

function Chips({title, options, value, onChange}: {title: string; options: string[]; value: string[]; onChange: (v: string[]) => void}) {
  const [custom, setCustom] = useState('');
  const all = useMemo(() => [...options, ...value.filter(v => !options.includes(v))], [options, value]);
  const toggle = (o: string) => onChange(value.includes(o) ? value.filter(x => x !== o) : [...value, o]);
  return <div className="chips-field">
    <h3>{title}</h3>
    <div className="chips">{all.map(o => <button type="button" key={o} className={value.includes(o) ? 'chip on' : 'chip'} aria-pressed={value.includes(o)} onClick={() => toggle(o)}>{o}</button>)}</div>
    <div className="inline-input"><input placeholder="Outro item" value={custom} onChange={e => setCustom(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); if (custom.trim()) { onChange([...value, custom.trim()]); setCustom('') } } }} /><button type="button" className="secondary" onClick={() => { if (custom.trim()) { onChange([...value, custom.trim()]); setCustom('') } }}>Adicionar</button></div>
  </div>;
}
