# XEDIK Contact Suite

<div align="center">

![XEDIK Logo](public/images/xedik-logo.svg)

### Plataforma Omnicanal de Contact Center, Telefonía WebRTC & CRM Inteligente

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel Framework](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Database](https://img.shields.io/badge/Database-MariaDB%20%7C%20MySQL-003545?style=flat-square&logo=mariadb&logoColor=white)](https://mariadb.org)
[![WebRTC](https://img.shields.io/badge/Telephony-WebRTC%20%2F%20Asterisk-E36209?style=flat-square&logo=asterisk&logoColor=white)](https://asterisk.org)
[![Storage](https://img.shields.io/badge/Storage-DigitalOcean%20Spaces-0080FF?style=flat-square&logo=digitalocean&logoColor=white)](https://www.digitalocean.com/products/spaces)

</div>

---

## 🚀 Acerca de XEDIK Contact Suite

**XEDIK Contact Suite** es una solución empresarial de Contact Center omnicanal y CRM diseñada para gestionar operaciones de atención, ventas y cobranza a gran escala. Integra telefonía basada en navegador (WebRTC), mensajería conversacional, marcación predictiva/preview, y automatización con IA en una interfaz unificada y de alto rendimiento.

---

## 🌟 Módulos y Capacidades Principales

### 🎧 1. Consola de Agente Omnicanal
* **Softphone WebRTC Integrado:** Realiza y recibe llamadas directamente desde el navegador (JsSIP / SIP sobre WSS) sin necesidad de software externo ni teléfonos IP físicos.
* **Bandeja Unificada de Conversaciones:** Atención centralizada de mensajes entrantes con historial completo del cliente.
* **Control de Estados del Agente:** Disponible, En llamada, Wrap-up (tipificación) y pausas parametrizadas.

### 📞 2. Telefonía & Motor Asterisk
* **Asterisk Realtime:** Gestión dinámica de endpoints SIP, autenticaciones (`ps_endpoints`, `ps_auths`) y colas de atención directamente desde la base de datos.
* **Reproductor de Grabaciones:** Almacenamiento seguro en **DigitalOcean Spaces / S3** con generación de URLs presignadas temporales para auditoría y QA.
* **Supervisor en Tiempo Real:** Monitor de agentes con detección de latidos (heartbeats), estados activos y tiempos de sesión.

### 💬 3. Canales y Mensajería Digital
* **WhatsApp Cloud API:** Cuentas empresariales oficiales de Meta, plantillas interactivas y webhooks en tiempo real.
* **SMS Gateway:** Soporte multiproveedor (DIDWW, Twilio, etc.) para envío masivo, números dedicados y respuestas bidireccionales.
* **Correo Electrónico (IMAP/SMTP):** Lectura periódica de buzones y envío autenticado con firmas corporativas.
* **Webchat Widget:** Widget personalizable para sitios web con soporte de flujos automatizados.
* **Redes Sociales:** Integración con Instagram y Facebook Messenger.

### ⚡ 4. Marcador Preview & Automatización
* **Campañas de Marcación:** Segmentación de listas de contactos con filtros avanzados.
* **Listas DNC (Do Not Call):** Protección y cumplimiento normativo anti-spam.
* **Tipificaciones (Dispositions):** Reglas automáticas para reintentos de llamada según resultado (Ocupado, Buzón, Contacto Efectivo).
* **Guiones de Llamada (Scripts):** Plantillas dinámicas de argumentarios para agentes.

### 📊 5. Reportes & Analítica
* **CDR Detallado:** Registro exhaustivo de llamadas con duración, costo, troncal, agente y grabación.
* **Reportes de Conversaciones:** Métricas de tiempo de primera respuesta (FRT) y resolución.
* **Auditoría de Agentes:** Historial de pausas, tiempos de conexión y métricas de productividad.

---

## 🛠️ Stack Tecnológico

* **Backend:** PHP 8.2+, Laravel 11.x
* **Base de Datos:** MariaDB 10.11+ / MySQL 8.0
* **Tiempo Real:** Pusher / Laravel WebSockets
* **Almacenamiento de Objetos:** DigitalOcean Spaces (S3 Compatible)
* **Telefonía / VoIP:** Asterisk PBX (PJSIP / WebRTC / WSS)
* **Automatización:** n8n Webhook Engine & Zernio API

---

## 📋 Requisitos del Servidor

* **PHP:** >= 8.2 con extensiones:
  * `pdo_mysql`, `curl`, `mbstring`, `openssl`, `xml`, `zip`, `bcmath`, `fileinfo`
* **Servidor Web:** Nginx o Apache con soporte de URL rewrite
* **Base de Datos:** MariaDB 10.6+ o MySQL 8.0+
* **Composer:** 2.x
* **Node.js & NPM:** (Requerido únicamente para compilación de assets si aplica)

---

## ⚙️ Instalación y Puesta en Marcha

### 1. Clonar el repositorio
```bash
git clone https://github.com/TU_USUARIO/CallCenterIA.git
cd CallCenterIA
```

### 2. Instalar dependencias de PHP
```bash
composer install --no-dev --optimize-autoloader
```

### 3. Configurar variables de entorno
```bash
cp .env.example .env
php artisan key:generate
```
> Edita el archivo `.env` con los accesos de tu base de datos MariaDB, credenciales de DigitalOcean Spaces, Pusher y VoIP.

### 4. Ejecutar migraciones y datos iniciales
```bash
php artisan migrate --force
php artisan db:seed --class=PermissionsSeeder
```

### 5. Enlace simbólico de almacenamiento
```bash
php artisan storage:link
```

### 6. Permisos de carpetas (Linux / VPS)
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

---

## ⏱️ Servicios en Segundo Plano (Producción)

### 1. Programador de Tareas (Cron)
Agrega la siguiente línea al crontab del servidor (`crontab -e`):
```bash
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Procesador de Colas (Supervisor)
Crea un archivo de configuración en `/etc/supervisor/conf.d/xedik-worker.conf`:
```ini
[program:xedik-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /ruta/al/proyecto/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/ruta/al/proyecto/storage/logs/worker.log
```
Luego actualiza Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

---

## 🔒 Seguridad y Buenas Prácticas

* **Variables protegidas:** Nunca confirmes archivos `.env` en el control de versiones.
* **Permisos mínimos:** Asegúrate de que las credenciales de base de datos otorguen únicamente los privilegios necesarios.
* **SSL / TLS:** Habilita HTTPS de extremo a extremo mediante Let's Encrypt / Certbot tanto para el panel web como para el servidor WebSocket de voz (WSS 8089).

---

## 📄 Licencia

Este software es propiedad privada y confidencial de **XEDIK**. Todos los derechos reservados.
Queda prohibida su copia, distribución o modificación no autorizada.
