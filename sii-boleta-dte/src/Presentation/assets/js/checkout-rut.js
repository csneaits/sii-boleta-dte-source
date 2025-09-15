(function() {
    function clean(value) {
        return value.replace(/[^0-9kK]/g, '').toUpperCase();
    }
    function format(rut) {
        var c = clean(rut);
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
    document.addEventListener('DOMContentLoaded', function() {
        var input = document.querySelector('input[name="sii_rut_recep"]');
        if (!input) {
            return;
        }
        var form = input.closest('form');
        function check() {
            var raw = input.value;
            var c = clean(raw);
            if (!c) {
                input.setCustomValidity('');
                input.value = '';
                return true;
            }
            input.value = format(c);
            if (c === '666666666') {
                input.setCustomValidity('RUT genérico no permitido');
                input.reportValidity();
                return false;
            }
            if (!isValid(c)) {
                input.setCustomValidity('RUT inválido');
                input.reportValidity();
                return false;
            }
            input.setCustomValidity('');
            return true;
        }
        input.addEventListener('input', check);
        form.addEventListener('submit', function(e) {
            if (!check()) {
                e.preventDefault();
                input.reportValidity();
            }
        });
    });
})();
