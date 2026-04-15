/**
 * Select2: Institución, Estado y Municipio (Nueva Solicitud).
 * Dependencias: jQuery, select2.min.js, i18n/es.js (antes de este archivo).
 */
(function () {
    'use strict';

    var $ = window.jQuery || window.$;
    if (!$) {
        console.error('[CNE Select2] jQuery no está disponible. Verifique el orden de los scripts (jQuery primero).');
        return;
    }

    function mayusTexto(texto) {
        if (typeof window.cneMayusCiudadanoTexto === 'function') {
            return window.cneMayusCiudadanoTexto(texto);
        }
        if (texto == null || texto === '') {
            return '';
        }
        try {
            return String(texto).toLocaleUpperCase('es-VE');
        } catch (e) {
            return String(texto).toUpperCase();
        }
    }

    function matcherCne(params, data) {
        if ($.trim(params.term) === '') {
            return data;
        }
        var term = $.trim(params.term).toLowerCase();
        if (term.length < 1) {
            return data;
        }
        var txt = data.text != null ? String(data.text) : '';
        if (txt.toLowerCase().indexOf(term) > -1) {
            return data;
        }
        return null;
    }

    function plantillaResultado(data) {
        if (!data || data.loading) {
            return data.text;
        }
        var t = data.text != null ? String(data.text) : '';
        return $('<span class="cne-select2-ns-mayus"></span>').text(mayusTexto(t));
    }

    function plantillaSeleccion(data) {
        if (!data || (data.id === '' && !data.text)) {
            return data && data.text != null ? plantillaResultado(data) : '';
        }
        var t = data.text != null ? String(data.text) : '';
        return $('<span class="cne-select2-ns-mayus"></span>').text(mayusTexto(t));
    }

    function obtenerDropdownParent() {
        var $sec = $('#seccion-nueva-solicitud');
        return $sec.length ? $sec : $(document.body);
    }

    function obtenerOpcionesBase() {
        return {
            width: '100%',
            minimumResultsForSearch: 0,
            language: 'es',
            matcher: matcherCne,
            templateResult: plantillaResultado,
            templateSelection: plantillaSeleccion,
            dropdownParent: obtenerDropdownParent(),
            dropdownCssClass: 'cne-select2-ns-dropdown'
        };
    }

    function destruirSiSelect2($el) {
        if (!$el || !$el.length) {
            return;
        }
        if ($el.hasClass('select2-hidden-accessible') && typeof $el.select2 === 'function') {
            $el.select2('destroy');
        }
    }

    function aplicarSelect2($el) {
        if (!$el || !$el.length) {
            return;
        }
        if (typeof $.fn.select2 !== 'function') {
            console.error('[CNE Select2] El plugin Select2 no está cargado. Orden esperado: jQuery → select2.min.js → i18n/es.js → cne_select2_nueva_solicitud.js');
            return;
        }
        destruirSiSelect2($el);
        var opts = $.extend({}, obtenerOpcionesBase());
        opts.dropdownParent = obtenerDropdownParent();
        $el.select2(opts);
    }

    var selectoresEntrada = ['#institucion', '#estado_id', '#municipio_id'];
    var selectoresEmpleado = ['#institucion-empleado', '#estado_id-empleado', '#municipio_id-empleado'];

    function aplicarInitPorModo(modo) {
        if (typeof $.fn.select2 !== 'function') {
            return false;
        }
        var lista = modo === 'empleado' ? selectoresEmpleado : selectoresEntrada;
        lista.forEach(function (sel) {
            aplicarSelect2($(sel));
        });
        return true;
    }

    window.cneSelect2NuevaSolicitudInit = function (modo) {
        aplicarInitPorModo(modo);
    };

    window.cneSelect2NuevaSolicitudReinitMunicipio = function (modo) {
        if (typeof $.fn.select2 !== 'function') {
            return;
        }
        var sel = modo === 'empleado' ? '#municipio_id-empleado' : '#municipio_id';
        aplicarSelect2($(sel));
    };

    /**
     * Debe ejecutarse después de otros listeners de DOMContentLoaded del dashboard
     * (p. ej. el que resetea #municipio_id con innerHTML); si no, Select2 se aplica y luego se rompe.
     */
    function inicializarCuandoDomEstable() {
        console.log('Inicializando Select2 en Nueva Solicitud...');

        if (typeof $.fn.select2 !== 'function') {
            console.error('[CNE Select2] Select2 no disponible; no se aplicará el widget a los selects.');
            return;
        }

        var modo = null;
        if ($('#estado_id').length && $('#municipio_id').length && $('#institucion').length) {
            modo = 'entrada';
        } else if ($('#estado_id-empleado').length && $('#municipio_id-empleado').length && $('#institucion-empleado').length) {
            modo = 'empleado';
        }

        if (!modo) {
            console.warn('[CNE Select2] No se encontraron los selects de Nueva Solicitud; omitiendo init.');
            return;
        }

        aplicarInitPorModo(modo);

        if (modo === 'entrada') {
            document.getElementById('institucion')?.dispatchEvent(new Event('change'));
        } else {
            document.getElementById('institucion-empleado')?.dispatchEvent(new Event('change'));
        }

        console.log('Select2 Inicializado');
    }

    $(document).ready(function () {
        setTimeout(inicializarCuandoDomEstable, 0);
    });
})();

