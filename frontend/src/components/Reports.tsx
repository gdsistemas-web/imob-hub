import {useEffect, useState} from 'react';
import {api, money, stages} from '../lib/api';
import {Icon} from './Icon';

const funnelStages: [string, string][] = [['qualification', 'Qualificação'], ['service', 'Atendimento'], ['visit', 'Visitas'], ['proposal', 'Propostas'], ['negotiation', 'Negociações'], ['won', 'Vendas']];
const COLOR_LEADS = '#2a78d6';
const COLOR_WON = '#eb6834';

function TrendChart({daily}: {daily: {day: string; leads: number; won: number}[]}) {
  if (!daily.length) return <p className="empty-inline">Sem dados no período selecionado.</p>;
  const width = 100, height = 40, pad = 3;
  const max = Math.max(1, ...daily.map(d => Number(d.leads)));
  const x = (i: number) => pad + (i / Math.max(1, daily.length - 1)) * (width - pad * 2);
  const y = (v: number) => height - pad - (v / max) * (height - pad * 2);
  const line = (key: 'leads' | 'won') => daily.map((d, i) => `${x(i)},${y(Number(d[key]))}`).join(' ');
  return <div className="trend-chart">
    <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" aria-hidden="true">
      <line x1={pad} y1={height - pad} x2={width - pad} y2={height - pad} stroke="#e1e0d9" strokeWidth="0.3" />
      <polyline points={line('leads')} fill="none" stroke={COLOR_LEADS} strokeWidth="0.8" vectorEffect="non-scaling-stroke" />
      <polyline points={line('won')} fill="none" stroke={COLOR_WON} strokeWidth="0.8" vectorEffect="non-scaling-stroke" />
      {daily.map((d, i) => <circle key={'l' + i} cx={x(i)} cy={y(Number(d.leads))} r="0.7" fill={COLOR_LEADS}><title>{d.day}: {d.leads} lead(s)</title></circle>)}
      {daily.map((d, i) => <circle key={'w' + i} cx={x(i)} cy={y(Number(d.won))} r="0.7" fill={COLOR_WON}><title>{d.day}: {d.won} venda(s)</title></circle>)}
    </svg>
    <div className="trend-legend"><span><i style={{background: COLOR_LEADS}} />Leads recebidos</span><span><i style={{background: COLOR_WON}} />Vendas (ganho)</span></div>
  </div>;
}

export function Reports() {
  const [d, setD] = useState<any>();
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [sourceId, setSourceId] = useState('');
  const [sdrId, setSdrId] = useState('');
  const [brokerId, setBrokerId] = useState('');
  const [businessType, setBusinessType] = useState('');
  const [sources, setSources] = useState<any[]>([]);
  const [sdrs, setSdrs] = useState<any[]>([]);
  const [brokers, setBrokers] = useState<any[]>([]);

  const query = () => `from=${from}&to=${to}&source_id=${sourceId}&sdr_id=${sdrId}&broker_id=${brokerId}&business_type=${businessType}`;
  const load = () => api('/reports?' + query()).then(r => setD(r.data));
  useEffect(() => { void load() }, []);
  useEffect(() => {
    void api('/sources').then(r => setSources(r.data));
    void api('/users').then(r => setSdrs(r.data.filter((u: any) => u.role === 'sdr')));
    void api('/brokers').then(r => setBrokers(r.data));
  }, []);

  async function csv() {
    const r = await fetch('/api/reports/export?' + query(), {headers: {Authorization: `Bearer ${localStorage.getItem('token')}`}});
    if (!r.ok) return alert('Falha na exportação.');
    const a = document.createElement('a'); a.href = URL.createObjectURL(await r.blob()); a.download = 'relatorio-vln.csv'; a.click(); URL.revokeObjectURL(a.href);
  }

  const maxFunnel = Math.max(1, ...funnelStages.map(([k]) => Number(d?.stages?.find((s: any) => s.stage === k)?.total || 0)));

  return <>
    <header>
      <div><h1>Relatórios gerenciais</h1><p className="muted">Resultados filtrados e exportação controlada</p></div>
      <button onClick={csv}>Exportar CSV</button>
    </header>
    <div className="filters">
      <input type="date" value={from} onChange={e => setFrom(e.target.value)} />
      <input type="date" value={to} onChange={e => setTo(e.target.value)} />
      <select value={sourceId} onChange={e => setSourceId(e.target.value)}><option value="">Todas as origens</option>{sources.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
      <select value={sdrId} onChange={e => setSdrId(e.target.value)}><option value="">Todos os SDRs</option>{sdrs.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
      <select value={brokerId} onChange={e => setBrokerId(e.target.value)}><option value="">Todos os corretores</option>{brokers.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}</select>
      <select value={businessType} onChange={e => setBusinessType(e.target.value)}><option value="">Venda e locação</option><option value="purchase">Só venda</option><option value="rental">Só locação</option></select>
      <button onClick={load}>Aplicar</button>
    </div>
    <div className="report-stat-grid">
      <article className="stat-card"><span className="stat-icon"><Icon name="leads" /></span><div><small>Leads recebidos</small><strong>{d?.summary?.leads_received ?? 0}</strong><p>no período filtrado</p></div></article>
      <article className="stat-card"><span className="stat-icon"><Icon name="check" /></span><div><small>Qualificados</small><strong>{d?.summary?.qualified ?? 0}</strong><p>passaram pela triagem</p></div></article>
      <article className="stat-card orange"><span className="stat-icon"><Icon name="calendar" /></span><div><small>1º contato (médio)</small><strong>{d?.summary?.avg_first_contact ? Math.round(d.summary.avg_first_contact) + ' min' : '—'}</strong><p>tempo até o primeiro atendimento</p></div></article>
      <article className="stat-card green"><span className="stat-icon"><Icon name="briefcase" /></span><div><small>Vendas</small><strong>{d?.summary?.sales ?? 0}</strong><p>{money(d?.summary?.sales_value)}</p></div></article>
      <article className="stat-card"><span className="stat-icon"><Icon name="home" /></span><div><small>Locações</small><strong>{d?.summary?.rentals ?? 0}</strong><p>{money(d?.summary?.rentals_value)}</p></div></article>
    </div>
    <div className="dashboard-grid top-grid report-charts">
      <section className="dash-card">
        <div className="section-heading"><h2>Funil de conversão</h2></div>
        <div className="funnel-list">{funnelStages.map(([key, label], index) => {
          const total = Number(d?.stages?.find((s: any) => s.stage === key)?.total || 0);
          const pct = Math.round(total / maxFunnel * 100);
          return <div className="funnel-row" key={key}><span className="funnel-shape" style={{width: `${Math.max(28, 100 - index * 11)}%`}} /><span>{label}</span><strong>{total}</strong><small>{pct}%</small></div>;
        })}</div>
      </section>
      <section className="dash-card">
        <div className="section-heading"><h2>Evolução no período</h2></div>
        <TrendChart daily={d?.daily || []} />
      </section>
    </div>
  </>;
}
