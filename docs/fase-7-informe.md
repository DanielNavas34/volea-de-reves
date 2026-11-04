# Informe final — Fase 7 (Scoring pádel + clasificación)

Fecha: 2026-10-20.

## Entregables

| Ítem | Estado | Ubicación |
|------|--------|-----------|
| Formatos de partido | Hecho | `includes/scoring/MatchFormat.php` |
| Validación marcador | Hecho | `includes/scoring/ScoringValidator.php` |
| Puntos y clasificación | Hecho | `includes/standings_service.php` (`LeagueStandingsService`) |
| Desempates documentados | Hecho | `docs/desempates-liga.md` |
| UI clasificación ordenada | Hecho | `usuario/clasificacion.php`, `public_home_helpers.php` |
| Tests | Hecho | `ScoringValidatorTest.php`, `StandingsServiceTest.php` |

## Formatos soportados

| Código | Descripción |
|--------|-------------|
| `best_of_3_standard` | Default BD; sets 6-0…7-6 |
| `best_of_3_super_tb` | Sets 1-2 estándar; set 3 super TB (10+ dif. 2) |

El código lee `partidos.formato_partido_codigo` al parsear resultados de admin.

## Clasificación

- Puntos victoria/derrota desde `configuracion` (`getPointsConfig`).
- Mutaciones incrementales: `applyValidatedMatchPoints` / `revertValidatedMatchPoints` (usados por Fase 6).
- Listados y posición de pareja: `fetchGrupoStandings`, `posicionParejaEnGrupo` con desempates unificados.

## Pruebas

```bash
docker compose exec web php tests/run.php
```

## Criterios de aceptación Fase 7

- [x] Scoring configurable (formatos + validador)
- [x] `LeagueStandingsService` lee configuración
- [x] Desempates documentados y aplicados en listados
- [x] Tests automatizados
