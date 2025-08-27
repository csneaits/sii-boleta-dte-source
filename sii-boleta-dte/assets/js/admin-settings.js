document.addEventListener('DOMContentLoaded', function() {
    var fileInput = document.getElementById('sii-dte-cert-file');
    var textInput = document.getElementById('sii-dte-cert-path');
    if (!fileInput || !textInput) {
        return;
    }
    fileInput.addEventListener('change', function() {
        if (fileInput.files && fileInput.files.length > 0) {
            textInput.value = fileInput.files[0].name;
        } else {
            textInput.value = '';
        }
    });
});
