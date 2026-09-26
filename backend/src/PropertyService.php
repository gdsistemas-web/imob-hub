<?php
declare(strict_types=1);

/**
 * Cadastro completo de imóveis, guia de fotos por cômodo e moderação de anúncios.
 * Fluxo do anúncio: draft → pending_review → approved | rejected (→ corrigido → pending_review).
 * O status comercial (available/reserved/sold/rented/inactive) continua independente do anúncio.
 */
final class PropertyService {
 public const TYPES = [
  'Apartamento'=>'residential','Casa'=>'residential','Casa em condomínio'=>'residential','Cobertura'=>'residential','Sobrado'=>'residential','Studio/Kitnet'=>'residential',
  'Sala Comercial'=>'commercial','Loja'=>'commercial','Galpão'=>'commercial','Prédio comercial'=>'commercial',
  'Terreno'=>'land','Lote em condomínio'=>'land',
  'Chácara/Sítio'=>'rural',
 ];
 public const CONDO_TYPES = ['Apartamento','Casa em condomínio','Cobertura','Studio/Kitnet','Sala Comercial','Lote em condomínio'];
 private const TEXT = ['reference_code','title','property_type','description','zip_code','street','street_number','complement','neighborhood','city','state','condo_name','owner_name','owner_document','owner_phone','owner_email','registry_number','registry_office','iptu_number','keys_location','available_from'];
 private const MONEY = ['price','rental_price','condo_fee','iptu_yearly','commission_percent','area_total','area_built'];
 private const INTS = ['bedrooms','suites','bathrooms','parking_spaces','floor_number','year_built'];
 private const BOOLS = ['accepts_pets','accepts_financing','accepts_exchange','show_full_address','exclusive_listing'];
 private const PRIVATE = ['owner_name','owner_document','owner_phone','owner_email','registry_number','registry_office','iptu_number','keys_location','commission_percent'];
 private const MAX_PHOTOS = 60;

 public function __construct(private PDO $db, private OperationsService $ops, private string $uploadDir, private string $publicBase) {}

 /** Cômodos exigidos e opcionais conforme tipo e quantidades informadas. */
 public static function photoGuide(array $p): array {
  $group = self::TYPES[$p['property_type'] ?? ''] ?? 'residential';
  $slot = fn(string $category, string $label, string $hint) => ['slot'=>$category, 'category'=>$category, 'label'=>$label, 'hint'=>$hint];
  $repeat = function (string $category, string $label, string $hint, $count) {
   $n = min(10, max(0, (int)$count)); $out = [];
   for ($i = 1; $i <= $n; $i++) $out[] = ['slot'=>"{$category}_{$i}", 'category'=>$category, 'label'=>$n > 1 ? "$label $i" : $label, 'hint'=>$hint];
   return $out;
  };
  $required = match ($group) {
   'land' => [
    $slot('front', 'Frente do terreno', 'Foto da rua mostrando a testada inteira do terreno.'),
    $slot('overview', 'Visão geral', 'De um ponto alto ou do fundo, mostrando a área toda.'),
   ],
   'commercial' => [
    $slot('facade', 'Fachada', 'Frente do imóvel/prédio com a entrada visível.'),
    $slot('main_area', 'Área principal', 'Do canto do ambiente, em modo paisagem, mostrando o espaço inteiro.'),
    ...$repeat('bathroom', 'Banheiro', 'Enquadre pia e vaso; porta aberta e luz acesa.', $p['bathrooms'] ?? 0),
   ],
   default => [
    $slot('facade', 'Fachada', $group === 'residential' && in_array($p['property_type'] ?? '', self::CONDO_TYPES, true) ? 'Frente do prédio/condomínio ou portaria.' : 'Frente do imóvel, da calçada, com boa luz.'),
    $slot('living_room', 'Sala', 'Do canto oposto à janela, em modo paisagem.'),
    $slot('kitchen', 'Cozinha', 'Mostre bancada, armários e ponto de fogão.'),
    ...$repeat('bedroom', 'Quarto', 'Do batente da porta, mostrando cama/armários.', $p['bedrooms'] ?? 0),
    ...$repeat('bathroom', 'Banheiro', 'Enquadre pia, box e vaso; tampa abaixada.', $p['bathrooms'] ?? 0),
    $slot('laundry', 'Área de serviço', 'Tanque e espaço para máquina.'),
    ...($group === 'rural' ? [$slot('overview', 'Área externa', 'Visão geral do terreno e da sede.')] : []),
   ],
  };
  $optional = match ($group) {
   'land' => [['surroundings','Entorno'],['access','Acesso/rua'],['other','Outras']],
   'commercial' => [['reception','Recepção'],['kitchen','Copa'],['parking','Estacionamento'],['loading','Docas/carga'],['view','Vista'],['condo_lobby','Prédio: portaria/hall'],['other','Outras']],
   default => [['balcony','Sacada/varanda'],['gourmet','Espaço gourmet'],['closet','Closet'],['office','Escritório'],['garage','Garagem'],['backyard','Quintal/jardim'],['pool','Piscina privativa'],['view','Vista'],
    ['condo_pool','Condomínio: piscina'],['condo_gym','Condomínio: academia'],['condo_party','Condomínio: salão de festas'],['condo_playground','Condomínio: playground'],['condo_lobby','Condomínio: portaria/hall'],['condo_other','Condomínio: outras áreas'],['other','Outras']],
  };
  return ['group'=>$group, 'required'=>$required, 'optional'=>array_map(fn($o) => ['category'=>$o[0], 'label'=>$o[1]], $optional)];
 }

 /** Itens que impedem o envio para aprovação. */
 public static function missing(array $p, array $photos): array {
  $group = self::TYPES[$p['property_type'] ?? ''] ?? 'residential';
  $empty = fn($k) => !isset($p[$k]) || trim((string)$p[$k]) === '';
  $out = [];
  $need = ['property_type'=>'Tipo do imóvel','purpose'=>'Finalidade','title'=>'Título do anúncio','zip_code'=>'CEP','street'=>'Rua','street_number'=>'Número','neighborhood'=>'Bairro','city'=>'Cidade','state'=>'UF','owner_name'=>'Nome do proprietário','owner_phone'=>'Telefone do proprietário'];
  if ($group !== 'land') $need += ['bathrooms'=>'Banheiros','parking_spaces'=>'Vagas'];
  if (in_array($group, ['residential','rural'], true)) $need += ['bedrooms'=>'Quartos'];
  if (in_array($p['property_type'] ?? '', self::CONDO_TYPES, true)) $need += ['condo_fee'=>'Valor do condomínio (0 se não houver)'];
  foreach ($need as $k => $label) if ($empty($k)) $out[] = $label;
  if ((float)($p['price'] ?? 0) <= 0) $out[] = ($p['purpose'] ?? '') === 'rental' ? 'Valor do aluguel' : 'Valor de venda';
  if (($p['purpose'] ?? '') === 'both' && (float)($p['rental_price'] ?? 0) <= 0) $out[] = 'Valor do aluguel';
  if ($group === 'land' ? (float)($p['area_total'] ?? 0) <= 0 : (float)($p['area_built'] ?? 0) <= 0) $out[] = $group === 'land' ? 'Área total' : 'Área útil/construída';
  if (mb_strlen(trim((string)($p['description'] ?? ''))) < 60) $out[] = 'Descrição com ao menos 60 caracteres';
  $filled = array_flip(array_column($photos, 'slot'));
  foreach (self::photoGuide($p)['required'] as $s) if (!isset($filled[$s['slot']])) $out[] = 'Foto: '.$s['label'];
  return $out;
 }

 public function list(array $u, array $f): array {
  [$where, $args] = $this->scope($u);
  if (!empty($f['listing_status'])) { $where[] = 'p.listing_status=?'; $args[] = $f['listing_status']; }
  if (!empty($f['purpose'])) { $where[] = "(p.purpose=? OR p.purpose='both')"; $args[] = $f['purpose']; }
  if (!empty($f['mine'])) { $where[] = 'p.created_by=?'; $args[] = $u['id']; }
  if (!empty($f['available'])) $where[] = "p.status IN ('available','reserved')";
  if (!empty($f['q'])) { $where[] = '(p.title LIKE ? OR p.reference_code LIKE ? OR p.neighborhood LIKE ? OR p.region LIKE ?)'; array_push($args, ...array_fill(0, 4, '%'.$f['q'].'%')); }
  $sql = $where ? ' WHERE '.implode(' AND ', $where) : '';
  $perPage = min(100, max(1, (int)($f['per_page'] ?? 20))); $page = max(1, (int)($f['page'] ?? 1));
  $q = $this->db->prepare('SELECT COUNT(*) FROM properties p'.$sql); $q->execute($args); $total = (int)$q->fetchColumn();
  $q = $this->db->prepare('SELECT p.*,u.name creator_name FROM properties p LEFT JOIN users u ON u.id=p.created_by'.$sql.' ORDER BY p.updated_at DESC LIMIT '.$perPage.' OFFSET '.(($page - 1) * $perPage));
  $q->execute($args); $rows = $q->fetchAll();
  $photos = $this->photosFor(array_column($rows, 'id'));
  $rows = array_map(fn($r) => $this->present($r, $photos[$r['id']] ?? [], $u), $rows);
  return ['data'=>$rows, 'page'=>$page, 'per_page'=>$perPage, 'total'=>$total, 'counts'=>$this->counts($u, !empty($f['mine']))];
 }

 public function counts(array $u, bool $mine = false): array {
  [$where, $args] = $this->scope($u);
  if ($mine) { $where[] = 'p.created_by=?'; $args[] = $u['id']; }
  $q = $this->db->prepare('SELECT p.listing_status,COUNT(*) total FROM properties p'.($where ? ' WHERE '.implode(' AND ', $where) : '').' GROUP BY p.listing_status');
  $q->execute($args); $out = ['draft'=>0,'pending_review'=>0,'approved'=>0,'rejected'=>0,'archived'=>0];
  foreach ($q->fetchAll() as $r) $out[$r['listing_status']] = (int)$r['total'];
  return $out;
 }

 public function get(string $id, array $u): array {
  $p = $this->find($id);
  if (!$this->canView($p, $u)) throw new AuthorizationException('Você não tem acesso a este imóvel.');
  $photos = $this->photosFor([$id])[$id] ?? [];
  $out = $this->present($p, $photos, $u);
  $q = $this->db->prepare('SELECT name FROM users WHERE id=?'); $q->execute([$p['created_by'] ?? '']); $out['creator_name'] = $q->fetchColumn() ?: null;
  $q = $this->db->prepare('SELECT e.action,e.notes,e.created_at,u.name user_name FROM property_review_events e JOIN users u ON u.id=e.user_id WHERE e.property_id=? ORDER BY e.created_at DESC');
  $q->execute([$id]); $out['events'] = $q->fetchAll();
  $out['guide'] = self::photoGuide($p);
  $out['missing'] = self::missing($p, $photos);
  $out['can_edit'] = $this->canEdit($p, $u);
  $out['can_review'] = in_array($u['role'], ['admin','manager'], true);
  return $out;
 }

 public function create(array $d, array $u): array {
  validate_required($d, ['property_type','purpose']);
  $this->checkEnums($d);
  $id = uid();
  $moderator = in_array($u['role'], ['admin','manager'], true);
  $data = $this->clean($d, $moderator);
  $data['reference_code'] = $data['reference_code'] ?? $this->newReference();
  $data['title'] = $data['title'] ?? $d['property_type'].' para '.(['purchase'=>'venda','rental'=>'locação','both'=>'venda e locação'][$d['purpose']]);
  $data['purpose'] = $d['purpose'];
  $data['status'] = $moderator ? ($d['status'] ?? 'available') : 'available';
  $data['listing_status'] = $moderator && !empty($d['publish']) ? 'approved' : 'draft';
  $data['created_by'] = $u['id'];
  if ($data['listing_status'] === 'approved') { $data['reviewed_by'] = $u['id']; $data['reviewed_at'] = date('Y-m-d H:i:s'); }
  $this->assertUniqueReference($data['reference_code'], null);
  $cols = array_keys($data);
  $this->db->prepare('INSERT INTO properties(id,'.implode(',', $cols).') VALUES(?'.str_repeat(',?', count($cols)).')')->execute([$id, ...array_values($data)]);
  $this->event($id, $u, 'created');
  $this->ops->audit($u['id'], 'property_created', 'property', $id, 'Imóvel cadastrado ('.$data['reference_code'].')');
  return $this->get($id, $u);
 }

 public function update(string $id, array $d, array $u): array {
  $p = $this->find($id);
  if (!$this->canEdit($p, $u)) throw new AuthorizationException($p['listing_status'] === 'pending_review' ? 'O anúncio está em análise e não pode ser alterado agora.' : 'Você não pode alterar este imóvel.');
  $this->checkEnums($d + ['purpose'=>$p['purpose']]);
  $moderator = in_array($u['role'], ['admin','manager'], true);
  $data = $this->clean($d, $moderator);
  if (isset($d['purpose'])) $data['purpose'] = $d['purpose'];
  if (isset($data['price']) && $data['price'] <= 0) throw new InvalidArgumentException('O valor deve ser positivo.');
  if (isset($data['reference_code'])) $this->assertUniqueReference($data['reference_code'], $id);
  if ($moderator && isset($d['status'])) $data['status'] = $d['status'];
  if (!$data) return $this->get($id, $u);
  $reReview = !$moderator && $p['listing_status'] === 'approved';
  if ($reReview) { $data['listing_status'] = 'pending_review'; $data['submitted_at'] = date('Y-m-d H:i:s'); }
  $this->write($id, $data);
  if ($reReview) { $this->event($id, $u, 'edited_after_approval', 'Anúncio alterado pelo corretor; volta para análise.'); $this->notifyModerators($id, $p['title'], $u); }
  $this->ops->audit($u['id'], 'property_updated', 'property', $id, 'Imóvel atualizado', ['fields'=>array_keys($data)]);
  return $this->get($id, $u);
 }

 public function submit(string $id, array $u): array {
  $p = $this->find($id);
  if (!$this->canEdit($p, $u)) throw new AuthorizationException('Você não pode enviar este anúncio.');
  if (!in_array($p['listing_status'], ['draft','rejected'], true)) throw new DomainException('Somente rascunhos ou anúncios reprovados podem ser enviados para análise.');
  $missing = self::missing($p, $this->photosFor([$id])[$id] ?? []);
  if ($missing) throw new InvalidArgumentException('Complete antes de enviar: '.implode('; ', $missing).'.');
  $this->write($id, ['listing_status'=>'pending_review', 'submitted_at'=>date('Y-m-d H:i:s')]);
  $this->event($id, $u, 'submitted');
  $this->notifyModerators($id, $p['title'], $u);
  $this->ops->audit($u['id'], 'property_submitted', 'property', $id, 'Anúncio enviado para aprovação');
  return $this->get($id, $u);
 }

 public function review(string $id, string $decision, ?string $notes, array $u): array {
  if (!in_array($u['role'], ['admin','manager'], true)) throw new AuthorizationException('Somente gestor ou administrador aprova anúncios.');
  $p = $this->find($id);
  if ($decision === 'archive') {
   $this->write($id, ['listing_status'=>'archived', 'reviewed_by'=>$u['id'], 'reviewed_at'=>date('Y-m-d H:i:s'), 'review_notes'=>$notes]);
   $this->event($id, $u, 'archived', $notes);
  } else {
   if (!in_array($decision, ['approve','reject'], true)) throw new InvalidArgumentException('Decisão inválida.');
   if ($p['listing_status'] !== 'pending_review') throw new DomainException('Este anúncio não está aguardando análise.');
   if ($decision === 'reject' && trim((string)$notes) === '') throw new InvalidArgumentException('Informe o motivo da reprovação para o corretor corrigir.');
   if ($decision === 'approve' && ($missing = self::missing($p, $this->photosFor([$id])[$id] ?? []))) throw new InvalidArgumentException('O anúncio ainda tem pendências: '.implode('; ', $missing).'.');
   $status = $decision === 'approve' ? 'approved' : 'rejected';
   $this->write($id, ['listing_status'=>$status, 'reviewed_by'=>$u['id'], 'reviewed_at'=>date('Y-m-d H:i:s'), 'review_notes'=>$notes]);
   $this->event($id, $u, $status, $notes);
  }
  if ($p['created_by'] && $p['created_by'] !== $u['id']) {
   [$title, $msg] = match ($decision) {
    'approve' => ['Anúncio aprovado', '"'.$p['title'].'" foi aprovado e já está publicado.'],
    'reject' => ['Anúncio reprovado', '"'.$p['title'].'" precisa de ajustes: '.mb_substr((string)$notes, 0, 300)],
    default => ['Anúncio arquivado', '"'.$p['title'].'" foi arquivado.'],
   };
   $this->ops->notify($p['created_by'], 'property_review:'.$id.':'.uid(), $title, $msg, 'property', $id);
  }
  $this->ops->audit($u['id'], 'property_'.$decision, 'property', $id, 'Moderação de anúncio: '.$decision);
  return $this->get($id, $u);
 }

 public function addPhoto(string $id, array $file, string $slot, ?string $caption, array $u): array {
  $p = $this->find($id);
  if (!$this->canEdit($p, $u)) throw new AuthorizationException('Você não pode alterar as fotos deste imóvel.');
  $guide = self::photoGuide($p);
  $category = $this->categoryFor($slot, $guide);
  $q = $this->db->prepare('SELECT COUNT(*) FROM property_photos WHERE property_id=?'); $q->execute([$id]); $count = (int)$q->fetchColumn();
  if ($count >= self::MAX_PHOTOS) throw new InvalidArgumentException('Limite de '.self::MAX_PHOTOS.' fotos por imóvel.');
  [$name, $w, $h] = $this->storeImage($file, $id);
  $photoId = uid();
  $this->db->prepare('INSERT INTO property_photos(id,property_id,storage_name,original_name,sort_order,is_cover,category,slot,caption,width,height,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$photoId, $id, $name, mb_substr(basename((string)($file['name'] ?? 'foto')), 0, 255), $count, $count === 0 ? 1 : 0, $category, $slot, $caption ? mb_substr($caption, 0, 160) : null, $w, $h, $u['id']]);
  if ($slot === 'facade' || $slot === 'front') $this->makeCover($id, $photoId, false);
  $this->afterMediaChange($p, $u);
  return $this->get($id, $u);
 }

 public function removePhoto(string $id, string $photoId, array $u): array {
  $p = $this->find($id);
  if (!$this->canEdit($p, $u)) throw new AuthorizationException('Você não pode alterar as fotos deste imóvel.');
  $q = $this->db->prepare('SELECT * FROM property_photos WHERE id=? AND property_id=?'); $q->execute([$photoId, $id]); $photo = $q->fetch();
  if (!$photo) throw new RuntimeException('Foto não encontrada.');
  $file = $this->uploadDir.'/'.$id.'/'.$photo['storage_name'];
  if (is_file($file)) unlink($file);
  $this->db->prepare('DELETE FROM property_photos WHERE id=?')->execute([$photoId]);
  if ($photo['is_cover']) {
   $q = $this->db->prepare('SELECT id FROM property_photos WHERE property_id=? ORDER BY sort_order LIMIT 1'); $q->execute([$id]);
   if ($next = $q->fetchColumn()) $this->makeCover($id, $next, false);
  }
  $this->afterMediaChange($p, $u);
  return $this->get($id, $u);
 }

 public function setCover(string $id, string $photoId, array $u): array {
  $p = $this->find($id);
  if (!$this->canEdit($p, $u)) throw new AuthorizationException('Você não pode alterar as fotos deste imóvel.');
  $this->makeCover($id, $photoId, true);
  return $this->get($id, $u);
 }

 /** Remove dados do proprietário e documentação para quem não é dono do cadastro nem moderador. */
 public function present(array $p, array $photos, array $u): array {
  foreach (['features','condo_features'] as $k) $p[$k] = $p[$k] ? (json_decode((string)$p[$k], true) ?: []) : [];
  $p['photos'] = $photos;
  $p['photo_count'] = count($photos);
  $cover = array_values(array_filter($photos, fn($x) => $x['is_cover']))[0] ?? ($photos[0] ?? null);
  $p['cover_url'] = $cover['url'] ?? null;
  if (!in_array($u['role'], ['admin','manager'], true) && $p['created_by'] !== $u['id']) foreach (self::PRIVATE as $k) unset($p[$k]);
  return $p;
 }

 private function scope(array $u): array {
  return match ($u['role']) {
   'admin','manager' => [[], []],
   'broker' => [["(p.listing_status='approved' OR p.created_by=?)"], [$u['id']]],
   default => [["p.listing_status='approved'"], []],
  };
 }
 private function canView(array $p, array $u): bool { return in_array($u['role'], ['admin','manager'], true) || $p['listing_status'] === 'approved' || $p['created_by'] === $u['id']; }
 private function canEdit(array $p, array $u): bool {
  if (in_array($u['role'], ['admin','manager'], true)) return true;
  return $u['role'] === 'broker' && $p['created_by'] === $u['id'] && $p['listing_status'] !== 'pending_review' && $p['listing_status'] !== 'archived';
 }

 private function find(string $id): array {
  $q = $this->db->prepare('SELECT * FROM properties WHERE id=?'); $q->execute([$id]); $p = $q->fetch();
  if (!$p) throw new RuntimeException('Imóvel não encontrado.');
  return $p;
 }

 private function photosFor(array $ids): array {
  if (!$ids) return [];
  $q = $this->db->prepare('SELECT * FROM property_photos WHERE property_id IN ('.implode(',', array_fill(0, count($ids), '?')).') ORDER BY property_id,sort_order,created_at');
  $q->execute($ids); $out = [];
  foreach ($q->fetchAll() as $x) $out[$x['property_id']][] = ['id'=>$x['id'], 'url'=>$this->publicBase.'/uploads/properties/'.$x['property_id'].'/'.$x['storage_name'], 'is_cover'=>(bool)$x['is_cover'], 'category'=>$x['category'], 'slot'=>$x['slot'], 'caption'=>$x['caption'], 'width'=>$x['width'] === null ? null : (int)$x['width'], 'height'=>$x['height'] === null ? null : (int)$x['height']];
  return $out;
 }

 private function clean(array $d, bool $moderator): array {
  $out = [];
  foreach (self::TEXT as $k) if (array_key_exists($k, $d)) { $v = trim((string)$d[$k]); $out[$k] = $v === '' ? null : mb_substr($v, 0, $k === 'description' ? 6000 : 190); }
  foreach (self::MONEY as $k) if (array_key_exists($k, $d)) { $v = $d[$k]; $out[$k] = $v === '' || $v === null ? null : (float)str_replace(',', '.', (string)$v); if ($out[$k] !== null && $out[$k] < 0) throw new InvalidArgumentException('Valores não podem ser negativos.'); }
  foreach (self::INTS as $k) if (array_key_exists($k, $d)) { $v = $d[$k]; $out[$k] = $v === '' || $v === null ? null : (int)$v; if ($out[$k] !== null && $k !== 'floor_number' && $out[$k] < 0) throw new InvalidArgumentException('Quantidades não podem ser negativas.'); }
  foreach (self::BOOLS as $k) if (array_key_exists($k, $d)) $out[$k] = $d[$k] === null || $d[$k] === '' ? null : (filter_var($d[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
  foreach (['features','condo_features'] as $k) if (array_key_exists($k, $d)) $out[$k] = json_encode(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 60), (array)$d[$k]))), JSON_UNESCAPED_UNICODE);
  if (array_key_exists('furnished', $d)) $out['furnished'] = in_array($d['furnished'], ['no','semi','yes'], true) ? $d['furnished'] : null;
  if (isset($out['state'])) $out['state'] = strtoupper(substr($out['state'], 0, 2));
  if (isset($out['zip_code'])) { $digits = preg_replace('/\D/', '', $out['zip_code']); if (strlen($digits) !== 8) throw new InvalidArgumentException('CEP inválido.'); $out['zip_code'] = substr($digits, 0, 5).'-'.substr($digits, 5); }
  if (isset($out['owner_email']) && !filter_var($out['owner_email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail do proprietário inválido.');
  if (isset($out['commission_percent']) && $out['commission_percent'] > 100) throw new InvalidArgumentException('Comissão acima de 100%.');
  if (isset($out['price']) && $out['price'] === null) unset($out['price']);
  // mantém os campos legados usados em listagens, filtros e relatórios
  if (isset($out['neighborhood'])) $out['region'] = $out['neighborhood'];
  if (array_key_exists('street', $out) || array_key_exists('street_number', $out)) {
   $parts = array_filter([$d['street'] ?? null, $d['street_number'] ?? null, $d['neighborhood'] ?? null, $d['city'] ?? null]);
   $out['address'] = $parts ? mb_substr(implode(', ', $parts), 0, 255) : null;
  }
  if (!$moderator) unset($out['reference_code']);
  return $out;
 }

 private function checkEnums(array $d): void {
  if (isset($d['property_type']) && !isset(self::TYPES[$d['property_type']])) throw new InvalidArgumentException('Tipo de imóvel inválido.');
  if (isset($d['purpose']) && !in_array($d['purpose'], ['purchase','rental','both'], true)) throw new InvalidArgumentException('Finalidade inválida.');
  if (isset($d['status']) && !in_array($d['status'], ['available','reserved','sold','rented','inactive'], true)) throw new InvalidArgumentException('Status comercial inválido.');
 }

 private function write(string $id, array $data): void {
  $set = implode(',', array_map(fn($k) => "$k=?", array_keys($data)));
  $this->db->prepare("UPDATE properties SET $set,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([...array_values($data), $id]);
 }

 private function event(string $id, array $u, string $action, ?string $notes = null): void {
  $this->db->prepare('INSERT INTO property_review_events(id,property_id,user_id,action,notes) VALUES(?,?,?,?,?)')->execute([uid(), $id, $u['id'], $action, $notes]);
 }

 private function notifyModerators(string $id, string $title, array $u): void {
  $key = 'property_submitted:'.$id.':'.uid();
  foreach ($this->db->query("SELECT id FROM users WHERE role IN ('admin','manager') AND active=1")->fetchAll(PDO::FETCH_COLUMN) as $mod)
   if ($mod !== $u['id']) $this->ops->notify($mod, $key, 'Anúncio aguardando aprovação', $u['name'].' enviou "'.$title.'" para análise.', 'property', $id);
 }

 private function afterMediaChange(array $p, array $u): void {
  $this->db->prepare('UPDATE properties SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$p['id']]);
  if ($u['role'] === 'broker' && $p['listing_status'] === 'approved') {
   $this->write($p['id'], ['listing_status'=>'pending_review', 'submitted_at'=>date('Y-m-d H:i:s')]);
   $this->event($p['id'], $u, 'edited_after_approval', 'Fotos alteradas pelo corretor; volta para análise.');
   $this->notifyModerators($p['id'], $p['title'], $u);
  }
 }

 private function makeCover(string $id, string $photoId, bool $strict): void {
  $q = $this->db->prepare('SELECT 1 FROM property_photos WHERE id=? AND property_id=?'); $q->execute([$photoId, $id]);
  if (!$q->fetchColumn()) { if ($strict) throw new RuntimeException('Foto não encontrada.'); return; }
  $this->db->prepare('UPDATE property_photos SET is_cover=CASE WHEN id=? THEN 1 ELSE 0 END WHERE property_id=?')->execute([$photoId, $id]);
 }

 private function categoryFor(string $slot, array $guide): string {
  foreach ($guide['required'] as $s) if ($s['slot'] === $slot) return $s['category'];
  foreach ($guide['optional'] as $o) if ($o['category'] === $slot) return $slot;
  throw new InvalidArgumentException('Cômodo da foto inválido para este tipo de imóvel.');
 }

 /** Valida o conteúdo real, corrige a orientação, reduz para no máximo 2048px e regrava em JPEG (remove EXIF/GPS). */
 private function storeImage(array $file, string $propertyId): array {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($file['tmp_name'] ?? ''))) throw new InvalidArgumentException('Falha no upload da foto.');
  $max = (int)(getenv('UPLOAD_MAX_BYTES') ?: 10485760);
  if ($file['size'] > $max) throw new InvalidArgumentException('Foto acima do limite de '.round($max / 1048576).' MB.');
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
  if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) throw new InvalidArgumentException('Formato não permitido. Envie JPEG, PNG ou WebP.');
  $info = getimagesize($file['tmp_name']);
  if (!$info) throw new InvalidArgumentException('Imagem inválida.');
  [$w, $h] = $info;
  if ($w * $h > 40_000_000) throw new InvalidArgumentException('Imagem com resolução excessiva.');
  if (max($w, $h) < 800 || min($w, $h) < 600) throw new InvalidArgumentException("Foto muito pequena ({$w}×{$h}). Use ao menos 800×600 para o anúncio ficar nítido.");
  ini_set('memory_limit', '512M');
  $img = imagecreatefromstring((string)file_get_contents($file['tmp_name']));
  if (!$img) throw new InvalidArgumentException('Não foi possível ler a imagem.');
  if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
   $o = (int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
   $angle = [3=>180, 6=>-90, 8=>90][$o] ?? 0;
   if ($angle) { $img = imagerotate($img, $angle, 0); [$w, $h] = [imagesx($img), imagesy($img)]; }
  }
  $scale = min(1, 2048 / max($w, $h));
  if ($scale < 1) { $img = imagescale($img, (int)round($w * $scale), (int)round($h * $scale), IMG_BICUBIC); [$w, $h] = [imagesx($img), imagesy($img)]; }
  if ($mime !== 'image/jpeg') { // fundo branco para imagens com transparência
   $bg = imagecreatetruecolor($w, $h); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255)); imagecopy($bg, $img, 0, 0, 0, 0, $w, $h); $img = $bg;
  }
  $dir = $this->uploadDir.'/'.$propertyId;
  if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Não foi possível preparar o armazenamento de fotos.');
  $name = bin2hex(random_bytes(16)).'.jpg';
  imageinterlace($img, true);
  if (!imagejpeg($img, $dir.'/'.$name, 84)) throw new RuntimeException('Falha ao salvar a foto.');
  return [$name, $w, $h];
 }

 private function newReference(): string {
  do { $ref = 'VLN-'.strtoupper(bin2hex(random_bytes(3))); $q = $this->db->prepare('SELECT 1 FROM properties WHERE reference_code=?'); $q->execute([$ref]); } while ($q->fetchColumn());
  return $ref;
 }

 private function assertUniqueReference(string $ref, ?string $id): void {
  $q = $this->db->prepare('SELECT 1 FROM properties WHERE reference_code=? AND id<>?'); $q->execute([$ref, $id ?? '']);
  if ($q->fetchColumn()) throw new InvalidArgumentException('Já existe um imóvel com esta referência.');
 }
}
