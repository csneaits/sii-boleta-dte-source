(function(){
    document.addEventListener('DOMContentLoaded', function(){
        var addBtn = document.getElementById('sii-add-item');
        var table = document.getElementById('sii-items-table');
        var tableBody = null;
        if (table){
            tableBody = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
            if (!tableBody){
                tableBody = document.createElement('tbody');
                table.appendChild(tableBody);
            }
        }
        var tipoSelect = document.getElementById('sii-tipo');
        var form = document.getElementById('sii-generate-dte-form');
        var previewSubmit = form ? form.querySelector('[name="preview"]') : null;
        var notices = document.getElementById('sii-generate-dte-notices');
        var modal = document.getElementById('sii-dte-modal');
        var modalFrame = modal ? document.getElementById('sii-dte-modal-frame') : null;
        var modalBackdrop = modal ? modal.querySelector('.sii-dte-modal-backdrop') : null;
        var modalClose = modal ? modal.querySelector('.sii-dte-modal-close') : null;
        var texts = (window.siiBoletaGenerate && window.siiBoletaGenerate.texts) ? window.siiBoletaGenerate.texts : {};
        var ajaxUrl = (window.siiBoletaGenerate && window.siiBoletaGenerate.ajax) ? window.siiBoletaGenerate.ajax : (window.ajaxurl || '/wp-admin/admin-ajax.php');
        var previewAction = (window.siiBoletaGenerate && window.siiBoletaGenerate.previewAction) ? window.siiBoletaGenerate.previewAction : 'sii_boleta_dte_generate_preview';

        function showNotice(type, message, link){
            if(!notices){return;}
            notices.innerHTML = '';
            if(!message){return;}
            var notice = document.createElement('div');
            notice.className = 'notice notice-' + type;
            var p = document.createElement('p');
            p.textContent = message;
            if (link && link.href && link.text){
                var dash = document.createTextNode(' - ');
                var a = document.createElement('a');
                a.href = link.href;
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = link.text;
                p.appendChild(dash);
                p.appendChild(a);
            }
            notice.appendChild(p);
            notices.appendChild(notice);
        }

        function openModal(url){
            if(!modal || !modalFrame || !url){return;}
            modalFrame.src = url;
            modal.style.display = 'block';
            if (document.body){
                document.body.classList.add('sii-dte-modal-open');
            }
        }

        function closeModal(){
            if(!modal){return;}
            modal.style.display = 'none';
            if (modalFrame){ modalFrame.src = ''; }
            if (document.body){
                document.body.classList.remove('sii-dte-modal-open');
            }
        }

        if (modalBackdrop){ modalBackdrop.addEventListener('click', closeModal); }
        if (modalClose){ modalClose.addEventListener('click', closeModal); }
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape'){ closeModal(); } });
        window.addEventListener('sii-boleta-open-preview', function(ev){
            var detail = ev && ev.detail ? ev.detail : ev;
            var url = detail && detail.url ? detail.url : (modal ? modal.getAttribute('data-preview-url') : '');
            if (url){ openModal(url); }
        });
        var modalAttr = modal ? modal.getAttribute('data-preview-url') : '';
        if (modalAttr){
            openModal(modalAttr);
        }
        function initRow(row){
            var desc = row.querySelector('input[name*="[desc]"]');
            var price = row.querySelector('input[name*="[price]"]');
            if(!desc){return;}
            var listId = 'sii-prod-' + Math.random().toString(36).slice(2);
            var dl = document.createElement('datalist');
            dl.id = listId;
            desc.setAttribute('list', listId);
            desc.parentNode.appendChild(dl);
            var cache = {};
            desc.addEventListener('input', function(){
                var term = desc.value.trim();
                if(term.length < 2){return;}
                var params = new URLSearchParams({
                    action: 'sii_boleta_dte_search_products',
                    q: term,
                    _ajax_nonce: (window.siiBoletaGenerate && window.siiBoletaGenerate.nonce) ? window.siiBoletaGenerate.nonce : ''
                });
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: params
                }).then(function(r){return r.json();}).then(function(resp){
                    if(!resp || !resp.success){return;}
                    dl.innerHTML = '';
                    cache = {};
                    resp.data.items.forEach(function(p){
                        var opt = document.createElement('option');
                        var disp = p.sku ? (p.sku + ' — ' + p.name) : p.name;
                        opt.value = disp;
                        opt.dataset.price = p.price;
                        opt.dataset.name = p.name;
                        opt.dataset.sku = p.sku || '';
                        dl.appendChild(opt);
                        cache[disp] = p;
                    });
                });
            });
            desc.addEventListener('change', function(){
                var val = desc.value;
                if(cache[val]){
                    var p = cache[val];
                    if(price){ price.value = p.price; }
                    // Insert SKU into description for traceability
                    if (p.sku){ desc.value = p.name + ' (' + p.sku + ')'; }
                }
            });
        }
        function addRow(){
            if (!tableBody) return;
            var row = document.createElement('tr');
            row.innerHTML = '<td><input type="text" name="items[][desc]" class="regular-text" /></td>'+
                            '<td><input type="number" name="items[][qty]" value="1" step="0.01" /></td>'+
                            '<td><input type="number" name="items[][price]" value="0" step="0.01" /></td>'+
                            '<td><button type="button" class="button remove-item">×</button></td>';
            tableBody.appendChild(row);
            initRow(row);
        }
        // Toggle sections first to ensure the form adapts immediately
        function toggleSections(){
            if (!tipoSelect) return;
            var t = parseInt(tipoSelect.value || '39', 10);
            document.querySelectorAll('.dte-section').forEach(function(sec){
                var attr = sec.getAttribute('data-types') || '';
                var types = attr ? attr.split(',').map(function(s){return parseInt(s,10);}) : [];
                var show = !types.length || types.indexOf(t) !== -1;
                sec.style.display = show ? '' : 'none';
                sec.querySelectorAll('input,select,textarea,button').forEach(function(el){
                    if (show){ el.removeAttribute('disabled'); }
                    else { el.setAttribute('disabled','disabled'); }
                });
            });

            // Update labels/requireds for Boletas vs Facturas
            var razonLabel = document.querySelector('label[for="sii-razon"]');
            var rutInput   = document.getElementById('sii-rut');
            var razonInput = document.getElementById('sii-razon');
            var giroRow    = document.getElementById('label-giro') ? document.getElementById('label-giro').closest('tr') : null;
            var isBoleta   = (t === 39 || t === 41);
            if (razonLabel){ razonLabel.textContent = isBoleta ? 'Nombre' : 'Razón Social'; }
            if (rutInput){ if (isBoleta) rutInput.removeAttribute('required'); else rutInput.setAttribute('required','required'); }
            if (razonInput){ if (isBoleta) razonInput.removeAttribute('required'); else razonInput.setAttribute('required','required'); }
            if (giroRow){
                // Giro ya es dte-section para no boletas, pero aseguramos display acorde
                giroRow.style.display = isBoleta ? 'none' : '';
                giroRow.querySelectorAll('input').forEach(function(el){
                    if (isBoleta) { el.setAttribute('disabled','disabled'); }
                    else { el.removeAttribute('disabled'); }
                });
            }
        }

        if (tipoSelect){
            tipoSelect.addEventListener('change', toggleSections);
            // Run on load
            toggleSections();
        }

        if (tableBody){
            Array.prototype.forEach.call(tableBody.querySelectorAll('tr'), initRow);
        }
        if (addBtn && tableBody){
            addBtn.addEventListener('click', function(e){
                e.preventDefault();
                addRow();
            });
            tableBody.addEventListener('click', function(e){
                if (e.target.classList.contains('remove-item')){
                    e.preventDefault();
                    var tr = e.target.closest('tr');
                    if (tr){ tr.remove(); }
                }
            });
        }

        if (form && previewSubmit && typeof window.fetch === 'function' && typeof window.URLSearchParams !== 'undefined'){
            var triggerPreview = function(e){
                if (e && (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey)){
                    return;
                }
                if (e){ e.preventDefault(); }
                if (form.getAttribute('data-preview-loading') === '1'){
                    return;
                }
                form.setAttribute('data-preview-loading', '1');
                var originalValue = previewSubmit.value;
                var loadingText = texts.loading || originalValue;
                previewSubmit.value = loadingText;
                previewSubmit.disabled = true;
                previewSubmit.classList.add('updating-message');

                var reset = function(){
                    form.removeAttribute('data-preview-loading');
                    previewSubmit.disabled = false;
                    previewSubmit.classList.remove('updating-message');
                    previewSubmit.value = originalValue;
                };

                var formData = new FormData(form);
                formData.set('preview', '1');
                formData.set('action', previewAction);
                var params = new URLSearchParams();
                formData.forEach(function(val, key){
                    params.append(key, val);
                });
                var errorMessage = texts.previewError || 'Could not generate preview. Please try again.';
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: params.toString()
                }).then(function(resp){
                    if (!resp.ok){ throw new Error(errorMessage); }
                    return resp.json();
                }).then(function(data){
                    if (!data || !data.success){
                        var msg = data && data.data && data.data.message ? data.data.message : errorMessage;
                        throw new Error(msg);
                    }
                    var payload = data.data || {};
                    var url = payload.url || '';
                    if (!url){
                        throw new Error(errorMessage);
                    }
                    var message = payload.message || texts.previewReady || '';
                    if (modal){
                        modal.setAttribute('data-preview-url', url);
                    }
                    showNotice('info', message, { href: url, text: texts.openNewTab || '' });
                    try {
                        var evt = new CustomEvent('sii-boleta-open-preview', { detail: { url: url } });
                        window.dispatchEvent(evt);
                    } catch (err) {
                        if (document.createEvent){
                            var legacy = document.createEvent('CustomEvent');
                            legacy.initCustomEvent('sii-boleta-open-preview', false, false, { url: url });
                            window.dispatchEvent(legacy);
                        } else {
                            openModal(url);
                        }
                    }
                    reset();
                }).catch(function(err){
                    var msg = err && err.message ? err.message : errorMessage;
                    showNotice('error', msg);
                    reset();
                });
            };

            previewSubmit.addEventListener('click', triggerPreview);
            form.addEventListener('submit', function(e){
                var submitter = e.submitter || document.activeElement;
                if (submitter && submitter.name === 'preview'){
                    triggerPreview(e);
                }
            });
        }
    });
})();
