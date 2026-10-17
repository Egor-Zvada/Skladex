document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-document-create]');
    if (!form) return;
    const count = form.querySelector('[data-document-selection]');
    const options = [...form.querySelectorAll('input[name="asset_ids[]"]')];
    const search = document.querySelector('[data-document-search]');
    const refresh = () => {
        const selected = options.filter(option => option.checked).length;
        if (count) count.textContent = `Выбрано: ${selected} из 100`;
        options.forEach(option => { option.disabled = !option.checked && selected >= 100; });
        if (search) {
            search.querySelectorAll('input[name="asset_ids[]"]').forEach(input => input.remove());
            options.filter(option => option.checked).forEach(option => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'asset_ids[]';
                input.value = option.value;
                search.append(input);
            });
        }
    };
    options.forEach(option => option.addEventListener('change', refresh));
    refresh();
    const type = form.querySelector('[data-document-template]');
    const title = form.querySelector('[data-document-title]');
    const defaults = [...(type?.options || [])].map(option => option.dataset.title);
    type?.addEventListener('change', () => {
        if (title && (!title.value.trim() || defaults.includes(title.value))) title.value = type.selectedOptions[0].dataset.title;
    });
});
