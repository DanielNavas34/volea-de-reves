# Informe final — Fase 3 (Bootstrap aplicación)

Fecha: 2026-10-20. Entorno: Docker local `liga_padel`.

## Entregables

| Ítem | Estado | Evidencia |
|------|--------|-----------|
| `includes/env.php` | Hecho | Fase 2 |
| `health.php` | Hecho | JSON `ok` + metadatos app |
| `includes/mailer.php` | Hecho | fake/log/smtp vía `.env` |
| Bootstrap único sesión | Hecho | `includes/bootstrap.php` + `appEnsureSession()` |
| CSRF acoplado a sesión | Hecho | `includes/security.php` → bootstrap |
| Esquema pádel obligatorio (app) | Hecho | `requireVoleaPadelInstall()` en `requireLogin()` |
| Pantalla instalación | Hecho | `instalacion_requerida.php` |
| Deprecar rutas tenis legacy | Hecho | `_legacy_deprecated/`, `legacy_tenis_guard.php`, stub `inscripcion.php` |
| Portada sin fatal si falta BD | Hecho | Aviso en `index.php` / `pulls.php` |
| `verificar.php` (token email) | Deprecado | Redirige a flujo PIN en `registro.php` |

## Archivos clave

- `includes/bootstrap.php` — sesión, URLs instalación, comprobación esquema.
- `includes/auth.php` — `requireLogin()` exige `liga_padel`.
- Rutas públicas Pulls/liga: solo `competiciones` (sin tabla `torneos`).
- Admin `grupos.php`: solo esquema pádel (sin categorías tenis legacy).

## Pruebas

```bash
docker compose exec web php tests/run.php
```

Incluye `bootstrap_fase3_functions`, CSRF y smoke admin.

## Legacy residual (referencia, no rutas activas en pádel)

Código tenis individual puede permanecer en `admin/partidos.php` o `usuario/dashboard.php` bajo `legacyLeagueSchemaAvailable()` — **no se ejecuta** cuando existen columnas `pareja_a_id` y tabla `competiciones`. Referencia histórica en `_legacy_deprecated/`.

## Criterios de aceptación Fase 3

- [x] Un solo punto de arranque de sesión para la app
- [x] Mailer configurable operativo
- [x] Health check operativo
- [x] Instalaciones sin esquema pádel guiadas (no pantallas rotas)
- [x] Rutas críticas tenis aisladas o redirigidas
