<?php
// Ficha de interesse e análise de crédito. Incluído por properties.php (usa $p, $props, $adminU, $brokerA, $brokerB, $sdrU, $broker1, $lead).
require_once __DIR__.'/../src/CreditService.php';
putenv('APP_ENCRYPTION_KEY='.str_repeat('k', 40));
$storage = sys_get_temp_dir().'/imob-hub-private-'.bin2hex(random_bytes(4)); putenv('PRIVATE_STORAGE_PATH='.$storage);
$p->exec("CREATE TABLE credit_applications(id TEXT PRIMARY KEY,code TEXT UNIQUE,opportunity_id TEXT,property_id TEXT,broker_id TEXT,analyst_id TEXT,business_type TEXT,payment_method TEXT,offer_value REAL,down_payment REAL,fgts_value REAL,financing_value REAL,financing_term_months INTEGER,bank_preference TEXT,rent_value REAL,guarantee_type TEXT,lease_months INTEGER,move_in_date TEXT,applicant_name TEXT,household_income REAL,personal_data TEXT,notes TEXT,consent_at TEXT,status TEXT DEFAULT 'draft',decision_notes TEXT,approved_value REAL,approved_conditions TEXT,submitted_at TEXT,decided_at TEXT,decided_by TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE credit_events(id TEXT PRIMARY KEY,application_id TEXT,user_id TEXT,action TEXT,notes TEXT,created_at TEXT);ALTER TABLE documents ADD label TEXT;CREATE TABLE contracts(id TEXT PRIMARY KEY,code TEXT UNIQUE,credit_application_id TEXT,opportunity_id TEXT,property_id TEXT,broker_id TEXT,template_key TEXT,status TEXT DEFAULT 'draft',total_value REAL,terms TEXT,signers TEXT,pdf_storage_name TEXT,pdf_sha256 TEXT,signed_storage_name TEXT,signed_sha256 TEXT,signature_provider TEXT,provider_envelope_id TEXT UNIQUE,sent_at TEXT,signed_at TEXT,cancelled_at TEXT,cancel_reason TEXT,created_by TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$analystId = uid(); $p->prepare('INSERT INTO users(id,name,email,password_hash,role) VALUES(?,?,?,?,?)')->execute([$analystId, 'Ana Analista', 'an@a.test', 'x', 'analyst']);
$analyst = ['id'=>$analystId, 'name'=>'Ana Analista', 'role'=>'analyst'];
$analyst2 = ['id'=>uid(), 'name'=>'Beto Analista', 'role'=>'analyst'];
$p->prepare('INSERT INTO users(id,name,email,password_hash,role) VALUES(?,?,?,?,?)')->execute([$analyst2['id'], 'Beto Analista', 'an2@a.test', 'x', 'analyst']);
$credit = new CreditService($p, new OperationsService($p), new LeadService($p));
$doc = function (string $content = "%PDF-1.4\n%teste\n") { $f = tempnam(sys_get_temp_dir(), 'doc'); file_put_contents($f, $content); return ['name'=>'doc.pdf', 'tmp_name'=>$f, 'error'=>UPLOAD_ERR_OK, 'size'=>filesize($f)]; };

// imóvel publicado para venda e locação
$prop = $props->create(['property_type'=>'Apartamento', 'purpose'=>'both', 'price'=>600000, 'rental_price'=>3500, 'publish'=>true], $adminU);
$draftProp = $props->create(['property_type'=>'Casa', 'purpose'=>'purchase', 'price'=>100], $brokerA);

// corretor abre ficha para cliente novo (cria lead na carteira dele); não aceita imóvel não publicado
try { $credit->create(['property_id'=>$draftProp['id'], 'payment_method'=>'cash', 'client'=>['name'=>'X', 'phone'=>'11999990000']], $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
$localSource = $p->query("SELECT id FROM lead_sources WHERE code='local'")->fetchColumn();
$oppB = (new LeadService($p))->create(['name'=>'Cliente do B', 'phone'=>'11911112233', 'source_id'=>$localSource, 'interest_type'=>'purchase', 'broker_id'=>$broker2, 'stage'=>'service']);
try { $credit->create(['property_id'=>$prop['id'], 'payment_method'=>'financing', 'opportunity_id'=>$oppB['id']], $brokerA); assert(false); } catch (AuthorizationException $e) { assert(true); }
$app = $credit->create(['property_id'=>$prop['id'], 'payment_method'=>'down_payment_financing', 'client'=>['name'=>'Paula Compradora', 'phone'=>'11977776666', 'email'=>'paula@teste.local']], $brokerA);
assert($app['status'] === 'draft' && str_starts_with($app['code'], 'FI-') && $app['offer_value'] == 600000 && $app['applicant']['name'] === 'Paula Compradora');
$opp = $p->query("SELECT * FROM opportunities WHERE id='{$app['opportunity_id']}'")->fetch();
assert($opp['broker_id'] === $broker1 && $opp['interest_type'] === 'purchase');
assert(!str_contains((string)$p->query("SELECT personal_data FROM credit_applications WHERE id='{$app['id']}'")->fetchColumn(), 'Paula')); // cifrado no banco

// visibilidade: rascunho não aparece para analista nem para outro corretor; SDR sem acesso
try { $credit->get($app['id'], $analyst); assert(false); } catch (AuthorizationException $e) { assert(true); }
try { $credit->get($app['id'], $brokerB); assert(false); } catch (AuthorizationException $e) { assert(true); }
try { $credit->list($sdrU, []); assert(false); } catch (AuthorizationException $e) { assert(true); }
assert(count($credit->list($analyst, [])['data']) === 0 && count($credit->list($brokerA, [])['data']) === 1);

// preenchimento: CPF inválido e entrada que cobre tudo são recusados; financiado calculado
try { $credit->update($app['id'], ['offer_value'=>'600000', 'down_payment'=>'600000'], $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
$filled = $credit->update($app['id'], [
 'offer_value'=>'590000', 'down_payment'=>'120000', 'fgts_value'=>'30000', 'financing_term_months'=>360, 'bank_preference'=>'Caixa',
 'applicant'=>['name'=>'Paula Compradora', 'cpf'=>'111.111.111-11', 'birth_date'=>'1990-04-02', 'phone'=>'11977776666', 'marital_status'=>'married', 'profession'=>'Engenheira', 'employment_type'=>'clt', 'monthly_income'=>'14000'],
 'co_applicant'=>['name'=>'Rui Cônjuge', 'cpf'=>'529.982.247-25', 'monthly_income'=>'6000', 'relationship'=>'spouse'],
], $brokerA);
assert($filled['financing_value'] == 440000 && $filled['household_income'] == 20000);
assert(in_array('CPF do proponente válido', $filled['missing'], true) && in_array('Documento: Certidão de estado civil', $filled['missing'], true) && in_array('Documento: Extrato do FGTS', $filled['missing'], true));
try { $credit->submit($app['id'], $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(str_contains($e->getMessage(), 'Complete antes de enviar')); }
$credit->update($app['id'], ['applicant'=>['name'=>'Paula Compradora', 'cpf'=>'390.533.447-05', 'birth_date'=>'1990-04-02', 'phone'=>'11977776666', 'marital_status'=>'married', 'profession'=>'Engenheira', 'employment_type'=>'clt', 'monthly_income'=>'14000'], 'consent'=>true], $brokerA);
try { $credit->addDocument($app['id'], $doc('<?php echo 1;'), 'id', $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(str_contains($e->getMessage(), 'Formato')); }
foreach (['id','income','residence','marital','fgts'] as $k) $withDocs = $credit->addDocument($app['id'], $doc(), $k, $brokerA);
assert(in_array('Documento: Documentos do cônjuge/compositor', $withDocs['missing'], true));
$withDocs = $credit->addDocument($app['id'], $doc(), 'co_applicant', $brokerA);
assert($withDocs['missing'] === [], implode(',', $withDocs['missing']));
$stored = $p->query("SELECT storage_name FROM documents WHERE entity_id='{$app['id']}' LIMIT 1")->fetchColumn();
assert(is_file($storage.'/'.$stored) && (fileperms($storage.'/'.$stored) & 0777) === 0600);

// envio → analistas notificados; ficha bloqueada para o corretor
$sent = $credit->submit($app['id'], $brokerA);
assert($sent['status'] === 'submitted' && (int)$p->query("SELECT COUNT(*) FROM notifications WHERE entity_id='{$app['id']}' AND user_id IN ('$analystId','{$analyst2['id']}')")->fetchColumn() === 2);
try { $credit->update($app['id'], ['notes'=>'x'], $brokerA); assert(false); } catch (AuthorizationException $e) { assert(true); }
assert($credit->list($analyst, ['queue'=>'new'])['data'][0]['id'] === $app['id']);
assert($credit->get($app['id'], $analyst)['applicant']['cpf'] === '39053344705'); // analista lê os dados decifrados

// analista assume; outro analista não decide; pendência exige descrição e devolve ao corretor
$credit->claim($app['id'], $analyst);
try { $credit->decide($app['id'], ['decision'=>'approve'], $analyst2); assert(false); } catch (DomainException $e) { assert(str_contains($e->getMessage(), 'outro analista')); }
try { $credit->decide($app['id'], ['decision'=>'pending_docs'], $analyst); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
$pending = $credit->decide($app['id'], ['decision'=>'pending_docs', 'notes'=>'Enviar holerites dos últimos 3 meses.'], $analyst);
assert($pending['status'] === 'pending_docs' && $pending['can_edit'] === false); // can_edit calculado para o analista
assert($credit->get($app['id'], $brokerA)['can_edit'] === true);
$credit->addDocument($app['id'], $doc(), 'income', $brokerA);
assert($credit->submit($app['id'], $brokerA)['status'] === 'in_analysis'); // volta direto para o mesmo analista

// aprovação com condições exige texto; aprovação notifica o corretor e registra na oportunidade
try { $credit->decide($app['id'], ['decision'=>'approve_conditions'], $analyst); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
$ok = $credit->decide($app['id'], ['decision'=>'approve_conditions', 'conditions'=>'Entrada mínima de R$ 130.000.', 'approved_value'=>'430000'], $analyst);
assert($ok['status'] === 'approved_conditions' && $ok['approved_value'] == 430000 && $ok['decided_by'] === $analystId);
assert((int)$p->query("SELECT COUNT(*) FROM notifications WHERE user_id='$broker1' AND entity_id='{$app['id']}' AND title LIKE 'Crédito aprovado%'")->fetchColumn() === 1);
assert((int)$p->query("SELECT COUNT(*) FROM interactions WHERE opportunity_id='{$app['opportunity_id']}' AND type='credit_decision'")->fetchColumn() === 2); // pendência + aprovação
try { $credit->decide($app['id'], ['decision'=>'reject', 'notes'=>'x'], $analyst); assert(false); } catch (DomainException $e) { assert(true); }

// locação com fiador exige fiador com CPF válido e documentos do fiador; reprovação exige motivo
$rent = $credit->create(['property_id'=>$prop['id'], 'payment_method'=>'rental', 'opportunity_id'=>$app['opportunity_id']], $brokerA);
assert($rent['rent_value'] == 3500 && $rent['business_type'] === 'rental');
try { $credit->update($rent['id'], ['payment_method'=>'cash'], $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
$r = $credit->update($rent['id'], ['guarantee_type'=>'guarantor', 'lease_months'=>30, 'consent'=>true, 'applicant'=>['name'=>'Paula Compradora', 'cpf'=>'39053344705', 'birth_date'=>'1990-04-02', 'phone'=>'11977776666', 'marital_status'=>'single', 'profession'=>'Engenheira', 'employment_type'=>'clt', 'monthly_income'=>'14000']], $brokerA);
assert(in_array('Nome e CPF válido do fiador', $r['missing'], true) && in_array('Documento: Documentos do fiador', $r['missing'], true) && !in_array('Documento: Certidão de estado civil', $r['missing'], true));
$credit->update($rent['id'], ['guarantor'=>['name'=>'Fiador Silva', 'cpf'=>'529.982.247-25']], $brokerA);
foreach (['id','income','residence','guarantor'] as $k) $credit->addDocument($rent['id'], $doc(), $k, $brokerA);
$credit->submit($rent['id'], $brokerA);
try { $credit->decide($rent['id'], ['decision'=>'reject'], $adminU); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
assert($credit->decide($rent['id'], ['decision'=>'reject', 'notes'=>'Renda insuficiente para o aluguel.'], $adminU)['status'] === 'rejected'); // gestão também decide

// cancelamento exige motivo; outro corretor não cancela
try { $credit->cancel($app['id'], 'x', $brokerB); assert(false); } catch (AuthorizationException $e) { assert(true); }
try { $credit->cancel($app['id'], ' ', $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
assert($credit->list($brokerA, [])['counts']['approved_conditions'] === 1 && $credit->list($brokerA, [])['counts']['awaiting_contract'] === 1);

require __DIR__.'/contracts.php';
array_map('unlink', glob($storage.'/*') ?: []); @rmdir($storage);
echo "OK: ficha de interesse — cifragem, documentos, envio, fila do analista, pendências, decisão, locação com fiador e escopo.\n";
