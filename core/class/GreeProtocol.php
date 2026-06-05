<?php

class GreeProtocol {

    const PORT        = 7000;
    const GENERIC_KEY = 'a3K8Bx%2r8Y7#xDh';
    const GCM_KEY     = '{yxAHAY_Lm6pbC/<';

    const MODES     = ['auto' => 0, 'cool' => 1, 'dry' => 2, 'fan_only' => 3, 'heat' => 4];
    const MODES_INV = [0 => 'auto', 1 => 'cool', 2 => 'dry', 3 => 'fan_only', 4 => 'heat'];

    // ---- Crypto V1 (AES-128-ECB) ----------------------------------------

    public static function encryptV1(array $data, string $key): string {
        $raw = openssl_encrypt(json_encode($data), 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
        if ($raw === false) {
            throw new RuntimeException('GreeProtocol::encryptV1: ' . openssl_error_string());
        }
        return base64_encode($raw);
    }

    public static function decryptV1(string $b64, string $key): array {
        $raw = openssl_decrypt(base64_decode($b64), 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
        if ($raw === false) {
            throw new RuntimeException('GreeProtocol::decryptV1: ' . openssl_error_string());
        }
        return json_decode($raw, true) ?: [];
    }

    // ---- Crypto V2 (AES-128-GCM) ----------------------------------------

    public static function encryptV2(array $data, string $key = self::GCM_KEY): string {
        $tag = '';
        $raw = openssl_encrypt(json_encode($data), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, str_repeat("\x00", 12), $tag, '', 16);
        if ($raw === false) {
            throw new RuntimeException('GreeProtocol::encryptV2: ' . openssl_error_string());
        }
        return base64_encode($raw . $tag);
    }

    public static function decryptV2(string $b64, string $key = self::GCM_KEY): array {
        $raw   = base64_decode($b64);
        $plain = openssl_decrypt(substr($raw, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, str_repeat("\x00", 12), substr($raw, -16));
        if ($plain === false) {
            throw new RuntimeException('GreeProtocol::decryptV2: ' . openssl_error_string());
        }
        return json_decode($plain, true) ?: [];
    }

    // ---- UDP ----------------------------------------------------------------

    private static function udpSend(string $ip, string $payload, int $timeout = 5): string {
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            throw new RuntimeException('GreeProtocol: socket_create failed: ' . socket_strerror(socket_last_error()));
        }
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
        socket_bind($sock, '0.0.0.0', 0);

        socket_sendto($sock, $payload, strlen($payload), 0, $ip, self::PORT);

        $buf = $from = '';
        $port = 0;
        $ret  = @socket_recvfrom($sock, $buf, 65535, 0, $from, $port);
        socket_close($sock);

        if ($ret === false || $buf === '') {
            throw new RuntimeException("GreeProtocol: no response from $ip (timeout)");
        }
        return $buf;
    }

    private static function udpBroadcast(string $broadcastIp, string $payload, int $timeout = 3): array {
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            throw new RuntimeException('GreeProtocol: socket_create failed: ' . socket_strerror(socket_last_error()));
        }
        socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
        socket_bind($sock, '0.0.0.0', 0);

        socket_sendto($sock, $payload, strlen($payload), 0, $broadcastIp, self::PORT);

        $responses = [];
        while (true) {
            $buf = $from = '';
            $port = 0;
            if (@socket_recvfrom($sock, $buf, 65535, 0, $from, $port) === false || $buf === '') {
                break;
            }
            $responses[] = ['ip' => $from, 'raw' => $buf];
        }
        socket_close($sock);
        return $responses;
    }

    // ---- Public API ---------------------------------------------------------

    /**
     * Discover devices via UDP broadcast.
     *
     * @return array[] Each item: ['ip', 'mac', 'name', 'ver', 'brand']
     */
    public static function discover(string $broadcastIp = '192.168.1.255', int $timeout = 3): array {
        $responses = self::udpBroadcast($broadcastIp, json_encode(['t' => 'scan']), $timeout);

        $devices = [];
        foreach ($responses as $r) {
            $envelope = json_decode($r['raw'], true);
            if (!isset($envelope['pack'])) continue;

            $pack = self::decryptV1($envelope['pack'], self::GENERIC_KEY);
            if (($pack['t'] ?? '') !== 'dev') continue;

            $devices[] = [
                'ip'    => $r['ip'],
                'mac'   => $pack['mac']   ?? $pack['cid'] ?? '',
                'name'  => $pack['name']  ?? '',
                'ver'   => $pack['ver']   ?? '',
                'brand' => $pack['brand'] ?? '',
            ];
        }
        return $devices;
    }

    /**
     * Bind to a device and return its encryption key.
     */
    public static function bind(string $ip, string $mac): string {
        $request = json_encode([
            't'    => 'pack',
            'i'    => 1,
            'uid'  => 0,
            'cid'  => 'app',
            'tcid' => $mac,
            'pack' => self::encryptV1(['mac' => $mac, 't' => 'bind', 'uid' => 0], self::GENERIC_KEY),
        ]);

        $envelope = json_decode(self::udpSend($ip, $request), true);
        if (!isset($envelope['pack'])) {
            throw new RuntimeException("GreeProtocol::bind: no pack in response from $ip");
        }

        $response = self::decryptV1($envelope['pack'], self::GENERIC_KEY);
        if (($response['t'] ?? '') !== 'bindok') {
            throw new RuntimeException("GreeProtocol::bind: expected bindok, got '" . ($response['t'] ?? 'none') . "'");
        }
        if (empty($response['key'])) {
            throw new RuntimeException("GreeProtocol::bind: empty key in response from $ip");
        }
        return $response['key'];
    }

    /**
     * Read device status.
     *
     * @return array Associative: ['Pow' => 1, 'Mod' => 1, 'SetTem' => 24, ...]
     */
    public static function getStatus(string $ip, string $mac, string $key): array {
        $cols = [
            'Pow', 'Mod', 'SetTem', 'WdSpd', 'Air', 'Blo', 'Health',
            'SwhSlp', 'Lig', 'SwingLfRig', 'SwUpDn', 'Quiet', 'Tur',
            'StHt', 'TemUn', 'HeatCoolType', 'TemRec', 'SvSt',
        ];

        $request = json_encode([
            't'    => 'pack',
            'i'    => 1,
            'uid'  => 0,
            'cid'  => 'app',
            'tcid' => $mac,
            'pack' => self::encryptV1(['mac' => $mac, 't' => 'status', 'cols' => $cols], $key),
        ]);

        $envelope = json_decode(self::udpSend($ip, $request), true);
        if (!isset($envelope['pack'])) {
            throw new RuntimeException("GreeProtocol::getStatus: no pack in response from $ip");
        }

        $response = self::decryptV1($envelope['pack'], $key);
        if (($response['t'] ?? '') !== 'dat') {
            throw new RuntimeException("GreeProtocol::getStatus: expected dat, got '" . ($response['t'] ?? 'none') . "'");
        }
        return array_combine($response['cols'], $response['dat']);
    }

    /**
     * Send a command to the device.
     *
     * @param array $params Associative: ['Pow' => 1, 'SetTem' => 24, ...]
     */
    public static function sendCommand(string $ip, string $mac, string $key, array $params): bool {
        $request = json_encode([
            't'    => 'pack',
            'i'    => 1,
            'uid'  => 0,
            'cid'  => 'app',
            'tcid' => $mac,
            'pack' => self::encryptV1(['opt' => array_keys($params), 'p' => array_values($params), 't' => 'cmd'], $key),
        ]);

        $envelope = json_decode(self::udpSend($ip, $request), true);
        if (!isset($envelope['pack'])) return false;

        $response = self::decryptV1($envelope['pack'], $key);
        return ($response['r'] ?? 0) === 200;
    }
}
