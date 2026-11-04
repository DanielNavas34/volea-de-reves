# Informe final — Fase 6 (Partidos liga)

Fecha: 2026-10-20.

## Entregables

| Ítem | Estado | Ubicación |
|------|--------|-----------|
| Servicio de partidos | Hecho | `includes/match_service.php` (`LeagueMatchService`) |
| Snapshot al validar | Hecho | `includes/partido_snapshot.php` → `padelEnsurePartidoIntegrantesSnapshot` |
| Puntos / stats clasificación | Centralizado | `padelApplyValidatedMatchPoints` (sin SQL en páginas) |
| Reporte usuario | Hecho | `usuario/partidos.php` → `reportarResultadoUsuario` |
| Aceptar / rechazar rival | Hecho | `usuario_padel_helpers.php` → servicio |
| Admin registrar / validar / borrar | Hecho | `admin/partidos.php` → servicio |
| Incidencia → forzar ganador | Hecho | `partido_flujo_usuario.php` → `validarPartido` |
| Auto-validación 24 h | Hecho | `autoValidarPartidosCaducados` |
| Tests | Hecho | `tests/MatchServiceTest.php` |

## Flujo

1. Calendario genera partidos `pendiente`.
2. Usuario confirma disputa → reporta sets → rival **acepta** → `validarPartido` → puntos + **4 filas snapshot**.
3. Admin puede registrar partido ya validado o validar pendientes; eliminar revierte puntos si estaba validado.

## Pruebas

```bash
docker compose exec web php tests/run.php
```

Casos: snapshot 4 jugadores al validar; reporte + aceptación actualiza clasificación.

## Fuera de alcance (Fase 7+)

- Recalcular competición completa (`LeagueStandingsService`).
- Corregir marcador de partido ya validado con revert/reapply (solo edición de sets en admin sin recalcular puntos — comportamiento previo).
- Validación reglamentaria avanzada de sets (`ScoringValidator`).

## Criterios de aceptación Fase 6

- [x] `MatchService` y snapshot en validación
- [x] Flujo usuario sin SQL directo de clasificación
- [x] Admin delegado en servicio
- [x] Tests automatizados
