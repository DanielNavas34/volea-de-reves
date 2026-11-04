# Informe final — Fase 4 (Parejas)

Fecha: 2026-10-20.

## Entregables del plan

| Ítem | Estado | Ubicación |
|------|--------|-----------|
| Servicio de dominio | Hecho | `includes/pair_service.php` (`PairService`, `pairService()`) |
| Dos titulares | Hecho | `adminCrearPareja`, flujo invitación → aceptar |
| Invitaciones | Hecho | `pareja_invitaciones`, `enviarInvitacionPorEmail`, `responderInvitacion` |
| Pantalla usuario | Hecho | `usuario/pareja.php` (delegada al servicio) |
| Pantalla admin | Hecho | `admin/parejas.php` (crear / editar estado) |
| Reglas de negocio | Hecho | `includes/padel_guardrails.php` |
| Consultas / etiquetas | Hecho | `includes/parejas_helpers.php` |
| Tests Fase 4 | Hecho | `tests/PairServiceTest.php` |

## API del servicio (resumen)

- `crearParejaAdmin($u1, $u2, $nombre, $creadorId)` — pareja **activa** con 2 titulares.
- `actualizarParejaAdmin($parejaId, $estado, $nombreDisplay)` — incluye bloqueo si inscrita en liga viva.
- `enviarInvitacionPorEmail($invitadorId, $email)` — pareja **borrador** + invitación 7 días.
- `responderInvitacion($invitadoId, $invitacionId, aceptar|rechazar)` — confirma 2 titulares.
- `disolverParejaDeUsuario($usuarioId)` — respeta guardrails de inscripción.

## Pruebas

```bash
docker compose exec web php tests/run.php
```

Casos: mismo jugador, email/auto-invite inválidos, duplicado de pareja activa, flujo invitación → aceptar con 2 titulares.

## Criterios de aceptación Fase 4

- [x] Lógica de parejas centralizada en servicio
- [x] Invitaciones y confirmación
- [x] Admin puede crear/editar
- [x] Tests automatizados de duplicados y confirmación
