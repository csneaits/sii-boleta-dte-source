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

    var wizard = document.querySelector('.sii-settings-wizard');
    if (!wizard) {
        return;
    }

    var stepItems = Array.prototype.slice.call(wizard.querySelectorAll('.sii-settings-steps li'));
    var stepOrder = stepItems
        .map(function (item) {
            var button = item.querySelector('.sii-settings-step-button');
            return button ? button.getAttribute('data-step') : null;
        })
        .filter(function (value) {
            return typeof value === 'string' && value.length > 0;
        });

    var stepSections = {};
    wizard.querySelectorAll('.sii-settings-step').forEach(function (section) {
        var id = section.getAttribute('data-step');
        if (id) {
            stepSections[id] = section;
        }
    });

    if (!stepOrder.length) {
        return;
    }

    var storageKey = 'sii-settings-active-step';
    if (cfg && cfg.optionKey) {
        storageKey += ':' + cfg.optionKey;
    }
    var furthestIndex = -1;

    function focusFirstField(stepId) {
        var section = stepSections[stepId];
        if (!section) {
            return;
        }
        var focusable = section.querySelector(
            'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]):not([type="button"])'
        );
        if (!focusable) {
            focusable = section.querySelector('[tabindex]:not([tabindex="-1"])');
        }
        if (focusable && typeof focusable.focus === 'function') {
            focusable.focus();
        }
    }

    function activateStep(stepId, options) {
        if (!stepId || stepOrder.indexOf(stepId) === -1) {
            return;
        }
        options = options || {};
        var skipStore = !!options.skipStore;
        var focusPanel = !!options.focusPanel;
        var activeIndex = stepOrder.indexOf(stepId);

        if (activeIndex > furthestIndex) {
            furthestIndex = activeIndex;
        }

        Object.keys(stepSections).forEach(function (id) {
            var section = stepSections[id];
            var isActive = id === stepId;
            section.classList.toggle('is-active', isActive);
            section.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            if (isActive) {
                section.removeAttribute('hidden');
            } else {
                section.setAttribute('hidden', 'hidden');
            }
        });

        stepItems.forEach(function (item) {
            var button = item.querySelector('.sii-settings-step-button');
            if (!button) {
                return;
            }
            var id = button.getAttribute('data-step');
            var index = stepOrder.indexOf(id);
            var isActive = id === stepId;
            var isComplete = false;
            if (index > -1) {
                if (index < furthestIndex) {
                    isComplete = true;
                } else if (index === furthestIndex && index !== activeIndex && furthestIndex !== activeIndex) {
                    isComplete = true;
                }
            }
            if (isActive) {
                isComplete = false;
            }
            item.classList.toggle('is-active', isActive);
            item.classList.toggle('is-complete', isComplete);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        if (!skipStore) {
            try {
                window.sessionStorage.setItem(storageKey, stepId);
            } catch (err) {
                /* no-op */
            }
        }

        if (focusPanel) {
            focusFirstField(stepId);
        }
    }

    stepItems.forEach(function (item) {
        var button = item.querySelector('.sii-settings-step-button');
        if (!button) {
            return;
        }
        button.addEventListener('click', function (event) {
            event.preventDefault();
            var targetStep = button.getAttribute('data-step');
            if (targetStep) {
                activateStep(targetStep, { focusPanel: true });
            }
        });
    });

    function findStepTrigger(element, attribute) {
        var el = element;
        while (el && el !== wizard) {
            if (el.nodeType === 1 && el.hasAttribute && el.hasAttribute(attribute)) {
                return el;
            }
            el = el.parentElement;
        }
        return null;
    }

    wizard.addEventListener('click', function (event) {
        var nextTrigger = findStepTrigger(event.target, 'data-step-next');
        if (nextTrigger) {
            event.preventDefault();
            var nextStep = nextTrigger.getAttribute('data-step-next');
            if (nextStep) {
                activateStep(nextStep, { focusPanel: true });
            }
            return;
        }
        var prevTrigger = findStepTrigger(event.target, 'data-step-prev');
        if (prevTrigger) {
            event.preventDefault();
            var prevStep = prevTrigger.getAttribute('data-step-prev');
            if (prevStep) {
                activateStep(prevStep, { focusPanel: true });
            }
        }
    });

    var storedStep = null;
    try {
        storedStep = window.sessionStorage.getItem(storageKey);
    } catch (err) {
        storedStep = null;
    }

    var defaultStep = storedStep && stepOrder.indexOf(storedStep) !== -1 ? storedStep : null;
    if (!defaultStep) {
        var current = wizard.querySelector('.sii-settings-step.is-active');
        if (current) {
            defaultStep = current.getAttribute('data-step');
        }
    }
    if (!defaultStep) {
        defaultStep = stepOrder[0];
    }

    activateStep(defaultStep, { focusPanel: false, skipStore: true });
}

document.addEventListener('DOMContentLoaded', initAdminSettings);
