<?php
// Fluxo de anúncios: cadastro pelo corretor, guia de fotos, envio, moderação e escopo de visibilidade.
// Incluído por run.php após o restante da suíte (usa $p, $admin, $broker1, $broker2, $sdrId).
require_once __DIR__.'/../src/PropertyService.php';
$uploadDir = sys_get_temp_dir().'/imob-hub-test-'.bin2hex(random_bytes(4));
$props = new PropertyService($p, new OperationsService($p), $uploadDir, 'http://test');
$adminU = ['id'=>$admin, 'name'=>'Admin', 'role'=>'admin'];
$brokerA = ['id'=>$broker1, 'name'=>'Corretor A', 'role'=>'broker'];
$brokerB = ['id'=>$broker2, 'name'=>'Corretor B', 'role'=>'broker'];
$sdrU = ['id'=>$sdrId, 'name'=>'SDR', 'role'=>'sdr'];
$photo = function (int $w = 1200, int $h = 900): array {
 $file = tempnam(sys_get_temp_dir(), 'img'); $img = imagecreatetruecolor($w, $h); imagefill($img, 0, 0, imagecolorallocate($img, 120, 160, 200)); imagejpeg($img, $file, 80);
 return ['name'=>'foto.jpg', 'tmp_name'=>$file, 'error'=>UPLOAD_ERR_OK, 'size'=>filesize($file)];
};

// guia de fotos acompanha tipo e quantidades
$guide = PropertyService::photoGuide(['property_type'=>'Apartamento', 'bedrooms'=>3, 'bathrooms'=>2]);
assert(array_column($guide['required'], 'slot') === ['facade','living_room','kitchen','bedroom_1','bedroom_2','bedroom_3','bathroom_1','bathroom_2','laundry']);
assert(in_array('condo_pool', array_column($guide['optional'], 'category'), true));
assert(array_column(PropertyService::photoGuide(['property_type'=>'Terreno'])['required'], 'slot') === ['front','overview']);
assert(PropertyService::photoGuide(['property_type'=>'Casa', 'bedrooms'=>1, 'bathrooms'=>1])['required'][3]['label'] === 'Quarto');

// corretor cria rascunho; tipo inválido é recusado; corretor não define a referência
try { $props->create(['property_type'=>'Castelo', 'purpose'=>'purchase'], $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
$draft = $props->create(['property_type'=>'Apartamento', 'purpose'=>'both', 'reference_code'=>'HACK-1', 'bedrooms'=>2, 'bathrooms'=>1], $brokerA);
assert($draft['listing_status'] === 'draft' && $draft['created_by'] === $broker1 && str_starts_with($draft['reference_code'], 'VLN-'));
assert(in_array('Foto: Quarto 2', $draft['missing'], true) && in_array('Valor do aluguel', $draft['missing'], true));
try { $props->submit($draft['id'], $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(str_contains($e->getMessage(), 'Complete antes de enviar')); }

// escopo: rascunho visível ao autor e à gestão, invisível a outro corretor e ao SDR
try { $props->get($draft['id'], $brokerB); assert(false); } catch (AuthorizationException $e) { assert(true); }
try { $props->update($draft['id'], ['title'=>'x'], $brokerB); assert(false); } catch (AuthorizationException $e) { assert(true); }
assert(!in_array($draft['id'], array_column($props->list($sdrU, [])['data'], 'id'), true));
assert(in_array($draft['id'], array_column($props->list($adminU, ['listing_status'=>'draft'])['data'], 'id'), true));

// validações de campos
try { $props->update($draft['id'], ['zip_code'=>'123'], $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert($e->getMessage() === 'CEP inválido.'); }
try { $props->addPhoto($draft['id'], $photo(), 'garagem_do_vizinho', null, $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
try { $props->addPhoto($draft['id'], $photo(400, 300), 'facade', null, $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(str_contains($e->getMessage(), 'muito pequena')); }

$full = $props->update($draft['id'], [
 'title'=>'Apartamento 2 quartos no Centro', 'price'=>'450000', 'rental_price'=>'2800', 'condo_fee'=>'650', 'area_built'=>'68',
 'zip_code'=>'01001000', 'street'=>'Praça da Sé', 'street_number'=>'100', 'neighborhood'=>'Sé', 'city'=>'São Paulo', 'state'=>'sp', 'parking_spaces'=>1,
 'description'=>'Apartamento reformado, com dois quartos, sala ampla, cozinha planejada e uma vaga de garagem coberta.',
 'owner_name'=>'João Proprietário', 'owner_phone'=>'11999990000', 'owner_document'=>'12345678900', 'features'=>['Armários planejados', ' ', 'Varanda'],
], $brokerA);
assert($full['zip_code'] === '01001-000' && $full['state'] === 'SP' && $full['region'] === 'Sé' && $full['features'] === ['Armários planejados','Varanda']);
foreach (array_column($full['guide']['required'], 'slot') as $slot) $withPhotos = $props->addPhoto($draft['id'], $photo(), $slot, null, $brokerA);
$withPhotos = $props->addPhoto($draft['id'], $photo(900, 1600), 'condo_pool', 'Piscina adulto', $brokerA);
assert($withPhotos['missing'] === [] && $withPhotos['photo_count'] === 8);
assert($withPhotos['cover_url'] === array_values(array_filter($withPhotos['photos'], fn($x) => $x['slot'] === 'facade'))[0]['url']);
assert(max($withPhotos['photos'][7]['width'], $withPhotos['photos'][7]['height']) <= 2048);

// envio → em análise bloqueia edição do corretor e notifica a gestão
$sent = $props->submit($draft['id'], $brokerA);
assert($sent['listing_status'] === 'pending_review' && $sent['creator_name'] === 'Corretor A');
assert((int)$p->query("SELECT COUNT(*) FROM notifications WHERE user_id='$admin' AND entity_id='{$draft['id']}'")->fetchColumn() === 1);
try { $props->update($draft['id'], ['title'=>'Outro'], $brokerA); assert(false); } catch (AuthorizationException $e) { assert(str_contains($e->getMessage(), 'em análise')); }
try { $props->review($draft['id'], 'approve', null, $brokerA); assert(false); } catch (AuthorizationException $e) { assert(true); }

// reprovação exige motivo; corretor corrige e reenvia; aprovação publica
try { $props->review($draft['id'], 'reject', ' ', $adminU); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
$rejected = $props->review($draft['id'], 'reject', 'Foto da cozinha escura.', $adminU);
assert($rejected['listing_status'] === 'rejected' && $rejected['review_notes'] === 'Foto da cozinha escura.');
$kitchen = array_values(array_filter($rejected['photos'], fn($x) => $x['slot'] === 'kitchen'))[0]['id'];
$afterRemoval = $props->removePhoto($draft['id'], $kitchen, $brokerA);
assert(in_array('Foto: Cozinha', $afterRemoval['missing'], true));
$props->addPhoto($draft['id'], $photo(), 'kitchen', null, $brokerA);
$props->submit($draft['id'], $brokerA);
$approved = $props->review($draft['id'], 'approve', null, $adminU);
assert($approved['listing_status'] === 'approved' && $approved['reviewed_by'] === $admin);
assert(count(array_filter(array_column($approved['events'], 'action'), fn($a) => $a === 'submitted')) === 2);

// publicado: visível para todos, mas dados do proprietário só para autor e gestão
$seenByB = $props->get($draft['id'], $brokerB);
assert(!isset($seenByB['owner_name']) && !isset($seenByB['owner_document']) && isset($props->get($draft['id'], $brokerA)['owner_name']));
assert(in_array($draft['id'], array_column($props->list($sdrU, [])['data'], 'id'), true));

// corretor altera anúncio publicado → volta para análise; gestor edita sem perder a publicação
assert($props->update($draft['id'], ['condo_fee'=>'700'], $brokerA)['listing_status'] === 'pending_review');
$props->review($draft['id'], 'approve', null, $adminU);
assert($props->update($draft['id'], ['condo_fee'=>'720', 'status'=>'reserved'], $adminU)['listing_status'] === 'approved');
$mineCounts = $props->list($brokerA, ['mine'=>1])['counts'];
assert($mineCounts['approved'] === 1 && $mineCounts['draft'] === 0);
assert($props->list($brokerB, ['mine'=>1])['counts']['approved'] === 0);

// só anúncio aprovado pode ser apresentado a um cliente
$hidden = $props->create(['property_type'=>'Casa', 'purpose'=>'purchase', 'price'=>300000], $brokerA);
try { $commercial->present($lead['id'], $hidden['id'], ['id'=>$otherBroker, 'role'=>'broker'], null); assert(false); } catch (InvalidArgumentException $e) { assert(true); }

// gestor pode cadastrar já publicado
assert($props->create(['property_type'=>'Terreno', 'purpose'=>'purchase', 'price'=>90000, 'publish'=>true], $adminU)['listing_status'] === 'approved');

require __DIR__.'/site.php';
require __DIR__.'/credit.php';
array_map('unlink', glob($uploadDir.'/*/*') ?: []); array_map('rmdir', glob($uploadDir.'/*') ?: []); @rmdir($uploadDir);
echo "OK: anúncios de imóveis — guia de fotos, rascunho, envio, moderação, escopo e privacidade do proprietário.\n";
