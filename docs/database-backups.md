# Database backup safety

SanIE database backups contain private financial and account data. They must
never be committed, attached to an issue, placed in `frontend/`, or stored in a
web-served directory.

## Backup location and naming

For Laragon development, use a directory outside `C:\laragon\www`, for example:

```text
C:\laragon\data\sanie-backups\database\
```

Set `SANIE_BACKUP_DIR` to that directory in the terminal used for backup work.
Use names in this form, without usernames, passwords, or host identifiers:

```text
sanie-db-YYYYMMDD-HHMMSS.sql
```

Existing local dumps are legacy ownership cases. Do not relocate or delete
them until their owner is confirmed. Migration tests use the sanitized,
DDL-only fixture at `backend/database/fixtures/august-2026-baseline-schema.sql`
instead of publishing a dump containing user rows or password hashes.

## Create a backup

In PowerShell, load the database connection values into environment variables,
then let MySQL prompt for the password. Do not put the password in the command
or filename.

```powershell
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupPath = Join-Path $env:SANIE_BACKUP_DIR "sanie-db-$timestamp.sql"
mysqldump.exe --host=$env:DB_HOST --user=$env:DB_USERNAME --password --single-transaction --routines --triggers --default-character-set=utf8mb4 --result-file=$backupPath $env:DB_DATABASE
```

Confirm the command succeeded and restrict filesystem access to the intended
operator. Encrypt backups before copying them to another device or service.

## Restore a backup

Restore only into the intended database after taking a current backup. The
following prompts for the database password and avoids embedding it in shell
history:

```powershell
mysql.exe --host=$env:DB_HOST --user=$env:DB_USERNAME --password --default-character-set=utf8mb4 --execute="source C:/laragon/data/sanie-backups/database/sanie-db-YYYYMMDD-HHMMSS.sql" $env:DB_DATABASE
```

After restoring, run the normal migration command and regression checks:

```powershell
php backend/database/migrate.php
php backend/tests/Phase2SchemaVerificationTest.php
```

## Retention

Until an approved automated policy exists, review backups manually and retain:

- 7 daily backups
- 4 weekly backups
- 3 monthly backups

Deletion must be owner-approved. Never run cleanup against an unresolved path,
and never delete the only verified restore point.

## Source-control recovery

Generated backups and SQL files in `backend/backups/` are ignored. Canonical
`backend/database/schema.sql`, migrations, seeds, and the sanitized DDL-only
baseline fixture remain visible to source control. If a private
dump was already committed, repair the Git repository first, confirm the file's
owner, then remove it from tracking and assess whether repository history and
database credentials require remediation. Do not use destructive Git cleanup.
