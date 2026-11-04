# Informe final — Fase 5 (Competiciones e inscripciones)

Fecha: 2026-10-20.

## Entregables

| Ítem | Estado | Ubicación |
|------|--------|-----------|
| Liga general / por grupos | Hecho | `ligaCrearVacia`, `admin/grupos.php` |
| Publicación (abierta, en curso, …) | Hecho | `LeagueCompetitionService::publicarLiga` |
| Inscripción usuario (pendiente) | Hecho | `usuario/ligas.php` → `solicitarInscripcion` |
| Confirmación admin | Hecho | `admin/inscripciones_liga.php` → `actualizarEstadoInscripcion` |
| Calendario round robin | Hecho | `generarCalendarioRoundRobin` (solo **confirmadas**) |
| Servicio de dominio | Hecho | `includes/competition_service.php` |
| Tests | Hecho | `tests/CompetitionServiceTest.php` |

## Flujo de inscripción

1. Usuario con **pareja activa** → `usuario/ligas.php` → estado **pendiente**.
2. Admin → `inscripciones_liga.php?grupo_id=` → **confirmada** → fila en `clasificacion_parejas`.
3. Admin → **Generar calendario** cuando hay ≥ 2 parejas confirmadas.

## Pulls

Las competiciones `tipo = 'pull'` se gestionan aparte (`dashboard`, `pull_gestion`, `inscripciones_pull`). Fase 5 cubre **ligas por parejas** en `competiciones` + `inscripciones`.

## Pruebas

```bash
docker compose exec web php tests/run.php
```

Casos: solicitud duplicada, pendiente→confirmada+clasificación, calendario solo con confirmadas.

## Criterios de aceptación Fase 5

- [x] Crear y publicar ligas (general / grupos)
- [x] Inscripción con confirmación admin
- [x] Servicio centralizado
- [x] Tests automatizados
