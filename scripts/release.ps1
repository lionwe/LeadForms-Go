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

$tar = Get-Command tar.exe -ErrorAction Stop
& $tar.Source -a -cf $archive -C $build 'leadforms-go'
if ($LASTEXITCODE -ne 0) {
	throw 'Release archive generation failed.'
}
Write-Output "Release archive: $archive"
