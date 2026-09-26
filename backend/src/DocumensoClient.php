<?php
declare(strict_types=1);

/**
 * Cliente da API v2 do Documenso (self-hosted ou nuvem).
 * Configuração: DOCUMENSO_URL (ex.: http://localhost:3000) e DOCUMENSO_API_KEY (Settings → API Tokens do Documenso).
 * Fluxo: envelope/create (PDF + destinatários + campos) → envelope/distribute (envia os e-mails) →
 * webhook DOCUMENT_COMPLETED → envelope/item/{id}/download?version=signed.
 */
class DocumensoClient {
 public function __construct(private string $baseUrl, private string $apiKey, private int $timeout = 30) {}

 public static function fromEnv(): ?self {
  $url = trim((string)getenv('DOCUMENSO_URL')); $key = trim((string)getenv('DOCUMENSO_API_KEY'));
  return $url !== '' && $key !== '' ? new self(rtrim($url, '/').'/api/v2', $key) : null;
 }

 /**
  * @param array $recipients [['name'=>..,'email'=>..,'fields'=>[['page'=>1,'positionX'=>..,'positionY'=>..,'width'=>..,'height'=>..]]]]
  * @return string envelopeId
  */
 public function createEnvelope(string $title, string $externalId, string $pdf, string $fileName, array $recipients, array $meta = []): string {
  $payload = [
   'type'=>'DOCUMENT', 'title'=>mb_substr($title, 0, 250), 'externalId'=>$externalId,
   'recipients'=>array_map(fn($r, $i) => [
    'email'=>$r['email'], 'name'=>$r['name'], 'role'=>'SIGNER', 'signingOrder'=>$i + 1,
    'fields'=>array_map(fn($f) => ['identifier'=>0, 'type'=>'SIGNATURE'] + array_intersect_key($f, array_flip(['page','positionX','positionY','width','height'])), $r['fields']),
   ], $recipients, array_keys($recipients)),
   'meta'=>$meta + ['language'=>'pt-BR', 'timezone'=>'America/Sao_Paulo', 'dateFormat'=>'dd/MM/yyyy HH:mm', 'signingOrder'=>'PARALLEL', 'distributionMethod'=>'EMAIL'],
  ];
  $r = $this->request('POST', '/envelope/create', ['payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE), 'files'=>new CURLStringFile($pdf, $fileName, 'application/pdf')]);
  if (empty($r['id'])) throw new IntegrationException('O Documenso não retornou o identificador do envelope.');
  return (string)$r['id'];
 }

 public function distribute(string $envelopeId, array $meta = []): void {
  $this->request('POST', '/envelope/distribute', ['envelopeId'=>$envelopeId] + ($meta ? ['meta'=>$meta] : []), true);
 }

 public function get(string $envelopeId): array { return $this->request('GET', '/envelope/'.rawurlencode($envelopeId)); }

 public function cancel(string $envelopeId, string $reason): void {
  $this->request('POST', '/envelope/cancel', ['envelopeId'=>$envelopeId, 'reason'=>mb_substr($reason, 0, 500)], true);
 }

 /** Baixa o PDF assinado (primeiro item do envelope). */
 public function downloadSigned(string $envelopeId): string {
  $env = $this->get($envelopeId);
  $item = $env['envelopeItems'][0]['id'] ?? null;
  if (!$item) throw new IntegrationException('Envelope sem documento para baixar.');
  return $this->request('GET', '/envelope/item/'.rawurlencode((string)$item).'/download?version=signed', null, false, true);
 }

 private function request(string $method, string $path, mixed $body = null, bool $json = false, bool $raw = false): mixed {
  $ch = curl_init($this->baseUrl.$path);
  $headers = ['Authorization: '.$this->apiKey, 'Accept: '.($raw ? 'application/pdf' : 'application/json')];
  $opts = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_TIMEOUT=>$this->timeout, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>3];
  if ($body !== null) {
   if ($json) { $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }
   else $opts[CURLOPT_POSTFIELDS] = $body; // multipart
  }
  $opts[CURLOPT_HTTPHEADER] = $headers;
  curl_setopt_array($ch, $opts);
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch);
  curl_close($ch);
  if ($res === false) throw new IntegrationException('Não foi possível conectar ao Documenso: '.$err);
  if ($code < 200 || $code >= 300) {
   $msg = json_decode((string)$res, true)['message'] ?? mb_substr(strip_tags((string)$res), 0, 200);
   throw new IntegrationException("Documenso respondeu $code: $msg");
  }
  if ($raw) return (string)$res;
  return $res === '' ? [] : (json_decode((string)$res, true) ?? []);
 }
}
