<?php
// Site público: vitrine, formulário de interesse, chat do visitante e integração com a Central de Conversas.
// Incluído por run.php depois de properties.php (usa $p, $props, $photo, $adminU, $brokerA, $brokerB, $sdrU).
require_once __DIR__.'/../src/SettingsService.php';
require_once __DIR__.'/../src/SiteService.php';
$p->exec("CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,value TEXT,updated_by TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE rate_limits(bucket TEXT PRIMARY KEY,window_start INTEGER,hits INTEGER)");
$p->prepare("INSERT INTO lead_sources VALUES(?,?,?,?,?)")->execute([uid(), 'site', 'Site', 'local', '']);
$settings = new SettingsService($p);
$site = new SiteService($p, new LeadService($p), $settings, 'http://test');
$conv = new ConversationService($p);

// configurações: esquema fixo, só campos conhecidos, e-mail validado
$settings->put('company', ['name'=>'Imobiliária Teste', 'creci'=>'J-12345', 'state'=>'sp', 'hacker'=>'x'], $adminU);
assert($settings->get('company')['name'] === 'Imobiliária Teste' && $settings->get('company')['state'] === 'SP' && !isset($settings->get('company')['hacker']));
try { $settings->put('company', ['email'=>'nao-e-email'], $adminU); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
$settings->put('site', ['chat_enabled'=>true, 'chat_greeting'=>'Olá! Já vamos te atender.'], $adminU);

// vitrine: só publicados e disponíveis; nunca dados do proprietário
$public = $props->create(['property_type'=>'Casa', 'purpose'=>'rental', 'price'=>4200, 'neighborhood'=>'Jardins', 'city'=>'São Paulo', 'owner_name'=>'Dono Secreto', 'owner_phone'=>'11911112222', 'bedrooms'=>3, 'street'=>'Rua Oculta', 'street_number'=>'9'], $adminU);
$props->addPhoto($public['id'], $photo(), 'kitchen', null, $adminU);
$props->addPhoto($public['id'], $photo(), 'facade', null, $adminU);
$p->prepare("UPDATE properties SET listing_status='approved' WHERE id=?")->execute([$public['id']]);
$hiddenDraft = $props->create(['property_type'=>'Casa', 'purpose'=>'rental', 'price'=>100, 'city'=>'São Paulo'], $brokerA);
$list = $site->list(['purpose'=>'rental', 'city'=>'São Paulo']);
$ids = array_column($list['data'], 'id');
assert(in_array($public['id'], $ids, true) && !in_array($hiddenDraft['id'], $ids, true));
$detail = $site->get($public['id']);
foreach (['owner_name','owner_phone','owner_document','registry_number','keys_location','commission_percent','created_by','street'] as $k) assert(!array_key_exists($k, $detail), $k);
assert($detail['rent'] == 4200 && $detail['sale'] === null);
assert($detail['photos'][0]['label'] === 'Fachada' && $detail['photos'][1]['label'] === 'Cozinha'); // capa primeiro, depois ordem do tour
try { $site->get($hiddenDraft['id']); assert(false); } catch (RuntimeException $e) { assert(true); }
assert(!in_array($public['id'], array_column($site->list(['purpose'=>'rental', 'max_price'=>4000, 'city'=>'São Paulo'])['data'], 'id'), true));
assert(in_array($public['id'], array_column($site->list(['purpose'=>'rental', 'min_price'=>4000, 'bedrooms'=>3])['data'], 'id'), true));
assert(in_array('São Paulo', array_column($site->filters()['cities'], 'city'), true));

// formulário de interesse → lead de origem site no funil do SDR; exige consentimento; honeypot ignora
try { $site->lead(['name'=>'Ana', 'phone'=>'11987654321'], '10.0.0.1'); assert(false); } catch (InvalidArgumentException $e) { assert(str_contains($e->getMessage(), 'autorizar')); }
$before = (int)$p->query('SELECT COUNT(*) FROM opportunities')->fetchColumn();
assert($site->lead(['name'=>'Bot', 'phone'=>'11987654321', 'consent'=>true, 'website'=>'spam.com'], '10.0.0.1')['received'] === true);
assert((int)$p->query('SELECT COUNT(*) FROM opportunities')->fetchColumn() === $before);
$lead = $site->lead(['name'=>'Ana Visitante', 'phone'=>'(11) 98765-4321', 'email'=>'ana@site.test', 'consent'=>true, 'property_id'=>$public['id'], 'message'=>'Posso visitar sábado?'], '10.0.0.1');
$opp = $p->query("SELECT o.*,s.code FROM opportunities o JOIN lead_sources s ON s.id=o.source_id WHERE o.id='{$lead['lead_id']}'")->fetch();
assert($opp['code'] === 'site' && $opp['interest_type'] === 'rental' && str_contains($opp['property_interest'], 'Posso visitar sábado?') && $opp['sdr_id'] !== null);

// chat: visitante inicia → conversa na fila humana com lead vinculado; atendente responde; visitante vê a resposta
$chat = $site->chatStart(['name'=>'Carlos Chat', 'phone'=>'11955554444', 'consent'=>'1', 'message'=>'Oi, a casa aceita pet?', 'property_id'=>$public['id']], '10.0.0.2');
assert(strlen($chat['token']) === 48 && $chat['status'] === 'open' && count($chat['messages']) === 2 && $chat['messages'][1]['from'] === 'system');
$cid = $p->query("SELECT id FROM conversations WHERE provider='site_chat' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
$row = $conv->get($cid, $adminU);
assert($row['status'] === 'human' && $row['opportunity_id'] !== null && $row['property_reference'] === $public['reference_code'] && !isset($row['visitor_token_hash']));

// corretor: vê a conversa livre, assume, e o outro corretor deixa de ver/responder
assert(in_array($cid, array_column($conv->list($brokerA), 'id'), true));
$conv->assign($cid, $brokerA);
assert(!in_array($cid, array_column($conv->list($brokerB), 'id'), true));
try { $conv->reply($cid, 'Oi', $brokerB); assert(false); } catch (AuthorizationException $e) { assert(true); }
try { $conv->assign($cid, $brokerB); assert(false); } catch (AuthorizationException $e) { assert(true); }
$conv->reply($cid, 'Aceita sim! Quer agendar uma visita?', $brokerA);
$state = $site->chatSend($chat['token'], 'Quero, sábado às 10h.', '10.0.0.2');
$last2 = array_slice($state['messages'], -2);
assert($last2[0]['from'] === 'agent' && $last2[0]['agent'] === 'Corretor' && $last2[1]['from'] === 'visitor' && $state['agent'] === 'Corretor');
try { $site->chatPoll(str_repeat('a', 48), '10.0.0.2'); assert(false); } catch (RuntimeException $e) { assert(true); }

// encerrada: visitante não envia mais
$conv->resolve($cid, $brokerA);
assert($site->chatPoll($chat['token'], '10.0.0.2')['status'] === 'closed');
try { $site->chatSend($chat['token'], 'Oi?', '10.0.0.2'); assert(false); } catch (DomainException $e) { assert(true); }

// limite de taxa por IP
$site->lead(['name'=>'X Y', 'phone'=>'11900000001', 'consent'=>true], '10.9.9.9');
for ($i = 0; $i < 4; $i++) $site->lead(['name'=>'X Y', 'phone'=>'119000000'.(10 + $i), 'consent'=>true], '10.9.9.9');
try { $site->lead(['name'=>'X Y', 'phone'=>'11900000099', 'consent'=>true], '10.9.9.9'); assert(false); } catch (RateLimitException $e) { assert(true); }

// chat desligado nas configurações
$settings->put('site', ['chat_enabled'=>false], $adminU);
try { $site->chatStart(['name'=>'Z Z', 'phone'=>'11911110000', 'consent'=>true, 'message'=>'oi'], '10.0.0.3'); assert(false); } catch (DomainException $e) { assert(true); }

echo "OK: site público — vitrine sem dados privados, formulário, chat com a Central de Conversas, escopo do corretor e limite de taxa.\n";
