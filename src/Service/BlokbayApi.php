<?php declare(strict_types=1);

namespace Blokbay\Payment\Service;

/**
 * Blokbay API client and webhook signature check. No Shopware classes: unit-testable on its own.
 */
class BlokbayApi
{
    public const DEFAULT_BASE = 'https://blokbay.com';
    public const TOLERANCE = 300;

    private string $secretKey;
    private string $base;
    /** @var (callable(string, string, array<string, string>, ?string): array{code: int, body: string})|null */
    private $transport;

    /**
     * @param (callable(string, string, array<string, string>, ?string): array{code: int, body: string})|null $transport
     */
    public function __construct(string $secretKey, string $base = self::DEFAULT_BASE, ?callable $transport = null)
    {
        $this->secretKey = trim($secretKey);
        $this->base = rtrim($base !== '' ? $base : self::DEFAULT_BASE, '/');
        $this->transport = $transport;
    }

    /** USD amount with 2 decimals, as a string ("249.90"). */
    public static function amount(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /**
     * @param array<string, string> $data
     * @return array<string, mixed>
     */
    public function createPayment(array $data, string $idempotencyKey = ''): array
    {
        return $this->request('POST', '/api/v1/payments', $data, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function getPayment(string $id): array
    {
        if (!preg_match('/^gp_[a-z0-9]+$/', $id)) {
            return ['error' => 'bad_id'];
        }

        return $this->request('GET', '/api/v1/payments/' . $id);
    }

    /**
     * @param array<string, string>|null $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $data = null, string $idempotencyKey = ''): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Blokbay-Shopware/1.0',
        ];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = substr($idempotencyKey, 0, 100);
        }
        $body = $data === null ? null : (string) json_encode($data);
        $res = $this->transport !== null
            ? ($this->transport)($method, $this->base . $path, $headers, $body)
            : self::curlTransport($method, $this->base . $path, $headers, $body);
        $json = json_decode($res['body'], true);
        if (!\is_array($json)) {
            return ['error' => 'bad_response', '_code' => $res['code']];
        }
        $json['_code'] = $res['code'];

        return $json;
    }

    /**
     * @param array<string, string> $headers
     * @return array{code: int, body: string}
     */
    private static function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $c = curl_init($url);
        if ($c === false) {
            return ['code' => 0, 'body' => ''];
        }
        curl_setopt_array($c, [
            \CURLOPT_CUSTOMREQUEST => $method !== '' ? $method : 'GET',
            \CURLOPT_HTTPHEADER => $h,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 20,
            \CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($c, \CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($c);
        $code = (int) curl_getinfo($c, \CURLINFO_HTTP_CODE);

        return ['code' => $code, 'body' => \is_string($out) ? $out : ''];
    }

    /**
     * Checks the Blokbay-Signature header: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the signing secret>.
     * Signatures older or newer than 5 minutes are refused (replay protection).
     */
    public static function verifySignature(string $rawBody, string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            $p = explode('=', trim($kv), 2);
            if (\count($p) === 2) {
                $parts[$p[0]] = $p[1];
            }
        }
        if (empty($parts['t']) || empty($parts['v1']) || !ctype_digit($parts['t'])) {
            return false;
        }
        $now ??= time();
        if (abs($now - (int) $parts['t']) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $parts['t'] . '.' . $rawBody, $secret);

        return hash_equals($expected, $parts['v1']);
    }
}
