# Install the `eleph` command line on Windows, without PHP, Composer or cargo.
#
#   irm https://github.com/hsimah-services/elephentity/releases/latest/download/install.ps1 | iex
#
# Downloads the win-x64 release archive, checks it against the release's SHA256SUMS, and puts
# eleph.exe in %LOCALAPPDATA%\eleph\bin. Then `eleph install` fetches the generator and the builders
# a project pins.
#
#   $env:ELEPH_VERSION       the release to install (1.2.3 or v1.2.3); the latest when unset
#   $env:ELEPH_INSTALL_DIR   where to put it
#   $env:ELEPH_RELEASES_URL  where releases are downloaded from (default https://github.com)

$ErrorActionPreference = 'Stop'

$repository = 'hsimah-services/elephentity'
$base = if ($env:ELEPH_RELEASES_URL) { $env:ELEPH_RELEASES_URL.TrimEnd('/') } else { 'https://github.com' }
$directory = if ($env:ELEPH_INSTALL_DIR) { $env:ELEPH_INSTALL_DIR } else { Join-Path $env:LOCALAPPDATA 'eleph\bin' }
$version = if ($env:ELEPH_VERSION) { $env:ELEPH_VERSION.TrimStart('v') } else { '' }

if ($env:PROCESSOR_ARCHITECTURE -ne 'AMD64') {
    throw "There is no prebuilt eleph for $($env:PROCESSOR_ARCHITECTURE). Releases are built for win-x64, linux-x64, linux-arm64 and osx-arm64."
}

$rid = 'win-x64'

if (-not $version) {
    # /releases/latest redirects to /releases/tag/v<version>; the redirect is the answer.
    $request = [System.Net.HttpWebRequest]::Create("$base/$repository/releases/latest")
    $request.AllowAutoRedirect = $false
    $response = $request.GetResponse()
    $location = $response.Headers['Location']
    $response.Close()

    if (-not $location) { throw "Could not find the latest release of $repository." }

    $version = ($location -split '/')[-1].TrimStart('v')
}

$archive = "eleph-$version-$rid.zip"
$release = "$base/$repository/releases/download/v$version"
$work = Join-Path ([System.IO.Path]::GetTempPath()) ("eleph-" + [System.Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $work | Out-Null

try {
    Write-Host "Installing eleph $version ($rid)"

    Invoke-WebRequest -Uri "$release/$archive" -OutFile (Join-Path $work $archive) -UseBasicParsing
    Invoke-WebRequest -Uri "$release/SHA256SUMS" -OutFile (Join-Path $work 'SHA256SUMS') -UseBasicParsing

    $expected = $null
    foreach ($line in Get-Content (Join-Path $work 'SHA256SUMS')) {
        $parts = $line -split '\s+', 2
        if ($parts.Count -eq 2 -and $parts[1].TrimStart('*') -eq $archive) { $expected = $parts[0].ToLowerInvariant() }
    }

    if (-not $expected) { throw "SHA256SUMS has no line for $archive." }

    $actual = (Get-FileHash -Algorithm SHA256 -Path (Join-Path $work $archive)).Hash.ToLowerInvariant()

    if ($expected -ne $actual) {
        throw "$archive does not match SHA256SUMS (expected $expected, got $actual). Nothing was installed."
    }

    Expand-Archive -Path (Join-Path $work $archive) -DestinationPath $work -Force

    if (-not (Test-Path (Join-Path $work 'eleph.exe'))) { throw "$archive has no eleph.exe in it." }

    New-Item -ItemType Directory -Force -Path $directory | Out-Null
    Copy-Item -Path (Join-Path $work 'eleph.exe') -Destination (Join-Path $directory 'eleph.exe') -Force

    Write-Host "Installed $(Join-Path $directory 'eleph.exe')"

    if (($env:PATH -split ';') -notcontains $directory) {
        Write-Host "Add it to your PATH:  setx PATH `"$directory;%PATH%`""
    }
}
finally {
    Remove-Item -Recurse -Force $work -ErrorAction SilentlyContinue
}
