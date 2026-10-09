$ErrorActionPreference = 'Stop'
$workspace = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$testRoot = Join-Path $workspace 'build'
$serverRoot = Join-Path $testRoot 'postgresql-14/pgsql'
$dataRoot = Join-Path $testRoot 'postgresql-data'
$credentialFile = Join-Path $testRoot 'database-test-postgresql.json'
$server = Join-Path $serverRoot 'bin/postgres.exe'
if (-not (Test-Path -LiteralPath $server)) { throw 'Official PostgreSQL 14 Windows binaries are required under build/postgresql-14/pgsql.' }
if (-not [System.IO.Path]::GetFullPath($dataRoot).StartsWith($testRoot + [System.IO.Path]::DirectorySeparatorChar)) { throw 'Unsafe test data path.' }
if (-not (Test-Path -LiteralPath $credentialFile)) {
    if (Test-Path -LiteralPath $dataRoot) { throw 'Existing cluster without credentials; refusing to overwrite.' }
    $testPassword = [Convert]::ToHexString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
    $passwordFile = Join-Path $testRoot 'postgresql-init-password.txt'
    Set-Content -LiteralPath $passwordFile -Value $testPassword -NoNewline
    try {
        & (Join-Path $serverRoot 'bin/initdb.exe') -D $dataRoot -U formstudio_test "--pwfile=$passwordFile" --auth=scram-sha-256 --encoding=UTF8 --locale=C *> (Join-Path $testRoot 'postgresql-bootstrap.log')
        if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL initialization failed; inspect build/postgresql-bootstrap.log.' }
    } finally { Remove-Item -LiteralPath $passwordFile }
    Add-Content -LiteralPath (Join-Path $dataRoot 'postgresql.conf') -Value "`nlisten_addresses = '127.0.0.1'`nport = 13368`ntimezone = 'UTC'`n"
    @{host='127.0.0.1';port=13368;database='formstudio_test_pg';user='formstudio_test';password=$testPassword} | ConvertTo-Json | Set-Content -LiteralPath $credentialFile
}
$pidFile = Join-Path $testRoot 'postgresql-process.json'
if (Test-Path -LiteralPath $pidFile) {
    $previous = Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json
    $running = Get-Process -Id $previous.pid -ErrorAction SilentlyContinue
    if ($running -and $running.Path -eq $server) { Write-Output 'Isolated PostgreSQL already running on 127.0.0.1:13368.'; exit 0 }
}
if (Get-NetTCPConnection -LocalPort 13368 -State Listen -ErrorAction SilentlyContinue) { throw 'Test port already in use by another process.' }
$process = Start-Process -FilePath $server -ArgumentList '-D', ('"' + $dataRoot + '"') -WorkingDirectory $serverRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot 'postgresql-stdout.log') -RedirectStandardError (Join-Path $testRoot 'postgresql-stderr.log')
@{pid=$process.Id;executable=$server;data=$dataRoot;port=13368} | ConvertTo-Json | Set-Content -LiteralPath $pidFile
Write-Output "Isolated PostgreSQL started on 127.0.0.1:13368 (PID $($process.Id))."
