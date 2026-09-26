import {useEffect, useState} from 'react';
import {Link, useNavigate, useParams} from 'react-router-dom';
import {api, date, money} from '../lib/api';
import type {AppUser} from './AppLayout';
import {Icon} from './Icon';

type Signer = {role: string; name: string; email: string; doc?: string; status?: string; signed_at?: string; reason?: string};
type Contract = {
  id: string; code: string; status: 'draft' | 'sent' | 'signed' | 'declined' | 'cancelled'; template_key: string; template_name: string; total_value: string | null;
  terms: Record<string, string>; signers: Signer[]; signer_roles: Record<string, string>; events: {action: string; notes: string | null; created_at: string; user_name: string | null}[];
  credit: {id: string; code: string; payment_method: string; approved_value: string | null; approved_conditions: string | null};
  signature_provider: string | null; provider_envelope_id: string | null; sent_at: string | null; signed_at: string | null; cancel_reason: string | null;
  can_edit: boolean; documenso: boolean; has_signed_pdf: boolean; pdf_sha256: string | null; signed_sha256: string | null;
};
export const contractStatus: Record<string, string> = {draft: 'Rascunho', sent: 'Aguardando assinaturas', signed: 'Assinado', declined: 'Recusado', cancelled: 'Cancelado'};
const eventLabel: Record<string, string> = {created: 'Contrato gerado', edited: 'Condições alteradas', sent: 'Enviado para assinatura', signed: 'Assinado', declined: 'Recusado por uma das partes', cancelled: 'Cancelado', closing_registered: 'Fechamento registrado', closing_failed: 'Fechamento não registrado'};
const val = (v: any) => v === null || v === undefined ? '' : String(v);

async function openPdf(id: string, signed = false, download = false) {
  const r = await fetch(`/api/contracts/${id}/pdf?inline=1${signed ? '&signed=1' : ''}`, {headers: {Authorization: `Bearer ${localStorage.getItem('token')}`}});
  if (!r.ok) throw new Error((await r.json()).message);
  const url = URL.createObjectURL(await r.blob());
  if (download) { const a = document.createElement('a'); a.href = url; a.download = `contrato${signed ? '-assinado' : ''}.pdf`; a.click() } else window.open(url, '_blank', 'noopener');
}

export function ContractList({user}: {user: AppUser}) {
  const [rows, setRows] = useState<any[]>([]);
  const [status, setStatus] = useState('');
  useEffect(() => { api(`/contracts${status ? '?status=' + status : ''}`).then(r => setRows(r.data)) }, [status]);
  return <>
    <header><div><h1>Contratos</h1><p className="muted">Gerados a partir das fichas aprovadas. {user.role === 'broker' ? 'Abra uma ficha aprovada para emitir um novo.' : ''}</p></div></header>
    <div className="listing-tabs" role="tablist">{[['', 'Todos'], ['draft', 'Rascunhos'], ['sent', 'Aguardando assinaturas'], ['signed', 'Assinados'], ['cancelled', 'Cancelados']].map(([k, l]) => <button key={k} role="tab" aria-selected={status === k} className={status === k ? 'active' : ''} onClick={() => setStatus(k)}>{l}</button>)}</div>
    <div className="credit-list">
      {rows.map(r => <Link key={r.id} to={'/contratos/' + r.id} className="credit-row card">
        <div className="credit-row-main"><strong>{r.applicant_name}</strong><small>{r.code} · {r.property_reference} – {r.property_title}</small><small>Ficha {r.credit_code} · {user.role !== 'broker' ? `Corretor: ${r.broker_name} · ` : ''}atualizado {date(r.updated_at)}</small></div>
        <div className="credit-row-side"><span className={'badge contract-' + r.status}>{contractStatus[r.status]}</span><b>{money(r.total_value)}{r.template_key === 'lease' ? <small>/mês</small> : null}</b>{r.status === 'sent' && <small>{r.signers_signed} de {r.signers_total} assinaram</small>}</div>
      </Link>)}
      {!rows.length && <div className="card empty">Nenhum contrato por aqui.</div>}
    </div>
  </>;
}

/** Botão usado na ficha aprovada: cria o contrato e abre a tela dele. */
export function IssueContractButton({creditId}: {creditId: string}) {
  const navigate = useNavigate(); const [busy, setBusy] = useState(false); const [error, setError] = useState('');
  return <>{error && <p className="error">{error}</p>}<button disabled={busy} onClick={async () => { setBusy(true); setError(''); try { const r = await api('/contracts', {method: 'POST', body: JSON.stringify({credit_application_id: creditId})}); navigate('/contratos/' + r.data.id) } catch (e: any) { setError(e.message); setBusy(false) } }}>{busy ? 'Gerando contrato…' : 'Emitir contrato'}</button></>;
}

export function ContractPage({user}: {user: AppUser}) {
  const {id} = useParams();
  const [c, setC] = useState<Contract | null>(null);
  const [terms, setTerms] = useState<Record<string, string>>({});
  const [signers, setSigners] = useState<Signer[]>([]);
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ok: boolean; text: string} | null>(null);

  const apply = (data: Contract, text?: string) => { setC(data); setTerms(data.terms); setSigners(data.signers); if (text) setNotice({ok: true, text}) };
  const loadPreview = async (signed = false) => {
    try {
      const r = await fetch(`/api/contracts/${id}/pdf?inline=1${signed ? '&signed=1' : ''}`, {headers: {Authorization: `Bearer ${localStorage.getItem('token')}`}});
      if (r.ok) { const blob = await r.blob(); setPreview(old => { if (old) URL.revokeObjectURL(old); return URL.createObjectURL(blob) }) }
    } catch { /* prévia é opcional */ }
  };
  useEffect(() => { api(`/contracts/${id}`).then(r => { apply(r.data); void loadPreview(r.data.has_signed_pdf) }).catch(e => setNotice({ok: false, text: e.message})) }, [id]);
  useEffect(() => { // enquanto aguarda assinaturas, confere o andamento a cada 30 s
    if (c?.status !== 'sent') return;
    const t = setInterval(() => api(`/contracts/${id}/refresh`, {method: 'POST'}).then(r => { if (r.data.status !== c.status) { apply(r.data); void loadPreview(r.data.has_signed_pdf) } else setC(r.data) }).catch(() => {}), 30000);
    return () => clearInterval(t);
  }, [c?.status]);

  async function run(fn: () => Promise<any>, text: string, refreshPdf = false) {
    setBusy(true); setNotice(null);
    try { const r = await fn(); apply(r.data, text); if (refreshPdf) await loadPreview(r.data.has_signed_pdf); return true }
    catch (e: any) { setNotice({ok: false, text: e.message}); return false } finally { setBusy(false) }
  }
  const save = () => run(() => api(`/contracts/${id}`, {method: 'PUT', body: JSON.stringify({terms, signers})}), 'Contrato atualizado e PDF regenerado.', true);

  if (!c) return notice ? <p className="error">{notice.text}</p> : <p className="muted">Carregando contrato…</p>;
  const lease = c.template_key === 'lease';
  const set = (k: string, v: string) => setTerms(t => ({...t, [k]: v}));
  const F = (k: string, label: string, type = 'text', hint?: string) => <label key={k}>{label}<input type={type} inputMode={type === 'number' ? 'decimal' : undefined} step={type === 'number' ? '0.01' : undefined} value={val(terms[k])} onChange={e => set(k, e.target.value)} />{hint && <small className="field-hint">{hint}</small>}</label>;
  const T = (k: string, label: string, rows = 3) => <label key={k} className="wide">{label}<textarea rows={rows} value={val(terms[k])} onChange={e => set(k, e.target.value)} /></label>;

  return <div className="property-editor contract-page">
    <header><div>
      <Link to="/contratos" className="back-link">← Contratos</Link>
      <h1>Contrato {c.code}</h1>
      <p className="muted">{c.template_name} · <span className={'badge contract-' + c.status}>{contractStatus[c.status]}</span> · ficha <Link to={'/fichas/' + c.credit.id}>{c.credit.code}</Link> · {money(c.total_value)}{lease ? '/mês' : ''}</p>
    </div></header>

    <StatusBanner c={c} />
    {notice && <p className={(notice.ok ? 'notice' : 'error') + ' notice-box'} role={notice.ok ? 'status' : 'alert'}>{notice.text}</p>}

    <div className="contract-layout">
      <div className="contract-side">
        <section className="card">
          <h2>Signatários</h2>
          {c.can_edit ? <>
            {signers.map((s, i) => <div key={i} className="signer-row">
              <select value={s.role} onChange={e => setSigners(list => list.map((x, j) => j === i ? {...x, role: e.target.value} : x))} aria-label="Papel">{Object.entries(c.signer_roles).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
              <input value={s.name} placeholder="Nome completo" aria-label="Nome" onChange={e => setSigners(list => list.map((x, j) => j === i ? {...x, name: e.target.value} : x))} />
              <input value={s.email} type="email" placeholder="E-mail para assinatura" aria-label="E-mail" onChange={e => setSigners(list => list.map((x, j) => j === i ? {...x, email: e.target.value} : x))} />
              <input value={s.doc || ''} placeholder="CPF/CNPJ" aria-label="Documento" onChange={e => setSigners(list => list.map((x, j) => j === i ? {...x, doc: e.target.value} : x))} />
              <button type="button" className="thumb-remove-inline" aria-label="Remover signatário" onClick={() => setSigners(list => list.filter((_, j) => j !== i))}><Icon name="close" /></button>
            </div>)}
            <button type="button" className="secondary small-button" onClick={() => setSigners(list => [...list, {role: 'witness', name: '', email: '', doc: ''}])}>+ Adicionar signatário (ex.: testemunha, cônjuge do vendedor)</button>
          </> : <ul className="signer-status">{c.signers.map((s, i) => <li key={i} className={s.status || 'pending'}>
            {s.status === 'signed' ? <Icon name="check" /> : <i />}
            <div><strong>{s.name}</strong><small>{c.signer_roles[s.role] || s.role} · {s.email}</small></div>
            <span>{s.status === 'signed' ? `Assinou${s.signed_at ? ' ' + date(s.signed_at.replace('T', ' ').slice(0, 19)) : ''}` : s.status === 'rejected' ? 'Recusou' : c.status === 'sent' ? 'Pendente' : ''}</span>
          </li>)}</ul>}
        </section>

        {c.can_edit && <section className="card terms">
          <h2>Condições do contrato</h2>
          <p className="muted">Pré-preenchidas com a ficha aprovada, o imóvel e os padrões da imobiliária. Ajuste e salve para regenerar o PDF.</p>
          <div className="grid">
            {T('comprador_qualificacao', lease ? 'Locatário (qualificação)' : 'Comprador(es) (qualificação)')}
            {T('vendedor_qualificacao', lease ? 'Locador (qualificação)' : 'Vendedor (qualificação)', 2)}
            {lease && terms.garantia === 'guarantor' && T('fiador_qualificacao', 'Fiador (qualificação)', 2)}
            {T('imovel_descricao', 'Descrição do imóvel', 2)}
            {T('imovel_endereco', 'Endereço do imóvel', 2)}
            {!lease && F('imovel_matricula', 'Matrícula')}
            {!lease && F('imovel_cartorio', 'Cartório de registro')}
            {!lease && F('imovel_iptu_inscricao', 'Inscrição do IPTU')}
            {lease ? <>
              {F('aluguel', 'Aluguel mensal (R$)', 'number')}{F('prazo_meses', 'Prazo (meses)', 'number')}{F('inicio', 'Início', 'date')}{F('vencimento_dia', 'Dia do vencimento', 'number')}{F('indice', 'Índice de reajuste')}
              <label>Garantia<select value={val(terms.garantia)} onChange={e => set('garantia', e.target.value)}>{[['deposit', 'Caução'], ['guarantor', 'Fiador'], ['insurance', 'Seguro-fiança'], ['capitalization', 'Título de capitalização'], ['none', 'Sem garantia']].map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>
              {['deposit', 'capitalization'].includes(terms.garantia) && F('garantia_valor', 'Valor da garantia (R$)', 'number', 'Caução: no máximo 3 aluguéis.')}
              {F('multa_alugueis', 'Multa rescisória (aluguéis)', 'number')}
              <label>Finalidade<select value={val(terms.finalidade)} onChange={e => set('finalidade', e.target.value)}><option value="residencial">Residencial</option><option value="não residencial">Não residencial</option></select></label>
              {F('imovel_condominio', 'Condomínio atual (R$)', 'number')}
            </> : <>
              {F('preco', 'Preço (R$)', 'number')}{F('sinal', 'Sinal / arras (R$)', 'number')}{F('sinal_data', 'Data do sinal', 'date')}
              {c.template_key === 'sale_down_payment' && F('entrada', 'Entrada (R$)', 'number')}
              {c.template_key !== 'sale_cash' && <>{F('fgts', 'FGTS (R$)', 'number')}{F('financiado', 'Valor financiado (R$)', 'number')}{F('banco', 'Banco')}{F('prazo_financiamento', 'Prazo do financiamento (meses)', 'number')}</>}
              {c.template_key === 'sale_cash' && F('saldo_data', 'Data do pagamento do saldo', 'date')}
              {F('prazo_escritura', 'Prazo para escritura/contrato definitivo (dias)', 'number')}
              {T('posse', 'Entrega da posse', 2)}
              {F('multa', 'Multa por descumprimento (%)', 'number')}{F('comissao_percentual', 'Comissão (%)', 'number')}
              <label>Comissão paga pelo<select value={val(terms.comissao_responsavel)} onChange={e => set('comissao_responsavel', e.target.value)}><option value="VENDEDOR">Vendedor</option><option value="COMPRADOR">Comprador</option></select></label>
            </>}
            {F('data', 'Data do contrato', 'date')}{F('cidade', 'Cidade de assinatura')}{F('foro', 'Foro (comarca)')}
            {T('condicoes_especiais', 'Condições especiais', 3)}
          </div>
        </section>}

        <section className="card"><details className="history" open={!c.can_edit}><summary>Histórico</summary><ul>{c.events.map((e, i) => <li key={i}><b>{eventLabel[e.action] || e.action}</b>{e.user_name ? ` · ${e.user_name}` : ''} · {date(e.created_at)}{e.notes && <p>{e.notes}</p>}</li>)}</ul></details>
          {c.pdf_sha256 && <p className="muted small hash">SHA-256 do PDF gerado: {c.pdf_sha256.slice(0, 16)}…{c.signed_sha256 && <><br />SHA-256 do PDF assinado: {c.signed_sha256.slice(0, 16)}…</>}</p>}
        </section>
      </div>

      <section className="card pdf-preview">
        <div className="pdf-head"><h2>{c.has_signed_pdf ? 'PDF assinado' : 'Prévia do contrato'}</h2>
          <div><button type="button" className="secondary" onClick={() => openPdf(c.id, c.has_signed_pdf).catch(e => setNotice({ok: false, text: e.message}))}>Abrir</button><button type="button" className="secondary" onClick={() => openPdf(c.id, c.has_signed_pdf, true).catch(e => setNotice({ok: false, text: e.message}))}>Baixar</button></div>
        </div>
        {preview ? <iframe src={preview} title="Prévia do contrato em PDF" /> : <p className="muted">Gerando prévia…</p>}
      </section>
    </div>

    <footer className="editor-actions">
      <div>{['draft', 'sent'].includes(c.status) && <button type="button" className="danger-link" disabled={busy} onClick={() => { const r = prompt('Motivo do cancelamento do contrato:'); if (r?.trim()) void run(() => api(`/contracts/${id}/cancel`, {method: 'POST', body: JSON.stringify({reason: r.trim()})}), 'Contrato cancelado.') }}>Cancelar contrato</button>}</div>
      <div>
        {c.can_edit && <button type="button" className="secondary" disabled={busy} onClick={save}>{busy ? 'Salvando…' : 'Salvar e regenerar PDF'}</button>}
        {c.status === 'sent' && c.signature_provider === 'documenso' && <button type="button" className="secondary" disabled={busy} onClick={() => run(() => api(`/contracts/${id}/refresh`, {method: 'POST'}), 'Status atualizado.', true)}>Atualizar status</button>}
        {['draft', 'sent'].includes(c.status) && <label className={'button secondary upload-inline' + (busy ? ' disabled' : '')} title="Use quando as assinaturas forem colhidas fora do sistema">Anexar PDF assinado<input type="file" accept="application/pdf" hidden onChange={e => { const f = e.target.files?.[0]; e.target.value = ''; if (!f || !confirm('Confirmar que este PDF está assinado por todas as partes? O negócio será registrado como fechado.')) return; const fd = new FormData(); fd.append('file', f); void run(() => api(`/contracts/${id}/signed`, {method: 'POST', body: fd}), 'PDF assinado anexado. Negócio registrado como fechado.', true) }} /></label>}
        {c.status === 'draft' && <button type="button" disabled={busy || !c.documenso} title={c.documenso ? '' : 'Configure o Documenso para enviar por e-mail'} onClick={async () => { if (await save() && confirm(`Enviar o contrato para assinatura eletrônica de ${signers.length} parte(s)? Depois do envio ele não pode mais ser editado.`)) await run(() => api(`/contracts/${id}/send`, {method: 'POST'}), 'Contrato enviado. Cada parte recebeu um e-mail com o link de assinatura.') }}>Enviar para assinatura</button>}
      </div>
    </footer>
  </div>;
}

function StatusBanner({c}: {c: Contract}) {
  if (c.status === 'signed') return <div className="status-banner approved"><strong>Contrato assinado por todas as partes{c.signed_at ? ` em ${date(c.signed_at)}` : ''}.</strong> O negócio foi registrado como fechado e o imóvel saiu da vitrine.</div>;
  if (c.status === 'sent') return <div className="status-banner pending"><strong>Aguardando assinaturas.</strong> {c.signers.filter(s => s.status === 'signed').length} de {c.signers.length} já assinaram. Cada parte recebeu o link por e-mail.</div>;
  if (c.status === 'declined') return <div className="status-banner rejected"><strong>Uma das partes recusou a assinatura.</strong> {c.signers.find(s => s.status === 'rejected')?.reason || ''} Ajuste as condições em um novo contrato.</div>;
  if (c.status === 'cancelled') return <div className="status-banner archived"><strong>Contrato cancelado.</strong> {c.cancel_reason}</div>;
  if (!c.documenso) return <div className="status-banner archived"><strong>Assinatura eletrônica não configurada.</strong> Revise o PDF, colha as assinaturas fora do sistema e anexe o PDF assinado, ou peça ao administrador para ligar o Documenso.</div>;
  return <div className="status-banner pending"><strong>Rascunho.</strong> Revise as condições, confira os e-mails dos signatários e envie para assinatura.</div>;
}
