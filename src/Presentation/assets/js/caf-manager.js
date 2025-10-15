(function () {
    if (typeof window.siiBoletaCaf === 'undefined') {
        return;
    }

    const cfg = window.siiBoletaCaf;
    const modal = document.getElementById('sii-boleta-folio-modal');
    const modalTitle = document.getElementById('sii-boleta-folio-modal-title');
    const form = document.getElementById('sii-boleta-folio-form');
    const cancelBtn = document.getElementById('sii-boleta-folio-cancel');
    const addBtn = document.getElementById('sii-boleta-add-folio');
    const table = document.getElementById('sii-boleta-folios-table');
    const idField = document.getElementById('sii-boleta-folio-id');
    const typeField = document.getElementById('sii-boleta-folio-type');
    const startField = document.getElementById('sii-boleta-folio-start');
    const qtyField = document.getElementById('sii-boleta-folio-quantity');
    const endField = document.getElementById('sii-boleta-folio-end');
    const cafField = document.getElementById('sii-boleta-folio-caf');
    const cafInfo = document.getElementById('sii-boleta-folio-caf-info');
    const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    const originalSubmitText = submitBtn ? submitBtn.textContent : '';
    const typeOptions = typeField ? Array.from(typeField.options) : [];

    const usedTypes = new Set();
    if (table) {
        const rows = table.querySelectorAll('tbody tr[data-tipo]');
        rows.forEach((row) => {
            const type = row.getAttribute('data-tipo');
            if (type) {
                usedTypes.add(type);
            }
        });
    }

    let currentMode = 'add';
    let baseQuantity = 0;

    function updateTypeOptions(excludedType) {
        if (!typeOptions.length) {
            return;
        }
        typeOptions.forEach((option) => {
            if (!option) {
                return;
            }
            option.disabled = false;
            if (option.value && usedTypes.has(option.value) && option.value !== excludedType) {
                option.disabled = true;
            }
        });
    }

    function selectFirstAvailableType() {
        if (!typeField) {
            return;
        }
        let selected = '';
        typeOptions.some((option) => {
            if (!option.disabled) {
                selected = option.value;
                return true;
            }
            return false;
        });
        typeField.value = selected;
    }

    function updateEndField() {
        if (!endField) {
            return;
        }
        const startValue = startField ? startField.value : '';
        const startNumber = Number.parseInt(startValue, 10);
        if (Number.isNaN(startNumber) || startNumber <= 0) {
            endField.value = '';
            return;
        }
        const quantityValue = qtyField ? qtyField.value : '';
        if (currentMode === 'edit') {
            let addition = Number.parseInt(quantityValue, 10);
            if (Number.isNaN(addition) || addition < 0) {
                addition = 0;
            }
            const totalQuantity = baseQuantity + addition;
            if (totalQuantity <= 0) {
                endField.value = '';
                return;
            }
            endField.value = String(startNumber + totalQuantity - 1);
            return;
        }
        const quantityNumber = Number.parseInt(quantityValue, 10);
        if (Number.isNaN(quantityNumber) || quantityNumber <= 0) {
            endField.value = '';
            return;
        }
        endField.value = String(startNumber + quantityNumber - 1);
    }

    function describeCaf(name, uploaded) {
        if (!cafInfo) {
            return;
        }
        if (!name) {
            cafInfo.textContent = cfg.texts.noCaf;
            return;
        }
        let description = cfg.texts.currentCaf.replace('%s', name);
        if (uploaded) {
            description += ' ' + cfg.texts.uploadedOn.replace('%s', uploaded);
        }
        cafInfo.textContent = description;
    }

    function openModal(mode, data) {
        currentMode = mode;
        if (mode === 'edit') {
            updateTypeOptions(data.tipo || '');
            modalTitle.textContent = cfg.texts.editTitle;
            idField.value = data.id;
            typeField.value = data.tipo;
            typeField.disabled = true;
            baseQuantity = Number.parseInt(data.cantidad || '0', 10);
            if (Number.isNaN(baseQuantity) || baseQuantity < 0) {
                baseQuantity = 0;
            }
            startField.value = data.desde;
            startField.readOnly = true;
            if (qtyField) {
                qtyField.value = '0';
                qtyField.min = '0';
            }
            updateEndField();
            describeCaf(data.cafName || '', data.cafUploaded || '');
        } else {
            updateTypeOptions('');
            modalTitle.textContent = cfg.texts.addTitle;
            idField.value = '0';
            selectFirstAvailableType();
            typeField.disabled = false;
            baseQuantity = 0;
            if (startField) {
                startField.readOnly = false;
            }
            startField.value = '';
            if (qtyField) {
                qtyField.value = '';
                qtyField.min = '1';
            }
            if (endField) {
                endField.value = '';
            }
            describeCaf('', '');
        }
        if (cafField) {
            cafField.value = '';
        }
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function (e) {
            e.preventDefault();
            closeModal();
        });
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            openModal('add', {});
        });
    }

    if (table) {
        table.addEventListener('click', function (event) {
            const target = event.target;
            if (!target) {
                return;
            }
            if (target.classList.contains('sii-boleta-edit-folio')) {
                const row = target.closest('tr');
                if (!row) {
                    return;
                }
                const data = {
                    id: row.getAttribute('data-id'),
                    tipo: row.getAttribute('data-tipo'),
                    desde: row.getAttribute('data-desde'),
                    cantidad: row.getAttribute('data-cantidad'),
                    cafName: row.getAttribute('data-caf-name'),
                    cafUploaded: row.getAttribute('data-caf-uploaded')
                };
                openModal('edit', data);
            }
            if (target.classList.contains('sii-boleta-delete-folio')) {
                const row = target.closest('tr');
                if (!row) {
                    return;
                }
                if (!window.confirm(cfg.texts.deleteConfirm)) {
                    return;
                }
                const formData = new FormData();
                formData.append('action', 'sii_boleta_dte_delete_folio_range');
                formData.append('id', row.getAttribute('data-id') || '0');
                formData.append('nonce', cfg.nonce);
                fetch(cfg.ajax, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                    .then((response) => response.json())
                    .then((payload) => {
                        if (!payload || !payload.success) {
                            const message = payload && payload.data && payload.data.message ? payload.data.message : cfg.texts.genericError;
                            window.alert(message);
                            return;
                        }
                        window.location.reload();
                    })
                    .catch(() => {
                        window.alert(cfg.texts.genericError);
                    });
            }
        });
    }

    if (startField) {
        startField.addEventListener('input', updateEndField);
    }

    if (qtyField) {
        qtyField.addEventListener('input', updateEndField);
    }

    if (cafField && cafInfo) {
        cafField.addEventListener('change', function () {
            if (!cafField.files || !cafField.files.length) {
                return;
            }
            const file = cafField.files[0];
            describeCaf(file.name, '');
        });
    }

    function setSubmitting(isSubmitting) {
        if (!submitBtn) {
            return;
        }
        if (isSubmitting) {
            submitBtn.disabled = true;
            submitBtn.classList.add('is-loading');
            submitBtn.textContent = cfg.texts.saving || originalSubmitText;
        } else {
            submitBtn.disabled = false;
            submitBtn.classList.remove('is-loading');
            submitBtn.textContent = originalSubmitText;
        }
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const formData = new FormData(form);
            if (typeField && typeField.disabled) {
                formData.append('tipo', typeField.value || '');
            }
            if (startField && startField.readOnly) {
                formData.set('start', startField.value || '');
            }
            if (currentMode === 'edit') {
                let addition = qtyField ? Number.parseInt(qtyField.value || '0', 10) : 0;
                if (Number.isNaN(addition) || addition < 0) {
                    addition = 0;
                }
                const totalQuantity = baseQuantity + addition;
                formData.set('quantity', String(totalQuantity));
            }
            formData.append('nonce', cfg.nonce);
            setSubmitting(true);
            fetch(cfg.ajax, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then((response) => response.json())
                .then((payload) => {
                    if (!payload || !payload.success) {
                        const message = payload && payload.data && payload.data.message ? payload.data.message : cfg.texts.genericError;
                        window.alert(message);
                        setSubmitting(false);
                        return;
                    }
                    window.location.reload();
                })
                .catch(() => {
                    window.alert(cfg.texts.genericError);
                    setSubmitting(false);
                });
        });
    }
})();
