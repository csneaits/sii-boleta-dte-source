(function() {
    var SELECTOR = 'input[name="sii_rut_recep"], input[name="billing_rut"]';

    function clean(value) {
        return value.replace(/[^0-9kK]/g, '').toUpperCase();
    }

    function format(rut) {
        var c = clean(rut);
        if (c.length <= 1) {
            return c;
        }
        var body = c.slice(0, -1);
        var dv = c.slice(-1);
        var formatted = '';
        while (body.length > 3) {
            formatted = '.' + body.slice(-3) + formatted;
            body = body.slice(0, -3);
        }
        return body + formatted + '-' + dv;
    }

    function computeDV(body) {
        var sum = 0;
        var multiplier = 2;
        for (var i = body.length - 1; i >= 0; i--) {
            sum += parseInt(body.charAt(i), 10) * multiplier;
            multiplier = (multiplier === 7) ? 2 : multiplier + 1;
        }
        var remainder = 11 - (sum % 11);
        if (remainder === 11) {
            return '0';
        }
        if (remainder === 10) {
            return 'K';
        }
        return String(remainder);
    }

    function isValid(rut) {
        var c = clean(rut);
        if (c.length < 2) {
            return false;
        }
        var body = c.slice(0, -1);
        var dv = c.slice(-1);
        var expected = computeDV(body);
        return dv === expected;
    }

    function isGeneric(rut) {
        return clean(rut) === '666666666';
    }

    function checkInput(input) {
        var raw = input.value;
        var c = clean(raw);
        if (!c) {
            input.setCustomValidity('');
            if (raw !== '') {
                input.value = '';
            }
            return true;
        }
        var formatted = format(c);
        if (input.value !== formatted) {
            input.value = formatted;
        }
        if (isGeneric(c)) {
            input.setCustomValidity('RUT genérico no permitido');
            return false;
        }
        if (!isValid(c)) {
            input.setCustomValidity('RUT inválido');
            return false;
        }
        input.setCustomValidity('');
        return true;
    }

    document.addEventListener('input', function(event) {
        var target = event.target;
        if (target && target.matches(SELECTOR)) {
            checkInput(target);
        }
    }, true);

    document.addEventListener('submit', function(event) {
        var form = event.target;
        if (!form || !form.querySelectorAll) {
            return;
        }
        var inputs = form.querySelectorAll(SELECTOR);
        if (!inputs.length) {
            return;
        }
        var valid = true;
        inputs.forEach(function(input) {
            if (!checkInput(input)) {
                valid = false;
                if (typeof input.reportValidity === 'function') {
                    input.reportValidity();
                }
            }
        });
        if (!valid) {
            event.preventDefault();
        }
    }, true);

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll(SELECTOR).forEach(function(input) {
            checkInput(input);
        });
    });
})();
