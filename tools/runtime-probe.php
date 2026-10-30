<?php

declare(strict_types=1);

// This probe uses its own disposable database; it never opens hub state.
$required = ['bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'iconv', 'mbstring', 'openssl', 'pcntl', 'pdo_sqlite', 'session', 'tokenizer', 'xml', 'xmlwriter'];
$missing = array_values(array_filter($required, fn (string $extension): bool => ! extension_loaded($extension)));
if ($missing !== []) {
    fwrite(STDERR, 'Missing extensions: '.implode(', ', $missing).PHP_EOL);
    exit(1);
}

$version = SQLite3::version();
$patched = $version['versionNumber'] >= 3051003 || in_array($version['versionNumber'], [3044006, 3050007], true);
if (! $patched) {
    fwrite(STDERR, 'SQLite WAL fix required; found '.$version['versionString'].PHP_EOL);
    exit(1);
}

$directory = sys_get_temp_dir().'/nebuless-probe-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $database = new PDO('sqlite:'.$directory.'/probe.sqlite');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mode = $database->query('PRAGMA journal_mode=WAL')->fetchColumn();
    if ($mode !== 'wal') {
        throw new RuntimeException('WAL unavailable');
    }
    $database->exec('PRAGMA synchronous=FULL; CREATE TABLE probe (value TEXT);');
    $database->exec("INSERT INTO probe VALUES ('persisted')");
    $database = null;
    $reopened = new PDO('sqlite:'.$directory.'/probe.sqlite');
    if ($reopened->query('SELECT value FROM probe')->fetchColumn() !== 'persisted') {
        throw new RuntimeException('Persistent database read failed');
    }
    $reopened = null;
    $file = fopen($directory.'/durability', 'wb');
    if ($file === false || fwrite($file, 'probe') !== 5 || ! fflush($file) || ! fsync($file)) {
        throw new RuntimeException('File synchronization unavailable');
    }
    fclose($file);
    echo json_encode(['php' => PHP_VERSION, 'sqlite' => $version['versionString'], 'wal' => true, 'persistence' => true, 'fsync' => true, 'uid' => function_exists('posix_getuid') ? posix_getuid() : null, 'platform' => php_uname('s').' '.php_uname('r')], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    foreach (glob($directory.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
}
