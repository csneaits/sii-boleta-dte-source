(function(){
    document.addEventListener('DOMContentLoaded', function(){
        var addBtn = document.getElementById('sii-add-item');
        var tableBody = document.querySelector('#sii-items-table tbody');
        var tipoSelect = document.getElementById('sii-tipo');
        if (!addBtn || !tableBody){return;}
        function initRow(row){
            var desc = row.querySelector('input[name*="[desc]"]');
            var price = row.querySelector('input[name*="[price]"]');
            if(!desc){return;}
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
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: params
                }).then(function(r){return r.json();}).then(function(resp){
                    if(!resp || !resp.success){return;}
                    dl.innerHTML = '';
                    cache = {};
                    resp.data.items.forEach(function(p){
                        var opt = document.createElement('option');
                        opt.value = p.name;
                        opt.dataset.price = p.price;
                        dl.appendChild(opt);
                        cache[p.name] = p;
                    });
                });
            });
            desc.addEventListener('change', function(){
                var val = desc.value;
                if(cache[val] && price){
                    price.value = cache[val].price;
                }
            });
        }
        function addRow(){
            var row = document.createElement('tr');
            row.innerHTML = '<td><input type="text" name="items[][desc]" class="regular-text" /></td>'+
                            '<td><input type="number" name="items[][qty]" value="1" step="0.01" /></td>'+
                            '<td><input type="number" name="items[][price]" value="0" step="0.01" /></td>'+
                            '<td><button type="button" class="button remove-item">×</button></td>';
            tableBody.appendChild(row);
            initRow(row);
        }
        Array.prototype.forEach.call(tableBody.querySelectorAll('tr'), initRow);
        addBtn.addEventListener('click', function(e){
            e.preventDefault();
            addRow();
        });
        tableBody.addEventListener('click', function(e){
            if (e.target.classList.contains('remove-item')){
                e.preventDefault();
                var tr = e.target.closest('tr');
                if (tr){ tr.remove(); }
            }
        });

        function toggleSections(){
            if (!tipoSelect) return;
            var t = parseInt(tipoSelect.value || '39', 10);
            document.querySelectorAll('.dte-section').forEach(function(sec){
                var types = (sec.getAttribute('data-types') || '').split(',').map(function(s){return parseInt(s,10);});
                var show = !types.length || types.indexOf(t) !== -1;
                sec.style.display = show ? '' : 'none';
                // enable/disable inputs inside hidden sections to avoid accidental submit
                sec.querySelectorAll('input,select,textarea,button').forEach(function(el){
                    if (show){ el.removeAttribute('disabled'); }
                    else { el.setAttribute('disabled','disabled'); }
                });
            });
        }

        if (tipoSelect){
            tipoSelect.addEventListener('change', toggleSections);
            toggleSections();
        }
    });
})();
