(() => {
    'use strict';
    const form = document.querySelector('[data-directory-settings]');
    if (!form) return;
    const groups = form.querySelector('[data-directory-groups]');
    const add = form.querySelector('[data-directory-add-group]');
    const template = form.querySelector('[data-directory-group-template]');
    const counter = form.querySelector('[data-directory-group-count]');
    const refresh = () => {
        const rows = Array.from(groups.querySelectorAll('[data-directory-group]'));
        rows.forEach((row, index) => {
            row.querySelector('[data-directory-group-title]').textContent = 'Группа ' + (index + 1);
            row.querySelectorAll('[name], [data-directory-name]').forEach((input) => {
                const field = input.dataset.directoryName || input.name.match(/\[(dn|role)\]$/)?.[1];
                if (field) input.name = 'groups[' + index + '][' + field + ']';
            });
        });
        if (add) add.disabled = rows.length >= 20;
        if (counter) counter.textContent = rows.length + ' из 20';
    };
    if (add && template) {
        add.addEventListener('click', () => {
            if (groups.querySelectorAll('[data-directory-group]').length >= 20) return;
            const fragment = template.content.cloneNode(true);
            const input = fragment.querySelector('input');
            groups.append(fragment);
            refresh();
            input?.focus();
        });
        groups.addEventListener('click', (event) => {
            const button = event.target.closest('[data-directory-remove-group]');
            if (!button) return;
            const row = button.closest('[data-directory-group]');
            const focusTarget = row.nextElementSibling?.querySelector('input')
                || row.previousElementSibling?.querySelector('input') || add;
            row.remove();
            refresh();
            focusTarget.focus();
        });
    }
    form.addEventListener('submit', (event) => {
        const save = event.submitter?.hasAttribute('data-directory-save');
        const password = form.querySelector('[data-directory-current-password]');
        if (save && password && !password.value) {
            event.preventDefault();
            password.setCustomValidity('Введите свой текущий пароль для сохранения настроек.');
            password.reportValidity();
            return;
        }
        const status = form.querySelector('[data-directory-submit-status]');
        if (status) status.textContent = save ? 'Сохраняем настройки…' : 'Проверяем подключение…';
    });
    form.querySelector('[data-directory-current-password]')?.addEventListener('input', (event) => {
        event.target.setCustomValidity('');
    });
    form.querySelector('[data-directory-check]')?.addEventListener('click', () => {
        form.querySelector('[data-directory-current-password]')?.setCustomValidity('');
    });
    refresh();
})();
