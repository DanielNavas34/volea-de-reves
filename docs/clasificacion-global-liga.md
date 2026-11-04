# Clasificación global entre grupos (Fase 8)

Aplica a competiciones de liga con **`modalidad_liga = 'grupos'`**.

## Regla aprobada

- Se listan **todas las parejas** inscritas con fila en `clasificacion_parejas` para esa `competicion_id`, sin importar el grupo.
- Cada fila muestra su **grupo de origen** (`grupo_nombre`).
- El **orden** usa los mismos criterios que la clasificación de grupo (`docs/desempates-liga.md`): puntos, victorias, diferencia de sets, diferencia de juegos.
- Es **informativa**: no sustituye la clasificación dentro del grupo ni define ascensos automáticos.

## Modalidad `general`

Un solo grupo lógico; la tabla global coincide con la del grupo.

## Implementación

`LeagueStandingsService::fetchCompeticionGlobalStandings($competicionId)` — UI en `usuario/clasificacion.php`.
