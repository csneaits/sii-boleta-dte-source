(function(){
    document.addEventListener('DOMContentLoaded', function(){
        var addBtn = document.getElementById('sii-add-item');
        var tableBody = document.querySelector('#sii-items-table tbody');
        if (!addBtn || !tableBody){return;}
        function addRow(){
            var row = document.createElement('tr');
            row.innerHTML = '<td><input type="text" name="items[][desc]" class="regular-text" /></td>'+
                            '<td><input type="number" name="items[][qty]" value="1" step="0.01" /></td>'+
                            '<td><input type="number" name="items[][price]" value="0" step="0.01" /></td>'+
                            '<td><button type="button" class="button remove-item">×</button></td>';
            tableBody.appendChild(row);
        }
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
    });
})();
