<?php

namespace App\Services\Budget;

/**
 * The fonts a card's name can be printed in (SIL Open Font License, from Google Fonts). The files are in the web
 * portal's resources/fonts/cards, cut to Latin letters, and the browser draws with them.
 */
final class CardFonts
{
    public const DEFAULT = 'great-vibes';

    /** key => [label, file, group]. */
    public const ALL = [
        'great-vibes' => ['Great Vibes', 'GreatVibes-Regular.ttf', 'script'],
        'alex-brush' => ['Alex Brush', 'AlexBrush-Regular.ttf', 'script'],
        'allura' => ['Allura', 'Allura-Regular.ttf', 'script'],
        'parisienne' => ['Parisienne', 'Parisienne-Regular.ttf', 'script'],
        'sacramento' => ['Sacramento', 'Sacramento-Regular.ttf', 'script'],
        'italianno' => ['Italianno', 'Italianno-Regular.ttf', 'script'],
        'arizonia' => ['Arizonia', 'Arizonia-Regular.ttf', 'script'],
        'kaushan' => ['Kaushan Script', 'KaushanScript-Regular.ttf', 'script'],
        'playball' => ['Playball', 'Playball-Regular.ttf', 'script'],
        'corinthia' => ['Corinthia', 'Corinthia-Regular.ttf', 'script'],
        'pinyon' => ['Pinyon Script', 'PinyonScript-Regular.ttf', 'formal'],
        'tangerine' => ['Tangerine', 'Tangerine-Bold.ttf', 'formal'],
        'saint-delafield' => ['Mrs Saint Delafield', 'MrsSaintDelafield-Regular.ttf', 'formal'],
        'muellerhoff' => ['Herr Von Muellerhoff', 'HerrVonMuellerhoff-Regular.ttf', 'formal'],
        'doulaise' => ['Monsieur La Doulaise', 'MonsieurLaDoulaise-Regular.ttf', 'formal'],
        'rouge' => ['Rouge Script', 'RougeScript-Regular.ttf', 'formal'],
        'imperial' => ['Imperial Script', 'ImperialScript-Regular.ttf', 'formal'],
        'luxurious' => ['Luxurious Script', 'LuxuriousScript-Regular.ttf', 'formal'],
        'mea-culpa' => ['Mea Culpa', 'MeaCulpa-Regular.ttf', 'formal'],
        'cinzel' => ['Cinzel Decorative', 'CinzelDecorative-Regular.ttf', 'serif'],
        'playfair-sc' => ['Playfair Small Caps', 'PlayfairDisplaySC-Regular.ttf', 'serif'],
        'playfair-italic' => ['Playfair Italic', 'PlayfairDisplaySC-Italic.ttf', 'serif'],
        'marcellus' => ['Marcellus', 'Marcellus-Regular.ttf', 'serif'],
        'gilda' => ['Gilda Display', 'GildaDisplay-Regular.ttf', 'serif'],
        'italiana' => ['Italiana', 'Italiana-Regular.ttf', 'serif'],
        'forum' => ['Forum', 'Forum-Regular.ttf', 'serif'],
        'prata' => ['Prata', 'Prata-Regular.ttf', 'serif'],
        'cormorant' => ['Cormorant Upright', 'CormorantUpright-SemiBold.ttf', 'serif'],
        'abril' => ['Abril Fatface', 'AbrilFatface-Regular.ttf', 'display'],
        'yeseva' => ['Yeseva One', 'YesevaOne-Regular.ttf', 'display'],
        'poiret' => ['Poiret One', 'PoiretOne-Regular.ttf', 'display'],
        'limelight' => ['Limelight', 'Limelight-Regular.ttf', 'display'],
        'berkshire' => ['Berkshire Swash', 'BerkshireSwash-Regular.ttf', 'display'],
        'unifraktur' => ['Unifraktur Cook', 'UnifrakturCook-Bold.ttf', 'display'],
    ];

    public const GROUPS = ['script' => 'Flowing', 'formal' => 'Formal', 'serif' => 'Classic', 'display' => 'Bold'];

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::ALL[$key]);
    }

    /** @return list<array{key: string, label: string, file: string, group: string}> */
    public static function list(): array
    {
        $out = [];
        foreach (self::ALL as $key => [$label, $file, $group]) {
            $out[] = ['key' => $key, 'label' => $label, 'file' => $file, 'group' => $group];
        }

        return $out;
    }
}
