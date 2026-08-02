<#
    Deploy C:\AI_IMAGE  ->  Z:\ruslan\wordpress-test  (the testbed's compose directory).

    The plugin folder is MIRRORED, so files deleted here are deleted there.
    Infra files are copied only if newer, so a hand-edit on the server survives
    until you deliberately overwrite it.

    Usage:  powershell -File C:\AI_IMAGE\tools\sync.ps1
            powershell -File C:\AI_IMAGE\tools\sync.ps1 -InfraToo
#>

param(
    [switch]$InfraToo,
    [string]$Target = 'Z:\ruslan\wordpress-test'
)

$ErrorActionPreference = 'Stop'
$Source = 'C:\AI_IMAGE'

if (-not (Test-Path $Target)) {
    Write-Error "Target not reachable: $Target  (is the SMB share mounted?)"
}

# --- plugin ---------------------------------------------------------------
$pluginSrc = Join-Path $Source 'plugin\ai-cake-topper'
$pluginDst = Join-Path $Target 'plugins\ai-cake-topper'

if (Test-Path $pluginSrc) {
    New-Item -ItemType Directory -Force -Path $pluginDst | Out-Null
    # /MIR mirrors. Exit codes 0-7 are success for robocopy; 8+ is a real failure.
    #
    # /COPY:DT and /DCOPY:T copy data and timestamps but NOT attributes. The
    # target is a Samba share with no concept of Windows file attributes, so
    # the default /COPY:DAT fails with "ERROR 5 Access is denied" on every
    # directory and then sits in a 30-second retry loop.
    #
    # /R:1 /W:1 caps that retry loop at one second instead of thirty, so a
    # genuine permission problem surfaces immediately rather than looking
    # like a hang.
    robocopy $pluginSrc $pluginDst /MIR /COPY:DT /DCOPY:T /R:1 /W:1 `
        /NFL /NDL /NJH /NJS /NP /XD .git node_modules /XF *.log
    if ($LASTEXITCODE -ge 8) { Write-Error "robocopy failed ($LASTEXITCODE)" }
    Write-Host "plugin  -> $pluginDst" -ForegroundColor Green
} else {
    Write-Host "plugin  -- not built yet, skipped" -ForegroundColor DarkGray
}

# --- infra ----------------------------------------------------------------
# Off by default: changing these needs `docker compose up -d --build`, so it
# should be a deliberate act rather than a side effect of syncing code.
if ($InfraToo) {
    $infra = Join-Path $Source 'infra'

    Copy-Item (Join-Path $infra 'docker-compose.yaml') $Target -Force
    Copy-Item (Join-Path $infra 'Dockerfile')          $Target -Force

    foreach ($d in 'php', 'mu-plugins') {
        $dst = Join-Path $Target $d
        New-Item -ItemType Directory -Force -Path $dst | Out-Null
        Copy-Item (Join-Path $infra "$d\*") $dst -Recurse -Force
    }

    # .env is never overwritten - it holds real API keys.
    $envDst = Join-Path $Target '.env'
    if (-not (Test-Path $envDst)) {
        Copy-Item (Join-Path $infra '.env.example') $envDst
        Write-Host "infra   -> created .env from example - FILL IN THE KEYS" -ForegroundColor Yellow
    }

    New-Item -ItemType Directory -Force -Path (Join-Path $Target 'aicake-files') | Out-Null

    Write-Host "infra   -> $Target  (run: docker compose up -d --build)" -ForegroundColor Green
}

Write-Host "done." -ForegroundColor Cyan
