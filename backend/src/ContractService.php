<?php
declare(strict_types=1);

/**
 * Contratos gerados a partir de uma ficha de crédito aprovada.
 * draft (PDF gerado, editável) → sent (Documenso) → signed | declined; draft/sent → cancelled.
 * Sem Documenso configurado, o fluxo manual continua: baixar o PDF, colher assinaturas e anexar o PDF assinado.
 * Ao assinar, o fechamento (ganho) é registrado na oportunidade e o imóvel passa a vendido/alugado.
 */
final class ContractService {
 private const SIGNER_ROLES = ['buyer'=>'Comprador(a)', 'seller'=>'Vendedor(a)', 'tenant'=>'Locatário(a)', 'landlord'=>'Locador(a)', 'guarantor'=>'Fiador(a)', 'agency'=>'Imobiliária', 'witness'=>'Testemunha', 'spouse'=>'Cônjuge', 'other'=>'Interveniente'];

 public function __construct(private PDO $db, private OperationsService $ops, private SettingsService $settings, private CommercialService $commercial, private ?DocumensoClient $signer) {}

 /* ---------- consulta ---------- */

 public function list(array $u, array $f): array {
  $where = []; $args = [];
  if ($u['role'] === 'broker') { $where[] = 'c.broker_id=?'; $args[] = $u['id']; }
  elseif (!in_array($u['role'], ['admin','manager'], true)) throw new AuthorizationException('Sem acesso aos contratos.');
  if (!empty($f['status'])) { $where[] = 'c.status=?'; $args[] = $f['status']; }
  $q = $this->db->prepare('SELECT c.id,c.code,c.status,c.template_key,c.total_value,c.sent_at,c.signed_at,c.created_at,c.updated_at,c.signers,a.applicant_name,a.code credit_code,p.reference_code property_reference,p.title property_title,b.name broker_name
   FROM contracts c JOIN credit_applications a ON a.id=c.credit_application_id JOIN properties p ON p.id=c.property_id JOIN users b ON b.id=c.broker_id'
   .($where ? ' WHERE '.implode(' AND ', $where) : '').' ORDER BY c.updated_at DESC LIMIT 200');
  $q->execute($args);
  $rows = array_map(function ($r) { $s = json_decode((string)$r['signers'], true) ?: []; $r['signers_total'] = count($s); $r['signers_signed'] = count(array_filter($s, fn($x) => ($x['status'] ?? '') === 'signed')); unset($r['signers']); return $r; }, $q->fetchAll());
  return ['data'=>$rows, 'templates'=>ContractTemplates::KEYS];
 }

 public function get(string $id, array $u): array {
  $c = $this->find($id); $this->assertAccess($c, $u);
  $q = $this->db->prepare('SELECT e.action,e.notes,e.created_at,u.name user_name FROM contract_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.contract_id=? ORDER BY e.created_at DESC'); $q->execute([$id]);
  $credit = $this->credit($c['credit_application_id']);
  $terms = open_json($c['terms']); $signers = json_decode((string)$c['signers'], true) ?: []; $hasSigned = (bool)$c['signed_storage_name'];
  unset($c['terms'], $c['signers'], $c['pdf_storage_name'], $c['signed_storage_name']);
  return $c + [
   'terms'=>$terms, 'signers'=>$signers, 'events'=>$q->fetchAll(),
   'credit'=>['id'=>$credit['id'], 'code'=>$credit['code'], 'status'=>$credit['status'], 'payment_method'=>$credit['payment_method'], 'approved_value'=>$credit['approved_value'], 'approved_conditions'=>$credit['approved_conditions']],
   'template_name'=>ContractTemplates::KEYS[$c['template_key']] ?? $c['template_key'], 'signer_roles'=>self::SIGNER_ROLES,
   'can_edit'=>$c['status'] === 'draft', 'documenso'=>$this->signer !== null, 'has_signed_pdf'=>$hasSigned,
  ];
 }

 /* ---------- ciclo de vida ---------- */

 public function createFromCredit(string $creditId, array $u): array {
  $a = $this->credit($creditId);
  if ($u['role'] === 'broker' && $a['broker_id'] !== $u['id']) throw new AuthorizationException('Esta ficha pertence a outro corretor.');
  if (!in_array($u['role'], ['broker','admin','manager'], true)) throw new AuthorizationException('Sem permissão para emitir contratos.');
  if (!in_array($a['status'], ['approved','approved_conditions'], true)) throw new DomainException('O contrato só pode ser emitido depois da aprovação da ficha.');
  $q = $this->db->prepare("SELECT code FROM contracts WHERE credit_application_id=? AND status<>'cancelled'"); $q->execute([$creditId]);
  if ($existing = $q->fetchColumn()) throw new DomainException("Já existe o contrato $existing para esta ficha. Cancele-o para emitir outro.");
  $property = $this->property($a['property_id']);
  $people = open_json($a['personal_data']);
  $key = ContractTemplates::BY_METHOD[$a['payment_method']];
  $terms = $this->defaultTerms($a, $property, $people);
  $signers = $this->defaultSigners($a, $property, $people);
  $id = uid(); $code = $this->newCode();
  $this->db->prepare('INSERT INTO contracts(id,code,credit_application_id,opportunity_id,property_id,broker_id,template_key,total_value,terms,signers,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$id, $code, $creditId, $a['opportunity_id'], $a['property_id'], $a['broker_id'], $key, $this->total($key, $terms), seal_json($terms), json_encode($signers, JSON_UNESCAPED_UNICODE), $u['id']]);
  $this->generate($id);
  $this->event($id, $u['id'], 'created', ContractTemplates::KEYS[$key]);
  $this->ops->audit($u['id'], 'contract_created', 'contract', $id, 'Contrato '.$code.' gerado a partir da ficha '.$a['code']);
  return $this->get($id, $u);
 }

 public function update(string $id, array $d, array $u): array {
  $c = $this->find($id); $this->assertAccess($c, $u);
  if ($c['status'] !== 'draft') throw new DomainException('Só é possível editar contratos em rascunho.');
  $terms = open_json($c['terms']);
  foreach ((array)($d['terms'] ?? []) as $k => $v) if (is_string($k) && preg_match('/^[a-z_]{2,40}$/', $k)) $terms[$k] = is_scalar($v) || $v === null ? mb_substr(trim((string)$v), 0, 3000) : $terms[$k] ?? '';
  $signers = json_decode((string)$c['signers'], true) ?: [];
  if (isset($d['signers'])) $signers = $this->cleanSigners((array)$d['signers']);
  $this->write($id, ['terms'=>seal_json($terms), 'signers'=>json_encode($signers, JSON_UNESCAPED_UNICODE), 'total_value'=>$this->total($c['template_key'], $terms)]);
  $this->generate($id);
  $this->event($id, $u['id'], 'edited');
  return $this->get($id, $u);
 }

 public function send(string $id, array $u): array {
  $c = $this->find($id); $this->assertAccess($c, $u);
  if ($c['status'] !== 'draft') throw new DomainException('Este contrato já foi enviado.');
  if (!$this->signer) throw new DomainException('Assinatura eletrônica não configurada. Configure DOCUMENSO_URL e DOCUMENSO_API_KEY ou use o fluxo manual (baixar PDF e anexar o assinado).');
  $signers = json_decode((string)$c['signers'], true) ?: [];
  $this->validateSigners($signers);
  [$pdf, $positions] = $this->render($c, $signers);
  $recipients = array_map(fn($s, $i) => ['name'=>$s['name'], 'email'=>$s['email'], 'fields'=>[$positions[$i]]], $signers, array_keys($signers));
  $company = $this->settings->get('company');
  $envelope = $this->signer->createEnvelope("Contrato {$c['code']} – ".ContractTemplates::KEYS[$c['template_key']], $c['id'], $pdf, "contrato-{$c['code']}.pdf", $recipients, [
   'subject'=>"Contrato {$c['code']} para assinatura – ".($company['name'] ?: 'Imobiliária'),
   'message'=>"Olá! Segue o contrato {$c['code']} para leitura e assinatura eletrônica. Em caso de dúvida, fale com o seu corretor.",
  ]);
  $this->write($id, ['status'=>'sent', 'signature_provider'=>'documenso', 'provider_envelope_id'=>$envelope, 'sent_at'=>date('Y-m-d H:i:s'),
   'signers'=>json_encode(array_map(fn($s) => $s + ['status'=>'pending'], $signers), JSON_UNESCAPED_UNICODE)]);
  try { $this->signer->distribute($envelope); }
  catch (Throwable $e) { $this->write($id, ['status'=>'draft', 'signature_provider'=>null, 'provider_envelope_id'=>null, 'sent_at'=>null]); throw $e; }
  $this->event($id, $u['id'], 'sent', count($signers).' signatário(s) notificados por e-mail');
  $this->ops->audit($u['id'], 'contract_sent', 'contract', $id, 'Contrato enviado para assinatura eletrônica');
  return $this->get($id, $u);
 }

 /** Consulta o Documenso (útil quando o webhook não alcança o servidor, como em ambiente local). */
 public function refresh(string $id, array $u): array {
  $c = $this->find($id); $this->assertAccess($c, $u);
  if ($c['status'] !== 'sent' || !$this->signer || !$c['provider_envelope_id']) return $this->get($id, $u);
  $env = $this->signer->get($c['provider_envelope_id']);
  $this->applyProviderState($c, (string)($env['status'] ?? ''), $env['recipients'] ?? [], $u['id']);
  return $this->get($id, $u);
 }

 /** Webhook do Documenso: retorna false se o envelope não pertence a este sistema. */
 public function webhook(array $body): bool {
  $p = $body['payload'] ?? [];
  $envelope = (string)($p['envelopeId'] ?? ''); $external = (string)($p['externalId'] ?? '');
  $q = $this->db->prepare('SELECT * FROM contracts WHERE provider_envelope_id=? OR (id=? AND ?<>\'\')'); $q->execute([$envelope, $external, $external]);
  $c = $q->fetch(); if (!$c) return false;
  $status = match ((string)($body['event'] ?? '')) { 'DOCUMENT_COMPLETED' => 'COMPLETED', 'DOCUMENT_REJECTED' => 'REJECTED', 'DOCUMENT_CANCELLED' => 'CANCELLED', default => 'PENDING' };
  $this->applyProviderState($c, $status, $p['recipients'] ?? $p['Recipient'] ?? [], null);
  return true;
 }

 public function uploadSigned(string $id, array $file, array $u): array {
  $c = $this->find($id); $this->assertAccess($c, $u);
  if (!in_array($c['status'], ['draft','sent'], true)) throw new DomainException('Este contrato não aceita mais o envio do PDF assinado.');
  [$name, , , $sha] = store_private_upload($file, ['application/pdf'=>'pdf']);
  $signers = array_map(fn($s) => ['status'=>'signed', 'signed_at'=>date('Y-m-d H:i:s')] + $s, json_decode((string)$c['signers'], true) ?: []);
  $this->write($id, ['signature_provider'=>$c['signature_provider'] ?: 'manual', 'signers'=>json_encode($signers, JSON_UNESCAPED_UNICODE)]);
  $this->finalize($this->find($id), $name, $sha, $u['id'], 'PDF assinado anexado manualmente');
  return $this->get($id, $u);
 }

 public function cancel(string $id, string $reason, array $u): array {
  $c = $this->find($id); $this->assertAccess($c, $u);
  if (!in_array($c['status'], ['draft','sent'], true)) throw new DomainException('Contrato assinado ou já encerrado não pode ser cancelado por aqui.');
  if (trim($reason) === '') throw new InvalidArgumentException('Informe o motivo do cancelamento.');
  if ($c['status'] === 'sent' && $c['signature_provider'] === 'documenso' && $this->signer) $this->signer->cancel($c['provider_envelope_id'], $reason);
  $this->write($id, ['status'=>'cancelled', 'cancelled_at'=>date('Y-m-d H:i:s'), 'cancel_reason'=>mb_substr($reason, 0, 500)]);
  $this->event($id, $u['id'], 'cancelled', $reason);
  return $this->get($id, $u);
 }

 /** Caminho e nome do PDF (gerado ou assinado) depois de checar o acesso. */
 public function file(string $id, array $u, bool $signed): array {
  $c = $this->find($id); $this->assertAccess($c, $u);
  $name = $signed ? $c['signed_storage_name'] : $c['pdf_storage_name'];
  if (!$name) throw new RuntimeException($signed ? 'O PDF assinado ainda não está disponível.' : 'PDF não gerado.');
  $this->ops->audit($u['id'], 'contract_download', 'contract', $id, $signed ? 'PDF assinado consultado' : 'PDF do contrato consultado');
  return [private_storage().'/'.$name, "contrato-{$c['code']}".($signed ? '-assinado' : '').'.pdf'];
 }

 /* ---------- modelos ---------- */

 public function templates(): array {
  $stored = $this->db->query('SELECT t.template_key,t.body,t.updated_at,u.name updated_by FROM contract_templates t LEFT JOIN users u ON u.id=t.updated_by')->fetchAll(PDO::FETCH_UNIQUE);
  $out = [];
  foreach (ContractTemplates::defaults() as $key => $body) $out[] = ['key'=>$key, 'name'=>ContractTemplates::KEYS[$key], 'body'=>$stored[$key]['body'] ?? $body, 'customized'=>isset($stored[$key]), 'updated_at'=>$stored[$key]['updated_at'] ?? null, 'updated_by'=>$stored[$key]['updated_by'] ?? null];
  return ['data'=>$out, 'variables'=>ContractTemplates::VARIABLES];
 }

 public function saveTemplate(string $key, ?string $body, array $u): array {
  if (!isset(ContractTemplates::KEYS[$key])) throw new RuntimeException('Modelo inexistente.');
  if ($body === null) { $this->db->prepare('DELETE FROM contract_templates WHERE template_key=?')->execute([$key]); $action = 'restaurado ao padrão'; }
  else {
   $body = str_replace("\r", '', $body);
   if (mb_strlen(trim($body)) < 200) throw new InvalidArgumentException('O texto do contrato está curto demais.');
   if (!str_contains($body, '{{assinaturas}}')) throw new InvalidArgumentException('Inclua {{assinaturas}} no ponto onde o bloco de assinaturas deve aparecer.');
   $known = []; foreach (ContractTemplates::VARIABLES as $group) $known += $group; $known['assinaturas'] = 1;
   preg_match_all('/\{\{\s*([a-z_.]+)\s*\}\}/', $body, $m);
   if ($unknown = array_diff(array_unique($m[1]), array_keys($known))) throw new InvalidArgumentException('Variáveis desconhecidas: '.implode(', ', $unknown).'.');
   $exists = $this->db->prepare('SELECT 1 FROM contract_templates WHERE template_key=?'); $exists->execute([$key]);
   $this->db->prepare($exists->fetchColumn() ? 'UPDATE contract_templates SET body=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE template_key=?' : 'INSERT INTO contract_templates(body,updated_by,template_key) VALUES(?,?,?)')->execute([$body, $u['id'], $key]);
   $action = 'atualizado';
  }
  $this->ops->audit($u['id'], 'contract_template_saved', 'contract_template', str_pad($key, 32, '_'), 'Modelo "'.ContractTemplates::KEYS[$key].'" '.$action);
  return $this->templates();
 }

 /** Prévia do modelo com dados fictícios (para conferir o texto antes de usar). */
 public function preview(string $key, ?string $body): string {
  if (!isset(ContractTemplates::KEYS[$key])) throw new RuntimeException('Modelo inexistente.');
  $sale = $key !== 'lease';
  $fake = ['id'=>'preview', 'code'=>'CT-EXEMPLO', 'template_key'=>$key, 'broker_id'=>null];
  $terms = [
   'data'=>date('Y-m-d'), 'cidade'=>'São Paulo', 'foro'=>'São Paulo/SP', 'condicoes_especiais'=>'Não há.',
   'comprador_qualificacao'=>'MARIA EXEMPLO DA SILVA, brasileira, solteira, engenheira, portadora do RG nº 12.345.678-9 SSP/SP, inscrita no CPF sob nº 123.456.789-09, residente na Rua Exemplo, 100, São Paulo/SP',
   'vendedor_qualificacao'=>'JOÃO PROPRIETÁRIO, inscrito no CPF sob nº 987.654.321-00', 'comprador_nome'=>'Maria Exemplo da Silva', 'vendedor_nome'=>'João Proprietário',
   'imovel_descricao'=>'Apartamento com 2 quartos, 1 vaga e 68 m² de área útil', 'imovel_endereco'=>'Rua das Flores, 200, apto 31, Centro, São Paulo/SP, CEP 01000-000', 'imovel_matricula'=>'123.456', 'imovel_cartorio'=>'1º Oficial de Registro de Imóveis de São Paulo', 'imovel_iptu_inscricao'=>'000.000.0000-0', 'imovel_condominio'=>'650',
   'preco'=>'500000', 'sinal'=>'50000', 'sinal_data'=>date('Y-m-d', strtotime('+3 days')), 'entrada'=>'100000', 'fgts'=>'20000', 'financiado'=>'380000', 'banco'=>'Caixa Econômica Federal', 'prazo_financiamento'=>'360', 'saldo_data'=>date('Y-m-d', strtotime('+60 days')), 'prazo_escritura'=>'60', 'posse'=>'na data da lavratura da escritura', 'multa'=>'10', 'comissao_percentual'=>'6', 'comissao_responsavel'=>'VENDEDOR',
   'aluguel'=>'3200', 'prazo_meses'=>'30', 'inicio'=>date('Y-m-d', strtotime('+7 days')), 'vencimento_dia'=>'10', 'indice'=>'IGP-M/FGV', 'garantia'=>'deposit', 'garantia_valor'=>'9600', 'multa_alugueis'=>'3', 'finalidade'=>'residencial',
  ];
  if (!$sale) { $terms['comprador_qualificacao'] = str_replace('MARIA', 'CARLA', $terms['comprador_qualificacao']); }
  $signers = $sale ? [['name'=>'Maria Exemplo da Silva', 'role'=>'buyer', 'doc'=>'123.456.789-09'], ['name'=>'João Proprietário', 'role'=>'seller', 'doc'=>'987.654.321-00'], ['name'=>'Imobiliária Exemplo', 'role'=>'agency', 'doc'=>'']]
   : [['name'=>'Carla Exemplo', 'role'=>'tenant', 'doc'=>'123.456.789-09'], ['name'=>'João Proprietário', 'role'=>'landlord', 'doc'=>''], ['name'=>'Imobiliária Exemplo', 'role'=>'agency', 'doc'=>'']];
  return $this->render($fake + ['terms'=>seal_json($terms)], $signers, $body)[0];
 }

 /* ---------- geração ---------- */

 private function generate(string $id): void {
  $c = $this->find($id);
  [$pdf] = $this->render($c, json_decode((string)$c['signers'], true) ?: []);
  $name = bin2hex(random_bytes(24)).'.pdf';
  if (file_put_contents(private_storage().'/'.$name, $pdf) === false) throw new RuntimeException('Falha ao gravar o PDF do contrato.');
  chmod(private_storage().'/'.$name, 0600);
  if ($c['pdf_storage_name'] && is_file(private_storage().'/'.$c['pdf_storage_name'])) unlink(private_storage().'/'.$c['pdf_storage_name']);
  $this->write($id, ['pdf_storage_name'=>$name, 'pdf_sha256'=>hash('sha256', $pdf)]);
 }

 /** @return array{0:string,1:array} PDF e posição dos campos de assinatura por signatário */
 private function render(array $c, array $signers, ?string $body = null): array {
  $body ??= $this->templateBody($c['template_key']);
  $this->clause = 0;
  $vars = $this->variables($c, open_json($c['terms']));
  $company = $this->settings->get('company');
  $pdf = new SimplePdf('Contrato '.$c['code']);
  $pdf->footer(trim(($company['name'] ?: 'Contrato').' · Contrato '.$c['code']));
  $positions = [];
  foreach (preg_split('/\n\s*\n/', str_replace("\r", '', $body)) as $block) {
   $block = trim($block);
   if ($block === '') continue;
   if ($block === '{{assinaturas}}') {
    $people = array_map(fn($s) => ['name'=>$s['name'] ?: '________________', 'role'=>self::SIGNER_ROLES[$s['role'] ?? 'other'] ?? 'Signatário', 'doc'=>$s['doc'] ?? ''], $signers);
    $positions = $pdf->signatures($people, ($vars['contrato.cidade'] ?: '________').', '.$vars['contrato.data'].'.');
    continue;
   }
   $n = 0; // numera as cláusulas automaticamente: "CLÁUSULA 1ª — DO OBJETO"
   foreach (explode("\n", $block) as $line) {
    $line = trim($this->fill($line, $vars));
    if ($line === '') continue;
    if (str_starts_with($line, '# ')) $pdf->heading(substr($line, 2));
    elseif (str_starts_with($line, '## ')) $pdf->subheading($this->clauseNumber(substr($line, 3)));
    elseif (str_starts_with($line, '- ')) $pdf->paragraph(substr($line, 2), 10, false, 14, '•');
    else $pdf->paragraph($line);
   }
  }
  return [$pdf->output(), $positions];
 }

 private int $clause = 0;
 private function clauseNumber(string $title): string {
  if (!preg_match('/^CLÁUSULA\s*[—–-]\s*/u', $title)) return $title;
  $this->clause++;
  return preg_replace('/^CLÁUSULA\s*[—–-]\s*/u', 'CLÁUSULA '.$this->clause.'ª — ', $title);
 }

 private function fill(string $text, array $vars): string {
  return preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', fn($m) => ($v = trim((string)($vars[$m[1]] ?? ''))) !== '' ? $v : '______________', $text);
 }

 private function templateBody(string $key): string {
  $q = $this->db->prepare('SELECT body FROM contract_templates WHERE template_key=?'); $q->execute([$key]);
  return $q->fetchColumn() ?: ContractTemplates::defaults()[$key];
 }

 /** Monta todas as variáveis do modelo a partir das condições do contrato, da empresa e do corretor. */
 private function variables(array $c, array $t): array {
  $company = $this->settings->get('company');
  $broker = $c['broker_id'] ? $this->db->query("SELECT name,creci FROM users WHERE id=".$this->db->quote($c['broker_id']))->fetch() : ['name'=>'Corretor Exemplo', 'creci'=>'000000-F'];
  $money = fn($v) => $v === null || $v === '' ? '' : 'R$ '.number_format((float)$v, 2, ',', '.').' ('.ContractTemplates::moneyWords((float)$v).')';
  $date = fn($v) => $v ? self::longDate((string)$v) : '';
  $agency = trim(($company['legal_name'] ?: $company['name']).($company['cnpj'] ? ', inscrita no CNPJ sob nº '.$company['cnpj'] : '').($company['creci'] ? ', CRECI '.$company['creci'] : '').($company['address'] ? ', com sede em '.$company['address'].($company['city'] ? ', '.$company['city'].($company['state'] ? '/'.$company['state'] : '') : '') : ''));
  $price = (float)($t['preco'] ?? 0); $signal = (float)($t['sinal'] ?? 0);
  $commissionPct = (float)($t['comissao_percentual'] ?? 0);
  $start = $t['inicio'] ?? ''; $months = (int)($t['prazo_meses'] ?? 0);
  $end = $start && $months ? date('Y-m-d', strtotime("$start +$months months -1 day")) : '';
  $rent = (float)($t['aluguel'] ?? 0);
  $guarantee = match ($t['garantia'] ?? '') {
   'deposit' => 'Em garantia das obrigações deste contrato, o LOCATÁRIO entrega ao LOCADOR, a título de caução em dinheiro, a quantia de '.$money($t['garantia_valor'] ?? 0).', equivalente a no máximo 3 (três) aluguéis (art. 38, § 2º, da Lei 8.245/1991), a ser depositada em caderneta de poupança e devolvida ao final da locação com os rendimentos, deduzidos eventuais débitos.',
   'guarantor' => 'Assina este contrato, como FIADOR e principal pagador, solidariamente responsável com o LOCATÁRIO por todas as obrigações até a efetiva entrega das chaves, com renúncia ao benefício de ordem (arts. 827 e 828 do Código Civil): '.($t['fiador_qualificacao'] ?? '______________').'.',
   'insurance' => 'As obrigações deste contrato são garantidas por seguro-fiança locatício contratado pelo LOCATÁRIO, que se obriga a mantê-lo vigente e renová-lo durante toda a locação, sob pena de rescisão.',
   'capitalization' => 'As obrigações deste contrato são garantidas por título de capitalização no valor de '.$money($t['garantia_valor'] ?? 0).', cedido ao LOCADOR durante toda a locação.',
   default => 'A presente locação é celebrada sem garantia, podendo o LOCADOR exigir o pagamento antecipado do aluguel até o sexto dia útil do mês vincendo (art. 42 da Lei 8.245/1991).',
  };
  return [
   'contrato.codigo'=>$c['code'], 'contrato.data'=>$date($t['data'] ?? date('Y-m-d')), 'contrato.cidade'=>$t['cidade'] ?? '', 'contrato.foro'=>$t['foro'] ?? '', 'condicoes_especiais'=>($t['condicoes_especiais'] ?? '') ?: 'Não há condições especiais além das previstas neste instrumento.',
   'imobiliaria.nome'=>$company['name'], 'imobiliaria.qualificacao'=>$agency, 'corretor.nome'=>$broker['name'] ?? '', 'corretor.creci'=>$broker['creci'] ?? '',
   'comprador.qualificacao'=>$t['comprador_qualificacao'] ?? '', 'comprador.nome'=>$t['comprador_nome'] ?? '', 'vendedor.qualificacao'=>$t['vendedor_qualificacao'] ?? '', 'vendedor.nome'=>$t['vendedor_nome'] ?? '',
   'locatario.qualificacao'=>$t['comprador_qualificacao'] ?? '', 'locatario.nome'=>$t['comprador_nome'] ?? '', 'locador.qualificacao'=>$t['vendedor_qualificacao'] ?? '', 'locador.nome'=>$t['vendedor_nome'] ?? '', 'fiador.qualificacao'=>$t['fiador_qualificacao'] ?? '',
   'imovel.descricao'=>$t['imovel_descricao'] ?? '', 'imovel.endereco'=>$t['imovel_endereco'] ?? '', 'imovel.matricula'=>$t['imovel_matricula'] ?? '', 'imovel.cartorio'=>$t['imovel_cartorio'] ?? '', 'imovel.iptu_inscricao'=>$t['imovel_iptu_inscricao'] ?? '', 'imovel.condominio'=>isset($t['imovel_condominio']) && $t['imovel_condominio'] !== '' ? 'R$ '.number_format((float)$t['imovel_condominio'], 2, ',', '.') : '',
   'venda.preco'=>$money($t['preco'] ?? ''), 'venda.sinal'=>$money($t['sinal'] ?? ''), 'venda.sinal_data'=>$date($t['sinal_data'] ?? ''), 'venda.entrada'=>$money($t['entrada'] ?? ''), 'venda.fgts'=>$money(($t['fgts'] ?? '') ?: 0), 'venda.financiado'=>$money($t['financiado'] ?? ''), 'venda.banco'=>$t['banco'] ?? '',
   'venda.prazo_financiamento'=>!empty($t['prazo_financiamento']) ? $t['prazo_financiamento'].' meses' : '', 'venda.saldo'=>$price ? $money(max(0, $price - $signal)) : '', 'venda.saldo_data'=>$date($t['saldo_data'] ?? ''), 'venda.prazo_escritura'=>$t['prazo_escritura'] ?? '', 'venda.posse'=>$t['posse'] ?? '', 'venda.multa'=>isset($t['multa']) && $t['multa'] !== '' ? $t['multa'].'%' : '',
   'comissao.percentual'=>$commissionPct ? rtrim(rtrim(number_format($commissionPct, 2, ',', ''), '0'), ',').'%' : '', 'comissao.valor'=>$commissionPct && $price ? $money(round($price * $commissionPct / 100, 2)) : '', 'comissao.responsavel'=>$t['comissao_responsavel'] ?? '',
   'locacao.aluguel'=>$rent ? $money($rent) : '', 'locacao.prazo_meses'=>$months ?: '', 'locacao.inicio'=>$date($start), 'locacao.fim'=>$date($end), 'locacao.vencimento_dia'=>$t['vencimento_dia'] ?? '', 'locacao.indice'=>$t['indice'] ?? '', 'locacao.garantia_texto'=>$guarantee, 'locacao.multa_alugueis'=>$t['multa_alugueis'] ?? '', 'locacao.finalidade'=>$t['finalidade'] ?? '',
  ];
 }

 private function defaultTerms(array $a, array $p, array $people): array {
  $cfg = $this->settings->get('contracts'); $company = $this->settings->get('company');
  $sale = $a['business_type'] === 'purchase';
  $buyers = array_filter([$people['applicant'] ?? null, ($people['co_applicant']['relationship'] ?? '') === 'spouse' ? $people['co_applicant'] : null]);
  $price = (float)($a['offer_value'] ?? 0);
  $address = implode(', ', array_filter([trim(($p['street'] ?? '').', '.($p['street_number'] ?? ''), ', '), $p['complement'], $p['neighborhood'], ($p['city'] ? $p['city'].'/'.$p['state'] : null), $p['zip_code'] ? 'CEP '.$p['zip_code'] : null])) ?: ($p['address'] ?? '');
  $desc = $p['property_type'].implode('', array_map(fn($x) => $x ? ", $x" : '', [$p['bedrooms'] ? $p['bedrooms'].' quarto(s)' : null, $p['suites'] ? $p['suites'].' suíte(s)' : null, $p['parking_spaces'] ? $p['parking_spaces'].' vaga(s) de garagem' : null, $p['area_built'] ? number_format((float)$p['area_built'], 2, ',', '.').' m² de área útil' : null, $p['area_total'] && $p['area_total'] !== $p['area_built'] ? number_format((float)$p['area_total'], 2, ',', '.').' m² de área total' : null, $p['condo_name'] ? 'no condomínio '.$p['condo_name'] : null]));
  $special = trim(implode("\n", array_filter([$a['approved_conditions'] ? 'Condição da aprovação de crédito: '.$a['approved_conditions'] : null])));
  $t = [
   'data'=>date('Y-m-d'), 'cidade'=>$company['city'] ?: ($p['city'] ?? ''), 'foro'=>$cfg['foro'] ?: trim(($p['city'] ?? '').($p['state'] ? '/'.$p['state'] : '')), 'condicoes_especiais'=>$special,
   'comprador_qualificacao'=>implode('; e ', array_map([$this, 'qualify'], $buyers)), 'comprador_nome'=>implode(' e ', array_map(fn($x) => $x['name'] ?? '', $buyers)),
   'vendedor_nome'=>$p['owner_name'] ?? '', 'vendedor_qualificacao'=>($p['owner_name'] ?? '').(!empty($p['owner_document']) ? ', inscrito(a) no '.(strlen(preg_replace('/\D/', '', $p['owner_document'])) > 11 ? 'CNPJ' : 'CPF').' sob nº '.$p['owner_document'] : ''),
   'imovel_descricao'=>$desc, 'imovel_endereco'=>$address, 'imovel_matricula'=>$p['registry_number'] ?? '', 'imovel_cartorio'=>$p['registry_office'] ?? '', 'imovel_iptu_inscricao'=>$p['iptu_number'] ?? '', 'imovel_condominio'=>$p['condo_fee'] ?? '',
  ];
  if ($sale) {
   $signal = round($price * (float)($cfg['signal_percent'] ?: 10) / 100, 2);
   $t += ['preco'=>(string)$price, 'sinal'=>(string)$signal, 'sinal_data'=>date('Y-m-d', strtotime('+3 days')), 'entrada'=>(string)($a['down_payment'] ?? ''), 'fgts'=>(string)($a['fgts_value'] ?? ''), 'financiado'=>(string)($a['approved_value'] && $a['payment_method'] !== 'cash' ? min((float)$a['approved_value'], (float)$a['financing_value']) : ($a['financing_value'] ?? '')),
    'banco'=>$a['bank_preference'] ?? '', 'prazo_financiamento'=>(string)($a['financing_term_months'] ?? ''), 'saldo_data'=>date('Y-m-d', strtotime('+'.((int)$cfg['deed_days'] ?: 60).' days')), 'prazo_escritura'=>$cfg['deed_days'] ?: '60',
    'posse'=>$a['payment_method'] === 'cash' ? 'na data da lavratura da escritura, após a quitação integral do preço' : 'em até 30 (trinta) dias após a liberação dos recursos do financiamento ao VENDEDOR',
    'multa'=>$cfg['penalty_percent'] ?: '10', 'comissao_percentual'=>(string)($p['commission_percent'] ?: $cfg['commission_percent'] ?: '6'), 'comissao_responsavel'=>mb_strtoupper($cfg['commission_payer'] ?: 'vendedor')];
  } else {
   $rent = (float)($a['approved_value'] ?: $a['rent_value']);
   $t += ['aluguel'=>(string)$rent, 'prazo_meses'=>(string)($a['lease_months'] ?: $cfg['lease_months'] ?: 30), 'inicio'=>$a['move_in_date'] ?: date('Y-m-d', strtotime('+7 days')), 'vencimento_dia'=>$cfg['lease_due_day'] ?: '10', 'indice'=>$cfg['lease_index'] ?: 'IGP-M/FGV',
    'garantia'=>$a['guarantee_type'] ?: 'none', 'garantia_valor'=>(string)($rent * (int)($cfg['deposit_months'] ?: 3)), 'multa_alugueis'=>$cfg['lease_penalty_rents'] ?: '3',
    'finalidade'=>(PropertyService::TYPES[$p['property_type']] ?? 'residential') === 'commercial' ? 'não residencial' : 'residencial',
    'fiador_qualificacao'=>!empty($people['guarantor']['name']) ? $this->qualify($people['guarantor']) : ''];
  }
  return $t;
 }

 private function defaultSigners(array $a, array $p, array $people): array {
  $sale = $a['business_type'] === 'purchase';
  $company = $this->settings->get('company');
  $s = [];
  $ap = $people['applicant'] ?? [];
  $s[] = ['role'=>$sale ? 'buyer' : 'tenant', 'name'=>$ap['name'] ?? '', 'email'=>$ap['email'] ?? '', 'doc'=>$this->cpf($ap['cpf'] ?? '')];
  if (($people['co_applicant']['relationship'] ?? '') === 'spouse' && !empty($people['co_applicant']['name'])) $s[] = ['role'=>'spouse', 'name'=>$people['co_applicant']['name'], 'email'=>$people['co_applicant']['email'] ?? '', 'doc'=>$this->cpf($people['co_applicant']['cpf'] ?? '')];
  $s[] = ['role'=>$sale ? 'seller' : 'landlord', 'name'=>$p['owner_name'] ?? '', 'email'=>$p['owner_email'] ?? '', 'doc'=>$p['owner_document'] ?? ''];
  if (!$sale && $a['guarantee_type'] === 'guarantor' && !empty($people['guarantor']['name'])) $s[] = ['role'=>'guarantor', 'name'=>$people['guarantor']['name'], 'email'=>$people['guarantor']['email'] ?? '', 'doc'=>$this->cpf($people['guarantor']['cpf'] ?? '')];
  $s[] = ['role'=>'agency', 'name'=>$company['legal_name'] ?: $company['name'], 'email'=>$company['email'] ?? '', 'doc'=>$company['cnpj'] ? 'CNPJ '.$company['cnpj'] : ''];
  return $s;
 }

 private function qualify(array $p): string {
  $marital = ['single'=>'solteiro(a)', 'married'=>'casado(a)', 'stable_union'=>'em união estável', 'divorced'=>'divorciado(a)', 'widowed'=>'viúvo(a)', 'separated'=>'separado(a)'][$p['marital_status'] ?? ''] ?? null;
  $regime = ['partial'=>'sob o regime da comunhão parcial de bens', 'universal'=>'sob o regime da comunhão universal de bens', 'separation'=>'sob o regime da separação total de bens', 'final_participation'=>'sob o regime da participação final nos aquestos'][$p['marriage_regime'] ?? ''] ?? null;
  $parts = [mb_strtoupper($p['name'] ?? ''), $p['nationality'] ?? 'brasileiro(a)', trim(($marital ?? '').($regime ? ' '.$regime : '')), $p['profession'] ?? null,
   !empty($p['rg']) ? 'portador(a) do RG nº '.$p['rg'].(!empty($p['rg_issuer']) ? ' '.$p['rg_issuer'] : '') : null, !empty($p['cpf']) ? 'inscrito(a) no CPF sob nº '.$this->cpf($p['cpf']) : null,
   !empty($p['address']) ? 'residente e domiciliado(a) em '.$p['address'].(!empty($p['city']) ? ', '.$p['city'].(!empty($p['state']) ? '/'.$p['state'] : '') : '') : null,
   !empty($p['email']) ? 'e-mail '.$p['email'] : null];
  return implode(', ', array_filter($parts, fn($x) => $x !== null && $x !== ''));
 }

 private function applyProviderState(array $c, string $status, array $recipients, ?string $userId): void {
  $signers = json_decode((string)$c['signers'], true) ?: [];
  foreach ($recipients as $r) foreach ($signers as &$s) {
   if (strcasecmp((string)($s['email'] ?? ''), (string)($r['email'] ?? '')) !== 0) continue;
   if (($r['signingStatus'] ?? '') === 'SIGNED') { $s['status'] = 'signed'; $s['signed_at'] = $r['signedAt'] ?? date('c'); }
   if (($r['signingStatus'] ?? '') === 'REJECTED') { $s['status'] = 'rejected'; $s['reason'] = $r['rejectionReason'] ?? null; }
  }
  unset($s);
  $this->write($c['id'], ['signers'=>json_encode($signers, JSON_UNESCAPED_UNICODE)]);
  if ($c['status'] !== 'sent') return;
  if ($status === 'COMPLETED') {
   $pdf = $this->signer?->downloadSigned($c['provider_envelope_id']);
   if (!$pdf || !str_starts_with($pdf, '%PDF')) throw new IntegrationException('O Documenso não devolveu o PDF assinado.');
   $name = bin2hex(random_bytes(24)).'.pdf';
   file_put_contents(private_storage().'/'.$name, $pdf); chmod(private_storage().'/'.$name, 0600);
   $this->finalize($this->find($c['id']), $name, hash('sha256', $pdf), $userId, 'Todas as partes assinaram pelo Documenso');
  } elseif ($status === 'REJECTED') {
   $this->write($c['id'], ['status'=>'declined']);
   $who = array_values(array_filter($signers, fn($s) => ($s['status'] ?? '') === 'rejected'))[0] ?? null;
   $this->event($c['id'], $userId, 'declined', $who ? $who['name'].' recusou: '.($who['reason'] ?? 'sem motivo informado') : null);
   $this->ops->notify($c['broker_id'], 'contract_declined:'.$c['id'], 'Contrato recusado', 'O contrato '.$c['code'].' foi recusado'.($who ? ' por '.$who['name'] : '').'.', 'contract', $c['id']);
  } elseif ($status === 'CANCELLED') {
   $this->write($c['id'], ['status'=>'cancelled', 'cancelled_at'=>date('Y-m-d H:i:s'), 'cancel_reason'=>'Cancelado no Documenso']);
   $this->event($c['id'], $userId, 'cancelled', 'Cancelado no Documenso');
  }
 }

 private function finalize(array $c, string $storageName, string $sha, ?string $userId, string $note): void {
  $this->write($c['id'], ['status'=>'signed', 'signed_at'=>date('Y-m-d H:i:s'), 'signed_storage_name'=>$storageName, 'signed_sha256'=>$sha]);
  $this->event($c['id'], $userId, 'signed', $note);
  $a = $this->credit($c['credit_application_id']);
  $q = $this->db->prepare('SELECT stage FROM opportunities WHERE id=?'); $q->execute([$c['opportunity_id']]);
  if (!in_array($q->fetchColumn(), ['won','lost'], true)) {
   try {
    $this->commercial->close($c['opportunity_id'], ['outcome'=>'won', 'closing_date'=>date('Y-m-d'), 'property_id'=>$c['property_id'], 'business_type'=>$a['business_type'], 'final_amount'=>$c['total_value'] ?: ($a['approved_value'] ?: $a['offer_value'] ?: $a['rent_value']), 'contract_reference'=>$c['code'], 'contract_status'=>'signed', 'notes'=>'Fechamento automático pela assinatura do contrato '.$c['code']], ['id'=>$c['broker_id'], 'role'=>'broker']);
    $this->event($c['id'], $userId, 'closing_registered', 'Oportunidade marcada como ganha e imóvel como '.($a['business_type'] === 'purchase' ? 'vendido' : 'alugado'));
   } catch (Throwable $e) { $this->event($c['id'], $userId, 'closing_failed', 'Registre o fechamento manualmente: '.$e->getMessage()); }
  }
  $msg = 'O contrato '.$c['code'].' ('.$a['applicant_name'].') foi assinado por todas as partes.';
  $this->ops->notify($c['broker_id'], 'contract_signed:'.$c['id'], 'Contrato assinado', $msg, 'contract', $c['id']);
  foreach ($this->db->query("SELECT id FROM users WHERE role IN ('admin','manager') AND active=1")->fetchAll(PDO::FETCH_COLUMN) as $m) $this->ops->notify($m, 'contract_signed:'.$c['id'], 'Contrato assinado', $msg, 'contract', $c['id']);
  $this->ops->audit($userId, 'contract_signed', 'contract', $c['id'], 'Contrato assinado', ['sha256'=>$sha]);
 }

 private function cleanSigners(array $list): array {
  $out = [];
  foreach ($list as $s) {
   $role = isset(self::SIGNER_ROLES[$s['role'] ?? '']) ? $s['role'] : 'other';
   $out[] = ['role'=>$role, 'name'=>mb_substr(trim((string)($s['name'] ?? '')), 0, 150), 'email'=>mb_strtolower(trim((string)($s['email'] ?? ''))), 'doc'=>mb_substr(trim((string)($s['doc'] ?? '')), 0, 40)];
  }
  if (count($out) < 2) throw new InvalidArgumentException('O contrato precisa de ao menos duas partes.');
  if (count($out) > 12) throw new InvalidArgumentException('Limite de 12 signatários.');
  return $out;
 }

 private function validateSigners(array $signers): void {
  $emails = [];
  foreach ($signers as $s) {
   if (trim($s['name'] ?? '') === '') throw new InvalidArgumentException('Todos os signatários precisam de nome.');
   if (!filter_var($s['email'] ?? '', FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Informe um e-mail válido para '.($s['name'] ?: 'todos os signatários').'. A assinatura é enviada por e-mail.');
   $e = mb_strtolower($s['email']);
   if (isset($emails[$e])) throw new InvalidArgumentException("O e-mail $e aparece para mais de um signatário. Cada parte precisa de um e-mail próprio.");
   $emails[$e] = true;
  }
 }

 private function total(string $key, array $t): ?float { $v = (float)($key === 'lease' ? ($t['aluguel'] ?? 0) : ($t['preco'] ?? 0)); return $v > 0 ? round($v, 2) : null; }
 private function cpf(string $v): string { $d = preg_replace('/\D/', '', $v); return strlen($d) === 11 ? substr($d, 0, 3).'.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-'.substr($d, 9) : $v; }
 public static function longDate(string $ymd): string {
  $ts = strtotime($ymd); if (!$ts) return $ymd;
  $months = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  return (int)date('j', $ts).' de '.$months[(int)date('n', $ts) - 1].' de '.date('Y', $ts);
 }

 private function assertAccess(array $c, array $u): void {
  $ok = in_array($u['role'], ['admin','manager'], true) || ($u['role'] === 'broker' && $c['broker_id'] === $u['id']);
  if (!$ok) throw new AuthorizationException('Você não tem acesso a este contrato.');
 }
 private function find(string $id): array { $q = $this->db->prepare('SELECT * FROM contracts WHERE id=?'); $q->execute([$id]); $c = $q->fetch(); if (!$c) throw new RuntimeException('Contrato não encontrado.'); return $c; }
 private function credit(string $id): array { $q = $this->db->prepare('SELECT * FROM credit_applications WHERE id=?'); $q->execute([$id]); $a = $q->fetch(); if (!$a) throw new RuntimeException('Ficha não encontrada.'); return $a; }
 private function property(string $id): array { $q = $this->db->prepare('SELECT * FROM properties WHERE id=?'); $q->execute([$id]); return $q->fetch() ?: throw new RuntimeException('Imóvel não encontrado.'); }
 private function write(string $id, array $data): void { $this->db->prepare('UPDATE contracts SET '.implode(',', array_map(fn($k) => "$k=?", array_keys($data))).',updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([...array_values($data), $id]); }
 private function event(string $id, ?string $userId, string $action, ?string $notes = null): void { $this->db->prepare('INSERT INTO contract_events(id,contract_id,user_id,action,notes,created_at) VALUES(?,?,?,?,?,?)')->execute([uid(), $id, $userId, $action, $notes, now_micro()]); }
 private function newCode(): string {
  $y = date('Y'); $q = $this->db->prepare('SELECT COUNT(*) FROM contracts WHERE code LIKE ?'); $q->execute(["CT-$y-%"]); $n = (int)$q->fetchColumn() + 1;
  do { $code = sprintf('CT-%s-%04d', $y, $n++); $q = $this->db->prepare('SELECT 1 FROM contracts WHERE code=?'); $q->execute([$code]); } while ($q->fetchColumn());
  return $code;
 }
}
