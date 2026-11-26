# Entorno local — `liga_padel`

## Requisitos

- Docker Desktop (Windows) en ejecución.
- Copia local de `.env` desde `.env.example` (no commitear).

## Variables

| Variable | Uso |
|----------|-----|
| `DB_NAME` | `liga_padel` |
| `DB_USER` / `DB_PASSWORD` | Usuario aplicación |
| `MYSQL_ROOT_PASSWORD` | Solo contenedor `db` |
| `DB_HOST` | `db` dentro de Compose; `127.0.0.1` si PHP fuera de Docker |

## Primera puesta en marcha (volumen nuevo)

1. Copiar `.env.example` → `.env` y cambiar contraseñas.
2. `docker compose up -d --build`
3. MySQL ejecuta **una sola vez** `sql/liga_padel_init.sql` si el volumen `db_data` está vacío.
4. Comprobar: `http://localhost:8000/health.php` (cuando exista) o phpMyAdmin `:8080`.

## Volumen MySQL ya existente (procedimiento seguro)

**No usar** `docker compose down -v` sin inspeccionar datos.

### Paso 1 — Inspeccionar

```powershell
docker compose ps
docker compose exec db mysql -uroot -p"$env:MYSQL_ROOT_PASSWORD" -e "SHOW DATABASES;"
docker compose exec db mysql -uroot -p"$env:MYSQL_ROOT_PASSWORD" -e "USE liga_tenis; SHOW TABLES;"
```

(Sustituir contraseña o usar valor de `.env`.)

### Paso 2 — Decidir

- Si solo hay datos de prueba desechables de esta copia → puedes recrear volumen **con backup previo** (export manual).
- Si hay duda → export:  
  `docker compose exec db mysqldump -uroot -p... --all-databases > backup_YYYYMMDD.sql`

### Paso 3 — Crear `liga_padel` sin borrar volumen

```powershell
docker compose exec -T db mysql -uroot -p... -e "CREATE DATABASE IF NOT EXISTS liga_padel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content sql\liga_padel_init.sql | docker compose exec -T db mysql -uroot -p... liga_padel
```

Crear usuario app si no existe (ver script SQL).

### Causa del error `torneos` no existe

- Compose montaba [`init.sql`](../init.sql) solo en **primer** init del volumen.
- Volumen creado antes, init fallido parcial, o BD `liga_tenis` sin tablas → `index.php:14` falla.
- **Solución pádel:** conectar app a `liga_padel` con esquema nuevo; no reimportar volcado producción.

## Legacy `init.sql`

- Contiene **datos personales reales** (auditoría).
- **Deprecado** para pádel; no montar en Docker.
- Mantener en repo solo como referencia histórica hasta eliminación acordada.

## Recuperación

| Escenario | Acción |
|-----------|--------|
| Esquema corrupto en dev | Restaurar `backup_*.sql` o reimportar `liga_padel_init.sql` en BD vacía |
| Contraseña olvidada | Recrear usuario en MySQL o reset en `.env` + volumen (último recurso) |
| Contenedores no arrancan | `docker compose logs db web` |

## Comprobaciones Fase 2

Con Docker activo:

```powershell
docker compose exec web php tests/run.php
curl http://localhost:8000/health.php
```

**Estado verificación (sin Docker en marcha):** pruebas **no ejecutadas**; scripts preparados en implementación.

## Credenciales de demo (post-init)

- Email admin ficticio en el SQL de inicio, solo para entorno local.
- La contraseña no se escribe en el repositorio. Cambiarla en cualquier despliegue.
