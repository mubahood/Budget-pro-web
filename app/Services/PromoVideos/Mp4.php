<?php

namespace App\Services\PromoVideos;

/**
 * Just enough of the MP4 format for promo videos, in plain PHP (the host has no ffmpeg): how long a film is and its
 * size in pixels, and moving its index (the `moov` box) in front of the picture data, so a browser can start playing
 * and seek before the whole file has arrived ("fast start", as `ffmpeg -movflags +faststart` does). A file this does
 * not understand is left exactly as it is.
 */
class Mp4
{
    /** The boxes on the way from moov down to the chunk offset tables. */
    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl'];

    /** A moov larger than this is not read into memory (a promo film's is a few hundred KB). */
    private const MAX_MOOV = 64 * 1048576;

    /**
     * @return array{faststart: bool, duration: float|null, width: int|null, height: int|null}
     */
    public static function inspect(string $path): array
    {
        $out = ['faststart' => false, 'duration' => null, 'width' => null, 'height' => null];
        $boxes = self::topLevel($path);
        $moov = $boxes['moov'] ?? null;
        if (! $moov) {
            return $out;
        }
        $out['faststart'] = ! isset($boxes['mdat']) || $moov['at'] < $boxes['mdat']['at'];
        $data = self::read($path, $moov);
        if ($data === null) {
            return $out;
        }
        foreach (self::children($data, $moov['head'], strlen($data)) as [$type, $start, $end, $head]) {
            if ($type === 'mvhd') {
                $p = $start + $head;
                $v = ord($data[$p]);
                [$scale, $dur] = $v === 1
                    ? [self::u32($data, $p + 20), self::u64($data, $p + 24)]
                    : [self::u32($data, $p + 12), self::u32($data, $p + 16)];
                $out['duration'] = $scale > 0 ? round($dur / $scale, 2) : null;
            }
            if ($type === 'trak' && $out['width'] === null) {
                foreach (self::children($data, $start + $head, $end) as [$t, $s, $e]) {
                    if ($t === 'tkhd' && $e - $s >= 92) {
                        $w = self::u32($data, $e - 8) >> 16;
                        $h = self::u32($data, $e - 4) >> 16;
                        if ($w > 0 && $h > 0) {
                            [$out['width'], $out['height']] = [$w, $h];
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Move the index in front of the picture data, in place. Returns true when the file was rewritten, false when it
     * already starts with its index or is not a file this understands.
     */
    public static function faststart(string $path): bool
    {
        $boxes = self::topLevel($path);
        $moov = $boxes['moov'] ?? null;
        $mdat = $boxes['mdat'] ?? null;
        if (! $moov || ! $mdat || $moov['at'] < $mdat['at'] || $moov['size'] > self::MAX_MOOV) {
            return false;
        }
        $data = self::read($path, $moov);
        if ($data === null) {
            return false;
        }
        // Every chunk offset points into data that moves down by the size of the index put in front of it.
        $data = self::shiftOffsets($data, $moov['head'], strlen($data), $moov['size']);

        $tmp = $path.'.faststart';
        $in = fopen($path, 'rb');
        $out = fopen($tmp, 'wb');
        try {
            self::copy($in, $out, 0, $mdat['at']);                              // ftyp and anything else before the data
            fwrite($out, $data);                                                // the index
            self::copy($in, $out, $mdat['at'], $moov['at'] - $mdat['at']);      // the data
            $after = $moov['at'] + $moov['size'];
            self::copy($in, $out, $after, max(0, (int) filesize($path) - $after)); // anything after the old index
        } finally {
            fclose($in);
            fclose($out);
        }
        clearstatcache(true, $tmp);
        if (filesize($tmp) !== filesize($path)) {
            @unlink($tmp);

            return false;
        }
        rename($tmp, $path);

        return true;
    }

    /** @return array<string, array{at: int, size: int, head: int}> the first box of each type at the top level */
    private static function topLevel(string $path): array
    {
        $boxes = [];
        $total = (int) @filesize($path);
        $f = @fopen($path, 'rb');
        if (! $f) {
            return [];
        }
        $at = 0;
        while ($at + 8 <= $total) {
            fseek($f, $at);
            $h = fread($f, 16);
            if (strlen($h) < 8) {
                break;
            }
            $size = self::u32($h, 0);
            $type = substr($h, 4, 4);
            $head = 8;
            if ($size === 1) {
                if (strlen($h) < 16) {
                    break;
                }
                $size = self::u64($h, 8);
                $head = 16;
            } elseif ($size === 0) {
                $size = $total - $at;
            }
            if ($size < $head || ! preg_match('/^[\x20-\x7e]{4}$/', $type)) {
                break;
            }
            $boxes[$type] ??= ['at' => $at, 'size' => $size, 'head' => $head];
            $at += $size;
        }
        fclose($f);

        return $boxes;
    }

    /** @param array{at: int, size: int} $box */
    private static function read(string $path, array $box): ?string
    {
        if ($box['size'] > self::MAX_MOOV) {
            return null;
        }
        $f = fopen($path, 'rb');
        fseek($f, $box['at']);
        $data = fread($f, $box['size']);
        fclose($f);

        return strlen((string) $data) === $box['size'] ? $data : null;
    }

    /** @return \Generator<int, array{0: string, 1: int, 2: int, 3: int}> type, start, end, header length */
    private static function children(string $data, int $from, int $to): \Generator
    {
        $at = $from;
        while ($at + 8 <= $to) {
            $size = self::u32($data, $at);
            $head = 8;
            if ($size === 1) {
                $size = self::u64($data, $at + 8);
                $head = 16;
            } elseif ($size === 0) {
                $size = $to - $at;
            }
            if ($size < $head || $at + $size > $to) {
                return;
            }
            yield [substr($data, $at + 4, 4), $at, $at + $size, $head];
            $at += $size;
        }
    }

    private static function shiftOffsets(string $data, int $from, int $to, int $by): string
    {
        foreach (self::children($data, $from, $to) as [$type, $start, $end, $head]) {
            if (in_array($type, self::CONTAINERS, true)) {
                $data = self::shiftOffsets($data, $start + $head, $end, $by);
            } elseif ($type === 'stco' || $type === 'co64') {
                $p = $start + $head + 4;                      // version and flags
                $n = self::u32($data, $p);
                $wide = $type === 'co64';
                for ($i = 0, $q = $p + 4; $i < $n && $q + ($wide ? 8 : 4) <= $end; $i++, $q += $wide ? 8 : 4) {
                    if ($wide) {
                        $data = substr_replace($data, pack('J', self::u64($data, $q) + $by), $q, 8);
                    } else {
                        $v = self::u32($data, $q) + $by;
                        if ($v > 0xFFFFFFFF) {
                            throw new \DomainException('The film is too large to rearrange here. Export it with fast start (ffmpeg -movflags +faststart) and upload it again.');
                        }
                        $data = substr_replace($data, pack('N', $v), $q, 4);
                    }
                }
            }
        }

        return $data;
    }

    /** @param resource $in @param resource $out */
    private static function copy($in, $out, int $from, int $length): void
    {
        fseek($in, $from);
        while ($length > 0) {
            $chunk = fread($in, min(1048576, $length));
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($out, $chunk);
            $length -= strlen($chunk);
        }
    }

    private static function u32(string $s, int $at): int
    {
        return unpack('N', substr($s, $at, 4))[1];
    }

    private static function u64(string $s, int $at): int
    {
        return unpack('J', substr($s, $at, 8))[1];
    }
}
