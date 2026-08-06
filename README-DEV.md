# Guía de entorno de desarrollo local (Windows + XAMPP)

Onboarding para cualquier dev nuevo del equipo en **Sintelc FlowTime**. Sigue las secciones en orden — cada una tiene un checkpoint antes de avanzar a la siguiente.

Para entender la arquitectura del proyecto (flujo de datos, multi-tenancy, endpoints ZKTeco, etc.) lee [`CLAUDE.md`](CLAUDE.md) una vez tengas el entorno levantado.

## Requisitos previos

- Windows con [XAMPP](https://www.apachefriends.org/) instalado, con **PHP 8.2 o superior** (Laravel 12 exige `^8.2`).
- Acceso al repositorio privado [`alopezl1805ss-bit/sintelc-timeflow`](https://github.com/alopezl1805ss-bit/sintelc-timeflow) en GitHub.

## 1. Verifica la versión de PHP de tu XAMPP

```
C:\xampp\php\php.exe -v
```

Debe decir `PHP 8.2.x` o superior. Si tu XAMPP trae una versión más vieja, descarga un build de XAMPP con PHP 8.2+ desde apachefriends.org — no intentes usar una versión anterior.

## 2. Instala Git, Composer y Node.js

| Herramienta | Descarga | Verificación |
|---|---|---|
| Git | [git-scm.com/download/win](https://git-scm.com/download/win) (opciones por defecto) | `git --version` |
| Composer | [getcomposer.org](https://getcomposer.org) (detecta solo tu `php.exe` de XAMPP) | `composer -V` |
| Node.js LTS | [nodejs.org](https://nodejs.org) | `node -v` y `npm -v` |

Cierra y vuelve a abrir la terminal después de cada instalación para que el `PATH` se actualice.

> **⚠️ Problema común: `npm` da error de "ejecución de scripts deshabilitada"**
> Si `npm -v` falla con `PSSecurityException`, es la política de ejecución de PowerShell bloqueando el script `.ps1` de npm. Arréglalo una sola vez:
> ```powershell
> Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
> ```
> Confirma con `S` cuando pregunte.

## 3. (Recomendado) Excluye tu carpeta de proyectos del antivirus

Windows Defender escanea en tiempo real cada archivo que Composer/npm tocan, lo que hace `composer install`, `npm install` y el hot-reload de Vite mucho más lentos de lo normal. Vale la pena excluirlo una vez:

**Seguridad de Windows → Protección contra virus y amenazas → Administrar configuración → Exclusiones → Agregar una exclusión → Carpeta** → selecciona tu carpeta de proyectos (ej. `C:\dev`).

## 4. Prende MySQL en XAMPP y crea la base de datos

1. Abre el **XAMPP Control Panel** → Start solo en el módulo **MySQL** (Apache no hace falta, el proyecto usa `php artisan serve`).
2. Clic en **Admin** junto a MySQL → se abre phpMyAdmin.
3. Pestaña **SQL** → corre:
   ```sql
   CREATE DATABASE sintelcft_dev;
   ```

## 5. Clona el repositorio

```powershell
mkdir C:\dev
cd C:\dev
git clone https://github.com/alopezl1805ss-bit/sintelc-timeflow.git
cd sintelc-timeflow
```

Si te pide login, usa tu cuenta de GitHub con acceso al repo.

Para arrancar a trabajar directamente sobre la rama de desarrollo compartida en vez de `main`:

```powershell
git checkout local-dev
```

(ver la sección [Git — cómo trabajar sin tocar producción](#11-git--cómo-trabajar-sin-tocar-producción) más abajo).

## 6. Configura tu `.env` local

```powershell
copy .env.example .env
notepad .env
```

Asegúrate de que estas líneas queden así (**nunca copies aquí credenciales reales de producción**):

```env
APP_NAME="Sintelc FlowTime (DEV)"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sintelcft_dev
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
CACHE_STORE=database

# Vacío hasta tener credenciales de sandbox de Factorial — ver sección 10
FACTORIAL_BASE_URL=
FACTORIAL_CLIENT_ID=
FACTORIAL_CLIENT_SECRET=
FACTORIAL_REDIRECT_URI=http://localhost:8000/oauth/factorial/callback
```

> `DB_PORT=3306` es el puerto por defecto de MySQL en XAMPP local. No lo confundas con el puerto `3307` que se usa para acceder a la base de datos de **producción** (ver `CLAUDE.md`) — son bases de datos completamente distintas.

## 7. Instala dependencias y corre migraciones

```powershell
composer run setup
```

Esto corre `composer install` → copia `.env` → `key:generate` → `migrate --force` → `npm install` → `npm run build`.

> **⚠️ No presiones Ctrl+C mientras corre.**
> Si lo interrumpes durante "Generating optimized autoload files", corrompe `vendor/autoload.php` y todo falla después con `Failed opening required vendor/autoload.php`. Si eso pasa:
> ```powershell
> Remove-Item -Recurse -Force vendor
> composer install
> ```
> y esta vez espera a que regrese solo al prompt, sin importar cuánto tarde (puede ser 1-3 min).

> **⚠️ Problema conocido: `composer.lock` pide PHP 8.4**
> Si al correr `composer install` ves errores tipo `symfony/... requires php >=8.4 - your php version (8.2.x) does not satisfy that requirement`, significa que el `composer.lock` del repo se generó en una máquina con PHP 8.4. Arréglalo con:
> ```powershell
> composer update
> ```
> Esto regenera el lock respetando el `^8.2` que pide `composer.json`. (Si esto te pasa, vale la pena avisar al equipo — puede afectar el próximo deploy si producción sigue en PHP 8.2.)

> **✅ Ya corregido en `local-dev`: falla la migración `drop_vendor_from_biometric_sources`**
> En versiones anteriores del repo, esta migración fallaba con `Cannot drop index 'biometric_sources_client_id_vendor_index' needed in a foreign key constraint` — el índice que borraba era el único que respaldaba la llave foránea de `client_id`. Ya está corregido en `database/migrations/2026_05_25_104829_drop_vendor_from_biometric_sources.php` dentro de la rama `local-dev` (crea un índice de reemplazo antes de borrar el original). Si clonaste desde `main` y todavía no se ha fundido ese fix, y ves este error, cambia a `local-dev` o aplica el mismo cambio a mano.

**✅ Checkpoint:**

```powershell
Test-Path node_modules\.bin\vite.cmd
```

Debe dar `True`.

## 8. Levanta el proyecto (3 terminales, **NO** `composer run dev`)

`composer run dev` usa el paquete `pail` (visor de logs), que requiere la extensión `pcntl` de PHP — esa extensión no existe en Windows, así que ese script siempre va a fallar y tumbar todo lo demás en cadena. En vez de eso, abre 3 terminales (pestañas de VSCode o ventanas de PowerShell), todas en `C:\dev\sintelc-timeflow`:

**Terminal 1**
```powershell
php artisan serve
```

**Terminal 2**
```powershell
php artisan queue:listen --tries=1 --timeout=0
```

**Terminal 3**
```powershell
npm run dev
```

Si necesitas ver los logs de errores (lo que hacía `pail`):
```powershell
Get-Content storage\logs\laravel.log -Wait -Tail 20
```

## 9. Crea tu usuario de prueba

La base está vacía, sin seeders de usuario. Crea el tuyo:

```powershell
php artisan tinker
```
```php
App\Models\User::create(['name' => 'Tu Nombre', 'email' => 'tucorreo@dev.local', 'password' => bcrypt('password123')]);
exit
```

**✅ Checkpoint final:** entra a `http://localhost:8000/login` (¡sin duplicar el `http://`!) y entra con esas credenciales.

## 10. Factorial — no uses las credenciales de producción

Las variables `FACTORIAL_CLIENT_ID`/`FACTORIAL_CLIENT_SECRET` de producción **nunca** deben ir en un `.env` local. Pide al equipo/soporte de Factorial credenciales de un entorno de prueba (sandbox/demo, ej. `api.eu2.demo.factorial.dev`) o una empresa de prueba separada, y regístralas ahí solo cuando las tengas.

## 11. Git — cómo trabajar sin tocar producción

Producción solo cambia cuando alguien hace `git pull` + deploy manualmente en el servidor (ver `CLAUDE.md`). Nada de lo que hagas en tu rama local o en GitHub la afecta hasta ese paso.

Este repo usa una rama compartida **`local-dev`** para ir acumulando el trabajo hecho en entorno de desarrollo local antes de pasarlo a producción. Flujo recomendado:

```powershell
git fetch origin
git checkout local-dev
git pull origin local-dev
```

Guarda avances normalmente directo en `local-dev`:

```powershell
git add .
git commit -m "descripción del cambio"
git push
```

Si vas a trabajar en algo grande o arriesgado, crea una rama propia a partir de `local-dev` en vez de commitear directo ahí:

```powershell
git checkout -b dev/nombre-de-tu-cambio
git push -u origin dev/nombre-de-tu-cambio
```
y cuando esté listo, ábrele un PR hacia `local-dev`.

Cuando lo que hay en `local-dev` esté probado y listo para producción, se abre un PR de `local-dev` → `main`. El deploy a producción sigue siendo un paso manual aparte en el servidor (ver `CLAUDE.md`, sección *Production*).

## 12. Claude Code local (opcional pero recomendado)

```powershell
npm install -g @anthropic-ai/claude-code
```

Desde `C:\dev\sintelc-timeflow`:

```powershell
claude
```

Lee automáticamente el `CLAUDE.md` del repo — ya trae todo el contexto de arquitectura del proyecto. No tiene acceso a producción a menos que tú le des credenciales SSH explícitamente (no lo hagas).

## Comandos útiles del día a día

```bash
# Tests
php artisan test
php artisan test --filter=TestClassName

# Resolver logs de asistencia que llegaron sin empleado mapeado
php artisan attendance:resolve-pending

# Sincronizar empleados/ubicaciones de Factorial para una conexión
php artisan factorial:sync-employees
php artisan factorial:sync-locations

# Enviar usuarios de Factorial a un dispositivo ZKTeco
php artisan biometric:push-users
```

Ver [`CLAUDE.md`](CLAUDE.md) para el detalle completo de arquitectura, flujo de datos y comandos post-deploy.
