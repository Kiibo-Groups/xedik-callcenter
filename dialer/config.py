# Power Dialer — configuración
# Desplegar en el servidor Asterisk, NO en Hostinger.
# Editar todos los valores antes de correr.

# ── Asterisk ARI ──────────────────────────────────────────────────────────────
ARI_URL  = 'http://localhost:8088'   # Asterisk corre local al dialer
ARI_USER = 'ari_xedik'
ARI_PASS = 'XEDIK$2026$'

# ── MariaDB Hostinger ─────────────────────────────────────────────────────────
DB_HOST = 'localhost'        # TODO: cambiar por host real de Hostinger
DB_PORT = 3306
DB_NAME = 'u617944783_crmxedik'
DB_USER = 'u617944783_crmxedik'    # TODO: confirmar usuario
DB_PASS = 'TU_PASSWORD_AQUI'        # TODO: password real

# ── Comportamiento ────────────────────────────────────────────────────────────
POLL_INTERVAL = 1      # segundos entre ciclos de marcación
RETRY_BACKOFF = 5      # segundos de espera si ARI no responde
LOG_LEVEL     = 'INFO' # DEBUG para ver todos los eventos ARI
