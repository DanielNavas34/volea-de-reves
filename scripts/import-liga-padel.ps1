# Recrea liga_padel desde cero (solo entorno local de desarrollo).
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Host "Creado .env desde .env.example"
}

$rootPass = "rootpassword"
foreach ($line in Get-Content ".env") {
    if ($line -match '^MYSQL_ROOT_PASSWORD=(.+)$') {
        $rootPass = $Matches[1]
    }
}

Write-Host "Eliminando y recreando liga_padel..."
docker compose exec -T db mysql -uroot "-p$rootPass" -e "DROP DATABASE IF EXISTS liga_padel;"
$sql = Get-Content -Raw "sql\liga_padel_init.sql"
$sql | docker compose exec -T db mysql -uroot "-p$rootPass"

$dbUser = "admin_padel"
$dbPass = "secret_padel_dev"
foreach ($line in Get-Content ".env") {
    if ($line -match '^DB_USER=(.+)$') { $dbUser = $Matches[1] }
    if ($line -match '^DB_PASSWORD=(.+)$') { $dbPass = $Matches[1] }
}

$grant = "CREATE USER IF NOT EXISTS '$dbUser'@'%' IDENTIFIED BY '$dbPass'; GRANT ALL PRIVILEGES ON liga_padel.* TO '$dbUser'@'%'; FLUSH PRIVILEGES;"
docker compose exec -T db mysql -uroot "-p$rootPass" -e $grant
Write-Host "Importacion completada."
