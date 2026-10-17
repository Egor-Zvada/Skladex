(() => {
    'use strict';
    document.querySelectorAll('[data-preset-selection]').forEach((form) => {
        const boxes = [...form.querySelectorAll('input[name="asset_uuids[]"]')];
        const all = form.querySelector('[data-preset-select-all]');
        const count = form.querySelector('[data-preset-count]');
        const submit = form.querySelector('[data-preset-submit]');
        const update = () => {
            const selected = boxes.filter((box) => box.checked).length;
            if (count) count.textContent = `Выбрано: ${selected}`;
            if (submit) submit.disabled = selected === 0;
            if (all) {
                all.checked = selected > 0 && selected === boxes.length;
                all.indeterminate = selected > 0 && selected < boxes.length;
                all.disabled = boxes.length === 0;
            }
        };
        all?.addEventListener('change', () => {
            boxes.forEach((box) => { box.checked = all.checked; });
            update();
        });
        boxes.forEach((box) => box.addEventListener('change', update));
        update();
    });
})();
