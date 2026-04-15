/**
 * Utilidad global: mayúsculas para textos de ciudadano en plantillas JS (evita parpadeo vs. solo CSS).
 */
(function () {
    'use strict';

    function cneMayusCiudadanoTexto(v) {
        if (v == null) {
            return '';
        }
        const s = String(v);
        if (s === '') {
            return '';
        }
        try {
            return s.toLocaleUpperCase('es-VE');
        } catch (e) {
            return s.toUpperCase();
        }
    }

    window.cneMayusCiudadanoTexto = cneMayusCiudadanoTexto;

    function aplicarMayusAInput(el) {
        if (!el || el.disabled || el.readOnly) {
            return;
        }
        const t = (el.type || '').toLowerCase();
        if (t === 'email' || t === 'password' || t === 'hidden' || t === 'file' || t === 'number' || t === 'date' || t === 'datetime-local') {
            return;
        }
        const start = el.selectionStart;
        const end = el.selectionEnd;
        const val = el.value;
        const up = cneMayusCiudadanoTexto(val);
        if (up !== val) {
            el.value = up;
            if (start != null && end != null && typeof el.setSelectionRange === 'function') {
                try {
                    el.setSelectionRange(start, end);
                } catch (e2) { /* ignore */ }
            }
        }
    }

    document.addEventListener('input', function (ev) {
        const el = ev.target;
        if (!el || !el.classList || !el.classList.contains('cne-mayus-ciudadano-live')) {
            return;
        }
        aplicarMayusAInput(el);
    });

    document.addEventListener('change', function (ev) {
        const el = ev.target;
        if (!el || !el.classList || !el.classList.contains('cne-mayus-ciudadano-live')) {
            return;
        }
        aplicarMayusAInput(el);
    });
})();
