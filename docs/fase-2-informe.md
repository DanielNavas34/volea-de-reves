# Informe final — Fase 2 (completada)

Fecha: 2026-03-25. Entorno: Docker local en ejecución.

---

## 1. Arquitectura

Documentada en [`arquitectura.md`](arquitectura.md). Implementados bootstrap de configuración (`includes/env.php`), comprobación de esquema legacy (`includes/legacy_schema.php`) y helper de puntos con prepared statements (`includes/league_points.php`).

---

## 2. Esquema de datos `liga_padel`

- Script: [`sql/liga_padel_init.sql`](../sql/liga_padel_init.sql)
- Tablas núcleo: usuarios, parejas, pareja_miembros, competiciones, competicion_grupos, inscripciones, partidos, partido_sets, clasificacion_parejas, configuracion, audit_log, login/registro attempts.
- Seeds: config 180/50; usuario admin ficticio `admin@liga-padel.local` (solo local; la contraseña no está en el repositorio).

---

## 3. Archivos creados o modificados

| Archivo | Acción |
|---------|--------|
| `.env.example`, `.env`, `.gitignore` | Nuevo |
| `docker-compose.yml` | `liga_padel`, env_file, init SQL pádel (sin `init.sql` PII) |
| `config/database.php` | Lee credenciales desde `.env` |
| `includes/env.php`, `legacy_schema.php`, `league_points.php` | Nuevo |
| `sql/liga_padel_init.sql` | Nuevo |
| `health.php`, `tests/*`, `scripts/import-liga-padel.ps1` | Nuevo |
| `usuario/partidos.php`, `usuario/dashboard.php`, `admin/partidos.php` | SQL preparado, POST+CSRF, guard legacy |
| `docs/fase-2-informe.md` | Actualizado |

---

## 4. Problemas detectados y solución

| Problema | Solución aplicada |
|----------|-------------------|
| Tabla `torneos` ausente en `liga_tenis` | BD app → `liga_padel` con esquema nuevo |
| Credenciales en código | `.env` + `getDBConnection()` |
| `init.sql` con PII montado en Docker | Desmontado; usar `sql/liga_padel_init.sql` |
| SQL concatenado en clasificación | `includes/league_points.php` |
| Mutaciones GET (partidos) | POST + CSRF en usuario/admin partidos |
| Páginas legacy vs esquema nuevo | Detección `legacyLeagueSchemaAvailable()` |

---

## 5. Pruebas ejecutadas (evidencia)

### `docker compose exec web php tests/run.php`

```
[OK] database_connection
[OK] table_usuarios
[OK] table_parejas
[OK] table_pareja_miembros
[OK] table_competiciones
[OK] table_competicion_grupos
[OK] table_inscripciones
[OK] table_partidos
[OK] table_partido_sets
[OK] table_clasificacion_parejas
[OK] table_configuracion
[OK] config_liga_puntos_victoria

Resumen: 12 ok, 0 fail
Exit code: 0
```

### `health.php`

- CLI: `{"ok":true,"database":"liga_padel"}`
- HTTP `http://localhost:8000/health.php`: `{"ok":true,"database":"liga_padel"}`

---

## 6. Riesgos pendientes

- Páginas públicas legacy (`index.php`, `torneos.php`) siguen consultando tablas tenis hasta Fase 3–4.
- `includes/mailer.php` aún ausente (registro por email).
- Reglamento detallado de marcador pádel (super tie-break) pendiente de aprobación deportiva.
- `init.sql` legacy sigue en el repo (no usado por Docker); conviene eliminarlo en fase de limpieza.

---

## 7. Próxima fase recomendada

**Fase 4 — Parejas, partidos y torneos en UI** sobre `liga_padel`: servicios, pantallas, inscripción por pareja, primer flujo de liga pádel.

---

## 8. Checklist Fase 2

- [x] Arquitectura documentada
- [x] Modelo datos implementado en MySQL
- [x] BD `liga_padel` separada y conectada
- [x] Secretos fuera del código fuente (`.env` en `.gitignore`)
- [x] Init sin PII de producción
- [x] Tests y health con evidencia
- [x] Endurecimiento básico SQL/CSRF en módulo partidos legacy
