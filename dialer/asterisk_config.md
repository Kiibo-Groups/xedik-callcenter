# Configuración Asterisk — Power Dialer XEDIK

Estos archivos van en el **servidor Asterisk**, NO en Hostinger.

## /etc/asterisk/ari.conf

```ini
[general]
enabled = yes
pretty = yes
allowed_origins = *

[marcador]
type = user
password = marcador_secret_2024
read_only = no
```

## /etc/asterisk/extensions.conf

```ini
; Contexto de simulación — no sale a PSTN, ideal para pruebas
[marcador_simulado]
exten => _X.,1,NoOp(Simulando llamada a ${EXTEN})
 same => n,Answer()
 same => n,Wait(1)
 same => n,Playback(demo-congrats)
 same => n,Wait(8)
 same => n,Hangup()

; Contexto de producción — entrega el canal a la app ARI
[marcador_power]
exten => _X.,1,Stasis(marcador_power,${EXTEN})
 same => n,Hangup()
```

## Servicio systemd — /etc/systemd/system/power-dialer.service

```ini
[Unit]
Description=XEDIK Power Dialer
After=network.target asterisk.service

[Service]
Type=simple
User=asterisk
WorkingDirectory=/opt/XEDIK/dialer
ExecStart=/usr/bin/python3 power_dialer.py
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

## Comandos de instalación

```bash
# Instalar dependencias Python
pip3 install requests websocket-client pymysql

# Copiar archivos
cp power_dialer.py config.py /opt/XEDIK/dialer/

# Editar config.py con credenciales reales de DB

# Activar servicio
systemctl enable power-dialer
systemctl start power-dialer
systemctl status power-dialer

# Ver logs en tiempo real
journalctl -u power-dialer -f
```

## Verificar ARI activo en Asterisk

```bash
asterisk -rx "ari show apps"
asterisk -rx "ari show status"
```
