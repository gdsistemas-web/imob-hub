<?php
declare(strict_types=1);

/** Configurações editáveis pelo administrador, guardadas como JSON por chave e validadas por um esquema fixo. */
final class SettingsService {
 private const SCHEMA = [
  'company' => ['name'=>'text','legal_name'=>'text','cnpj'=>'text','creci'=>'text','phone'=>'text','whatsapp'=>'text','email'=>'email','address'=>'text','city'=>'text','state'=>'text'],
  'site' => ['hero_title'=>'text','hero_subtitle'=>'long','about'=>'long','chat_enabled'=>'bool','chat_greeting'=>'long','business_hours'=>'text'],
  'contracts' => ['foro'=>'text','commission_percent'=>'number','commission_payer'=>'text','penalty_percent'=>'number','deed_days'=>'number','signal_percent'=>'number','lease_months'=>'number','lease_due_day'=>'number','lease_index'=>'text','lease_penalty_rents'=>'number','deposit_months'=>'number'],
 ];

 public function __construct(private PDO $db) {}

 public function get(string $key): array {
  if (!isset(self::SCHEMA[$key])) throw new RuntimeException('Configuração inexistente.');
  $q = $this->db->prepare('SELECT value FROM app_settings WHERE setting_key=?'); $q->execute([$key]);
  $stored = json_decode((string)($q->fetchColumn() ?: '{}'), true) ?: [];
  $out = [];
  foreach (self::SCHEMA[$key] as $field => $type) $out[$field] = $stored[$field] ?? ($type === 'bool' ? false : '');
  return $out;
 }

 public function put(string $key, array $data, array $u): array {
  if (!isset(self::SCHEMA[$key])) throw new RuntimeException('Configuração inexistente.');
  $current = $this->get($key);
  foreach (self::SCHEMA[$key] as $field => $type) {
   if (!array_key_exists($field, $data)) continue;
   $v = $data[$field];
   $current[$field] = match ($type) {
    'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
    'long' => mb_substr(trim((string)$v), 0, 2000),
    'number' => (function ($v) { $v = trim(str_replace(',', '.', (string)$v)); if ($v !== '' && (!is_numeric($v) || (float)$v < 0)) throw new InvalidArgumentException('Use apenas números positivos nos padrões de contrato.'); return $v; })($v),
    'email' => (function ($v) { $v = trim((string)$v); if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail inválido.'); return $v; })($v),
    default => mb_substr(trim((string)$v), 0, 190),
   };
  }
  if ($key === 'company' && trim($current['name']) === '') throw new InvalidArgumentException('Informe o nome da imobiliária.');
  if ($key === 'company') $current['state'] = strtoupper(mb_substr($current['state'], 0, 2));
  if ($key === 'contracts') {
   if ($current['lease_due_day'] !== '' && ((int)$current['lease_due_day'] < 1 || (int)$current['lease_due_day'] > 28)) throw new InvalidArgumentException('O dia de vencimento deve estar entre 1 e 28.');
   if ($current['deposit_months'] !== '' && (float)$current['deposit_months'] > 3) throw new InvalidArgumentException('A caução não pode passar de 3 aluguéis (art. 38 da Lei 8.245/1991).');
   if (!in_array($current['commission_payer'], ['vendedor','comprador'], true)) $current['commission_payer'] = 'vendedor';
  }
  $json = json_encode($current, JSON_UNESCAPED_UNICODE);
  $exists = $this->db->prepare('SELECT 1 FROM app_settings WHERE setting_key=?'); $exists->execute([$key]);
  $sql = $exists->fetchColumn() ? 'UPDATE app_settings SET value=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE setting_key=?' : 'INSERT INTO app_settings(value,updated_by,setting_key) VALUES(?,?,?)';
  $this->db->prepare($sql)->execute([$json, $u['id'], $key]);
  return $current;
 }
}
