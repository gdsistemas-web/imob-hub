<?php
// Cria um administrador de forma interativa (primeiro acesso em produção, sem seed).
// Uso: php backend/bin/create_admin.php
// Usa a mesma regra da tela Equipe (create_user): senha com 8+ caracteres, e-mail único, hash PASSWORD_DEFAULT.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../src/bootstrap.php';

$tty = function_exists('posix_isatty') && posix_isatty(STDIN);

// Estado original do terminal, restaurado em qualquer saída (sucesso, erro, exceção ou exit).
$sttyState = $tty ? trim((string)shell_exec('stty -g 2>/dev/null')) : '';
$restoreTerminal = function () use ($sttyState): void { if ($sttyState !== '') shell_exec('stty '.escapeshellarg($sttyState).' 2>/dev/null'); };
register_shutdown_function($restoreTerminal);
// Ctrl+C: com pcntl, sai de forma limpa; sem pcntl, o trap do shell em askSecret() restaura o terminal.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $cancel = function () use ($restoreTerminal): void { $restoreTerminal(); fwrite(STDERR, "\nCancelado. Nada foi criado.\n"); exit(130); };
    pcntl_signal(SIGINT, $cancel); pcntl_signal(SIGTERM, $cancel);
}

function ask(string $label): string {
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    if ($line === false) { fwrite(STDERR, "\nEntrada encerrada. Nada foi criado.\n"); exit(1); }
    return trim($line);
}

/**
 * Lê a senha sem eco. No terminal, a leitura é feita por um sh com trap: se o usuário apertar Ctrl+C,
 * o sinal também chega ao sh, que restaura o terminal antes de sair. Fora de terminal, apenas lê a linha.
 */
function askSecret(string $label, bool $tty): string {
    fwrite(STDOUT, $label);
    if ($tty) {
        $line = shell_exec('s=$(stty -g); trap \'stty "$s"; exit 130\' INT TERM HUP; stty -echo; IFS= read -r p; r=$?; stty "$s"; [ $r -eq 0 ] && printf "%s\n" "$p"');
        fwrite(STDOUT, "\n");
    } else $line = fgets(STDIN);
    if ($line === false || $line === null || $line === '') { fwrite(STDERR, "\nEntrada encerrada. Nada foi criado.\n"); exit(1); }
    return rtrim($line, "\r\n");
}

try {
    $db = db();
    $admins = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
    fwrite(STDOUT, "Criar administrador do IMOB HUB\n".($admins ? "Atenção: já existe(m) $admins administrador(es) ativo(s).\n" : '')."\n");

    $name = ask('Nome: ');
    if (mb_strlen($name) < 2) throw new InvalidArgumentException('Informe o nome.');
    $email = mb_strtolower(ask('E-mail: '));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail inválido.');
    $q = $db->prepare('SELECT 1 FROM users WHERE email=?'); $q->execute([$email]);
    if ($q->fetchColumn()) throw new InvalidArgumentException('Já existe um usuário com este e-mail.');

    $password = askSecret('Senha (mínimo 8 caracteres): ', $tty);
    $confirm = askSecret('Confirme a senha: ', $tty);
    if (!hash_equals($password, $confirm)) throw new InvalidArgumentException('As senhas não conferem.');

    create_user(['name'=>$name, 'email'=>$email, 'role'=>'admin', 'password'=>$password]);
    fwrite(STDOUT, "\nAdministrador criado: $name <$email>. Acesse /login para entrar.\n");
    exit(0);
} catch (InvalidArgumentException|DomainException $e) {
    fwrite(STDERR, "\nErro: ".$e->getMessage()." Nada foi criado.\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "\nErro ao acessar o banco: verifique DB_DSN, DB_USER e DB_PASS e se as migrations foram executadas.\n");
    error_log('create_admin: '.$e->getMessage());
    exit(2);
}
