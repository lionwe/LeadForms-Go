$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$build = Join-Path $root 'build'
$source = Join-Path $build 'leadforms-go'
$archive = Join-Path $build 'leadforms-go.zip'
$package = Get-Content -LiteralPath (Join-Path $root 'package.json') -Raw | ConvertFrom-Json
$versionedArchive = Join-Path $build ("leadforms-go-{0}.zip" -f $package.version)

if (-not (Test-Path -LiteralPath $source)) {
    throw 'Release directory was not generated.'
}

if (Test-Path -LiteralPath $archive) {
    Remove-Item -LiteralPath $archive -Force
}

if (Test-Path -LiteralPath $versionedArchive) {
    Remove-Item -LiteralPath $versionedArchive -Force
}

$tar = Get-Command tar.exe -ErrorAction Stop
& $tar.Source -a -cf $archive -C $build 'leadforms-go'
if ($LASTEXITCODE -ne 0) {
	throw 'Release archive generation failed.'
}

$entries = @(& $tar.Source -tf $archive)
if ($LASTEXITCODE -ne 0 -or $entries.Count -eq 0) {
	throw 'Release archive validation failed.'
}

$invalidEntries = @($entries | Where-Object { $_ -match '\\' -or ($_ -ne 'leadforms-go/' -and -not $_.StartsWith('leadforms-go/')) })
if ($invalidEntries.Count -gt 0) {
	throw "Release archive contains non-portable paths: $($invalidEntries -join ', ')"
}

if ($entries -notcontains 'leadforms-go/leadforms-go.php') {
	throw 'Release archive does not contain the plugin entry file.'
}

Copy-Item -LiteralPath $archive -Destination $versionedArchive
Write-Output "Release archives: $archive, $versionedArchive"
