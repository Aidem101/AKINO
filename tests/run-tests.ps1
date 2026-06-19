param(
    [string] $Php = "C:\OSPanel\modules\PHP-8.0\php.exe",
    [string] $Chrome = "C:\Program Files\Google\Chrome\Application\chrome.exe",
    [int] $Port = 8142,
    [string] $HttpBaseUrl = "",
    [string] $VisualBaseUrl = "",
    [int] $ChromeTimeoutMilliseconds = 12000,
    [switch] $SkipVisual,
    [switch] $StrictVisual,
    [switch] $StrictDb
)

$ErrorActionPreference = "Stop"
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$ArtifactRoot = Join-Path $ProjectRoot "tests\artifacts"
$RunId = Get-Date -Format "yyyyMMdd-HHmmss"
$RunDir = Join-Path $ArtifactRoot $RunId
$SessionDir = Join-Path $RunDir "session"
$ServerOut = Join-Path $RunDir "php-server.out.log"
$ServerErr = Join-Path $RunDir "php-server.err.log"
$OwnHttpServer = $HttpBaseUrl -eq ""
$BaseUrl = if ($OwnHttpServer) { "http://127.0.0.1:$Port" } else { $HttpBaseUrl.TrimEnd("/") }

if ($VisualBaseUrl -eq "") {
    $VisualBaseUrl = $BaseUrl
}

$VisualBaseUrl = $VisualBaseUrl.TrimEnd("/")
$PreviousFallbackEnv = $env:AKINO_FORCE_DB_FALLBACK

trap {
    $capturedError = $_

    if ($null -eq $PreviousFallbackEnv) {
        Remove-Item Env:\AKINO_FORCE_DB_FALLBACK -ErrorAction SilentlyContinue
    } else {
        $env:AKINO_FORCE_DB_FALLBACK = $PreviousFallbackEnv
    }

    throw $capturedError
}

New-Item -ItemType Directory -Force -Path $RunDir | Out-Null
New-Item -ItemType Directory -Force -Path $SessionDir | Out-Null

function Write-Step([string] $Message) {
    Write-Host ""
    Write-Host "== $Message =="
}

function Assert-File([string] $Path, [string] $Name) {
    if (-not (Test-Path $Path)) {
        throw "$Name not found: $Path"
    }
}

function Invoke-HttpCheck([string] $Path, [string] $Name) {
    $url = "$BaseUrl/$Path"
    $response = Invoke-WebRequest -Uri $url -UseBasicParsing -MaximumRedirection 5 -TimeoutSec 20

    if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 400) {
        throw "$Name returned HTTP $($response.StatusCode): $url"
    }

    $body = [string] $response.Content
    $badMarkers = @("Fatal error", "Parse error", "Warning:", "Deprecated:", "Stack trace")

    foreach ($marker in $badMarkers) {
        if ($body.Contains($marker)) {
            throw "$Name contains PHP marker '$marker': $url"
        }
    }

    Write-Host "OK HTTP $($response.StatusCode) $Name"
}

function Assert-HttpStatus(
    [scriptblock] $Request,
    [int] $ExpectedStatus,
    [string] $Name
) {
    try {
        $response = & $Request
        $actualStatus = [int] $response.StatusCode
    } catch {
        $errorResponse = $_.Exception.Response

        if ($null -eq $errorResponse) {
            throw
        }

        $actualStatus = [int] $errorResponse.StatusCode
    }

    if ($actualStatus -ne $ExpectedStatus) {
        throw "$Name returned HTTP $actualStatus, expected $ExpectedStatus"
    }

    Write-Host "OK HTTP $actualStatus $Name"
}

function Invoke-SecurityHttpChecks {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $homeResponse = Invoke-WebRequest -Uri "$BaseUrl/Home.php" -UseBasicParsing -WebSession $session -TimeoutSec 20

    foreach ($header in @{
        "X-Content-Type-Options" = "nosniff"
        "X-Frame-Options" = "DENY"
        "Referrer-Policy" = "no-referrer"
    }.GetEnumerator()) {
        if ([string] $homeResponse.Headers[$header.Key] -ne $header.Value) {
            throw "Security header $($header.Key) is missing or invalid"
        }
    }

    $contentSecurityPolicy = [string] $homeResponse.Headers["Content-Security-Policy"]

    foreach ($directive in @("default-src 'self'", "script-src 'self' 'nonce-", "script-src-attr 'none'", "style-src-attr 'none'")) {
        if (-not $contentSecurityPolicy.Contains($directive)) {
            throw "Content-Security-Policy is missing directive: $directive"
        }
    }

    if ($contentSecurityPolicy.Contains("'unsafe-inline'")) {
        throw "Content-Security-Policy still allows unsafe-inline"
    }

    if ($null -ne $homeResponse.Headers["X-Powered-By"]) {
        throw "X-Powered-By header discloses the PHP version"
    }

    if (-not [string]::IsNullOrWhiteSpace([string] $homeResponse.Headers["Server"])) {
        Write-Host "INFO Server header is controlled by the web server: $($homeResponse.Headers["Server"])"
    }

    if (-not ([string] $homeResponse.Content).Contains("integrity=`"sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0`"")) {
        throw "Font Awesome stylesheet is missing its integrity attribute"
    }

    $faviconResponse = Invoke-WebRequest -Uri "$BaseUrl/favicon.svg" -UseBasicParsing -WebSession $session -TimeoutSec 20

    if ([string] $faviconResponse.Headers["X-Content-Type-Options"] -ne "nosniff") {
        throw "Static assets are missing X-Content-Type-Options"
    }

    $xssPayload = 'series"><svg/onload=alert(document.domain)>'
    $xssUrl = "$BaseUrl/Catalog.php?type=$([Uri]::EscapeDataString($xssPayload))"
    $xssResponse = Invoke-WebRequest -Uri $xssUrl -UseBasicParsing -WebSession $session -TimeoutSec 20

    foreach ($marker in @($xssPayload, "<svg/onload", "<script>alert")) {
        if ([string] $xssResponse.Content -like "*$marker*") {
            throw "Catalog reflected a potentially executable XSS payload"
        }
    }

    $csrfMatch = [regex]::Match(
        [string] $homeResponse.Content,
        '<meta\s+name="csrf-token"\s+content="([^"]+)"'
    )

    if (-not $csrfMatch.Success) {
        throw "CSRF token meta tag was not found on Home"
    }

    $csrfToken = $csrfMatch.Groups[1].Value
    $postHeaders = @{
        "X-Requested-With" = "XMLHttpRequest"
    }

    Assert-HttpStatus -ExpectedStatus 403 -Name "API rejects missing CSRF token" -Request {
        Invoke-WebRequest `
            -Uri "$BaseUrl/api/request-code.php" `
            -UseBasicParsing `
            -WebSession $session `
            -Method Post `
            -Headers $postHeaders `
            -Body @{ phone = "invalid" } `
            -TimeoutSec 20
    }

    $postHeaders["X-CSRF-Token"] = $csrfToken

    Assert-HttpStatus -ExpectedStatus 422 -Name "API accepts valid CSRF token" -Request {
        Invoke-WebRequest `
            -Uri "$BaseUrl/api/request-code.php" `
            -UseBasicParsing `
            -WebSession $session `
            -Method Post `
            -Headers $postHeaders `
            -Body @{ phone = "invalid" } `
            -TimeoutSec 20
    }
}

function Save-PhpServerLogs($ServerProcess, [string] $StdoutPath, [string] $StderrPath) {
    if ($null -eq $ServerProcess) {
        return
    }

    try {
        $stdout = $ServerProcess.StandardOutput.ReadToEnd()
        $stderr = $ServerProcess.StandardError.ReadToEnd()
        Set-Content -Path $StdoutPath -Value $stdout
        Set-Content -Path $StderrPath -Value $stderr
    } catch {
        # Server log collection is best-effort and must not hide the original test failure.
    }
}

function Invoke-ChromeScreenshot([string] $ChromePath, [array] $Arguments, [int] $TimeoutMilliseconds = 45000) {
    & $ChromePath @Arguments | Out-Null

    if ($LASTEXITCODE -ne 0) {
        throw "Chrome exited with code $LASTEXITCODE."
    }
}

Assert-File $Php "PHP"

if ($StrictDb) {
    Remove-Item Env:\AKINO_FORCE_DB_FALLBACK -ErrorAction SilentlyContinue
} else {
    $env:AKINO_FORCE_DB_FALLBACK = "1"
}

Write-Step "PHP syntax"
$phpFiles = Get-ChildItem -Path (Join-Path $ProjectRoot "public"), (Join-Path $ProjectRoot "src"), (Join-Path $ProjectRoot "config") -Recurse -Filter "*.php"

foreach ($file in $phpFiles) {
    & $Php -l $file.FullName | Out-Host

    if ($LASTEXITCODE -ne 0) {
        throw "PHP lint failed: $($file.FullName)"
    }
}

Write-Step "Functional smoke tests"
$smokeArgs = @((Join-Path $ProjectRoot "tests\smoke.php"))
$smokeArgs = @("-d", "session.save_path=$SessionDir") + $smokeArgs

if (-not $StrictDb) {
    $smokeArgs += "--allow-skip-db"
}

& $Php $smokeArgs | Tee-Object -FilePath (Join-Path $RunDir "smoke.log")

if ($LASTEXITCODE -ne 0) {
    throw "Functional smoke tests failed"
}

$server = $null

try {
    if ($OwnHttpServer) {
        Write-Step "Start PHP HTTP server"
        $serverArguments = '-d "session.save_path=' + $SessionDir + '" -S 127.0.0.1:' + $Port + ' -t "' + (Join-Path $ProjectRoot "public") + '" "' + (Join-Path $ProjectRoot "tests\zap\router.php") + '"'
        $serverStartInfo = New-Object System.Diagnostics.ProcessStartInfo
        $serverStartInfo.FileName = $Php
        $serverStartInfo.Arguments = $serverArguments
        $serverStartInfo.WorkingDirectory = $ProjectRoot
        $serverStartInfo.UseShellExecute = $false
        $serverStartInfo.RedirectStandardOutput = $true
        $serverStartInfo.RedirectStandardError = $true
        $serverStartInfo.CreateNoWindow = $true
        $server = [System.Diagnostics.Process]::Start($serverStartInfo)

        $ready = $false

        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Milliseconds 300

            try {
                $response = Invoke-WebRequest -Uri "$BaseUrl/Home.php" -UseBasicParsing -MaximumRedirection 5 -TimeoutSec 3

                if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) {
                    $ready = $true
                    break
                }
            } catch {
                if ($server.HasExited) {
                    throw "PHP server exited early. See $ServerErr"
                }
            }
        }

        if (-not $ready) {
            throw "PHP server did not become ready on $BaseUrl"
        }
    } else {
        Write-Step "Use external HTTP server"
        Write-Host "Base URL: $BaseUrl"
    }

    Write-Step "HTTP smoke checks"
    $pages = @(
        @{ Path = "Home.php"; Name = "Home" },
        @{ Path = "Films_Catalog.php"; Name = "Films catalog" },
        @{ Path = "Series_Page.php"; Name = "Series catalog" },
        @{ Path = "Catalog.php?q=%D0%A5%D0%BE%D0%B1"; Name = "Search results" },
        @{ Path = "api/search-suggestions.php?q=%D0%A5%D0%BE%D0%B1"; Name = "Search suggestions API" },
        @{ Path = "Films_Catalog.php?director=Smoke"; Name = "Films director filter" },
        @{ Path = "Film_Page.php?id=1"; Name = "Film page" },
        @{ Path = "Watch.php?id=1"; Name = "Watch page" },
        @{ Path = "Admin_Login.php"; Name = "Admin login" }
    )

    foreach ($page in $pages) {
        Invoke-HttpCheck -Path $page.Path -Name $page.Name
    }

    Write-Step "HTTP security checks"
    Invoke-SecurityHttpChecks

    if (-not $SkipVisual) {
        Assert-File $Chrome "Chrome"
        Write-Step "Visual smoke screenshots"
        $screenshotsDir = Join-Path $RunDir "screenshots"
        New-Item -ItemType Directory -Force -Path $screenshotsDir | Out-Null

        $visualPages = @(
            @{ Path = "Home.php"; Name = "home" },
            @{ Path = "Films_Catalog.php"; Name = "films-catalog" },
            @{ Path = "Series_Page.php"; Name = "series-catalog" },
            @{ Path = "Film_Page.php?id=1"; Name = "film-page" },
            @{ Path = "Watch.php?id=1"; Name = "watch" },
            @{ Path = "Admin_Login.php"; Name = "admin-login" }
        )

        foreach ($page in $visualPages) {
            $screenshot = Join-Path $screenshotsDir ($page.Name + ".png")
            $url = "$VisualBaseUrl/$($page.Path)"
            $chromeArgs = @(
                "--headless=new",
                "--disable-gpu",
                "--hide-scrollbars",
                "--window-size=1440,1000",
                "--screenshot=$screenshot",
                $url
            )

            try {
                Invoke-ChromeScreenshot -ChromePath $Chrome -Arguments $chromeArgs -TimeoutMilliseconds $ChromeTimeoutMilliseconds
            } catch {
                if ($StrictVisual) {
                    throw
                }

                Write-Warning "Visual smoke skipped: Chrome could not run in this environment. $($_.Exception.Message)"
                break
            }

            $shot = Get-Item $screenshot

            if ($shot.Length -lt 10000) {
                throw "Screenshot looks empty: $screenshot"
            }

            Write-Host "OK screenshot $($page.Name): $screenshot"
        }
    }
} finally {
    if ($server -and -not $server.HasExited) {
        $server.Kill()
        $server.WaitForExit()
    }

    Save-PhpServerLogs $server $ServerOut $ServerErr
}

Write-Step "Done"
Write-Host "Artifacts: $RunDir"

if ($null -eq $PreviousFallbackEnv) {
    Remove-Item Env:\AKINO_FORCE_DB_FALLBACK -ErrorAction SilentlyContinue
} else {
    $env:AKINO_FORCE_DB_FALLBACK = $PreviousFallbackEnv
}
