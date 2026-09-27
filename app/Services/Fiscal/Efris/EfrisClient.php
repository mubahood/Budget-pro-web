<?php

namespace App\Services\Fiscal\Efris;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Uganda EFRIS system-to-system transport: the JSON envelope, the interface codes, and the encryption and
 * signing steps, each a separate method so each can be checked against URA's spec on its own.
 *
 * Built to URA's EFRIS system-to-system spec. Must be certified on URA's sandbox with the shop's own
 * credentials before production. Field names verified against: URA's public "EFRIS System to System
 * Integration / Interface Design" material (the `data` / `globalInfo` / `returnStateInfo` envelope, the
 * interface codes T101, T103, T104, T109, T110) and the request/response samples that circulate with the
 * public open-source EFRIS clients. Not verified against a live sandbox: this repository has no URA credentials.
 *
 * How a call works (as implemented here):
 *  1. The body is `{"data": {"content", "signature", "dataDescription"}, "globalInfo": {...}, "returnStateInfo": {...}}`,
 *     POSTed as JSON to the single endpoint (`.../ws/taapp/getInformation`); `globalInfo.interfaceCode` selects the call.
 *  2. T101 (server time) and T104 (symmetric key) travel in plain text: `content` is base64 of the JSON (or empty).
 *  3. T104 answers `passowrdDes` (URA's own spelling): the session AES key, RSA-encrypted with the taxpayer's
 *     public key. We decrypt it with the taxpayer's private key (PKCS#1 v1.5).
 *  4. Business calls (T109 upload invoice, T110 credit note) carry `content` = base64(AES-ECB-PKCS7(json, key)) and
 *     `signature` = base64(RSA-SHA1 signature of that content string with the private key);
 *     `dataDescription` = {codeType: "1" (cipher), encryptCode: "2" (AES), zipCode: "0"}.
 *  5. The answer's `returnStateInfo.returnCode` is "00" on success; its `data.content` is base64, AES-encrypted when
 *     `codeType` is "1" and gzipped when `zipCode` is "1".
 *
 * UNCERTAIN (check on the sandbox): the signature algorithm (SHA1withRSA assumed), whether the decrypted
 * `passowrdDes` is the raw key or base64 of it (both handled), the `encryptCode` values, and the order of
 * gzip vs AES in answers (decrypt, then gunzip, assumed).
 */
class EfrisClient
{
    public const SANDBOX_URL = 'https://efristest.ura.go.ug/efrisws/ws/taapp/getInformation';

    public const PRODUCTION_URL = 'https://efrisws.ura.go.ug/ws/taapp/getInformation';

    /** Interface codes used here. */
    public const T101_SERVER_TIME = 'T101';

    public const T103_SIGN_IN = 'T103';

    public const T104_SYMMETRIC_KEY = 'T104';

    public const T109_UPLOAD_INVOICE = 'T109';

    public const T110_CREDIT_NOTE = 'T110';

    public const SIGNATURE_ALGO = OPENSSL_ALGO_SHA1;

    private ?string $aesKey = null;

    /**
     * @param  array{tin: string, device_no: string, brn?: string, app_id?: string, version?: string, user_name?: string, operator?: string}  $identity
     */
    public function __construct(
        private readonly string $url,
        private readonly array $identity,
        private readonly string $privateKeyPem,
        private readonly ?string $passphrase = null,
        private readonly int $timeout = 30,
    ) {
    }

    public static function urlFor(string $environment): string
    {
        return $environment === 'production'
            ? (string) (config('fiscal.efris.production_url') ?: self::PRODUCTION_URL)
            : (string) (config('fiscal.efris.sandbox_url') ?: self::SANDBOX_URL);
    }

    // ── Envelope ─────────────────────────────────────────────

    /** @return array<string, mixed> the whole request body */
    public function envelope(string $interfaceCode, string $content, string $signature, bool $encrypted): array
    {
        return [
            'data' => [
                'content' => $content,
                'signature' => $signature,
                'dataDescription' => $encrypted
                    ? ['codeType' => '1', 'encryptCode' => '2', 'zipCode' => '0']
                    : ['codeType' => '0', 'encryptCode' => '1', 'zipCode' => '0'],
            ],
            'globalInfo' => $this->globalInfo($interfaceCode),
            'returnStateInfo' => ['returnCode' => '', 'returnMessage' => ''],
        ];
    }

    /** @return array<string, mixed> */
    public function globalInfo(string $interfaceCode): array
    {
        return [
            'appId' => (string) ($this->identity['app_id'] ?? config('fiscal.efris.app_id') ?: 'AP04'),
            'version' => (string) ($this->identity['version'] ?? config('fiscal.efris.version') ?: '1.1.20191201'),
            'dataExchangeId' => $this->dataExchangeId(),
            'interfaceCode' => $interfaceCode,
            'requestCode' => 'TP',
            'requestTime' => now('Africa/Kampala')->format('Y-m-d H:i:s'),
            'responseCode' => 'TA',
            'userName' => (string) ($this->identity['user_name'] ?? 'admin'),
            'deviceMAC' => 'FFFFFFFFFFFF',
            'deviceNo' => (string) $this->identity['device_no'],
            'tin' => (string) $this->identity['tin'],
            'brn' => (string) ($this->identity['brn'] ?? ''),
            'taxpayerID' => '1',
            'longitude' => '',
            'latitude' => '',
            'agentType' => '0',
            'extendField' => [
                'responseDateFormat' => 'dd/MM/yyyy',
                'responseTimeFormat' => 'dd/MM/yyyy HH:mm:ss',
                'referenceNo' => '',
                'operatorName' => (string) ($this->identity['operator'] ?? ''),
            ],
        ];
    }

    /** 32 hex characters, unique per request. */
    public function dataExchangeId(): string
    {
        return bin2hex(random_bytes(16));
    }

    // ── Encryption and signing ───────────────────────────────

    private static function cipher(string $key): string
    {
        return match (strlen($key)) {
            16 => 'aes-128-ecb', 24 => 'aes-192-ecb', 32 => 'aes-256-ecb',
            default => throw new RuntimeException('The EFRIS session key has an unexpected length.'),
        };
    }

    /** base64(AES/ECB/PKCS5Padding($plain)). */
    public static function aesEncrypt(string $plain, string $key): string
    {
        $out = openssl_encrypt($plain, self::cipher($key), $key, OPENSSL_RAW_DATA);
        if ($out === false) {
            throw new RuntimeException('Could not encrypt the EFRIS request.');
        }

        return base64_encode($out);
    }

    public static function aesDecrypt(string $base64, string $key): string
    {
        $out = openssl_decrypt((string) base64_decode($base64, true), self::cipher($key), $key, OPENSSL_RAW_DATA);
        if ($out === false) {
            throw new RuntimeException('Could not decrypt the EFRIS answer.');
        }

        return $out;
    }

    /** @return \OpenSSLAsymmetricKey */
    private function privateKey()
    {
        $key = openssl_pkey_get_private($this->privateKeyPem, (string) $this->passphrase);
        if ($key === false) {
            throw new RuntimeException('The private key could not be read. Check the PEM text and its password.');
        }

        return $key;
    }

    /** base64 signature of $content with the taxpayer's private key. */
    public function sign(string $content): string
    {
        if (! openssl_sign($content, $signature, $this->privateKey(), self::SIGNATURE_ALGO)) {
            throw new RuntimeException('Could not sign the EFRIS request with the private key.');
        }

        return base64_encode($signature);
    }

    /** The AES key inside T104's `passowrdDes`: RSA-decrypted with the private key; raw or base64 inside. */
    public function decryptSymmetricKey(string $passowrdDes): string
    {
        if (! openssl_private_decrypt((string) base64_decode($passowrdDes, true), $plain, $this->privateKey(), OPENSSL_PKCS1_PADDING)) {
            throw new RuntimeException('URA\'s session key could not be decrypted with this private key. Is it the key registered for this TIN?');
        }
        $decoded = base64_decode($plain, true);
        if ($decoded !== false && in_array(strlen($decoded), [16, 24, 32], true)) {
            return $decoded;
        }
        if (in_array(strlen($plain), [16, 24, 32], true)) {
            return $plain;
        }
        throw new RuntimeException('URA\'s session key has an unexpected length.');
    }

    // ── Transport ────────────────────────────────────────────

    /**
     * One call. $payload null = empty content. Business calls are encrypted with the session key (fetched first).
     *
     * @return array{ok: bool, code: string, message: string, content: mixed, request: array<string, mixed>, response: mixed}
     */
    public function call(string $interfaceCode, ?array $payload, bool $encrypt = false): array
    {
        if ($encrypt && $this->aesKey === null) {
            $this->symmetricKey();
        }
        $json = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $content = $json === '' ? '' : ($encrypt ? self::aesEncrypt($json, (string) $this->aesKey) : base64_encode($json));
        $signature = $content === '' ? '' : $this->sign($content);
        $body = $this->envelope($interfaceCode, $content, $signature, $encrypt);

        // What we keep: the plain payload, never the key, the cipher text or the signature.
        $safe = $body;
        $safe['data']['content'] = $payload;
        $safe['data']['signature'] = $signature === '' ? '' : '[signed]';

        $res = Http::timeout($this->timeout)->acceptJson()->asJson()->post($this->url, $body);
        $answer = $res->json();
        if (! is_array($answer)) {
            return ['ok' => false, 'code' => 'http_'.$res->status(), 'message' => 'EFRIS did not answer (HTTP '.$res->status().').', 'content' => null, 'request' => $safe, 'response' => mb_substr((string) $res->body(), 0, 2000)];
        }
        $code = (string) ($answer['returnStateInfo']['returnCode'] ?? '');
        $message = (string) ($answer['returnStateInfo']['returnMessage'] ?? '');
        $decoded = $this->decodeContent((array) ($answer['data'] ?? []));
        $parsed = is_string($decoded) && $decoded !== '' ? (json_decode($decoded, true) ?? $decoded) : $decoded;

        return ['ok' => $code === '00', 'code' => $code, 'message' => $message, 'content' => $parsed, 'request' => $safe,
            'response' => ['returnStateInfo' => $answer['returnStateInfo'] ?? null, 'content' => $interfaceCode === self::T104_SYMMETRIC_KEY ? '[key exchange]' : $parsed]];
    }

    /** base64 → AES-decrypt (codeType 1) → gunzip (zipCode 1). */
    public function decodeContent(array $data): ?string
    {
        $content = (string) ($data['content'] ?? '');
        if ($content === '') {
            return null;
        }
        $desc = (array) ($data['dataDescription'] ?? []);
        $bytes = ($desc['codeType'] ?? '0') === '1' && $this->aesKey !== null
            ? self::aesDecrypt($content, $this->aesKey)
            : (string) base64_decode($content, true);
        if (($desc['zipCode'] ?? '0') === '1') {
            $bytes = @gzdecode($bytes) ?: $bytes;
        }

        return $bytes;
    }

    // ── Interface codes ──────────────────────────────────────

    /** T101: URA's clock (no key needed). */
    public function serverTime(): array
    {
        return $this->call(self::T101_SERVER_TIME, null);
    }

    /** T103: sign in; answers the taxpayer and device details URA holds. Run after T104 on some set-ups. */
    public function signIn(): array
    {
        return $this->call(self::T103_SIGN_IN, null, true);
    }

    /** T104: fetch and decrypt the session AES key. */
    public function symmetricKey(): string
    {
        $r = $this->call(self::T104_SYMMETRIC_KEY, null);
        $des = is_array($r['content']) ? ($r['content']['passowrdDes'] ?? $r['content']['passwordDes'] ?? null) : null;
        if (! $r['ok'] || ! is_string($des) || $des === '') {
            throw new RuntimeException('EFRIS key exchange (T104) failed: '.($r['message'] ?: $r['code'] ?: 'no key in the answer').'.');
        }

        return $this->aesKey = $this->decryptSymmetricKey($des);
    }

    /** T109: upload an invoice / receipt. */
    public function uploadInvoice(array $invoice): array
    {
        return $this->call(self::T109_UPLOAD_INVOICE, $invoice, true);
    }

    /** T110: apply for a credit note against an issued invoice. */
    public function creditNote(array $application): array
    {
        return $this->call(self::T110_CREDIT_NOTE, $application, true);
    }

    /** Tests: the session key in use. */
    public function sessionKey(): ?string
    {
        return $this->aesKey;
    }
}
