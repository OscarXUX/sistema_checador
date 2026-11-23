# Guía de despliegue a producción

> Despliegue · 22/11/2026 · Complementa la parte técnica de SE-08 y OP-09 antes de recibir los datos del servidor definitivo (TE-06).

---

## 1. Requisitos del servidor

- PHP `>= 8.2` con extensiones: `ctype, curl, dom, fileinfo, mbstring, openssl, pdo_mysql, session, tokenizer, xml, zip` y **OPcache** habilitado (`opcache.enable=1`, `opcache.validate_timestamps=0` en producción tras cada despliegue).
- MySQL 8 o MariaDB en el mismo servidor o red interna, conectado en la misma VLAN/red local que la terminal Hikvision DS-K1T804AEF (`TE-04` / `TE-06`).
- Node.js 20 LTS para compilar assets (puede hacerse en build/CI o en el servidor).
- Certificado TLS (HTTPS) para servir la aplicación web (`SESSION_SECURE_COOKIE=true`).

---

## 2. Variables de entorno (`.env`)

Partir de `.env.production.example` (no usar `.env.example` de desarrollo ni copiar un `.env` con `APP_DEBUG=true`):

```powershell
copy .env.production.example .env
php artisan key:generate
```

> [!IMPORTANT]
> Una vez capturados empleados reales, **no cambiar `APP_KEY`**: RFC, CURP y NSS están cifrados con esa llave (`SE-05`). Hacer respaldo cifrado aparte de la llave y la base.

Valores clave en producción (`SE-08`):

- `APP_ENV=production`
- `APP_DEBUG=false`
- `LOG_LEVEL=warning`
- `APP_URL=https://tu-dominio-o-ip-interna`
- `SESSION_DRIVER=database`
- `SESSION_LIFETIME=30`
- `SESSION_SECURE_COOKIE=true`
- `HIKVISION_URL=https://...` (si el firmware lo soporta; de lo contrario, red/VLAN aislada entre servidor y terminal, `SE-06`)

---

## 3. Pasos de instalación y cachés (`OP-09`)

```powershell
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan usuarios:crear
php artisan optimize
```

`php artisan optimize` ejecuta y genera en un paso `config:cache`, `event:cache`, `routes:cache` (`route:cache`) y `views:cache` (`view:cache`).

> [!NOTE]
> Cuando cambie `.env` o se actualice el código vía `git pull`, ejecutar `php artisan optimize:clear` seguido de `php artisan optimize` y `npm run build` (si cambiaron assets), además de recargar PHP-FPM / Apache / IIS para limpiar OPcache.

---

## 4. Tarea programada cada minuto (`hikvision:sync`)

### Opción A · Windows (Programador de tareas / Task Scheduler)

Crear una tarea que se ejecute **cada 1 minuto** indefinidamente con la cuenta de servicio:

- **Programa:** `C:\xampp\php\php.exe` (o la ruta real de `php.exe`)
- **Argumentos:** `artisan schedule:run`
- **Iniciar en:** ruta absoluta del proyecto (ej. `C:\inetpub\wwwroot\sistema_checador`)

### Opción B · Linux (`cron`)

```cron
* * * * * cd /var/www/sistema_checador && php artisan schedule:run >> /dev/null 2>&1
```

La definición en `routes/console.php` ya protege la ejecución con `withoutOverlapping()` y el candado compartido `AttendanceSyncCoordinator` (`SE-13`).

---

## 5. Checklist previo a abrir al usuario

1. Verificar que no exista `public/hot` en el servidor.
2. Confirmar cabeceras de seguridad (`Strict-Transport-Security`, `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`) desde el navegador sobre HTTPS.
3. Confirmar `APP_DEBUG=false` y que el archivo `.env` no sea legible desde la web.
4. Ejecutar `composer audit` y `npm audit` sin vulnerabilidades abiertas.
5. Completar pendientes dependientes del cliente: motivos de salida (`TE-01`), aviso de privacidad y consentimiento biométrico (`TE-02`), licencia Wijmo o reemplazo (`TE-03`), roles (`SE-02` / `TE-05`).
