<?php
declare(strict_types=1);

/**
 * Ficha de interesse / análise de crédito.
 * Fluxo: draft → submitted → in_analysis → approved | approved_conditions | rejected
 *        in_analysis → pending_docs → (corretor completa) → submitted …   e qualquer status aberto → cancelled.
 * Dados pessoais (proponente, cônjuge/compositor de renda, fiador) ficam cifrados em personal_data.
 */
final class CreditService {
 public const METHODS = ['cash'=>'Venda à vista','financing'=>'Venda financiada','down_payment_financing'=>'Entrada + financiamento','rental'=>'Locação'];
 public const DOCS = [
  'id'=>'Documento com foto e CPF (RG/CNH)', 'income'=>'Comprovante de renda', 'residence'=>'Comprovante de residência', 'marital'=>'Certidão de estado civil',
  'fgts'=>'Extrato do FGTS', 'tax'=>'Declaração de IR', 'co_applicant'=>'Documentos do cônjuge/compositor', 'guarantor'=>'Documentos do fiador', 'other'=>'Outro',
 ];
 private const PERSON = ['name','cpf','rg','rg_issuer','birth_date','nationality','marital_status','marriage_regime','profession','employment_type','employer','employment_since','monthly_income','other_income','phone','email','zip_code','address','city','state','relationship'];
 private const OPEN = ['draft','submitted','in_analysis','pending_docs'];
 private const DECIDED = ['approved','approved_conditions','rejected'];

 public function __construct(private PDO $db, private OperationsService $ops, private LeadService $leads) {}

 public function list(array $u, array $f): array {
  $where = []; $args = [];
  if ($u['role'] === 'broker') { $where[] = 'a.broker_id=?'; $args[] = $u['id']; }
  elseif ($u['role'] === 'analyst') $where[] = "a.status<>'draft'";
  elseif (!in_array($u['role'], ['admin','manager'], true)) throw new AuthorizationException('Sem acesso às fichas de crédito.');
  $queues = ['new'=>["a.status='submitted'"], 'mine'=>["a.status IN ('in_analysis','pending_docs')", 'a.analyst_id=?'], 'open'=>["a.status IN ('submitted','in_analysis','pending_docs')"], 'pending'=>["a.status='pending_docs'"], 'decided'=>["a.status IN ('approved','approved_conditions','rejected')"], 'draft'=>["a.status='draft'"], 'cancelled'=>["a.status='cancelled'"]];
  if (!empty($f['queue']) && isset($queues[$f['queue']])) { $where = array_merge($where, $queues[$f['queue']]); if ($f['queue'] === 'mine') $args[] = $u['id']; }
  if (!empty($f['q'])) { $where[] = '(a.applicant_name LIKE ? OR a.code LIKE ? OR p.reference_code LIKE ?)'; array_push($args, '%'.$f['q'].'%', '%'.$f['q'].'%', '%'.$f['q'].'%'); }
  $sql = 'SELECT a.id,a.code,a.status,a.payment_method,a.business_type,a.offer_value,a.rent_value,a.financing_value,a.approved_value,a.applicant_name,a.household_income,a.submitted_at,a.decided_at,a.updated_at,a.analyst_id,
   p.title property_title,p.reference_code property_reference,b.name broker_name,an.name analyst_name
   FROM credit_applications a JOIN properties p ON p.id=a.property_id JOIN users b ON b.id=a.broker_id LEFT JOIN users an ON an.id=a.analyst_id'
   .($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY COALESCE(a.submitted_at,a.updated_at) DESC LIMIT 200';
  $q = $this->db->prepare($sql); $q->execute($args);
  return ['data'=>$q->fetchAll(), 'counts'=>$this->counts($u)];
 }

 private function counts(array $u): array {
  $scope = $u['role'] === 'broker' ? ' WHERE broker_id=?' : ($u['role'] === 'analyst' ? " WHERE status<>'draft'" : '');
  $q = $this->db->prepare('SELECT status,COUNT(*) total FROM credit_applications'.$scope.' GROUP BY status'); $q->execute($u['role'] === 'broker' ? [$u['id']] : []);
  $out = array_fill_keys(['draft','submitted','in_analysis','pending_docs','approved','approved_conditions','rejected','cancelled'], 0);
  foreach ($q->fetchAll() as $r) $out[$r['status']] = (int)$r['total'];
  $q = $this->db->prepare("SELECT COUNT(*) FROM credit_applications WHERE status IN ('in_analysis','pending_docs') AND analyst_id=?"); $q->execute([$u['id']]);
  $out['mine'] = (int)$q->fetchColumn();
  $q = $this->db->prepare("SELECT COUNT(*) FROM credit_applications a WHERE a.status IN ('approved','approved_conditions') AND NOT EXISTS (SELECT 1 FROM contracts c WHERE c.credit_application_id=a.id AND c.status<>'cancelled')".($u['role'] === 'broker' ? ' AND a.broker_id=?' : ''));
  $q->execute($u['role'] === 'broker' ? [$u['id']] : []);
  $out['awaiting_contract'] = (int)$q->fetchColumn();
  return $out;
 }

 public function get(string $id, array $u): array {
  $a = $this->find($id);
  $this->assertView($a, $u);
  $q = $this->db->prepare('SELECT p.id,p.title,p.reference_code,p.property_type,p.purpose,p.price,p.rental_price,p.condo_fee,p.iptu_yearly,p.street,p.street_number,p.complement,p.neighborhood,p.city,p.state,p.zip_code,p.area_built,p.area_total,p.registry_number,p.registry_office,p.iptu_number,p.owner_name,p.owner_document FROM properties p WHERE p.id=?');
  $q->execute([$a['property_id']]); $property = $q->fetch();
  $q = $this->db->prepare('SELECT o.id,o.stage,c.name,c.email,c.phone FROM opportunities o JOIN contacts c ON c.id=o.contact_id WHERE o.id=?'); $q->execute([$a['opportunity_id']]);
  $q2 = $this->db->prepare('SELECT e.action,e.notes,e.created_at,u.name user_name FROM credit_events e JOIN users u ON u.id=e.user_id WHERE e.application_id=? ORDER BY e.created_at DESC'); $q2->execute([$id]);
  $people = open_json($a['personal_data']);
  unset($a['personal_data']);
  $docs = $this->documents($id);
  return $a + [
   'applicant'=>$people['applicant'] ?? [], 'co_applicant'=>$people['co_applicant'] ?? null, 'guarantor'=>$people['guarantor'] ?? null,
   'property'=>$property, 'client'=>$q->fetch(), 'events'=>$q2->fetchAll(), 'documents'=>$docs,
   'required_documents'=>$this->requiredDocs($a, $people), 'missing'=>$this->missing($a, $people, $docs),
   'broker_name'=>$this->userName($a['broker_id']), 'analyst_name'=>$a['analyst_id'] ? $this->userName($a['analyst_id']) : null,
   'can_edit'=>$this->canEdit($a, $u), 'can_decide'=>$this->canDecide($a, $u), 'contract'=>$this->contractOf($id),
   'labels'=>['methods'=>self::METHODS, 'documents'=>self::DOCS],
  ];
 }

 public function create(array $d, array $u): array {
  if (!in_array($u['role'], ['broker','admin','manager'], true)) throw new AuthorizationException('Somente corretor ou gestão abre fichas.');
  validate_required($d, ['property_id','payment_method']);
  if (!isset(self::METHODS[$d['payment_method']])) throw new InvalidArgumentException('Modalidade inválida.');
  $business = $d['payment_method'] === 'rental' ? 'rental' : 'purchase';
  $property = $this->property($d['property_id'], $business);
  $opportunityId = $d['opportunity_id'] ?? null;
  if ($opportunityId) {
   $q = $this->db->prepare('SELECT o.*,c.name,c.email,c.phone FROM opportunities o JOIN contacts c ON c.id=o.contact_id WHERE o.id=?'); $q->execute([$opportunityId]); $opp = $q->fetch();
   if (!$opp) throw new RuntimeException('Oportunidade não encontrada.');
   if ($u['role'] === 'broker' && $opp['broker_id'] !== $u['id']) throw new AuthorizationException('Esta oportunidade pertence a outro corretor.');
   if (in_array($opp['stage'], ['won','lost'], true)) throw new DomainException('A oportunidade já está encerrada.');
   $client = ['name'=>$opp['name'], 'email'=>$opp['email'], 'phone'=>$opp['phone']];
   $brokerId = $opp['broker_id'] ?: $u['id'];
  } else {
   $client = $d['client'] ?? [];
   if (trim((string)($client['name'] ?? '')) === '' || (trim((string)($client['phone'] ?? '')) === '' && trim((string)($client['email'] ?? '')) === '')) throw new InvalidArgumentException('Informe nome e telefone ou e-mail do cliente.');
   $source = $this->db->query("SELECT id FROM lead_sources WHERE code='local'")->fetchColumn();
   $lead = $this->leads->create(['name'=>$client['name'], 'phone'=>$client['phone'] ?? null, 'email'=>$client['email'] ?? null, 'source_id'=>$source, 'interest_type'=>$business, 'stage'=>'service', 'broker_id'=>$u['role'] === 'broker' ? $u['id'] : null, 'region'=>$property['neighborhood'], 'property_interest'=>'Ficha de interesse: '.$property['reference_code']], $u['id']);
   $opportunityId = $lead['id']; $brokerId = $u['role'] === 'broker' ? $u['id'] : ($d['broker_id'] ?? $u['id']);
   $this->db->prepare('UPDATE opportunities SET received_at=CURRENT_TIMESTAMP,service_started_at=CURRENT_TIMESTAMP,distributed_at=CURRENT_TIMESTAMP WHERE id=? AND broker_id IS NOT NULL')->execute([$opportunityId]);
  }
  $id = uid();
  $applicant = ['name'=>trim((string)$client['name']), 'phone'=>$client['phone'] ?? '', 'email'=>$client['email'] ?? ''];
  $values = $business === 'rental' ? ['rent_value'=>$property['purpose'] === 'rental' ? $property['price'] : $property['rental_price']] : ['offer_value'=>$property['price']];
  $cols = ['id'=>$id, 'code'=>$this->newCode(), 'opportunity_id'=>$opportunityId, 'property_id'=>$property['id'], 'broker_id'=>$brokerId, 'business_type'=>$business, 'payment_method'=>$d['payment_method'],
   'applicant_name'=>$applicant['name'], 'personal_data'=>seal_json(['applicant'=>$applicant])] + $values;
  $this->db->prepare('INSERT INTO credit_applications('.implode(',', array_keys($cols)).') VALUES('.implode(',', array_fill(0, count($cols), '?')).')')->execute(array_values($cols));
  $this->event($id, $u, 'created');
  $this->interaction($opportunityId, $u, 'credit_created', 'Ficha de interesse '.$cols['code'].' aberta para '.$property['reference_code']);
  return $this->get($id, $u);
 }

 public function update(string $id, array $d, array $u): array {
  $a = $this->find($id);
  if (!$this->canEdit($a, $u)) throw new AuthorizationException($a['status'] === 'submitted' || $a['status'] === 'in_analysis' ? 'A ficha está com o analista e não pode ser alterada agora.' : 'Você não pode alterar esta ficha.');
  $data = [];
  foreach (['offer_value','down_payment','fgts_value','rent_value'] as $k) if (array_key_exists($k, $d)) $data[$k] = $this->money($d[$k]);
  foreach (['financing_term_months','lease_months'] as $k) if (array_key_exists($k, $d)) $data[$k] = $d[$k] === '' || $d[$k] === null ? null : max(0, (int)$d[$k]);
  if (array_key_exists('bank_preference', $d)) $data['bank_preference'] = mb_substr(trim((string)$d['bank_preference']), 0, 80) ?: null;
  if (array_key_exists('move_in_date', $d)) $data['move_in_date'] = $d['move_in_date'] ?: null;
  if (array_key_exists('notes', $d)) $data['notes'] = mb_substr(trim((string)$d['notes']), 0, 4000) ?: null;
  if (array_key_exists('guarantee_type', $d)) { if ($d['guarantee_type'] && !in_array($d['guarantee_type'], ['deposit','guarantor','insurance','capitalization','none'], true)) throw new InvalidArgumentException('Garantia inválida.'); $data['guarantee_type'] = $d['guarantee_type'] ?: null; }
  if (array_key_exists('payment_method', $d)) {
   if (!isset(self::METHODS[$d['payment_method']])) throw new InvalidArgumentException('Modalidade inválida.');
   if (($d['payment_method'] === 'rental') !== ($a['business_type'] === 'rental')) throw new InvalidArgumentException('Não é possível trocar venda por locação na mesma ficha. Abra uma nova ficha.');
   $data['payment_method'] = $d['payment_method'];
  }
  $method = $data['payment_method'] ?? $a['payment_method'];
  $offer = $data['offer_value'] ?? $a['offer_value']; $down = $data['down_payment'] ?? $a['down_payment']; $fgts = $data['fgts_value'] ?? $a['fgts_value'];
  if ($method === 'cash' || $method === 'rental') { if ($method === 'cash') { $data['down_payment'] = null; $data['fgts_value'] = null; } $data['financing_value'] = null; $data['financing_term_months'] = null; }
  else {
   if ($method === 'financing') { $data['down_payment'] = null; $down = null; }
   $financed = (float)$offer - (float)$down - (float)$fgts;
   if ($offer !== null && $financed <= 0) throw new InvalidArgumentException('Entrada e FGTS não podem cobrir o valor total em uma venda financiada. Use "Venda à vista".');
   $data['financing_value'] = $offer === null ? null : round($financed, 2);
  }
  $people = open_json($a['personal_data']);
  foreach (['applicant','co_applicant','guarantor'] as $role) if (array_key_exists($role, $d)) $people[$role] = $d[$role] === null ? null : $this->person((array)$d[$role]);
  if (array_key_exists('applicant', $d) || array_key_exists('co_applicant', $d) || array_key_exists('guarantor', $d)) {
   $data['personal_data'] = seal_json($people);
   $data['applicant_name'] = $people['applicant']['name'] ?? $a['applicant_name'];
   $data['household_income'] = $this->income($people['applicant'] ?? []) + $this->income($people['co_applicant'] ?? []) ?: null;
  }
  if (!empty($d['consent'])) $data['consent_at'] = date('Y-m-d H:i:s');
  if ($data) $this->write($id, $data);
  return $this->get($id, $u);
 }

 public function submit(string $id, array $u): array {
  $a = $this->find($id);
  if (!$this->canEdit($a, $u)) throw new AuthorizationException('Você não pode enviar esta ficha.');
  if (!in_array($a['status'], ['draft','pending_docs'], true)) throw new DomainException('Esta ficha já foi enviada.');
  $people = open_json($a['personal_data']);
  if ($missing = $this->missing($a, $people, $this->documents($id))) throw new InvalidArgumentException('Complete antes de enviar: '.implode('; ', $missing).'.');
  $resubmit = $a['status'] === 'pending_docs';
  $this->write($id, ['status'=>$resubmit && $a['analyst_id'] ? 'in_analysis' : 'submitted', 'submitted_at'=>$resubmit ? $a['submitted_at'] : date('Y-m-d H:i:s')]);
  $this->event($id, $u, $resubmit ? 'resubmitted' : 'submitted');
  $this->interaction($a['opportunity_id'], $u, 'credit_submitted', 'Ficha '.$a['code'].($resubmit ? ' reenviada com as pendências resolvidas' : ' enviada para análise de crédito'));
  if ($resubmit && $a['analyst_id']) $this->ops->notify($a['analyst_id'], 'credit_resubmitted:'.$id.':'.uid(), 'Pendências respondidas', 'A ficha '.$a['code'].' ('.$a['applicant_name'].') voltou com as pendências resolvidas.', 'credit', $id);
  else foreach ($this->db->query("SELECT id FROM users WHERE role='analyst' AND active=1")->fetchAll(PDO::FETCH_COLUMN) as $analyst)
   $this->ops->notify($analyst, 'credit_submitted:'.$id.':'.uid(), 'Nova ficha para análise', $a['code'].' · '.$a['applicant_name'].' · '.self::METHODS[$a['payment_method']], 'credit', $id);
  $this->ops->audit($u['id'], 'credit_submitted', 'credit_application', $id, 'Ficha enviada para análise');
  return $this->get($id, $u);
 }

 public function claim(string $id, array $u): array {
  $a = $this->find($id);
  if (!in_array($u['role'], ['analyst','admin','manager'], true)) throw new AuthorizationException('Somente analistas assumem fichas.');
  if ($a['status'] !== 'submitted') throw new DomainException('Esta ficha não está aguardando análise.');
  $this->write($id, ['status'=>'in_analysis', 'analyst_id'=>$u['id']]);
  $this->event($id, $u, 'claimed');
  $this->ops->notify($a['broker_id'], 'credit_claimed:'.$id.':'.uid(), 'Ficha em análise', $a['code'].' ('.$a['applicant_name'].') está sendo analisada por '.$u['name'].'.', 'credit', $id);
  return $this->get($id, $u);
 }

 public function decide(string $id, array $d, array $u): array {
  $a = $this->find($id);
  if (!in_array($u['role'], ['analyst','admin','manager'], true)) throw new AuthorizationException('Somente analistas decidem fichas.');
  if ($a['status'] === 'submitted') { $this->claim($id, $u); $a = $this->find($id); }
  if (!$this->canDecide($a, $u)) throw new DomainException($a['status'] === 'in_analysis' ? 'Esta ficha está com outro analista.' : 'Esta ficha não está em análise.');
  $decision = (string)($d['decision'] ?? ''); $notes = trim((string)($d['notes'] ?? ''));
  $status = ['approve'=>'approved', 'approve_conditions'=>'approved_conditions', 'pending_docs'=>'pending_docs', 'reject'=>'rejected'][$decision] ?? throw new InvalidArgumentException('Decisão inválida.');
  if (in_array($decision, ['reject','pending_docs'], true) && $notes === '') throw new InvalidArgumentException($decision === 'reject' ? 'Informe o motivo da reprovação.' : 'Descreva o que está pendente para o corretor resolver.');
  $conditions = trim((string)($d['conditions'] ?? ''));
  if ($decision === 'approve_conditions' && $conditions === '') throw new InvalidArgumentException('Descreva as condições da aprovação.');
  $data = ['status'=>$status, 'decision_notes'=>$notes ?: null];
  if (in_array($decision, ['approve','approve_conditions'], true)) {
   $default = $a['business_type'] === 'rental' ? $a['rent_value'] : ($a['financing_value'] ?: $a['offer_value']);
   $data += ['approved_value'=>$this->money($d['approved_value'] ?? null) ?? $default, 'approved_conditions'=>$conditions ?: null, 'decided_at'=>date('Y-m-d H:i:s'), 'decided_by'=>$u['id']];
  } elseif ($decision === 'reject') $data += ['decided_at'=>date('Y-m-d H:i:s'), 'decided_by'=>$u['id']];
  $this->write($id, $data);
  $this->event($id, $u, $status, trim($notes.($conditions ? "\nCondições: $conditions" : '')) ?: null);
  [$title, $msg] = match ($status) {
   'approved' => ['Crédito aprovado', $a['code'].' · '.$a['applicant_name'].' foi aprovado. Você já pode emitir o contrato.'],
   'approved_conditions' => ['Crédito aprovado com condições', $a['code'].' · '.$a['applicant_name'].': '.mb_substr($conditions, 0, 250)],
   'pending_docs' => ['Pendência na ficha', $a['code'].' · '.$a['applicant_name'].': '.mb_substr($notes, 0, 250)],
   default => ['Crédito reprovado', $a['code'].' · '.$a['applicant_name'].': '.mb_substr($notes, 0, 250)],
  };
  $this->ops->notify($a['broker_id'], 'credit_decision:'.$id.':'.uid(), $title, $msg, 'credit', $id);
  $this->interaction($a['opportunity_id'], $u, 'credit_decision', $title.' ('.$a['code'].')'.($notes ? ': '.$notes : ''));
  $this->ops->audit($u['id'], 'credit_'.$status, 'credit_application', $id, 'Decisão de crédito: '.$status);
  return $this->get($id, $u);
 }

 public function cancel(string $id, string $reason, array $u): array {
  $a = $this->find($id);
  $own = $u['role'] === 'broker' && $a['broker_id'] === $u['id'];
  if (!$own && !in_array($u['role'], ['admin','manager'], true)) throw new AuthorizationException('Você não pode cancelar esta ficha.');
  if ($a['status'] === 'cancelled') throw new DomainException('A ficha já está cancelada.');
  if (trim($reason) === '') throw new InvalidArgumentException('Informe o motivo do cancelamento.');
  $this->write($id, ['status'=>'cancelled']);
  $this->event($id, $u, 'cancelled', $reason);
  if ($a['analyst_id'] && $a['analyst_id'] !== $u['id']) $this->ops->notify($a['analyst_id'], 'credit_cancelled:'.$id.':'.uid(), 'Ficha cancelada', $a['code'].' foi cancelada: '.mb_substr($reason, 0, 200), 'credit', $id);
  return $this->get($id, $u);
 }

 public function addDocument(string $id, array $file, string $kind, array $u): array {
  $a = $this->find($id);
  $analyst = in_array($u['role'], ['analyst','admin','manager'], true) && $a['status'] !== 'draft' && $a['status'] !== 'cancelled';
  if (!$this->canEdit($a, $u) && !$analyst) throw new AuthorizationException('Você não pode anexar documentos a esta ficha.');
  if (!isset(self::DOCS[$kind])) throw new InvalidArgumentException('Tipo de documento inválido.');
  [$name, $mime, $size, $sha] = store_private_upload($file);
  $docId = uid();
  $this->db->prepare("INSERT INTO documents(id,opportunity_id,entity_type,entity_id,uploaded_by,original_name,storage_name,mime_type,size_bytes,sha256,label) VALUES(?,?,'credit_application',?,?,?,?,?,?,?,?)")
   ->execute([$docId, $a['opportunity_id'], $id, $u['id'], mb_substr(basename((string)($file['name'] ?? 'documento')), 0, 255), $name, $mime, $size, $sha, $kind]);
  $this->ops->audit($u['id'], 'document_upload', 'document', $docId, 'Documento anexado à ficha '.$a['code'], ['kind'=>$kind, 'mime'=>$mime, 'size'=>$size]);
  return $this->get($id, $u);
 }

 public function removeDocument(string $id, string $docId, array $u): array {
  $a = $this->find($id);
  if (!$this->canEdit($a, $u)) throw new AuthorizationException('Você não pode remover documentos desta ficha.');
  $this->db->prepare("UPDATE documents SET deleted_at=CURRENT_TIMESTAMP,deleted_by=? WHERE id=? AND entity_type='credit_application' AND entity_id=? AND deleted_at IS NULL")->execute([$u['id'], $docId, $id]);
  $this->ops->audit($u['id'], 'document_delete', 'document', $docId, 'Documento removido da ficha '.$a['code']);
  return $this->get($id, $u);
 }

 /** Retorna o registro do documento depois de checar o acesso à ficha. */
 public function document(string $id, string $docId, array $u): array {
  $this->assertView($this->find($id), $u);
  $q = $this->db->prepare("SELECT * FROM documents WHERE id=? AND entity_type='credit_application' AND entity_id=? AND deleted_at IS NULL"); $q->execute([$docId, $id]);
  $doc = $q->fetch(); if (!$doc) throw new RuntimeException('Documento não encontrado.');
  $this->ops->audit($u['id'], 'document_download', 'document', $docId, 'Documento de ficha consultado');
  return $doc;
 }

 public function requiredDocs(array $a, array $people): array {
  $req = ['id','income','residence'];
  if (in_array($people['applicant']['marital_status'] ?? '', ['married','stable_union','divorced','widowed'], true)) $req[] = 'marital';
  if ((float)$a['fgts_value'] > 0) $req[] = 'fgts';
  if (!empty($people['co_applicant']['name'])) $req[] = 'co_applicant';
  if ($a['payment_method'] === 'rental' && $a['guarantee_type'] === 'guarantor') $req[] = 'guarantor';
  return $req;
 }

 private function missing(array $a, array $people, array $docs): array {
  $out = []; $p = $people['applicant'] ?? [];
  foreach (['name'=>'Nome do proponente','cpf'=>'CPF do proponente','birth_date'=>'Data de nascimento','phone'=>'Telefone','marital_status'=>'Estado civil','profession'=>'Profissão','employment_type'=>'Vínculo de trabalho'] as $k => $label) if (trim((string)($p[$k] ?? '')) === '') $out[] = $label;
  if (!empty($p['cpf']) && !valid_cpf($p['cpf'])) $out[] = 'CPF do proponente válido';
  if ((float)($p['monthly_income'] ?? 0) <= 0) $out[] = 'Renda mensal do proponente';
  if (!empty($people['co_applicant']['name']) && !valid_cpf((string)($people['co_applicant']['cpf'] ?? ''))) $out[] = 'CPF válido do cônjuge/compositor';
  if ($a['payment_method'] === 'rental') {
   if ((float)$a['rent_value'] <= 0) $out[] = 'Valor do aluguel';
   if (!$a['guarantee_type']) $out[] = 'Tipo de garantia';
   if ($a['guarantee_type'] === 'guarantor' && (empty($people['guarantor']['name']) || !valid_cpf((string)($people['guarantor']['cpf'] ?? '')))) $out[] = 'Nome e CPF válido do fiador';
  } else {
   if ((float)$a['offer_value'] <= 0) $out[] = 'Valor da proposta';
   if ($a['payment_method'] !== 'cash' && ((int)$a['financing_term_months'] < 12 || (int)$a['financing_term_months'] > 420)) $out[] = 'Prazo do financiamento (12 a 420 meses)';
   if ($a['payment_method'] === 'down_payment_financing' && (float)$a['down_payment'] <= 0) $out[] = 'Valor da entrada';
  }
  $have = array_flip(array_column($docs, 'label'));
  foreach ($this->requiredDocs($a, $people) as $k) if (!isset($have[$k])) $out[] = 'Documento: '.self::DOCS[$k];
  if (!$a['consent_at']) $out[] = 'Autorização do cliente para análise de crédito';
  return $out;
 }

 private function documents(string $id): array {
  $q = $this->db->prepare("SELECT d.id,d.label,d.original_name,d.mime_type,d.size_bytes,d.created_at,u.name uploaded_by_name FROM documents d JOIN users u ON u.id=d.uploaded_by WHERE d.entity_type='credit_application' AND d.entity_id=? AND d.deleted_at IS NULL ORDER BY d.created_at");
  $q->execute([$id]); return $q->fetchAll();
 }

 private function person(array $d): array {
  $out = [];
  foreach (self::PERSON as $k) if (isset($d[$k]) && trim((string)$d[$k]) !== '') $out[$k] = mb_substr(trim((string)$d[$k]), 0, 190);
  foreach (['monthly_income','other_income'] as $k) if (isset($out[$k])) $out[$k] = (string)max(0, (float)str_replace(',', '.', $out[$k]));
  if (isset($out['cpf'])) $out['cpf'] = preg_replace('/\D/', '', $out['cpf']);
  if (isset($out['email']) && !filter_var($out['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail inválido: '.$out['email']);
  return $out;
 }

 private function income(?array $p): float { return (float)($p['monthly_income'] ?? 0) + (float)($p['other_income'] ?? 0); }
 private function money(mixed $v): ?float { if ($v === null || $v === '') return null; $f = (float)str_replace(',', '.', (string)$v); if ($f < 0) throw new InvalidArgumentException('Valores não podem ser negativos.'); return round($f, 2); }

 private function property(string $id, string $business): array {
  $q = $this->db->prepare("SELECT * FROM properties WHERE id=? AND listing_status='approved'"); $q->execute([$id]); $p = $q->fetch();
  if (!$p) throw new InvalidArgumentException('Imóvel não encontrado ou ainda não publicado.');
  if (in_array($p['status'], ['sold','rented','inactive'], true)) throw new DomainException('Este imóvel não está mais disponível.');
  if ($p['purpose'] !== 'both' && $p['purpose'] !== $business) throw new InvalidArgumentException($business === 'rental' ? 'Este imóvel não está disponível para locação.' : 'Este imóvel não está disponível para venda.');
  return $p;
 }

 private function assertView(array $a, array $u): void {
  $ok = match ($u['role']) { 'admin','manager' => true, 'broker' => $a['broker_id'] === $u['id'], 'analyst' => $a['status'] !== 'draft', default => false };
  if (!$ok) throw new AuthorizationException('Você não tem acesso a esta ficha.');
 }
 private function canEdit(array $a, array $u): bool {
  if (!in_array($a['status'], ['draft','pending_docs'], true)) return false;
  return in_array($u['role'], ['admin','manager'], true) || ($u['role'] === 'broker' && $a['broker_id'] === $u['id']);
 }
 private function canDecide(array $a, array $u): bool {
  if ($a['status'] !== 'in_analysis') return false;
  return in_array($u['role'], ['admin','manager'], true) || ($u['role'] === 'analyst' && $a['analyst_id'] === $u['id']);
 }

 private function find(string $id): array {
  $q = $this->db->prepare('SELECT * FROM credit_applications WHERE id=?'); $q->execute([$id]); $a = $q->fetch();
  if (!$a) throw new RuntimeException('Ficha não encontrada.');
  return $a;
 }
 private function write(string $id, array $data): void {
  $this->db->prepare('UPDATE credit_applications SET '.implode(',', array_map(fn($k) => "$k=?", array_keys($data))).',updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([...array_values($data), $id]);
 }
 private function event(string $id, array $u, string $action, ?string $notes = null): void {
  $this->db->prepare('INSERT INTO credit_events(id,application_id,user_id,action,notes,created_at) VALUES(?,?,?,?,?,?)')->execute([uid(), $id, $u['id'], $action, $notes, now_micro()]);
 }
 private function interaction(string $opportunityId, array $u, string $type, string $text): void {
  $this->db->prepare('INSERT INTO interactions(id,opportunity_id,user_id,type,description) VALUES(?,?,?,?,?)')->execute([uid(), $opportunityId, $u['id'], $type, $text]);
 }
 private function contractOf(string $id): ?array { $q = $this->db->prepare("SELECT id,code,status FROM contracts WHERE credit_application_id=? AND status<>'cancelled' ORDER BY created_at DESC LIMIT 1"); $q->execute([$id]); return $q->fetch() ?: null; }
 private function userName(string $id): ?string { $q = $this->db->prepare('SELECT name FROM users WHERE id=?'); $q->execute([$id]); return $q->fetchColumn() ?: null; }
 private function newCode(): string {
  $year = date('Y');
  $q = $this->db->prepare('SELECT COUNT(*) FROM credit_applications WHERE code LIKE ?'); $q->execute(["FI-$year-%"]); $n = (int)$q->fetchColumn() + 1;
  do { $code = sprintf('FI-%s-%04d', $year, $n++); $q = $this->db->prepare('SELECT 1 FROM credit_applications WHERE code=?'); $q->execute([$code]); } while ($q->fetchColumn());
  return $code;
 }
}
