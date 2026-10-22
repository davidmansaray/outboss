<?php

declare(strict_types=1);

// Create an isolated local fixture. Existing configuration is never overwritten.
$root = dirname(__DIR__);
$target = $root.'/.env';

if (file_exists($target)) {
    fwrite(STDOUT, ".env already exists; left unchanged.\n");
    exit(0);
}

$database = $root.'/database/showcase.sqlite';
$values = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_URL' => 'http://127.0.0.1:8125',
    'APP_DEBUG' => 'true',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => '"'.$database.'"',
    'QUEUE_CONNECTION' => 'sync',
    'CACHE_STORE' => 'array',
    'MAIL_MAILER' => 'array',
    'SESSION_COOKIE' => 'outboss_showcase_session',
    'CAMPAIGN_SENDING_ENABLED' => 'false',
];
$text = file_get_contents($root.'/.env.example');

foreach ($values as $key => $value) {
    $pattern = '/^'.preg_quote($key, '/').'=.*/m';
    $line = $key.'='.$value;
    $text = preg_match($pattern, $text) ? preg_replace_callback($pattern, fn () => $line, $text) : $text."\n".$line;
}

$file = fopen($target, 'x');
if ($file === false) {
    throw new RuntimeException('Could not create local showcase configuration.');
}
fwrite($file, $text."\n");
fclose($file);
chmod($target, 0600);
if (! file_exists($database)) {
    touch($database);
}
fwrite(STDOUT, "Created local showcase configuration and SQLite file. Run migrations and the demo seeder next.\n");
