document.addEventListener('DOMContentLoaded', function () {
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

    // Manejo de archivos CAF dinámicos
    var cafData = siiBoletaSettings;
    var addBtn = document.getElementById('sii-dte-add-caf');
    var cafContainer = document.getElementById('sii-dte-caf-container');

    function updateCafOptions() {
        var selected = Array.from(document.querySelectorAll('.sii-dte-caf-type')).map(function (sel) {
            return sel.value;
        });
        document.querySelectorAll('.sii-dte-caf-type').forEach(function (sel) {
            var current = sel.value;
            Array.from(sel.options).forEach(function (opt) {
                if (opt.value && opt.value !== current && selected.includes(opt.value)) {
                    opt.disabled = true;
                } else {
                    opt.disabled = false;
                }
            });
        });
    }

    if (addBtn && cafContainer) {
        addBtn.addEventListener('click', function () {
            var select = '<select name="' + cafData.optionKey + '[caf_type][]" class="sii-dte-caf-type">';
            select += '<option value="">' + cafData.texts.selectDocument + '</option>';
            Object.keys(cafData.cafOptions).forEach(function (val) {
                select += '<option value="' + val + '">' + cafData.cafOptions[val] + '</option>';
            });
            select += '</select>';
            var row = document.createElement('div');
            row.className = 'sii-dte-caf-row';
            row.innerHTML = select +
                '<input type="file" name="caf_file[]" accept=".xml" />' +
                '<input type="text" name="' + cafData.optionKey + '[caf_path][]" class="regular-text" placeholder="/ruta/CAF.xml" />' +
                '<button type="button" class="button sii-dte-remove-caf">&times;</button>';
            cafContainer.appendChild(row);
            updateCafOptions();
        });

        cafContainer.addEventListener('click', function (e) {
            if (e.target.classList.contains('sii-dte-remove-caf')) {
                e.target.parentNode.remove();
                updateCafOptions();
            }
        });

        cafContainer.addEventListener('change', function (e) {
            if (e.target.classList.contains('sii-dte-caf-type')) {
                updateCafOptions();
            }
        });

        updateCafOptions();
    }

    // Selección del logo
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
                title: cafData.texts.selectLogo,
                button: { text: cafData.texts.useLogo },
                multiple: false
            });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                document.getElementById('sii-dte-logo-preview').src = attachment.url;
                document.getElementById('sii_dte_logo_id').value = attachment.id;
            });
            frame.open();
        });

        removeLogo.addEventListener('click', function () {
            document.getElementById('sii-dte-logo-preview').src = '';
            document.getElementById('sii_dte_logo_id').value = '';
        });
    }

    // Prueba de SMTP
    var smtpBtn = document.getElementById('sii-smtp-test-btn');
    if (smtpBtn) {
        smtpBtn.addEventListener('click', function () {
            var btn = smtpBtn;
            var msg = document.getElementById('sii-smtp-test-msg');
            btn.disabled = true;
            msg.textContent = cafData.texts.sending;
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'sii_boleta_dte_test_smtp',
                    profile: document.getElementById('sii-smtp-profile').value,
                    to: document.getElementById('sii-smtp-test-to').value,
                    _wpnonce: cafData.nonce
                })
            }).then(function (r) { return r.json(); }).then(function (resp) {
                btn.disabled = false;
                if (resp && resp.success) {
                    msg.style.color = 'green';
                    msg.textContent = resp.data && resp.data.message ? resp.data.message : 'OK';
                } else {
                    msg.style.color = 'red';
                    msg.textContent = cafData.texts.sendFail;
                }
            }).catch(function () {
                btn.disabled = false;
                msg.style.color = 'red';
                msg.textContent = cafData.texts.sendFail;
            });
        });
    }
});

