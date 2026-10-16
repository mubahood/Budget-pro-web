<?php

namespace App\Support;

/**
 * A business's own colour (docs/STANDALONE_EDITION_PLAN.md §15), and the few shades the screens need.
 *
 * The back office draws everything that says "this is the brand" — buttons,
 * links, the active menu row, focus rings — from three CSS variables, so one
 * colour re-tints the whole system without a stylesheet per business. The same
 * colour is the accent on every printed document.
 *
 * Only a colour dark enough to carry white text is accepted: a primary button
 * is white on the brand colour and a link is the brand colour on white, and a
 * pale cyan fails both. The bar is WCAG AA for body text (4.5:1).
 *
 * Every value that reaches a stylesheet has been through normalise(), which
 * only ever returns `#RRGGBB` — nothing typed into the settings form can close
 * the `<style>` element it is printed into.
 */
final class BrandColour
{
    public const MIN_CONTRAST = 4.5;

    /** `#RRGGBB` in capitals, from `#rgb`, `rgb`, `#rrggbb` or `rrggbb`; null for anything else. */
    public static function normalise(?string $value): ?string
    {
        $value = ltrim(trim((string) $value), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $value)) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        return preg_match('/^[0-9a-fA-F]{6}$/', $value) ? '#'.strtoupper($value) : null;
    }

    /** The WCAG contrast ratio between this colour and white, from 1 to 21. */
    public static function contrastWithWhite(string $hex): float
    {
        return 1.05 / (self::luminance($hex) + 0.05);
    }

    public static function readableOnWhite(string $hex): bool
    {
        return self::contrastWithWhite($hex) >= self::MIN_CONTRAST;
    }

    /**
     * The shades the stylesheets use: the colour itself, a darker one for
     * hover and pressed states, and a pale tint for selected rows and badges.
     *
     * @return array{base:string, dark:string, soft:string}
     */
    public static function palette(string $hex): array
    {
        return [
            'base' => $hex,
            'dark' => self::mix($hex, '#000000', 0.18),
            'soft' => self::mix($hex, '#FFFFFF', 0.9),
        ];
    }

    /** `$weight` of the way from `$from` to `$to`. */
    public static function mix(string $from, string $to, float $weight): string
    {
        [$r1, $g1, $b1] = self::channels($from);
        [$r2, $g2, $b2] = self::channels($to);

        $blend = fn (int $a, int $b): int => (int) round($a + ($b - $a) * $weight);

        return sprintf('#%02X%02X%02X', $blend($r1, $r2), $blend($g1, $g2), $blend($b1, $b2));
    }

    private static function luminance(string $hex): float
    {
        $linear = static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        [$r, $g, $b] = self::channels($hex);

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
