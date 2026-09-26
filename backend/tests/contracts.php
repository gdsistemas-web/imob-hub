<?php
// Contratos: geração do PDF a partir da ficha aprovada, modelos, envio ao Documenso (dublê), webhook, fluxo manual e fechamento.
// Incluído por credit.php (usa $p, $credit, $app, $prop, $props, $brokerA, $brokerB, $adminU, $analyst, $doc, $storage, $broker1).
require_once __DIR__.'/../src/SimplePdf.php';
require_once __DIR__.'/../src/ContractTemplates.php';
require_once __DIR__.'/../src/DocumensoClient.php';
require_once __DIR__.'/../src/ContractService.php';
$p->exec("CREATE TABLE contract_templates(template_key TEXT PRIMARY KEY,body TEXT,updated_by TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE contract_events(id TEXT PRIMARY KEY,contract_id TEXT,user_id TEXT,action TEXT,notes TEXT,created_at TEXT);ALTER TABLE users ADD creci TEXT");
$p->exec("CREATE TABLE IF NOT EXISTS closings(id TEXT,opportunity_id TEXT UNIQUE,property_id TEXT,responsible_id TEXT,outcome TEXT,business_type TEXT,final_amount REAL,closing_date TEXT,loss_reason TEXT,notes TEXT,contract_reference TEXT,contract_status TEXT,contract_file_path TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$p->prepare('UPDATE users SET creci=? WHERE id=?')->execute(['123456-F', $broker1]);
$settings->put('company', ['name'=>'Imobiliária Teste', 'legal_name'=>'Imobiliária Teste Ltda.', 'cnpj'=>'12.345.678/0001-90', 'creci'=>'J-12345', 'email'=>'contratos@imob.test', 'address'=>'Av. Central, 1000', 'city'=>'São Paulo', 'state'=>'SP'], $adminU);

/** Dublê do Documenso: registra as chamadas e devolve respostas no formato da API v2. */
final class FakeDocumenso extends DocumensoClient {
 public array $calls = []; public string $status = 'PENDING'; public array $recipients = [];
 public function __construct() {}
 public function createEnvelope(string $title, string $externalId, string $pdf, string $fileName, array $recipients, array $meta = []): string { $this->calls[] = ['create', compact('title', 'externalId', 'recipients', 'meta') + ['pdf'=>substr($pdf, 0, 8)]]; return 'envelope_test123'; }
 public function distribute(string $envelopeId, array $meta = []): void { $this->calls[] = ['distribute', $envelopeId]; }
 public function get(string $envelopeId): array { return ['id'=>$envelopeId, 'status'=>$this->status, 'recipients'=>$this->recipients, 'envelopeItems'=>[['id'=>'item_1']]]; }
 public function cancel(string $envelopeId, string $reason): void { $this->calls[] = ['cancel', $envelopeId, $reason]; }
 public function downloadSigned(string $envelopeId): string { return "%PDF-1.4\n% assinado\n"; }
}
$pdfText = function (string $pdf): string { // junta o texto de todas as linhas (Tj), desfazendo as quebras
 preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m); $raw = implode("\n", array_map(fn($s) => (string)@gzuncompress($s), $m[1]));
 preg_match_all('/\((.*?)\) Tj/s', $raw, $t); return str_replace(['\\(', '\\)'], ['(', ')'], implode(' ', $t[1]));
};

// sem Documenso: rascunho gerado a partir da ficha aprovada, com dados das partes, imóvel e valores por extenso
$plain = new ContractService($p, new OperationsService($p), $settings, new CommercialService($p), null);
try { $plain->createFromCredit($rent['id'], $brokerA); assert(false); } catch (DomainException $e) { assert(str_contains($e->getMessage(), 'aprovação')); } // ficha reprovada
try { $plain->createFromCredit($app['id'], $brokerB); assert(false); } catch (AuthorizationException $e) { assert(true); }
$ct = $plain->createFromCredit($app['id'], $brokerA);
assert($credit->get($app['id'], $brokerA)['contract']['id'] === $ct['id'] && $credit->list($brokerA, [])['counts']['awaiting_contract'] === 0);
assert($ct['status'] === 'draft' && $ct['template_key'] === 'sale_down_payment' && str_starts_with($ct['code'], 'CT-') && $ct['documenso'] === false);
assert(str_contains($ct['terms']['comprador_qualificacao'], 'PAULA COMPRADORA') && str_contains($ct['terms']['comprador_qualificacao'], '390.533.447-05') && str_contains($ct['terms']['condicoes_especiais'], 'Entrada mínima'));
assert(array_column($ct['signers'], 'role') === ['buyer', 'spouse', 'seller', 'agency'] && $ct['total_value'] == 590000 && str_contains($ct['terms']['comprador_qualificacao'], 'RUI CÔNJUGE'));
try { $plain->createFromCredit($app['id'], $brokerA); assert(false); } catch (DomainException $e) { assert(str_contains($e->getMessage(), 'Já existe')); }
[$file] = $plain->file($ct['id'], $brokerA, false);
$pdf = file_get_contents($file);
assert(str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, '%%EOF'));
$text = $pdfText($pdf);
assert(str_contains($text, iconv('UTF-8', 'CP1252', 'CLÁUSULA 1ª — DO OBJETO')) && str_contains($text, iconv('UTF-8', 'CP1252', 'quinhentos e noventa mil reais')) && str_contains($text, 'CT-'));
assert(!str_contains($text, '{{')); // nenhuma variável sem substituir
try { $plain->send($ct['id'], $brokerA); assert(false); } catch (DomainException $e) { assert(str_contains($e->getMessage(), 'não configurada')); }

// edição de condições e signatários regenera o PDF
$edited = $plain->update($ct['id'], ['terms'=>['sinal'=>'60000', 'posse'=>'na entrega das chaves, em 15/12/2026'], 'signers'=>[
 ['role'=>'buyer', 'name'=>'Paula Compradora', 'email'=>'paula@teste.local', 'doc'=>'390.533.447-05'],
 ['role'=>'seller', 'name'=>'Dono do Imóvel', 'email'=>'', 'doc'=>''],
 ['role'=>'agency', 'name'=>'Imobiliária Teste Ltda.', 'email'=>'contratos@imob.test', 'doc'=>''],
]], $brokerA);
assert($edited['terms']['sinal'] === '60000' && $edited['pdf_sha256'] !== $ct['pdf_sha256']);
assert(str_contains($pdfText(file_get_contents($plain->file($ct['id'], $brokerA, false)[0])), iconv('UTF-8', 'CP1252', 'sessenta mil reais')));

// com Documenso (dublê): exige e-mail de todos; envia com campo de assinatura posicionado por signatário
$fake = new FakeDocumenso();
$svc = new ContractService($p, new OperationsService($p), $settings, new CommercialService($p), $fake);
try { $svc->send($ct['id'], $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(str_contains($e->getMessage(), 'Dono do Imóvel')); }
$svc->update($ct['id'], ['signers'=>[['role'=>'buyer', 'name'=>'Paula Compradora', 'email'=>'paula@teste.local'], ['role'=>'seller', 'name'=>'Dono do Imóvel', 'email'=>'PAULA@teste.local'], ['role'=>'agency', 'name'=>'Imobiliária Teste Ltda.', 'email'=>'contratos@imob.test']]], $brokerA);
try { $svc->send($ct['id'], $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(str_contains($e->getMessage(), 'mais de um signatário')); }
$svc->update($ct['id'], ['signers'=>[['role'=>'buyer', 'name'=>'Paula Compradora', 'email'=>'paula@teste.local', 'doc'=>'390.533.447-05'], ['role'=>'seller', 'name'=>'Dono do Imóvel', 'email'=>'dono@teste.local'], ['role'=>'agency', 'name'=>'Imobiliária Teste Ltda.', 'email'=>'contratos@imob.test']]], $brokerA);
$sent = $svc->send($ct['id'], $brokerA);
assert($sent['status'] === 'sent' && $sent['provider_envelope_id'] === 'envelope_test123' && $fake->calls[1] === ['distribute', 'envelope_test123']);
$create = $fake->calls[0][1];
assert($create['externalId'] === $ct['id'] && count($create['recipients']) === 3 && $create['pdf'] === '%PDF-1.4' && $create['meta']['subject'] !== '');
foreach ($create['recipients'] as $r) { $f = $r['fields'][0]; assert($f['page'] >= 1 && $f['positionX'] >= 0 && $f['positionX'] + $f['width'] <= 100 && $f['positionY'] > 0 && $f['positionY'] + $f['height'] <= 100); }
assert($create['recipients'][0]['fields'][0]['positionX'] < $create['recipients'][1]['fields'][0]['positionX']); // duas colunas
try { $svc->update($ct['id'], ['terms'=>['sinal'=>'1']], $brokerA); assert(false); } catch (DomainException $e) { assert(true); }

// webhook: assinatura parcial atualiza o signatário; conclusão guarda o PDF assinado e registra o fechamento
assert($svc->webhook(['event'=>'DOCUMENT_SIGNED', 'payload'=>['envelopeId'=>'outro_envelope']]) === false);
$svc->webhook(['event'=>'DOCUMENT_RECIPIENT_COMPLETED', 'payload'=>['envelopeId'=>'envelope_test123', 'status'=>'PENDING', 'recipients'=>[['email'=>'paula@teste.local', 'signingStatus'=>'SIGNED', 'signedAt'=>'2026-09-26T14:00:00Z'], ['email'=>'dono@teste.local', 'signingStatus'=>'NOT_SIGNED']]]]);
$partial = $svc->get($ct['id'], $brokerA);
assert($partial['status'] === 'sent' && $partial['signers'][0]['status'] === 'signed' && $partial['signers'][1]['status'] === 'pending');
$svc->webhook(['event'=>'DOCUMENT_COMPLETED', 'payload'=>['envelopeId'=>'envelope_test123', 'status'=>'COMPLETED', 'recipients'=>array_map(fn($e) => ['email'=>$e, 'signingStatus'=>'SIGNED'], ['paula@teste.local', 'dono@teste.local', 'contratos@imob.test'])]]);
$done = $svc->get($ct['id'], $brokerA);
assert($done['status'] === 'signed' && $done['has_signed_pdf'] && count(array_filter($done['signers'], fn($s) => $s['status'] === 'signed')) === 3);
assert(file_get_contents($svc->file($ct['id'], $adminU, true)[0]) === "%PDF-1.4\n% assinado\n");
$opp = $p->query("SELECT stage FROM opportunities WHERE id='{$app['opportunity_id']}'")->fetchColumn();
$closing = $p->query("SELECT * FROM closings WHERE opportunity_id='{$app['opportunity_id']}'")->fetch();
assert($opp === 'won' && $closing['contract_reference'] === $ct['code'] && $closing['business_type'] === 'purchase');
assert($p->query("SELECT status FROM properties WHERE id='{$prop['id']}'")->fetchColumn() === 'sold');
assert((int)$p->query("SELECT COUNT(*) FROM notifications WHERE user_id='$broker1' AND entity_id='{$ct['id']}'")->fetchColumn() === 1);
try { $svc->cancel($ct['id'], 'x', $brokerA); assert(false); } catch (DomainException $e) { assert(true); }
try { $svc->file($ct['id'], $brokerB, false); assert(false); } catch (AuthorizationException $e) { assert(true); }

// locação: fluxo manual (sem Documenso) com PDF assinado anexado; garantia por caução no texto
$prop2 = $props->create(['property_type'=>'Sala Comercial', 'purpose'=>'rental', 'price'=>4000, 'publish'=>true], $adminU);
$lease = $credit->create(['property_id'=>$prop2['id'], 'payment_method'=>'rental', 'client'=>['name'=>'Empresa Locatária', 'phone'=>'11955556666']], $brokerA);
$credit->update($lease['id'], ['guarantee_type'=>'deposit', 'lease_months'=>36, 'consent'=>true, 'applicant'=>['name'=>'Empresa Locatária', 'cpf'=>'39053344705', 'birth_date'=>'1985-01-01', 'phone'=>'11955556666', 'marital_status'=>'single', 'profession'=>'Empresário', 'employment_type'=>'owner', 'monthly_income'=>'30000']], $brokerA);
foreach (['id','income','residence'] as $k) $credit->addDocument($lease['id'], $doc(), $k, $brokerA);
$credit->submit($lease['id'], $brokerA); $credit->decide($lease['id'], ['decision'=>'approve'], $adminU);
$lc = $plain->createFromCredit($lease['id'], $brokerA);
assert($lc['template_key'] === 'lease' && $lc['terms']['finalidade'] === 'não residencial' && $lc['terms']['garantia_valor'] === '12000' && $lc['terms']['prazo_meses'] === '36');
$leaseText = $pdfText(file_get_contents($plain->file($lc['id'], $brokerA, false)[0]));
assert(str_contains($leaseText, iconv('UTF-8', 'CP1252', 'caução em dinheiro')) && str_contains($leaseText, iconv('UTF-8', 'CP1252', 'doze mil reais')));
try { $plain->uploadSigned($lc['id'], $doc('não é pdf'), $brokerA); assert(false); } catch (InvalidArgumentException $e) { assert(true); }
$manual = $plain->uploadSigned($lc['id'], $doc(), $brokerA);
assert($manual['status'] === 'signed' && $manual['signature_provider'] === 'manual');
assert($p->query("SELECT status FROM properties WHERE id='{$prop2['id']}'")->fetchColumn() === 'rented');

// modelos: validação de variáveis e do bloco de assinaturas; prévia gera PDF; restaurar volta ao padrão
try { $plain->saveTemplate('lease', str_repeat('Texto do contrato. ', 20).'{{assinaturas}} {{variavel.inexistente}}', $adminU); assert(false); } catch (InvalidArgumentException $e) { assert(str_contains($e->getMessage(), 'variavel.inexistente')); }
try { $plain->saveTemplate('lease', str_repeat('Texto do contrato sem assinaturas. ', 20), $adminU); assert(false); } catch (InvalidArgumentException $e) { assert(str_contains($e->getMessage(), '{{assinaturas}}')); }
$custom = "# CONTRATO PERSONALIZADO\n\n## CLÁUSULA — DO ALUGUEL\nAluguel de {{locacao.aluguel}} para {{locatario.nome}}. ".str_repeat('Cláusula de teste. ', 12)."\n\n{{assinaturas}}";
$tpl = $plain->saveTemplate('lease', $custom, $adminU);
assert(array_values(array_filter($tpl['data'], fn($t) => $t['key'] === 'lease'))[0]['customized'] === true);
$preview = $plain->preview('lease', null);
assert(str_contains($pdfText($preview), 'CONTRATO PERSONALIZADO') && str_contains($pdfText($preview), iconv('UTF-8', 'CP1252', 'três mil e duzentos reais')));
$plain->saveTemplate('lease', null, $adminU);
assert(array_values(array_filter($plain->templates()['data'], fn($t) => $t['key'] === 'lease'))[0]['customized'] === false);
foreach (array_keys(ContractTemplates::KEYS) as $key) assert(!str_contains($pdfText($plain->preview($key, null)), '{{'));
assert(ContractService::longDate('2026-03-01') === '1 de março de 2026');

echo "OK: contratos — geração do PDF, variáveis, edição, Documenso (dublê) com posições de assinatura, webhook, fluxo manual, fechamento e modelos.\n";
