# Desempates — clasificación liga (Fase 7)

Orden aplicado por `LeagueStandingsService` al listar un grupo (`competicion_id` + `grupo_id`).

| Prioridad | Criterio | Dirección |
|-----------|----------|-----------|
| 1 | Puntos (`puntos`) | Mayor primero |
| 2 | Partidos ganados (`partidos_ganados`) | Mayor primero |
| 3 | Diferencia de sets (`sets_favor − sets_contra`) | Mayor primero |
| 4 | Diferencia de juegos (`juegos_favor − juegos_contra`) | Mayor primero |
| 5 | Identificador pareja (`pareja_id`) | Menor primero (estable) |

## Clasificación global

Ver **`docs/clasificacion-global-liga.md`** (Fase 8): mismo orden de desempates entre parejas de distintos grupos.

## Fuera de alcance (futuro)

- **Enfrentamiento directo** entre parejas empatadas.
- Recálculo masivo admin expuesto en UI (método `recalcularGrupo` disponible en servicio para mantenimiento).

## Puntos de liga

Leídos de `configuracion`:

- `liga_puntos_victoria` (default 180)
- `liga_puntos_derrota` (default 50)

No dependen del marcador del partido; solo del resultado validado (ganador/perdedor).
