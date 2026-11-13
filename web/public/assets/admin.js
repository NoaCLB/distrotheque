(function () {
    'use strict';

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    var container = document.querySelector('[data-downloads]');
    if (!container) {
        return;
    }

    var template = document.querySelector('[data-download-template]');

    function bindRemove(row) {
        var button = row.querySelector('[data-download-remove]');
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            var rows = container.querySelectorAll('[data-download-row]');
            if (rows.length <= 1) {
                row.querySelectorAll('input').forEach(function (input) {
                    input.value = '';
                });
                return;
            }
            row.remove();
        });
    }

    container.querySelectorAll('[data-download-row]').forEach(bindRemove);

    var add = document.querySelector('[data-download-add]');
    if (add && template && template.content) {
        add.addEventListener('click', function () {
            var clone = template.content.cloneNode(true);
            var row = clone.querySelector('[data-download-row]');
            if (!row) {
                return;
            }
            container.appendChild(clone);
            bindRemove(row);
            var input = row.querySelector('input');
            if (input) {
                input.focus();
            }
        });
    }
})();
