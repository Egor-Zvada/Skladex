/* Skladex interface. No secrets or application data are persisted in the browser. */
(() => {
    'use strict';

    const root = document.documentElement;
    const themeKey = 'skladex.theme';
    const themeMedia = window.matchMedia('(prefers-color-scheme: dark)');
    const mobileMedia = window.matchMedia('(max-width: 980px)');
    const script = document.getElementById('skladex-script');
    let explicitTheme = null;
    let qrLibraryPromise = null;
    const scannerStates = new WeakMap();

    const savedTheme = () => {
        try {
            const value = localStorage.getItem(themeKey);
            return value === 'dark' || value === 'light' ? value : null;
        } catch (_) {
            return null;
        }
    };

    const applyTheme = (theme) => {
        root.dataset.theme = theme;
        const dark = theme === 'dark';
        const label = dark ? 'Включить светлую тему' : 'Включить тёмную тему';
        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.setAttribute('aria-pressed', String(dark));
            button.setAttribute('aria-label', label);
            button.title = label;
            const caption = button.querySelector('[data-theme-label]');
            if (caption) caption.textContent = label;
        });
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.content = dark ? '#171717' : '#FAFAFA';
    };

    explicitTheme = savedTheme();
    applyTheme(explicitTheme || (themeMedia.matches ? 'dark' : 'light'));
    themeMedia.addEventListener('change', () => {
        if (!explicitTheme) applyTheme(themeMedia.matches ? 'dark' : 'light');
    });
    window.addEventListener('storage', (event) => {
        if (event.key !== themeKey) return;
        explicitTheme = savedTheme();
        applyTheme(explicitTheme || (themeMedia.matches ? 'dark' : 'light'));
    });

    const stopVideoStream = (video) => {
        if (!video) return;
        const stream = video.srcObject;
        if (stream && typeof stream.getTracks === 'function') {
            stream.getTracks().forEach((track) => track.stop());
        }
        video.srcObject = null;
    };

    const loadQrLibrary = () => {
        if (window.ZXingBrowser?.BrowserQRCodeReader) return Promise.resolve(window.ZXingBrowser);
        if (qrLibraryPromise) return qrLibraryPromise;
        qrLibraryPromise = new Promise((resolve, reject) => {
            const source = new URL(script?.dataset.qrLibrary || '/assets/qr-scanner.min.js', window.location.href);
            if (source.origin !== window.location.origin || !/^https?:$/.test(source.protocol)) {
                reject(new Error('Локальная библиотека сканирования недоступна.'));
                return;
            }
            const library = document.createElement('script');
            library.src = source.href;
            library.async = true;
            library.dataset.skladexLibrary = 'qr';
            library.addEventListener('load', () => {
                if (window.ZXingBrowser?.BrowserQRCodeReader) resolve(window.ZXingBrowser);
                else {
                    library.remove();
                    reject(new Error('Не удалось подготовить сканер.'));
                }
            }, { once: true });
            library.addEventListener('error', () => {
                library.remove();
                reject(new Error('Не удалось загрузить локальный сканер.'));
            }, { once: true });
            document.head.append(library);
        }).catch((error) => {
            qrLibraryPromise = null;
            throw error;
        });
        return qrLibraryPromise;
    };

    window.Skladex = Object.assign(window.Skladex || {}, { loadQrLibrary, stopVideoStream });

    const initializeNavigation = () => {
        const sidebar = document.getElementById('app-sidebar');
        const workspace = document.getElementById('app-workspace');
        const opener = document.querySelector('[data-nav-toggle]');
        const backdrop = document.querySelector('[data-sidebar-backdrop]');
        if (!sidebar || !opener) return;
        let open = false;

        const setOpen = (value, restoreFocus = true) => {
            open = Boolean(value && mobileMedia.matches);
            document.body.classList.toggle('nav-is-open', open);
            opener.setAttribute('aria-expanded', String(open));
            opener.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
            sidebar.inert = mobileMedia.matches && !open;
            if (workspace) workspace.inert = open;
            if (backdrop) backdrop.hidden = !open;
            if (mobileMedia.matches) sidebar.setAttribute('aria-hidden', String(!open));
            else sidebar.removeAttribute('aria-hidden');
            if (open) {
                sidebar.setAttribute('role', 'dialog');
                sidebar.setAttribute('aria-modal', 'true');
                sidebar.querySelector('[data-nav-close]')?.focus();
            } else {
                sidebar.removeAttribute('role');
                sidebar.removeAttribute('aria-modal');
                if (restoreFocus && mobileMedia.matches) opener.focus();
            }
        };
        opener.addEventListener('click', () => setOpen(!open));
        sidebar.querySelector('[data-nav-close]')?.addEventListener('click', () => setOpen(false));
        backdrop?.addEventListener('click', () => setOpen(false));
        sidebar.addEventListener('click', (event) => {
            if (event.target.closest('a') && open) setOpen(false, false);
        });
        document.addEventListener('keydown', (event) => {
            if (!open) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                setOpen(false);
            }
            if (event.key !== 'Tab') return;
            const elements = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled]), [tabindex="0"]'))
                .filter((element) => element.getClientRects().length);
            const first = elements[0];
            const last = elements[elements.length - 1];
            if (!first) return;
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
        mobileMedia.addEventListener('change', () => setOpen(false, false));
        setOpen(false, false);
    };

    const initializeValidation = () => {
        const summary = document.getElementById('validation-summary');
        if (!summary) return;
        const fields = Array.from(document.querySelectorAll('input:not([type="hidden"]), select, textarea'));
        summary.querySelectorAll('[data-validation-field]').forEach((message, index) => {
            const name = message.dataset.validationField;
            const brackets = name.replace(/\.([^.]*)/g, '[$1]');
            const field = fields.find((element) => element.name === name || element.name === brackets || element.name === `${name}[]`);
            if (!field) return;
            if (!field.id) field.id = `invalid-field-${index}`;
            field.setAttribute('aria-invalid', 'true');
            const describedBy = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
            describedBy.add(message.id);
            field.setAttribute('aria-describedby', Array.from(describedBy).join(' '));
            const link = document.createElement('a');
            link.href = `#${field.id}`;
            link.textContent = message.textContent;
            link.addEventListener('click', () => field.focus());
            message.replaceChildren(link);
        });
        summary.focus();
    };

    const initializeTables = () => {
        document.querySelectorAll('table.table').forEach((table) => {
            let wrapper = table.parentElement;
            if (!wrapper.classList.contains('table-wrap')) {
                wrapper = document.createElement('div');
                wrapper.className = 'table-wrap';
                table.before(wrapper);
                wrapper.append(table);
            }
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute('aria-label', table.caption?.textContent || 'Таблица данных');
            const update = () => {
                if (wrapper.scrollWidth > wrapper.clientWidth + 1) wrapper.tabIndex = 0;
                else wrapper.removeAttribute('tabindex');
            };
            update();
            if (window.ResizeObserver) new ResizeObserver(update).observe(wrapper);
        });
    };

    const scannerError = (error) => {
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) return 'Для камеры откройте Skladex по HTTPS. Пока можно ввести код вручную.';
        const name = error?.name || '';
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError') return 'Доступ к камере запрещён. Разрешите его в настройках сайта или введите код вручную.';
        if (name === 'NotFoundError' || name === 'DevicesNotFoundError') return 'Камера не найдена. Можно ввести код вручную.';
        if (name === 'NotReadableError' || name === 'TrackStartError') return 'Камера занята другим приложением. Закройте его и повторите попытку.';
        if (name === 'OverconstrainedError') return 'Не удалось выбрать камеру. Повторите попытку или введите код вручную.';
        return 'Не удалось включить камеру. Повторите попытку или введите код вручную.';
    };

    const getScannerState = (dialog) => {
        if (!scannerStates.has(dialog)) scannerStates.set(dialog, { generation: 0, controls: null, processing: false, trigger: null, torch: false });
        return scannerStates.get(dialog);
    };

    const stopScanner = (dialog) => {
        const state = getScannerState(dialog);
        state.generation += 1;
        try { state.controls?.stop(); } catch (_) { /* Tracks below are stopped independently. */ }
        state.controls = null;
        state.stream?.getTracks().forEach((track) => track.stop());
        state.stream = null;
        state.torch = false;
        stopVideoStream(dialog.querySelector('[data-scan-video]'));
        const torch = dialog.querySelector('[data-scan-torch]');
        if (torch) {
            torch.hidden = true;
            torch.setAttribute('aria-pressed', 'false');
        }
    };

    const findScannerTarget = (dialog) => {
        try { return document.querySelector(dialog.dataset.scanTarget || 'input[name="code"]'); }
        catch (_) { return null; }
    };

    const startScanner = async (dialog) => {
        stopScanner(dialog);
        const state = getScannerState(dialog);
        const generation = state.generation;
        const previousCamera = state.pendingCamera;
        let releaseCamera;
        const pendingCamera = new Promise((resolve) => { releaseCamera = resolve; });
        state.pendingCamera = pendingCamera;
        state.processing = false;
        const video = dialog.querySelector('[data-scan-video]');
        const status = dialog.querySelector('[data-scan-status]');
        const retry = dialog.querySelector('[data-scan-retry]');
        const placeholder = dialog.querySelector('[data-scan-placeholder]');
        if (!video || !status) {
            releaseCamera();
            if (state.pendingCamera === pendingCamera) state.pendingCamera = null;
            return;
        }
        status.textContent = 'Запрашиваем доступ к камере…';
        if (retry) retry.hidden = true;
        if (placeholder) placeholder.hidden = false;
        try {
            // A closed permission prompt cannot be aborted by the browser. Queue a
            // reopen behind it so stale camera setup cannot detach a newer stream.
            await previousCamera;
            if (!dialog.open || generation !== state.generation) return;
            if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('HTTPS required');
            const library = await loadQrLibrary();
            if (!dialog.open || generation !== state.generation) return;
            const reader = new library.BrowserQRCodeReader();
            const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
            if (!dialog.open || generation !== state.generation) {
                stream.getTracks().forEach((track) => track.stop());
                return;
            }
            state.stream = stream;
            const controls = await reader.decodeFromStream(stream, video, (result) => {
                if (!result || state.processing || generation !== state.generation || !dialog.open) return;
                state.processing = true;
                const text = result.getText().trim();
                const target = findScannerTarget(dialog);
                if (!target || !('value' in target) || !text || text.length > 2048 || (target.maxLength > 0 && text.length > target.maxLength)) {
                    stopScanner(dialog);
                    status.textContent = !target ? 'Поле для кода недоступно. Закройте камеру и попробуйте ручной ввод.' : 'Этот QR не подходит для поля кода. Попробуйте другую метку или введите код вручную.';
                    if (retry) retry.hidden = false;
                    return;
                }
                stopScanner(dialog);
                target.value = text;
                target.dispatchEvent(new Event('input', { bubbles: true }));
                target.dispatchEvent(new Event('change', { bubbles: true }));
                dialog.close();
                target.focus();
                document.dispatchEvent(new CustomEvent('skladex:scan', { detail: { text, target: target.id || target.name, input: target } }));
                // The server resolves the value. Decoded URLs are never followed here.
                if (dialog.dataset.scanSubmit === 'true' && target.form) target.form.requestSubmit();
            });
            if (!dialog.open || generation !== state.generation) {
                controls.stop();
                stopVideoStream(video);
                return;
            }
            state.controls = controls;
            if (placeholder) placeholder.hidden = true;
            status.textContent = 'Камера готова. Поместите QR внутри рамки.';
            const torch = dialog.querySelector('[data-scan-torch]');
            if (torch) torch.hidden = typeof controls.switchTorch !== 'function';
        } catch (error) {
            if (generation !== state.generation || !dialog.open) return;
            stopScanner(dialog);
            status.textContent = scannerError(error);
            if (retry) retry.hidden = false;
        } finally {
            releaseCamera();
            if (state.pendingCamera === pendingCamera) state.pendingCamera = null;
        }
    };

    const closeScanner = (dialog) => {
        stopScanner(dialog);
        dialog.close();
    };

    const initializeScanners = () => {
        document.querySelectorAll('[data-scan-dialog]').forEach((dialog) => {
            dialog.addEventListener('close', () => stopScanner(dialog));
            dialog.addEventListener('cancel', () => stopScanner(dialog));
            dialog.addEventListener('click', (event) => {
                if (event.target !== dialog) return;
                const bounds = dialog.getBoundingClientRect();
                if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeScanner(dialog);
            });
            dialog.querySelector('[data-scan-close]')?.addEventListener('click', () => closeScanner(dialog));
            dialog.querySelector('[data-scan-manual]')?.addEventListener('click', () => {
                closeScanner(dialog);
                findScannerTarget(dialog)?.focus();
            });
            dialog.querySelector('[data-scan-retry]')?.addEventListener('click', () => startScanner(dialog));
            dialog.querySelector('[data-scan-torch]')?.addEventListener('click', async (event) => {
                const state = getScannerState(dialog);
                if (!state.controls?.switchTorch) return;
                const button = event.currentTarget;
                const generation = state.generation;
                button.disabled = true;
                try {
                    state.torch = !state.torch;
                    await state.controls.switchTorch(state.torch);
                    if (generation === state.generation) button.setAttribute('aria-pressed', String(state.torch));
                } catch (_) {
                    if (generation === state.generation) {
                        dialog.querySelector('[data-scan-status]').textContent = 'Подсветка недоступна на этой камере.';
                        button.hidden = true;
                    }
                } finally {
                    button.disabled = false;
                }
            });
        });
        document.querySelectorAll('[data-scan-open]').forEach((button) => button.addEventListener('click', () => {
            const dialog = document.getElementById(button.dataset.scanOpen);
            if (!dialog || !dialog.matches('[data-scan-dialog]')) return;
            if (typeof dialog.showModal !== 'function') {
                findScannerTarget(dialog)?.focus();
                return;
            }
            if (dialog.open) return;
            getScannerState(dialog).trigger = button;
            dialog.showModal();
            startScanner(dialog);
        }));
        const closeScanners = () => document.querySelectorAll('[data-scan-dialog]').forEach((dialog) => {
            stopScanner(dialog);
            if (dialog.open) dialog.close();
        });
        window.addEventListener('pagehide', closeScanners);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) closeScanners();
        });
    };

    const initializeLabelSelection = () => {
        document.querySelectorAll('[data-label-batch]').forEach((form) => {
            const checkboxes = Array.from(form.querySelectorAll('input[name="target_ids[]"]'));
            const selectAll = form.querySelector('[data-label-select-all]');
            const count = form.querySelector('[data-label-count]');
            const update = () => {
                const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
                if (count) count.textContent = `Выбрано: ${selected}`;
                if (selectAll) {
                    selectAll.checked = selected > 0 && selected === checkboxes.length;
                    selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
                    selectAll.disabled = checkboxes.length === 0;
                }
                form.querySelectorAll('[data-label-requires-selection]').forEach((button) => { button.disabled = selected === 0; });
            };
            selectAll?.addEventListener('change', () => {
                checkboxes.forEach((checkbox) => { checkbox.checked = selectAll.checked; });
                update();
            });
            checkboxes.forEach((checkbox) => checkbox.addEventListener('change', update));
            update();
        });
    };

    const initializeSelectVersions = () => {
        document.querySelectorAll('select[data-version-select]').forEach((select) => {
            const update = () => {
                const field = select.form?.elements.namedItem(select.dataset.versionSelect);
                if (field && 'value' in field) field.value = select.selectedOptions[0]?.dataset.version || '';
            };
            select.addEventListener('change', update);
            update();
        });
    };

    const initializeSetupToken = () => {
        const input = document.querySelector('input[data-setup-token]');
        if (!input) return;
        const match = /^#token=([a-fA-F0-9]{64})$/.exec(window.location.hash);
        if (!match) return;
        input.value = match[1];
        // Fragments stay out of HTTP requests; remove the key from browser history.
        window.history.replaceState(null, '', window.location.pathname);
    };

    const initialize = () => {
        initializeSetupToken();
        document.querySelectorAll('[data-theme-toggle]').forEach((button) => button.addEventListener('click', () => {
            explicitTheme = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem(themeKey, explicitTheme); } catch (_) { /* Theme still works with storage blocked. */ }
            applyTheme(explicitTheme);
        }));
        document.querySelectorAll('[data-dismiss-feedback]').forEach((button) => button.addEventListener('click', () => {
            const message = button.closest('[data-feedback]');
            if (!message) return;
            document.getElementById('main-content')?.focus();
            message.remove();
        }));
        document.querySelectorAll('[data-focus-target]').forEach((button) => button.addEventListener('click', () => {
            try { document.querySelector(button.dataset.focusTarget)?.focus(); } catch (_) { /* Invalid selectors cannot navigate. */ }
        }));
        document.addEventListener('keydown', (event) => {
            if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'k' || document.querySelector('dialog[open]') || document.body.classList.contains('nav-is-open')) return;
            const search = document.getElementById('global-search-input');
            if (!search || search.disabled) return;
            event.preventDefault();
            search.focus();
            search.select();
        });
        document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        }));
        initializeNavigation();
        initializeValidation();
        initializeTables();
        initializeScanners();
        initializeLabelSelection();
        initializeSelectVersions();
        document.dispatchEvent(new CustomEvent('skladex:ready'));
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
