<?php
/**
 * configserver — dal codice deploy + utente + password al profilo di accesso
 *
 * POST JSON {"codice": "...", "user": "...", "pasw": "..."}
 *   OK → {"status":"OK","url":"https://.../","token":"...","account":{...}}
 *   KO → {"status":"KO"}  sempre uguale, qualunque sia il motivo, e mai prima di TEMPO_MINIMO
 *
 * il registro dei codici sta in ../etc/deploy.ini ( codice = url ), fuori dalla docroot;
 * i codici sono 8 cifre casuali, che non dicono di che deploy si tratta: si creano con
 * ../bin/nuovo-codice.php <url>
 * i contatori dei tentativi e il log ( senza password ) in ../var/
 * scritto per PHP 7.3 ( web02 )
 */

const TEMPO_MINIMO = 2.0;          // secondi: OK e KO non si distinguono dal tempo
const MAX_PER_IP = 10;             // tentativi falliti per IP nella finestra
const MAX_PER_CODICE = 30;         // tentativi falliti per codice nella finestra
const FINESTRA = 900;              // secondi ( 15 minuti )
const TIMEOUT_DEPLOY = 8;          // secondi per la chiamata al deploy

$base = dirname(__DIR__);
$inizio = microtime(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ko() {
    global $inizio;
    $resto = TEMPO_MINIMO - (microtime(true) - $inizio);
    if ($resto > 0) usleep((int) ($resto * 1e6));
    http_response_code(401);
    echo json_encode(['status' => 'KO']);
    exit;
}

function registra($esito, $codice) {
    global $base;
    $riga = sprintf("%s\t%s\t%s\t%s\n", date('c'), $_SERVER['REMOTE_ADDR'] ?? '-', $esito,
        preg_replace('/[^a-z0-9_.-]/i', '?', substr((string) $codice, 0, 40)));
    @file_put_contents("$base/var/log/accessi.log", $riga, FILE_APPEND | LOCK_EX);
}

// contatore dei fallimenti per chiave ( ip:..., codice:... ) in un file per chiave
function fallimenti($chiave, $aggiungi = false) {
    global $base;
    $file = "$base/var/tentativi/" . hash('sha256', $chiave);
    $ora = time();
    $tempi = [];
    if (is_file($file)) {
        foreach (explode("\n", (string) @file_get_contents($file)) as $t) {
            if ($t !== '' && (int) $t > $ora - FINESTRA) $tempi[] = (int) $t;
        }
    }
    if ($aggiungi) {
        $tempi[] = $ora;
        @file_put_contents($file, implode("\n", $tempi), LOCK_EX);
    }
    return count($tempi);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'KO']);
    exit;
}

$dati = json_decode((string) file_get_contents('php://input'), true);
$codice = strtolower(trim((string) ($dati['codice'] ?? '')));
$user = (string) ($dati['user'] ?? '');
$pasw = (string) ($dati['pasw'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? '-';

// troppi tentativi: KO senza nemmeno guardare, e senza dire perche'
if (fallimenti("ip:$ip") >= MAX_PER_IP || fallimenti("codice:$codice") >= MAX_PER_CODICE) {
    registra('BLOCCO', $codice);
    ko();
}

$fallito = function () use ($ip, $codice) {
    fallimenti("ip:$ip", true);
    fallimenti("codice:$codice", true);
    registra('KO', $codice);
    ko();
};

if ($codice === '' || $user === '' || $pasw === '' || !preg_match('/^[0-9]{8}$/', $codice)) $fallito();

$registro = @parse_ini_file("$base/etc/deploy.ini", false, INI_SCANNER_RAW) ?: [];
$url = $registro[$codice] ?? null;
if (!$url) $fallito();
$url = rtrim($url, '/') . '/';

// prova di login sul deploy, con le stesse credenziali
$ch = curl_init($url . 'api/login');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['__login__' => ['user' => $user, 'pasw' => $pasw]]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    // senza User-Agent glisweb ( 09/11/2026 ) stampa un Notice prima del JSON e la risposta non si legge
    CURLOPT_USERAGENT => 'glisweb-configserver/1',
    CURLOPT_TIMEOUT => TIMEOUT_DEPLOY,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$risposta = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$r = is_string($risposta) ? json_decode($risposta, true) : null;
if ($http !== 200 || !is_array($r) || empty($r['token']) || ($r['status'] ?? '') === 'KO') $fallito();

// come in glisweb_cli/api.py: l'account e' la risposta di login senza il token
$token = $r['token'];
unset($r['token']);

registra('OK', $codice);
$resto = TEMPO_MINIMO - (microtime(true) - $inizio);
if ($resto > 0) usleep((int) ($resto * 1e6));
echo json_encode([
    'status' => 'OK',
    'url' => $url,
    'token' => $token,
    'account' => $r,
]);
