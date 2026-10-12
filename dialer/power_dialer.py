#!/usr/bin/env python3
"""
Power Dialer — xedik CRM v2
Conecta a Asterisk ARI via WebSocket y gestiona marcación concurrente.

Dependencias:
    pip3 install requests websocket-client pymysql

Correr como servicio systemd en el servidor Asterisk.
"""

VERSION = "2.4.0"
# Changelog:
# 1.0.0 — inicial
# 1.1.0 — CAUSE_MAP extendido, duración desde creationtime, filtro canal ;2
# 1.2.0 — conexiones persistentes thread-local (fix max_connections_per_hour)
# 1.3.0 — threading.Event para poll (fix Bad file descriptor), reset phantom rows al arrancar
# 2.0.0 — Queue-based DB worker: exactamente 2 conexiones DB fijas (poll_conn + worker_conn)
# 2.1.0 — ChannelStateChange tracking: detecta "Up" antes de ChannelDestroyed
#          billsec real = tiempo desde answered_at hasta ChannelDestroyed
#          uniqueid de ARI guardado en log table
# 2.2.0 — Correlación PJSIP via CHANNEL(linkedid): fix billsec=0 en contextos con trunk PJSIP
#          PJSIP/awsgw-XXXX no contiene el número — se lee linkedid (=LOCAL;1 uniqueid) via ARI GET
#          _campaign_table_cache eliminado → siempre SELECT fresco (evita tabla stale tras cambio de lead list)
# 2.3.0 — CAUSE_MAP saneado: eliminados NORMAL_CLEARING/UNKNOWN/ANSWER; solo was_answered=True
#          determina ANSWER (cause_txt de LOCAL;1/Stasis no es fiable para PSTN)
# 2.4.0 — Fix definitivo correlación PJSIP: evento Dial reemplaza ChannelCreated+HTTP GET
#          Canales PJSIP no están en Stasis → ARI REST siempre devuelve 404 (no es race condition)
#          Dial event payload contiene caller=LOCAL;2 + peer=PJSIP → correlación sin HTTP, sin threads

import json
import logging
import queue
import threading
import time
import datetime
import requests
import websocket
import pymysql
import pymysql.cursors
from config import (
    ARI_URL, ARI_USER, ARI_PASS,
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS,
    POLL_INTERVAL, RETRY_BACKOFF, LOG_LEVEL,
)

logging.basicConfig(
    level=getattr(logging, LOG_LEVEL),
    format='%(asctime)s [%(levelname)s] %(message)s',
)
log = logging.getLogger('power_dialer')

# ── Estado compartido ─────────────────────────────────────────────────────────
active_channels: dict[int, dict[str, dict]] = {}
state_lock = threading.Lock()
_poll_trigger = threading.Event()

# ── Causa Asterisk → status en dl_X_leads ────────────────────────────────────
CAUSE_MAP = {
    # BUSY — red de seguridad si LOCAL;1 propaga la causa del PSTN
    'BUSY':              'BUSY',
    'USER_BUSY':         'BUSY',
    'USER BUSY':         'BUSY',
    # NO_ANSWER
    'NO_ANSWER':         'NO_ANSWER',
    'NO ANSWER':         'NO_ANSWER',
    'NOANSWER':          'NO_ANSWER',
    'NO_USER_RESPONSE':  'NO_ANSWER',
    'CANCEL':            'NO_ANSWER',
    'ORIGINATOR_CANCEL': 'NO_ANSWER',
    # FAILED — errores técnicos de red/trunk
    'CONGESTION':        'FAILED',
    'CHANUNAVAIL':       'FAILED',
    'FAILURE':           'FAILED',
    # NORMAL CLEARING / UNKNOWN / ANSWER no están aquí intencionalmente.
    # Para LOCAL;1 (Stasis), estas causas significan solo que el canal terminó
    # limpiamente — no indican que el PSTN haya contestado.
    # La única fuente confiable de ANSWER es was_answered=True (ChannelStateChange→Up).
}

def _resolve_status(disposition: str, duration: int, was_answered: bool) -> str:
    """
    Determina el status final del lead.
    Fuente de verdad: was_answered (ChannelStateChange=Up).
    cause_txt de LOCAL;1 NO es confiable para ANSWER (siempre es Normal Clearing/Unknown).
    """
    if was_answered:
        return 'ANSWER'
    return CAUSE_MAP.get(disposition.upper(), 'NO_ANSWER')

# ── DB Queue — único punto de escritura a DB ──────────────────────────────────
# Jobs: ('log_call', ...) | ('release', queue_dial_id)
# El db_worker es el ÚNICO thread que usa worker_conn.
# El poll_loop usa su propia poll_conn separada.
# Total conexiones DB: 2 fijas, independientemente del volumen de llamadas.

_db_queue: queue.Queue = queue.Queue()
_worker_stats = {'jobs': 0, 'errors': 0, 'conn_resets': 0}
# {local1_channel_name: local1_channel_id} — correlación LOCAL;2→LOCAL;1 por nombre
_local1_by_name: dict[str, str] = {}
# {pjsip_channel_id: local1_channel_id} — poblado en ChannelCreated (antes del Up)
_pjsip_to_local1: dict[str, str] = {}


def _open_connection(label: str) -> pymysql.connections.Connection:
    """Abre una nueva conexión MySQL y la registra en el log."""
    conn = pymysql.connect(
        host=DB_HOST, port=DB_PORT,
        database=DB_NAME, user=DB_USER, password=DB_PASS,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
        connect_timeout=10,
    )
    # Intentar extender wait_timeout de la sesión (Hostinger puede tenerlo en ~28s)
    try:
        with conn.cursor() as cur:
            cur.execute("SET SESSION wait_timeout=28800")
            cur.execute("SET SESSION interactive_timeout=28800")
        conn.commit()
    except Exception:
        pass  # algunos hosts no permiten cambiar wait_timeout — ignorar silenciosamente
    log.info('DB connection opened [%s] (thread=%d)', label, threading.get_ident())
    return conn


def _ensure_conn(conn, label: str) -> pymysql.connections.Connection:
    """Verifica la conexión y reconecta si es necesario sin abrir una nueva."""
    try:
        conn.ping()
        return conn
    except Exception:
        log.warning('DB connection lost [%s], reconnecting...', label)
        try:
            conn.connect()
            _worker_stats['conn_resets'] += 1
            log.info('DB connection restored [%s]', label)
            return conn
        except Exception as e:
            log.error('DB reconnect failed [%s]: %s — opening new connection', label, e)
            _worker_stats['conn_resets'] += 1
            return _open_connection(label)


# ── DB Worker — procesa todos los writes de log/release ──────────────────────

BATCH_SIZE = 50   # máx registros por transacción — balance entre latencia y tamaño de SQL


def _get_campaign_tables(conn, campaign_id: int) -> tuple | None:
    """Devuelve (log_table, lead_table) para una campaña, consultando en tiempo real."""
    with conn.cursor() as cur:
        cur.execute(
            """SELECT ll.log_table_name, ll.table_name
               FROM dialer_campaigns dc
               JOIN lead_lists ll ON ll.id = dc.lead_list_id
               WHERE dc.id=%s""",
            (campaign_id,)
        )
        row = cur.fetchone()
    if not row:
        return None
    return (row['log_table_name'], row['table_name'])


def db_worker() -> None:
    """
    Thread permanente con UNA sola conexión DB.
    Drena la cola en lotes de hasta BATCH_SIZE jobs por transacción.
    """
    log.info('DB worker started')
    conn = _open_connection('worker')

    while True:
        # Esperar primer job (blocking para no quemar CPU)
        try:
            first_job = _db_queue.get(timeout=20)
        except queue.Empty:
            try:
                conn.ping()
                log.debug('[WORKER] keepalive ping OK')
            except Exception:
                conn = _ensure_conn(conn, 'worker')
            if _db_queue.qsize() > 0:
                log.warning('[WORKER] Queue backlog: %d', _db_queue.qsize())
            continue

        # Drenar hasta BATCH_SIZE-1 jobs más sin bloquear
        batch = [first_job]
        try:
            while len(batch) < BATCH_SIZE:
                batch.append(_db_queue.get_nowait())
        except queue.Empty:
            pass

        log_calls = [j for j in batch if j[0] == 'log_call']
        releases  = [j for j in batch if j[0] == 'release']

        try:
            conn = _ensure_conn(conn, 'worker')

            if log_calls:
                _do_batch_log_calls(conn, log_calls)

            for job in releases:
                queue_dial_id = job[1]
                ari_resp = job[2] if len(job) > 2 else ''
                _do_release(conn, queue_dial_id)
                if ari_resp:
                    log.warning('Originate failed [qd#%d]: %s', queue_dial_id, ari_resp)

            _worker_stats['jobs'] += len(batch)
            if _worker_stats['jobs'] % 50 < len(batch):
                log.info('[WORKER] %d jobs done | errors: %d | conn_resets: %d | queue: %d',
                         _worker_stats['jobs'], _worker_stats['errors'],
                         _worker_stats['conn_resets'], _db_queue.qsize())
        except Exception as e:
            _worker_stats['errors'] += 1
            log.error('[WORKER] Batch error (%d jobs): %s', len(batch), e)
            try:
                conn.rollback()
            except Exception:
                pass
            conn = _ensure_conn(conn, 'worker')
        finally:
            for _ in batch:
                _db_queue.task_done()


def _do_batch_log_calls(conn, jobs: list) -> None:
    """
    Graba N llamadas en una sola transacción:
      - 1 INSERT multi-row en log table
      - 1 UPDATE CASE WHEN en lead table
      - 1 DELETE WHERE IN en queue_dial
    Agrupa por campaign_id para manejar correctamente múltiples campañas en el batch.
    """
    # Agrupar por campaign_id
    by_campaign: dict = {}
    for job in jobs:
        _, campaign_id, queue_dial_id, lead_id, phone, disposition, duration, billsec, uniqueid, ari_response, final_status = job
        by_campaign.setdefault(campaign_id, []).append(
            (queue_dial_id, lead_id, phone, disposition, duration, billsec,
             uniqueid, ari_response, final_status)
        )

    with conn.cursor() as cur:
        for campaign_id, items in by_campaign.items():
            tables = _get_campaign_tables(conn, campaign_id)
            if not tables:
                log.error('_do_batch_log_calls: campaign #%d not found', campaign_id)
                continue

            log_table, lead_table = tables

            # ── INSERT multi-row (1 round-trip) ──────────────────────────────
            log_vals = []
            for (queue_dial_id, lead_id, phone, disposition, duration, billsec,
                 uniqueid, ari_response, final_status) in items:
                fs = final_status or _resolve_status(disposition, duration, False)
                log_vals.append(
                    (campaign_id, lead_id, phone, uniqueid or None, fs, disposition, duration, billsec)
                )
            placeholders = ','.join(['(%s,%s,%s,%s,%s,%s,%s,%s)'] * len(log_vals))
            flat_log = [v for row in log_vals for v in row]
            cur.execute(
                f"INSERT INTO `{log_table}` "
                f"(dialer_campaign_id, dialer_lead_id, phone, uniqueid, final_status, disposition, duration, billsec) "
                f"VALUES {placeholders}",
                flat_log
            )

            # ── UPDATE CASE WHEN (1 round-trip) ──────────────────────────────
            case_status   = []
            case_duration = []
            case_billsec  = []
            lead_ids      = []
            for (queue_dial_id, lead_id, phone, disposition, duration, billsec,
                 uniqueid, ari_response, final_status) in items:
                new_status = final_status or _resolve_status(disposition, duration, False)
                case_status.extend([lead_id, new_status])
                case_duration.extend([lead_id, duration])
                case_billsec.extend([lead_id, billsec])
                lead_ids.append(lead_id)

            when_status   = ' '.join(['WHEN %s THEN %s'] * len(items))
            when_duration = ' '.join(['WHEN %s THEN %s'] * len(items))
            when_billsec  = ' '.join(['WHEN %s THEN %s'] * len(items))
            in_ids        = ','.join(['%s'] * len(items))
            cur.execute(
                f"UPDATE `{lead_table}` SET "
                f"status        = CASE id {when_status} END, "
                f"called_count  = called_count + 1, "
                f"last_called_at= NOW(), "
                f"last_duration = CASE id {when_duration} END, "
                f"last_billsec  = CASE id {when_billsec} END "
                f"WHERE id IN ({in_ids})",
                case_status + case_duration + case_billsec + lead_ids
            )

            # ── DELETE WHERE IN (1 round-trip) ───────────────────────────────
            q_ids = [item[0] for item in items]
            cur.execute(
                f"DELETE FROM queue_dial WHERE id IN ({','.join(['%s'] * len(q_ids))})",
                q_ids
            )

            phones_str = ', '.join(item[2] for item in items[:5])
            suffix     = f'... +{len(items)-5}' if len(items) > 5 else ''
            log.info('Batch logged %d calls (campaign #%d): %s%s',
                     len(items), campaign_id, phones_str, suffix)

    conn.commit()


def _do_release(conn, queue_dial_id: int) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE queue_dial SET status='pending' WHERE id=%s",
            (queue_dial_id,)
        )
    conn.commit()
    log.debug('Released queue item #%d back to pending', queue_dial_id)


# ── ARI REST ──────────────────────────────────────────────────────────────────

def ari_delete(path: str) -> bool:
    """Hangup o eliminar un recurso ARI."""
    try:
        r = requests.delete(
            f'{ARI_URL}/ari{path}',
            auth=(ARI_USER, ARI_PASS),
            timeout=5,
        )
        return r.status_code in (200, 204)
    except requests.RequestException as e:
        log.error('ARI DELETE %s error: %s', path, e)
        return False


def ari_get(path: str, params: dict | None = None) -> tuple[dict | None, int, str]:
    """Lee un recurso ARI via GET. Retorna (body, http_status, error_msg).
    Usar params= para query strings — requests URL-encodea los valores automáticamente."""
    try:
        r = requests.get(
            f'{ARI_URL}/ari{path}',
            auth=(ARI_USER, ARI_PASS),
            params=params,
            timeout=5,
        )
        if r.status_code == 200:
            return r.json(), r.status_code, ''
        msg = r.text[:200]
        log.debug('ARI GET %s → %d %s', path, r.status_code, msg)
        return None, r.status_code, msg
    except requests.RequestException as e:
        log.error('ARI GET %s error: %s', path, e)
        return None, 0, str(e)


def ari_post(path: str, data: dict) -> tuple[dict | None, int, str]:
    """
    Retorna (body, http_status_code, error_message).
    body es None si falló.
    """
    try:
        r = requests.post(
            f'{ARI_URL}/ari{path}',
            auth=(ARI_USER, ARI_PASS),
            json=data,
            timeout=5,
        )
        if r.status_code in (200, 201, 204):
            return (r.json() if r.content else {}), r.status_code, ''
        msg = r.text[:200]
        log.warning('ARI POST %s → %s %s', path, r.status_code, msg)
        return None, r.status_code, msg
    except requests.RequestException as e:
        log.error('ARI POST %s error: %s', path, e)
        return None, 0, str(e)


def count_active_channels(campaign_id: int) -> int:
    with state_lock:
        return len(active_channels.get(campaign_id, {}))


# ── Marcación ─────────────────────────────────────────────────────────────────

def dial_lead(campaign: dict, queue_row: dict) -> None:
    """
    Origina la llamada via ARI HTTP (red — justifica thread separado).
    NO toca DB directamente: fallos van a _db_queue.
    Captura uniqueid (channel id de ARI) y ari_response para logging.
    """
    phone         = queue_row['phone']
    queue_dial_id = queue_row['id']
    lead_id       = queue_row['dialer_lead_id']

    result, ari_status, ari_error = ari_post('/channels', {
        'endpoint':  f'Local/{phone}@{campaign["context"]}',
        'app':       'marcador_power',
        'appArgs':   f'{campaign["id"]},{queue_dial_id},{lead_id}',
        'callerId':  f'{campaign["caller_id"]} <{campaign["caller_id"]}>',
        'variables': {
            'DIALER_CAMPAIGN_ID': str(campaign['id']),
            'DIALER_QUEUE_ID':    str(queue_dial_id),
            'DIALER_LEAD_ID':     str(lead_id),
        },
    })

    if result and 'id' in result:
        channel_id   = result['id']    # Asterisk uniqueid (ej: "1780260644.541")
        channel_name = result.get('name', '')  # ej: "Local/6622293306@marcador_pstn-00000001;1"
        ch_state     = result.get('state', '')
        with state_lock:
            if campaign['id'] not in active_channels:
                active_channels[campaign['id']] = {}
            active_channels[campaign['id']][channel_id] = {
                'queue_dial_id': queue_dial_id,
                'lead_id':       lead_id,
                'phone':         phone,
                'channel_name':  channel_name,
                'uniqueid':      channel_id,
                'ari_response':  f'HTTP {ari_status} state={ch_state}',
                'started_at':    time.time(),
                'answered':      False,
            }
            if channel_name:
                _local1_by_name[channel_name] = channel_id
        log.info('Campaign #%d → dialing %s [channel %s]',
                 campaign['id'], phone, channel_id)
    else:
        ari_resp = f'HTTP {ari_status}: {ari_error[:100]}' if ari_error else f'HTTP {ari_status}'
        log.warning('Campaign #%d → failed to originate %s [%s]',
                    campaign['id'], phone, ari_resp)
        # Fallo ARI → devolver a pending via queue (sin crear conexión nueva)
        _db_queue.put(('release', queue_dial_id, ari_resp))


# ── Poll loop — única conexión poll_conn ──────────────────────────────────────

def poll_loop() -> None:
    log.info('Poll loop started (interval=%ds)', POLL_INTERVAL)
    conn = _open_connection('poll')
    _stats_tick = 0

    while True:
        conn = _ensure_conn(conn, 'poll')
        try:
            _poll_once(conn)
        except Exception as e:
            log.error('Poll loop error: %s', e)
            try:
                conn.rollback()
            except Exception:
                pass

        _stats_tick += 1
        if _stats_tick >= 60:
            _stats_tick = 0
            with state_lock:
                total_ch = sum(len(ch) for ch in active_channels.values())
            log.info('[STATS] Active channels: %d | Queue backlog: %d | Worker jobs: %d | Worker errors: %d',
                     total_ch, _db_queue.qsize(),
                     _worker_stats['jobs'], _worker_stats['errors'])

        _poll_trigger.wait(timeout=POLL_INTERVAL)
        _poll_trigger.clear()


def _poll_once(conn) -> None:
    with conn.cursor() as cur:
        cur.execute("SELECT * FROM dialer_campaigns WHERE status='running'")
        campaigns = cur.fetchall()

    for campaign in campaigns:
        cid      = campaign['id']
        capacity = campaign['dial_block_size'] - count_active_channels(cid)
        if capacity <= 0:
            continue

        with conn.cursor() as cur:
            cur.execute(
                """SELECT id, dialer_lead_id, phone, lead_list_table
                   FROM queue_dial
                   WHERE dialer_campaign_id=%s AND status='pending'
                   LIMIT %s
                   FOR UPDATE""",
                (cid, capacity)
            )
            rows = cur.fetchall()

            if not rows:
                # Esperar a que el db_worker vacíe su cola antes de marcar completed.
                # Evita el estado confuso "Completed con leads en NEW todavía".
                pending_jobs = _db_queue.qsize()
                if pending_jobs > 0:
                    log.info('Campaign #%d queue_dial empty but %d jobs pending in worker — waiting',
                             cid, pending_jobs)
                    conn.commit()
                    continue
                cur.execute(
                    "UPDATE dialer_campaigns SET status='completed' WHERE id=%s",
                    (cid,)
                )
                conn.commit()
                log.info('Campaign #%d completed — queue_dial and worker both empty.', cid)
                continue

            ids = [r['id'] for r in rows]
            cur.execute(
                f"UPDATE queue_dial SET status='dialing' "
                f"WHERE id IN ({','.join(['%s'] * len(ids))})",
                ids
            )
        conn.commit()

        for row in rows:
            threading.Thread(
                target=dial_lead,
                args=(campaign, row),
                daemon=True,
            ).start()


# ── Eventos ARI — procesados inline en el WebSocket thread ────────────────────

def on_ws_message(ws, message: str) -> None:
    try:
        event = json.loads(message)
    except json.JSONDecodeError:
        return

    etype = event.get('type', '')

    if etype == 'ChannelDestroyed':
        _handle_channel_destroyed(event)
    elif etype == 'ChannelStateChange':
        _handle_channel_state_change(event)
    elif etype == 'Dial':
        _handle_dial(event)
    elif etype in ('StasisStart', 'StasisEnd'):
        log.debug('%s: %s', etype, event.get('channel', {}).get('id'))


def _handle_dial(event: dict) -> None:
    """
    Evento Dial de ARI: cuando LOCAL;2 ejecuta Dial(PJSIP/...), el payload contiene
    caller=LOCAL;2 y peer=PJSIP. Capturamos la correlación PJSIP→LOCAL;1 sin HTTP.
    Solo procesamos el inicio del dial (dialstatus vacío).
    """
    if event.get('dialstatus', ''):
        return

    caller = event.get('caller', {})
    peer   = event.get('peer', {})

    caller_name = caller.get('name', '')
    peer_id     = peer.get('id', '')

    if not peer_id or not caller_name.startswith('Local/') or not caller_name.endswith(';2'):
        return

    local1_name = caller_name[:-1] + '1'  # Local/X@ctx;2 → Local/X@ctx;1

    with state_lock:
        local1_id = _local1_by_name.get(local1_name)
        if local1_id:
            _pjsip_to_local1[peer_id] = local1_id
            log.info('PJSIP %s → LOCAL;1 %s (Dial)', peer_id, local1_id)
        else:
            log.debug('Dial: LOCAL;1 no encontrado para %s', local1_name)


def _handle_channel_state_change(event: dict) -> None:
    """
    Detecta cuando un canal llega a estado 'Up' (contestado).

    Caso 1 — LOCAL;1 directo: contextos que llaman Answer() directamente
              (ej: marcador_simulado). LOCAL;1 en Stasis recibe el Up.

    Caso 2 — PJSIP saliente: _pjsip_to_local1 poblado desde evento Dial (sin HTTP).
              Lookup instantáneo, sin race condition.
              answered_at = tiempo exacto del evento Up (siempre llega antes de LOCAL;1 Destroyed).

    Caso 3 — LOCAL;2 Up (fallback): para contextos sin PJSIP saliente.
              Si answered=True ya (Caso 2 ganó la carrera), es no-op.
    """
    channel      = event.get('channel', {})
    channel_id   = channel.get('id', '')
    channel_name = channel.get('name', '')
    state        = channel.get('state', '')

    if state != 'Up':
        return

    answer_time = time.time()

    with state_lock:
        # Caso 1: LOCAL;1 directo — está en active_channels directamente
        for cid, channels in active_channels.items():
            if channel_id in channels:
                if not channels[channel_id].get('answered'):
                    channels[channel_id]['answered']    = True
                    channels[channel_id]['answered_at'] = answer_time
                    log.info('ANSWERED (local;1) — campaign #%d phone %s',
                             cid, channels[channel_id]['phone'])
                return

        # Caso 2: PJSIP saliente — lookup en _pjsip_to_local1 (pre-poblado en ChannelCreated)
        if not channel_name.startswith('Local/'):
            local1_id = _pjsip_to_local1.get(channel_id)
            if local1_id:
                for cid, channels in active_channels.items():
                    if local1_id in channels and not channels[local1_id].get('answered'):
                        channels[local1_id]['answered']    = True
                        channels[local1_id]['answered_at'] = answer_time
                        log.info('ANSWERED (pjsip) — campaign #%d phone %s',
                                 cid, channels[local1_id]['phone'])
                        return
            else:
                log.warning('PJSIP %s Up sin correlación (ChannelCreated no llegó a tiempo?)',
                            channel_id)
            return  # canal PJSIP procesado, no continuar a Case 3

        # Caso 3: LOCAL;2 fallback — nombre termina en ;2
        if channel_name.endswith(';2'):
            local1_name = channel_name[:-1] + '1'
            local1_id   = _local1_by_name.get(local1_name)
            if local1_id:
                for cid, channels in active_channels.items():
                    if local1_id in channels and not channels[local1_id].get('answered'):
                        channels[local1_id]['answered']    = True
                        channels[local1_id]['answered_at'] = answer_time
                        log.info('ANSWERED (local;2 fallback) — campaign #%d phone %s',
                                 cid, channels[local1_id]['phone'])
                        return


def _handle_channel_destroyed(event: dict) -> None:
    channel      = event.get('channel', {})
    channel_id   = channel.get('id', '')
    channel_name = channel.get('name', '')
    cause_txt    = event.get('cause_txt', 'UNKNOWN')

    if channel_name.endswith(';2'):
        log.debug('Ignoring Local ;2 leg: %s', channel_name)
        return

    # Calcular duración
    creation_str = channel.get('creationtime', '')
    try:
        created  = datetime.datetime.fromisoformat(creation_str.replace('Z', '+00:00'))
        now      = datetime.datetime.now(datetime.timezone.utc)
        duration = max(0, int((now - created).total_seconds()))
    except (ValueError, TypeError):
        duration = 0

    billsec = duration

    # Lookup en memoria (sin DB)
    campaign_id  = None
    channel_info = None
    with state_lock:
        # Limpiar correlación PJSIP si aplica
        if not channel_name.startswith('Local/'):
            _pjsip_to_local1.pop(channel_id, None)

        for cid, channels in active_channels.items():
            if channel_id in channels:
                campaign_id  = cid
                channel_info = channels.pop(channel_id)
                # Limpiar índice de nombre
                _local1_by_name.pop(channel_info.get('channel_name', ''), None)
                break

    if not channel_info:
        return

    uniqueid     = channel_info.get('uniqueid', channel_id)
    ari_response = channel_info.get('ari_response', '')
    was_answered = channel_info.get('answered', False)
    answered_at  = channel_info.get('answered_at', None)

    # billsec = tiempo real de conversación (desde Up hasta Destroyed)
    # duration = tiempo total del canal (desde creación hasta Destroyed)
    if was_answered and answered_at:
        billsec = max(0, int(time.time() - answered_at))
    else:
        billsec = 0  # no contestó = 0 segundos de conversación

    # Determinar disposición final con prioridad:
    # 1. ChannelStateChange→Up (certeza) 2. cause_txt 3. fallback duration
    final_status = _resolve_status(cause_txt, duration, was_answered)

    log.info('ChannelDestroyed — campaign #%d phone %s cause=%s answered=%s dur=%ds → %s',
             campaign_id, channel_info['phone'], cause_txt,
             was_answered, duration, final_status)

    # Encolar job de log — SIN tocar DB aquí, SIN crear nuevo thread
    _db_queue.put((
        'log_call',
        campaign_id,
        channel_info['queue_dial_id'],
        channel_info['lead_id'],
        channel_info['phone'],
        cause_txt,      # disposición raw de Asterisk (se guarda en log)
        duration,
        billsec,
        uniqueid,
        ari_response,
        final_status,   # status resuelto (se guarda en dl_X_leads)
    ))

    # Señal al poll loop para que tome el siguiente lead inmediatamente
    _poll_trigger.set()


# ── WebSocket ─────────────────────────────────────────────────────────────────

def on_ws_error(ws, error) -> None:
    log.error('WebSocket error: %s', error)


def on_ws_close(ws, close_status_code, close_msg) -> None:
    log.warning('WebSocket closed (%s). Reconnecting in %ds...', close_status_code, RETRY_BACKOFF)


def on_ws_open(ws) -> None:
    log.info('WebSocket connected to Asterisk ARI.')


def start_websocket() -> None:
    ws_url = (
        ARI_URL.replace('http', 'ws')
        + f'/ari/events?api_key={ARI_USER}:{ARI_PASS}&app=marcador_power&subscribeAll=true'
    )
    while True:
        try:
            ws = websocket.WebSocketApp(
                ws_url,
                on_open=on_ws_open,
                on_message=on_ws_message,
                on_error=on_ws_error,
                on_close=on_ws_close,
            )
            ws.run_forever(ping_interval=30, ping_timeout=10)
        except Exception as e:
            log.error('WebSocket exception: %s', e)
        log.info('Reconnecting in %ds...', RETRY_BACKOFF)
        time.sleep(RETRY_BACKOFF)


# ── Entry point ───────────────────────────────────────────────────────────────

if __name__ == '__main__':
    log.info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━')
    log.info('  xedik Power Dialer v%s', VERSION)
    log.info('  ARI : %s', ARI_URL)
    log.info('  DB  : %s@%s/%s', DB_USER, DB_HOST, DB_NAME)
    log.info('  Architecture: poll_conn + worker_conn (2 DB connections total)')
    log.info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━')

    # Startup: resetear filas phantom 'dialing' de crashes anteriores
    try:
        startup_conn = _open_connection('startup')
        with startup_conn.cursor() as cur:
            cur.execute("UPDATE queue_dial SET status='pending' WHERE status='dialing'")
            reset_count = cur.rowcount
        startup_conn.commit()
        startup_conn.close()
        if reset_count > 0:
            log.warning('Startup: reset %d phantom dialing rows to pending', reset_count)
        else:
            log.info('Startup: queue_dial clean (no phantom rows)')
    except Exception as e:
        log.error('Startup cleanup error: %s', e)

    # DB worker — 1 conexión persistente para todos los writes
    worker_thread = threading.Thread(target=db_worker, daemon=True, name='db-worker')
    worker_thread.start()

    # Poll loop — 1 conexión persistente para SELECT FOR UPDATE + queue management
    poll_thread = threading.Thread(target=poll_loop, daemon=True, name='poll-loop')
    poll_thread.start()

    # WebSocket — hilo principal (bloqueante con reconnect)
    start_websocket()
