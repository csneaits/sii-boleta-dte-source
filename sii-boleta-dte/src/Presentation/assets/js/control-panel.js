(function (window, document) {
    'use strict';

    var cfg = window.siiBoletaControlPanel || {};
    var ajaxUrl = cfg.ajax || window.ajaxurl || '/wp-admin/admin-ajax.php';
    var action = cfg.action || '';
    var nonce = cfg.nonce || '';
    var refreshInterval = parseInt(cfg.refreshInterval || 0, 10);

    if (!action || !nonce) {
        return;
    }

    var logsBody = document.getElementById('sii-control-logs-body');
    var queueBody = document.getElementById('sii-control-queue-body');
    var queueTable = document.getElementById('sii-control-queue-table');
    var queueEmpty = document.getElementById('sii-control-queue-empty');

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

    function requestSnapshot() {
        var params = new URLSearchParams();
        params.append('action', action);
        params.append('nonce', nonce);

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
            });
    }

    requestSnapshot();

    if (!isNaN(refreshInterval) && refreshInterval > 0) {
        window.setInterval(requestSnapshot, refreshInterval * 1000);
    }
})(window, document);
