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

    function calculateEndValue(start, quantity) {
        const startNumber = Number.parseInt(start, 10);
        const quantityNumber = Number.parseInt(quantity, 10);
        if (Number.isNaN(startNumber) || Number.isNaN(quantityNumber) || startNumber <= 0 || quantityNumber <= 0) {
            return '';
        }
        return String(startNumber + quantityNumber - 1);
    }

    function updateEndField() {
        if (!endField) {
            return;
        }
        endField.value = calculateEndValue(startField ? startField.value : '', qtyField ? qtyField.value : '');
    }

    function openModal(mode, data) {
        currentMode = mode;
        if (mode === 'edit') {
            updateTypeOptions(data.tipo || '');
            modalTitle.textContent = cfg.texts.editTitle;
            idField.value = data.id;
            typeField.value = data.tipo;
            typeField.disabled = true;
            startField.value = data.desde;
            qtyField.value = data.cantidad;
            updateEndField();
        } else {
            updateTypeOptions('');
            modalTitle.textContent = cfg.texts.addTitle;
            idField.value = '0';
            selectFirstAvailableType();
            typeField.disabled = false;
            startField.value = '';
            qtyField.value = '';
            if (endField) {
                endField.value = '';
            }
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
                    cantidad: row.getAttribute('data-cantidad')
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
