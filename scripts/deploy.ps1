<#
.SYNOPSIS
    Copies the local transport drop-in into a WordPress install.

.DESCRIPTION
    This repo holds the artifacts, not a WordPress tree. The drop-in has to live in
    wp-content/mu-plugins to take effect, so this script bridges the two.

.EXAMPLE
    .\scripts\deploy.ps1 -WordPressRoot /path/to/wordpress
#>
param(
    [Parameter(Mandatory = $true)]
    [string] $WordPressRoot
)

$ErrorActionPreference = 'Stop'

$source = Join-Path $PSScriptRoot '..\plugin\wso-local-webhook-transport.php'
$target = Join-Path $WordPressRoot 'wp-content\mu-plugins'

if (-not (Test-Path (Join-Path $WordPressRoot 'wp-load.php'))) {
    throw "Not a WordPress root (wp-load.php missing): $WordPressRoot"
}

if (-not (Test-Path $target)) {
    New-Item -ItemType Directory -Path $target -Force | Out-Null
}

Copy-Item $source $target -Force
Write-Host "Deployed to $(Join-Path $target 'wso-local-webhook-transport.php')"

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    & $php.Source -l (Join-Path $target 'wso-local-webhook-transport.php')
} else {
    Write-Host 'php not on PATH — syntax check skipped.'
}
