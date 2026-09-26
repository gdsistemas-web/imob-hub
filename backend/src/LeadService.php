<?php
declare(strict_types=1);
final class LeadService {
 public function __construct(private PDO $db){}
 public function create(array $d, ?string $actor=null): array {
  validate_required($d,['name','source_id','interest_type']);
  if(!in_array($d['interest_type'],['purchase','rental'],true)) throw new InvalidArgumentException('Tipo de interesse inválido.');
  if(isset($d['email']) && $d['email']!=='' && !filter_var($d['email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail inválido.');
  $this->db->beginTransaction(); try {
   if(!empty($d['external_id'])){$q=$this->db->prepare('SELECT id FROM opportunities WHERE source_id=? AND external_id=?');$q->execute([$d['source_id'],$d['external_id']]);if($q->fetch())throw new DomainException('Identificador externo já recebido.');}
   $matches=[]; if(!empty($d['email'])||!empty($d['phone'])){$parts=[];$args=[];if(!empty($d['email'])){$parts[]='email=?';$args[]=$d['email'];}if(!empty($d['phone'])){$parts[]='phone=?';$args[]=$d['phone'];}$q=$this->db->prepare('SELECT * FROM contacts WHERE '.implode(' OR ',$parts));$q->execute($args);$matches=$q->fetchAll();}
   if(count($matches)>1) throw new DomainException('Contato ambíguo: revise e associe manualmente.');
   $contact=$matches[0]??null;$contactId=$contact['id']??uid();
   if(!$contact){$q=$this->db->prepare('INSERT INTO contacts(id,name,email,phone) VALUES(?,?,?,?)');$q->execute([$contactId,trim($d['name']),$d['email']??null,$d['phone']??null]);}
   $sdrId=$d['sdr_id']??null;if(!$sdrId){$s=$this->db->query("SELECT id FROM users WHERE role='sdr' AND active=1 ORDER BY created_at,id LIMIT 1");$sdrId=$s->fetchColumn()?:null;}
   $id=uid();$q=$this->db->prepare('INSERT INTO opportunities(id,contact_id,source_id,external_id,interest_type,region,min_value,max_value,property_interest,stage,sdr_id,broker_id,first_contact_due_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
   $q->execute([$id,$contactId,$d['source_id'],$d['external_id']??null,$d['interest_type'],$d['region']??null,$d['min_value']??null,$d['max_value']??null,$d['property_interest']??null,$d['stage']??'qualification',$sdrId,$d['broker_id']??null,date('Y-m-d H:i:s',time()+1800)]);
   $q=$this->db->prepare('INSERT INTO interactions(id,opportunity_id,user_id,type,description) VALUES(?,?,?,?,?)');$q->execute([uid(),$id,$actor,'created','Lead cadastrado']);
   $this->db->commit(); return $this->get($id);
  }catch(Throwable $e){$this->db->rollBack();throw $e;}
 }
 public function get(string $id): array {$q=$this->db->prepare('SELECT o.*,c.name,c.email,c.phone,s.name source_name,u.name broker_name FROM opportunities o JOIN contacts c ON c.id=o.contact_id JOIN lead_sources s ON s.id=o.source_id LEFT JOIN users u ON u.id=o.broker_id WHERE o.id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Lead não encontrado.');$q=$this->db->prepare('SELECT i.*,u.name user_name FROM interactions i LEFT JOIN users u ON u.id=i.user_id WHERE opportunity_id=? ORDER BY created_at DESC');$q->execute([$id]);$r['history']=$q->fetchAll();return $r;}
 public function move(string $id,string $stage,?string $reason, string $actor): array {$old=$this->get($id);$flow=['qualification'=>['service','nurturing'],'nurturing'=>['qualification'],'service'=>['visit','proposal'],'visit'=>['service','proposal'],'proposal'=>['negotiation','visit'],'negotiation'=>['proposal'],'won'=>[],'lost'=>[]];if(!in_array($stage,$flow[$old['stage']]??[],true))throw new DomainException('Transição comercial não permitida. Use o fluxo de fechamento para ganhar ou perder.');$q=$this->db->prepare('UPDATE opportunities SET stage=?,loss_reason=NULL WHERE id=?');$q->execute([$stage,$id]);$q=$this->db->prepare('INSERT INTO interactions(id,opportunity_id,user_id,type,description) VALUES(?,?,?,?,?)');$q->execute([uid(),$id,$actor,'stage_changed',stage_label($old['stage']).' → '.stage_label($stage)]);return $this->get($id);}
}
