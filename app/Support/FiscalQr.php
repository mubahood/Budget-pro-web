<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * QR codes for fiscal receipts (supermarket plan F2), from bacon/bacon-qr-code's encoder (both apps have it).
 * svg() for HTML receipts; png() (GD, when present) for dompdf PDFs, which draw bitmaps more reliably than SVG.
 */
class FiscalQr
{
    /** @return list<list<bool>> the module matrix */
    public static function matrix(string $payload): array
    {
        $m = Encoder::encode($payload, ErrorCorrectionLevel::M())->getMatrix();
        $rows = [];
        for ($y = 0; $y < $m->getHeight(); $y++) {
            $row = [];
            for ($x = 0; $x < $m->getWidth(); $x++) {
                $row[] = $m->get($x, $y) === 1;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** An inline SVG (one path, 4-module quiet zone), $size px square. */
    public static function svg(string $payload, int $size = 120): string
    {
        $rows = self::matrix($payload);
        $n = count($rows) + 8;
        $d = '';
        foreach ($rows as $y => $row) {
            foreach ($row as $x => $on) {
                if ($on) {
                    $d .= 'M'.($x + 4).' '.($y + 4).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$n.' '.$n.'" shape-rendering="crispEdges" role="img" aria-label="Fiscal QR code">'
            .'<rect width="'.$n.'" height="'.$n.'" fill="#fff"/><path fill="#000" d="'.$d.'"/></svg>';
    }

    /** A data: URI for <img>: PNG when GD is available, else SVG. */
    public static function dataUri(string $payload, int $scale = 4): string
    {
        if (! function_exists('imagecreate')) {
            return 'data:image/svg+xml;base64,'.base64_encode(self::svg($payload));
        }
        $rows = self::matrix($payload);
        $n = (count($rows) + 8) * $scale;
        $img = imagecreate($n, $n);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        foreach ($rows as $y => $row) {
            foreach ($row as $x => $on) {
                if ($on) {
                    imagefilledrectangle($img, ($x + 4) * $scale, ($y + 4) * $scale, ($x + 5) * $scale - 1, ($y + 5) * $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
