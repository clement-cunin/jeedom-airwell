<?php
/**
 * Script de test du protocole Gree — validation sans Jeedom
 *
 * Usage:
 *   php test_gree.php discover [broadcast_ip]
 *   php test_gree.php bind    <ip> <mac>
 *   php test_gree.php status  <ip> <mac> <key>
 *   php test_gree.php cmd     <ip> <mac> <key> Param=valeur [...]
 *
 * Exemples:
 *   php test_gree.php discover 192.168.1.255
 *   php test_gree.php bind 192.168.1.42 f4911e3a1234
 *   php test_gree.php status 192.168.1.42 f4911e3a1234 <device_key>
 *   php test_gree.php cmd 192.168.1.42 f4911e3a1234 <key> Pow=1 SetTem=22 Mod=1
 */

require_once __DIR__ . '/../core/class/GreeProtocol.php';

// ---- Helpers ----------------------------------------------------------------

function ok(string $msg): void  { echo "\033[32m✓\033[0m $msg\n"; }
function err(string $msg): void { echo "\033[31m✗\033[0m $msg\n"; exit(1); }
function hdr(string $msg): void { echo "\n\033[1m$msg\033[0m\n" . str_repeat('─', strlen($msg)) . "\n"; }

function dumpStatus(array $status): void {
    $modeStr = GreeProtocol::MODES_INV[$status['Mod'] ?? 0] ?? '?';
    $pow     = $status['Pow'] ? 'ON' : 'OFF';
    echo "  Alimentation  : $pow\n";
    echo "  Mode          : $modeStr ({$status['Mod']})\n";
    echo "  Consigne      : {$status['SetTem']} °C\n";
    echo "  Ventilateur   : {$status['WdSpd']}\n";
    echo "\n  Dump complet  :\n";
    foreach ($status as $k => $v) {
        printf("    %-16s %s\n", $k, $v);
    }
}

// ---- Main -------------------------------------------------------------------

$cmd = $argv[1] ?? 'help';

switch ($cmd) {

    // ---- discover -----------------------------------------------------------
    case 'discover':
        $broadcastIp = $argv[2] ?? '192.168.1.255';
        hdr("Découverte UDP — $broadcastIp");
        try {
            $devices = GreeProtocol::discover($broadcastIp);
        } catch (Exception $e) {
            err($e->getMessage());
        }
        if (empty($devices)) {
            echo "  Aucun appareil trouvé.\n";
            echo "  Vérifiez que le clim est allumé et sur le même réseau.\n";
        } else {
            foreach ($devices as $d) {
                ok("Appareil trouvé");
                printf("    IP    : %s\n", $d['ip']);
                printf("    MAC   : %s\n", $d['mac']);
                printf("    Nom   : %s\n", $d['name']);
                printf("    Marque: %s  Version: %s\n", $d['brand'], $d['ver']);
            }
        }
        break;

    // ---- bind ---------------------------------------------------------------
    case 'bind':
        [$ip, $mac] = [$argv[2] ?? null, $argv[3] ?? null];
        if (!$ip || !$mac) err("Usage: php test_gree.php bind <ip> <mac>");

        hdr("Binding — $ip  ($mac)");
        try {
            $result = GreeProtocol::bind($ip, $mac);
            ok("Binding réussi (cipher=" . $result['cipher'] . ")");
            echo "  Clé device : " . $result['key'] . "\n";
            echo "  Cipher     : " . $result['cipher'] . "\n";
            echo "\n  → Notez la clé ET le cipher pour les commandes suivantes.\n";
        } catch (Exception $e) {
            err($e->getMessage());
        }
        break;

    // ---- status -------------------------------------------------------------
    case 'status':
        [$ip, $mac, $key, $cipher] = [$argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null, $argv[5] ?? 'v1'];
        if (!$ip || !$mac || !$key) err("Usage: php test_gree.php status <ip> <mac> <key> [v1|v2]");

        hdr("Lecture état — $ip  (cipher=$cipher)");
        try {
            $status = GreeProtocol::getStatus($ip, $mac, $key, $cipher);
            ok("Réponse reçue");
            dumpStatus($status);
        } catch (Exception $e) {
            err($e->getMessage());
        }
        break;

    // ---- cmd ----------------------------------------------------------------
    case 'cmd':
        [$ip, $mac, $key, $cipher] = [$argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null, $argv[5] ?? 'v1'];
        if (!$ip || !$mac || !$key || !isset($argv[6])) {
            err("Usage: php test_gree.php cmd <ip> <mac> <key> [v1|v2] Param=valeur [...]");
        }

        $params = [];
        foreach (array_slice($argv, 6) as $arg) {
            [$k, $v] = explode('=', $arg, 2) + [null, null];
            if (!$k || $v === null) err("Format invalide: $arg  (attendu: Param=valeur)");
            $params[$k] = is_numeric($v) ? (int)$v : $v;
        }

        hdr("Envoi commande — $ip  (cipher=$cipher)");
        echo "  Paramètres : " . json_encode($params) . "\n";
        try {
            $ok = GreeProtocol::sendCommand($ip, $mac, $key, $params, $cipher);
            $ok ? ok("Commande acceptée (r=200)") : err("Commande refusée par le device");
        } catch (Exception $e) {
            err($e->getMessage());
        }
        break;

    // ---- help ---------------------------------------------------------------
    default:
        echo <<<HELP

        Usage:
          php test_gree.php discover [broadcast_ip]         Découverte réseau
          php test_gree.php bind    <ip> <mac>              Récupérer la clé device
          php test_gree.php status  <ip> <mac> <key>        Lire l'état
          php test_gree.php cmd     <ip> <mac> <key> K=V…   Envoyer une commande

        Paramètres Gree courants:
          Pow=1/0       Allumer/Éteindre
          Mod=0..4      Mode: 0=auto 1=cool 2=dry 3=fan_only 4=heat
          SetTem=16..30 Température consigne
          WdSpd=0..5    Vitesse ventilateur (0=auto)
        HELP;
}

echo "\n";
