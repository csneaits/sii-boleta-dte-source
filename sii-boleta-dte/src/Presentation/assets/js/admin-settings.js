function initAdminSettings() {
    var fileInput = document.getElementById('sii-dte-cert-file');
    var textInput = document.getElementById('sii-dte-cert-path');
    if (fileInput && textInput) {
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length > 0) {
                textInput.value = fileInput.files[0].name;
            } else {
                textInput.value = '';
            }
        });
    }

    if (typeof siiBoletaSettings === 'undefined') {
        return;
    }

    var cfg = siiBoletaSettings;

    var girosContainer = document.getElementById('sii-dte-giros-container');
    var addGiroBtn = document.getElementById('sii-dte-add-giro');
    var removeLabel = addGiroBtn ? addGiroBtn.getAttribute('data-remove-label') : '';

    function createGiroRow(value) {
        var row = document.createElement('div');
        row.className = 'sii-dte-giro-row';

        var input = document.createElement('input');
        input.type = 'text';
        input.name = cfg.optionKey + '[giros][]';
        input.className = 'regular-text sii-input-wide';
        input.value = value || '';
        row.appendChild(input);

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'button sii-dte-remove-giro';
        removeBtn.innerHTML = '&times;';
        if (removeLabel) {
            removeBtn.setAttribute('aria-label', removeLabel);
            removeBtn.title = removeLabel;
        }
        row.appendChild(removeBtn);

        return row;
    }

    function updateGiroRemoveButtons() {
        if (!girosContainer) {
            return;
        }
        var rows = girosContainer.querySelectorAll('.sii-dte-giro-row');
        rows.forEach(function (row) {
            var removeBtn = row.querySelector('.sii-dte-remove-giro');
            if (!removeBtn) {
                return;
            }
            if (rows.length <= 1) {
                removeBtn.disabled = true;
                removeBtn.style.visibility = 'hidden';
            } else {
                removeBtn.disabled = false;
                removeBtn.style.visibility = 'visible';
            }
        });
    }

    if (addGiroBtn && girosContainer) {
        addGiroBtn.addEventListener('click', function (e) {
            e.preventDefault();
            girosContainer.appendChild(createGiroRow(''));
            updateGiroRemoveButtons();
        });

        girosContainer.addEventListener('click', function (e) {
            if (e.target.classList.contains('sii-dte-remove-giro')) {
                e.preventDefault();
                var row = e.target.closest('.sii-dte-giro-row');
                if (row && girosContainer.contains(row)) {
                    row.remove();
                    updateGiroRemoveButtons();
                }
            }
        });

        updateGiroRemoveButtons();
    }

    var selectLogo = document.getElementById('sii-dte-select-logo');
    var removeLogo = document.getElementById('sii-dte-remove-logo');
    if (selectLogo && removeLogo && typeof wp !== 'undefined' && wp.media) {
        var frame;
        selectLogo.addEventListener('click', function (e) {
            e.preventDefault();
            if (frame) {
                frame.open();
                return;
            }
            frame = wp.media({
                title: cfg.texts.selectLogo,
                button: { text: cfg.texts.useLogo },
                multiple: false
            });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                document.getElementById('sii-dte-logo-preview').src = attachment.url;
                document.getElementById('sii_dte_logo_id').value = attachment.id;
            });
            frame.open();
        });

        removeLogo.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('sii-dte-logo-preview').src = '';
            document.getElementById('sii_dte_logo_id').value = '';
        });
    }
}

document.addEventListener('DOMContentLoaded', initAdminSettings);
