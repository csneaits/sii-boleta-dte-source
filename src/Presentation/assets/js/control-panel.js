(function (window, document) {
    'use strict';

    // =====================================================
    // SISTEMA DE MODALES REUTILIZABLE
    // =====================================================
    var SiiModal = {
        /**
         * Show modal.
         * @param {string} type
         * @param {string} title
         * @param {string} message
         * @param {string|string[]} [details] optional details (string or array of lines)
         */
        show: function(type, title, message, details) {
            console.log('[SiiModal.show] type:', type, 'title:', title, 'message:', message);
            
            var modal = document.getElementById('sii-notification-modal');
            var icon = document.getElementById('sii-modal-icon');
            var titleEl = document.getElementById('sii-modal-title');
            var messageEl = document.getElementById('sii-modal-message');
            var detailsEl = document.getElementById('sii-modal-details');
            var detailsToggle = document.getElementById('sii-modal-details-toggle');
            
            console.log('[SiiModal.show] Elementos:', {
                modal: modal,
                icon: icon,
                titleEl: titleEl,
                messageEl: messageEl
            });
            
            if (!modal || !icon || !titleEl || !messageEl) {
                console.error('[SiiModal.show] Modal elements not found');
                return;
            }
            
            // Configurar icono y título según el tipo
            icon.className = 'sii-modal-icon ' + type;
            titleEl.textContent = title;
            messageEl.textContent = message;
            // Details handling
            if (detailsEl) {
                if (Array.isArray(details)) {
                    detailsEl.textContent = details.join('\n');
                } else if (typeof details === 'string' && details.length) {
                    detailsEl.textContent = details;
                } else {
                    detailsEl.textContent = '';
                }
                if (detailsToggle) {
                    if (detailsEl.textContent && detailsEl.textContent.length) {
                        detailsToggle.style.display = '';
                        detailsToggle.dataset.bound = '';
                    } else {
                        detailsToggle.style.display = 'none';
                    }
                }
            }
            
            // Mostrar modal
            modal.style.display = 'flex';
            console.log('[SiiModal.show] Modal mostrado');
            
            // Evitar scroll del body
            document.body.style.overflow = 'hidden';
        },
        
        hide: function() {
            var modal = document.getElementById('sii-notification-modal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        },
        
        success: function(message, title) {
            this.show('success', title || '¡Éxito!', message);
        },
        
        error: function(message, title) {
            this.show('error', title || 'Error', message);
        },
        
        warning: function(message, title) {
            this.show('warning', title || 'Advertencia', message);
        },
        
        info: function(message, title) {
            this.show('info', title || 'Información', message);
        }
    };
    
    // Exponer globalmente para reutilización
    window.SiiModal = SiiModal;
    
    // Función para procesar notificaciones
    function processNotifications() {
        var noticesData = document.getElementById('sii-notices-data');
        console.log('[SiiModal] Buscando notificaciones...', noticesData);
        
        if (noticesData && noticesData.dataset.notices) {
            console.log('[SiiModal] Datos encontrados:', noticesData.dataset.notices);
            try {
                var notices = JSON.parse(noticesData.dataset.notices);
                console.log('[SiiModal] Notificaciones parseadas:', notices);
                
                if (notices && notices.length > 0) {
                    var notice = notices[0]; // Mostrar la primera notificación
                    console.log('[SiiModal] Mostrando notificación:', notice);

                    var details = notice.details || null;
                    var title = (notice.type === 'error') ? 'Error' : '¡Éxito!';
                    // Mostrar modal con detalles si están presentes
                    if (notice.type === 'error') {
                        SiiModal.show('error', title, notice.message, details);
                    } else {
                        SiiModal.show('success', title, notice.message, details);
                    }
                    // Limpiar para evitar mostrar múltiples veces
                    noticesData.dataset.notices = '';
                } else {
                    console.log('[SiiModal] No hay notificaciones para mostrar');
                }
            } catch (e) {
                console.error('[SiiModal] Error parsing notices:', e);
            }
        } else {
            console.log('[SiiModal] No se encontraron datos de notificaciones');
        }
    }
    
    // Manejar cierre del modal
    function initModal() {
        var closeBtn = document.getElementById('sii-modal-close');
        var modal = document.getElementById('sii-notification-modal');
        var overlay = modal ? modal.querySelector('.sii-modal-overlay') : null;
        
        if (closeBtn && !closeBtn.dataset.bound) {
            closeBtn.dataset.bound = 'true';
            closeBtn.addEventListener('click', function() {
                SiiModal.hide();
            });
        }
        
        if (overlay && !overlay.dataset.bound) {
            overlay.dataset.bound = 'true';
            overlay.addEventListener('click', function() {
                SiiModal.hide();
            });
        }

        // Details toggle
        var detailsToggle = document.getElementById('sii-modal-details-toggle');
        var detailsEl = document.getElementById('sii-modal-details');
        if (detailsToggle && !detailsToggle.dataset.clickBound) {
            detailsToggle.dataset.clickBound = 'true';
            detailsToggle.addEventListener('click', function() {
                if (!detailsEl) return;
                if (detailsEl.style.display === 'none' || detailsEl.style.display === '') {
                    detailsEl.style.display = 'block';
                    detailsToggle.textContent = detailsToggle.textContent || 'Ocultar detalles';
                } else {
                    detailsEl.style.display = 'none';
                    detailsToggle.textContent = detailsToggle.textContent || 'Ver detalles';
                }
            });
        }
        
        // Cerrar con ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal && modal.style.display === 'flex') {
                SiiModal.hide();
            }
        });
        
        // Procesar notificaciones del servidor
        processNotifications();
    }
    
    // Inicializar cuando el DOM esté listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initModal);
    } else {
        // DOM ya está listo
        initModal();
    }
    
    // También procesar notificaciones inmediatamente si ya existen
    if (document.getElementById('sii-notices-data')) {
        setTimeout(processNotifications, 100);
    }

    // =====================================================
    // CÓDIGO EXISTENTE
    // =====================================================
    var cfg = window.siiBoletaControlPanel || {};
    var ajaxUrl = cfg.ajax || window.ajaxurl || '/wp-admin/admin-ajax.php';
    var action = cfg.action || '';
    var queueActionEndpoint = cfg.queueAction || '';
    var nonce = cfg.nonce || '';
    var refreshInterval = parseInt(cfg.refreshInterval || 0, 10);
    var DISABLE_AJAX_TABS = true; // render server-side only
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
    var queueConfirmModal;
    var queueConfirmOverlay;
    var queueConfirmTitle;
    var queueConfirmMessage;
    var queueConfirmAccept;
    var queueConfirmCancel;
    var queueConfirmBaseTitle = '';
    var pendingQueueAction = null;
    var pdfModal;
    var pdfModalOverlay;
    var pdfCloseButtons;
    var pdfFrame;
    var pdfEmpty;
    var pdfOpenNew;
    var pdfTitle;
    var pdfBaseTitle = '';
    var pdfCurrentUrl = '';
    var pdfModalInitialized = false;
    
    var queueFilters = { attempts: '', age: '', from: '', to: '' };
    var logFilters = { status: '', type: '', from: '', to: '', page: 1, limit: 10 };

    (function seedFiltersFromUrl() {
        try {
            var params = new URLSearchParams(window.location.search);
            queueFilters.attempts = params.get('filter_attempts') || '';
            queueFilters.age = params.get('filter_age') || '';
            queueFilters.from = params.get('filter_from') || '';
            queueFilters.to = params.get('filter_to') || '';
            logFilters.status = params.get('logs_status') || '';
            logFilters.type = params.get('logs_type') || '';
            logFilters.from = params.get('logs_from') || '';
            logFilters.to = params.get('logs_to') || '';
            logFilters.page = parseInt(params.get('logs_page') || '1', 10) || 1;
            logFilters.limit = parseInt(params.get('logs_per_page') || '10', 10) || 10;
        } catch (e) {
            queueFilters.attempts = '';
            queueFilters.age = '';
            queueFilters.from = '';
            queueFilters.to = '';
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
    // Legacy details element removed (feature deprecated)
        // Re-resolve confirmation modal nodes each time the tab is re-rendered.
        queueConfirmModal = document.getElementById('sii-queue-confirm-modal');
        queueConfirmOverlay = queueConfirmModal ? queueConfirmModal.querySelector('.sii-modal-overlay') : null;
        queueConfirmTitle = queueConfirmModal ? queueConfirmModal.querySelector('#sii-queue-confirm-title') : null;
        queueConfirmMessage = queueConfirmModal ? queueConfirmModal.querySelector('#sii-queue-confirm-message') : null;
        queueConfirmAccept = document.getElementById('sii-queue-confirm-accept');
        queueConfirmCancel = document.getElementById('sii-queue-confirm-cancel');
        if (queueConfirmTitle && !queueConfirmBaseTitle) {
            queueConfirmBaseTitle = queueConfirmTitle.textContent || '';
        }
    }

    /**
     * Safely parse a fetch Response as JSON. Some server errors return HTML
     * (for example an auth/login page or an error page) which causes a
     * SyntaxError: Unexpected token '<' when using response.json() directly.
     * This helper reads the response as text and attempts JSON.parse with a
     * friendly error message when parsing fails.
     *
     * @param {Response} response
     * @param {string} fallbackMessage
     * @returns {Promise<Object>} resolves with parsed JSON or rejects with Error
     */
    function safeParseJsonResponse(response, fallbackMessage) {
        fallbackMessage = fallbackMessage || 'Invalid JSON response from server';
        return response.text().then(function (txt) {
            var trimmed = (txt || '').trim();
            if (!trimmed) {
                throw new Error(fallbackMessage + ': empty response');
            }
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                var contentType = '';
                try { contentType = (response.headers && response.headers.get) ? (response.headers.get('content-type') || '') : ''; } catch (ex) { contentType = ''; }
                // If the server returned HTML (common for errors), provide a clearer message
                if (contentType.indexOf('text/html') !== -1 || trimmed.charAt(0) === '<') {
                    // Avoid dumping large HTML into the message; show a short hint instead
                    var hint = trimmed.replace(/\s+/g, ' ').slice(0, 160);
                    throw new Error(fallbackMessage + ': server returned HTML (likely an error page). ' + (hint ? (hint + '...') : ''));
                }
                throw new Error(fallbackMessage + ': ' + (e && e.message ? e.message : 'JSON parse error'));
            }
        });
    }

    function getQueueConfirmMessage(action) {
        var texts = (cfg && cfg.texts) ? cfg.texts : {};
        switch (action) {
            case 'process':
                return texts.queueConfirmProcess || 'Se procesará el trabajo seleccionado. ¿Deseas continuar?';
            case 'requeue':
                return texts.queueConfirmRequeue || 'Se reiniciarán los intentos del trabajo seleccionado. ¿Deseas continuar?';
            case 'cancel':
                return texts.queueConfirmCancel || 'Se eliminará el trabajo de la cola. ¿Deseas continuar?';
            default:
                return texts.queueConfirmGeneric || 'Esta acción se ejecutará inmediatamente. ¿Deseas continuar?';
        }
    }

    function closeQueueConfirmModal() {
        if (!queueConfirmModal) {
            return;
        }
        queueConfirmModal.style.display = 'none';
        document.body.style.overflow = '';
        pendingQueueAction = null;
    }

    function initQueueConfirmModal() {
        if (!queueConfirmModal) {
            return;
        }
        if (queueConfirmModal.dataset && queueConfirmModal.dataset.bound === '1') {
            return;
        }
        if (queueConfirmTitle) {
            var titleText = (cfg && cfg.texts && cfg.texts.queueConfirmTitle) ? cfg.texts.queueConfirmTitle : (queueConfirmBaseTitle || queueConfirmTitle.textContent || 'Confirmar acción');
            queueConfirmTitle.textContent = titleText;
        }
        if (queueConfirmAccept && cfg && cfg.texts && cfg.texts.queueConfirmProceed) {
            queueConfirmAccept.textContent = cfg.texts.queueConfirmProceed;
        }
        if (queueConfirmCancel && cfg && cfg.texts && cfg.texts.queueConfirmAbort) {
            queueConfirmCancel.textContent = cfg.texts.queueConfirmAbort;
        }
        if (queueConfirmOverlay) {
            queueConfirmOverlay.addEventListener('click', closeQueueConfirmModal, false);
        }
        if (queueConfirmCancel) {
            queueConfirmCancel.addEventListener('click', closeQueueConfirmModal, false);
        }
        if (queueConfirmAccept) {
            queueConfirmAccept.addEventListener('click', function () {
                if (!pendingQueueAction || !pendingQueueAction.form || !pendingQueueAction.action) {
                    closeQueueConfirmModal();
                    return;
                }
                var actionData = pendingQueueAction;
                pendingQueueAction = null;
                closeQueueConfirmModal();
                performQueueAction(actionData.form, actionData.action);
            }, false);
        }
        queueConfirmModal.dataset.bound = '1';
    }

    function openQueueConfirmModal(form, action, button) {
        refreshDomRefs();
        initQueueConfirmModal();
        if (!queueConfirmModal || !form) {
            pendingQueueAction = null;
            performQueueAction(form, action);
            return;
        }
        pendingQueueAction = { form: form, action: action, button: button || null };
        if (queueConfirmMessage) {
            queueConfirmMessage.textContent = getQueueConfirmMessage(action);
        }
        if (queueConfirmTitle) {
            var titleText = (cfg && cfg.texts && cfg.texts.queueConfirmTitle) ? cfg.texts.queueConfirmTitle : (queueConfirmBaseTitle || queueConfirmTitle.textContent || 'Confirmar acción');
            queueConfirmTitle.textContent = titleText;
        }
        if (queueConfirmAccept && cfg && cfg.texts && cfg.texts.queueConfirmProceed) {
            queueConfirmAccept.textContent = cfg.texts.queueConfirmProceed;
        }
        if (queueConfirmCancel && cfg && cfg.texts && cfg.texts.queueConfirmAbort) {
            queueConfirmCancel.textContent = cfg.texts.queueConfirmAbort;
        }
        queueConfirmModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    

    function refreshAfterQueueAction() {
        if (!tabContent) {
            return;
        }
        var active = tabContent.getAttribute('data-active-tab') || '';
        if (active === 'queue') {
            fetchQueueTabWithFilters();
        } else if (active === 'logs') {
            fetchLogsTabWithFilters();
        } else if (active === 'metrics') {
            requestSnapshot();
        }
    }

    function performQueueAction(form, queueAction) {
        if (!form || !queueActionEndpoint) {
            return;
        }
        var formData = new FormData(form);
        var jobId = formData.get('job_id');
        var nonceField = formData.get('sii_boleta_queue_nonce') || formData.get('_wpnonce') || (cfg.queueNonce || '');
        if (!queueAction || !jobId || !nonceField) {
            showNotice('error', defaultQueueFail);
            return;
        }
        if (form.dataset && form.dataset.processing === '1') {
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
                return safeParseJsonResponse(response, defaultQueueFail);
            })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    var message = payload && payload.data && payload.data.message ? payload.data.message : defaultQueueFail;
                    throw new Error(message);
                }
                var message = payload.data && payload.data.message ? payload.data.message : defaultQueueOk;
                showNotice('success', message);
                refreshAfterQueueAction();
            })
            .catch(function (err) {
                var message = (err && err.message) ? err.message : defaultQueueFail;
                showNotice('error', message);
            })
            .finally(function () {
                if (form.dataset) {
                    form.dataset.processing = '0';
                }
                try {
                    form.removeAttribute('data-sii-submit-action');
                } catch (ex) {
                    // ignore attribute removal errors
                }
                Array.prototype.forEach.call(buttons, function (button) {
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                });
            });
    }

    function initPdfModal() {
        if (pdfModalInitialized) {
            return;
        }

        pdfModal = document.getElementById('sii-pdf-modal');
        if (!pdfModal) {
            pdfModalInitialized = true; // avoid retrying
            return;
        }

        pdfModalOverlay = pdfModal.querySelector('.sii-modal-overlay');
        pdfCloseButtons = pdfModal.querySelectorAll('.sii-modal-close');
        pdfFrame = pdfModal.querySelector('.sii-pdf-frame');
        pdfEmpty = pdfModal.querySelector('.sii-pdf-empty');
        pdfOpenNew = pdfModal.querySelector('.sii-pdf-open-new');
        pdfTitle = pdfModal.querySelector('#sii-pdf-modal-title');
        pdfBaseTitle = pdfTitle ? (pdfTitle.getAttribute('data-base-title') || pdfTitle.textContent || '') : '';

        var closeHandler = function (event) {
            if (event) {
                event.preventDefault();
            }
            closePdfModal();
        };

        if (pdfModalOverlay) {
            pdfModalOverlay.addEventListener('click', closeHandler, false);
        }

        if (pdfCloseButtons && pdfCloseButtons.length) {
            Array.prototype.forEach.call(pdfCloseButtons, function (button) {
                button.addEventListener('click', closeHandler, false);
            });
        }

        document.addEventListener('keydown', function (event) {
            if (pdfModal && pdfModal.style.display === 'flex' && event.key === 'Escape') {
                closePdfModal();
            }
        });

        if (pdfFrame) {
            pdfFrame.addEventListener('load', function () {
                if (pdfEmpty) {
                    var hasSrc = pdfFrame.src && pdfFrame.src !== 'about:blank';
                    pdfEmpty.style.display = hasSrc ? 'none' : '';
                }
                if (pdfOpenNew) {
                    pdfOpenNew.style.display = pdfCurrentUrl ? '' : 'none';
                }
            });
        }

        pdfModalInitialized = true;
    }

    function closePdfModal() {
        if (!pdfModal) {
            return;
        }
        pdfModal.style.display = 'none';
        document.body.style.overflow = '';
        pdfCurrentUrl = '';
        if (pdfFrame) {
            pdfFrame.src = 'about:blank';
        }
        if (pdfOpenNew) {
            pdfOpenNew.href = '#';
            pdfOpenNew.style.display = 'none';
        }
        if (pdfTitle && pdfBaseTitle) {
            pdfTitle.textContent = pdfBaseTitle;
        }
    }

    function openPdfModal(url, dataset) {
        initPdfModal();

        if (!url) {
            showNotice('error', cfg.texts && cfg.texts.previewUnavailable ? cfg.texts.previewUnavailable : 'No se pudo abrir el PDF.');
            return;
        }

        if (!pdfModal) {
            window.open(url, '_blank', 'noopener');
            return;
        }

        pdfCurrentUrl = url;
        if (pdfFrame) {
            pdfFrame.src = url;
            pdfFrame.style.display = 'block';
        }
        if (pdfEmpty) {
            pdfEmpty.style.display = 'none';
        }
        if (pdfOpenNew) {
            pdfOpenNew.href = url;
            pdfOpenNew.style.display = '';
        }
        if (pdfTitle) {
            var extra = [];
            if (dataset && dataset.type) {
                var typeTemplate = (cfg.texts && cfg.texts.previewLabelType) ? cfg.texts.previewLabelType : 'Tipo %s';
                extra.push(typeTemplate.replace('%s', dataset.type));
            }
            if (dataset && dataset.folio) {
                var folioTemplate = (cfg.texts && cfg.texts.previewLabelFolio) ? cfg.texts.previewLabelFolio : 'Folio %s';
                extra.push(folioTemplate.replace('%s', dataset.folio));
            }
            pdfTitle.textContent = extra.length ? pdfBaseTitle + ' · ' + extra.join(' · ') : pdfBaseTitle;
        }

        pdfModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function buildPreviewPdfUrl(dataset) {
        if (!dataset || !dataset.fileKey) {
            return '';
        }
        if (!cfg.previewPdfNonce) {
            return '';
        }
        try {
            var url = new URL(ajaxUrl, window.location.origin);
            url.searchParams.set('action', cfg.previewPdfAction || 'sii_boleta_preview_pdf');
            if (cfg.previewPdfNonce) {
                url.searchParams.set('_wpnonce', cfg.previewPdfNonce);
            }
            url.searchParams.set('file_key', dataset.fileKey);
            if (dataset.orderId) {
                url.searchParams.set('order_id', dataset.orderId);
            }
            if (dataset.type) {
                url.searchParams.set('type', dataset.type);
            }
            if (dataset.folio) {
                url.searchParams.set('folio', dataset.folio);
            }
            return url.toString();
        } catch (err) {
            return '';
        }
    }

    function resolveMetaTypeLabel(type) {
        if (!type) {
            return 'sii_boleta';
        }
        var normalized = String(type).trim();
        switch (normalized) {
            case '61':
                return 'sii_boleta_credit_note';
            case '56':
                return 'sii_boleta_debit_note';
            default:
                return 'sii_boleta';
        }
    }

    function buildViewPdfUrl(dataset) {
        if (!dataset || !dataset.pdfKey || !dataset.pdfNonce) {
            return '';
        }
        try {
            var url = new URL(ajaxUrl, window.location.origin);
            url.searchParams.set('action', cfg.viewPdfAction || 'sii_boleta_dte_view_pdf');
            if (cfg.viewPdfNonce) {
                url.searchParams.set('_wpnonce', cfg.viewPdfNonce);
            }
            url.searchParams.set('key', dataset.pdfKey);
            var metaType = dataset.metaPrefix ? String(dataset.metaPrefix) : resolveMetaTypeLabel(dataset.type);
            if (metaType) {
                url.searchParams.set('type', metaType);
            }
            url.searchParams.set('nonce', dataset.pdfNonce);
            if (dataset.orderId) {
                url.searchParams.set('order_id', dataset.orderId);
            }
            return url.toString();
        } catch (err) {
            return '';
        }
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
            if (filters.from) {
                url.searchParams.set('filter_from', filters.from);
            } else {
                url.searchParams.delete('filter_from');
            }
            if (filters.to) {
                url.searchParams.set('filter_to', filters.to);
            } else {
                url.searchParams.delete('filter_to');
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
        if (queueFilters.from) {
            params.append('filter_from', queueFilters.from);
        }
        if (queueFilters.to) {
            params.append('filter_to', queueFilters.to);
        }
    }

    function initQueueFilters(preserveState) {
        var applyFiltersBtn = document.getElementById('apply-filters');
        var clearFiltersBtn = document.getElementById('clear-filters');
        var showHelpBtn = document.getElementById('show-help');
        var helpPanel = document.getElementById('sii-queue-help');
        var attemptsFilter = document.getElementById('filter_attempts');
        var ageFilter = document.getElementById('filter_age');
        var fromFilter = document.getElementById('filter_from');
        var toFilter = document.getElementById('filter_to');

        if (!applyFiltersBtn && !clearFiltersBtn && !showHelpBtn) {
            return;
        }

        var syncField = function (el, key) {
            if (!el) return;
            if (preserveState || queueFilters[key]) {
                el.value = queueFilters[key] || '';
            } else {
                queueFilters[key] = el.value || '';
            }
        };

        syncField(attemptsFilter, 'attempts');
        syncField(ageFilter, 'age');
        syncField(fromFilter, 'from');
        syncField(toFilter, 'to');

        if (applyFiltersBtn && !applyFiltersBtn.dataset.bound) {
            applyFiltersBtn.dataset.bound = '1';
            applyFiltersBtn.addEventListener('click', function () {
                queueFilters.attempts = attemptsFilter ? attemptsFilter.value : '';
                queueFilters.age = ageFilter ? ageFilter.value : '';
                queueFilters.from = fromFilter ? fromFilter.value : '';
                queueFilters.to = toFilter ? toFilter.value : '';
                // Navegar recargando la página con parámetros GET
                try {
                    var url = new URL(window.location.href);
                    if (queueFilters.attempts) url.searchParams.set('filter_attempts', queueFilters.attempts); else url.searchParams.delete('filter_attempts');
                    if (queueFilters.age) url.searchParams.set('filter_age', queueFilters.age); else url.searchParams.delete('filter_age');
                    if (queueFilters.from) url.searchParams.set('filter_from', queueFilters.from); else url.searchParams.delete('filter_from');
                    if (queueFilters.to) url.searchParams.set('filter_to', queueFilters.to); else url.searchParams.delete('filter_to');
                    window.location.assign(url.toString());
                } catch (e) { window.location.reload(); }
            });
        }

        if (clearFiltersBtn && !clearFiltersBtn.dataset.bound) {
            clearFiltersBtn.dataset.bound = '1';
            clearFiltersBtn.addEventListener('click', function () {
                if (attemptsFilter) attemptsFilter.value = '';
                if (ageFilter) ageFilter.value = '';
                if (fromFilter) fromFilter.value = '';
                if (toFilter) toFilter.value = '';
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('filter_attempts');
                    url.searchParams.delete('filter_age');
                    url.searchParams.delete('filter_from');
                    url.searchParams.delete('filter_to');
                    window.location.assign(url.toString());
                } catch (e) { window.location.reload(); }
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
            .then(function (r) {
                if (!r.ok) {
                    return r.text().then(function (txt) {
                        var msg = txt || 'Network error (' + r.status + ')';
                        throw new Error(msg);
                    });
                }
                return safeParseJsonResponse(r, (cfg.texts && cfg.texts.loadError) ? cfg.texts.loadError : 'Error al cargar el contenido.');
            })
                .then(function (payload) {
                    if (!payload || !payload.success || !payload.data) {
                        var serverMsg = (payload && payload.data && payload.data.message) ? payload.data.message : (payload && payload.message ? payload.message : 'Invalid payload');
                        throw new Error(serverMsg);
                    }
                    tabContent.innerHTML = payload.data.html || '';
                    refreshDomRefs();
                    initQueueConfirmModal();
                    initQueueFilters(true);
                })
                .catch(function (err) {
                    console.error('[sii-boleta] control panel tab error:', err);
                    var message = (err && err.message) ? String(err.message) : (cfg.texts && cfg.texts.loadError ? cfg.texts.loadError : 'Error al cargar el contenido.');
                    tabContent.innerHTML = '<p style="color:#d63638;">' + (message) + '</p>';
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
                try {
                    var url = new URL(window.location.href);
                    if (logFilters.status) url.searchParams.set('logs_status', logFilters.status); else url.searchParams.delete('logs_status');
                    if (logFilters.type) url.searchParams.set('logs_type', logFilters.type); else url.searchParams.delete('logs_type');
                    if (logFilters.from) url.searchParams.set('logs_from', logFilters.from); else url.searchParams.delete('logs_from');
                    if (logFilters.to) url.searchParams.set('logs_to', logFilters.to); else url.searchParams.delete('logs_to');
                    url.searchParams.set('logs_page', '1');
                    window.location.assign(url.toString());
                } catch (e) { window.location.reload(); }
            });
        }

        if (clearBtn && !clearBtn.dataset.bound) {
            clearBtn.dataset.bound = '1';
            clearBtn.addEventListener('click', function () {
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('logs_status');
                    url.searchParams.delete('logs_type');
                    url.searchParams.delete('logs_from');
                    url.searchParams.delete('logs_to');
                    url.searchParams.delete('logs_page');
                    window.location.assign(url.toString());
                } catch (e) { window.location.reload(); }
            });
        }

        if (prevBtn && !prevBtn.dataset.bound) {
            prevBtn.dataset.bound = '1';
            prevBtn.addEventListener('click', function () {
                var page = Math.max(1, (logFilters.page || 1) - 1);
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('logs_page', String(page));
                    window.location.assign(url.toString());
                } catch (e) { window.location.reload(); }
            });
        }

        if (nextBtn && !nextBtn.dataset.bound) {
            nextBtn.dataset.bound = '1';
            nextBtn.addEventListener('click', function () {
                var pagination = document.getElementById('log-pagination');
                var pages = pagination ? parseInt(pagination.getAttribute('data-pages') || '1', 10) || 1 : 1;
                var page = Math.min(pages, (logFilters.page || 1) + 1);
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('logs_page', String(page));
                    window.location.assign(url.toString());
                } catch (e) { window.location.reload(); }
            });
        }
    }

    function fetchLogsTabWithFilters() {
        if (!tabAction || !tabContent) {
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
            .then(function (r) {
                if (!r.ok) {
                    return r.text().then(function (txt) { throw new Error(txt || ('Network error (' + r.status + ')')); });
                }
                return safeParseJsonResponse(r, (cfg.texts && cfg.texts.loadError) ? cfg.texts.loadError : 'Error al cargar el contenido.');
            })
            .then(function (payload) {
                if (!payload || !payload.success || !payload.data) {
                    var serverMsg = (payload && payload.data && payload.data.message) ? payload.data.message : (payload && payload.message ? payload.message : 'Invalid payload');
                    throw new Error(serverMsg);
                }
                tabContent.innerHTML = payload.data.html || '';
                logsBody = document.getElementById('sii-control-logs-body');
                refreshDomRefs();
                initQueueFilters(true);
                initLogFilters(true);
            })
            .catch(function (err) {
                console.error('[sii-boleta] control panel tab (logs) error:', err);
                var message = (err && err.message) ? String(err.message) : (cfg.texts && cfg.texts.loadError ? cfg.texts.loadError : 'Error al cargar el contenido.');
                tabContent.innerHTML = '<p style="color:#d63638;">' + (message) + '</p>';
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
	            return '';
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
		var normalizedHtml = ensureHtml(html, 'logs');
		logsBody.innerHTML = normalizedHtml;

		var tableWrapper = document.querySelector('.sii-log-table-wrapper');
		var emptyState = document.getElementById('sii-log-empty-state');
		var table = document.getElementById('sii-control-logs-table');
		var hasRows = logsBody.querySelectorAll('tr').length > 0 && !logsBody.querySelector('.sii-control-empty-row');

		if (tableWrapper) {
			tableWrapper.style.display = hasRows ? '' : 'none';
		}
		if (table) {
			table.style.display = hasRows ? '' : 'none';
		}
		if (emptyState) {
			emptyState.style.display = hasRows ? 'none' : '';
		}
	}

    function updateQueue(html, hasJobs) {
        refreshDomRefs();
        if (!queueBody) {
            return;
        }
        queueBody.innerHTML = ensureHtml(html, 'queue');
        if (queueEmpty) {
            if (hasJobs) {
                queueEmpty.classList.add('is-hidden');
            } else {
                queueEmpty.classList.remove('is-hidden');
            }
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

    var snapshotErrorNotified = false;

    function requestSnapshot() {
        if (!tabContent) {
            return;
        }
        var activeTab = tabContent.getAttribute('data-active-tab') || '';
        if (activeTab !== 'metrics') {
            return;
        }
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

        // Evitar caches agresivos y ayudar al diagnóstico
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
                    throw new Error('Network error (' + response.status + ')');
                }
                return safeParseJsonResponse(response, 'Invalid payload');
            })
            .then(function (dataObj) {
                if (!dataObj || !dataObj.success || !dataObj.data) {
                    var reason = 'Invalid payload';
                    if (dataObj && dataObj.data && dataObj.data.message) {
                        reason = dataObj.data.message;
                    }
                    console.error('[sii-boleta] Snapshot error:', reason, dataObj);
                    if (!snapshotErrorNotified && reason) {
                        showNotice('error', reason);
                        snapshotErrorNotified = true;
                    }
                    throw new Error('Invalid payload');
                }
                snapshotErrorNotified = false;
                var data = dataObj.data;
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
            .catch(function (err) {
                // Deja pista en consola para depuración; no interrumpe la UI
                console.error('[sii-boleta] Snapshot request failed:', err);
                if (!snapshotErrorNotified) {
                    var msg = (err && err.message) ? String(err.message) : '';
                    if (msg && msg.indexOf('Invalid payload') !== -1) {
                        showNotice('error', 'No se pudo actualizar el panel. Recarga la página.');
                        snapshotErrorNotified = true;
                    }
                }
            })
            .then(finalize, finalize);
    }

    refreshDomRefs();
    initQueueConfirmModal();
    initPdfModal();
    initQueueFilters(false);
    initLogFilters(false);

    document.addEventListener('click', function (event) {
        var previewBtn = event.target.closest('.sii-preview-pdf-btn');
        if (previewBtn) {
            event.preventDefault();
            var dataset = previewBtn.dataset || {};
            var previewUrl = '';

            if (dataset.fileKey) {
                previewUrl = buildPreviewPdfUrl(dataset);
            }

            if (!previewUrl && dataset.pdfKey) {
                previewUrl = buildViewPdfUrl(dataset);
            }

            if (!previewUrl) {
                showNotice('error', cfg.texts && cfg.texts.previewUnavailable ? cfg.texts.previewUnavailable : 'No se pudo abrir el PDF.');
                return;
            }

            openPdfModal(previewUrl, dataset);
            return;
        }
    }, false);

    

    // Toggle de historial de logs por Track ID
    document.addEventListener('click', function (event) {
        var row = event.target.closest('.sii-log-group-toggle');
        if (!row) return;
        var id = row.getAttribute('data-group');
        if (!id) return;
        var detail = document.getElementById(id);
        if (!detail) return;
        var isHidden = window.getComputedStyle(detail).display === 'none';
        detail.style.display = isHidden ? '' : 'none';
        row.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    }, false);

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        var row = event.target.closest('.sii-log-group-toggle');
        if (!row) return;
        event.preventDefault();
        var id = row.getAttribute('data-group');
        if (!id) return;
        var detail = document.getElementById(id);
        if (!detail) return;
        var isHidden = window.getComputedStyle(detail).display === 'none';
        detail.style.display = isHidden ? '' : 'none';
        row.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    }, false);

    

    // Capturar acción de la cola y mostrar confirmación
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest && e.target.closest('.sii-inline-form button[name="queue_action"]');
        if (!btn || !btn.form) {
            return;
        }
        if (!queueActionEndpoint) {
            return;
        }
        e.preventDefault();
        var actionValue = btn.value || '';
        if (!actionValue) {
            showNotice('error', defaultQueueFail);
            return;
        }
        btn.form.setAttribute('data-sii-submit-action', actionValue);
        openQueueConfirmModal(btn.form, actionValue, btn);
    }, false);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.classList || !form.classList.contains('sii-inline-form')) return;
        if (!queueActionEndpoint) return;
        e.preventDefault();
        if (form.dataset && form.dataset.processing === '1') return;
        var actionValue = '';
        if (e.submitter && e.submitter.name === 'queue_action') {
            actionValue = e.submitter.value || '';
        }
        if (!actionValue && form.getAttribute('data-sii-submit-action')) {
            actionValue = form.getAttribute('data-sii-submit-action') || '';
        }
        if (!actionValue) {
            var formData = new FormData(form);
            actionValue = formData.get('queue_action') || '';
        }
        if (!actionValue) {
            showNotice('error', defaultQueueFail);
            return;
        }
        form.setAttribute('data-sii-submit-action', actionValue);
        openQueueConfirmModal(form, actionValue, e.submitter || null);
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.sii-control-refresh');
        if (!btn) return;
        e.preventDefault();
        var active = tabContent ? (tabContent.getAttribute('data-active-tab') || '') : '';
        if (active === 'metrics') {
            requestSnapshot();
        } else if (active === 'queue') {
            fetchQueueTabWithFilters();
        } else if (active === 'logs') {
            fetchLogsTabWithFilters();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && queueConfirmModal && queueConfirmModal.style.display === 'flex') {
            closeQueueConfirmModal();
        }
    });

    var tabsWrapper = document.getElementById('sii-control-panel-tabs');
    if (!DISABLE_AJAX_TABS && tabsWrapper && tabContent && tabAction) {
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
            }).then(function (r) {
                if (!r.ok) { return r.text().then(function (txt) { throw new Error(txt || ('Network error (' + r.status + ')')); }); }
                return safeParseJsonResponse(r, (cfg.texts && cfg.texts.loadError) ? cfg.texts.loadError : 'Error al cargar el contenido.');
            })
              .then(function (payload) {
                  if (!payload || !payload.success || !payload.data) {
                      var serverMsg = (payload && payload.data && payload.data.message) ? payload.data.message : (payload && payload.message ? payload.message : 'Invalid payload');
                      throw new Error(serverMsg);
                  }
                  tabContent.innerHTML = payload.data.html || '';
                  refreshDomRefs();
                  if (tab === 'queue') {
                      initQueueConfirmModal();
                      initQueueFilters(true);
                  } else if (tab === 'logs') {
                      logsBody = document.getElementById('sii-control-logs-body');
                      initLogFilters(true);
                  } else if (tab === 'metrics') {
                      requestSnapshot();
                  }
              })
              .catch(function (err) {
                  console.error('[sii-boleta] control panel tab navigation error:', err);
                  var message = (err && err.message) ? String(err.message) : (cfg.texts && cfg.texts.loadError ? cfg.texts.loadError : 'Error al cargar el contenido.');
                  tabContent.innerHTML = '<p style="color:#d63638;">' + (message) + '</p>';
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
        }).then(function (r) {
            if (!r.ok) { return r.text().then(function (txt) { throw new Error(txt || ('Network error (' + r.status + ')')); }); }
            return safeParseJsonResponse(r, (cfg.texts && cfg.texts.loadError) ? cfg.texts.loadError : 'Error al cargar el contenido.');
        }).then(function (payload) {
            if (!payload || !payload.success || !payload.data) {
                var serverMsg = (payload && payload.data && payload.data.message) ? payload.data.message : (payload && payload.message ? payload.message : 'Invalid payload');
                throw new Error(serverMsg);
            }
            if (tabContent) {
                tabContent.innerHTML = payload.data.html || '';
                requestSnapshot();
            }
        }).catch(function (err) {
            console.error('[sii-boleta] metrics submit error:', err);
            var message = (err && err.message) ? String(err.message) : (cfg.texts && cfg.texts.loadError ? cfg.texts.loadError : 'Error al cargar métricas.');
            if (tabContent) {
                tabContent.innerHTML = '<p style="color:#d63638;">' + (message) + '</p>';
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
        }).then(function (r) {
            if (!r.ok) { return r.text().then(function (txt) { throw new Error(txt || ('Network error (' + r.status + ')')); }); }
            return safeParseJsonResponse(r, (cfg.texts && cfg.texts.loadError) ? cfg.texts.loadError : 'Error al cargar el contenido.');
        }).then(function (payload) {
            if (!payload || !payload.success || !payload.data) {
                var serverMsg = (payload && payload.data && payload.data.message) ? payload.data.message : (payload && payload.message ? payload.message : 'Invalid payload');
                throw new Error(serverMsg);
            }
            if (tabContent) {
                tabContent.innerHTML = payload.data.html || '';
                requestSnapshot();
            }
        }).catch(function (err) {
            console.error('[sii-boleta] metrics reset error:', err);
            if (tabContent) tabContent.innerHTML = '<p style="color:#d63638;">' + ((err && err.message) ? String(err.message) : (cfg.texts && cfg.texts.loadError ? cfg.texts.loadError : 'Error al cargar métricas.')) + '</p>';
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
        }).then(function (r) {
            if (!r.ok) { return r.text().then(function (txt) { throw new Error(txt || ('Network error (' + r.status + ')')); }); }
            return safeParseJsonResponse(r, (cfg.texts && cfg.texts.loadError) ? cfg.texts.loadError : 'Error al cargar el contenido.');
        })
          .then(function (payload) {
              if (!payload || !payload.success || !payload.data) {
                  var serverMsg = (payload && payload.data && payload.data.message) ? payload.data.message : (payload && payload.message ? payload.message : 'Invalid payload');
                  throw new Error(serverMsg);
              }
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
          .catch(function (err) {
              console.error('[sii-boleta] run prune error:', err);
              if (statusEl) statusEl.textContent = (err && err.message) ? String(err.message) : (cfg.texts && cfg.texts.error ? cfg.texts.error : 'Error');
          });
    });

    // Deshabilitado: ya no usamos snapshots periódicos
})(window, document);
