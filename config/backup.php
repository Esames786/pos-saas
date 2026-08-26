<?php

return [

    // Filesystem disk used to store backups (local recommended for now).
    'disk' => env('BACKUP_DISK', 'local'),

    // Path within the disk root. On the default Laravel 12 'local' disk the root
    // is storage/app/private, so backups land in storage/app/private/backups.
    'path' => env('BACKUP_PATH', 'backups'),

    // External binaries. Override with absolute paths if not on PATH
    // (e.g. Windows/Laragon: D:/laragon2/bin/mysql/.../bin/mysqldump.exe).
    'mysql_dump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'mysql_binary'      => env('MYSQL_BINARY', 'mysql'),
    'gzip_binary'       => env('GZIP_BINARY', 'gzip'),

    // Delete backups older than this many days when --prune is passed.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    // Include a storage/app archive (uploads such as payment proofs).
    'include_storage' => (bool) env('BACKUP_INCLUDE_STORAGE', true),

    // PROD-READINESS-1: enables the nightly 02:00 `tenants:backup --prune`
    // schedule. Set true ONLY on the production server (read via config() so
    // it stays config-cache safe). Also gates the per-tenant `tenants:auto-backup`.
    'schedule_enabled' => (bool) env('BACKUP_SCHEDULE_ENABLED', false),

    // TENANT-AUTO-BACKUP-1: minutes after a configured slot within which the dispatcher may still
    // fire that slot (so a skipped every-few-minutes run does not miss it). The unique slot claim
    // keeps it once-per-day regardless.
    'auto_grace_minutes' => (int) env('BACKUP_AUTO_GRACE_MINUTES', 15),

];
