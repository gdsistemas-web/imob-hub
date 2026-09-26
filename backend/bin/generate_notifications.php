<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/OperationsService.php';$db=db();$ops=new OperationsService($db);$count=0;
$q=$db->query("SELECT t.*,u.name FROM tasks t JOIN users u ON u.id=t.assigned_to WHERE t.status='pending' AND t.due_at<=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 24 HOUR)");
foreach($q as $t){$overdue=strtotime($t['due_at'])<time();$bucket=$overdue?'overdue':'due-soon';$ops->notify($t['assigned_to'],'task:'.$t['id'].':'.$bucket.':'.date('Y-m-d-H',strtotime($t['due_at'])),$overdue?'Tarefa vencida':'Tarefa próxima do prazo',$t['title'],'task',$t['id']);$count++;}
$q=$db->query("SELECT p.id,p.opportunity_id,p.valid_until,o.broker_id FROM proposals p JOIN opportunities o ON o.id=p.opportunity_id WHERE p.status IN ('sent','under_review') AND p.valid_until BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE,INTERVAL 2 DAY) AND o.broker_id IS NOT NULL");
foreach($q as $p){$ops->notify($p['broker_id'],'proposal:'.$p['id'].':expiry:'.$p['valid_until'],'Proposta próxima da validade','Validade: '.date('d/m/Y',strtotime($p['valid_until'])),'proposal',$p['id']);$count++;}
echo "Notificações avaliadas: $count\n";
