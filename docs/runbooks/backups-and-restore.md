# Runbook — backups and restore

- **Nightly** `ops backup` (02:00) writes `storage/app/backups/<db>-<date>.sql.gz`, verifies the gzip and
  keeps `BACKUP_KEEP_DAYS` days. **Weekly** `ops drill` restores the latest file into `BACKUP_DRILL_DATABASE`
  (an empty scratch database) and compares, or — without one — proves the dump holds every table and its rows.
  Results show on System health; failures are logged at error level.
- **Before every deploy** also take the manual dump from the deploy protocol (`mysqldump | gzip` + `gzip -t`).
- **Restore:** stop the scheduler cron line; `gunzip -c backup.sql.gz | mysql <db>`; `php artisan migrate --force`
  (in case the backup predates a migration); clear caches; run the smoke checks; re-enable cron.
- **One shop only:** use the shop's own export (Your data → Download) or restore into a scratch database and
  copy that company's rows.
