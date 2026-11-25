<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging, HTTP v1 (no SDK): the service account signs a JWT with openssl, Google swaps it for an access token
 * (cached for 50 minutes), and each message goes to one phone token. The key file lives outside the repo (services.firebase.credentials).
 * No key: nothing is sent and it is logged once a day. A token Google no longer knows is reported back so the caller can drop it.
 */
class PushSender
{
    public const CHANNELS = ['reminders', 'activity', 'account'];

    /** Results of send(). */
    public const SENT = 'sent';

    public const OFF = 'off';           // no credentials: nothing was sent

    public const BAD_TOKEN = 'bad_token'; // the token is dead: remove it

    public const FAILED = 'failed';     // try another time

    /** @var array<string, mixed>|null|false */
    private array|null|false $account = null;

    public function enabled(): bool
    {
        return $this->account() !== null;
    }

    /**
     * @param  array<string, scalar|null>  $data  kind, link, notification_id, app (all sent as strings)
     */
    public function send(string $token, string $title, string $body, array $data, string $channel = 'activity', bool $urgent = false): string
    {
        $account = $this->account();
        if ($account === null) {
            return self::OFF;
        }
        $access = $this->accessToken($account);
        if ($access === null) {
            return self::FAILED;
        }
        $message = [
            'token' => $token,
            'notification' => ['title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 400)],
            'data' => array_map(fn ($v) => (string) $v, array_filter($data, fn ($v) => $v !== null)),
            'android' => [
                'priority' => $urgent ? 'HIGH' : 'NORMAL',
                'notification' => ['channel_id' => in_array($channel, self::CHANNELS, true) ? $channel : 'activity'],
            ],
        ];
        try {
            $r = Http::withToken($access)->timeout(10)->acceptJson()
                ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode((string) $account['project_id']).'/messages:send', ['message' => $message]);
        } catch (\Throwable $e) {
            Log::warning('FCM unreachable', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return self::FAILED;
        }
        if ($r->successful()) {
            return self::SENT;
        }
        if ($r->status() === 401) {
            Cache::forget($this->cacheKey($account));
        }

        return self::isDeadToken($r->status(), (array) $r->json()) ? self::BAD_TOKEN : self::FAILED;
    }

    /** UNREGISTERED (404), SENDER_ID_MISMATCH (403) and a token Google calls not valid (400) mean the token is dead. */
    public static function isDeadToken(int $status, array $json): bool
    {
        $codes = collect($json['error']['details'] ?? [])->pluck('errorCode')->filter()->all();
        if (array_intersect($codes, ['UNREGISTERED', 'SENDER_ID_MISMATCH']) !== [] || $status === 404) {
            return true;
        }
        $msg = strtolower((string) ($json['error']['message'] ?? ''));

        return $status === 400 && (in_array('INVALID_ARGUMENT', $codes, true) || ($json['error']['status'] ?? '') === 'INVALID_ARGUMENT')
            && str_contains($msg, 'registration token');
    }

    /** @return array{client_email: string, private_key: string, project_id: string, token_uri?: string}|null */
    private function account(): ?array
    {
        if ($this->account !== null) {
            return $this->account ?: null;
        }
        $path = trim((string) config('services.firebase.credentials'));
        $json = $path !== '' && is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key']) || empty($json['project_id'])) {
            if (Cache::add('push:off-logged:'.now()->toDateString(), 1, now()->addDay())) {
                Log::info('Push notifications are off: FIREBASE_CREDENTIALS is unset or not a readable service-account file.');
            }
            $this->account = false;

            return null;
        }

        return $this->account = $json;
    }

    private function cacheKey(array $account): string
    {
        return 'push:fcm-access:'.sha1((string) $account['client_email']);
    }

    private function accessToken(array $account): ?string
    {
        $key = $this->cacheKey($account);
        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }
        $uri = (string) ($account['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $jwt = self::jwt($account, $uri, time());
        if ($jwt === null) {
            Log::warning('Push: the Firebase service-account key could not sign.');

            return null;
        }
        try {
            $r = Http::asForm()->timeout(10)->post($uri, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]);
        } catch (\Throwable $e) {
            Log::warning('Push: Google token endpoint unreachable', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
        $token = $r->successful() ? (string) ($r->json('access_token') ?? '') : '';
        if ($token === '') {
            Log::warning('Push: Google refused the service account', ['status' => $r->status()]);

            return null;
        }
        Cache::put($key, $token, now()->addSeconds(max(60, min(3000, (int) ($r->json('expires_in') ?? 3600) - 300))));

        return $token;
    }

    /** The signed RS256 assertion Google swaps for an access token. */
    public static function jwt(array $account, string $audience, int $now): ?string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(json_encode([
            'iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $audience, 'iat' => $now, 'exp' => $now + 3600,
        ]));
        $key = openssl_pkey_get_private((string) $account['private_key']);
        if ($key === false || ! openssl_sign($head.'.'.$claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $head.'.'.$claims.'.'.$b64($signature);
    }
}
