<?php

namespace App\Services\Learn;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The 11-character id in any YouTube link (watch?v=, youtu.be/, /embed/, /shorts/, /live/, /v/, the mobile and music
 * sites, youtube-nocookie.com, or the bare id), and a video's title and picture from YouTube's oEmbed (no API key).
 */
final class YouTube
{
    private const ID = '/^[A-Za-z0-9_-]{11}$/';

    private const HOSTS = ['youtube.com', 'youtu.be', 'youtube-nocookie.com'];

    /** A neutral client name: nothing about the person or the account goes to YouTube. */
    private const AGENT = 'Mozilla/5.0 (compatible; LearnVideos/1.0)';

    public static function videoId(?string $input): ?string
    {
        $s = trim((string) $input);
        if ($s === '') {
            return null;
        }
        if (preg_match(self::ID, $s)) {
            return $s;
        }
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $s)) {
            $s = 'https://'.ltrim($s, '/');
        }
        $p = parse_url($s);
        if (! is_array($p) || ! isset($p['host'])) {
            return null;
        }
        $host = (string) preg_replace('/^(www\.|m\.|music\.)/', '', strtolower($p['host']));
        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }
        parse_str((string) ($p['query'] ?? ''), $query);
        $segments = array_values(array_filter(explode('/', (string) ($p['path'] ?? '')), 'strlen'));
        $candidate = match (true) {
            $host === 'youtu.be' => $segments[0] ?? null,
            isset($query['v']) && is_string($query['v']) => $query['v'],
            in_array($segments[0] ?? '', ['embed', 'shorts', 'live', 'v', 'e'], true) => $segments[1] ?? null,
            default => null,
        };

        return is_string($candidate) && preg_match(self::ID, $candidate) ? $candidate : null;
    }

    public static function thumbnail(string $id): string
    {
        return 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg';
    }

    /** @return array{youtube_id: string, title: string|null, thumbnail_url: string} */
    public static function lookup(string $id): array
    {
        $title = null;
        try {
            $r = Http::timeout(8)->acceptJson()->withHeaders(['User-Agent' => self::AGENT])
                ->get('https://www.youtube.com/oembed', ['url' => 'https://www.youtube.com/watch?v='.$id, 'format' => 'json']);
            if ($r->successful() && is_string($r->json('title'))) {
                $title = mb_substr(trim(html_entity_decode((string) $r->json('title'), ENT_QUOTES | ENT_HTML5)), 0, 160);
            }
        } catch (\Throwable $e) {
            Log::info('YouTube oEmbed unreachable', ['error' => $e->getMessage()]);
        }

        return ['youtube_id' => $id, 'title' => $title, 'thumbnail_url' => self::thumbnail($id)];
    }
}
