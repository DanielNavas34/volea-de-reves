# Informe final — Fase 10 (Seguridad)

Fecha: 2026-10-20.

## Entregables

| Ítem | Estado | Ubicación |
|------|--------|-----------|
| CSRF unificado en POST admin partidos | Hecho | `admin/partidos.php` → `requirePostCsrf()` |
| CSRF incidencias admin | Hecho | `admin/incidencias_partidos.php` |
| Verificación email + PIN restaurada | Hecho | `registro.php`, `login.php`, `includes/auth.php` |
| Mailer SMTP / fake configurable | Hecho | `includes/mailer.php`, `.env.example` |
| Reenvío PIN | Hecho | `registro.php` (`accion=reenviar_pin`) |
| Tests mailer / verificación | Hecho | `tests/MailerTest.php` |

## Verificación de correo

- **Activa** por defecto (`emailVerificationEnabled()`).
- Registro: usuario `email_verificado=0` hasta introducir PIN de 6 dígitos.
- Login y `requireLogin()` redirigen a `registro.php` si el email no está verificado (excepto admin).
- **Desactivar solo en tests:** `SKIP_EMAIL_VERIFICATION=true` en `.env`.
- **Envío real:** `MAIL_DRIVER=smtp` y `MAIL_HOST`, `MAIL_USERNAME`, etc.
- **Desarrollo sin SMTP:** solo con `MAIL_DRIVER=fake` explícito (no hay banner de PIN en registro).
- **Credenciales:** `.env` o `config/mail.local.php` (copiar del `includes/mailer.php` del servidor tenis).
- El `.env` del proyecto prevalece sobre variables antiguas del contenedor Docker.

## CSRF y mutaciones GET

- Acciones admin de partidos (validar, eliminar, registrar) van por **POST** con token CSRF.
- Área usuario (`partidos.php`, `dashboard.php`, `pulls.php`, etc.) ya usaban POST + CSRF para cambios de estado.

## Pruebas

```bash
docker compose exec web php tests/run.php
```

## Criterios de aceptación Fase 10

- [x] POST mutaciones con CSRF en admin partidos e incidencias
- [x] Sin atajos de registro/login que salten verificación de email
- [x] Mailer documentado en `.env.example`
- [x] Tests de helpers de mailer
