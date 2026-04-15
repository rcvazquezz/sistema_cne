/**
 * Combos Estado / Municipio / Institución — mismo patrón visual y Fuse.js que Tipo de Trámite.
 * Requiere: Fuse (fuse.js), Font Awesome para iconos en vacío.
 */
(function () {
    'use strict';

    function fuseOptsCombo() {
        return {
            keys: ['nombre'],
            threshold: 0.3,
            distance: 100,
            includeScore: true,
            includeMatches: true,
            minMatchCharLength: 1,
            getFn: function (obj, path) {
                var normalizeText = function (text) {
                    return String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                };
                var value = obj[path];
                if (typeof value === 'string') {
                    return normalizeText(value);
                }
                return value;
            }
        };
    }

    function escapeRegExp(s) {
        return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function textoMayus(v) {
        if (typeof window.cneMayusCiudadanoTexto === 'function') {
            return window.cneMayusCiudadanoTexto(v);
        }
        return String(v || '').toUpperCase();
    }

    function setBotonTexto(button, texto, esPlaceholder) {
        if (!button) return;
        var span = button.querySelector('.selected-tramite-text');
        if (!span) return;
        if (esPlaceholder || texto === '' || texto == null) {
            span.textContent = texto || 'Seleccione…';
            span.className = 'selected-tramite-text tramite-placeholder';
        } else {
            span.textContent = textoMayus(texto);
            span.className = 'selected-tramite-text';
        }
    }

    function cerrarCombo(button, dropdown) {
        if (dropdown) dropdown.classList.remove('open');
        if (button) button.classList.remove('open');
    }

    function renderOpciones(container, items, query, onPick) {
        var html = '';
        items.forEach(function (it) {
            var nombreRaw = String(it.nombre != null ? it.nombre : '');
            var nombreDisplay = textoMayus(nombreRaw);
            if (query) {
                try {
                    var re = new RegExp('(' + escapeRegExp(query) + ')', 'gi');
                    nombreDisplay = nombreDisplay.replace(re, '<span class="search-highlight">$1</span>');
                } catch (e) { /* ignore */ }
            }
            var safe = nombreRaw.replace(/"/g, '&quot;');
            var val = it.id != null ? String(it.id) : '';
            html += '<div class="tramite-search-option" data-value="' + val + '" data-nombre="' + safe + '"><span>' + nombreDisplay + '</span></div>';
        });
        container.innerHTML = html;
        container.querySelectorAll('.tramite-search-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                onPick(opt);
            });
        });
    }

    function mostrarVacío(container) {
        container.innerHTML =
            '<div class="no-results-message"><i class="fas fa-search mb-2"></i><p>No se encontraron resultados</p></div>';
    }

    window.cneNuevaSolicitudCombosInit = function (cfg) {
        if (typeof Fuse === 'undefined') {
            console.error('[CNE Combos] Fuse.js no está cargado.');
            return;
        }
        cfg = cfg || {};
        var ids = cfg.ids || {};
        var estadosLista = cfg.estadosLista || [];
        var institucionesLista = cfg.institucionesLista || [];
        var MUN = cfg.municipiosPorEstado || {};

        var fe = ids.estado || {};
        var fm = ids.municipio || {};
        var fi = ids.institucion || {};

        var fuseEstados = new Fuse(estadosLista, fuseOptsCombo());
        var fuseInst = new Fuse(institucionesLista, fuseOptsCombo());
        var listaMunicipiosActual = [];
        var fuseMunicipios = null;

        function bindAbrirCerrar(button, dropdown) {
            if (!button || !dropdown) return;
            button.addEventListener('click', function (e) {
                e.stopPropagation();
                if (button.disabled) return;
                var abrir = !dropdown.classList.contains('open');
                document.querySelectorAll('.tramite-search-dropdown.open').forEach(function (d) {
                    d.classList.remove('open');
                });
                document.querySelectorAll('.tramite-search-button.open').forEach(function (b) {
                    b.classList.remove('open');
                });
                if (abrir) {
                    dropdown.classList.add('open');
                    button.classList.add('open');
                }
            });
        }

        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('.tramite-search-wrapper')) return;
            document.querySelectorAll('.tramite-search-dropdown.open').forEach(function (d) {
                d.classList.remove('open');
            });
            document.querySelectorAll('.tramite-search-button.open').forEach(function (b) {
                b.classList.remove('open');
            });
        });

        /* --- Estado --- */
        (function () {
            var h = document.getElementById(fe.h);
            var b = document.getElementById(fe.b);
            var d = document.getElementById(fe.d);
            var inp = document.getElementById(fe.i);
            var res = document.getElementById(fe.r);
            if (!h || !b || !d || !inp || !res) return;

            function pintarTodo() {
                res.innerHTML =
                    '<div class="tramite-search-option" data-value=""><span class="tramite-placeholder">Seleccione un estado</span></div>' +
                    estadosLista.map(function (it) {
                        var safe = String(it.nombre).replace(/"/g, '&quot;');
                        return (
                            '<div class="tramite-search-option" data-value="' +
                            String(it.id) +
                            '" data-nombre="' +
                            safe +
                            '"><span>' +
                            textoMayus(it.nombre) +
                            '</span></div>'
                        );
                    }).join('');
                res.querySelectorAll('.tramite-search-option').forEach(function (opt) {
                    opt.addEventListener('click', function () {
                        var v = this.getAttribute('data-value');
                        var n = this.getAttribute('data-nombre') || '';
                        h.value = v || '';
                        setBotonTexto(b, n || 'Seleccione un estado', !v);
                        cerrarCombo(b, d);
                        inp.value = '';
                        h.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
            }

            pintarTodo();

            inp.addEventListener('input', function () {
                var q = this.value.trim();
                clearTimeout(inp._t);
                inp._t = setTimeout(function () {
                    if (!q) {
                        pintarTodo();
                        return;
                    }
                    var r = fuseEstados.search(q);
                    var items = r.map(function (x) {
                        return x.item;
                    });
                    if (!items.length) {
                        mostrarVacío(res);
                        return;
                    }
                    renderOpciones(res, items, q, function (optEl) {
                        var v = optEl.getAttribute('data-value');
                        var n = optEl.getAttribute('data-nombre') || '';
                        h.value = v || '';
                        setBotonTexto(b, n || 'Seleccione un estado', !v);
                        cerrarCombo(b, d);
                        inp.value = '';
                        h.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }, 280);
            });

            d.addEventListener('click', function (e) {
                e.stopPropagation();
            });
            bindAbrirCerrar(b, d);
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                setTimeout(function () {
                    if (d.classList.contains('open')) {
                        inp.focus();
                        inp.select();
                    }
                }, 50);
            });
        })();

        /* --- Institución --- */
        (function () {
            var h = document.getElementById(fi.h);
            var b = document.getElementById(fi.b);
            var d = document.getElementById(fi.d);
            var inp = document.getElementById(fi.i);
            var res = document.getElementById(fi.r);
            if (!h || !b || !d || !inp || !res) return;

            function pintarTodo() {
                res.innerHTML =
                    '<div class="tramite-search-option" data-value=""><span class="tramite-placeholder">Seleccione una institución</span></div>' +
                    institucionesLista.map(function (it) {
                        var safe = String(it.nombre).replace(/"/g, '&quot;');
                        return (
                            '<div class="tramite-search-option" data-value="' +
                            String(it.id) +
                            '" data-nombre="' +
                            safe +
                            '"><span>' +
                            textoMayus(it.nombre) +
                            '</span></div>'
                        );
                    }).join('');
                res.querySelectorAll('.tramite-search-option').forEach(function (opt) {
                    opt.addEventListener('click', function () {
                        var v = this.getAttribute('data-value');
                        var n = this.getAttribute('data-nombre') || '';
                        h.value = v || '';
                        setBotonTexto(b, n || 'Seleccione una institución', !v);
                        cerrarCombo(b, d);
                        inp.value = '';
                        h.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
            }

            pintarTodo();

            inp.addEventListener('input', function () {
                var q = this.value.trim();
                clearTimeout(inp._t);
                inp._t = setTimeout(function () {
                    if (!q) {
                        pintarTodo();
                        return;
                    }
                    var r = fuseInst.search(q);
                    var items = r.map(function (x) {
                        return x.item;
                    });
                    if (!items.length) {
                        mostrarVacío(res);
                        return;
                    }
                    renderOpciones(res, items, q, function (optEl) {
                        var v = optEl.getAttribute('data-value');
                        var n = optEl.getAttribute('data-nombre') || '';
                        h.value = v || '';
                        setBotonTexto(b, n || 'Seleccione una institución', !v);
                        cerrarCombo(b, d);
                        inp.value = '';
                        h.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }, 280);
            });

            d.addEventListener('click', function (e) {
                e.stopPropagation();
            });
            bindAbrirCerrar(b, d);
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                setTimeout(function () {
                    if (d.classList.contains('open')) {
                        inp.focus();
                        inp.select();
                    }
                }, 50);
            });

            if (h.value) {
                var f = institucionesLista.find(function (x) {
                    return String(x.id) === String(h.value);
                });
                if (f) setBotonTexto(b, f.nombre, false);
            }
        })();

        /* --- Municipio (lista dinámica) --- */
        var mh = document.getElementById(fm.h);
        var mb = document.getElementById(fm.b);
        var md = document.getElementById(fm.d);
        var minp = document.getElementById(fm.i);
        var mres = document.getElementById(fm.r);

        function pintarMunicipiosLista(lista) {
            if (!mres) return;
            if (!lista || !lista.length) {
                mres.innerHTML =
                    '<div class="tramite-search-option" data-value=""><span class="tramite-placeholder">Seleccione un municipio</span></div>';
                var opt = mres.querySelector('.tramite-search-option');
                if (opt && mh && mb && md && minp) {
                    opt.addEventListener('click', function () {
                        mh.value = '';
                        setBotonTexto(mb, 'Seleccione un municipio', true);
                        cerrarCombo(mb, md);
                        minp.value = '';
                        mh.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }
                return;
            }
            mres.innerHTML =
                '<div class="tramite-search-option" data-value=""><span class="tramite-placeholder">Seleccione un municipio</span></div>' +
                lista.map(function (it) {
                    var safe = String(it.nombre).replace(/"/g, '&quot;');
                    return (
                        '<div class="tramite-search-option" data-value="' +
                        String(it.id) +
                        '" data-nombre="' +
                        safe +
                        '"><span>' +
                        textoMayus(it.nombre) +
                        '</span></div>'
                    );
                }).join('');
            mres.querySelectorAll('.tramite-search-option').forEach(function (opt) {
                opt.addEventListener('click', function () {
                    var v = this.getAttribute('data-value');
                    var n = this.getAttribute('data-nombre') || '';
                    mh.value = v || '';
                    setBotonTexto(mb, n || 'Seleccione un municipio', !v);
                    cerrarCombo(mb, md);
                    minp.value = '';
                    mh.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
        }

        function refrescarMunicipios(estadoId) {
            if (!mh) return;
            mh.value = '';
            if (!estadoId) {
                mh.disabled = true;
                if (mb) mb.disabled = true;
                if (minp) minp.disabled = true;
                listaMunicipiosActual = [];
                fuseMunicipios = null;
                setBotonTexto(mb, 'Seleccione un municipio', true);
                pintarMunicipiosLista([]);
                mh.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
            var raw = MUN[estadoId] || [];
            listaMunicipiosActual = raw.map(function (m) {
                return { id: String(m.id), nombre: m.nombre };
            });
            fuseMunicipios = new Fuse(listaMunicipiosActual, fuseOptsCombo());
            mh.disabled = false;
            if (mb) mb.disabled = false;
            if (minp) minp.disabled = false;
            setBotonTexto(mb, 'Seleccione un municipio', true);
            pintarMunicipiosLista(listaMunicipiosActual);
            mh.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (minp && mres && mh && mb && md) {
            minp.addEventListener('input', function () {
                var q = this.value.trim();
                clearTimeout(minp._t);
                minp._t = setTimeout(function () {
                    if (!listaMunicipiosActual.length) {
                        mostrarVacío(mres);
                        return;
                    }
                    if (!q) {
                        pintarMunicipiosLista(listaMunicipiosActual);
                        return;
                    }
                    var f = fuseMunicipios || new Fuse(listaMunicipiosActual, fuseOptsCombo());
                    fuseMunicipios = f;
                    var r = f.search(q);
                    var items = r.map(function (x) {
                        return x.item;
                    });
                    if (!items.length) {
                        mostrarVacío(mres);
                        return;
                    }
                    renderOpciones(mres, items, q, function (optEl) {
                        var v = optEl.getAttribute('data-value');
                        var n = optEl.getAttribute('data-nombre') || '';
                        mh.value = v || '';
                        setBotonTexto(mb, n || 'Seleccione un municipio', !v);
                        cerrarCombo(mb, md);
                        minp.value = '';
                        mh.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }, 280);
            });
            md.addEventListener('click', function (e) {
                e.stopPropagation();
            });
            bindAbrirCerrar(mb, md);
            mb.addEventListener('click', function (e) {
                e.stopPropagation();
                setTimeout(function () {
                    if (md.classList.contains('open')) {
                        minp.focus();
                        minp.select();
                    }
                }, 50);
            });
        }

        refrescarMunicipios('');

        window.cneNuevaSolicitudCombosRefrescarMunicipios = refrescarMunicipios;

        window.cneNuevaSolicitudCombosSetEstadoValor = function (id) {
            var h = document.getElementById(fe.h);
            var b = document.getElementById(fe.b);
            if (!h || !b) return;
            if (!id) {
                h.value = '';
                setBotonTexto(b, 'Seleccione un estado', true);
                h.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
            var it = estadosLista.find(function (x) {
                return String(x.id) === String(id);
            });
            if (it) {
                h.value = String(it.id);
                setBotonTexto(b, it.nombre, false);
                h.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        window.cneNuevaSolicitudCombosSetMunicipioValor = function (id) {
            if (!mh || !mb) return;
            if (!id) {
                mh.value = '';
                setBotonTexto(mb, 'Seleccione un municipio', true);
                mh.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
            var it = listaMunicipiosActual.find(function (x) {
                return String(x.id) === String(id);
            });
            if (it) {
                mh.value = String(it.id);
                setBotonTexto(mb, it.nombre, false);
                mh.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        window.cneNuevaSolicitudCombosSyncInstitucionBoton = function () {
            var h = document.getElementById(fi.h);
            var b = document.getElementById(fi.b);
            if (!h || !b) return;
            if (!h.value) {
                setBotonTexto(b, 'Seleccione una institución', true);
                return;
            }
            var it = institucionesLista.find(function (x) {
                return String(x.id) === String(h.value);
            });
            if (it) {
                setBotonTexto(b, it.nombre, false);
            }
        };
    };
})();
