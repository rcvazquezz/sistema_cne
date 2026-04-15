/**
 * Cliente SSE (Server-Sent Events) CNE — dispara eventos 'cne:realtime' para campanas y tablas.
 * Degradación: si falla la conexión, no altera el flujo (solo deja de recibir push).
 */
(function () {
    'use strict';

    console.log('[SSE] cne_realtime.js cargado');

    function formatCodigo(s) {
        return String(s || '').replace(/_/g, ' ');
    }

    function resolveStreamUrl(cfg) {
        var raw = cfg && cfg.streamUrl;
        if (!raw) {
            return '';
        }
        if (String(raw).indexOf('http') === 0) {
            return raw;
        }
        if (String(raw).charAt(0) === '/') {
            return window.location.origin + raw;
        }
        return new URL(raw, window.location.href).href;
    }

    function connect() {
        console.log('[SSE] Intentando conectar...');
        var cfg = window.CNE_REALTIME;
        if (!cfg || cfg.enabled === false) {
            console.warn('[SSE] CNE_REALTIME deshabilitado o ausente');
            return;
        }
        if (!cfg.streamUrl) {
            console.warn('[SSE] Falta streamUrl en CNE_REALTIME');
            return;
        }
        var url = resolveStreamUrl(cfg);
        if (!url) {
            return;
        }
        console.log('[SSE] URL EventSource:', url);
        var es;
        try {
            es = new EventSource(url, { withCredentials: true });
        } catch (e) {
            console.error('[SSE] Error al crear EventSource:', e);
            scheduleReconnect();
            return;
        }

        es.onopen = function () {
            cfg._rtBackoffMs = 2000;
            console.log('[SSE] Conexión abierta (EventSource)');
        };

        es.onmessage = function (ev) {
            try {
                var msg = JSON.parse(ev.data);
                if (!msg || !msg.event) {
                    return;
                }
                var evt = msg.event;
                if (evt.accion_codigo && !evt.accion_label_fmt) {
                    evt.accion_label_fmt = formatCodigo(evt.accion_label || evt.accion_codigo);
                }
                window.dispatchEvent(new CustomEvent('cne:realtime', { detail: msg }));
            } catch (err) {
                /* ignorar frame inválido */
            }
        };

        es.onerror = function () {
            try {
                es.close();
            } catch (e) {}
            scheduleReconnect();
        };

        cfg._rtEs = es;
    }

    function scheduleReconnect() {
        var cfg = window.CNE_REALTIME;
        if (!cfg) {
            return;
        }
        if (cfg._rtEs) {
            try {
                cfg._rtEs.close();
            } catch (e) {}
            cfg._rtEs = null;
        }
        var delay = cfg._rtBackoffMs || 2000;
        cfg._rtBackoffMs = Math.min(Math.floor(delay * 1.5), 30000);
        clearTimeout(cfg._rtReconnectTimer);
        cfg._rtReconnectTimer = setTimeout(function () {
            console.log('[SSE] Reintentando conexión...');
            connect();
        }, delay);
    }

    function startWhenReady() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function onReady() {
                document.removeEventListener('DOMContentLoaded', onReady);
                connect();
            });
        } else {
            connect();
        }
    }

    startWhenReady();

    window.__CNE_SSE_CONNECT__ = connect;
})();
