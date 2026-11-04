# Seguridad — estado y plan

Verificación sobre código actual (Fase 2, solo lectura).

## Resumen por severidad

| Sev | Tema | Evidencia | Fase corrección |
|-----|------|-----------|-----------------|
| Crítico | PII en `init.sql` | Volcado producción | Fase 2: dejar de montar; Fase 3+: eliminar del repo |
| Alto | Secretos en código | `config/database.php` líneas 6-9 | Fase 2: `.env` |
| Alto | CSRF incompleto | ~~GET mutaciones usuario~~ → POST + CSRF | Corregido Fase 10 |
| Alto | Admin GET destructivo | ~~`admin/partidos.php` GET~~ → POST + `requirePostCsrf()` | Corregido Fase 10 |
| Alto | SQL concatenado | `WHERE usuario_id = " . $id` | Fase 7–10 (servicio + prepared) |
| Alto | Verificación email | PIN por correo (`MAIL_DRIVER=smtp`) o fake en dev | Fase 10 |
| Medio | AuthZ liga | `requireLiga()` sin uso | Fase 4+ |
| Medio | Eliminación usuario | No limpia inscripciones torneo legacy | Fase migración |
| Bajo | `verificar.php` | Columna `token_verificacion` inexistente en esquema | Deprecar |
| Info | Doble `session_start` | `index.php` + `security.php` | Unificar bootstrap |

## Controles existentes (mantener)

- PDO prepared en la mayoría de consultas de login/registro.
- `password_hash` / `password_verify`.
- Rate limit login (`login_attempts`), recuperación de contraseña (misma tabla) y reenvío PIN (`registro_attempts` si `REGISTRATION_RATE_LIMIT=true`).
- CSRF en login, registro, recuperación, inscripción.
- Recuperación: token aleatorio en el correo, hash SHA-256 en BD (`includes/password_reset_tokens.php`); respuesta neutra si el email no existe; invalidación de sesión tras cambio (`password_changed_at` + `enforcePasswordChangedAtSession`).
- URLs públicas sin `.php` (`.htaccess` 301 + `appUrl()` / `redirectTo()`).
- Sesión: httponly, SameSite Strict, regeneración periódica.
- Open redirect mitigado en login.
- `.htaccess`: `Options -Indexes`, reescritura sin `.php`, cabeceras `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`.

## Reglas obligatorias (nuevo código)

1. **Prepared statements** siempre; prohibido concatenar IDs en SQL.
2. **POST + CSRF** para cambios de estado (partidos, admin, inscripciones).
3. **AuthZ en servidor** antes de leer/escribir partido o competición.
4. **Secretos** solo en `.env`; `.gitignore` para `.env`.
5. **Errores producción:** `APP_DEBUG=false`, mensajes genéricos al usuario.
6. **Resultados:** solo participantes de la pareja en el partido o admin pueden validar/rechazar.
7. **Auditoría** en cambios admin de marcador y config de puntos.

## Archivos sensibles

- `.env` y `config/mail.local.php` — no en Git; contraseñas SMTP solo ahí (nunca en `includes/mailer.php` ni commits).
- `config/database.php` — sin passwords en claro tras Fase 2.
- `.htaccess` deniega acceso HTTP a `/config/`, `/includes/`, `/sql/` y `/tests/`.

## Checklist pre-producción

- [x] HTTPS forzado fuera de `local` (`FORCE_HTTPS`, `includes/https_guard.php`)
- [x] Cookies `Secure` con HTTPS / proxy (`appIsHttpsRequest`)
- [x] `.env` y `storage/` bloqueados en `.htaccess`
- [x] Cron solo CLI o `CRON_SECRET` (`includes/cron_guard.php`)
- [x] `MAIL_DRIVER=fake` ignorado fuera de `local`; PIN no en logs públicos
- [x] Errores genéricos al usuario si `APP_DEBUG=false` (`appSafeExceptionMessage`)
- [ ] Cabeceras CSP básicas
- [ ] Backups cifrados BD
- [ ] Cuentas DB con mínimo privilegio
- [ ] Rotar admin seed y SMTP en cada entorno nuevo (manual)

Ver [`pre-despliegue-staging.md`](pre-despliegue-staging.md).
