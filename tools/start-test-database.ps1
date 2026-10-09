$ErrorActionPreference = 'Stop'
$workspace = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$testRoot = Join-Path $workspace 'build'
$serverRoot = Join-Path $testRoot 'mariadb/mariadb-11.4.5-winx64'
$dataRoot = Join-Path $testRoot 'database-data'
$credentialFile = Join-Path $testRoot 'database-test.json'
$port = 13367
if (-not [System.IO.Path]::GetFullPath($dataRoot).StartsWith($testRoot + [System.IO.Path]::DirectorySeparatorChar)) { throw 'Unsafe test data path' }
if (-not (Test-Path -LiteralPath (Join-Path $serverRoot 'bin/mariadbd.exe'))) { throw 'Verified portable MariaDB 11.4.5 is required under build/mariadb.' }
if (-not (Test-Path -LiteralPath $credentialFile)) {
    if (Test-Path -LiteralPath $dataRoot) { throw 'Existing database directory without credential metadata; refusing to overwrite.' }
    $testPassword = [Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N')
    & (Join-Path $serverRoot 'bin/mariadb-install-db.exe') "--datadir=$dataRoot" "--password=$testPassword" "--port=$port" --silent *> (Join-Path $testRoot 'database-bootstrap.log')
    if ($LASTEXITCODE -ne 0) { throw 'Database initialization failed; inspect build/database-bootstrap.log.' }
    @{host='127.0.0.1'; port=$port; user='root'; password=$testPassword; database='formstudio_test'; prefix='nfs_'} | ConvertTo-Json | Set-Content -LiteralPath $credentialFile -Encoding utf8
}
$pidFile = Join-Path $testRoot 'database-process.json'
if (Test-Path -LiteralPath $pidFile) {
    $previous = Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json
    $running = Get-Process -Id $previous.pid -ErrorAction SilentlyContinue
    if ($running -and $running.Path -eq (Join-Path $serverRoot 'bin/mariadbd.exe')) { Write-Output "Test database already running on 127.0.0.1:$port"; exit 0 }
}
$serverArguments = @("--defaults-file=$dataRoot/my.ini", '--bind-address=127.0.0.1', "--port=$port", "--datadir=$dataRoot", "--log-error=$testRoot/database-server.log")
$process = Start-Process -FilePath (Join-Path $serverRoot 'bin/mariadbd.exe') -ArgumentList $serverArguments -WorkingDirectory $serverRoot -WindowStyle Hidden -PassThru
@{pid=$process.Id; executable=$process.Path; data=$dataRoot; port=$port} | ConvertTo-Json | Set-Content -LiteralPath $pidFile -Encoding utf8
Write-Output "Isolated test database started on 127.0.0.1:$port (PID $($process.Id))."
