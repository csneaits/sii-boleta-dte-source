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
    var queueWrapper;
    var queueFilters = { attempts: '', age: '' };
    var logFilters = { status: '', type: '', from: '', to: '', page: 1, limit: 10 };

    (function seedFiltersFromUrl() {
        try {
            var params = new URLSearchParams(window.location.search);
            queueFilters.attempts = params.get('filter_attempts') || '';
            queueFilters.age = params.get('filter_age') || '';
            logFilters.status = params.get('logs_status') || '';
            logFilters.type = params.get('logs_type') || '';
            logFilters.from = params.get('logs_from') || '';
            logFilters.to = params.get('logs_to') || '';
            logFilters.page = parseInt(params.get('logs_page') || '1', 10) || 1;
            logFilters.limit = parseInt(params.get('logs_per_page') || '10', 10) || 10;
        } catch (e) {
            queueFilters.attempts = '';
            queueFilters.age = '';
            logFilters.status = '';
            logFilters.type = '';
            logFilters.from = '';
            logFilters.to = '';
            logFilters.page = 1;
            logFilters.limit = 10;
        }
    })();

    function refreshDomRefs() {
        queueBody = document.getElementById('sii-control-queue-body');
        queueTable = document.getElementById('sii-control-queue-table');
        queueEmpty = document.getElementById('sii-control-queue-empty');
        queueWrapper = document.querySelector('.sii-control-queue-wrapper');
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

    function updateLogUrlFilters(filters) {
        try {
            var url = new URL(window.location.href);
            if (filters.status) {
                url.searchParams.set('logs_status', filters.status);
            } else {
                url.searchParams.delete('logs_status');
            }
            if (filters.type) {
                url.searchParams.set('logs_type', filters.type);
            } else {
                url.searchParams.delete('logs_type');
            }
            if (filters.from) {
                url.searchParams.set('logs_from', filters.from);
            } else {
                url.searchParams.delete('logs_from');
            }
            if (filters.to) {
                url.searchParams.set('logs_to', filters.to);
            } else {
                url.searchParams.delete('logs_to');
            }
            url.searchParams.set('logs_page', filters.page || 1);
            url.searchParams.set('logs_per_page', filters.limit || 10);
            window.history.replaceState({}, document.title, url.toString());
        } catch (e) {
            // ignore URL update issues
        }
    }

    function appendLogFilters(params) {
        if (!params) return;
        if (logFilters.status) {
            params.append('log_status', logFilters.status);
        }
        if (logFilters.type) {
            params.append('log_type', logFilters.type);
        }
        if (logFilters.from) {
            params.append('log_from', logFilters.from);
        }
        if (logFilters.to) {
            params.append('log_to', logFilters.to);
        }
        params.append('log_page', logFilters.page);
        params.append('log_limit', logFilters.limit);
    }

    function syncLogPagination(page, pages, total, limit) {
        var paginationEl = document.getElementById('log-pagination');
        if (!paginationEl) return;
        paginationEl.setAttribute('data-page', page);
        paginationEl.setAttribute('data-pages', pages);
        paginationEl.setAttribute('data-total', total);
        paginationEl.setAttribute('data-limit', limit);
        var prevBtn = document.getElementById('log-page-prev');
        var nextBtn = document.getElementById('log-page-next');
        if (prevBtn) {
            prevBtn.disabled = page <= 1;
        }
        if (nextBtn) {
            nextBtn.disabled = page >= pages;
        }
    }

    function updateLogSummary(total, page, limit, visibleCount) {
        var summary = document.getElementById('log-summary');
        if (!summary) return;
        summary.setAttribute('data-total', total);
        summary.setAttribute('data-page', page);
        summary.setAttribute('data-limit', limit);
        var pages = Math.max(1, Math.ceil(total / limit));
        summary.setAttribute('data-pages', pages);

        var start = total > 0 ? ( ( page - 1 ) * limit ) + 1 : 0;
        var end = total > 0 ? Math.min( start + visibleCount - 1, total ) : 0;
        if (total > 0 && visibleCount > 0) {
            summary.innerHTML = '<p>' + (cfg.texts && cfg.texts.logsSummary
                ? cfg.texts.logsSummary.replace('%start%', start).replace('%end%', end).replace('%total%', total)
                : 'Mostrando ' + start + '-' + end + ' de ' + total + ' registros') + '</p>';
        } else {
            summary.innerHTML = '<p>' + (cfg.texts && cfg.texts.noLogs ? cfg.texts.noLogs : 'Sin DTE recientes.') + '</p>';
        }
    }

    function initLogFilters(preserveState) {
        var statusField = document.getElementById('log_status');
        var typeField = document.getElementById('log_type');
        var fromField = document.getElementById('log_from');
        var toField = document.getElementById('log_to');
        var applyBtn = document.getElementById('log-apply-filters');
        var clearBtn = document.getElementById('log-clear-filters');
        var prevBtn = document.getElementById('log-page-prev');
        var nextBtn = document.getElementById('log-page-next');
        var paginationEl = document.getElementById('log-pagination');

        if (!statusField && !typeField && !fromField && !toField && !applyBtn && !clearBtn && !paginationEl && !prevBtn && !nextBtn) {
            return;
        }

        if (paginationEl) {
            var pageDom = parseInt(paginationEl.getAttribute('data-page') || logFilters.page, 10) || 1;
            var limitDom = parseInt(paginationEl.getAttribute('data-limit') || logFilters.limit, 10) || logFilters.limit;
            var pagesDom = parseInt(paginationEl.getAttribute('data-pages') || '1', 10) || 1;
            var totalDom = parseInt(paginationEl.getAttribute('data-total') || '0', 10) || 0;
            logFilters.page = pageDom;
            logFilters.limit = limitDom;
            syncLogPagination(logFilters.page, pagesDom, totalDom, logFilters.limit);
        }

        if (statusField) {
            statusField.value = logFilters.status || '';
        }
        if (typeField) {
            typeField.value = logFilters.type || '';
        }
        if (fromField) {
            fromField.value = logFilters.from || '';
        }
        if (toField) {
            toField.value = logFilters.to || '';
        }

        if (applyBtn && !applyBtn.dataset.bound) {
            applyBtn.dataset.bound = '1';
            applyBtn.addEventListener('click', function () {
                logFilters.status = statusField ? statusField.value : '';
                logFilters.type = typeField ? typeField.value : '';
                logFilters.from = fromField ? fromField.value : '';
                logFilters.to = toField ? toField.value : '';
                logFilters.page = 1;
                updateLogUrlFilters(logFilters);
                fetchLogsTabWithFilters();
            });
        }

        if (clearBtn && !clearBtn.dataset.bound) {
            clearBtn.dataset.bound = '1';
            clearBtn.addEventListener('click', function () {
                logFilters.status = '';
                logFilters.type = '';
                logFilters.from = '';
                logFilters.to = '';
                logFilters.page = 1;
                updateLogUrlFilters(logFilters);
                fetchLogsTabWithFilters();
            });
        }

        if (prevBtn && !prevBtn.dataset.bound) {
            prevBtn.dataset.bound = '1';
            prevBtn.addEventListener('click', function () {
                if (logFilters.page > 1) {
                    logFilters.page -= 1;
                    updateLogUrlFilters(logFilters);
                    fetchLogsTabWithFilters();
                }
            });
        }

        if (nextBtn && !nextBtn.dataset.bound) {
            nextBtn.dataset.bound = '1';
            nextBtn.addEventListener('click', function () {
                var pagination = document.getElementById('log-pagination');
                var pages = pagination ? parseInt(pagination.getAttribute('data-pages') || '1', 10) || 1 : 1;
                if (logFilters.page < pages) {
                    logFilters.page += 1;
                    updateLogUrlFilters(logFilters);
                    fetchLogsTabWithFilters();
                }
            });
        }
    }

    function fetchLogsTabWithFilters() {
        if (!tabAction || !tabContent) {
            requestSnapshot();
            return;
        }

        tabContent.setAttribute('data-active-tab', 'logs');
        tabContent.innerHTML = '<p style="padding:8px 0;">' + (cfg.texts && cfg.texts.loading ? cfg.texts.loading : 'Cargando…') + '</p>';

        var params = new URLSearchParams();
        params.append('action', tabAction);
        params.append('nonce', nonce);
        params.append('tab', 'logs');
        appendLogFilters(params);

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
                logsBody = document.getElementById('sii-control-logs-body');
                refreshDomRefs();
                initQueueFilters(true);
                initLogFilters(true);
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
            return '<tr class="sii-control-empty-row"><td colspan="5">' + (cfg.texts && cfg.texts.noLogs ? cfg.texts.noLogs : '') + '</td></tr>';
        }
        if (type === 'queue') {
            return '<tr class="sii-control-empty-row"><td colspan="5">' + (cfg.texts && cfg.texts.noQueue ? cfg.texts.noQueue : '') + '</td></tr>';
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
        if (queueWrapper) {
            queueWrapper.style.display = hasJobs ? '' : 'none';
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
        appendLogFilters(params);

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
                if (typeof data.logsTotal !== 'undefined') {
                    var page = data.logsPage || logFilters.page || 1;
                    var pages = data.logsPages || Math.max(1, Math.ceil((data.logsTotal || 0) / (data.logsLimit || logFilters.limit || 10)));
                    var limit = data.logsLimit || logFilters.limit || 10;
                    var count = data.logsCount || 0;
                    logFilters.page = page;
                    logFilters.limit = limit;
                    updateLogSummary(data.logsTotal || 0, page, limit, count);
                    syncLogPagination(page, pages, data.logsTotal || 0, limit);
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
    initLogFilters(false);
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
            if (tab === 'logs') {
                appendLogFilters(params);
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
                  if (tab === 'queue') {
                      requestSnapshot();
                      initQueueFilters(true);
                  } else if (tab === 'logs') {
                      logsBody = document.getElementById('sii-control-logs-body');
                      initLogFilters(true);
                      requestSnapshot();
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
