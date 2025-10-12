(function (window, document) {
    'use strict';

    var cfg = window.siiBoletaControlPanel || {};
    var ajaxUrl = cfg.ajax || window.ajaxurl || '/wp-admin/admin-ajax.php';
    var action = cfg.action || '';
    var queueActionEndpoint = cfg.queueAction || '';
    var nonce = cfg.nonce || '';
    var refreshInterval = parseInt(cfg.refreshInterval || 0, 10);
    var tabAction = cfg.tabAction || '';
    var isRefreshing = false;

    if (!action || !nonce) {
        return;
    }

    var logsBody = document.getElementById('sii-control-logs-body');
    var noticesContainer = document.getElementById('sii-control-panel-notices');
    var tabContent = document.getElementById('sii-control-tab-content');
    var defaultQueueOk = (cfg.texts && cfg.texts.queueActionOk) ? cfg.texts.queueActionOk : 'Acción de cola ejecutada.';
    var defaultQueueFail = (cfg.texts && cfg.texts.queueActionFail) ? cfg.texts.queueActionFail : 'No se pudo ejecutar la acción seleccionada.';
    var queueBody;
    var queueTable;
    var queueEmpty;
    var queueFilters = { attempts: '', age: '' };

    (function seedFiltersFromUrl() {
        try {
            var params = new URLSearchParams(window.location.search);
            queueFilters.attempts = params.get('filter_attempts') || '';
            queueFilters.age = params.get('filter_age') || '';
        } catch (e) {
            queueFilters.attempts = '';
            queueFilters.age = '';
        }
    })();

    function refreshDomRefs() {
        queueBody = document.getElementById('sii-control-queue-body');
        queueTable = document.getElementById('sii-control-queue-table');
        queueEmpty = document.getElementById('sii-control-queue-empty');
    }

    function updateUrlFilters(filters) {
        try {
            var url = new URL(window.location.href);
            if (filters.attempts) {
                url.searchParams.set('filter_attempts', filters.attempts);
            } else {
                url.searchParams.delete('filter_attempts');
            }
            if (filters.age) {
                url.searchParams.set('filter_age', filters.age);
            } else {
                url.searchParams.delete('filter_age');
            }
            window.history.replaceState({}, document.title, url.toString());
        } catch (e) {
            // Ignore browsers without URL API support.
        }
    }

    function appendQueueFilters(params) {
        if (!params) return;
        if (queueFilters.attempts) {
            params.append('filter_attempts', queueFilters.attempts);
        }
        if (queueFilters.age) {
            params.append('filter_age', queueFilters.age);
        }
    }

    function initQueueFilters(preserveState) {
        var applyFiltersBtn = document.getElementById('apply-filters');
        var clearFiltersBtn = document.getElementById('clear-filters');
        var showHelpBtn = document.getElementById('show-help');
        var helpPanel = document.getElementById('sii-queue-help');
        var attemptsFilter = document.getElementById('filter_attempts');
        var ageFilter = document.getElementById('filter_age');

        if (!applyFiltersBtn && !clearFiltersBtn && !showHelpBtn) {
            return;
        }

        if (preserveState) {
            if (attemptsFilter) {
                attemptsFilter.value = queueFilters.attempts || '';
            }
            if (ageFilter) {
                ageFilter.value = queueFilters.age || '';
            }
        } else {
            if (attemptsFilter && !queueFilters.attempts) {
                queueFilters.attempts = attemptsFilter.value || '';
            }
            if (ageFilter && !queueFilters.age) {
                queueFilters.age = ageFilter.value || '';
            }
        }

        if (applyFiltersBtn && !applyFiltersBtn.dataset.bound) {
            applyFiltersBtn.dataset.bound = '1';
            applyFiltersBtn.addEventListener('click', function () {
                queueFilters.attempts = attemptsFilter ? attemptsFilter.value : '';
                queueFilters.age = ageFilter ? ageFilter.value : '';
                updateUrlFilters(queueFilters);
                fetchQueueTabWithFilters();
            });
        }

        if (clearFiltersBtn && !clearFiltersBtn.dataset.bound) {
            clearFiltersBtn.dataset.bound = '1';
            clearFiltersBtn.addEventListener('click', function () {
                queueFilters.attempts = '';
                queueFilters.age = '';
                updateUrlFilters(queueFilters);
                fetchQueueTabWithFilters();
            });
        }

        if (showHelpBtn && !showHelpBtn.dataset.bound) {
            showHelpBtn.dataset.bound = '1';
            showHelpBtn.addEventListener('click', function () {
                if (!helpPanel) return;
                helpPanel.style.display = helpPanel.style.display === 'none' ? 'block' : 'none';
            });
        }
    }

    function fetchQueueTabWithFilters() {
        if (!tabAction || !tabContent) {
            requestSnapshot();
            return;
        }

        tabContent.setAttribute('data-active-tab', 'queue');
        tabContent.innerHTML = '<p style="padding:8px 0;">' + (cfg.texts && cfg.texts.loading ? cfg.texts.loading : 'Cargando…') + '</p>';

        var params = new URLSearchParams();
        params.append('action', tabAction);
        params.append('nonce', nonce);
        params.append('tab', 'queue');
        appendQueueFilters(params);

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        })
            .then(function (r) { if (!r.ok) throw new Error('net'); return r.json(); })
            .then(function (payload) {
                if (!payload || !payload.success || !payload.data) throw new Error('bad');
                tabContent.innerHTML = payload.data.html || '';
                refreshDomRefs();
                initQueueFilters(true);
                requestSnapshot();
            })
            .catch(function () {
                tabContent.innerHTML = '<p style="color:#d63638;">' + (cfg.texts && cfg.texts.loadError ? cfg.texts.loadError : 'Error al cargar el contenido.') + '</p>';
            });
    }

    function showNotice(type, message) {
        if (!noticesContainer || !message) {
            return;
        }
        noticesContainer.innerHTML = '';
        var div = document.createElement('div');
        div.className = 'notice ' + (type === 'error' ? 'notice-error' : 'notice-success');
        var p = document.createElement('p');
        p.textContent = message;
        div.appendChild(p);
        noticesContainer.appendChild(div);
    }

    function ensureHtml(html, type) {
        if (typeof html === 'string' && html.length > 0) {
            return html;
        }
        if (type === 'logs') {
            return '<tr class="sii-control-empty-row"><td colspan="2">' + (cfg.texts && cfg.texts.noLogs ? cfg.texts.noLogs : '') + '</td></tr>';
        }
        if (type === 'queue') {
            return '<tr class="sii-control-empty-row"><td colspan="4">' + (cfg.texts && cfg.texts.noQueue ? cfg.texts.noQueue : '') + '</td></tr>';
        }
        return '';
    }

    function updateLogs(html) {
        if (!logsBody) {
            return;
        }
        logsBody.innerHTML = ensureHtml(html, 'logs');
    }

    function updateQueue(html, hasJobs) {
        refreshDomRefs();
        if (!queueBody) {
            return;
        }
        queueBody.innerHTML = ensureHtml(html, 'queue');
        if (queueEmpty) {
            queueEmpty.style.display = hasJobs ? 'none' : '';
        }
        if (queueTable) {
            queueTable.style.display = hasJobs ? '' : 'none';
        }
    }

    function setRefreshButtonsState(disabled) {
        var buttons = document.querySelectorAll('.sii-control-refresh');
        Array.prototype.forEach.call(buttons, function (button) {
            button.disabled = !!disabled;
            if (disabled) {
                button.setAttribute('aria-busy', 'true');
            } else {
                button.removeAttribute('aria-busy');
            }
        });
    }

    function requestSnapshot() {
        if (isRefreshing) {
            return;
        }
        isRefreshing = true;
        setRefreshButtonsState(true);
        var finalize = function () {
            isRefreshing = false;
            setRefreshButtonsState(false);
        };
        var params = new URLSearchParams();
        params.append('action', action);
        params.append('nonce', nonce);
        appendQueueFilters(params);

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Network error');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.success || !payload.data) {
                    throw new Error('Invalid payload');
                }
                var data = payload.data;
                if (typeof data.logsHtml !== 'undefined') {
                    updateLogs(data.logsHtml);
                }
                if (typeof data.queueHtml !== 'undefined') {
                    updateQueue(data.queueHtml, !!data.queueHasJobs);
                }
            })
            .catch(function () {
                // Silently ignore errors to avoid interrupting the UI.
            })
            .then(finalize, finalize);
    }

    refreshDomRefs();
    initQueueFilters(false);
    requestSnapshot();

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.classList || !form.classList.contains('sii-inline-form')) return;
        if (!queueActionEndpoint) return;
        e.preventDefault();
        if (form.dataset && form.dataset.processing === '1') return;
        var formData = new FormData(form);
        var queueAction = formData.get('queue_action');
        var jobId = formData.get('job_id');
        var nonceField = formData.get('sii_boleta_queue_nonce') || formData.get('_wpnonce');
        if (!queueAction || !jobId || !nonceField) {
            showNotice('error', defaultQueueFail);
            return;
        }

        var buttons = form.querySelectorAll('button');
        Array.prototype.forEach.call(buttons, function (button) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        });
        if (form.dataset) {
            form.dataset.processing = '1';
        }

        var params = new URLSearchParams();
        params.append('action', queueActionEndpoint);
        params.append('nonce', nonceField);
        params.append('queue_action', queueAction);
        params.append('job_id', jobId);
        appendQueueFilters(params);

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(defaultQueueFail);
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    var message = payload && payload.data && payload.data.message ? payload.data.message : defaultQueueFail;
                    throw new Error(message);
                }
                var message = payload.data && payload.data.message ? payload.data.message : defaultQueueOk;
                showNotice('success', message);
                requestSnapshot();
            })
            .catch(function (err) {
                var message = (err && err.message) ? err.message : defaultQueueFail;
                showNotice('error', message);
            })
            .finally(function () {
                if (form.dataset) {
                    form.dataset.processing = '0';
                }
                Array.prototype.forEach.call(buttons, function (button) {
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                });
            });
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.sii-control-refresh');
        if (!btn) return;
        e.preventDefault();
        requestSnapshot();
    });

    var tabsWrapper = document.getElementById('sii-control-panel-tabs');
    if (tabsWrapper && tabContent && tabAction) {
        tabsWrapper.addEventListener('click', function (e) {
            var a = e.target.closest('a.nav-tab');
            if (!a) return;
            e.preventDefault();
            var url = new URL(a.getAttribute('href'), window.location.origin);
            var tab = url.searchParams.get('tab') || 'logs';
            if (a.classList.contains('nav-tab-active')) return;
            Array.prototype.forEach.call(tabsWrapper.querySelectorAll('a.nav-tab'), function (el) {
                el.classList.remove('nav-tab-active');
            });
            a.classList.add('nav-tab-active');
            tabContent.setAttribute('data-active-tab', tab);
            tabContent.innerHTML = '<p style="padding:8px 0;">' + (cfg.texts && cfg.texts.loading ? cfg.texts.loading : 'Cargando…') + '</p>';
            var params = new URLSearchParams();
            params.append('action', tabAction);
            params.append('nonce', nonce);
            params.append('tab', tab);
            if (tab === 'queue') {
                appendQueueFilters(params);
            }
            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            }).then(function (r) { if (!r.ok) throw new Error('net'); return r.json(); })
              .then(function (payload) {
                  if (!payload || !payload.success || !payload.data) throw new Error('bad');
                  tabContent.innerHTML = payload.data.html || '';
                  refreshDomRefs();
                  if (tab === 'logs' || tab === 'queue') {
                      requestSnapshot();
                      initQueueFilters(true);
                  }
              })
              .catch(function () {
                  tabContent.innerHTML = '<p style="color:#d63638;">' + (cfg.texts && cfg.texts.loadError ? cfg.texts.loadError : 'Error al cargar el contenido.') + '</p>';
              });
        });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('sii-metric-filter')) return;
        if (!nonce) return;
        e.preventDefault();
        var formData = new FormData(form);
        var params = new URLSearchParams();
        params.append('action', 'sii_boleta_dte_metrics_filter');
        params.append('nonce', nonce);
        params.append('metrics_year', formData.get('metrics_year') || '');
        params.append('metrics_month', formData.get('metrics_month') || '');
        if (tabContent) {
            tabContent.innerHTML = '<p style="padding:8px 0;">' + (cfg.texts && cfg.texts.loading ? cfg.texts.loading : 'Cargando…') + '</p>';
        }
        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        }).then(function (r) { if (!r.ok) throw new Error('net'); return r.json(); })
          .then(function (payload) {
              if (!payload || !payload.success || !payload.data) throw new Error('bad');
              if (tabContent) {
                  tabContent.innerHTML = payload.data.html || '';
              }
          })
          .catch(function () {
              if (tabContent) {
                  tabContent.innerHTML = '<p style="color:#d63638;">' + (cfg.texts && cfg.texts.loadError ? cfg.texts.loadError : 'Error al cargar métricas.') + '</p>';
              }
          });
    });

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-metrics-reset="1"]');
        if (!link) return;
        if (!nonce) return;
        e.preventDefault();
        if (tabContent) {
            tabContent.innerHTML = '<p style="padding:8px 0;">' + (cfg.texts && cfg.texts.loading ? cfg.texts.loading : 'Cargando…') + '</p>';
        }
        var params = new URLSearchParams();
        params.append('action', 'sii_boleta_dte_metrics_filter');
        params.append('nonce', nonce);
        params.append('metrics_year', '');
        params.append('metrics_month', '');
        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        }).then(function (r) { if (!r.ok) throw new Error('net'); return r.json(); })
          .then(function (payload) {
              if (!payload || !payload.success || !payload.data) throw new Error('bad');
              if (tabContent) tabContent.innerHTML = payload.data.html || '';
          })
          .catch(function () {
              if (tabContent) tabContent.innerHTML = '<p style="color:#d63638;">' + (cfg.texts && cfg.texts.loadError ? cfg.texts.loadError : 'Error al cargar métricas.') + '</p>';
          });
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('#sii-run-prune-debug');
        if (!btn) return;
        if (!nonce) return;
        e.preventDefault();
        var statusEl = document.getElementById('sii-run-prune-status');
        if (statusEl) {
            statusEl.textContent = (cfg.texts && cfg.texts.loading ? cfg.texts.loading : 'Procesando…');
        }
        var params = new URLSearchParams();
        params.append('action', 'sii_boleta_dte_run_prune');
        params.append('nonce', nonce);
        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        }).then(function (r) { if (!r.ok) throw new Error('net'); return r.json(); })
          .then(function (payload) {
              if (!payload || !payload.success || !payload.data) throw new Error('bad');
              if (statusEl) {
                  statusEl.textContent = (cfg.texts && cfg.texts.done ? cfg.texts.done : 'Listo') + ' (' + (payload.data.deleted || 0) + ')';
              }
              if (payload.data.html) {
                  var maintenanceContent = document.getElementById('sii-control-tab-content');
                  if (maintenanceContent && maintenanceContent.getAttribute('data-active-tab') === 'maintenance') {
                      maintenanceContent.innerHTML = payload.data.html;
                  }
              }
          })
          .catch(function () {
              if (statusEl) statusEl.textContent = (cfg.texts && cfg.texts.error ? cfg.texts.error : 'Error');
          });
    });

    if (!isNaN(refreshInterval) && refreshInterval > 0) {
        window.setInterval(requestSnapshot, refreshInterval * 1000);
    }
})(window, document);
