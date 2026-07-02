$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$build = Join-Path $root 'build'
$source = Join-Path $build 'leadforms-go'
$archive = Join-Path $build 'leadforms-go.zip'

if (-not (Test-Path -LiteralPath $source)) {
    throw 'Release directory was not generated.'
}

if (Test-Path -LiteralPath $archive) {
    Remove-Item -LiteralPath $archive -Force
}

Compress-Archive -LiteralPath $source -DestinationPath $archive -CompressionLevel Optimal
Write-Output "Release archive: $archive"
