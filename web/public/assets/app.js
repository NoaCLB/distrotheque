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

    document.querySelectorAll('[data-arch-toggle]').forEach(function (button) {
        var meta = button.closest('.index__meta');
        if (!meta) {
            return;
        }

        button.hidden = false;
        meta.classList.add('is-collapsible');

        button.addEventListener('click', function () {
            var open = meta.classList.toggle('is-open');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.setAttribute(
                'aria-label',
                open ? button.dataset.labelLess : button.dataset.labelMore
            );
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
