# Implementación pendiente — copiar al activar modo Agent

Cursor sigue en **modo plan** (no permite editar PHP/SQL). Contenido listo para aplicar en un solo paso.

## 1. `docker-compose.yml` (reemplazar servicio db y web)

```yaml
services:
  web:
    build: .
    ports:
      - "8000:80"
    volumes:
      - ./:/var/www/html
    env_file:
      - .env
    environment:
      DB_HOST: db
    depends_on:
      - db

  db:
    image: mysql:8.0
    ports:
      - "3306:3306"
    env_file:
      - .env
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_NAME}
      MYSQL_USER: ${DB_USER}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
      - ./sql/liga_padel_init.sql:/docker-entrypoint-initdb.d/01-liga_padel.sql:ro

  phpmyadmin:
    image: phpmyadmin/phpmyadmin
    ports:
      - "8080:80"
    environment:
      PMA_HOST: db
    depends_on:
      - db

volumes:
  db_data:
```

**Importante:** dejar de montar `init.sql` (PII producción).

## 2. Archivos nuevos

Ver contenido completo en:

- [`includes/env.php`](implementacion/env.php.md) — incluido abajo en sección 3
- [`config/database.php`](implementacion/database.php.md)
- [`.env.example`](../.env.example) — crear desde entorno-local
- [`sql/liga_padel_init.sql`](sql-liga_padel_init-reference.md) — mover SQL a `sql/`
- [`health.php`](implementacion/health.php.md)
- [`tests/run.php`](implementacion/tests.md)

## 3. `includes/env.php`

Ver implementación estándar en diseño Fase 2 (funciones `loadEnvFile`, `env`).

## 4. `health.php`

```php
<?php
header('Content-Type: application/json; charset=utf-8');
$required = ['usuarios','parejas','competiciones','partidos','configuracion'];
try {
    require_once __DIR__ . '/config/database.php';
    $db = getDBConnection();
    $dbName = env('DB_NAME', 'liga_padel');
    $missing = [];
    foreach ($required as $table) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
        );
        $stmt->execute([$dbName, $table]);
        if ((int)$stmt->fetchColumn() === 0) {
            $missing[] = $table;
        }
    }
    if ($missing) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'missing_tables' => $missing]);
        exit;
    }
    echo json_encode(['ok' => true, 'database' => $dbName]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'database_unavailable']);
}
```

## 5. Tests mínimos

`tests/bootstrap.php` — carga autoload simple.  
`tests/SchemaTest.php` — comprueba tablas.  
`tests/run.php` — ejecuta tests, exit code 0/1.

## 6. Script import manual (PowerShell)

`scripts/import-liga-padel.ps1`:

```powershell
# Uso: .\scripts\import-liga-padel.ps1
docker compose exec -T db mysql -uroot -p$env:MYSQL_ROOT_PASSWORD -e "CREATE DATABASE IF NOT EXISTS liga_padel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content sql\liga_padel_init.sql | docker compose exec -T db mysql -uroot -p$env:MYSQL_ROOT_PASSWORD
```

## 7. Post-implementación

1. Copiar `.env.example` → `.env`
2. Iniciar Docker Desktop
3. Import manual si volumen ya existía (ver `entorno-local.md`)
4. `docker compose exec web php tests/run.php`
5. Abrir `http://localhost:8000/health.php`

Actualizar [`fase-2-informe.md`](fase-2-informe.md) con resultados reales.
