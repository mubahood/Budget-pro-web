<?php

/*
| Akiba Ledger P2.1 (AK-51): public holidays per country, for working days (the loan cooling-off, SI 108 reg. 11).
|
| `fixed`: [month, day, name], every year. `easter`: [days from Easter Sunday, name] (Good Friday, Easter Monday).
| `movable`: per year, [Y-m-d, name, estimate]. The two Eids follow the moon and are announced shortly before; the
| dates here are the expected ones, marked estimates until the platform admin confirms the gazetted day with
| `php artisan sacco:holidays --set=YYYY-MM-DD:"Name"` (PublicHolidays::set).
*/
return [
    'UG' => [
        'fixed' => [
            [1, 1, "New Year's Day"],
            [1, 26, 'Liberation Day'],
            [2, 16, 'Archbishop Janani Luwum Day'],
            [3, 8, "International Women's Day"],
            [5, 1, 'Labour Day'],
            [6, 3, "Martyrs' Day"],
            [6, 9, 'National Heroes Day'],
            [10, 9, 'Independence Day'],
            [12, 25, 'Christmas Day'],
            [12, 26, 'Boxing Day'],
        ],
        'easter' => [
            [-2, 'Good Friday'],
            [1, 'Easter Monday'],
        ],
        'movable' => [
            2024 => [['2024-04-10', 'Eid al-Fitr', false], ['2024-06-17', 'Eid al-Adha', false]],
            2025 => [['2025-03-31', 'Eid al-Fitr', false], ['2025-06-06', 'Eid al-Adha', false]],
            2026 => [['2026-03-20', 'Eid al-Fitr', true], ['2026-05-27', 'Eid al-Adha', true]],
            2027 => [['2027-03-10', 'Eid al-Fitr', true], ['2027-05-17', 'Eid al-Adha', true]],
            2028 => [['2028-02-27', 'Eid al-Fitr', true], ['2028-05-05', 'Eid al-Adha', true]],
            2029 => [['2029-02-15', 'Eid al-Fitr', true], ['2029-04-24', 'Eid al-Adha', true]],
            2030 => [['2030-02-05', 'Eid al-Fitr', true], ['2030-04-13', 'Eid al-Adha', true]],
        ],
    ],
];
