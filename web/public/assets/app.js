(function () {
    'use strict';

    document.documentElement.classList.add('js');

    document.querySelectorAll('[data-autosubmit]').forEach(function (control) {
        var form = control.form;
        if (!form) {
            return;
        }
        control.addEventListener('change', function () {
            form.submit();
        });
    });

    var input = document.getElementById('q');
    if (input) {
        document.addEventListener('keydown', function (event) {
            var tag = (event.target.tagName || '').toLowerCase();
            var typing = tag === 'input' || tag === 'textarea' || event.target.isContentEditable;
            if (event.key === '/' && !typing) {
                event.preventDefault();
                input.focus();
                input.select();
            }
        });
    }
})();
