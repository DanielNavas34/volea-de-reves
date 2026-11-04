# Preproducción — checklist de seguridad

## Antes del primer deploy

1. Copiar [`.env.staging.example`](../.env.staging.example) → `.env` en el servidor (**nunca** en Git).
2. `APP_ENV=staging`, `APP_DEBUG=false`, `REGISTRATION_RATE_LIMIT=true`.
3. `TRUSTED_PROXY=true` si hay Cloudflare/nginx delante; `FORCE_HTTPS=true`.
4. `MAIL_DRIVER=smtp` con credenciales reales; **no** usar `fake` fuera de local.
5. Definir `CRON_SECRET` (aleatorio ≥ 32 caracteres) si los cron se llaman por URL.
6. Importar [`sql/liga_padel_init.sql`](../sql/liga_padel_init.sql) + migraciones `php sql/apply_*.php` si aplica.
7. **Cambiar contraseña** del admin seed (`admin@liga-padel.local`) tras el primer login.
8. Rotar SMTP y BD si alguna credencial estuvo en un commit, zip o chat.

## Comprobaciones HTTP

- `https://tu-dominio/health` → `ok: true` sin mensajes de PDO.
- Intentar `https://tu-dominio/.env` → **403/404**.
- Intentar `https://tu-dominio/storage/logs/mail.log` → **403**.
- `https://tu-dominio/cron/auto_validar_partidos.php` sin secreto → **403**.
- Cron remoto: `.../cron/auto_validar_partidos.php?cron_secret=TU_SECRETO`.

## Git

- `.gitignore` cubre `.env`, volcados SQL, `config/mail.local.php`.
- Repo **privado**; activar alertas de secretos en GitHub.
- Checklist local: `php scripts/predeploy-check.php`
- CI: workflow `secrets-guard.yml` en cada push.
- Despliegue FTP (InfinityFree, etc.): workflow `deploy-ftps.yml` + secretos `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, opcional `FTP_SERVER_DIR`.

### Crear repo privado en GitHub (primera vez)

```powershell
cd ruta\liga_tenis_cursor
git init -b main
git add -A
git status   # confirmar que NO aparece .env
php scripts/predeploy-check.php
git commit -m "Preproducción: endurecimiento seguridad y docs deploy"
gh repo create volea-de-reves --private --source=. --push
```

(Sustituye el nombre del repo. Si no tienes `gh`, crea el repo vacío en github.com y `git remote add origin …` + `git push -u origin main`.)

## Si filtraste un secreto

1. Rotar credencial en el proveedor (SMTP, MySQL, admin).
2. Si estuvo en Git: considerar historial (`git filter-repo`) o repo nuevo + rotación obligatoria.
