$ErrorActionPreference = 'Stop'
$workspace = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$testRoot = Join-Path $workspace 'build'
$serverRoot = Join-Path $testRoot 'mysql/mysql-8.4.8-winx64'
$dataRoot = Join-Path $testRoot 'mysql-data'
$credentialFile = Join-Path $testRoot 'database-test-mysql8.json'
$server = Join-Path $serverRoot 'bin/mysqld.exe'
if (-not (Test-Path -LiteralPath $server)) { throw 'Official MySQL 8.4.8 Windows binaries are required under build/mysql.' }
if (-not [System.IO.Path]::GetFullPath($dataRoot).StartsWith($testRoot + [System.IO.Path]::DirectorySeparatorChar)) { throw 'Unsafe test data path.' }
$initFile = Join-Path $testRoot 'mysql-init.sql'
if (-not (Test-Path -LiteralPath $credentialFile)) {
    if (Test-Path -LiteralPath $dataRoot) { throw 'Existing MySQL data without credentials; refusing to overwrite.' }
    $testPassword = [Convert]::ToHexString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
    & $server --no-defaults --initialize-insecure "--basedir=$serverRoot" "--datadir=$dataRoot" --console *> (Join-Path $testRoot 'mysql-bootstrap.log')
    if ($LASTEXITCODE -ne 0) { throw 'MySQL initialization failed; inspect build/mysql-bootstrap.log.' }
    Set-Content -LiteralPath $initFile -Value "ALTER USER 'root'@'localhost' IDENTIFIED BY '$testPassword';"
    @{host='127.0.0.1';port=13373;database='formstudio_test_mysql8';user='root';password=$testPassword} | ConvertTo-Json | Set-Content -LiteralPath $credentialFile
}
$pidFile = Join-Path $testRoot 'mysql-process.json'
if (Test-Path -LiteralPath $pidFile) {
    $previous = Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json
    $running = Get-Process -Id $previous.pid -ErrorAction SilentlyContinue
    if ($running -and $running.Path -eq $server) { Write-Output 'Isolated MySQL already running on 127.0.0.1:13373.'; exit 0 }
}
if (Get-NetTCPConnection -LocalPort 13373 -State Listen -ErrorAction SilentlyContinue) { throw 'Test port already in use by another process.' }
$arguments = @('--no-defaults', ('--basedir="' + $serverRoot + '"'), ('--datadir="' + $dataRoot + '"'), '--bind-address=127.0.0.1', '--port=13373', '--mysqlx=OFF', '--console')
if (Test-Path -LiteralPath $initFile) { $arguments += ('--init-file="' + $initFile + '"') }
$process = Start-Process -FilePath $server -ArgumentList $arguments -WorkingDirectory $serverRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot 'mysql-stdout.log') -RedirectStandardError (Join-Path $testRoot 'mysql-stderr.log')
@{pid=$process.Id;executable=$server;data=$dataRoot;port=13373} | ConvertTo-Json | Set-Content -LiteralPath $pidFile
Write-Output "Isolated MySQL started on 127.0.0.1:13373 (PID $($process.Id)). Remove build/mysql-init.sql after the first authenticated database test."
