# AKINO test runner

Run the full local check from the project root:

```powershell
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1
```

What it checks:

- PHP syntax for `public`, `src`, and `config`.
- Functional smoke tests in `tests\smoke.php`.
- HTTP smoke checks with the PHP built-in server.
- Headless Chrome screenshots for the main public pages and admin login.

Artifacts are saved to `tests\artifacts\<run-id>`.

The runner uses `C:\OSPanel\modules\PHP-8.0\php.exe` by default because `.osp\project.ini` runs this project on `PHP-8.0`.

By default, if MySQL/OpenServer is not running, the runner forces AKINO fallback data so HTTP and visual checks still run quickly. To require the real database functional tests, start OpenServer/MySQL first and run:

```powershell
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -StrictDb
```

Useful options:

```powershell
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -SkipVisual
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -StrictVisual
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -StrictDb -StrictVisual -VisualBaseUrl http://project
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -StrictDb -StrictVisual -HttpBaseUrl http://project -VisualBaseUrl http://project
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -Port 8140
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1 -ChromeTimeoutMilliseconds 30000
```

OpenServer note:

If projects only open when OpenServer is started as administrator, check `C:\OSPanel\logs\general.log`. In this workspace the log showed access denied for `C:\Windows\System32\drivers\etc\hosts`, so OpenServer cannot update local domains without elevated rights. The test runner avoids that by using PHP's built-in server on `127.0.0.1:<port>` for smoke checks.

Headless Chrome may also be blocked by restricted shells. In the default mode the runner warns and continues if Chrome cannot start. Use `-StrictVisual` when running from a normal user shell where Chrome is allowed and screenshots must be created.
