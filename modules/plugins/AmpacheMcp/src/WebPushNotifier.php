<?php

declare(strict_types=1);

namespace AmpacheMcp;

final class WebPushNotifier
{
    private const DEFAULT_TTL = 300;

    public function __construct(
        private PushSubscriptionStore $subscriptions,
        private string $vapidSubject,
        private string $vapidPublicKey,
        private string $vapidPrivateKey,
        private string $defaultClickUrl = ''
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->vapidSubject !== '' && $this->vapidPublicKey !== '' && $this->vapidPrivateKey !== '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array{configured: bool, attempted: int, sent: int, failed: int, failures: list<array{id: string, endpoint: string, status: int, message: string}>}
     */
    public function send(array $data): array
    {
        $subscriptions = $this->subscriptions->all();
        $result = [
            'configured' => $this->isConfigured(),
            'attempted' => count($subscriptions),
            'sent' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        if (!$this->isConfigured() || $subscriptions === []) {
            return $result;
        }

        $payload = $this->payload($data);
        foreach ($subscriptions as $subscription) {
            try {
                $response = $this->sendOne($subscription, $payload);
                if ($response['status'] >= 200 && $response['status'] < 300) {
                    ++$result['sent'];
                    continue;
                }

                ++$result['failed'];
                $result['failures'][] = [
                    'id' => (string)($subscription['id'] ?? ''),
                    'endpoint' => (string)($subscription['endpoint'] ?? ''),
                    'status' => $response['status'],
                    'message' => $response['body'],
                ];
                if (in_array($response['status'], [404, 410], true)) {
                    $this->subscriptions->deleteById((string)($subscription['id'] ?? ''));
                }
            } catch (\Throwable $error) {
                ++$result['failed'];
                $result['failures'][] = [
                    'id' => (string)($subscription['id'] ?? ''),
                    'endpoint' => (string)($subscription['endpoint'] ?? ''),
                    'status' => 0,
                    'message' => $error->getMessage(),
                ];
            }
        }

        return $result;
    }

    public function publicKey(): string
    {
        return $this->vapidPublicKey;
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array{status: int, body: string}
     */
    private function sendOne(array $subscription, string $payload): array
    {
        $endpoint = (string)($subscription['endpoint'] ?? '');
        $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        if ($endpoint === '' || (string)($keys['p256dh'] ?? '') === '' || (string)($keys['auth'] ?? '') === '') {
            throw new \RuntimeException('Stored push subscription is missing endpoint or keys.');
        }

        $encrypted = $this->encryptPayload(
            $payload,
            (string)$keys['p256dh'],
            (string)$keys['auth']
        );

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize curl for push notification.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encrypted['body'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'TTL: ' . self::DEFAULT_TTL,
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Authorization: ' . $this->vapidAuthorizationHeader($endpoint),
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new \RuntimeException('Push notification request failed: ' . $error);
        }

        return [
            'status' => $status,
            'body' => (string)$body,
        ];
    }

    /**
     * @return array{body: string}
     */
    private function encryptPayload(string $payload, string $userPublicKey, string $authSecret): array
    {
        $userPublic = ampache_mcp_base64url_decode($userPublicKey);
        $auth = ampache_mcp_base64url_decode($authSecret);
        if (strlen($userPublic) !== 65 || $userPublic[0] !== "\x04") {
            throw new \RuntimeException('Push subscription p256dh key is invalid.');
        }

        $serverKey = $this->generateEcKey();
        $serverPublic = $this->publicKeyBytes($serverKey);
        $sharedSecret = openssl_pkey_derive($this->publicKeyPem($userPublic), $serverKey, 32);
        if (!is_string($sharedSecret) || strlen($sharedSecret) !== 32) {
            throw new \RuntimeException('Unable to derive Web Push shared secret.');
        }

        $ikmInfo = "WebPush: info\x00" . $userPublic . $serverPublic;
        $ikm = $this->hkdf($sharedSecret, $auth, $ikmInfo, 32);
        $salt = random_bytes(16);
        $cek = $this->hkdf($ikm, $salt, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $this->hkdf($ikm, $salt, "Content-Encoding: nonce\x00", 12);

        $tag = '';
        $record = $payload . "\x02";
        $ciphertext = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Unable to encrypt Web Push payload.');
        }

        $header = $salt . pack('N', 4096) . chr(strlen($serverPublic)) . $serverPublic;

        return ['body' => $header . $ciphertext . $tag];
    }

    private function vapidAuthorizationHeader(string $endpoint): string
    {
        $jwtHeader = ['typ' => 'JWT', 'alg' => 'ES256'];
        $jwtBody = [
            'aud' => $this->audience($endpoint),
            'exp' => time() + 12 * 60 * 60,
            'sub' => $this->vapidSubject,
        ];

        $unsigned = ampache_mcp_base64url_encode((string)json_encode($jwtHeader))
            . '.'
            . ampache_mcp_base64url_encode((string)json_encode($jwtBody));

        $privateKey = openssl_pkey_get_private($this->privateKeyPem(
            ampache_mcp_base64url_decode($this->vapidPrivateKey),
            ampache_mcp_base64url_decode($this->vapidPublicKey)
        ));
        if ($privateKey === false) {
            throw new \RuntimeException('VAPID private key is invalid.');
        }

        $signature = '';
        if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign VAPID token.');
        }

        return 'vapid t=' . $unsigned . '.' . ampache_mcp_base64url_encode($this->ecdsaDerToJose($signature))
            . ', k=' . $this->vapidPublicKey;
    }

    private function payload(array $data): string
    {
        $payload = [
            'title' => (string)($data['title'] ?? 'AI Queue ready'),
            'body' => (string)($data['body'] ?? 'Your AI Queue playlist has been updated.'),
            'url' => (string)($data['url'] ?? $this->defaultClickUrl),
            'tag' => (string)($data['tag'] ?? 'ampache-ai-queue'),
            'data' => is_array($data['data'] ?? null) ? $data['data'] : [],
        ];

        return (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return \OpenSSLAsymmetricKey
     */
    private function generateEcKey()
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($key === false) {
            throw new \RuntimeException('Unable to generate Web Push ECDH key.');
        }

        return $key;
    }

    /**
     * @param \OpenSSLAsymmetricKey $key
     */
    private function publicKeyBytes($key): string
    {
        $details = openssl_pkey_get_details($key);
        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        if (!is_string($x) || !is_string($y)) {
            throw new \RuntimeException('Unable to read generated Web Push public key.');
        }

        return "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);
    }

    private function audience(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (string)$parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private function hkdf(string $ikm, string $salt, string $info, int $length): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $t = '';
        $okm = '';
        $block = 1;
        while (strlen($okm) < $length) {
            $t = hash_hmac('sha256', $t . $info . chr($block), $prk, true);
            $okm .= $t;
            ++$block;
        }

        return substr($okm, 0, $length);
    }

    private function publicKeyPem(string $publicKey): string
    {
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $publicKey;

        return $this->pem('PUBLIC KEY', $der);
    }

    private function privateKeyPem(string $privateKey, string $publicKey): string
    {
        if (strlen($privateKey) !== 32 || strlen($publicKey) !== 65) {
            throw new \RuntimeException('VAPID keys must be P-256 base64url keys.');
        }

        $der = "\x30\x77\x02\x01\x01\x04\x20" . $privateKey
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00" . $publicKey;

        return $this->pem('EC PRIVATE KEY', $der);
    }

    private function pem(string $label, string $der): string
    {
        return "-----BEGIN " . $label . "-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END " . $label . "-----\n";
    }

    private function ecdsaDerToJose(string $signature): string
    {
        $offset = 0;
        if (ord($signature[$offset++]) !== 0x30) {
            throw new \RuntimeException('Invalid ECDSA signature.');
        }
        $this->readDerLength($signature, $offset);

        if (ord($signature[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid ECDSA signature R value.');
        }
        $rLength = $this->readDerLength($signature, $offset);
        $r = substr($signature, $offset, $rLength);
        $offset += $rLength;

        if (ord($signature[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid ECDSA signature S value.');
        }
        $sLength = $this->readDerLength($signature, $offset);
        $s = substr($signature, $offset, $sLength);

        return $this->trimInteger($r) . $this->trimInteger($s);
    }

    private function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++]);
        if ($length < 0x80) {
            return $length;
        }

        $bytes = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $bytes; ++$i) {
            $length = ($length << 8) | ord($der[$offset++]);
        }

        return $length;
    }

    private function trimInteger(string $value): string
    {
        $value = ltrim($value, "\x00");

        return str_pad(substr($value, -32), 32, "\x00", STR_PAD_LEFT);
    }
}
