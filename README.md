# AKINO

Local PHP prototype of an online cinema.

## Configuration

Copy `.env.example` to `.env` and set local credentials:

```ini
AKINO_DB_HOST=mysql-8.0
AKINO_DB_PORT=3306
AKINO_DB_DATABASE=akino_app
AKINO_DB_USERNAME=root
AKINO_DB_PASSWORD=
AKINO_DB_TIMEOUT=1
AKINO_DB_FALLBACK_HOSTS=

AKINO_ADMIN_LOGIN=akino_admin
AKINO_ADMIN_PASSWORD=...
AKINO_DEMO_AUTH=1
```

`config/database.local.php` and `config/admin.local.php` are also supported and ignored by git.
For OpenServer, the local MySQL module commonly works through `mysql-8.0` with `root` and an empty password. `AKINO_DB_FALLBACK_HOSTS` is intentionally empty by default so page loads do not wait on unreachable fallback addresses.

## Database Setup

Run the explicit setup script instead of relying on page loads to mutate the database:

```powershell
C:\OSPanel\modules\PHP-8.0\php.exe tools\setup_database.php
```

Useful options:

```powershell
C:\OSPanel\modules\PHP-8.0\php.exe tools\setup_database.php --skip-schema
C:\OSPanel\modules\PHP-8.0\php.exe tools\setup_database.php --skip-seed
```

The schema lives in `database/akino.sql`. Demo movie, season, episode and admin seed data is only loaded when `AKINO_RUNTIME_BOOTSTRAP=1`, which the setup script enables for its own run.

## Tests

```powershell
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -SkipVisual
```

For a real database run:

```powershell
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -StrictDb -SkipVisual
```
