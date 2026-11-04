# Informe final — Fase 9 (UI pádel)

Fecha: 2026-10-20.

## Entregables

| Ítem | Estado | Ubicación |
|------|--------|-----------|
| Etiquetas pareja (2 nombres) | Hecho | `includes/padel_ui_helpers.php` |
| Estilos pareja / enfrentamiento | Hecho | `assets/css/style.css` |
| Tablas responsive + clase pádel | Hecho | `.table-responsive`, `league-table--padel` |
| Portada, usuario, admin | Hecho | `index.php`, `usuario/*`, `admin/partidos.php`, `parejas.php`, `inscripciones_liga.php` |
| Tests | Hecho | `tests/PadelUiHelpersTest.php` |

## Helpers

- `padelHtmlEtiquetaPareja()` — divide `Nombre1 / Nombre2` en `.pareja-miembro`.
- `padelHtmlEnfrentamientoParejas()` — bloque A vs B.
- `padelLeagueTableClass()` — `league-table league-table--padel`.

Los datos siguen generándose con `sqlParejaEtiqueta()` en SQL; la Fase 9 unifica **presentación** HTML/CSS.

## Pruebas

```bash
docker compose exec web php tests/run.php
```

## Criterios de aceptación Fase 9

- [x] Listados muestran pareja con dos jugadores
- [x] Tablas con scroll horizontal en móvil
- [x] Tests de helpers UI
