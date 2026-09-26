<?php
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/LeadService.php';
require __DIR__.'/../src/SdrService.php';
require __DIR__.'/../src/CommercialService.php';
require __DIR__.'/../src/OperationsService.php';
require __DIR__.'/../src/FlowService.php';
require __DIR__.'/../src/ConversationService.php';
$pdo=db();
mt_srand(20260923);

function pick(array $a){return $a[array_rand($a)];}
function weighted(array $weights){$r=mt_rand(1,100);$acc=0;foreach($weights as $k=>$w){$acc+=$w;if($r<=$acc)return $k;}return array_key_first($weights);}
function slugify(string $s):string{$map=['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'];$s=strtr($s,$map);$s=strtolower(preg_replace('/[^a-zA-Z]+/','.',$s));return trim($s,'.');}
function minutesAgo(int $min):string{return date('Y-m-d H:i:s',time()-$min*60);}
function daysAgo(float $days):string{return minutesAgo((int)round($days*1440));}
function clampToday(string $d):string{$today=date('Y-m-d');return $d>$today?$today:$d;}
function slotPool(int $fromDay,int $toDay):array{$out=[];foreach(range($fromDay,$toDay) as $d)foreach([9,11,13,15,17] as $h)$out[]=[$d,$h];shuffle($out);return $out;}
function slotDate(array $slot):string{[$d,$h]=$slot;$sign=$d>=0?'+':'';return date('Y-m-d',strtotime($sign.$d.' days')).' '.sprintf('%02d:00:00',$h);}
function fullName(array $first,array $last):string{return pick($first).' '.pick($last);}

$firstNames=['Ana','Beatriz','Carla','Daniela','Eduarda','Fernanda','Gabriela','Helena','Isabela','Juliana','Camila','Larissa','Mariana','Natália','Patrícia','Rafaela','Sabrina','Tatiane','Vanessa','Bruno','Carlos','Diego','Eduardo','Felipe','Gustavo','Henrique','Igor','João','Kleber','Lucas','Marcelo','Nelson','Otávio','Paulo','Rodrigo','Sérgio','Thiago','Vinícius','William','Rafael'];
$lastNames=['Silva','Santos','Oliveira','Souza','Rodrigues','Ferreira','Almeida','Pereira','Lima','Gomes','Costa','Ribeiro','Martins','Carvalho','Araújo','Melo','Barbosa','Rocha','Dias','Nunes','Teixeira','Moreira','Cardoso','Correia'];
$regions=['Centro','Jardim Paulista','Vila Mariana','Moema','Pinheiros','Vila Madalena','Tatuapé','Santana','Brooklin','Itaim Bibi','Campinas - Cambuí','Campinas - Taquaral','Jundiaí - Anhangabaú','Jundiaí - Centro','Sorocaba - Centro','Alphaville'];

// 1) usuários (4 fixos de sempre + equipe extra para variedade nos gráficos)
$users=[['Administrador','admin@vlninfo.local','admin'],['Gestora','gestor@vlninfo.local','manager'],['SDR Demonstração','sdr@vlninfo.local','sdr'],['Corretor Demonstração','corretor@vlninfo.local','broker'],['Analista de Crédito','analista@vlninfo.local','analyst']];
$extraSdrNames=['Marina Souza','Rafael Costa','Beatriz Andrade'];
$extraBrokerNames=['Diego Martins','Camila Ferreira','Lucas Pereira','Fernanda Rocha','Gustavo Lima'];
foreach($extraSdrNames as $n)$users[]=[$n,slugify($n).'@vlninfo.local','sdr'];
foreach($extraBrokerNames as $n)$users[]=[$n,slugify($n).'@vlninfo.local','broker'];
$hash=password_hash('Demo@123',PASSWORD_DEFAULT);
$q=$pdo->prepare('INSERT INTO users(id,name,email,password_hash,role) VALUES(?,?,?,?,?)');
$userIds=[];
foreach($users as $u){$id=uid();$q->execute([$id,$u[0],$u[1],$hash,$u[2]]);$userIds[$u[1]]=$id;}
$adminId=$userIds['admin@vlninfo.local'];$gestorId=$userIds['gestor@vlninfo.local'];
$sdrIds=[$userIds['sdr@vlninfo.local']];foreach($extraSdrNames as $n)$sdrIds[]=$userIds[slugify($n).'@vlninfo.local'];
$brokerIds=[$userIds['corretor@vlninfo.local']];foreach($extraBrokerNames as $n)$brokerIds[]=$userIds[slugify($n).'@vlninfo.local'];
$adminUser=['id'=>$adminId,'role'=>'admin'];

// 2) origens de lead
$sources=[['local','Entrada local','local','Token WEBHOOK_TOKEN'],['site','Site e formulários','awaiting_credentials','URL/formato do formulário e segredo de assinatura'],['olx','OLX/Canal Pro','awaiting_credentials','Documentação da API, credenciais e formato/assinatura do webhook'],['quintoandar','QuintoAndar','awaiting_credentials','Documentação, credenciais e permissões da conta'],['whatsapp','WhatsApp oficial','awaiting_credentials','Meta Business, WABA, número, token e segredo do app'],['email','E-mail','awaiting_credentials','Provedor, OAuth/IMAP e regras de consentimento'],['social','Redes sociais','awaiting_credentials','Plataformas, contas comerciais, apps e permissões'],['partner','Outros portais/parceiros','awaiting_credentials','Documentação e credenciais de cada parceiro']];
$q=$pdo->prepare('INSERT INTO lead_sources(id,code,name,connector_status,requirements) VALUES(?,?,?,?,?)');
$sourceIdByCode=[];
foreach($sources as $s){$id=uid();$q->execute([$id,...$s]);$sourceIdByCode[$s[0]]=$id;}

// 3) fluxos de automação (compra/locação originais + um de dúvidas com transferência humana)
$leadSvc=new LeadService($pdo);
$flowService=new FlowService($pdo,$leadSvc);
function publishFlow(FlowService $flowService,string $name,array $nodes,array $admin):string{$result=$flowService->save(null,$name,['nodes'=>$nodes],$admin);$flowService->publish($result['id'],$admin);return $result['id'];}
$leadFlowNodes=function(string $interest){return [['id'=>'start','type'=>'start','config'=>[],'next'=>'welcome'],['id'=>'welcome','type'=>'message','config'=>['text'=>'Olá! Vou fazer algumas perguntas rápidas.'],'next'=>'name'],['id'=>'name','type'=>'question','config'=>['text'=>'Qual é o seu nome?','field'=>'name'],'next'=>'phone'],['id'=>'phone','type'=>'question','config'=>['text'=>'Qual é o seu telefone com DDD?','field'=>'phone'],'next'=>'region'],['id'=>'region','type'=>'question','config'=>['text'=>'Em qual região você procura?','field'=>'region'],'next'=>'value'],['id'=>'value','type'=>'question','config'=>['text'=>'Qual é o valor máximo pretendido?','field'=>'max_value'],'next'=>'create'],['id'=>'create','type'=>'create_lead','config'=>['interest_type'=>$interest],'next'=>'done'],['id'=>'done','type'=>'message','config'=>['text'=>'Obrigado! Encaminhamos seus dados para a triagem.'],'next'=>'end'],['id'=>'end','type'=>'end','config'=>[]]];};
$purchaseFlowId=publishFlow($flowService,'Interesse em compra',$leadFlowNodes('purchase'),$adminUser);
$rentalFlowId=publishFlow($flowService,'Interesse em locação',$leadFlowNodes('rental'),$adminUser);
$supportFlowId=publishFlow($flowService,'Dúvidas frequentes',[
  ['id'=>'start','type'=>'start','config'=>[],'next'=>'greet'],
  ['id'=>'greet','type'=>'message','config'=>['text'=>'Olá! Sou o assistente virtual da IMOB HUB. Posso te ajudar com informações rápidas.'],'next'=>'topic'],
  ['id'=>'topic','type'=>'question','config'=>['text'=>'Sobre qual imóvel ou assunto você quer falar? Me diga o código ou o bairro.','field'=>'topic'],'next'=>'human'],
  ['id'=>'human','type'=>'human','config'=>[]],
],$adminUser);

// 4) rodízio automático ativo para a demonstração
$pdo->prepare('UPDATE distribution_settings SET automatic_enabled=1,updated_by=? WHERE id=1')->execute([$adminId]);

// 5) imóveis
$propertyTypes=[
  'Apartamento'=>['purchase'=>[250000,1200000],'rental'=>[1500,6000]],
  'Casa'=>['purchase'=>[300000,2000000],'rental'=>[2000,9000]],
  'Cobertura'=>['purchase'=>[700000,3500000],'rental'=>[5000,15000]],
  'Terreno'=>['purchase'=>[150000,900000],'rental'=>null],
  'Sala Comercial'=>['purchase'=>[200000,1500000],'rental'=>[2000,12000]],
  'Galpão'=>['purchase'=>[500000,3000000],'rental'=>[6000,20000]],
];
$q=$pdo->prepare('INSERT INTO properties(id,reference_code,title,property_type,address,region,purpose,price,status,listing_status,created_by,reviewed_by,reviewed_at,neighborhood,city,state,street,street_number,zip_code,bedrooms,suites,bathrooms,parking_spaces,area_built,area_total,condo_fee,iptu_yearly,rental_price,description,owner_name,owner_phone,accepts_financing,accepts_pets) VALUES(?,?,?,?,?,?,?,?,?,\'approved\',?,?,CURRENT_TIMESTAMP,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1)');
$properties=[];
for($i=1;$i<=50;$i++){
  $type=pick(array_keys($propertyTypes));
  $ranges=$propertyTypes[$type];
  if($ranges['rental']===null)$purpose='purchase';
  else{$roll=mt_rand(1,100);$purpose=$roll<=55?'purchase':($roll<=85?'rental':'both');}
  $range=$purpose==='rental'?$ranges['rental']:$ranges['purchase'];
  $price=mt_rand($range[0],$range[1]);
  $region=pick($regions);
  $id=uid();
  $title=$type.' em '.$region;
  [$city,$hood]=str_contains($region,' - ')?explode(' - ',$region,2):[$region==='Alphaville'?'Barueri':'São Paulo',$region];
  $land=$type==='Terreno';$commercial=in_array($type,['Sala Comercial','Galpão'],true);
  $beds=$land||$commercial?null:mt_rand(1,4);$baths=$land?null:($commercial?mt_rand(1,3):mt_rand(1,max(1,$beds)));
  $area=$land?null:($type==='Galpão'?mt_rand(400,3000):($commercial?mt_rand(30,250):mt_rand(40,90)*($beds?:1)/2+20));
  $rent=$purpose==='both'?mt_rand($ranges['rental'][0],$ranges['rental'][1]):null;
  $desc=$type.' em '.$hood.', '.($beds?"$beds quarto(s), ":'').($area?"$area m² de área útil, ":'').'próximo a comércio, transporte e serviços. Imóvel de demonstração gerado pelo seed.';
  $q->execute([$id,'REF-'.str_pad((string)$i,4,'0',STR_PAD_LEFT),$title,$type,'Rua '.$i.', '.$region,$region,$purpose,$price,'available',$adminId,$adminId,$hood,$city,'SP','Rua '.$i,(string)mt_rand(10,999),sprintf('%05d-%03d',mt_rand(1000,19999),mt_rand(0,999)),$beds,$beds?mt_rand(0,min(2,$beds)):null,$baths,$land?null:mt_rand(0,3),$area,$land?mt_rand(250,1200):$area,in_array($type,['Apartamento','Cobertura','Sala Comercial'],true)?mt_rand(300,2500):0,mt_rand(600,9000),$rent,$desc,'Proprietário '.$i,'(11) 9'.mt_rand(1000,9999).'-'.mt_rand(1000,9999)]);
  $properties[$id]=['id'=>$id,'purpose'=>$purpose,'price'=>$price,'type'=>$type,'region'=>$region,'title'=>$title,'status'=>'available'];
}
foreach(array_rand($properties,4) as $rid){$properties[$rid]['status']='reserved';$pdo->prepare("UPDATE properties SET status='reserved' WHERE id=?")->execute([$rid]);}
function pickAvailable(array &$properties,string $interest){
  $pool=array_values(array_filter($properties,fn($p)=>in_array($p['purpose'],[$interest,'both'],true)&&!in_array($p['status'],['sold','rented'],true)));
  if(!$pool)throw new RuntimeException('Sem imóveis disponíveis para '.$interest);
  return pick($pool);
}

// 6) leads percorrendo o funil real (mesmas regras de negócio da API)
$sdrSvc=new SdrService($pdo);
$commercial=new CommercialService($pdo);
$ops=new OperationsService($pdo);
$pastVisitPool=[];$futureVisitPool=[];
foreach($brokerIds as $b){$pastVisitPool[$b]=slotPool(-75,-3);$futureVisitPool[$b]=slotPool(2,14);}
$sourceWeights=['olx'=>38,'site'=>24,'partner'=>16,'social'=>12,'whatsapp'=>4,'quintoandar'=>3,'email'=>2,'local'=>1];
$lossReasons=['Sem retorno do cliente','Escolheu outro imóvel','Fora do orçamento','Desistiu da compra','Financiamento não aprovado'];
$sdrCursor=0;

for($i=1;$i<=300;$i++){
  $name=fullName($firstNames,$lastNames);
  $interest=mt_rand(1,100)<=65?'purchase':'rental';
  $sourceCode=weighted($sourceWeights);
  $createdDaysAgo=mt_rand(0,90*1440)/1440;
  $createdAt=daysAgo($createdDaysAgo);
  $sdrId=$sdrIds[$sdrCursor%count($sdrIds)];$sdrCursor++;
  $budget=$interest==='purchase'?mt_rand(200000,1500000):mt_rand(1500,8000);
  $lead=$leadSvc->create([
    'name'=>$name,'email'=>slugify($name).$i.'@teste-demo.local','phone'=>sprintf('119%08d',$i),
    'source_id'=>$sourceIdByCode[$sourceCode],'interest_type'=>$interest,'region'=>pick($regions),
    'min_value'=>(int)($budget*0.7),'max_value'=>$budget,'sdr_id'=>$sdrId,
  ],$adminId);
  $leadId=$lead['id'];$contactId=$lead['contact_id'];
  $pdo->prepare('UPDATE opportunities SET created_at=?,updated_at=? WHERE id=?')->execute([$createdAt,$createdAt,$leadId]);
  $pdo->prepare('UPDATE contacts SET created_at=?,updated_at=? WHERE id=?')->execute([$createdAt,$createdAt,$contactId]);

  $r=mt_rand(1,100);
  if($r<=7){$sdrSvc->contact($leadId,['result'=>'attempt'],$adminUser);continue;}
  $sdrSvc->contact($leadId,['result'=>'completed'],$adminUser);
  if($r<=15)continue;
  if($r<=25){$sdrSvc->qualify($leadId,['result'=>pick(['no_interest','invalid','duplicate']),'reason'=>pick($lossReasons)],$adminUser);continue;}
  if($r<=40){
    $when=mt_rand(1,100);
    $nextContact=$when<=33?minutesAgo(mt_rand(60,4320)):($when<=66?minutesAgo(mt_rand(-720,-30)):minutesAgo(-mt_rand(1440,20160)));
    $sdrSvc->qualify($leadId,['result'=>'nurturing','reason'=>'Vai decidir em breve','next_contact_at'=>$nextContact,'property_type'=>pick(array_keys($propertyTypes))],$adminUser);
    continue;
  }

  $sdrSvc->qualify($leadId,['result'=>'qualified','property_type'=>pick(array_keys($propertyTypes)),'decision_timing'=>pick(['Imediato','Até 3 meses','Até 6 meses']),'needs'=>'Busca imóvel em '.pick($regions)],$adminUser);
  $pdo->prepare('UPDATE opportunities SET qualified_at=? WHERE id=?')->execute([daysAgo(max(0,$createdDaysAgo-mt_rand(0,3))),$leadId]);
  if(mt_rand(1,100)<=5)continue;

  $distributed=$sdrSvc->distribute($leadId,null,$adminUser,null,true);
  $brokerId=$distributed['broker_id'];
  $brokerUser=['id'=>$brokerId,'role'=>'broker'];
  $ops->audit($adminId,'assigned','opportunity',$leadId,'Oportunidade distribuída',['broker'=>$brokerId,'method'=>'round_robin']);
  $sdrSvc->brokerStatus($leadId,'received',$brokerUser);
  if(mt_rand(1,100)<=85)$sdrSvc->brokerStatus($leadId,'started',$brokerUser);
  if(mt_rand(1,100)>70)continue;

  $property=pickAvailable($properties,$interest);
  $commercial->present($leadId,$property['id'],$brokerUser,'Aderente ao perfil buscado.');

  if(mt_rand(1,100)<=20){
    $slot=array_pop($futureVisitPool[$brokerId])??[mt_rand(2,14),13];
    $commercial->visit($leadId,['property_id'=>$property['id'],'scheduled_at'=>slotDate($slot),'location'=>$property['region']],$brokerUser);
    continue;
  }
  $slot=array_pop($pastVisitPool[$brokerId])??[-mt_rand(3,75),13];
  $visit=$commercial->visit($leadId,['property_id'=>$property['id'],'scheduled_at'=>slotDate($slot),'location'=>$property['region']],$brokerUser);
  $ops->audit($brokerId,'visit_created','visit',$visit['id'],'Visita criada');

  $vr=mt_rand(1,100);
  if($vr<=15){$commercial->visitUpdate($visit['id'],['status'=>'cancelled','cancellation_reason'=>pick(['Cliente remarcou','Imprevisto do cliente','Corretor indisponível'])],$brokerUser);$ops->audit($brokerId,'visit_cancelled','visit',$visit['id'],'Visita atualizada');continue;}
  $commercial->visitUpdate($visit['id'],['status'=>'completed','feedback'=>pick(['Gostou muito do imóvel','Achou o preço alto','Quer ver outras opções','Vai conversar em família'])],$brokerUser);
  $ops->audit($brokerId,'visit_completed','visit',$visit['id'],'Visita atualizada');
  if($vr<=35)continue;

  $amount=round($property['price']*(mt_rand(90,100)/100),2);
  $proposalDate=clampToday(date('Y-m-d',strtotime('+'.mt_rand(1,5).' days',strtotime(slotDate($slot)))));
  $proposal=$commercial->proposal($leadId,['property_id'=>$property['id'],'proposal_type'=>$interest,'amount'=>$amount,'proposal_date'=>$proposalDate,'valid_until'=>date('Y-m-d',strtotime('+15 days',strtotime($proposalDate)))],$brokerUser);

  $targetRoll=mt_rand(1,100);
  $target=$targetRoll<=15?'draft':($targetRoll<=35?'sent':($targetRoll<=50?'rejected':($targetRoll<=65?'cancelled':($targetRoll<=85?'under_review':'accepted'))));
  if($target!=='draft'){
    $proposal=$commercial->proposalUpdate($proposal['id'],['status'=>'sent'],$brokerUser);
    if(in_array($target,['rejected','cancelled'],true)){$commercial->proposalUpdate($proposal['id'],['status'=>$target],$brokerUser);}
    elseif(in_array($target,['under_review','accepted'],true)){
      $proposal=$commercial->proposalUpdate($proposal['id'],['status'=>'under_review'],$brokerUser);
      $commercial->negotiate($leadId,['proposal_id'=>$proposal['id'],'amount'=>round($amount*0.97,2),'notes'=>'Cliente pediu ajuste de valor.'],$brokerUser);
      if($target==='accepted')$commercial->proposalUpdate($proposal['id'],['status'=>'accepted'],$brokerUser);
    }
    $ops->audit($brokerId,'proposal_updated','proposal',$proposal['id'],'Proposta atualizada',['status'=>$target]);
  }
  if(!in_array($target,['under_review','accepted'],true))continue;

  $closeRoll=mt_rand(1,100);
  if($closeRoll<=65){
    $closingDate=clampToday(date('Y-m-d',strtotime('+'.mt_rand(3,15).' days',strtotime($proposalDate))));
    $commercial->close($leadId,['outcome'=>'won','property_id'=>$property['id'],'business_type'=>$interest,'final_amount'=>round($amount*(mt_rand(95,100)/100),2),'closing_date'=>$closingDate,'contract_reference'=>'CTR-'.$i],$brokerUser);
    $properties[$property['id']]['status']=$interest==='purchase'?'sold':'rented';
    $ops->audit($brokerId,'opportunity_closed','opportunity',$leadId,'Oportunidade encerrada',['outcome'=>'won']);
  } elseif($closeRoll<=85){
    $closingDate=clampToday(date('Y-m-d',strtotime('+'.mt_rand(1,10).' days',strtotime($proposalDate))));
    $commercial->close($leadId,['outcome'=>'lost','loss_reason'=>pick($lossReasons),'closing_date'=>$closingDate],$brokerUser);
    $ops->audit($brokerId,'opportunity_closed','opportunity',$leadId,'Oportunidade encerrada',['outcome'=>'lost']);
  }
}

// 7) compromissos de agenda para os corretores
$q=$pdo->prepare('INSERT INTO calendar_events(id,opportunity_id,responsible_id,title,event_type,starts_at,ends_at,status,notes,created_by) VALUES(?,NULL,?,?,?,?,?,\'scheduled\',?,?)');
foreach($brokerIds as $b){
  $q->execute([uid(),$b,'Reunião com cliente','commitment',date('Y-m-d').' 16:00:00',date('Y-m-d').' 17:00:00','Alinhamento de proposta',$adminId]);
  $q->execute([uid(),$b,'Follow-up por telefone','commitment',date('Y-m-d',strtotime('+1 day')).' 10:00:00',date('Y-m-d',strtotime('+1 day')).' 10:30:00',null,$adminId]);
}

// 8) notificações iniciais para os 4 usuários de demonstração
$ops->notify($adminId,'demo:proposals-review','Propostas aguardando revisão','Há propostas em análise aguardando decisão.','proposal',null);
$ops->notify($gestorId,'demo:rodizio-ativo','Rodízio automático ativado','A distribuição automática de leads está ativa.',null,null);
$ops->notify($sdrIds[0],'demo:novos-leads','Novos leads aguardando triagem','Existem leads novos aguardando primeiro contato.','opportunity',null);
$ops->notify($brokerIds[0],'demo:visita-agendada','Nova visita agendada','Você tem uma visita agendada nos próximos dias.','visit',null);

// 9) conversas de exemplo (algumas transferidas para atendimento humano, outras concluídas pela automação)
$conversationSvc=new ConversationService($pdo);
$customerOpeners=['Oi, boa tarde! Vi um anúncio, ainda está disponível?','Olá, gostaria de saber sobre um imóvel para alugar.','Bom dia, poderiam me passar mais informações sobre a Cobertura em Moema?','Oi, quero agendar uma visita.','Olá, ainda não recebi retorno sobre minha proposta.','Boa tarde, o imóvel do Jardim Paulista já foi vendido?','Oi! Tenho interesse em terrenos na região de Jundiaí.','Olá, poderia me explicar as condições de financiamento?'];
for($i=1;$i<=8;$i++){
  $conversationId='demo-conv-'.$i;
  $flowService->message($supportFlowId,$conversationId,$customerOpeners[$i-1],false,$adminUser);
  $flowService->message($supportFlowId,$conversationId,pick(['Rua Augusta, 500','Bairro Moema','Código REF-0012','Zona Sul']),false,$adminUser);
}
$convRows=$pdo->query("SELECT id FROM conversations WHERE provider='simulator' AND external_id LIKE 'demo-conv-%' ORDER BY external_id")->fetchAll(PDO::FETCH_COLUMN);
$sdrActor=['id'=>$sdrIds[0],'role'=>'sdr'];
if(!empty($convRows[0])){$conversationSvc->assign($convRows[0],$sdrActor);$conversationSvc->reply($convRows[0],'Oi! Claro, posso te ajudar. Um momento que já busco os detalhes.',$sdrActor);$conversationSvc->resolve($convRows[0],$sdrActor);}
if(!empty($convRows[1])){$conversationSvc->assign($convRows[1],$sdrActor);$conversationSvc->reply($convRows[1],'Oi! Já verifiquei aqui, um momento que te retorno com os detalhes.',$sdrActor);}

$phoneCounter=9000;
foreach([['label'=>'compra','flow'=>$purchaseFlowId,'min'=>300000,'max'=>900000],['label'=>'locacao','flow'=>$rentalFlowId,'min'=>2000,'max'=>6000]] as $cfg){
  for($i=1;$i<=3;$i++){
    $conversationId='demo-lead-'.$cfg['label'].'-'.$i;
    $flowService->message($cfg['flow'],$conversationId,'Olá',true,$adminUser);
    $flowService->message($cfg['flow'],$conversationId,fullName($firstNames,$lastNames),true,$adminUser);
    $flowService->message($cfg['flow'],$conversationId,sprintf('119%08d',++$phoneCounter),true,$adminUser);
    $flowService->message($cfg['flow'],$conversationId,pick($regions),true,$adminUser);
    $flowService->message($cfg['flow'],$conversationId,(string)mt_rand($cfg['min'],$cfg['max']),true,$adminUser);
  }
}

// 10) ajuste fino de datas em tabelas auxiliares, para o histórico não parecer todo "de hoje"
$pdo->exec('UPDATE interactions i JOIN opportunities o ON o.id=i.opportunity_id SET i.created_at=DATE_ADD(o.created_at, INTERVAL FLOOR(RAND()*168) HOUR)');
$pdo->exec('UPDATE distributions d JOIN opportunities o ON o.id=d.opportunity_id SET d.created_at=DATE_ADD(o.created_at, INTERVAL FLOOR(1+RAND()*48) HOUR)');
$pdo->exec('UPDATE negotiations n JOIN opportunities o ON o.id=n.opportunity_id SET n.created_at=DATE_ADD(o.created_at, INTERVAL FLOOR(72+RAND()*240) HOUR)');
$pdo->exec("UPDATE proposals SET created_at=CONCAT(proposal_date,' 10:00:00'), updated_at=CONCAT(proposal_date,' 10:00:00')");
$pdo->exec('UPDATE proposal_events pe JOIN proposals p ON p.id=pe.proposal_id SET pe.created_at=p.created_at');
$pdo->exec("UPDATE closings SET created_at=CONCAT(closing_date,' 17:00:00'), updated_at=CONCAT(closing_date,' 17:00:00')");
$pdo->exec("UPDATE audit_events a JOIN opportunities o ON o.id=a.entity_id AND a.entity_type='opportunity' SET a.created_at=DATE_ADD(o.created_at, INTERVAL FLOOR(RAND()*200) HOUR)");
$pdo->exec("UPDATE audit_events a JOIN visits v ON v.id=a.entity_id AND a.entity_type='visit' SET a.created_at=v.scheduled_at");
$pdo->exec("UPDATE audit_events a JOIN proposals p ON p.id=a.entity_id AND a.entity_type='proposal' SET a.created_at=p.created_at");

$stageCounts=$pdo->query("SELECT stage,COUNT(*) total FROM opportunities GROUP BY stage")->fetchAll(PDO::FETCH_KEY_PAIR);
echo "Dados de demonstração criados. Senha de todos os usuários: Demo@123\n";
echo "Leads por etapa: ".json_encode($stageCounts)."\n";
