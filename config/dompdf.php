<?php

/*
| dompdf's own defaults (its package's config file, read so an upgrade carries through), with one change: fonts are
| subset, so a PDF holds the letters it uses. A one-page document drops from about 880 KB to tens of KB, in every app on
| this code; the pages themselves look the same.
*/
$config = require base_path('vendor/barryvdh/laravel-dompdf/config/dompdf.php');
$config['options']['enable_font_subsetting'] = true;

return $config;
