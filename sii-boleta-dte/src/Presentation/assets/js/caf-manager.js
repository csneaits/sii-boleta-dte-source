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

    let currentMode = 'add';

    function openModal(mode, data) {
        currentMode = mode;
        if (mode === 'edit') {
            modalTitle.textContent = cfg.texts.editTitle;
            idField.value = data.id;
            typeField.value = data.tipo;
            typeField.disabled = true;
            startField.value = data.desde;
            qtyField.value = data.cantidad;
        } else {
            modalTitle.textContent = cfg.texts.addTitle;
            idField.value = '0';
            typeField.value = '';
            typeField.disabled = false;
            startField.value = '';
            qtyField.value = '';
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

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const formData = new FormData(form);
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
        });
    }
})();
