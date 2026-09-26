import {useEffect, useState} from 'react';
import {api, money} from '../lib/api';
import {Icon} from './Icon';

const roleLabel: Record<string, string> = {admin: 'Administrador', manager: 'Gestor', sdr: 'SDR', broker: 'Corretor', analyst: 'Analista de crédito'};

function AssignModal({opportunity, brokers, onClose, onAssigned}: {opportunity: any; brokers: any[]; onClose: () => void; onAssigned: () => void}) {
  const [msg, setMsg] = useState('');
  const [busy, setBusy] = useState(false);
  async function distribute(broker_id: string, automatic = false) {
    setBusy(true);
    try { await api(`/leads/${opportunity.id}/distribute`, {method: 'POST', body: JSON.stringify({broker_id, automatic})}); onAssigned(); onClose() }
    catch (x: any) { setMsg(x.message) } finally { setBusy(false) }
  }
  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title"><span className="integration-icon on"><Icon name="users" /></span><div><h2>{opportunity.name}</h2><p className="muted">{opportunity.region || 'Região não informada'} · {money(opportunity.min_value)} – {money(opportunity.max_value)}</p></div></div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <div className="modal-body">
        {msg && <p className="error">{msg}</p>}
        <label>Atribuir manualmente<select disabled={busy} defaultValue="" onChange={e => e.target.value && distribute(e.target.value)}>
          <option value="">Selecione um corretor</option>
          {brokers.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select></label>
        <button disabled={busy} onClick={() => distribute('', true)}>Usar rodízio automático</button>
      </div>
    </div>
  </div>;
}

function UserModal({user, onClose, onChanged}: {user: any | null; onClose: () => void; onChanged: () => void}) {
  const isNew = !user;
  const [msg, setMsg] = useState('');
  const [active, setActive] = useState(user ? !!Number(user.active) : true);
  const [busy, setBusy] = useState(false);

  async function save(e: any) {
    e.preventDefault();
    setBusy(true);
    const body: any = Object.fromEntries(new FormData(e.currentTarget));
    body.active = active;
    if (!body.password) delete body.password;
    try {
      if (isNew) { validateNew(body); await api('/team', {method: 'POST', body: JSON.stringify(body)}) }
      else await api(`/team/${user.id}`, {method: 'PUT', body: JSON.stringify(body)});
      onChanged(); onClose();
    } catch (x: any) { setMsg(x.message) } finally { setBusy(false) }
  }
  function validateNew(body: any) { if (!body.password || body.password.length < 8) throw new Error('Defina uma senha com ao menos 8 caracteres.') }

  return <div className="modal-overlay" onClick={onClose}>
    <div className="modal" onClick={e => e.stopPropagation()}>
      <header className="modal-header">
        <div className="integration-modal-title"><span className="integration-icon on"><Icon name="users" /></span><div><h2>{isNew ? 'Novo usuário' : user.name}</h2><p className="muted">{isNew ? 'Cria um novo membro da equipe' : roleLabel[user.role]}</p></div></div>
        <button type="button" className="modal-close" onClick={onClose} aria-label="Fechar"><Icon name="close" /></button>
      </header>
      <form className="modal-body" onSubmit={save}>
        {msg && <p className="error">{msg}</p>}
        <div className="grid">
          <label>Nome<input name="name" defaultValue={user?.name} required /></label>
          <label>E-mail<input name="email" type="email" defaultValue={user?.email} required /></label>
          <label>CRECI (corretor)<input name="creci" defaultValue={user?.creci || ''} placeholder="Ex.: 123456-F" /></label>
          <label>Papel<select name="role" defaultValue={user?.role || 'sdr'}>
            <option value="admin">Administrador</option><option value="manager">Gestor</option><option value="sdr">SDR</option><option value="broker">Corretor</option><option value="analyst">Analista de crédito</option>
          </select></label>
          <label>{isNew ? 'Senha' : 'Nova senha (opcional)'}<input name="password" type="password" placeholder={isNew ? '' : 'Deixe em branco para manter'} required={isNew} /></label>
        </div>
        {!isNew && <label className="integration-switch"><span><strong>Usuário ativo</strong><small>Desativado, o usuário não consegue mais fazer login.</small></span><input type="checkbox" checked={active} onChange={e => setActive(e.target.checked)} /><i /></label>}
        {!isNew && <dl className="modal-meta">
          <dt>Oportunidades (corretor)</dt><dd>{user.broker_opportunities} ({user.broker_won} ganhas)</dd>
          <dt>Oportunidades (SDR)</dt><dd>{user.sdr_opportunities}</dd>
        </dl>}
        <button disabled={busy}>{isNew ? 'Criar usuário' : 'Salvar alterações'}</button>
      </form>
    </div>
  </div>;
}

export function Team() {
  const [queue, setQueue] = useState<any[]>([]);
  const [brokers, setBrokers] = useState<any[]>([]);
  const [team, setTeam] = useState<any[]>([]);
  const [enabled, setEnabled] = useState(false);
  const [msg, setMsg] = useState('');
  const [assignTarget, setAssignTarget] = useState<any>(null);
  const [userModal, setUserModal] = useState<any>(undefined);

  const loadQueue = () => api('/distribution/queue').then(r => setQueue(r.data));
  const loadTeam = () => api('/team').then(r => setTeam(r.data));
  useEffect(() => {
    void loadQueue();
    void api('/brokers').then(r => setBrokers(r.data));
    void loadTeam();
    void api('/distribution/settings').then(r => setEnabled(!!Number(r.data.automatic_enabled)));
  }, []);

  async function toggleRoundRobin(v: boolean) {
    setEnabled(v);
    try { await api('/distribution/settings', {method: 'PUT', body: JSON.stringify({automatic_enabled: v})}); setMsg('Configuração do rodízio salva.') }
    catch (x: any) { setMsg(x.message) }
  }
  async function toggleActive(u: any, activeNext: boolean) {
    try { await api(`/team/${u.id}`, {method: 'PUT', body: JSON.stringify({name: u.name, email: u.email, role: u.role, active: activeNext})}); loadTeam() }
    catch (x: any) { setMsg(x.message) }
  }

  return <>
    <header><div><h1>Equipe e distribuição</h1><p className="muted">Fila de distribuição, rodízio automático e gestão da equipe</p></div></header>
    {msg && <p className="notice">{msg}</p>}

    <section className="dash-card team-section">
      <div className="section-heading"><h2>Fila de distribuição</h2><span className="muted">{queue.length} oportunidade(s) qualificada(s) sem corretor</span></div>
      <div className="sdr-queue" style={{padding: '0 17px 17px'}}>
        {queue.map(x => <button key={x.id} className="sdr-card" onClick={() => setAssignTarget(x)}>
          <div className="sdr-card-main"><span className="lead-avatar">{x.name.slice(0, 2).toUpperCase()}</span><div><strong>{x.name}</strong><small>{x.region || 'Região não informada'}</small></div></div>
          <div className="sdr-card-meta"><small>{money(x.min_value)} – {money(x.max_value)}</small></div>
        </button>)}
        {!queue.length && <p className="empty-inline">Nenhuma oportunidade aguardando distribuição.</p>}
      </div>
    </section>

    <section className="card team-roundrobin">
      <label className="integration-switch">
        <span><strong>Rodízio automático</strong><small>Quando ativo, escolhe o próximo corretor ativo pela ordem estável de identificação.</small></span>
        <input type="checkbox" checked={enabled} onChange={e => toggleRoundRobin(e.target.checked)} /><i />
      </label>
    </section>

    <section className="dash-card team-section">
      <div className="section-heading"><h2>Equipe</h2><button onClick={() => setUserModal(null)}>+ Novo usuário</button></div>
      <div className="team-grid">
        {team.map(u => <article key={u.id} className={'team-card' + (Number(u.active) ? '' : ' inactive')}>
          <div className="team-card-top" onClick={() => setUserModal(u)}>
            <span className="lead-avatar">{u.name.slice(0, 2).toUpperCase()}</span>
            <div><strong>{u.name}</strong><small>{u.email}</small></div>
          </div>
          <div className="team-card-foot">
            <span className="badge qualification">{roleLabel[u.role]}</span>
            <label className="mini-switch" onClick={e => e.stopPropagation()}>
              <input type="checkbox" checked={!!Number(u.active)} onChange={e => toggleActive(u, e.target.checked)} /><i />
            </label>
          </div>
        </article>)}
      </div>
    </section>

    {assignTarget && <AssignModal opportunity={assignTarget} brokers={brokers} onClose={() => setAssignTarget(null)} onAssigned={loadQueue} />}
    {userModal !== undefined && <UserModal user={userModal} onClose={() => setUserModal(undefined)} onChanged={loadTeam} />}
  </>;
}
