# Sistema checador: instalación y arranque

> Guía · actualizado 18/11/2026 · Para levantar el proyecto en una computadora con Windows. La documentación del proyecto está en `docs/` (empieza por `docs/CONTEXTO_SISTEMA_CHECADOR.md`).

---

## 1. Requisitos

| Herramienta | Versión | Para qué | Cómo revisarla |
|---|---|---|---|
| PHP | 8.2 o más reciente (el de XAMPP sirve) | Correr Laravel | `php -v` |
| Composer | 2.x | Dependencias de PHP | `composer -V` |
| Node.js | 20 LTS o más reciente | Compilar CSS y JS con Vite | `node -v` |
| npm | El que trae Node | Dependencias de JS | `npm -v` |
| MySQL | 8.x (o el MariaDB de XAMPP) | Base de datos | En DBeaver, o `mysql --version` |
| DBeaver | Cualquiera reciente | Crear y revisar la base | — |
| Git | 2.x | Clonar y subir cambios | `git --version` |

PHP necesita estas extensiones. Revísalas con `php -m`:

```text
ctype, curl, dom, fileinfo, gd, mbstring, openssl, pdo_mysql, pdo_sqlite, session, tokenizer, xml, zip
```

Si falta alguna en XAMPP, abre `C:\xampp\php\php.ini`, quita el `;` de la línea `extension=nombre` y abre una terminal nueva. `pdo_sqlite` solo se usa en las pruebas; `gd` sirve para procesar las fotos de los empleados.

> Usa la misma versión mayor de Node que el resto del equipo. Si no, `npm install` modifica `package-lock.json` (agrega líneas `"peer": true`).

---

## 2. Instalación (una sola vez)

Todos los comandos son para PowerShell y se corren dentro de la carpeta del proyecto.

### 2.1 Descargar el proyecto e instalar dependencias

```powershell
git clone https://github.com/OscarXUX/sistema_checador.git
cd sistema_checador
composer install
npm install
copy .env.example .env
php artisan key:generate
```

### 2.2 Crear la base de datos en DBeaver

1. Conéctate a tu MySQL en `localhost:3306` (doble clic en la conexión).
2. Abre un editor SQL (Ctrl + ]) y ejecuta:

```sql
CREATE DATABASE sistema_checador CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

3. Selecciona la conexión y presiona F5: debe aparecer `sistema_checador`.

### 2.3 Configurar el `.env`

Abre `.env` y revisa estas variables. Nunca subas el `.env` al repositorio. **No cambies `APP_KEY` después de capturar empleados**: RFC, CURP y NSS se cifran con ella.

```env
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistema_checador
DB_USERNAME=root
DB_PASSWORD=            # la de tu MySQL (la misma que usas en DBeaver)

SESSION_DRIVER=database
SESSION_LIFETIME=30

HIKVISION_URL=http://192.168.0.69   # IP de la terminal en su red
HIKVISION_USER=admin
HIKVISION_PASSWORD=                 # la del aparato
```

### 2.4 Crear las tablas y tu cuenta

```powershell
php artisan migrate
php artisan usuarios:crear
```

`usuarios:crear` pide nombre, correo y contraseña. La contraseña debe tener **15 caracteres o más** y puede ser una frase (por ejemplo `mi cafe de la manana es doble`). No hay registro desde la página ni una cuenta de fábrica: sin este paso no puedes entrar.

### 2.5 Compilar el CSS y JS y correr las pruebas

```powershell
npm run build
php artisan test
```

Deben pasar todas las pruebas. Si fallan las de páginas con "Vite manifest not found", te faltó `npm run build`.

---

## 3. Levantar el proyecto (cada vez que trabajes)

Abre **dos terminales** en la carpeta del proyecto, y una tercera si quieres la sincronización automática con la terminal:

| Terminal | Comando | Para qué |
|---|---|---|
| 1 | `php artisan serve` | La aplicación |
| 2 | `npm run dev` | CSS y JS con recarga automática al guardar |
| 3 (opcional) | `php artisan schedule:work` | Descarga las checadas de la terminal cada minuto |

Abre **http://127.0.0.1:8000** e inicia sesión con la cuenta que creaste.

> La URL que muestra `npm run dev` (puerto 5173) **no** es la aplicación; solo sirve los archivos de Vite. Si prefieres no tener la terminal 2 abierta, usa `npm run build` (hay que repetirlo cada vez que cambies CSS o JS).

---

## 4. Después de hacer `git pull`

```powershell
composer install
npm install
php artisan migrate
php artisan optimize:clear
npm run build
```

Revisa también el parche más reciente en `docs/parches/`: su sección "Pasos manuales" dice si hay algo extra (por ejemplo, un cambio en el `.env`).

---

## 5. Cuentas de usuario

| Comando | Qué hace |
|---|---|
| `php artisan usuarios:crear` | Crea una cuenta con perfil Administrador |
| `php artisan usuarios:crear --perfil=Consulta` | Crea una cuenta con otro perfil |
| `php artisan permisos:sincronizar` | Carga en la base los permisos nuevos del código |
| `php artisan usuarios:restablecer correo@ejemplo.com` | Cambia la contraseña y cierra las sesiones abiertas de esa cuenta |
| `php artisan usuarios:estado correo@ejemplo.com inactivo` | Desactiva la cuenta sin borrarla y cierra sus sesiones |
| `php artisan usuarios:estado correo@ejemplo.com activo` | La vuelve a activar |

---

Después de la primera cuenta, lo más cómodo es crear las demás en **Administración → Usuarios** (menú lateral, solo administradores), donde también se eligen perfiles y permisos.

---

## 6. Terminal Hikvision

| Dato | Detalle |
|---|---|
| Modelo | DS-K1T804AEF, protocolo ISAPI |
| Conexión | Solo responde dentro de su red local. Fuera de esa red, el dashboard muestra "Offline" y es normal |
| Prueba rápida | Abre `HIKVISION_URL` en el navegador: si aparece el login de Hikvision, el aparato es alcanzable |
| En el sistema | Barra lateral → "Probar Conectividad" |
| Sincronizar a mano | `php artisan hikvision:sync` descarga lo nuevo desde la última vez; `--from=2026-11-01 --to=2026-11-18` descarga un rango completo |
| Checadas de alguien recién registrado | Botón "Sincronizar" (día completo) o `--from` con la fecha |

---

## 7. Problemas comunes

| Síntoma | Causa | Solución |
|---|---|---|
| `php artisan test` falla con 419 o dice "Pruebas detenidas para no borrar tu base de datos real" | La configuración quedó en caché (`php artisan optimize` o `config:cache`) | `php artisan optimize:clear` y volver a correr las pruebas. En local no uses `optimize` |
| Error 500 "Vite manifest not found" | No se han compilado los assets | `npm run build` o tener `npm run dev` abierto |
| En el puerto 5173 sale una página de Laravel + Vite | Es el servidor de Vite, no la aplicación | Abre la URL de `php artisan serve` (puerto 8000) |
| `Access denied for user` al migrar | Usuario o contraseña del `.env` distintos a los de DBeaver | Copia los datos de la conexión (DBeaver → clic derecho → Editar conexión) |
| `Unknown database 'sistema_checador'` | La base no existe o se creó en otra conexión | Repite el paso 2.2 en `localhost:3306` |
| `Connection refused` al migrar | MySQL apagado | Inicia el servicio MySQL (Servicios de Windows o panel de XAMPP) |
| La terminal aparece "Offline" | No estás en la red del aparato, IP equivocada o falta `HIKVISION_PASSWORD` | Revisa la sección 6 |
| "Correo o contraseña incorrectos" con datos correctos | Cuenta inactiva | `php artisan usuarios:estado tu@correo activo` |
| "Demasiados intentos" | 5 intentos fallidos en un minuto | Espera un minuto o restablece la contraseña |
| "Tu sesión expiró" | 30 minutos sin actividad | Vuelve a iniciar sesión |
| "Ya hay una sincronización en curso" | La tarea programada u otro usuario está sincronizando | Espera unos segundos y vuelve a intentar |
| Marca de agua de Wijmo arriba a la derecha | Wijmo corre en modo evaluación | Pendiente de licencia (TE-03 en `docs/pendientes/teracrom/`) |
| En la consola sale "violates the following Content Security Policy" | Un `<script>` sin nonce o un recurso de otro dominio | Agregar `nonce="{{ Vite::cspNonce() }}"` al `<script>`; si usas `npm run dev`, reinícialo |
| La búsqueda no encuentra un RFC | RFC y CURP están cifrados: solo se encuentran completos y exactos | Escribe el RFC o CURP completo |
| `package-lock.json` aparece modificado sin cambios reales | Versión de npm distinta | `git checkout -- package-lock.json` o subirlo; usar la misma versión de Node que el equipo |

---

## 8. Documentación

| Qué | Dónde |
|---|---|
| Qué es el sistema, tablas, módulos y decisiones | `docs/CONTEXTO_SISTEMA_CHECADOR.md` |
| Índice de la carpeta de documentación | `docs/README.md` |
| Cambios aplicados, uno por documento | `docs/parches/` |
| Lo que falta, por apartado | `docs/pendientes/` |
