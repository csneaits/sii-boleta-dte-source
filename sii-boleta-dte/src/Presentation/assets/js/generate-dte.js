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
        var sendAction = (window.siiBoletaGenerate && window.siiBoletaGenerate.sendAction) ? window.siiBoletaGenerate.sendAction : 'sii_boleta_dte_send_document';
        var supportsAjax = typeof window.fetch === 'function' && typeof window.URLSearchParams !== 'undefined';
        var sendSubmit = form ? form.querySelector('input[type="submit"][name="submit"]') : null;
        var rutInput = document.getElementById('sii-rut');

        function getText(key, fallback){
            if (texts && typeof texts[key] === 'string' && texts[key]){
                return texts[key];
            }
            return fallback;
        }

        function escapeAttribute(value){
            if (value === null || value === undefined){ return ''; }
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function normalizeRutValue(value){
            if (typeof value !== 'string'){ return ''; }
            var rut = value.trim().toUpperCase();
            if (rut === ''){ return ''; }
            if (/[^0-9K.\-]/.test(rut)){ return ''; }
            rut = rut.replace(/[.\-]/g, '');
            if (rut.length < 2){ return ''; }
            var body = rut.slice(0, -1);
            var dv = rut.slice(-1);
            if (!/^[0-9]+$/.test(body)){ return ''; }
            body = body.replace(/^0+/, '');
            if (body === ''){ body = '0'; }
            return body + '-' + dv;
        }

        function evaluateRut(value){
            var normalized = normalizeRutValue(value);
            if (!normalized){
                return { valid: false, normalized: '' };
            }
            var parts = normalized.split('-');
            var body = parts[0];
            var dv = parts[1];
            var sum = 0;
            var factor = 2;
            for (var i = body.length - 1; i >= 0; i--){
                sum += parseInt(body.charAt(i), 10) * factor;
                factor = (factor === 7) ? 2 : factor + 1;
            }
            var mod = 11 - (sum % 11);
            var expected;
            if (mod === 11){ expected = '0'; }
            else if (mod === 10){ expected = 'K'; }
            else { expected = String(mod); }
            return { valid: expected === dv, normalized: normalized };
        }

        function validateRutField(showMessage){
            if (showMessage === undefined){ showMessage = true; }
            if (!rutInput){ return true; }
            var value = rutInput.value || '';
            var trimmed = value.trim();
            var tipo = tipoSelect ? parseInt(tipoSelect.value || '39', 10) : 39;
            var requiresRut = !(tipo === 39 || tipo === 41);

            if (trimmed === ''){
                if (requiresRut){
                    var requiredMsg = (texts && texts.rutRequired) ? texts.rutRequired : 'El RUT del receptor es obligatorio para este tipo de documento.';
                    rutInput.classList.add('sii-rut-invalid');
                    rutInput.setCustomValidity(requiredMsg);
                    if (showMessage){ rutInput.reportValidity(); }
                    return false;
                }
                rutInput.value = '';
                rutInput.classList.remove('sii-rut-invalid');
                rutInput.setCustomValidity('');
                return true;
            }

            var result = evaluateRut(trimmed);
            if (!result.valid){
                var invalidMsg = (texts && texts.rutInvalid) ? texts.rutInvalid : 'El RUT ingresado no es válido.';
                rutInput.classList.add('sii-rut-invalid');
                rutInput.setCustomValidity(invalidMsg);
                if (showMessage){ rutInput.reportValidity(); }
                return false;
            }

            rutInput.value = result.normalized;
            rutInput.classList.remove('sii-rut-invalid');
            rutInput.setCustomValidity('');
            return true;
        }

        function enhanceNumberField(input){
            if (!input || input.__siiEnhanced){ return; }
            var incAttr = input.getAttribute('data-increment');
            var increment = incAttr ? parseFloat(incAttr) : 0;
            if (!increment || Number.isNaN(increment)){ return; }
            var decimalsAttr = input.getAttribute('data-decimals');
            var decimals = decimalsAttr ? parseInt(decimalsAttr, 10) : 0;
            if (Number.isNaN(decimals) || decimals < 0){ decimals = 0; }
            var factor = Math.pow(10, decimals);
            var min = null;
            var max = null;
            var minAttr = input.getAttribute('min');
            if (minAttr !== null && minAttr !== ''){
                var minVal = parseFloat(minAttr);
                if (!Number.isNaN(minVal)){
                    min = minVal;
                }
            }
            var maxAttr = input.getAttribute('max');
            if (maxAttr !== null && maxAttr !== ''){
                var maxVal = parseFloat(maxAttr);
                if (!Number.isNaN(maxVal)){
                    max = maxVal;
                }
            }

            function parseValue(raw){
                if (typeof raw !== 'string'){ raw = raw !== undefined && raw !== null ? String(raw) : ''; }
                raw = raw.replace(',', '.');
                var num = parseFloat(raw);
                return Number.isNaN(num) ? 0 : num;
            }

            function formatValue(value){
                var normalized = Math.round(value * factor) / factor;
                var result = decimals > 0 ? normalized.toFixed(decimals) : normalized.toString();
                if (decimals > 0){
                    result = result.replace(/\.0+$/, '').replace(/\.$/, '');
                }
                return result;
            }

            function dispatchUpdate(){
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function applyDelta(delta){
                var current = parseValue(input.value);
                var next = current + delta;
                if (min !== null && next < min){ next = min; }
                if (max !== null && next > max){ next = max; }
                input.value = formatValue(next);
                dispatchUpdate();
            }

            input.addEventListener('keydown', function(ev){
                if (ev.key === 'ArrowUp' || ev.key === 'ArrowDown'){
                    ev.preventDefault();
                    applyDelta(ev.key === 'ArrowUp' ? increment : -increment);
                }
            });

            input.addEventListener('wheel', function(ev){
                if (ev.ctrlKey || ev.metaKey || ev.shiftKey){ return; }
                ev.preventDefault();
                applyDelta(ev.deltaY < 0 ? increment : -increment);
            }, { passive: false });

            input.addEventListener('blur', function(){
                if (input.value === ''){ return; }
                var parsed = parseValue(input.value);
                if (min !== null && parsed < min){ parsed = min; }
                if (max !== null && parsed > max){ parsed = max; }
                input.value = formatValue(parsed);
                dispatchUpdate();
            });

            input.__siiEnhanced = true;
        }

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
        if (rutInput){
            rutInput.addEventListener('blur', function(){ validateRutField(false); });
            rutInput.addEventListener('input', function(){ if (rutInput.classList.contains('sii-rut-invalid')){ validateRutField(false); } });
        }
        function initRow(row){
            var desc = row.querySelector('input[data-field="desc"]');
            var qty = row.querySelector('input[data-field="qty"]');
            var price = row.querySelector('input[data-field="price"]');
            var discountPct = row.querySelector('input[data-field="discount_pct"]');
            var discountAmount = row.querySelector('input[data-field="discount_amount"]');
            if(!desc){return;}
            enhanceNumberField(qty);
            enhanceNumberField(price);
            enhanceNumberField(discountPct);
            enhanceNumberField(discountAmount);
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
                    var name = (p.name || '').trim();
                    var sku = (p.sku || '').toString().trim();
                    var value = name;

                    if (sku) {
                        var escaped = sku.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        var suffixRegex = new RegExp('\\s*\\(' + escaped + '\\)\\s*$', 'i');
                        if (suffixRegex.test(value)) {
                            value = value.replace(suffixRegex, ' (' + sku + ')').trim();
                        } else {
                            value = value.replace(suffixRegex, '').trim();
                            value = value.length ? value + ' (' + sku + ')' : '(' + sku + ')';
                        }
                    }

                    desc.value = value || sku;
                    cache[desc.value] = p;
                }
            });
        }

        function renumberRows(){
            if (!tableBody){ return; }
            var rows = tableBody.querySelectorAll('tr');
            Array.prototype.forEach.call(rows, function(row, index){
                row.querySelectorAll('[data-field]').forEach(function(input){
                    var field = input.getAttribute('data-field');
                    if (!field){ return; }
                    input.name = 'items[' + index + '][' + field + ']';
                });
            });
        }

        function addRow(){
           if (!tableBody) return;
           var row = document.createElement('tr');
            var descLabel = escapeAttribute(getText('itemsDescLabel', 'Descripción'));
            var qtyLabel = escapeAttribute(getText('itemsQtyLabel', 'Cantidad'));
            var priceLabel = escapeAttribute(getText('itemsPriceLabel', 'Precio unitario'));
            var actionsLabel = escapeAttribute(getText('itemsActionsLabel', 'Acciones'));
            var removeLabel = escapeAttribute(getText('itemsRemoveLabel', 'Eliminar ítem'));
            var advancedSummary = escapeAttribute(getText('itemsAdvancedSummary', 'Opciones avanzadas del ítem'));
            var codeTypeLabel = escapeAttribute(getText('itemsCodeTypeLabel', 'Tipo de código'));
            var codeValueLabel = escapeAttribute(getText('itemsCodeValueLabel', 'Código'));
            var extraDescLabel = escapeAttribute(getText('itemsExtraDescLabel', 'Descripción adicional'));
            var unitLabel = escapeAttribute(getText('itemsUnitLabel', 'Unidad del ítem'));
            var unitRefLabel = escapeAttribute(getText('itemsUnitRefLabel', 'Unidad de referencia'));
            var discountPctLabel = escapeAttribute(getText('itemsDiscountPctLabel', 'Descuento %'));
            var discountAmtLabel = escapeAttribute(getText('itemsDiscountAmtLabel', 'Descuento $'));
            var taxCodeLabel = escapeAttribute(getText('itemsTaxCodeLabel', 'Impuesto adicional'));
            var retainerLabel = escapeAttribute(getText('itemsRetainerLabel', 'Indicador retenedor'));
            row.innerHTML = '<td data-label="' + descLabel + '">' +
                                '<input type="text" data-field="desc" name="items[][desc]" class="regular-text" />' +
                                '<details class="sii-item-advanced dte-section" data-types="33,34,43,46,52,56,61,110,111,112" style="display:none">' +
                                        '<summary>' + advancedSummary + '</summary>' +
                                        '<div class="sii-item-advanced-grid">' +
                                                '<label><span>' + codeTypeLabel + '</span><input type="text" data-field="code_type" name="items[][code_type]" /></label>' +
                                                '<label><span>' + codeValueLabel + '</span><input type="text" data-field="code_value" name="items[][code_value]" /></label>' +
                                                '<label class="sii-item-advanced-wide"><span>' + extraDescLabel + '</span><textarea data-field="extra_desc" name="items[][extra_desc]" rows="3"></textarea></label>' +
                                                '<label><span>' + unitLabel + '</span><input type="text" data-field="unit_item" name="items[][unit_item]" /></label>' +
                                                '<label><span>' + unitRefLabel + '</span><input type="text" data-field="unit_ref" name="items[][unit_ref]" /></label>' +
                                                '<label><span>' + discountPctLabel + '</span><input type="number" data-field="discount_pct" name="items[][discount_pct]" value="0" step="0.01" data-increment="0.1" data-decimals="2" inputmode="decimal" min="0" /></label>' +
                                                '<label><span>' + discountAmtLabel + '</span><input type="number" data-field="discount_amount" name="items[][discount_amount]" value="0" step="0.01" data-increment="1" data-decimals="2" inputmode="decimal" min="0" /></label>' +
                                                '<label><span>' + taxCodeLabel + '</span><input type="text" data-field="tax_code" name="items[][tax_code]" /></label>' +
                                                '<label><span>' + retainerLabel + '</span><input type="text" data-field="retained_indicator" name="items[][retained_indicator]" /></label>' +
                                        '</div>' +
                                '</details>' +
                        '</td>' +
                        '<td data-label="' + qtyLabel + '"><input type="number" data-field="qty" name="items[][qty]" value="1" step="0.01" data-increment="1" data-decimals="2" inputmode="decimal" min="0" /></td>' +
                        '<td data-label="' + priceLabel + '"><input type="number" data-field="price" name="items[][price]" value="0" step="0.01" data-increment="1" data-decimals="2" inputmode="decimal" min="0" /></td>' +
                        '<td data-label="' + actionsLabel + '"><button type="button" class="button remove-item" aria-label="' + removeLabel + '">×</button></td>';
           tableBody.appendChild(row);
           initRow(row);
            renumberRows();
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
            validateRutField(false);
        }

        if (tipoSelect){
            tipoSelect.addEventListener('change', toggleSections);
            // Run on load
            toggleSections();
        }

       if (tableBody){
            Array.prototype.forEach.call(tableBody.querySelectorAll('tr'), initRow);
            renumberRows();
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
                    renumberRows();
                }
            });
        }

        var refTable = document.getElementById('sii-ref-table');
        var refBody = refTable ? (refTable.tBodies && refTable.tBodies[0] ? refTable.tBodies[0] : refTable.appendChild(document.createElement('tbody'))) : null;
        var addRefBtn = document.getElementById('sii-add-reference');

        function renumberReferences(){
            if (!refBody){ return; }
            var rows = refBody.querySelectorAll('tr');
            Array.prototype.forEach.call(rows, function(row, index){
                row.setAttribute('data-ref-row', index);
                row.querySelectorAll('[data-ref-field]').forEach(function(input){
                    var field = input.getAttribute('data-ref-field');
                    if (!field){ return; }
                    input.name = 'references[' + index + '][' + field + ']';
                });
            });
        }

        function clearReferenceRow(row){
            row.querySelectorAll('[data-ref-field]').forEach(function(input){
                if (input.type === 'checkbox'){
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        function addReferenceRow(){
            if (!refBody){ return; }
            var row = document.createElement('tr');
            var typeLabel = escapeAttribute(getText('referenceTypeLabel', 'Tipo'));
            var folioLabel = escapeAttribute(getText('referenceFolioLabel', 'Folio'));
            var dateLabel = escapeAttribute(getText('referenceDateLabel', 'Fecha'));
            var reasonLabel = escapeAttribute(getText('referenceReasonLabel', 'Razón / glosa'));
            var globalLabel = escapeAttribute(getText('referenceGlobalLabel', 'Global'));
            var actionsLabelRef = escapeAttribute(getText('referenceActionsLabel', 'Acciones'));
            var removeLabel = escapeAttribute(getText('referenceRemoveLabel', 'Eliminar referencia'));
            row.innerHTML = '<td data-label="' + typeLabel + '"><select data-ref-field="tipo" name="references[][tipo]">' +
                    '<option value="">—</option>' +
                    '<option value="33">Factura</option>' +
                    '<option value="34">Factura Exenta</option>' +
                    '<option value="39">Boleta</option>' +
                    '<option value="41">Boleta Exenta</option>' +
                    '<option value="52">Guía de despacho</option>' +
                '</select></td>' +
                '<td data-label="' + folioLabel + '"><input type="number" data-ref-field="folio" name="references[][folio]" step="1" /></td>' +
                '<td data-label="' + dateLabel + '"><input type="date" data-ref-field="fecha" name="references[][fecha]" /></td>' +
                '<td data-label="' + reasonLabel + '"><input type="text" data-ref-field="razon" name="references[][razon]" /></td>' +
                '<td data-label="' + globalLabel + '" class="sii-ref-checkbox"><label><input type="checkbox" data-ref-field="global" name="references[][global]" value="1" /><span class="screen-reader-text">' + globalLabel + '</span></label></td>' +
                '<td data-label="' + actionsLabelRef + '"><button type="button" class="button remove-reference" aria-label="' + removeLabel + '">×</button></td>';
            refBody.appendChild(row);
            renumberReferences();
        }

        if (addRefBtn && refBody){
            addRefBtn.addEventListener('click', function(event){
                event.preventDefault();
                addReferenceRow();
            });
            refBody.addEventListener('click', function(event){
                if (event.target.classList.contains('remove-reference')){
                    event.preventDefault();
                    var row = event.target.closest('tr');
                    if (!row){ return; }
                    if (refBody.querySelectorAll('tr').length === 1){
                        clearReferenceRow(row);
                        return;
                    }
                    row.remove();
                    renumberReferences();
                }
            });
            renumberReferences();
        }

        var triggerPreview = null;
        var triggerSend = null;

        if (form && previewSubmit && supportsAjax){
            triggerPreview = function(event, submitter){
                if (event && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)){
                    return;
                }
                if (event){ event.preventDefault(); }
                if (form.getAttribute('data-preview-loading') === '1'){
                    return;
                }
                form.setAttribute('data-preview-loading', '1');
                var button = (submitter && submitter.tagName) ? submitter : previewSubmit;
                var originalValue = button ? button.value : '';
                if (button){
                    var loadingText = texts.loading || originalValue;
                    button.value = loadingText;
                    button.disabled = true;
                    button.classList.add('updating-message');
                }

                var reset = function(){
                    form.removeAttribute('data-preview-loading');
                    if (button){
                        button.disabled = false;
                        button.classList.remove('updating-message');
                        button.value = originalValue;
                    }
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
        }

        if (form && supportsAjax){
            triggerSend = function(event, submitter){
                if (event && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)){
                    return;
                }
                if (event){ event.preventDefault(); }
                if (form.getAttribute('data-send-loading') === '1'){
                    return;
                }
                form.setAttribute('data-send-loading', '1');
                var button = (submitter && submitter.tagName) ? submitter : sendSubmit;
                var originalValue = button ? button.value : '';
                if (button){
                    var sendingText = texts.sending || originalValue;
                    button.value = sendingText;
                    button.disabled = true;
                    button.classList.add('updating-message');
                }

                var resetSend = function(){
                    form.removeAttribute('data-send-loading');
                    if (button){
                        button.disabled = false;
                        button.classList.remove('updating-message');
                        button.value = originalValue;
                    }
                };

                var formData = new FormData(form);
                formData.delete('preview');
                formData.set('action', sendAction);
                var paramsSend = new URLSearchParams();
                formData.forEach(function(val, key){
                    paramsSend.append(key, val);
                });
                var errorSend = texts.sendError || 'Could not send the document. Please try again.';
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: paramsSend.toString()
                }).then(function(resp){
                    if (!resp.ok){
                        return resp.text().then(function(body){
                            var detailedMessage = '';
                            if (body){
                                try {
                                    var jsonBody = JSON.parse(body);
                                    var responseMessage = jsonBody && jsonBody.data && jsonBody.data.message ? jsonBody.data.message : '';
                                    detailedMessage = responseMessage || (jsonBody && jsonBody.message ? jsonBody.message : '');
                                } catch (parseErr) {
                                    detailedMessage = body.trim();
                                }
                            }
                            var statusInfo = resp.status ? ' (HTTP ' + resp.status + (resp.statusText ? ' ' + resp.statusText : '') + ')' : '';
                            var combined = detailedMessage ? detailedMessage + statusInfo : errorSend + statusInfo;
                            throw new Error(combined);
                        });
                    }
                    return resp.json();
                }).then(function(data){
                    if (!data || !data.success){
                        var msg = data && data.data && data.data.message ? data.data.message : errorSend;
                        throw new Error(msg);
                    }
                    var payload = data.data || {};
                    var message = payload.message || texts.sendSuccess || '';
                    var trackId = payload.track_id ? payload.track_id.toString() : '';
                    if (message && message.indexOf('%s') !== -1 && trackId){
                        message = message.replace('%s', trackId);
                    } else if (!message && trackId){
                        message = 'Tracking ID: ' + trackId;
                    }
                    var pdfUrl = payload.pdf_url || '';
                    var noticeType = payload.notice_type || (payload.queued ? 'warning' : 'success');
                    var linkText = texts.viewPdf || 'Download PDF';
                    var link = null;
                    if (pdfUrl){
                        link = { href: pdfUrl, text: linkText };
                        if (modal){
                            modal.setAttribute('data-preview-url', pdfUrl);
                        }
                    }
                    showNotice(noticeType, message, link);
                    if (pdfUrl){
                        try {
                            var evtSend = new CustomEvent('sii-boleta-open-preview', { detail: { url: pdfUrl } });
                            window.dispatchEvent(evtSend);
                        } catch (errEvt) {
                            if (document.createEvent){
                                var legacySend = document.createEvent('CustomEvent');
                                legacySend.initCustomEvent('sii-boleta-open-preview', false, false, { url: pdfUrl });
                                window.dispatchEvent(legacySend);
                            } else {
                                openModal(pdfUrl);
                            }
                        }
                    }
                    resetSend();
                }).catch(function(err){
                    var msg = err && err.message ? err.message : errorSend;
                    showNotice('error', msg);
                    resetSend();
                });
            };
        }

        if (form){
            form.addEventListener('submit', function(e){
                var submitter = e.submitter || document.activeElement;
                var isPreview = submitter && submitter.name === 'preview';
                if (!validateRutField(true)){
                    e.preventDefault();
                    return;
                }
                if (!supportsAjax){
                    return;
                }
                if (isPreview && triggerPreview){
                    triggerPreview(e, submitter);
                    return;
                }
                if (triggerSend){
                    triggerSend(e, submitter);
                }
            });
        }
    });
})();
