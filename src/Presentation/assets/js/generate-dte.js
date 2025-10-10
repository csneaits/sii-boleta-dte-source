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
    var xmlPreviewAction = (window.siiBoletaGenerate && window.siiBoletaGenerate.xmlPreviewAction) ? window.siiBoletaGenerate.xmlPreviewAction : 'sii_boleta_dte_preview_xml';
    var xmlValidateAction = (window.siiBoletaGenerate && window.siiBoletaGenerate.xmlValidateAction) ? window.siiBoletaGenerate.xmlValidateAction : 'sii_boleta_dte_validate_xml';
    var xmlEnvioValidateAction = (window.siiBoletaGenerate && window.siiBoletaGenerate.xmlEnvioValidateAction) ? window.siiBoletaGenerate.xmlEnvioValidateAction : 'sii_boleta_dte_validate_envio';
        var supportsAjax = typeof window.fetch === 'function' && typeof window.URLSearchParams !== 'undefined';
        var sendSubmit = form ? form.querySelector('[name="submit"]') : null;
        var rutInput = document.getElementById('sii-rut');
        var refTable = document.getElementById('sii-ref-table');
        var refBody = refTable ? (refTable.tBodies && refTable.tBodies.length ? refTable.tBodies[0] : refTable.appendChild(document.createElement('tbody'))) : null;
        var refTemplate = document.getElementById('sii-ref-row-template');
        var addRefBtn = document.getElementById('sii-add-reference');
        var ncMotivoSelect = document.getElementById('sii-nc-motivo');
        var ncReferenceHint = document.getElementById('sii-nc-reference-hint');
        var ncZeroNotice = document.getElementById('sii-nc-zero-notice');
        var ncGlobalNote = document.getElementById('sii-nc-global-note');
        var tipsList = document.querySelector('.sii-generate-dte-tips');
        var tipItems = tipsList ? tipsList.querySelectorAll('[data-tip-type]') : null;
        var stepper = document.getElementById('sii-generate-dte-steps');
        var stepSections = document.querySelectorAll('.sii-dte-step');
        var stepOrder = [];
        var stepSectionsMap = {};
        var stepState = {};
        var creditNoteState = { valid: true, issue: null, field: null, message: '' };
        if (stepSections && stepSections.length){
            Array.prototype.forEach.call(stepSections, function(section){
                var stepId = section.getAttribute('data-step');
                if (!stepId){ return; }
                stepOrder.push(stepId);
                stepSectionsMap[stepId] = section;
            });
        }

        function getText(key, fallback){
            if (texts && typeof texts[key] === 'string' && texts[key]){
                return texts[key];
            }
            return fallback;
        }

        function getButtonLabel(button){
            if (!button){ return ''; }
            if (button.dataset && typeof button.dataset.label === 'string' && button.dataset.label){
                return button.dataset.label;
            }
            var span = button.querySelector('.sii-action-text');
            if (span && typeof span.textContent === 'string'){
                return span.textContent.trim();
            }
            return (button.textContent || '').trim();
        }

        function rememberOriginalButtonLabel(button){
            if (!button || !button.dataset){ return; }
            if (!button.dataset.originalLabel){
                var current = getButtonLabel(button);
                if (current){
                    button.dataset.originalLabel = current;
                }
            }
        }

        function setButtonLabel(button, label){
            if (!button){ return; }
            if (button.dataset){
                button.dataset.label = label;
            }
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
            var span = button.querySelector('.sii-action-text');
            if (span && typeof span.textContent === 'string'){
                span.textContent = label;
            } else if (typeof button.textContent === 'string'){
                button.textContent = label;
            }
        }

        // --- XML Preview Modal Logic ---
        var xmlBtn = document.getElementById('sii-preview-xml-btn');
        var xmlModal = document.getElementById('sii-xml-preview-modal');
        var xmlClose = xmlModal ? xmlModal.querySelector('.sii-xml-modal-close') : null;
        var xmlBackdrop = xmlModal ? xmlModal.querySelector('.sii-xml-modal-backdrop') : null;
    var xmlCode = xmlModal ? xmlModal.querySelector('#sii-xml-code code') : null;
    var xmlCodeContainer = xmlModal ? xmlModal.querySelector('#sii-xml-code') : null;
        var xmlValidationBox = xmlModal ? xmlModal.querySelector('#sii-xml-validation') : null;
    var xmlValidationSpinner = xmlModal ? xmlModal.querySelector('#sii-xml-validation-spinner') : null;
        var xmlMeta = xmlModal ? xmlModal.querySelector('#sii-xml-meta') : null;
        var xmlCopyBtn = xmlModal ? xmlModal.querySelector('#sii-xml-copy') : null;
        var xmlDownloadBtn = xmlModal ? xmlModal.querySelector('#sii-xml-download') : null;
        var xmlValidateBtn = xmlModal ? xmlModal.querySelector('#sii-xml-validate') : null;
    var xmlEnvioValidateBtn = xmlModal ? xmlModal.querySelector('#sii-xml-validate-envio') : null;
    var currentXmlTipo = 0;
    var currentXml = '';
    var currentXmlLines = [];
    var xmlWrap = false;

        function openXmlModal(){ if (xmlModal){ xmlModal.style.display='block'; document.body.classList.add('sii-xml-modal-open'); } }
        function closeXmlModal(){ if (xmlModal){ xmlModal.style.display='none'; document.body.classList.remove('sii-xml-modal-open'); } }
        if (xmlClose){ xmlClose.addEventListener('click', closeXmlModal); }
        if (xmlBackdrop){ xmlBackdrop.addEventListener('click', closeXmlModal); }
        document.addEventListener('keydown', function(e){ if(e.key==='Escape' && xmlModal && xmlModal.style.display==='block'){ closeXmlModal(); } });

        function serializeForm(form){
            var fd = new FormData(form);
            // ensure tipo name is 'tipo'
            return fd;
        }
        function buildCodeHtml(raw){
            if (!raw){ return ''; }
            currentXmlLines = raw.replace(/\r\n?/g,'\n').split('\n');
            var out = '<ol class="sii-xml-lines">';
            for (var i=0;i<currentXmlLines.length;i++){
                var safe = currentXmlLines[i]
                    .replace(/&/g,'&amp;')
                    .replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;');
                out += '<li data-line="'+(i+1)+'"><code>'+safe + '</code></li>';
            }
            out += '</ol>';
            return out;
        }

        function highlightLine(line){
            if (!xmlCodeContainer){ return; }
            var prev = xmlCodeContainer.querySelector('.sii-xml-line-highlight');
            if (prev){ prev.classList.remove('sii-xml-line-highlight'); }
            var li = xmlCodeContainer.querySelector('li[data-line="'+line+'"][data-line]');
            if (li){
                li.classList.add('sii-xml-line-highlight');
                try { li.scrollIntoView({block:'center'}); } catch(e){}
            }
        }

        function toggleWrap(){
            xmlWrap = !xmlWrap;
            if (xmlCodeContainer){
                xmlCodeContainer.classList.toggle('sii-xml-wrap', xmlWrap);
            }
            var btn = xmlModal.querySelector('#sii-xml-wrap');
            if (btn){ btn.textContent = xmlWrap ? 'No wrap' : 'Wrap'; }
        }

        function fetchXmlPreview(){
            if (!form || !supportsAjax){ return; }
            if (xmlValidationBox){ xmlValidationBox.textContent=''; }
            if (xmlMeta){ xmlMeta.textContent=getText('xmlLoading','Generando XML…'); }
            var fd = serializeForm(form);
            fd.append('action', xmlPreviewAction);
            var nonceField = form.querySelector('[name="sii_boleta_generate_dte_nonce"]');
            if (nonceField){ fd.append('sii_boleta_generate_dte_nonce', nonceField.value); }
            fd.append('preview','1');
            fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(json){
                    if (!json || !json.success){
                        if (xmlMeta){ xmlMeta.textContent=getText('xmlError','No se pudo generar el XML.'); }
                        return;
                    }
                    currentXml = json.data && json.data.xml ? String(json.data.xml) : '';
                    currentXmlTipo = json.data && json.data.tipo ? parseInt(json.data.tipo,10) : 0;
                    if (xmlCode && xmlCodeContainer){ xmlCode.innerHTML = ''; xmlCodeContainer.innerHTML = buildCodeHtml(currentXml); }
                    if (xmlMeta){ xmlMeta.textContent = 'bytes: '+ (json.data.size||0) + ' · líneas: ' + (json.data.lines||0); }
                })
                .catch(function(){ if (xmlMeta){ xmlMeta.textContent=getText('xmlError','No se pudo generar el XML.'); } });
        }
        if (xmlBtn){
            xmlBtn.addEventListener('click', function(){ openXmlModal(); fetchXmlPreview(); });
        }
        if (xmlCopyBtn){
            xmlCopyBtn.addEventListener('click', function(){
                if (!currentXml){ return; }
                try { navigator.clipboard.writeText(currentXml); if(xmlMeta){ xmlMeta.textContent=getText('xmlCopied','XML copiado.'); } }
                catch(e){ /* ignore */ }
            });
        }
        if (xmlDownloadBtn){
            xmlDownloadBtn.addEventListener('click', function(){
                if (!currentXml){ return; }
                var blob = new Blob([currentXml], { type:'application/xml' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'dte-preview.xml';
                document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 100);
            });
        }
        if (xmlValidateBtn){
            xmlValidateBtn.addEventListener('click', function(){
                if (!currentXml){ return; }
                if (xmlValidationBox){ xmlValidationBox.textContent=getText('xmlValidateLoading','Validando…'); xmlValidationBox.className=''; }
                if (xmlValidationSpinner){ xmlValidationSpinner.style.display='block'; }
                var fd = new FormData();
                fd.append('action', xmlValidateAction);
                fd.append('tipo', String(currentXmlTipo||0));
                fd.append('xml', currentXml);
                var nonceField = form ? form.querySelector('[name="sii_boleta_generate_dte_nonce"]') : null;
                if (nonceField){ fd.append('sii_boleta_generate_dte_nonce', nonceField.value); }
                fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(json){
                        if (!json){ return; }
                        if (json.success){
                            if (xmlValidationBox){ xmlValidationBox.innerHTML='<span class="sii-xml-ok">'+getText('xmlValidateOk','XML válido.')+'</span>'; xmlValidationBox.style.color='#0a0'; }
                        } else {
                            var msg = (json.data && json.data.message) ? String(json.data.message) : getText('xmlValidateFail','Validación fallida.');
                            var errors = (json.data && json.data.errors) ? json.data.errors : [];
                            if (xmlValidationBox){
                                xmlValidationBox.style.color='';
                                if (errors.length){
                                    var list = document.createElement('div');
                                    list.className='sii-xml-errors';
                                    var heading = document.createElement('div');
                                    heading.className='sii-xml-errors-heading';
                                    var countBadge = ' ('+errors.length+')';
                                    heading.textContent = msg + countBadge;
                                    list.appendChild(heading);
                                    var ul = document.createElement('ol');
                                    ul.className='sii-xml-error-list';
                                    errors.slice(0,200).forEach(function(e){
                                        var lineNum = (e && typeof e.line !== 'undefined') ? e.line : '?';
                                        var message = (e && e.message) ? e.message : '';
                                        var li = document.createElement('li');
                                        li.innerHTML = '<button type="button" class="sii-xml-jump" data-line="'+lineNum+'">L'+lineNum+'</button> '+message.replace(/</g,'&lt;');
                                        ul.appendChild(li);
                                    });
                                    list.appendChild(ul);
                                    xmlValidationBox.innerHTML='';
                                    xmlValidationBox.appendChild(list);
                                } else {
                                    // Sin errores listados: puede ser que schemaValidate devolvió fallo pero libxml no entregó line numbers.
                                    xmlValidationBox.innerHTML = '<span class="sii-xml-errors-none">'+msg+' (sin detalles de línea)</span>';
                                }
                            }
                        }
                    })
                    .catch(function(){ if (xmlValidationBox){ xmlValidationBox.textContent=getText('xmlError','No se pudo validar.'); xmlValidationBox.style.color='#a00'; } })
                    .finally(function(){ if (xmlValidationSpinner){ xmlValidationSpinner.style.display='none'; } });
            });
        }

        if (xmlEnvioValidateBtn){
            xmlEnvioValidateBtn.addEventListener('click', function(){
                if (!currentXml){ return; }
                if (xmlValidationBox){ xmlValidationBox.textContent=getText('xmlValidateLoading','Validando…'); xmlValidationBox.className=''; }
                if (xmlValidationSpinner){ xmlValidationSpinner.style.display='block'; }
                var fd = new FormData();
                fd.append('action', xmlEnvioValidateAction);
                fd.append('tipo', String(currentXmlTipo||0));
                fd.append('xml', currentXml);
                var nonceField = form ? form.querySelector('[name="sii_boleta_generate_dte_nonce"]') : null;
                if (nonceField){ fd.append('sii_boleta_generate_dte_nonce', nonceField.value); }
                fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(json){
                        if (!json){ return; }
                        if (json.success){
                            if (xmlValidationBox){ xmlValidationBox.innerHTML='<span class="sii-xml-ok" style="color:#064;">'+(getText('xmlEnvioValidateOk','Sobre EnvioDTE válido.'))+'</span>'; xmlValidationBox.style.color='#064'; }
                        } else {
                            var msg = (json.data && json.data.message) ? String(json.data.message) : getText('xmlEnvioValidateFail','Errores de validación del EnvioDTE.');
                            var errors = (json.data && json.data.errors) ? json.data.errors : [];
                            if (xmlValidationBox){
                                xmlValidationBox.style.color='';
                                if (errors.length){
                                    var list = document.createElement('div');
                                    list.className='sii-xml-errors';
                                    var heading = document.createElement('div');
                                    heading.className='sii-xml-errors-heading';
                                    heading.textContent = msg + ' ('+errors.length+')';
                                    list.appendChild(heading);
                                    var ul = document.createElement('ol');
                                    ul.className='sii-xml-error-list';
                                    errors.slice(0,200).forEach(function(e){
                                        var lineNum = (e && typeof e.line !== 'undefined') ? e.line : '?';
                                        var message = (e && e.message) ? e.message : '';
                                        var li = document.createElement('li');
                                        li.innerHTML = '<button type="button" class="sii-xml-jump" data-line="'+lineNum+'">L'+lineNum+'</button> '+message.replace(/</g,'&lt;');
                                        ul.appendChild(li);
                                    });
                                    list.appendChild(ul);
                                    xmlValidationBox.innerHTML='';
                                    xmlValidationBox.appendChild(list);
                                } else {
                                    xmlValidationBox.innerHTML = '<span class="sii-xml-errors-none">'+msg+' (sin detalles de línea)</span>';
                                }
                            }
                        }
                    })
                    .catch(function(){ if (xmlValidationBox){ xmlValidationBox.textContent=getText('xmlError','No se pudo validar.'); xmlValidationBox.style.color='#a00'; } })
                    .finally(function(){ if (xmlValidationSpinner){ xmlValidationSpinner.style.display='none'; } });
            });
        }

        // Delegate click on error lines
        if (xmlModal){
            xmlModal.addEventListener('click', function(e){
                var t = e.target;
                if (t && t.classList && t.classList.contains('sii-xml-jump')){
                    var line = parseInt(t.getAttribute('data-line'),10);
                    if (line>0){ highlightLine(line); }
                }
                if (t && t.id === 'sii-xml-wrap'){ toggleWrap(); }
            });
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

        function focusField(field){
            if (!field || typeof field.focus !== 'function'){ return; }
            if (field.scrollIntoView && field.offsetParent === null){
                try { field.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                catch (err) { /* ignore */ }
            }
            try { field.focus({ preventScroll: true }); }
            catch (err) {
                try { field.focus(); }
                catch (err2) { /* ignore */ }
            }
        }

        function isFieldRelevant(field){
            if (!field || field.disabled){ return false; }
            if (field.type === 'hidden'){ return false; }
            var el = field;
            while (el){
                if (el.hasAttribute && el.hasAttribute('hidden')){ return false; }
                if (window.getComputedStyle){
                    var style = window.getComputedStyle(el);
                    if (style && (style.display === 'none' || style.visibility === 'hidden')){
                        return false;
                    }
                }
                el = el.parentElement;
            }
            if (typeof field.offsetParent === 'undefined'){ return true; }
            if (field.offsetParent === null && field.getClientRects && field.getClientRects().length === 0){
                return false;
            }
            return true;
        }

        function findStepForField(field){
            if (!field || !field.closest){ return null; }
            var section = field.closest('.sii-dte-step');
            if (!section){ return null; }
            var step = section.getAttribute('data-step');
            return step || null;
        }

        function getCurrentStep(){
            if (!stepOrder.length){ return null; }
            var current = stepper ? stepper.getAttribute('data-current-step') : null;
            if (!current || stepOrder.indexOf(current) === -1){
                current = stepOrder[0];
                if (stepper){ stepper.setAttribute('data-current-step', current); }
            }
            return current;
        }

        function evaluateStep(step){
            var status = { complete: true, firstInvalid: null };
            var section = stepSectionsMap[step];
            if (!section){ return status; }
            var fields = section.querySelectorAll('input,select,textarea');
            Array.prototype.some.call(fields, function(field){
                if (!isFieldRelevant(field)){ return false; }
                if (typeof field.checkValidity === 'function' && !field.checkValidity()){
                    status.complete = false;
                    status.firstInvalid = field;
                    return true;
                }
                return false;
            });
            if (status.complete && step === 'resumen' && creditNoteState && !creditNoteState.valid){
                status.complete = false;
                status.firstInvalid = creditNoteState.field || status.firstInvalid;
            }
            return status;
        }

        function getStepIncompleteMessage(){
            if (form && form.dataset && form.dataset.stepIncomplete){
                return form.dataset.stepIncomplete;
            }
            return getText('stepIncomplete', 'Completa los campos obligatorios antes de continuar.');
        }

        function getRequirementLabel(isRequired){
            if (form && form.dataset){
                if (isRequired && form.dataset.requiredLabel){
                    return form.dataset.requiredLabel;
                }
                if (!isRequired && form.dataset.optionalLabel){
                    return form.dataset.optionalLabel;
                }
            }
            return getText(isRequired ? 'requiredBadge' : 'optionalBadge', isRequired ? 'Obligatorio' : 'Opcional');
        }

        function annotateFieldRequirements(){
            if (!form){ return; }
            var controls = form.querySelectorAll('input, select, textarea');
            Array.prototype.forEach.call(controls, function(field){
                if (!field.id || field.type === 'hidden'){ return; }
                if (field.closest && field.closest('.sii-field-no-badge')){ return; }
                var selectorId = field.id.replace(/"/g, '\\"');
                var label = form.querySelector('label[for="' + selectorId + '"]');
                if (!label){ return; }
                label.classList.add('sii-field-label');
                var badge = label.querySelector('.sii-field-badge');
                if (!badge){
                    badge = document.createElement('span');
                    badge.className = 'sii-field-badge';
                    label.appendChild(badge);
                }
                var requiredState = !!field.required;
                badge.textContent = getRequirementLabel(requiredState);
                badge.classList.toggle('sii-field-badge--required', requiredState);
                badge.classList.toggle('sii-field-badge--optional', !requiredState);
            });
        }

        function setCurrentStep(step, options){
            if (!stepOrder.length){ return; }
            var target = (step && stepOrder.indexOf(step) !== -1) ? step : getCurrentStep() || stepOrder[0];
            if (stepper){ stepper.setAttribute('data-current-step', target); }
            refreshSteps();
            if (options && options.focus){
                var focusTarget = options.focusField || (stepSectionsMap[target] ? stepSectionsMap[target].querySelector('input,select,textarea,button,a[href]') : null);
                focusField(focusTarget);
            }
        }

        function evaluateCreditNoteState(){
            var state = { valid: true, issue: null, field: null, message: '' };
            if (!isCreditNote() || !refBody){ return state; }
            var rows = refBody.querySelectorAll('tr');
            var firstField = null;
            var hasReference = false;
            var firstMissing = null;
            var firstMissingReason = null;
            var motive = getNcMotivo();
            Array.prototype.forEach.call(rows, function(row){
                var tipoField = row.querySelector('[data-ref-field="tipo"]');
                var folioField = row.querySelector('[data-ref-field="folio"]');
                var fechaField = row.querySelector('[data-ref-field="fecha"]');
                var reasonField = row.querySelector('[data-ref-field="razon"]');
                if (!firstField){
                    firstField = tipoField || folioField || fechaField || reasonField;
                }
                var tipoVal = tipoField ? tipoField.value.trim() : '';
                var folioVal = folioField ? folioField.value.trim() : '';
                var fechaVal = fechaField ? fechaField.value.trim() : '';
                var reasonVal = reasonField ? reasonField.value.trim() : '';
                if (!tipoVal && !folioVal && !fechaVal && !reasonVal){
                    return;
                }
                hasReference = true;
                if (!firstMissing){
                    if (!tipoVal){ firstMissing = tipoField; }
                    else if (!folioVal){ firstMissing = folioField; }
                    else if (!fechaVal){ firstMissing = fechaField; }
                }
                if (!firstMissingReason && motive === 'texto' && tipoVal && folioVal && fechaVal && !reasonVal){
                    firstMissingReason = reasonField;
                }
            });
            if (!hasReference){
                state.valid = false;
                state.issue = 'required';
                state.field = firstField;
                state.message = form && form.dataset ? (form.dataset.ncRequired || '') : '';
                return state;
            }
            if (firstMissing){
                state.valid = false;
                state.issue = 'incomplete';
                state.field = firstMissing;
                state.message = form && form.dataset ? (form.dataset.ncIncomplete || '') : '';
                return state;
            }
            if (firstMissingReason){
                state.valid = false;
                state.issue = 'reason';
                state.field = firstMissingReason;
                state.message = form && form.dataset ? (form.dataset.ncReason || '') : '';
                return state;
            }
            return state;
        }

        function refreshSteps(){
            if (!stepOrder.length){ return; }
            var current = getCurrentStep();
            stepState = {};
            creditNoteState = evaluateCreditNoteState();
            stepOrder.forEach(function(step){
                var status = evaluateStep(step);
                stepState[step] = status;
                var section = stepSectionsMap[step];
                var isActive = step === current;
                if (section){
                    section.classList.toggle('is-active', isActive);
                    if (isActive){
                        section.removeAttribute('hidden');
                        section.setAttribute('aria-hidden', 'false');
                    } else {
                        section.setAttribute('hidden', 'hidden');
                        section.setAttribute('aria-hidden', 'true');
                    }
                }
                if (stepper){
                    var item = stepper.querySelector('[data-step="' + step + '"]');
                    if (item){
                        item.classList.toggle('is-active', isActive);
                        item.classList.toggle('is-complete', status.complete);
                        if (isActive){
                            item.setAttribute('aria-current', 'step');
                        } else {
                            item.removeAttribute('aria-current');
                        }
                    }
                }
            });
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

        function isCreditNote(){
            if (!tipoSelect){ return false; }
            var current = parseInt(tipoSelect.value || '0', 10);
            return current === 61;
        }

        function getNcMotivo(){
            if (!ncMotivoSelect){ return ''; }
            return ncMotivoSelect.value || '';
        }

        function getNcRequiredCode(){
            var motive = getNcMotivo();
            if (motive === 'anula'){ return '1'; }
            if (motive === 'texto'){ return '2'; }
            if (motive === 'montos'){ return '3'; }
            return '';
        }

        function applyTextCorrectionLock(enable){
            if (!tableBody){ return; }
            Array.prototype.forEach.call(tableBody.querySelectorAll('tr'), function(row){
                var qtyInput = row.querySelector('input[data-field="qty"]');
                var priceInput = row.querySelector('input[data-field="price"]');
                if (qtyInput){
                    if (enable){
                        qtyInput.value = '1';
                        qtyInput.setAttribute('readonly', 'readonly');
                    } else {
                        qtyInput.removeAttribute('readonly');
                    }
                }
                if (priceInput){
                    if (enable){
                        priceInput.value = '0';
                        priceInput.setAttribute('readonly', 'readonly');
                    } else {
                        priceInput.removeAttribute('readonly');
                    }
                }
            });
        }

        function updateCreditNoteUi(){
            var isNc = isCreditNote();
            var motive = getNcMotivo();
            if (isNc && ncMotivoSelect && !motive){
                ncMotivoSelect.value = 'anula';
                motive = 'anula';
            }
            var placeholder = form && form.dataset ? (form.dataset.ncReasonPlaceholder || '') : '';
            if (ncReferenceHint){
                var hints = {
                    anula: ncReferenceHint.getAttribute('data-hint-anula') || '',
                    texto: ncReferenceHint.getAttribute('data-hint-texto') || '',
                    montos: ncReferenceHint.getAttribute('data-hint-montos') || ''
                };
                var hintMessage = hints[motive] || hints.anula || '';
                if (isNc && hintMessage){
                    ncReferenceHint.textContent = hintMessage;
                    ncReferenceHint.style.display = '';
                } else {
                    ncReferenceHint.style.display = 'none';
                }
            }
            if (ncGlobalNote){
                ncGlobalNote.style.display = isNc ? '' : 'none';
            }
            if (ncZeroNotice){
                ncZeroNotice.style.display = (isNc && motive === 'texto') ? '' : 'none';
            }
            if (refBody){
                Array.prototype.forEach.call(refBody.querySelectorAll('tr'), function(row){
                    var codref = row.querySelector('[data-ref-field="codref"]');
                    var globalChk = row.querySelector('[data-ref-field="global"]');
                    var reasonInput = row.querySelector('[data-ref-field="razon"]');
                    var tipoField = row.querySelector('[data-ref-field="tipo"]');
                    var folioField = row.querySelector('[data-ref-field="folio"]');
                    var fechaField = row.querySelector('[data-ref-field="fecha"]');
                    var hasCoreData = false;
                    if (tipoField && tipoField.value){ hasCoreData = true; }
                    if (folioField && folioField.value){ hasCoreData = true; }
                    if (fechaField && fechaField.value){ hasCoreData = true; }
                    if (codref){
                        if (isNc){
                            var requiredCode = getNcRequiredCode();
                            if (requiredCode){
                                codref.value = requiredCode;
                            }
                            codref.setAttribute('disabled', 'disabled');
                        } else {
                            codref.removeAttribute('disabled');
                        }
                    }
                    if (globalChk){
                        if (isNc){
                            globalChk.checked = false;
                            globalChk.setAttribute('disabled', 'disabled');
                        } else {
                            globalChk.removeAttribute('disabled');
                        }
                    }
                    if (reasonInput){
                        if (isNc && motive === 'texto'){
                            if (placeholder){
                                reasonInput.setAttribute('placeholder', placeholder);
                            }
                            if (hasCoreData){
                                reasonInput.setAttribute('required', 'required');
                            } else {
                                reasonInput.removeAttribute('required');
                            }
                        } else {
                            if (placeholder && reasonInput.getAttribute('placeholder') === placeholder){
                                reasonInput.removeAttribute('placeholder');
                            }
                            reasonInput.removeAttribute('required');
                        }
                    }
                });
            }
            applyTextCorrectionLock(isNc && motive === 'texto');
            refreshSteps();
            annotateFieldRequirements();
        }

        function updateTips(){
            if (!tipItems || !tipItems.length){ return; }
            var current = tipoSelect ? parseInt(tipoSelect.value || '0', 10) : 0;
            Array.prototype.forEach.call(tipItems, function(item){
                var attr = item.getAttribute('data-tip-type') || '';
                if (!attr || attr === '*'){
                    item.style.display = '';
                    return;
                }
                var shouldShow = attr.split(',').some(function(part){
                    var trimmed = part.trim();
                    if (trimmed === '*'){ return true; }
                    var parsed = parseInt(trimmed, 10);
                    return !Number.isNaN(parsed) && parsed === current;
                });
                item.style.display = shouldShow ? '' : 'none';
            });
        }

        function validateCreditNote(){
            creditNoteState = evaluateCreditNoteState();
            refreshSteps();
            if (!creditNoteState.valid){
                if (creditNoteState.message){
                    showNotice('error', creditNoteState.message);
                }
                var field = creditNoteState.field;
                if (field){
                    var step = findStepForField(field) || 'resumen';
                    setCurrentStep(step, { focus: true, focusField: field });
                    if (typeof field.reportValidity === 'function'){
                        field.reportValidity();
                    }
                } else {
                    setCurrentStep('resumen', { focus: true });
                }
                return false;
            }
            return true;
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
            var indicatorSelect = row.querySelector('select[data-field="exempt_indicator"]');
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
            if (indicatorSelect){
                indicatorSelect.addEventListener('change', function(){
                    if (indicatorSelect.value){
                        indicatorSelect.dataset.manual = '1';
                    } else {
                        delete indicatorSelect.dataset.manual;
                        delete indicatorSelect.dataset.autoApplied;
                        applyIndicatorDefaults();
                    }
                });
            }
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
            var indicatorLabel = escapeAttribute(getText('itemsIndicatorLabel', 'Indicador de exención'));
            var indicatorAuto = escapeAttribute(getText('itemsIndicatorAuto', 'Automático'));
            var indicatorExempt = escapeAttribute(getText('itemsIndicatorExempt', 'No afecto o exento de IVA'));
            var indicatorNonBillable = escapeAttribute(getText('itemsIndicatorNonBillable', 'Producto o servicio no facturable'));
            row.innerHTML = '<td data-label="' + descLabel + '">' +
                                '<div class="sii-item-stack">' +
                                    '<div class="sii-item-primary"><input type="text" data-field="desc" name="items[][desc]" class="regular-text" /></div>' +
                                    '<div class="sii-item-metrics">' +
                                        '<label class="sii-item-metric"><span>' + qtyLabel + '</span><input type="number" data-field="qty" name="items[][qty]" value="1" step="0.01" data-increment="1" data-decimals="2" inputmode="decimal" min="0" /></label>' +
                                        '<label class="sii-item-metric"><span>' + priceLabel + '</span><input type="number" data-field="price" name="items[][price]" value="0" step="0.01" data-increment="1" data-decimals="2" inputmode="decimal" min="0" /></label>' +
                                    '</div>' +
                                    '<details class="sii-item-advanced dte-section" data-types="33,34,41,43,46,52,56,61,110,111,112" style="display:none">' +
                                            '<summary>' + advancedSummary + '</summary>' +
                                            '<div class="sii-item-advanced-grid">' +
                                                    '<label class="dte-section" data-types="34,41"><span>' + indicatorLabel + '</span><select data-field="exempt_indicator" name="items[][exempt_indicator]"><option value="">' + indicatorAuto + '</option><option value="1">' + indicatorExempt + '</option><option value="2">' + indicatorNonBillable + '</option></select></label>' +
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
                                '</div>' +
                        '</td>' +
                        '<td data-label="' + actionsLabel + '"><button type="button" class="button remove-item" aria-label="' + removeLabel + '">×</button></td>';
            tableBody.appendChild(row);
           initRow(row);
            renumberRows();
            applyIndicatorDefaults();
            toggleSections();
        }
        function applyIndicatorDefaults(){
            if (!tableBody){ return; }
            var currentType = tipoSelect ? parseInt(tipoSelect.value || '39', 10) : 39;
            var shouldDefault = (currentType === 34 || currentType === 41);
            Array.prototype.forEach.call(tableBody.querySelectorAll('select[data-field="exempt_indicator"]'), function(select){
                if (!select){ return; }
                if (shouldDefault){
                    if (!select.dataset.manual && !select.value){
                        select.value = '1';
                        select.dataset.autoApplied = '1';
                    }
                } else if (select.dataset.autoApplied === '1' && !select.dataset.manual){
                    select.value = '';
                    delete select.dataset.autoApplied;
                }
            });
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
            applyIndicatorDefaults();
            validateRutField(false);
            updateCreditNoteUi();
            updateTips();
            refreshSteps();
            annotateFieldRequirements();
        }

        if (tipoSelect){
            tipoSelect.addEventListener('change', toggleSections);
            // Run on load
            toggleSections();
            updateCreditNoteUi();
            updateTips();
        }

        if (ncMotivoSelect){
            ncMotivoSelect.addEventListener('change', updateCreditNoteUi);
        }

       if (tableBody){
            Array.prototype.forEach.call(tableBody.querySelectorAll('tr'), initRow);
            renumberRows();
            updateCreditNoteUi();
            applyIndicatorDefaults();
        }
       if (addBtn && tableBody){
            addBtn.addEventListener('click', function(e){
                e.preventDefault();
                addRow();
                updateCreditNoteUi();
            });
            tableBody.addEventListener('click', function(e){
                if (e.target.classList.contains('remove-item')){
                    e.preventDefault();
                    var tr = e.target.closest('tr');
                    if (tr){ tr.remove(); }
                    renumberRows();
                    updateCreditNoteUi();
                    applyIndicatorDefaults();
                }
            });
        }

        function renumberReferences(){
            if (!refBody){ return; }
            var rows = refBody.querySelectorAll('tr');
            Array.prototype.forEach.call(rows, function(row, index){
                row.setAttribute('data-ref-row', index);
                Array.prototype.forEach.call(row.querySelectorAll('[data-ref-field]'), function(input){
                    var field = input.getAttribute('data-ref-field');
                    if (!field){ return; }
                    input.name = 'references[' + index + '][' + field + ']';
                });
            });
        }

        function clearReferenceRow(row){
            if (!row){ return; }
            Array.prototype.forEach.call(row.querySelectorAll('[data-ref-field]'), function(input){
                if (input.type === 'checkbox'){
                    input.checked = false;
                } else {
                    input.value = '';
                }
            });
        }

        function createReferenceRow(){
            var row = null;
            if (refTemplate){
                if (refTemplate.content && refTemplate.content.firstElementChild){
                    row = refTemplate.content.firstElementChild.cloneNode(true);
                }
                if (!row){
                    var wrapper = document.createElement('tbody');
                    wrapper.innerHTML = (refTemplate.innerHTML || '').trim();
                    if (wrapper.firstElementChild){
                        row = wrapper.removeChild(wrapper.firstElementChild);
                    }
                }
            }
            if (!row && refBody){
                var existing = refBody.querySelector('tr');
                if (existing){
                    row = existing.cloneNode(true);
                }
            }
            if (!row){
                row = document.createElement('tr');
            }
            return row;
        }

        function addReferenceRow(){
            if (!refBody){ return; }
            var row = createReferenceRow();
            if (!row){ return; }
            clearReferenceRow(row);
            refBody.appendChild(row);
            renumberReferences();
            updateCreditNoteUi();
        }

        if (addRefBtn && refBody){
            addRefBtn.addEventListener('click', function(event){
                event.preventDefault();
                addReferenceRow();
                updateCreditNoteUi();
            });
            refBody.addEventListener('click', function(event){
                if (event.target.classList.contains('remove-reference')){
                    event.preventDefault();
                    var row = event.target.closest('tr');
                    if (!row){ return; }
                    if (refBody.querySelectorAll('tr').length === 1){
                        clearReferenceRow(row);
                        updateCreditNoteUi();
                        return;
                    }
                    row.remove();
                    renumberReferences();
                    updateCreditNoteUi();
                }
            });
            renumberReferences();
            updateCreditNoteUi();
        }

        if (refBody){
            refBody.addEventListener('input', function(event){
                var target = event && event.target ? event.target : null;
                if (!target){ return; }
                if (target.hasAttribute('data-ref-field')){
                    updateCreditNoteUi();
                }
            });
        }

        if (form){
            form.addEventListener('invalid', function(event){
                var target = event && event.target ? event.target : null;
                var step = findStepForField(target);
                if (step){
                    setCurrentStep(step, { focus: true, focusField: target });
                }
            }, true);
            form.addEventListener('input', function(){ refreshSteps(); }, true);
            form.addEventListener('change', function(){ refreshSteps(); }, true);
            form.addEventListener('click', function(event){
                var nextBtn = event && event.target && event.target.closest ? event.target.closest('.sii-step-next') : null;
                if (nextBtn){
                    event.preventDefault();
                    var currentStepId = getCurrentStep();
                    if (!currentStepId){ return; }
                    refreshSteps();
                    var status = stepState[currentStepId] || evaluateStep(currentStepId);
                    if (status && !status.complete){
                        setCurrentStep(currentStepId, { focus: true, focusField: status.firstInvalid });
                        if (status.firstInvalid && typeof status.firstInvalid.reportValidity === 'function'){
                            status.firstInvalid.reportValidity();
                        }
                        showNotice('error', getStepIncompleteMessage());
                        return;
                    }
                    var idx = stepOrder.indexOf(currentStepId);
                    if (idx !== -1 && idx + 1 < stepOrder.length){
                        setCurrentStep(stepOrder[idx + 1], { focus: true });
                    }
                    return;
                }
                var prevBtn = event && event.target && event.target.closest ? event.target.closest('.sii-step-prev') : null;
                if (prevBtn){
                    event.preventDefault();
                    var activeStep = getCurrentStep();
                    if (!activeStep){ return; }
                    var currentIndex = stepOrder.indexOf(activeStep);
                    if (currentIndex > 0){
                        setCurrentStep(stepOrder[currentIndex - 1], { focus: true });
                    }
                }
            });
        }

        if (stepper){
            stepper.addEventListener('click', function(event){
                var button = event && event.target && event.target.closest ? event.target.closest('[data-step-target]') : null;
                if (!button){ return; }
                event.preventDefault();
                var targetStep = button.getAttribute('data-step-target');
                if (!targetStep){ return; }
                var currentStepId = getCurrentStep();
                refreshSteps();
                var targetIndex = stepOrder.indexOf(targetStep);
                var currentIndex = currentStepId ? stepOrder.indexOf(currentStepId) : -1;
                if (currentIndex !== -1 && targetIndex > currentIndex){
                    var blockedStatus = null;
                    var blockedIndex = -1;
                    for (var i = currentIndex; i < targetIndex; i++){
                        var stepId = stepOrder[i];
                        var status = stepState[stepId] || evaluateStep(stepId);
                        if (status && !status.complete){
                            blockedStatus = status;
                            blockedIndex = i;
                            break;
                        }
                    }
                    if (blockedStatus && blockedIndex !== -1){
                        var stepToFocus = stepOrder[blockedIndex];
                        setCurrentStep(stepToFocus, { focus: true, focusField: blockedStatus.firstInvalid });
                        if (blockedStatus.firstInvalid && typeof blockedStatus.firstInvalid.reportValidity === 'function'){
                            blockedStatus.firstInvalid.reportValidity();
                        }
                        showNotice('error', getStepIncompleteMessage());
                        return;
                    }
                }
                setCurrentStep(targetStep, { focus: true });
            });
        }

        refreshSteps();
        annotateFieldRequirements();

        var triggerPreview = null;
        var triggerSend = null;

        if (form && previewSubmit && supportsAjax){
            triggerPreview = function(event, submitter){
                if (event && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)){
                    return;
                }
                if (!validateCreditNote()){
                    if (event){ event.preventDefault(); }
                    return;
                }
                if (event){ event.preventDefault(); }
                if (form.getAttribute('data-preview-loading') === '1'){
                    return;
                }
                form.setAttribute('data-preview-loading', '1');
                var button = (submitter && submitter.tagName) ? submitter : previewSubmit;
                var originalLabel = button ? getButtonLabel(button) : '';
                if (button){
                    rememberOriginalButtonLabel(button);
                    var loadingText = texts.loading || originalLabel;
                    setButtonLabel(button, loadingText);
                    button.disabled = true;
                    button.classList.add('updating-message');
                }

                var reset = function(){
                    form.removeAttribute('data-preview-loading');
                    if (button){
                        button.disabled = false;
                        button.classList.remove('updating-message');
                        var restoreLabel = button.dataset && button.dataset.originalLabel ? button.dataset.originalLabel : originalLabel;
                        setButtonLabel(button, restoreLabel || originalLabel);
                    }
                };

                var formData = new FormData(form);
                formData.set('preview', '1');
                formData.set('action', previewAction);
                var params = new URLSearchParams();
                formData.forEach(function(val, key){
                    params.append(key, val);
                });
                var errorMessage = texts.previewError || 'No se pudo generar la vista previa. Inténtalo nuevamente.';
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
                if (!validateCreditNote()){
                    if (event){ event.preventDefault(); }
                    return;
                }
                if (event){ event.preventDefault(); }
                if (form.getAttribute('data-send-loading') === '1'){
                    return;
                }
                form.setAttribute('data-send-loading', '1');
                var button = (submitter && submitter.tagName) ? submitter : sendSubmit;
                var originalLabelSend = button ? getButtonLabel(button) : '';
                if (button){
                    rememberOriginalButtonLabel(button);
                    var sendingText = texts.sending || originalLabelSend;
                    setButtonLabel(button, sendingText);
                    button.disabled = true;
                    button.classList.add('updating-message');
                }

                var resetSend = function(){
                    form.removeAttribute('data-send-loading');
                    if (button){
                        button.disabled = false;
                        button.classList.remove('updating-message');
                        var restoreSend = button.dataset && button.dataset.originalLabel ? button.dataset.originalLabel : originalLabelSend;
                        setButtonLabel(button, restoreSend || originalLabelSend);
                    }
                };

                var formData = new FormData(form);
                formData.delete('preview');
                formData.set('action', sendAction);
                var paramsSend = new URLSearchParams();
                formData.forEach(function(val, key){
                    paramsSend.append(key, val);
                });
                var errorSend = texts.sendError || 'No se pudo enviar el documento. Inténtalo nuevamente.';
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
                    var linkText = texts.viewPdf || 'Descargar PDF';
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
                if (!validateCreditNote()){
                    e.preventDefault();
                    return;
                }
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
