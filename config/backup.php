<?php

/*
| Nightly database backups and the restore drill (plan Part D, P4-6).
*/
return [
    'path' => env('BACKUP_PATH', storage_path('app/backups')),
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),
    // An empty scratch database the drill may overwrite. Unset = the drill checks the dump file itself.
    'drill_database' => env('BACKUP_DRILL_DATABASE'),
    'exports_path' => storage_path('app/exports'),
    'deletion_grace_days' => (int) env('TENANT_DELETION_GRACE_DAYS', 30),
];
