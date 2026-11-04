# Informe final — Fase 8 (Estadísticas e historial)

Fecha: 2026-10-20.

## Entregables

| Ítem | Estado | Ubicación |
|------|--------|-----------|
| Historial pareja / jugador | Hecho | `includes/history_service.php`, `usuario/historial.php` |
| Estadísticas resumen | Hecho | `resumenPareja`, `resumenJugador` (snapshot) |
| Clasificación global | Hecho | `LeagueStandingsService::fetchCompeticionGlobalStandings` |
| UI global multi-grupo | Hecho | `usuario/clasificacion.php` |
| Regla documentada | Hecho | `docs/clasificacion-global-liga.md` |
| Navegación | Hecho | Menú «Historial», enlace desde `partidos.php` |
| Tests | Hecho | `tests/HistoryServiceTest.php` |

## Historial jugador

Los partidos personales se obtienen de **`partido_integrantes_snapshot`** (integrantes al validar). Si la tabla no existe, fallback a partidos de la pareja activa actual.

## Clasificación global

Solo se muestra cuando la liga tiene **`modalidad_liga = 'grupos'`**. Mismos desempates que en grupo (`docs/desempates-liga.md`).

## Pruebas

```bash
docker compose exec web php tests/run.php
```

## Criterios de aceptación Fase 8

- [x] Vistas historial por pareja y jugador
- [x] Clasificación global entre grupos
- [x] Servicio + tests
