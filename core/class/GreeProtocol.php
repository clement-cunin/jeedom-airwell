<?php

class GreeProtocol {

    const PORT = 7000;

    // Generic keys used during discovery + bind
    const GENERIC_KEY_V1 = 'a3K8Bx%2r8Y7#xDh';
    const GENERIC_KEY_V2 = '{yxAHAY_Lm6pbC/<';

    // V2 (AES-128-GCM) fixed parameters
    const GCM_NONCE = "\x54\x40\x78\x44\x49\x67\x5a\x51\x6c\x5e\x63\x13";
    const GCM_AAD   = 'qualcomm-test';

    const MODES     = ['auto' => 0, 'cool' => 1, 'dry' => 2, 'fan_only' => 3, 'heat' => 4];
    const MODES_INV = [0 => 'auto', 1 => 'cool', 2 => 'dry', 3 => 'fan_only', 4 => 'heat'];

    // ---- Crypto V1 (AES-128-ECB) ----------------------------------------

    public static function encryptV1(array $data, string $key): array {
        $raw = openssl_encrypt(json_encode($data), 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
        if ($raw === false) {
            throw new RuntimeException('GreeProtocol::encryptV1: ' . openssl_error_string());
        }
        return ['pack' => base64_encode($raw), 'tag' => null];
    }

    public static function decryptV1(string $b64, string $key): array {
        $raw = openssl_decrypt(base64_decode($b64), 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
        if ($raw === false) {
            throw new RuntimeException('GreeProtocol::decryptV1: ' . openssl_error_string());
        }
        return json_decode($raw, true) ?: [];
    }

    // ---- Crypto V2 (AES-128-GCM) ----------------------------------------

    public static function encryptV2(array $data, string $key): array {
        $tag = '';
        $raw = openssl_encrypt(
            json_encode($data), 'aes-128-gcm', $key,
            OPENSSL_RAW_DATA, self::GCM_NONCE, $tag, self::GCM_AAD, 16
        );
        if ($raw === false) {
            throw new RuntimeException('GreeProtocol::encryptV2: ' . openssl_error_string());
        }
        return ['pack' => base64_encode($raw), 'tag' => base64_encode($tag)];
    }

    public static function decryptV2(string $b64, string $key, string $tagB64 = ''): array {
        $tag   = $tagB64 ? base64_decode($tagB64) : '';
        $plain = openssl_decrypt(
            base64_decode($b64), 'aes-128-gcm', $key,
            OPENSSL_RAW_DATA, self::GCM_NONCE, $tag, self::GCM_AAD
        );
        if ($plain === false) {
            throw new RuntimeException('GreeProtocol::decryptV2: ' . openssl_error_string());
        }
        return json_decode($plain, true) ?: [];
    }

    // ---- Envelope builder --------------------------------------------------

    private static function buildEnvelope(array $encrypted, string $mac, int $i = 1): string {
        $envelope = [
            't'    => 'pack',
            'i'    => $i,
            'uid'  => 0,
            'cid'  => 'app',
            'tcid' => $mac,
            'pack' => $encrypted['pack'],
        ];
        if ($encrypted['tag'] !== null) {
            $envelope['tag'] = $encrypted['tag'];
        }
        return json_encode($envelope);
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
            if (@socket_recvfrom($sock, $buf, 65535, 0, $from, $port) === false || $buf === '') break;
            $responses[] = ['ip' => $from, 'raw' => $buf];
        }
        socket_close($sock);
        return $responses;
    }

    // ---- Response decryption helper ----------------------------------------

    private static function decryptResponse(array $envelope, string $key, string $cipher): array {
        if (!isset($envelope['pack'])) {
            throw new RuntimeException('GreeProtocol: no pack in response');
        }
        if ($cipher === 'v2') {
            return self::decryptV2($envelope['pack'], $key, $envelope['tag'] ?? '');
        }
        return self::decryptV1($envelope['pack'], $key);
    }

    // ---- Public API ---------------------------------------------------------

    /**
     * Discover devices via UDP broadcast.
     *
     * @return array[] Each item: ['ip', 'mac', 'name', 'ver', 'brand', 'modelType', 'hid']
     */
    public static function discover(string $broadcastIp = '192.168.1.255', int $timeout = 3): array {
        $responses = self::udpBroadcast($broadcastIp, json_encode(['t' => 'scan']), $timeout);

        $devices = [];
        foreach ($responses as $r) {
            $envelope = json_decode($r['raw'], true);
            if (!isset($envelope['pack'])) continue;

            // scan responses use V1 generic key regardless of device cipher version
            $pack = self::decryptV1($envelope['pack'], self::GENERIC_KEY_V1);
            if (($pack['t'] ?? '') !== 'dev') continue;

            $devices[] = [
                'ip'        => $r['ip'],
                'mac'       => $pack['mac']       ?? $pack['cid'] ?? '',
                'name'      => $pack['name']      ?? '',
                'ver'       => $pack['ver']        ?? '',
                'brand'     => $pack['brand']      ?? '',
                'modelType' => $pack['ModelType']  ?? '',
                'hid'       => $pack['hid']        ?? '',
            ];
        }
        return $devices;
    }

    /**
     * Bind to a device, trying V1 (ECB) first, then V2 (GCM) on timeout.
     *
     * @return array ['key' => string, 'cipher' => 'v1'|'v2']
     * @throws RuntimeException on failure
     */
    public static function bind(string $ip, string $mac): array {
        // Try V1 first
        try {
            return self::bindWithCipher($ip, $mac, 'v1');
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'timeout') === false) {
                throw $e;
            }
        }

        // V1 timed out → try V2
        return self::bindWithCipher($ip, $mac, 'v2');
    }

    private static function bindWithCipher(string $ip, string $mac, string $cipher): array {
        $payload    = ['mac' => $mac, 't' => 'bind', 'uid' => 0];
        $genericKey = $cipher === 'v2' ? self::GENERIC_KEY_V2 : self::GENERIC_KEY_V1;
        $encrypted  = $cipher === 'v2'
            ? self::encryptV2($payload, $genericKey)
            : self::encryptV1($payload, $genericKey);

        $request  = self::buildEnvelope($encrypted, $mac, 1);
        $raw      = self::udpSend($ip, $request);
        $envelope = json_decode($raw, true);
        $response = self::decryptResponse($envelope, $genericKey, $cipher);

        if (($response['t'] ?? '') !== 'bindok') {
            throw new RuntimeException("GreeProtocol::bind: expected bindok, got '" . ($response['t'] ?? 'none') . "'");
        }
        if (empty($response['key'])) {
            throw new RuntimeException("GreeProtocol::bind: empty key in response");
        }
        return ['key' => $response['key'], 'cipher' => $cipher];
    }

    /**
     * Read device status.
     *
     * @param string $cipher 'v1' or 'v2'
     * @return array Associative: ['Pow' => 1, 'Mod' => 1, 'SetTem' => 24, ...]
     */
    public static function getStatus(string $ip, string $mac, string $key, string $cipher = 'v1'): array {
        $cols = [
            'Pow', 'Mod', 'SetTem', 'WdSpd', 'Air', 'Blo', 'Health',
            'SwhSlp', 'Lig', 'SwingLfRig', 'SwUpDn', 'Quiet', 'Tur',
            'StHt', 'TemUn', 'HeatCoolType', 'TemRec', 'SvSt', 'TemSen',
            'OutEnvTem', 'DwatSen', 'SlpMod', 'AntiDirectBlow',
        ];

        $payload   = ['mac' => $mac, 't' => 'status', 'cols' => $cols];
        $encrypted = $cipher === 'v2' ? self::encryptV2($payload, $key) : self::encryptV1($payload, $key);
        $request   = self::buildEnvelope($encrypted, $mac, 0);

        $raw      = self::udpSend($ip, $request);
        $envelope = json_decode($raw, true);
        $response = self::decryptResponse($envelope, $key, $cipher);

        if (($response['t'] ?? '') !== 'dat') {
            throw new RuntimeException("GreeProtocol::getStatus: expected dat, got '" . ($response['t'] ?? 'none') . "'");
        }
        return array_combine($response['cols'], $response['dat']);
    }

    /**
     * Send a command to the device.
     *
     * @param array  $params Associative: ['Pow' => 1, 'SetTem' => 24, ...]
     * @param string $cipher 'v1' or 'v2'
     */
    public static function sendCommand(string $ip, string $mac, string $key, array $params, string $cipher = 'v1'): bool {
        $payload   = ['opt' => array_keys($params), 'p' => array_values($params), 't' => 'cmd'];
        $encrypted = $cipher === 'v2' ? self::encryptV2($payload, $key) : self::encryptV1($payload, $key);
        $request   = self::buildEnvelope($encrypted, $mac, 0);

        $raw      = self::udpSend($ip, $request);
        $envelope = json_decode($raw, true);
        if (!isset($envelope['pack'])) return false;

        $response = self::decryptResponse($envelope, $key, $cipher);
        return ($response['r'] ?? 0) === 200;
    }
}
