<?php
require __DIR__.'/../src/bootstrap.php';
$pdo=db();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$existing=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")->fetchColumn();
$tracked=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if($existing&&$tracked===0){$pdo->exec("INSERT INTO schema_migrations(migration) VALUES('001_initial.sql')");echo "Base anterior detectada; 001_initial.sql registrada sem reaplicação.\n";}
$check=$pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration=?');$mark=$pdo->prepare('INSERT INTO schema_migrations(migration) VALUES(?)');
foreach(glob(__DIR__.'/migrations/*.sql') as $file){$name=basename($file);$check->execute([$name]);if($check->fetchColumn()){echo 'Ignorando '.$name.' (já aplicada)'.PHP_EOL;continue;}echo 'Aplicando '.$name.PHP_EOL;$pdo->exec(file_get_contents($file));$mark->execute([$name]);}
echo "Migrações concluídas.\n";
