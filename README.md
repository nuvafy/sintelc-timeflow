<div align="center">

<img src="public/favicon.svg" width="60" alt="Sintelc FlowTime Logo"/>

# Sintelc FlowTime

**Middleware de asistencia biométrica → Factorial HR**

[![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square&logo=livewire&logoColor=white)](https://livewire.laravel.com)
[![Deploy](https://img.shields.io/badge/AWS-EC2%20t3.micro-FF9900?style=flat-square&logo=amazon-aws&logoColor=white)](https://aws.amazon.com)

</div>

---

## ¿Qué es?

Sintelc FlowTime recibe marcaciones de asistencia desde dispositivos biométricos **ZKTeco** y las sincroniza automáticamente con la API de **Factorial HR**. Es multi-tenant: un solo servidor atiende múltiples empresas cliente, cada una con su propia conexión OAuth a Factorial.

## Flujo de datos

```
Dispositivo ZKTeco
    └─► POST /iclock/cdata?table=ATTLOG
            └─► AttendanceLog::insert()
                    └─► SyncAttendanceToFactorial (Job)
                            ├─► FactorialService::clockIn() / clockOut()
                            └─► fallback: FactorialService::updateShift()
```

Los dispositivos hacen polling de comandos cada pocos segundos via `GET /iclock/getrequest`. El servidor responde con instrucciones como `DATA UPDATE USERINFO` para sincronizar empleados al biométrico.

## Características

- **Multi-tenant** — clientes aislados, cada uno con sus dispositivos y conexión Factorial
- **Protocolo iClock/PUSH** — compatible con la mayoría de dispositivos ZKTeco
- **Empleados locales** — alta de empleados solo en el sistema (sin Factorial), con push automático a todos los biométricos
- **Mapeo de PINs** — conecta el código del dispositivo con el empleado de Factorial
- **Reporte de asistencia** — exportación Excel con horas trabajadas, descansos y estado por empleado
- **Dashboard en tiempo real** — métricas de sincronización con gráficas Chart.js
- **Comandos en cola** — envío de usuarios al dispositivo con seguimiento de estado

## Tecnologías

| Capa | Tecnología |
|------|-----------|
| Backend | Laravel 12, PHP 8.2 |
| Frontend | Livewire Volt, Alpine.js, Tailwind CSS |
| Queue | Database queue driver, Supervisor |
| Cache | Database cache |
| Infraestructura | AWS EC2 t3.micro, Nginx, Cloudflare |

## Instalación local

```bash
git clone https://github.com/nuvafy/sintelc-timeflow.git
cd sintelc-timeflow

composer install
npm install

cp .env.example .env
php artisan key:generate

# Configurar DB, Factorial OAuth, etc. en .env
php artisan migrate

php artisan serve
npm run dev

# En otra terminal — worker de colas (requerido para sync)
php artisan queue:work --sleep=1 --tries=1
```

## Comandos útiles

```bash
# Resolver logs pendientes sin mapeo de empleado
php artisan attendance:resolve-pending

# Sincronizar empleados y ubicaciones desde Factorial
php artisan factorial:sync-employees
php artisan factorial:sync-locations

# Enviar usuarios a dispositivo biométrico
php artisan biometric:push-users

# Después de cada deploy en producción
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Estados de un registro de asistencia

| Estado | Descripción |
|--------|-------------|
| `pending` | Llegó pero el empleado no está mapeado aún |
| `resolved` | Mapeado, job despachado, pendiente de procesar |
| `synced` | Enviado exitosamente a Factorial |
| `failed` | Factorial lo rechazó (ver `sync_error`) |
| `local` | Empleado solo existe en el sistema, no va a Factorial |

## Producción

- **URL:** `app.sintelcft.dev`
- **Servidor:** AWS EC2 t3.micro — `us-east-2`
- **App root:** `/var/www/sintelcft`

---

<div align="center">
  <sub>Desarrollado por <a href="https://nuvafy.com">Nuvafy</a> para Sintelc</sub>
</div>
