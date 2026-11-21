<?php

declare(strict_types=1);

use Kongono\Security\Encryptor;

$root = dirname(__DIR__);
$envExample = $root . DIRECTORY_SEPARATOR . '.env.example';
$envFile = $root . DIRECTORY_SEPARATOR . '.env';

if (!is_file($envFile) && is_file($envExample)) {
    copy($envExample, $envFile);
}

$autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload) || !is_file($envFile)) {
    exit(0);
}

require $autoload;

$contents = file_get_contents($envFile);
if (is_string($contents) && 1 === preg_match('/^APP_KEY=\s*$/m', $contents)) {
    $key = Encryptor::generateKey();
    $updated = preg_replace('/^APP_KEY=\s*$/m', 'APP_KEY=' . $key, $contents, 1);
    if (is_string($updated)) {
        file_put_contents($envFile, $updated, LOCK_EX);
        fwrite(STDOUT, "Generated 256-bit APP_KEY in .env\n");
    }
}
