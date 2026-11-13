<?php
require_once __DIR__ . '/GreeProtocol.php';

/**
 * Network diagnostic for one device: runs a series of independent checks
 * (subnet, ping/ARP, unicast Gree probe, broadcast per interface, key)
 * and derives the most likely cause of a "no response" error.
 *
 * Has no dependency on the Jeedom core (only uses __() when available),
 * so it can be run from the command line.
 */
class AirwellDiagnostic {

    const OK          = 'ok';
    const WARNING     = 'warning';
    const ERROR       = 'error';
    const UNAVAILABLE = 'unavailable';

    const BROADCAST_TIMEOUT = 2;
    const PROBE_TIMEOUT     = 3;

    /**
     * @return array [
     *   'steps'        => [['step', 'status', 'message', 'details'], ...],
     *   'conclusion'   => string,
     *   'suggestedMac' => string|null,
     *   'suggestedIp'  => string|null,
     * ]
     */
    public static function run(string $ip, string $mac, string $key = '', string $cipher = 'v1'): array {
        $ip  = trim($ip);
        $mac = GreeProtocol::normalizeMac($mac);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [
                'steps'        => [self::step(self::t('Adresse IP'), self::ERROR, self::t('Adresse IP invalide : %s', $ip))],
                'conclusion'   => self::t("L'adresse IP saisie n'est pas valide. Corrigez-la (format 192.168.1.20), sauvegardez puis relancez le diagnostic."),
                'suggestedMac' => null,
                'suggestedIp'  => null,
            ];
        }

        $interfaces = self::ipv4Interfaces();
        $ctx = ['ip' => $ip, 'mac' => $mac];

        $steps = [
            self::checkSubnet($ip, $interfaces, $ctx),
            self::checkPresence($ip, $mac, $ctx),
            self::checkProbe($ip, $mac, $ctx),
            self::checkBroadcast($ip, $mac, $interfaces, $ctx),
            self::checkKey($ip, $mac, $key, $cipher, $ctx),
        ];

        return [
            'steps'        => $steps,
            'conclusion'   => self::conclude($ctx),
            'suggestedMac' => $ctx['suggestedMac'] ?? null,
            'suggestedIp'  => $ctx['suggestedIp'] ?? null,
        ];
    }

    // ---- Step 1: same subnet --------------------------------------------------

    private static function checkSubnet(string $ip, ?array $interfaces, array &$ctx): array {
        $title = self::t('Même sous-réseau que Jeedom');
        if ($interfaces === null) {
            return self::step($title, self::UNAVAILABLE, self::t('Impossible de lister les interfaces réseau de Jeedom.'));
        }

        $list = implode(', ', array_map(function ($i) {
            return "{$i['name']} {$i['address']}/{$i['netmask']}";
        }, $interfaces));

        foreach ($interfaces as $i) {
            if (self::inSubnet($ip, $i['address'], $i['netmask'])) {
                $ctx['sameSubnet'] = true;
                return self::step($title, self::OK, self::t('Le climatiseur est sur le réseau de l\'interface %s (%s).', $i['name'], $i['address']), $list);
            }
        }
        $ctx['sameSubnet'] = false;
        return self::step(
            $title,
            self::WARNING,
            self::t('Le climatiseur n\'est sur aucun sous-réseau de Jeedom (réseau séparé, VLAN, ou faute de frappe dans l\'IP ?).'),
            $list
        );
    }

    // ---- Step 2: ping + ARP -----------------------------------------------------

    private static function checkPresence(string $ip, string $mac, array &$ctx): array {
        $title = self::t('Présence sur le réseau local (ping / ARP)');

        $ping = self::ping($ip);          // true | false | null (unavailable)
        $arp  = self::arpLookup($ip);     // string mac | '' (no entry) | null (unavailable)

        $details = self::t('Ping : %s', $ping === null ? self::t('non disponible') : ($ping ? self::t('répond') : self::t('pas de réponse')))
            . ' · ' . self::t('ARP : %s', $arp === null ? self::t('non disponible') : ($arp === '' ? self::t('aucune entrée') : $arp));

        if ($arp !== null && $arp !== '') {
            $ctx['arpMac'] = $arp;
            if ($arp === $mac) {
                $ctx['present'] = true;
                return self::step($title, self::OK, self::t('Un appareil avec la MAC saisie est bien présent à cette adresse.'), $details);
            }
            $ctx['ipTakenByOther'] = true;
            return self::step(
                $title,
                self::ERROR,
                self::t('Cette IP correspond à un autre appareil (MAC %s) : l\'IP du climatiseur a probablement changé, ou la MAC saisie est fausse.', self::formatMac($arp)),
                $details
            );
        }

        if ($ping === true) {
            $ctx['present'] = true;
            $msg = ($ctx['sameSubnet'] ?? true)
                ? self::t('Un appareil répond au ping à cette adresse.')
                : self::t('Un appareil répond au ping à cette adresse (via un routeur : impossible de vérifier sa MAC).');
            return self::step($title, self::OK, $msg, $details);
        }

        if ($arp === '' && ($ctx['sameSubnet'] ?? false)) {
            $ctx['nothingAtIp'] = true;
            return self::step(
                $title,
                self::ERROR,
                self::t('Rien ne répond à cette IP sur le réseau local (appareil éteint, IP fausse, ou réseau isolé).'),
                $details
            );
        }

        if ($ping === null && $arp === null) {
            return self::step($title, self::UNAVAILABLE, self::t('Ni ping ni table ARP disponibles sur cette installation (Docker ?).'), $details);
        }

        return self::step(
            $title,
            self::WARNING,
            self::t('Pas de réponse au ping (non bloquant : certains appareils ne répondent pas au ping).'),
            $details
        );
    }

    // ---- Step 3: unicast Gree probe ---------------------------------------------

    private static function checkProbe(string $ip, string $mac, array &$ctx): array {
        $title  = self::t('Réponse au protocole Gree (requête directe)');
        $device = GreeProtocol::probe($ip, self::PROBE_TIMEOUT);

        if ($device === null) {
            $ctx['probeOk'] = false;
            return self::step(
                $title,
                self::ERROR,
                self::t('L\'appareil ne répond pas au protocole local Gree (port UDP 7000 bloqué, ou module Wi-Fi qui n\'accepte pas le pilotage local).')
            );
        }

        $ctx['probeOk'] = true;
        $details = self::describeDevice($device);
        $deviceMac = GreeProtocol::normalizeMac($device['mac']);

        if ($deviceMac !== '' && $deviceMac !== $mac) {
            $ctx['suggestedMac'] = $deviceMac;
            return self::step(
                $title,
                self::ERROR,
                self::t('Le climatiseur répond, mais sa MAC (%s) est différente de celle saisie (%s).', self::formatMac($deviceMac), self::formatMac($mac)),
                $details
            );
        }
        return self::step($title, self::OK, self::t('Le climatiseur répond au protocole Gree.'), $details);
    }

    // ---- Step 4: broadcast per interface --------------------------------------

    private static function checkBroadcast(string $ip, string $mac, ?array $interfaces, array &$ctx): array {
        $title = self::t('Découverte par broadcast');

        $targets = ['255.255.255.255' => self::t('scan standard')];
        foreach ($interfaces ?? [] as $i) {
            $targets[$i['broadcast']] = $i['name'];
        }

        $foundOn = [];
        $lines   = [];
        foreach ($targets as $broadcast => $label) {
            $match = null;
            try {
                foreach (GreeProtocol::discover($broadcast, self::BROADCAST_TIMEOUT) as $d) {
                    if (GreeProtocol::normalizeMac($d['mac']) === $mac || $d['ip'] === $ip) {
                        $match = $d;
                        break;
                    }
                }
            } catch (Exception $e) {
                $lines[] = "$broadcast ($label) : " . $e->getMessage();
                continue;
            }
            if ($match !== null) {
                $foundOn[$broadcast] = $match;
                $lines[] = "$broadcast ($label) : " . self::t('trouvé à %s', $match['ip']);
            } else {
                $lines[] = "$broadcast ($label) : " . self::t('non trouvé');
            }
        }
        $details = implode("\n", $lines);

        if (!$foundOn) {
            $ctx['broadcastFound'] = false;
            return self::step($title, self::ERROR, self::t('Le climatiseur n\'a été trouvé par aucun broadcast.'), $details);
        }

        $ctx['broadcastFound'] = true;
        $match = reset($foundOn);
        if ($match['ip'] !== $ip) {
            $ctx['suggestedIp'] = $match['ip'];
            if (!isset($ctx['suggestedMac']) && GreeProtocol::normalizeMac($match['mac']) !== $mac) {
                $ctx['suggestedMac'] = GreeProtocol::normalizeMac($match['mac']);
            }
            return self::step(
                $title,
                self::ERROR,
                self::t('Le climatiseur a été trouvé, mais à l\'adresse %s et non %s : son IP a changé.', $match['ip'], $ip),
                $details
            );
        }
        if (!isset($foundOn['255.255.255.255'])) {
            return self::step(
                $title,
                self::WARNING,
                self::t('Le scan standard ne trouve pas le climatiseur, mais le broadcast %s le trouve : utilisez cette adresse dans l\'import automatique.', array_key_first($foundOn)),
                $details
            );
        }
        return self::step($title, self::OK, self::t('Le climatiseur est trouvé par le scan standard.'), $details);
    }

    // ---- Step 5: key ------------------------------------------------------------

    private static function checkKey(string $ip, string $mac, string $key, string $cipher, array &$ctx): array {
        $title = self::t('Communication avec la clé (binding)');
        if ($key === '') {
            $ctx['keyStatus'] = 'none';
            return self::step($title, self::UNAVAILABLE, self::t('Aucun binding n\'a encore réussi : pas de clé à tester.'));
        }
        try {
            $status = GreeProtocol::getStatus($ip, $mac, $key, $cipher);
            $ctx['keyStatus'] = 'ok';
            return self::step(
                $title,
                self::OK,
                self::t('Communication complète OK.'),
                self::t('Chiffrement : %s · Alimentation : %s', $cipher, ($status['Pow'] ?? 0) ? 'on' : 'off')
            );
        } catch (Exception $e) {
            $ctx['keyStatus'] = 'error';
            return self::step($title, self::ERROR, self::t('La lecture de l\'état avec la clé enregistrée échoue.'), $e->getMessage());
        }
    }

    // ---- Conclusion -------------------------------------------------------------

    private static function conclude(array $ctx): string {
        if ($ctx['probeOk'] ?? false) {
            if (isset($ctx['suggestedMac'])) {
                return self::t('Le climatiseur répond, mais la MAC saisie est incorrecte. Remplacez-la par %s, sauvegardez puis relancez le binding.', self::formatMac($ctx['suggestedMac']));
            }
            switch ($ctx['keyStatus'] ?? 'none') {
                case 'ok':
                    return self::t('Tout fonctionne : le climatiseur répond et la clé est valide.');
                case 'error':
                    return self::t('Le climatiseur répond, mais refuse la clé enregistrée (appareil réinitialisé ou ré-appairé ?). Relancez le binding.');
                default:
                    return self::t('Le climatiseur répond : il ne reste plus qu\'à lancer le binding.');
            }
        }

        if (isset($ctx['suggestedIp'])) {
            return self::t('Le climatiseur a changé d\'adresse IP : il est maintenant en %s. Mettez à jour l\'IP, sauvegardez puis relancez le binding. Pour éviter que ça se reproduise, réservez une IP fixe au climatiseur dans votre box (bail DHCP statique).', $ctx['suggestedIp']);
        }
        if ($ctx['ipTakenByOther'] ?? false) {
            return self::t('L\'IP saisie appartient à un autre appareil (MAC %s). Vérifiez l\'IP et la MAC du climatiseur dans l\'interface de votre box.', self::formatMac($ctx['arpMac']));
        }
        if ($ctx['present'] ?? false) {
            return self::t('Un appareil est bien présent à cette adresse, mais il ne répond pas au protocole local Gree (port UDP 7000). Vérifiez qu\'aucun pare-feu ne bloque ce port. Certains modules Wi-Fi récents n\'acceptent que le pilotage par le cloud.');
        }
        if (($ctx['sameSubnet'] ?? null) === false) {
            return self::t('Le climatiseur n\'est pas sur le même réseau que Jeedom et ne répond pas. Placez-les sur le même réseau (même box, sans réseau invité ni VLAN séparé), ou vérifiez l\'IP saisie.');
        }
        return self::t('Aucun appareil ne répond à cette IP. Vérifiez dans votre box l\'IP actuelle du climatiseur, qu\'il est alimenté et connecté au Wi-Fi, et que l\'isolation des appareils Wi-Fi (Wi-Fi invité, « AP isolation ») est désactivée.');
    }

    // ---- Network helpers ------------------------------------------------------

    /**
     * @return array|null [['name', 'address', 'netmask', 'broadcast'], ...] or null if unavailable
     */
    public static function ipv4Interfaces(): ?array {
        if (!function_exists('net_get_interfaces')) {
            return null;
        }
        $raw = @net_get_interfaces();
        if (!is_array($raw)) {
            return null;
        }
        $result = [];
        foreach ($raw as $name => $iface) {
            if (isset($iface['up']) && !$iface['up']) continue;
            foreach ($iface['unicast'] ?? [] as $addr) {
                if (($addr['family'] ?? null) !== AF_INET || empty($addr['address']) || empty($addr['netmask'])) continue;
                if (strpos($addr['address'], '127.') === 0 || strpos($addr['address'], '169.254.') === 0) continue;
                if ($addr['netmask'] === '255.255.255.255') continue;
                $ip   = ip2long($addr['address']);
                $mask = ip2long($addr['netmask']);
                $result[] = [
                    'name'      => (string)$name,
                    'address'   => $addr['address'],
                    'netmask'   => $addr['netmask'],
                    'broadcast' => long2ip(($ip & $mask) | (~$mask & 0xFFFFFFFF)),
                ];
            }
        }
        return $result;
    }

    private static function inSubnet(string $ip, string $address, string $netmask): bool {
        $mask = ip2long($netmask);
        return (ip2long($ip) & $mask) === (ip2long($address) & $mask);
    }

    /**
     * @return bool|null true = answered, false = no answer, null = ping unavailable
     */
    private static function ping(string $ip): ?bool {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            return null;
        }
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? 'ping -n 1 -w 1000 ' . escapeshellarg($ip)
            : 'ping -c 1 -W 1 ' . escapeshellarg($ip);
        $output = [];
        $code   = 0;
        @exec($cmd . ' 2>&1', $output, $code);
        if ($code === 126 || $code === 127) {
            return null; // ping binary missing or not executable
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows ping exits 0 on "destination unreachable" too: only a TTL line is a real reply
            return $code === 0 && stripos(implode("\n", $output), 'TTL=') !== false;
        }
        return $code === 0;
    }

    /**
     * @return string|null normalized MAC, '' if no complete entry, null if the ARP table is unavailable
     */
    private static function arpLookup(string $ip): ?string {
        $file = '/proc/net/arp';
        if (!@is_readable($file)) {
            return null;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }
        // Format: IP address | HW type | Flags | HW address | Mask | Device
        foreach (array_slice($lines, 1) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 4 || $cols[0] !== $ip) continue;
            $arpMac = GreeProtocol::normalizeMac($cols[3]);
            if ($cols[2] === '0x0' || $arpMac === '000000000000') continue; // incomplete entry
            return $arpMac;
        }
        return '';
    }

    // ---- Formatting -------------------------------------------------------------

    private static function step(string $title, string $status, string $message, string $details = ''): array {
        return ['step' => $title, 'status' => $status, 'message' => $message, 'details' => $details];
    }

    private static function describeDevice(array $d): string {
        $parts = [];
        foreach (['name' => 'Nom', 'mac' => 'MAC', 'hid' => 'Module', 'ver' => 'Version', 'brand' => 'Marque'] as $field => $label) {
            if (($d[$field] ?? '') !== '') {
                $parts[] = self::t($label) . ' : ' . ($field === 'mac' ? self::formatMac(GreeProtocol::normalizeMac($d[$field])) : $d[$field]);
            }
        }
        return implode(' · ', $parts);
    }

    private static function formatMac(string $mac): string {
        return strlen($mac) === 12 ? strtoupper(implode(':', str_split($mac, 2))) : $mac;
    }

    private static function t(string $text, ...$args): string {
        if (function_exists('__')) {
            $text = __($text, __FILE__);
        }
        return $args ? vsprintf($text, $args) : $text;
    }
}
