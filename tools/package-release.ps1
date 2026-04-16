[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$pluginSlug = 'image2url-clipboard-booster'
$distDir = Join-Path $repoRoot 'dist'
$stageDir = Join-Path $distDir $pluginSlug
$zipPath = Join-Path $distDir ($pluginSlug + '.zip')

if (Test-Path -LiteralPath $stageDir) {
    Remove-Item -LiteralPath $stageDir -Recurse -Force
}

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

New-Item -ItemType Directory -Path $stageDir -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $stageDir 'assets') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $stageDir 'assets\\js') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $stageDir 'includes') -Force | Out-Null

$rootFiles = @(
    'image2url-clipboard-booster.php',
    'readme.txt',
    'uninstall.php'
)

foreach ($file in $rootFiles) {
    Copy-Item -LiteralPath (Join-Path $repoRoot $file) -Destination (Join-Path $stageDir $file) -Force
}

Copy-Item -LiteralPath (Join-Path $repoRoot 'assets\\js\\admin-settings.js') -Destination (Join-Path $stageDir 'assets\\js\\admin-settings.js') -Force
Copy-Item -LiteralPath (Join-Path $repoRoot 'assets\\js\\editor-paste.js') -Destination (Join-Path $stageDir 'assets\\js\\editor-paste.js') -Force
Copy-Item -LiteralPath (Join-Path $repoRoot 'assets\\js\\migration-jobs.js') -Destination (Join-Path $stageDir 'assets\\js\\migration-jobs.js') -Force

Get-ChildItem -LiteralPath (Join-Path $repoRoot 'includes') -Filter '*.php' | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $stageDir 'includes') -Force
}

Compress-Archive -LiteralPath $stageDir -DestinationPath $zipPath -Force

Write-Output ('Created release package: ' + $zipPath)
