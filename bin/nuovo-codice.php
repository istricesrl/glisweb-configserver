<?php
/**
 * aggiunge un deploy al registro del configserver con un codice di 8 cifre casuali
 *   php bin/nuovo-codice.php https://deploy.example.com/   -> stampa il codice
 * se l'url c'e' gia', stampa il codice che ha e non ne crea un altro
 */
if (PHP_SAPI !== 'cli') exit(1);
$url = $argv[1] ?? '';
if (!preg_match('#^https://[a-z0-9.-]+/?$#i', $url)) { fwrite(STDERR, "uso: php nuovo-codice.php https://deploy/\n"); exit(1); }
$url = rtrim($url, '/') . '/';
$file = dirname(__DIR__) . '/etc/deploy.ini';
$registro = @parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];
foreach ($registro as $c => $u) {
    if (rtrim($u, '/') . '/' === $url) { echo "$c\n"; exit(0); }
}
do { $codice = (string) random_int(10000000, 99999999); } while (isset($registro[$codice]));
file_put_contents($file, "$codice = $url\n", FILE_APPEND | LOCK_EX);
echo "$codice\n";
