<?php
declare(strict_types=1);

/**
 * Endpoints públicos do site: vitrine de imóveis publicados, formulário de interesse e chat do visitante.
 * Nada aqui exige login; por isso só campos públicos saem e toda entrada passa por limite de taxa.
 * O chat do visitante vira uma conversa 'site_chat' na fila humana da Central de Conversas e um lead de origem "site".
 */
final class SiteService {
 private const PUBLIC_FIELDS = ['id','reference_code','title','property_type','purpose','status','price','rental_price','condo_fee','iptu_yearly','area_total','area_built','bedrooms','suites','bathrooms','parking_spaces','floor_number','year_built','furnished','accepts_pets','accepts_financing','accepts_exchange','description','neighborhood','city','state','condo_name'];
 private const VISIBLE = "p.listing_status='approved' AND p.status='available'";

 public function __construct(private PDO $db, private LeadService $leads, private SettingsService $settings, private string $publicBase) {}

 public function info(): array {
  $company = $this->settings->get('company'); $site = $this->settings->get('site');
  return ['company'=>$company, 'site'=>$site];
 }

 public function list(array $f): array {
  [$where, $args, $priceExpr] = $this->where($f);
  $order = match ($f['sort'] ?? '') { 'price_asc' => "$priceExpr ASC", 'price_desc' => "$priceExpr DESC", default => 'COALESCE(p.reviewed_at,p.created_at) DESC' };
  $perPage = min(24, max(1, (int)($f['per_page'] ?? 12))); $page = max(1, (int)($f['page'] ?? 1));
  $q = $this->db->prepare('SELECT COUNT(*) FROM properties p WHERE '.$where); $q->execute($args); $total = (int)$q->fetchColumn();
  $q = $this->db->prepare('SELECT p.* FROM properties p WHERE '.$where.' ORDER BY '.$order.',p.id LIMIT '.$perPage.' OFFSET '.(($page - 1) * $perPage));
  $q->execute($args); $rows = $q->fetchAll();
  $photos = $this->photos(array_column($rows, 'id'), true);
  return ['data'=>array_map(fn($r) => $this->card($r, $photos[$r['id']] ?? []), $rows), 'page'=>$page, 'per_page'=>$perPage, 'total'=>$total];
 }

 public function get(string $id): array {
  $q = $this->db->prepare('SELECT p.* FROM properties p WHERE p.id=? AND '.self::VISIBLE); $q->execute([$id]); $p = $q->fetch();
  if (!$p) throw new RuntimeException('Este imóvel não está mais disponível.');
  $out = $this->card($p, $this->photos([$id], false)[$id] ?? []);
  $out['description'] = $p['description'];
  foreach (['features','condo_features'] as $k) $out[$k] = $p[$k] ? (json_decode((string)$p[$k], true) ?: []) : [];
  if ((int)$p['show_full_address'] === 1) { $out['street'] = $p['street']; $out['street_number'] = $p['street_number']; }
  $similar = $this->list(['purpose'=>$p['purpose'] === 'rental' ? 'rental' : 'purchase', 'city'=>$p['city'], 'per_page'=>4]);
  $out['similar'] = array_values(array_slice(array_filter($similar['data'], fn($x) => $x['id'] !== $id), 0, 3));
  return $out;
 }

 public function filters(): array {
  $types = $this->db->query('SELECT p.property_type value,COUNT(*) total FROM properties p WHERE '.self::VISIBLE.' GROUP BY p.property_type ORDER BY total DESC')->fetchAll();
  $places = $this->db->query('SELECT p.city,p.neighborhood,COUNT(*) total FROM properties p WHERE '.self::VISIBLE." AND p.city IS NOT NULL AND p.city<>'' GROUP BY p.city,p.neighborhood ORDER BY p.city,p.neighborhood")->fetchAll();
  $cities = [];
  foreach ($places as $r) { $cities[$r['city']]['city'] = $r['city']; $cities[$r['city']]['total'] = ($cities[$r['city']]['total'] ?? 0) + (int)$r['total']; if ($r['neighborhood']) $cities[$r['city']]['neighborhoods'][] = $r['neighborhood']; }
  return ['types'=>$types, 'cities'=>array_values($cities)];
 }

 /** Formulário "Tenho interesse" do site: cria o lead no funil do SDR. */
 public function lead(array $d, string $ip): array {
  rate_limit('site-lead:'.$ip, 5, 600);
  if (!empty($d['website'])) return ['received'=>true]; // honeypot: robôs preenchem campos invisíveis
  $contact = $this->visitor($d);
  $property = !empty($d['property_id']) ? $this->visibleProperty((string)$d['property_id']) : null;
  $message = mb_substr(trim((string)($d['message'] ?? '')), 0, 1500);
  $lead = $this->createLead($contact, $property, 'Formulário do site'.($message !== '' ? "\nMensagem: $message" : ''), $d['interest_type'] ?? null, 'form');
  return ['received'=>true, 'lead_id'=>$lead['id']];
 }

 public function chatStart(array $d, string $ip): array {
  rate_limit('site-chat-start:'.$ip, 4, 600);
  if (!($this->settings->get('site')['chat_enabled'] ?? false)) throw new DomainException('O chat está indisponível no momento. Use o formulário de contato.');
  if (!empty($d['website'])) throw new InvalidArgumentException('Não foi possível iniciar o atendimento.');
  $contact = $this->visitor($d);
  $message = $this->messageBody($d['message'] ?? '');
  $property = !empty($d['property_id']) ? $this->visibleProperty((string)$d['property_id']) : null;
  $lead = $this->createLead($contact, $property, 'Chat do site', $d['interest_type'] ?? null, 'chat');
  $token = bin2hex(random_bytes(24)); $id = uid();
  $this->db->prepare("INSERT INTO conversations(id,provider,external_id,phone,contact_id,opportunity_id,status,visitor_token_hash,property_id) VALUES(?,'site_chat',?,?,?,?,'human',?,?)")
   ->execute([$id, 'site-'.$id, $contact['phone'] ?: null, $lead['contact_id'], $lead['id'], hash('sha256', $token), $property['id'] ?? null]);
  $this->insertMessage($id, 'in', $message);
  $greeting = trim((string)($this->settings->get('site')['chat_greeting'] ?? ''));
  if ($greeting !== '') $this->insertMessage($id, 'out', $greeting);
  return ['token'=>$token] + $this->chatState($id);
 }

 public function chatSend(string $token, string $body, string $ip): array {
  rate_limit('site-chat-msg:'.$ip, 30, 60);
  $c = $this->conversation($token);
  if ($c['status'] === 'closed') throw new DomainException('Este atendimento foi encerrado. Inicie uma nova conversa.');
  $this->insertMessage($c['id'], 'in', $this->messageBody($body));
  $this->db->prepare("UPDATE conversations SET status=CASE WHEN status='waiting' THEN 'human' ELSE status END,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$c['id']]);
  return $this->chatState($c['id']);
 }

 public function chatPoll(string $token, string $ip): array {
  rate_limit('site-chat-poll:'.$ip, 120, 60);
  return $this->chatState($this->conversation($token)['id']);
 }

 private function chatState(string $id): array {
  $q = $this->db->prepare('SELECT c.status,u.name agent FROM conversations c LEFT JOIN users u ON u.id=c.assigned_to WHERE c.id=?'); $q->execute([$id]); $c = $q->fetch();
  $q = $this->db->prepare('SELECT m.id,m.direction,m.body,m.created_at,m.sender_user_id,u.name sender FROM conversation_messages m LEFT JOIN users u ON u.id=m.sender_user_id WHERE m.conversation_id=? ORDER BY m.created_at,m.id LIMIT 300');
  $q->execute([$id]);
  $first = fn(?string $n) => $n ? explode(' ', trim($n))[0] : null;
  $messages = array_map(fn($m) => ['id'=>$m['id'], 'from'=>$m['direction'] === 'in' ? 'visitor' : ($m['sender_user_id'] ? 'agent' : 'system'), 'body'=>$m['body'], 'created_at'=>$m['created_at'], 'agent'=>$first($m['sender'])], $q->fetchAll());
  return ['status'=>$c['status'] === 'closed' ? 'closed' : 'open', 'agent'=>$first($c['agent']), 'messages'=>$messages];
 }

 private function conversation(string $token): array {
  if (!preg_match('/^[a-f0-9]{48}$/', $token)) throw new RuntimeException('Conversa não encontrada.');
  $q = $this->db->prepare("SELECT * FROM conversations WHERE visitor_token_hash=? AND provider='site_chat'"); $q->execute([hash('sha256', $token)]);
  $c = $q->fetch(); if (!$c) throw new RuntimeException('Conversa não encontrada.');
  return $c;
 }

 private function insertMessage(string $conversationId, string $direction, string $body): void {
  $this->db->prepare("INSERT INTO conversation_messages(id,conversation_id,provider,external_message_id,direction,body,status,created_at) VALUES(?,?,'site_chat',?,?,?,?,?)")
   ->execute([uid(), $conversationId, 'site-'.uid(), $direction, $body, $direction === 'in' ? 'received' : 'sent', now_micro()]);
  $this->db->prepare('UPDATE conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$conversationId]);
 }

 private function messageBody(mixed $body): string {
  $body = trim((string)$body);
  if ($body === '') throw new InvalidArgumentException('Escreva sua mensagem.');
  return mb_substr($body, 0, 2000);
 }

 private function visitor(array $d): array {
  $name = trim((string)($d['name'] ?? '')); $phone = preg_replace('/[^\d+]/', '', (string)($d['phone'] ?? '')); $email = trim((string)($d['email'] ?? ''));
  if (mb_strlen($name) < 2) throw new InvalidArgumentException('Informe seu nome.');
  if ($phone === '' && $email === '') throw new InvalidArgumentException('Informe telefone ou e-mail para retornarmos.');
  if ($phone !== '' && (strlen(ltrim($phone, '+')) < 10 || strlen($phone) > 16)) throw new InvalidArgumentException('Telefone inválido. Inclua o DDD.');
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail inválido.');
  if (empty($d['consent']) || !filter_var($d['consent'], FILTER_VALIDATE_BOOLEAN)) throw new InvalidArgumentException('É preciso autorizar o contato para continuar.');
  return ['name'=>mb_substr($name, 0, 120), 'phone'=>$phone, 'email'=>$email];
 }

 private function createLead(array $contact, ?array $property, string $note, ?string $interest, string $channel): array {
  $source = $this->db->query("SELECT id FROM lead_sources WHERE code='site'")->fetchColumn();
  if (!$source) throw new RuntimeException('Origem "site" não cadastrada.');
  $interest = in_array($interest, ['purchase','rental'], true) ? $interest : (($property['purpose'] ?? '') === 'rental' ? 'rental' : 'purchase');
  $details = $note.($property ? "\nImóvel: {$property['reference_code']} – {$property['title']}" : '');
  $base = ['name'=>$contact['name'], 'source_id'=>$source, 'interest_type'=>$interest, 'external_id'=>$channel.'-'.uid(), 'region'=>$property['neighborhood'] ?? null, 'property_interest'=>mb_substr($details, 0, 2000)];
  // e-mail e telefone podem apontar para contatos diferentes; nesse caso o lead entra sem vínculo e com os dados na observação
  foreach ([['email'=>$contact['email'] ?: null, 'phone'=>$contact['phone'] ?: null], ['phone'=>$contact['phone'] ?: null], []] as $attempt) {
   try {
    $extra = $attempt ? '' : "\nContato informado: {$contact['phone']} {$contact['email']}";
    return $this->leads->create($base + $attempt + ['property_interest'=>$base['property_interest'].$extra]);
   } catch (DomainException $e) { if (!str_contains($e->getMessage(), 'ambíguo')) throw $e; }
  }
  throw new RuntimeException('Não foi possível registrar o contato.');
 }

 private function visibleProperty(string $id): array {
  $q = $this->db->prepare('SELECT p.* FROM properties p WHERE p.id=? AND '.self::VISIBLE); $q->execute([$id]); $p = $q->fetch();
  if (!$p) throw new InvalidArgumentException('Imóvel indisponível.');
  return $p;
 }

 private function where(array $f): array {
  $where = [self::VISIBLE]; $args = [];
  $purpose = $f['purpose'] ?? '';
  if ($purpose === 'purchase' || $purpose === 'rental') { $where[] = "(p.purpose=? OR p.purpose='both')"; $args[] = $purpose; }
  $priceExpr = $purpose === 'rental' ? "(CASE WHEN p.purpose='rental' THEN p.price ELSE p.rental_price END)" : 'p.price';
  if (!empty($f['type'])) { $where[] = 'p.property_type=?'; $args[] = $f['type']; }
  if (!empty($f['city'])) { $where[] = 'p.city=?'; $args[] = $f['city']; }
  if (!empty($f['neighborhood'])) { $where[] = 'p.neighborhood=?'; $args[] = $f['neighborhood']; }
  if (!empty($f['bedrooms'])) { $where[] = 'p.bedrooms>=?'; $args[] = (int)$f['bedrooms']; }
  if (!empty($f['parking'])) { $where[] = 'p.parking_spaces>=?'; $args[] = (int)$f['parking']; }
  if (!empty($f['min_price'])) { $where[] = "$priceExpr>=CAST(? AS DECIMAL(13,2))"; $args[] = (float)$f['min_price']; }
  if (!empty($f['max_price'])) { $where[] = "$priceExpr<=CAST(? AS DECIMAL(13,2))"; $args[] = (float)$f['max_price']; }
  if (!empty($f['q'])) { $where[] = '(p.title LIKE ? OR p.neighborhood LIKE ? OR p.city LIKE ? OR p.reference_code=?)'; array_push($args, '%'.$f['q'].'%', '%'.$f['q'].'%', '%'.$f['q'].'%', $f['q']); }
  return [implode(' AND ', $where), $args, $priceExpr];
 }

 private function card(array $p, array $photos): array {
  $out = array_intersect_key($p, array_flip(self::PUBLIC_FIELDS));
  $out['neighborhood'] = $p['neighborhood'] ?: $p['region']; // cadastros antigos só têm a região
  $out['rent'] = $p['purpose'] === 'rental' ? $p['price'] : $p['rental_price'];
  $out['sale'] = $p['purpose'] === 'rental' ? null : $p['price'];
  $out['photos'] = $photos;
  $out['cover_url'] = $photos[0]['url'] ?? null;
  return $out;
 }

 /** Capa primeiro, depois a ordem do guia de fotos (fachada, sala, cozinha…). */
 private function photos(array $ids, bool $coverOnly): array {
  if (!$ids) return [];
  $q = $this->db->prepare('SELECT ph.*,p.property_type,p.bedrooms,p.bathrooms FROM property_photos ph JOIN properties p ON p.id=ph.property_id WHERE ph.property_id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY ph.property_id,ph.is_cover DESC,ph.sort_order');
  $q->execute($ids); $out = [];
  foreach ($q->fetchAll() as $x) {
   if ($coverOnly && isset($out[$x['property_id']])) continue;
   $guide = PropertyService::photoGuide($x);
   [$label, $rank] = [null, 999];
   foreach ($guide['required'] as $i => $s) if ($s['slot'] === $x['slot']) [$label, $rank] = [$s['label'], $i];
   foreach ($guide['optional'] as $i => $o) if ($label === null && $o['category'] === $x['category']) [$label, $rank] = [preg_replace('/^(Condomínio|Prédio): /', '', $o['label']), 100 + $i];
   $out[$x['property_id']][] = ['url'=>$this->publicBase.'/uploads/properties/'.$x['property_id'].'/'.$x['storage_name'], 'label'=>$x['caption'] ?: $label, 'width'=>$x['width'] === null ? null : (int)$x['width'], 'height'=>$x['height'] === null ? null : (int)$x['height'], 'rank'=>$x['is_cover'] ? -1 : $rank];
  }
  foreach ($out as $pid => $list) { // capa primeiro, depois a sequência de um tour pelo imóvel
   usort($list, fn($a, $b) => $a['rank'] <=> $b['rank']);
   $out[$pid] = array_map(fn($x) => array_diff_key($x, ['rank'=>1]), $list);
  }
  return $out;
 }
}
