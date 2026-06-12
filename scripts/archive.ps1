#Requires -Version 5.1
<#
.SYNOPSIS
    Packages the plugin into a versioned zip inside dist/.

.DESCRIPTION
    Reads the plugin version from src/bu-qr-generator.php, copies the entire
    src/ folder into a staging folder renamed to bu-qr-generator/, then zips
    it to dist/bu-qr-generator-<version>.zip.

    Exits with a non-zero code if the zip for that version already exists.

.EXAMPLE
    .\scripts\archive.ps1
#>

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ── Paths ──────────────────────────────────────────────────────────────────

$root       = Resolve-Path (Join-Path $PSScriptRoot '..')
$srcDir     = Join-Path $root 'src'
$pluginFile = Join-Path $srcDir 'bu-qr-generator.php'
$distDir    = Join-Path $root 'dist'
$pluginSlug = 'bu-qr-generator'

if (-not (Test-Path $srcDir)) {
    Write-Error "src/ folder not found at: $srcDir"
    exit 1
}

# ── Read version from plugin header ────────────────────────────────────────

$headerContent = Get-Content $pluginFile -TotalCount 20 -Raw

if ($headerContent -notmatch '(?m)^\s*\*\s*Version:\s*(.+?)\s*$') {
    Write-Error "Could not find 'Version:' header in $pluginFile"
    exit 1
}

$version = $Matches[1].Trim()

Write-Host "Plugin version : $version"

# ── Check for existing archive ─────────────────────────────────────────────

$zipName = "$pluginSlug-$version.zip"
$zipPath = Join-Path $distDir $zipName

if (Test-Path $zipPath) {
    Write-Error "Archive already exists for v$version : $zipPath`nBump the version in src/bu-qr-generator.php before archiving."
    exit 1
}

# ── Ensure dist/ exists ────────────────────────────────────────────────────

if (-not (Test-Path $distDir)) {
    New-Item -ItemType Directory -Path $distDir | Out-Null
}

# ── Staging area ───────────────────────────────────────────────────────────
# src/ is copied into staging as bu-qr-generator/ so the zip contains
# bu-qr-generator/<files> (WordPress expects the folder to match the slug).

$stagingRoot   = Join-Path ([System.IO.Path]::GetTempPath()) "buqr-staging-$version"
$stagingPlugin = Join-Path $stagingRoot $pluginSlug

if (Test-Path $stagingRoot) {
    Remove-Item $stagingRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $stagingPlugin | Out-Null

# ── Files to exclude from the archive ──────────────────────────────────────

$excludedFiles = @('.gitignore', '.gitattributes', '.editorconfig', 'package-lock.json', 'yarn.lock')
$excludedExts  = @('.log', '.map')

# ── Copy src/ → staging/bu-qr-generator/ ───────────────────────────────────

Get-ChildItem -Path $srcDir -Recurse -Force | Where-Object {
    if ($_.PSIsContainer)                          { return $false }
    if ($excludedFiles -contains $_.Name)          { return $false }
    if ($excludedExts  -contains $_.Extension)     { return $false }
    return $true
} | ForEach-Object {
    # Preserve sub-folder structure relative to src/
    $relativePath = $_.FullName.Substring($srcDir.Path.Length).TrimStart('\', '/')
    $destination  = Join-Path $stagingPlugin $relativePath
    $destFolder   = Split-Path $destination -Parent

    if (-not (Test-Path $destFolder)) {
        New-Item -ItemType Directory -Path $destFolder -Force | Out-Null
    }

    Copy-Item -Path $_.FullName -Destination $destination
}

# ── Create zip ─────────────────────────────────────────────────────────────

Write-Host "Creating       : $zipPath"

Compress-Archive -Path $stagingPlugin -DestinationPath $zipPath

# ── Cleanup staging ────────────────────────────────────────────────────────

Remove-Item $stagingRoot -Recurse -Force

# ── Done ───────────────────────────────────────────────────────────────────

$size = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
Write-Host "Done           : $zipName  ($size KB)" -ForegroundColor Green
