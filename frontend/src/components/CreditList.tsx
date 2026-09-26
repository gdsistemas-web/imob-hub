import {useEffect, useState} from 'react';
import {Link, useNavigate, useSearchParams} from 'react-router-dom';
import {api, date, money} from '../lib/api';
import {creditStatus, methodLabel, type CreditRow} from '../lib/credit';
import type {AppUser} from './AppLayout';

type Tab = {key: string; label: string; count?: (c: Record<string, number>) => number};

function tabsFor(role: string): Tab[] {
  if (role === 'broker') return [
    {key: '', label: 'Todas'},
    {key: 'draft', label: 'Rascunhos', count: c => c.draft},
    {key: 'open', label: 'Em andamento', count: c => c.submitted + c.in_analysis},
    {key: 'pending', label: 'Pendências', count: c => c.pending_docs},
    {key: 'decided', label: 'Respondidas', count: c => c.approved + c.approved_conditions + c.rejected},
  ];
  return [
    {key: 'new', label: 'Novas', count: c => c.submitted},
    {key: 'mine', label: 'Comigo', count: c => c.mine},
    {key: 'pending', label: 'Aguardando corretor', count: c => c.pending_docs},
    {key: 'decided', label: 'Decididas', count: c => c.approved + c.approved_conditions + c.rejected},
    {key: 'cancelled', label: 'Canceladas'},
  ];
}

export function CreditList({user}: {user: AppUser}) {
  const tabs = tabsFor(user.role);
  const [params, setParams] = useSearchParams();
  const tab = tabs.find(t => t.key === (params.get('aba') ?? tabs[0].key)) || tabs[0];
  const [rows, setRows] = useState<CreditRow[]>([]);
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [q, setQ] = useState('');
  const [loading, setLoading] = useState(true);
  const navigate = useNavigate();
  const broker = user.role === 'broker';
  const base = broker ? '/fichas' : '/analises';

  useEffect(() => {
    setLoading(true);
    const t = setTimeout(() => api(`/credit?queue=${tab.key}${q ? '&q=' + encodeURIComponent(q) : ''}`).then(r => { setRows(r.data); setCounts(r.counts) }).finally(() => setLoading(false)), q ? 250 : 0);
    return () => clearTimeout(t);
  }, [tab.key, q]);

  return <>
    <header>
      <div><h1>{broker ? 'Fichas de interesse' : 'Análises de crédito'}</h1><p className="muted">{broker ? 'Propostas dos seus clientes enviadas para análise de crédito.' : 'Fichas enviadas pelos corretores. Assuma uma ficha para analisar.'}</p></div>
      {user.role !== 'analyst' && <button onClick={() => navigate('/fichas/nova')}>Nova ficha</button>}
    </header>
    <div className="listing-tabs" role="tablist">
      {tabs.map(t => <button key={t.key} role="tab" aria-selected={t === tab} className={(t === tab ? 'active ' : '') + (t.key === 'new' && counts.submitted ? 'attention' : '') + (t.key === 'pending' && broker && counts.pending_docs ? 'attention' : '')} onClick={() => setParams(t.key ? {aba: t.key} : {aba: ''})}>
        {t.label}{t.count && Object.keys(counts).length ? <b>{t.count(counts)}</b> : null}
      </button>)}
    </div>
    <div className="filters"><input placeholder="Buscar por cliente, código da ficha ou do imóvel" value={q} onChange={e => setQ(e.target.value)} /></div>
    <div className="credit-list" aria-busy={loading}>
      {rows.map(r => <Link key={r.id} to={`${base}/${r.id}`} className="credit-row card">
        <div className="credit-row-main">
          <strong>{r.applicant_name || 'Cliente sem nome'}</strong>
          <small>{r.code} · {methodLabel[r.payment_method]} · {r.property_reference} – {r.property_title}</small>
          <small>{broker ? (r.analyst_name ? `Analista: ${r.analyst_name}` : r.submitted_at ? 'Na fila de análise' : `Atualizada ${date(r.updated_at)}`) : `Corretor: ${r.broker_name}${r.analyst_name ? ' · Analista: ' + r.analyst_name : ''}`}{r.submitted_at ? ` · enviada ${date(r.submitted_at)}` : ''}</small>
        </div>
        <div className="credit-row-side">
          <span className={'badge credit-' + r.status}>{creditStatus[r.status]}</span>
          <b>{money(r.approved_value || (r.business_type === 'rental' ? r.rent_value : r.financing_value || r.offer_value))}{r.business_type === 'rental' ? <small>/mês</small> : null}</b>
          {r.household_income && <small>Renda {money(r.household_income)}</small>}
        </div>
      </Link>)}
      {!loading && !rows.length && <div className="card empty">{tab.key === 'new' ? 'Nenhuma ficha aguardando análise.' : broker && !tab.key ? 'Você ainda não abriu fichas. Toque em “Nova ficha” quando um cliente quiser fazer uma proposta.' : 'Nada por aqui.'}</div>}
    </div>
  </>;
}
