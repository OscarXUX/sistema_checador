# Cabeceras de seguridad y política de contenido (CSP)

> Parche · 18/11/2026 · Resuelve la parte de cabeceras de SE-08 de docs/auditorias/2026-11-17/11-15_AUDITORIA_SISTEMA_CHECADOR_VS_GUIA_SEGURIDAD.md. La configuración del `.env` de producción sigue pendiente (depende de TE-06).

---

## 1. Contexto / Causa

| Problema | Causa |
|---|---|
| Sin `nosniff`, `X-Frame-Options`, `Referrer-Policy`, CSP ni HSTS | No había middleware de cabeceras (`bootstrap/app.php` vacío) |
| `X-Powered-By: PHP/8.x` revelaba la versión | Configuración por defecto de PHP |

---

## 2. Fix aplicado

Nuevo `App\Http\Middleware\SecurityHeaders`, agregado al grupo `web` en `bootstrap/app.php`:

| Cabecera | Valor |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains`, **solo** cuando la petición llega por HTTPS |
| `Cache-Control` | `no-store, private` en páginas con sesión iniciada (datos personales) |
| `X-Powered-By` | Se quita |
| `Content-Security-Policy` | Ver tabla siguiente |

| Directiva CSP | Valor | Por qué |
|---|---|---|
| `script-src` | `'self' 'nonce-…'` | Solo scripts propios y los bloques `<script>` de las vistas que llevan el nonce de la petición |
| `style-src` | `'self' 'unsafe-inline'` | Bootstrap y Wijmo ponen estilos en línea; el riesgo es bajo comparado con scripts |
| `img-src`, `font-src` | `'self' data:` | Íconos y fuentes de Bootstrap Icons |
| `connect-src` | `'self'` | Las peticiones `fetch` solo van al propio sistema |
| `object-src` | `'none'` | Sin plugins |
| `base-uri`, `form-action`, `frame-ancestors` | `'self'` | Evitan cambiar la base de URLs, enviar formularios a otro sitio o incrustar el sistema en otro |

| Detalle | Cómo quedó |
|---|---|
| Nonce | `Vite::useCspNonce()` en el middleware; las cuatro vistas con `<script>` usan `<script nonce="{{ Vite::cspNonce() }}">` |
| `npm run dev` | Si existe `public/hot`, la CSP agrega el servidor de Vite (y su `ws://`) |
| Vite en IPv4 | `vite.config.js` fija `server.host = '127.0.0.1'`, porque la CSP no acepta direcciones IPv6 como `[::1]` |

Se probó en Chrome (sin ventana), con assets compilados y con `npm run dev`: Dashboard (tabla y dos gráficas), Empleados (tabla y formulario de edición), Kardex y Turnos cargan sin violaciones de CSP, y un `<script>` inyectado sin nonce queda bloqueado.

---

## 3. Archivos afectados

| Tipo | Archivos |
|---|---|
| Nuevos | `app/Http/Middleware/SecurityHeaders.php`, `tests/Feature/SecurityHeadersTest.php`, este documento |
| Modificados | `bootstrap/app.php`, `vite.config.js`, `resources/views/dashboard.blade.php`, `resources/views/employees/index.blade.php`, `resources/views/reports/kardex.blade.php`, `resources/views/shifts/index.blade.php` (nonce) |

---

## 4. Pasos manuales

1. `git pull`
2. No hay migraciones (las de este día son del parche de RFC, CURP y NSS).
3. `php artisan optimize:clear`
4. Si usas `npm run dev`, ciérralo y vuelve a abrirlo (cambió `vite.config.js`).

---

## 5. Cómo verificar

1. `php artisan test`: deben pasar las **75** (4 nuevas en `SecurityHeadersTest`).
2. F12 → Red → cualquier página → Encabezados de respuesta: aparecen `Content-Security-Policy`, `X-Frame-Options` y las demás; no aparece `X-Powered-By`.
3. F12 → Consola: sin mensajes de "Content Security Policy" al navegar por Dashboard, Empleados, Kardex y Turnos.

---

## 6. Notas / deuda técnica

| Prioridad | Pendiente | Detalle |
|---|---|---|
| 🟡 Media | Resto de SE-08 | En producción: `APP_DEBUG=false`, `APP_ENV=production`, HTTPS, `SESSION_SECURE_COOKIE=true`. Va en la guía de despliegue (TE-06) |
| 🟢 Baja | Página nueva con `<script>` | Debe llevar `nonce="{{ Vite::cspNonce() }}"`; si no, el navegador lo bloquea (se ve en la consola) |
| 🟢 Baja | `preload` de HSTS | Solo cuando todo el dominio esté en HTTPS |
