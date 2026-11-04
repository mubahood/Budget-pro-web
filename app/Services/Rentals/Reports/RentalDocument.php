<?php

namespace App\Services\Rentals\Reports;

use App\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * Every Estate Ledger paper document from the outside in (docs/estate-ledger/03-improvements.md §9): the account's
 * letterhead with its logo, and the footer on every page (account, what it is, its period, page X of Y, when it was
 * made). The type scale and the look are in resources/views/rentals/document.blade.php. A document names the account
 * and nothing else (no product or platform name, KR-05).
 */
final class RentalDocument
{
    /** The widest and tallest a logo is kept at, in pixels: twice what it prints at, so a large upload stays small in the file. */
    private const LOGO_MAX_WIDTH = 480;

    private const LOGO_MAX_HEIGHT = 176;

    /** Above this a logo is kept as a JPEG on white rather than a PNG. */
    private const LOGO_MAX_PNG_BYTES = 30_000;

    /** The longest line of the letterhead, in characters: cut, never wrapped under the next block. */
    private const LINE_MAX = 78;

    /** @var array<string, ?string> a logo's data address per file and its time */
    private static array $logos = [];

    /**
     * The account's letterhead. A receipt passes the name and phone it was issued under.
     *
     * @return array{name: string, logo: ?string, lines: list<string>}
     */
    public static function letterhead(int $companyId, ?string $name = null, ?string $phone = null): array
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        $fit = fn (array $parts) => mb_strimwidth(implode(' · ', array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== '')), 0, self::LINE_MAX, '...');
        $accountName = trim((string) ($name ?? $company?->name ?? ''));
        $where = strcasecmp(trim((string) $company?->address), $accountName) === 0 ? '' : (string) $company?->address;
        $pobox = trim((string) $company?->pobox);
        $lines = [$fit([$where, $pobox !== '' ? 'P.O. Box '.preg_replace('/^p\.?\s*o\.?\s*box\s*/i', '', $pobox) : '']),
            $fit([$phone ?? $company?->phone_number, $phone === null ? $company?->phone_number_2 : '', $company?->email, $company?->website])];

        return ['name' => $accountName, 'logo' => self::logo($company), 'lines' => array_values(array_filter($lines, fn ($l) => $l !== ''))];
    }

    /**
     * A letterhead as a receipt was issued: its own name, phone and logo, nothing that may have changed since. A receipt
     * issued before logos were written down wears the account's logo.
     *
     * @return array{name: string, logo: ?string, lines: list<string>}
     */
    public static function issued(int $companyId, string $name, ?string $phone, ?string $logoPath, bool $logoKnown): array
    {
        $company = $logoKnown ? null : Company::withoutGlobalScopes()->find($companyId);
        $logo = $logoKnown ? (trim((string) $logoPath) !== '' ? self::logoFile((string) $logoPath) : null) : self::logo($company);

        return ['name' => $name, 'logo' => $logo, 'lines' => array_values(array_filter([trim((string) $phone)], fn ($l) => $l !== ''))];
    }

    /**
     * HTML to a PDF with the footer on every page. $foot: left (who, what, which period) and generated (when; empty on a
     * document that must print the same each time).
     *
     * @param  string|array{0: float|int, 1: float|int, 2: float|int, 3: float|int}  $paper
     * @param  array{left: string, generated: string}|null  $foot
     */
    public static function output(string $html, string|array $paper = 'a4', string $orientation = 'portrait', ?array $foot = null, ?int &$pages = null): string
    {
        $pdf = app('dompdf.wrapper')->loadHTML($html)->setPaper($paper, $orientation);
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $pages = (int) $canvas->get_page_count();
        if ($foot !== null) {
            $canvas->page_script(function (int $page, int $count, $c, $metrics) use ($foot) {
                $font = $metrics->getFont('DejaVu Sans');
                $size = 6.0; // 8 px, the scale's caption
                $ink = [0.333, 0.376, 0.416];
                $w = $c->get_width();
                $y = $c->get_height() - 26;
                $margin = 25.5; // the page's 34 px
                $c->line($margin, $y - 5, $w - $margin, $y - 5, [0.118, 0.169, 0.2], 0.45);
                $right = 'Page '.$page.' of '.$count.($foot['generated'] !== '' ? ' · generated '.$foot['generated'] : '');
                $rightWidth = $metrics->getTextWidth($right, $font, $size);
                $room = $w - 2 * $margin - $rightWidth - 12;
                $left = $foot['left'];
                while ($left !== '' && $metrics->getTextWidth($left, $font, $size) > $room) {
                    $left = mb_substr($left, 0, -2);
                    $left = rtrim($left).'...';
                    if (mb_strlen($left) < 5) {
                        $left = '';
                    }
                }
                $c->text($margin, $y, $left, $font, $size, $ink);
                $c->text($w - $margin - $rightWidth, $y, $right, $font, $size, $ink);
            });
        }

        return (string) $pdf->output();
    }

    /**
     * How tall this HTML draws on a page of this width, in points: a narrow receipt's page is cut to its content.
     */
    public static function heightOf(string $html, float $widthPoints, float $tallest): float
    {
        $pdf = app('dompdf.wrapper')->loadHTML($html)->setPaper([0, 0, $widthPoints, $tallest]);
        $height = 0.0;
        $pdf->getDomPDF()->setCallbacks([['event' => 'end_frame', 'f' => function ($frame) use (&$height) {
            if (strtolower((string) $frame->get_node()->nodeName) === 'body') {
                $box = $frame->get_padding_box();
                $height = max($height, (float) $box['h']);
            }
        }]]);
        $pdf->render();

        return $height;
    }

    /** The account's logo as a small data address, or null: no logo, a missing file or one that cannot be read never stops a document. */
    private static function logo(?Company $company): ?string
    {
        if ($company === null) {
            return null;
        }
        foreach ([$company->getAttribute('logo_wide'), $company->logo] as $stored) {
            if (($logo = self::logoFile((string) $stored)) !== null) {
                return $logo;
            }
        }

        return null;
    }

    /** The path a logo is stored under, for a receipt to write down when it is issued. */
    public static function logoPath(?Company $company): string
    {
        foreach ([$company?->getAttribute('logo_wide'), $company?->logo] as $stored) {
            if (trim((string) $stored) !== '' && self::logoFile((string) $stored) !== null) {
                return trim((string) $stored);
            }
        }

        return '';
    }

    private static function logoFile(string $stored): ?string
    {
        $path = trim($stored);
        if ($path === '' || str_contains($path, '..') || preg_match('#^[a-z]+://#i', $path)) {
            return null;
        }
        $root = rtrim((string) (config('budgetpro.media_root') ?: config('filesystems.disks.admin.root') ?: public_path('storage')), '/');
        $file = $root.'/'.ltrim($path, '/');
        if (! is_file($file)) {
            return null;
        }
        $key = $file.'@'.(int) filemtime($file);
        if (! array_key_exists($key, self::$logos)) {
            self::$logos[$key] = self::shrink($file);
        }

        return self::$logos[$key];
    }

    private static function shrink(string $file): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        try {
            $bytes = @file_get_contents($file);
            $source = $bytes !== false && $bytes !== '' ? @imagecreatefromstring($bytes) : false;
            if ($source === false) {
                return null;
            }
            [$w, $h] = [imagesx($source), imagesy($source)];
            $scale = min(1, self::LOGO_MAX_WIDTH / max(1, $w), self::LOGO_MAX_HEIGHT / max(1, $h));
            [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
            $image = imagecreatetruecolor($nw, $nh);
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255)); // paper is white: no transparency to carry
            imagecopyresampled($image, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagepng($image, null, 9);
            $png = (string) ob_get_clean();
            if (strlen($png) <= self::LOGO_MAX_PNG_BYTES) {
                return 'data:image/png;base64,'.base64_encode($png);
            }
            ob_start();
            imagejpeg($image, null, 88);

            return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
        } catch (\Throwable $e) {
            Log::warning('A logo could not be read for a document: '.$e->getMessage());

            return null;
        }
    }
}
