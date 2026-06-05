<?php
/**
 * Test de connexion au cloud Gree pour récupérer les devices Airwell
 *
 * Usage: php test_cloud.php <email> <password> [server_url]
 *
 * Serveurs disponibles:
 *   Europe    : https://eugrih.gree.com  (défaut)
 *   US        : https://usgrih.gree.com
 *   China     : https://grih.gree.com
 *   Australia : https://augrih.gree.com
 */

define('APP_ID',   '4920681951525131286');
define('APP_HASH', '0fa513124aa97781d1f3f40d61ca1a89');
define('AES_KEY',  '#G$&^jgfujy6ujxt');
define('GAEN1',    '5ac2bdf935bcca70');

define('DEFAULT_SERVER', 'https://eugrih.gree.com');

// ---- Crypto ----------------------------------------------------------------

function aesEncrypt(string $data): string {
    $raw = openssl_encrypt($data, 'aes-128-ecb', AES_KEY, OPENSSL_RAW_DATA);
    if ($raw === false) {
        throw new RuntimeException('AES encrypt failed: ' . openssl_error_string());
    }
    return base64_encode($raw);
}

function aesDecrypt(string $b64): string {
    $raw = openssl_decrypt(base64_decode($b64), 'aes-128-ecb', AES_KEY, OPENSSL_RAW_DATA);
    if ($raw === false) {
        throw new RuntimeException('AES decrypt failed: ' . openssl_error_string());
    }
    return $raw;
}

// ---- Request builder -------------------------------------------------------

function prepBody(array $payload, int $now, array $hashProps): array {
    $t  = gmdate('Y-m-d H:i:s', $now);
    $vc = md5(APP_ID . '_' . APP_HASH . '_' . $t . '_' . $now);

    $propValues = array_map(fn($p) => (string)$payload[$p], $hashProps);
    $datVc      = md5(APP_HASH . '_' . implode('_', $propValues));

    return array_merge([
        'api' => [
            'appId' => APP_ID,
            'r'     => $now,
            't'     => $t,
            'vc'    => $vc,
        ],
        'datVc' => $datVc,
    ], $payload);
}

// ---- HTTP ------------------------------------------------------------------

function postRequest(string $baseUrl, string $endpoint, array $body): array {
    $json      = json_encode($body, JSON_UNESCAPED_UNICODE);
    $encrypted = aesEncrypt($json);

    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $encrypted,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Gaen1: ' . GAEN1,
            'Charset: utf-8',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    echo "  HTTP $httpCode  →  $baseUrl$endpoint\n";

    if ($error) {
        throw new RuntimeException("cURL error: $error");
    }
    if ($response === false || $response === '') {
        throw new RuntimeException("Empty response");
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        echo "  Raw response: $response\n";
        throw new RuntimeException("Non-JSON response");
    }

    // Codes d'erreur renvoyés directement (non chiffrés)
    if (isset($decoded['res']) && $decoded['res'] !== 1) {
        echo "  Error response: " . json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
        throw new RuntimeException("API error (res={$decoded['res']})");
    }

    if (isset($decoded['enRes'])) {
        $plain = aesDecrypt($decoded['enRes']);
        $data  = json_decode($plain, true);
        if ($data === null) {
            echo "  Decrypted (unparseable): $plain\n";
            throw new RuntimeException("Decrypted response is not valid JSON");
        }
        return $data;
    }

    return $decoded;
}

// ---- Main ------------------------------------------------------------------

$email    = $argv[1] ?? null;
$password = $argv[2] ?? null;
$server   = rtrim($argv[3] ?? DEFAULT_SERVER, '/');

if (!$email || !$password) {
    echo "Usage: php test_cloud.php <email> <password> [server_url]\n";
    echo "Default server: " . DEFAULT_SERVER . "\n";
    exit(1);
}

echo "\n=== Gree Cloud — test de connexion ===\n";
echo "Serveur : $server\n\n";

// --- Login ------------------------------------------------------------------
echo "[ LOGIN ]\n";

$now = time();
$t   = gmdate('Y-m-d H:i:s', $now);
$h   = md5(md5($password) . $password);
$psw = md5($h . $t);

$loginBody = prepBody(['user' => $email, 'psw' => $psw, 't' => $t], $now, ['user', 'psw', 't']);
$loginData = postRequest($server, '/App/UserLoginV2', $loginBody);

if (empty($loginData['uid']) || empty($loginData['token'])) {
    echo "  Login échoué. Réponse :\n";
    echo json_encode($loginData, JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$uid   = $loginData['uid'];
$token = $loginData['token'];
echo "  UID   : $uid\n";
echo "  Token : $token\n\n";

// --- Homes ------------------------------------------------------------------
echo "[ HOMES ]\n";

$homesBody = prepBody(['token' => $token, 'uid' => $uid], time(), ['token', 'uid']);
$homesData = postRequest($server, '/App/GetHomes', $homesBody);

$homes = $homesData['home'] ?? $homesData['homes'] ?? [];
if (empty($homes)) {
    echo "  Aucune maison trouvée. Réponse complète :\n";
    echo json_encode($homesData, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

foreach ($homes as $home) {
    $homeId   = $home['id'] ?? $home['homeId'] ?? '?';
    $homeName = $home['name'] ?? 'Sans nom';
    echo "  Maison : $homeName  (id=$homeId)\n";
}
echo "\n";

// --- Devices ----------------------------------------------------------------
foreach ($homes as $home) {
    $homeId   = $home['id'] ?? $home['homeId'] ?? null;
    $homeName = $home['name'] ?? 'Sans nom';

    echo "[ DEVICES — $homeName ]\n";

    $devBody = prepBody([
        'token'  => $token,
        'uid'    => $uid,
        'homeId' => $homeId,
    ], time(), ['token', 'uid', 'homeId']);

    $devData = postRequest($server, '/App/GetDevsInRoomsOfHomeV2', $devBody);

    $rooms = $devData['rooms'] ?? [];
    if (empty($rooms)) {
        echo "  Aucune pièce / réponse complète :\n";
        echo json_encode($devData, JSON_PRETTY_PRINT) . "\n";
        continue;
    }

    foreach ($rooms as $room) {
        $roomName = $room['name'] ?? $room['roomName'] ?? 'Pièce inconnue';
        $devices  = $room['devs'] ?? $room['devices'] ?? [];

        foreach ($devices as $dev) {
            echo "  Pièce : $roomName\n";
            echo "    Nom  : " . ($dev['name']   ?? $dev['devName'] ?? '?') . "\n";
            echo "    MAC  : " . ($dev['mac']    ?? $dev['devMac']  ?? '?') . "\n";
            echo "    IP   : " . ($dev['localIp'] ?? $dev['ip']     ?? '?') . "\n";
            echo "    Clé  : " . ($dev['bindKey'] ?? $dev['key']    ?? $dev['devKey'] ?? '?') . "\n";
            echo "    --- dump complet ---\n";
            echo "    " . json_encode($dev, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        }
    }
}
