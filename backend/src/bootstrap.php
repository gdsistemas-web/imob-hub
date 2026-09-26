<?php
declare(strict_types=1);
final class AuthorizationException extends RuntimeException {}
final class RateLimitException extends RuntimeException {}
/** Falha em serviço externo (ex.: Documenso); a API responde 502. */
final class IntegrationException extends RuntimeException {}

function env_load(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) putenv(trim($key).'='.trim($value));
    }
}
env_load(dirname(__DIR__, 2).'/.env');
env_load(dirname(__DIR__).'/.env');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $dsn = getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=imob_hub;charset=utf8mb4';
        $pdo = new PDO($dsn, getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (str_starts_with($dsn, 'sqlite:')) $pdo->exec('PRAGMA foreign_keys=ON');
        else $pdo->exec("SET time_zone='".(new DateTime())->format('P')."'"); // mesmo fuso do PHP para CURRENT_TIMESTAMP e date() baterem
    }
    return $pdo;
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) throw new InvalidArgumentException('JSON inválido.');
    return $data;
}
function respond(mixed $data, int $status=200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
function validate_required(array $data, array $fields): void {
    $missing=[]; foreach($fields as $f) if(!isset($data[$f]) || trim((string)$data[$f])==='') $missing[]=$f;
    if($missing) throw new InvalidArgumentException('Campos obrigatórios: '.implode(', ', $missing));
}
function bearer(): ?string {
    $h=$_SERVER['HTTP_AUTHORIZATION']??''; return preg_match('/^Bearer\s+(.+)$/i',$h,$m)?$m[1]:null;
}
function auth(array $roles=[]): array {
    $token=bearer(); if(!$token) respond(['message'=>'Não autenticado.'],401);
    $q=db()->prepare('SELECT u.id,u.name,u.email,u.role FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.expires_at>CURRENT_TIMESTAMP AND u.active=1');
    $q->execute([hash('sha256',$token)]); $user=$q->fetch();
    if(!$user) respond(['message'=>'Sessão inválida ou expirada.'],401);
    if($roles && !in_array($user['role'],$roles,true)) respond(['message'=>'Você não tem permissão para esta ação.'],403);
    // o analista de crédito só enxerga a própria área; rotas genéricas (sem lista de perfis) ficam fechadas para ele
    if(!$roles && $user['role']==='analyst' && !preg_match('#^/api/(me|logout|notifications(/.*)?|credit(/.*)?)$#',(string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH))) respond(['message'=>'Você não tem permissão para esta ação.'],403);
    return $user;
}
function uid(): string { return bin2hex(random_bytes(16)); }
function stage_label(string $stage): string { return ['qualification'=>'Qualificação','service'=>'Atendimento','visit'=>'Visita','proposal'=>'Proposta','negotiation'=>'Negociação','won'=>'Ganho','lost'=>'Perdido','nurturing'=>'Nutrição'][$stage]??$stage; }
function authorize_opportunity(array $user, string $id): void {
    if (!in_array($user['role'],['broker','sdr'],true)) return;
    $column=$user['role']==='broker'?'broker_id':'sdr_id';
    $q=db()->prepare("SELECT 1 FROM opportunities WHERE id=? AND $column=?");
    $q->execute([$id,$user['id']]);
    if (!$q->fetchColumn()) respond(['message'=>'Você não tem permissão para acessar esta oportunidade.'],403);
}

/** Limite simples por janela fixa (ex.: 5 envios a cada 10 min por IP) para endpoints públicos. */
function rate_limit(string $bucket, int $max, int $window): void {
    $key=hash('sha256',$bucket);$now=time();$db=db();
    if(random_int(1,100)===1)$db->prepare('DELETE FROM rate_limits WHERE window_start<?')->execute([$now-86400]);
    $q=$db->prepare('SELECT window_start,hits FROM rate_limits WHERE bucket=?');$q->execute([$key]);$row=$q->fetch();
    if(!$row){try{$db->prepare('INSERT INTO rate_limits(bucket,window_start,hits) VALUES(?,?,1)')->execute([$key,$now]);}catch(PDOException){$db->prepare('UPDATE rate_limits SET hits=hits+1 WHERE bucket=?')->execute([$key]);}return;}
    if($now-(int)$row['window_start']>=$window){$db->prepare('UPDATE rate_limits SET window_start=?,hits=1 WHERE bucket=?')->execute([$now,$key]);return;}
    if((int)$row['hits']>=$max)throw new RateLimitException('Muitas tentativas em pouco tempo. Aguarde alguns minutos e tente novamente.');
    $db->prepare('UPDATE rate_limits SET hits=hits+1 WHERE bucket=?')->execute([$key]);
}
function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR']??'unknown'); }
function now_micro(): string { return (new DateTime())->format('Y-m-d H:i:s.u'); }

/** Cifra dados pessoais sensíveis (LGPD) com APP_ENCRYPTION_KEY; o banco guarda só o texto cifrado. */
function seal_json(array $data): string {
    $key=getenv('APP_ENCRYPTION_KEY')?:'';if(strlen($key)<32)throw new RuntimeException('Configure APP_ENCRYPTION_KEY com ao menos 32 caracteres.');
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce.sodium_crypto_secretbox(json_encode($data,JSON_UNESCAPED_UNICODE),$nonce,hash('sha256',$key,true)));
}
function open_json(?string $sealed): array {
    if(!$sealed)return [];
    $raw=base64_decode($sealed,true);$key=getenv('APP_ENCRYPTION_KEY')?:'';
    if($raw===false||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES||strlen($key)<32)throw new RuntimeException('Não foi possível ler os dados protegidos.');
    $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),hash('sha256',$key,true));
    if($plain===false)throw new RuntimeException('Dados protegidos corrompidos ou chave de criptografia alterada.');
    return json_decode($plain,true)?:[];
}
function valid_cpf(string $cpf): bool {
    $d=preg_replace('/\D/','',$cpf);if(strlen($d)!==11||preg_match('/^(\d)\1{10}$/',$d))return false;
    for($t=9;$t<11;$t++){$sum=0;for($i=0;$i<$t;$i++)$sum+=(int)$d[$i]*($t+1-$i);if((int)$d[$t]!==((10*$sum)%11)%10)return false;}
    return true;
}
function private_storage(): string {
    $dir=getenv('PRIVATE_STORAGE_PATH')?:dirname(__DIR__).'/storage/private';
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Não foi possível preparar armazenamento privado.');
    return $dir;
}
/** Grava um upload fora da pasta pública, validando o MIME real. Retorna [nome_interno, mime, bytes, sha256]. */
function store_private_upload(array $file, array $allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']): array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_file((string)($file['tmp_name']??'')))throw new InvalidArgumentException('Falha no upload.');
    $max=(int)(getenv('UPLOAD_MAX_BYTES')?:10485760);if($file['size']>$max)throw new InvalidArgumentException('Arquivo acima do limite configurado.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);if(!isset($allowed[$mime]))throw new InvalidArgumentException('Formato não permitido. Envie PDF, JPG, PNG ou WebP.');
    $name=bin2hex(random_bytes(24)).'.'.$allowed[$mime];$target=private_storage().'/'.$name;
    if(!(is_uploaded_file($file['tmp_name'])?move_uploaded_file($file['tmp_name'],$target):rename($file['tmp_name'],$target)))throw new RuntimeException('Falha ao armazenar documento.');
    chmod($target,0600);
    return [$name,$mime,(int)$file['size'],hash_file('sha256',$target)];
}

/** Regra única de criação de usuário (tela Equipe e backend/bin/create_admin.php). Retorna o id criado. */
function create_user(array $d): string {
    validate_required($d,['name','email','role','password']);
    if(!in_array($d['role'],['admin','manager','sdr','broker','analyst'],true))throw new InvalidArgumentException('Papel inválido.');
    if(strlen($d['password'])<8)throw new InvalidArgumentException('A senha deve ter ao menos 8 caracteres.');
    $q=db()->prepare('SELECT 1 FROM users WHERE email=?');$q->execute([$d['email']]);
    if($q->fetchColumn())throw new InvalidArgumentException('Já existe um usuário com este e-mail.');
    $id=uid();
    db()->prepare('INSERT INTO users(id,name,email,password_hash,role,creci) VALUES(?,?,?,?,?,?)')->execute([$id,$d['name'],$d['email'],password_hash($d['password'],PASSWORD_DEFAULT),$d['role'],trim((string)($d['creci']??''))?:null]);
    return $id;
}

/**
 * Falha inesperada (banco, PHP, infraestrutura): resposta pública genérica com um código de ocorrência;
 * o detalhe técnico (mensagem, SQL, arquivo, stack trace) vai só para o log do servidor.
 * A aplicação sinaliza "não encontrado" com RuntimeException da própria classe; subclasses internas (ex.: PDOException) caem aqui.
 */
function internal_error(Throwable $e): never {
    respond(['message'=>'Erro interno. Consulte os logs do servidor.','error_id'=>log_internal_error($e)],500);
}
/** Registra o detalhe técnico no log do servidor e devolve o código curto que aparece para o usuário. */
function log_internal_error(Throwable $e): string {
    $ref=bin2hex(random_bytes(4));
    error_log(sprintf('[erro %s] %s %s — %s',$ref,$_SERVER['REQUEST_METHOD']??'CLI',(string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),$e->__toString()));
    return $ref;
}
