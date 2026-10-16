(() => {
    'use strict';
    const form = document.querySelector('[data-label-editor]');
    const configNode = document.getElementById('label-editor-config');
    if (!form || !configNode) return;
    let config;
    try { config = JSON.parse(configNode.textContent); } catch { return; }
    const find = selector => form.querySelector(selector);
    const input = name => form.elements.namedItem(name);
    const fields = Object.keys(config.defaults);
    const grid = find('[data-label-grid]'), sheet = find('[data-label-sheet]');
    const viewport = find('[data-label-viewport]'), status = find('[data-preview-status]');
    const checks = [...form.querySelectorAll('input[name="target_ids[]"]')];
    const preset = find('[data-paper-preset]'), orientation = find('[data-paper-orientation]');
    const sizes = {A4: [210, 297], A3: [297, 420], A5: [148, 210]};
    const images = new Map();
    let page = 0, current = null, box = null, scale = 1, revision = 0;
    let timer, pending, drag = null;
    const round = value => Math.round(value * 100) / 100;
    const selected = () => checks.filter(check => check.checked);
    const tell = (text, error = false) => {
        status.textContent = text;
        status.classList.toggle('is-error', error);
    };
    const clearErrors = () => fields.concat('start_cell').forEach(name => input(name).removeAttribute('aria-invalid'));

    function geometry() {
        const g = {};
        for (const name of fields) {
            const field = input(name), n = field.value === '' ? NaN : Number(field.value);
            if (!Number.isFinite(n) || Math.abs(n) > 1000 || Math.abs(Math.round(n * 100) - n * 100) > 0.000001) {
                field.setAttribute('aria-invalid', 'true');
                throw new Error('Заполните размеры конечными числами, не более двух знаков после запятой.');
            }
            g[name] = round(n);
        }
        for (const axis of ['width', 'height']) if (g['paper_' + axis + '_mm'] < 50 || g['paper_' + axis + '_mm'] > 420) {
            input('paper_' + axis + '_mm').setAttribute('aria-invalid', 'true');
            throw new Error('Каждая сторона листа должна быть от 50 до 420 мм.');
        }
        if (!Number.isInteger(g.columns) || !Number.isInteger(g.rows) || g.columns < 1 || g.rows < 1 || g.columns * g.rows > 500) throw new Error('Укажите целую сетку: от 1 до 500 ячеек.');
        if (g.label_width_mm <= 0 || g.label_height_mm <= 0) throw new Error('Размеры этикетки должны быть больше нуля.');
        if (['gap_x_mm', 'gap_y_mm', 'margin_left_mm', 'margin_top_mm'].some(name => g[name] < 0)) throw new Error('Поля и зазоры не могут быть отрицательными.');
        for (const [axis, count, size, margin, paper] of [['x','columns','label_width_mm','margin_left_mm','paper_width_mm'], ['y','rows','label_height_mm','margin_top_mm','paper_height_mm']]) {
            const start = Math.round(g[margin] * 100) + Math.round(g['offset_' + axis + '_mm'] * 100);
            const end = start + g[count] * Math.round(g[size] * 100) + (g[count] - 1) * Math.round(g['gap_' + axis + '_mm'] * 100);
            if (start < 0 || end > Math.round(g[paper] * 100)) {
                input('offset_' + axis + '_mm').setAttribute('aria-invalid', 'true');
                throw new Error('Сетка выходит за границы листа по оси ' + axis.toUpperCase() + '. Измените размеры, поля или смещение.');
            }
        }
        return g;
    }

    function contentBox(g) {
        const w = g.label_width_mm, h = g.label_height_mm;
        const padding = Math.min(2, w * .06, h * .06), horizontal = w >= h + 18;
        const size = Math.max(.000001, Math.min(28, w - 2 * padding, h - 2 * padding - (horizontal || h < 15 ? 0 : 7)));
        return {qr_x_mm: horizontal ? padding : (w-size)/2, qr_y_mm: horizontal ? (h-size)/2 : padding,
            qr_size_mm: size, text_x_mm: horizontal ? 2*padding+size : padding,
            text_y_mm: horizontal ? Math.max(padding,(h-18)/2) : padding+size+.6,
            text_width_mm: Math.max(0,horizontal ? w-size-3*padding : w-2*padding), horizontal, show_text: horizontal || h >= 15};
    }

    function layout(g, count) {
        const capacity = g.columns * g.rows, start = Number(input('start_cell').value);
        input('start_cell').max = String(capacity);
        if (!Number.isInteger(start) || start < 1 || start > capacity) {
            input('start_cell').setAttribute('aria-invalid','true');
            throw new Error('Начальная ячейка должна быть от 1 до ' + capacity + '.');
        }
        const pages = count ? Math.ceil((start - 1 + count) / capacity) : 1;
        if (count > 500 || pages > 50) throw new Error('Допустимо до 500 этикеток и 50 листов. Уменьшите выборку или измените сетку.');
        return {geometry:g, capacity, pages_count:pages, start};
    }

    function syncPaperControls() {
        const w = Number(input('paper_width_mm').value), h = Number(input('paper_height_mm').value);
        orientation.value = w > h ? 'landscape' : 'portrait';
        preset.value = Object.keys(sizes).find(key => Math.min(w,h) === sizes[key][0] && Math.max(w,h) === sizes[key][1]) || 'custom';
    }

    function syncSelection() {
        const count = selected().length, all = find('[data-label-select-all]');
        find('[data-label-count]').textContent = 'Выбрано: ' + count;
        if (all) {
            const visible = checks.filter(check => !check.closest('tr').hidden), checked = visible.filter(check => check.checked).length;
            all.checked = visible.length > 0 && checked === visible.length;
            all.indeterminate = checked > 0 && checked < visible.length;
        }
    }

    function draw() {
        if (!current) return;
        const g = current.geometry, picked = selected();
        page = Math.max(0, Math.min(page, current.pages_count - 1));
        const zoom = find('[data-preview-zoom]').value;
        scale = zoom === 'fit' ? Math.min(3.78, Math.max(.1,(viewport.clientWidth - 36) / g.paper_width_mm)) : 96/25.4*Number(zoom);
        sheet.style.width = g.paper_width_mm*scale + 'px'; sheet.style.height = g.paper_height_mm*scale + 'px';
        grid.style.left = (g.margin_left_mm + g.offset_x_mm)*scale + 'px';
        grid.style.top = (g.margin_top_mm + g.offset_y_mm)*scale + 'px';
        grid.style.width = (g.columns*g.label_width_mm+(g.columns-1)*g.gap_x_mm)*scale + 'px';
        grid.style.height = (g.rows*g.label_height_mm+(g.rows-1)*g.gap_y_mm)*scale + 'px';
        const fragment = document.createDocumentFragment();
        for (let cell = 0; cell < current.capacity; cell++) {
            const index = page*current.capacity+cell-(current.start-1), target = picked[index];
            const node = document.createElement('div'); node.className = 'le-cell'; node.dataset.cell = String(cell+1);
            node.style.left = (cell%g.columns)*(g.label_width_mm+g.gap_x_mm)*scale+'px';
            node.style.top = Math.floor(cell/g.columns)*(g.label_height_mm+g.gap_y_mm)*scale+'px';
            node.style.width = g.label_width_mm*scale+'px'; node.style.height = g.label_height_mm*scale+'px';
            node.classList.toggle('is-skipped', page===0 && cell+1<current.start);
            node.classList.toggle('is-start', page===0 && cell+1===current.start);
            node.title = 'Ячейка '+(cell+1)+(page===0?' · нажмите для начала печати':'');
            const number = document.createElement('span'); number.className='le-cell-number'; number.textContent=String(cell+1); node.append(number);
            if (target) {
                node.dataset.targetIndex=String(index); node.dataset.uuid=target.value; node.classList.add('is-filled');
                const src = images.get(target.value), qr = document.createElement(src ? 'img' : 'span');
                qr.className='le-qr'; qr.style.left=box.qr_x_mm*scale+'px'; qr.style.top=box.qr_y_mm*scale+'px';
                qr.style.width=qr.style.height=box.qr_size_mm*scale+'px';
                if (src) { qr.src=src; qr.alt='QR '+target.dataset.targetCode; qr.draggable=false; } else {qr.classList.add('le-qr-pending'); qr.textContent='QR'; qr.setAttribute('aria-label',box.qr_size_mm*scale<20?'QR отобразится при увеличении масштаба':'QR подготавливается');}
                node.append(qr);
                if (box.show_text) {
                    const text=document.createElement('div'); text.className='le-label-text';
                    text.style.left=box.text_x_mm*scale+'px'; text.style.top=box.text_y_mm*scale+'px'; text.style.width=box.text_width_mm*scale+'px';
                    text.style.fontSize=(7*25.4/72*scale)+'px'; text.style.lineHeight=(9*25.4/72*scale)+'px';
                    const code=document.createElement('span'); code.className='le-label-code'; code.textContent=target.dataset.targetCode;
                    const name=document.createElement('span'); name.className='le-label-name'; name.style.webkitLineClamp=box.horizontal?'3':'1'; name.textContent=target.dataset.targetName;
                    text.append(code,name); node.append(text);
                }
            }
            fragment.append(node);
        }
        grid.replaceChildren(fragment);
        find('[data-page-label]').textContent='Лист '+(page+1)+' / '+current.pages_count;
        find('[data-page-prev]').disabled=page===0; find('[data-page-next]').disabled=page+1===current.pages_count;
        find('[data-sheet-summary]').textContent=g.paper_width_mm+' × '+g.paper_height_mm+' мм · '+g.columns+' × '+g.rows+' · этикетка '+g.label_width_mm+' × '+g.label_height_mm+' мм';
        find('[data-qr-warning]').hidden=box.qr_size_mm>=20;
    }

    function invalidate() {
        revision++; clearTimeout(timer); if (pending) pending.abort(); pending=null;
    }

    function refresh() {
        invalidate(); clearErrors(); syncSelection();
        try {
            current=layout(geometry(),selected().length); box=contentBox(current.geometry);
            form.classList.remove('is-invalid');
            find('[data-label-pdf]').disabled=selected().length===0;
            form.querySelectorAll('[data-geometry-action]').forEach(button=>button.disabled=false);
            draw(); tell(selected().length?'Положение обновлено. Проверяем на сервере…':'Пустой лист: выберите объекты в списке ниже.');
            timer=setTimeout(verify,250);
        } catch(error) {
            current=null; form.classList.add('is-invalid'); tell(error.message,true);
            find('[data-label-pdf]').disabled=true;
            form.querySelectorAll('[data-geometry-action]').forEach(button=>button.disabled=true);
        }
    }

    function visibleMissing() {
        if (!current || box.qr_size_mm*scale<20) return [];
        const bounds=viewport.getBoundingClientRect();
        return [...grid.querySelectorAll('[data-target-index]')].filter(node=>{
            if (images.has(node.dataset.uuid)) return false;
            const r=node.getBoundingClientRect();
            return r.bottom>bounds.top && r.top<bounds.bottom && r.right>bounds.left && r.left<bounds.right;
        }).slice(0,24).map(node=>Number(node.dataset.targetIndex));
    }

    async function verify() {
        if (!current || drag) return;
        const stamp=revision, indices=visibleMissing(), picked=selected();
        const payload={editor:true, target_type:input('target_type').value, target_ids:picked.map(check=>check.value), start_cell:current.start, qr_indices:indices, ...current.geometry};
        const controller=new AbortController(); if (pending) pending.abort(); pending=controller;
        try {
            const response=await fetch(form.dataset.previewUrl,{method:'POST',credentials:'same-origin',redirect:'error',signal:controller.signal,
                headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':input('_token').value},body:JSON.stringify(payload)});
            const result=await response.json(); if(stamp!==revision) return;
            if(!response.ok) {
                const errors=result.errors||{};
                for(const name of Object.keys(errors)) input(name)?.setAttribute?.('aria-invalid','true');
                throw new Error(Object.values(errors).flat()[0] || (response.status===419?'Сессия истекла. Обновите страницу.':'Не удалось проверить лист. Обновите страницу.'));
            }
            current={...result.data.layout,start:result.data.start_cell};box=result.data.box;
            for(const [index,src] of Object.entries(result.data.images||{})) if(picked[Number(index)] && typeof src==='string' && src.startsWith('data:image/png;base64,')) images.set(picked[Number(index)].value,src);
            draw(); tell(picked.length?'Проверено · этикеток: '+picked.length+' · листов: '+current.pages_count+'.':'Лист проверен. Выберите объекты для печати.');
            if(visibleMissing().length) timer=setTimeout(verify,50);
        } catch(error) {
            if(error.name==='AbortError'||stamp!==revision) return;
            tell(error.message==='Failed to fetch'?'Нет связи с сервером. PDF проверит размеры при скачивании.':error.message,true);
            // Server validation errors block stale previews; network failures leave native server validation available.
            if(error.message!=='Failed to fetch') find('[data-label-pdf]').disabled=true;
        } finally { if(pending===controller) pending=null; }
    }

    function move(dx,dy,base=current?.geometry) {
        if(!base) return;
        const offset=find('[data-drag-mode]').value==='offset';
        for(const [axis,delta,count,size,margin,paper] of [['x',dx,'columns','label_width_mm','margin_left_mm','paper_width_mm'],['y',dy,'rows','label_height_mm','margin_top_mm','paper_height_mm']]) {
            const field=offset?'offset_'+axis+'_mm':margin, other=offset?base[margin]:base['offset_'+axis+'_mm'];
            const span=base[count]*base[size]+(base[count]-1)*base['gap_'+axis+'_mm'];
            const min=offset?-other:Math.max(0,-other), max=base[paper]-span-other;
            input(field).value=round(Math.max(min,Math.min(max,base[field]+delta))).toFixed(2);
        }
        refresh();
    }

    grid.addEventListener('pointerdown',event=>{
        if(event.button!==0 || !current || !event.target.closest('.le-cell')) return;
        event.preventDefault(); grid.focus({preventScroll:true});
        drag={x:event.clientX,y:event.clientY,g:{...current.geometry},scale,moved:false,cell:event.target.closest('.le-cell').dataset.cell};
        grid.setPointerCapture(event.pointerId); grid.classList.add('is-dragging');
    });
    grid.addEventListener('pointermove',event=>{
        if(!drag) return;
        const dx=(event.clientX-drag.x)/drag.scale, dy=(event.clientY-drag.y)/drag.scale;
        if(Math.abs(event.clientX-drag.x)+Math.abs(event.clientY-drag.y)>3) drag.moved=true;
        if(drag.moved) move(dx,dy,drag.g);
    });
    const endDrag=event=>{if(!drag)return;const cell=!drag.moved&&event.type==='pointerup'&&page===0?drag.cell:null;drag=null;grid.classList.remove('is-dragging');if(cell){input('start_cell').value=cell;refresh();}clearTimeout(timer);timer=setTimeout(verify,100);};
    grid.addEventListener('pointerup',endDrag); grid.addEventListener('pointercancel',endDrag);
    grid.addEventListener('keydown',event=>{
        if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)||!current) return;
        event.preventDefault(); const step=event.shiftKey?1:.1;
        move(event.key==='ArrowLeft'?-step:event.key==='ArrowRight'?step:0,event.key==='ArrowUp'?-step:event.key==='ArrowDown'?step:0);
    });
    fields.concat('start_cell').forEach(name=>input(name).addEventListener('input',()=>{if(name.startsWith('paper_'))syncPaperControls();refresh();}));
    checks.forEach(check=>check.addEventListener('change',refresh));
    find('[data-label-select-all]')?.addEventListener('change',event=>{checks.filter(check=>!check.closest('tr').hidden).forEach(check=>check.checked=event.target.checked);refresh();});
    find('[data-target-filter]').addEventListener('input',event=>{
        const query=event.target.value.trim().toLocaleLowerCase('ru');
        checks.forEach(check=>check.closest('tr').hidden=!(check.dataset.targetName+' '+check.dataset.targetCode).toLocaleLowerCase('ru').includes(query));syncSelection();
    });
    const changePage=delta=>{page+=delta;invalidate();draw();timer=setTimeout(verify,100);};
    find('[data-page-prev]').addEventListener('click',()=>changePage(-1));find('[data-page-next]').addEventListener('click',()=>changePage(1));
    find('[data-preview-zoom]').addEventListener('change',()=>{draw();timer=setTimeout(verify,100);});
    let scrollTimer; viewport.addEventListener('scroll',()=>{clearTimeout(scrollTimer);scrollTimer=setTimeout(()=>{if(!pending)verify();},180);},{passive:true});
    find('[data-offset-reset]').addEventListener('click',()=>{input('offset_x_mm').value='0';input('offset_y_mm').value='0';refresh();});
    preset.addEventListener('change',()=>{
        if(preset.value!=='custom') {const sides=[...sizes[preset.value]];if(orientation.value==='landscape')sides.reverse();input('paper_width_mm').value=sides[0];input('paper_height_mm').value=sides[1];}
        refresh();
    });
    orientation.addEventListener('change',()=>{
        const sides=[Number(input('paper_width_mm').value),Number(input('paper_height_mm').value)].sort((a,b)=>a-b);
        if(orientation.value==='landscape')sides.reverse();input('paper_width_mm').value=sides[0];input('paper_height_mm').value=sides[1];refresh();
    });
    find('[data-label-profile]').addEventListener('change',event=>{
        const profile=config.profiles.find(profile=>String(profile.id)===event.target.value), values=profile?.geometry||config.defaults;
        fields.forEach(name=>input(name).value=values[name]);input('profile_id').value=profile?.id||'';input('expected_version').value=profile?.version||'';
        if(input('name'))input('name').value=profile?.name||'';find('[data-profile-version]').textContent=profile?'v'+profile.version:'Новый';
        input('start_cell').value='1';page=0;syncPaperControls();refresh();
    });
    form.addEventListener('submit',event=>{
        try{geometry();if(event.submitter?.hasAttribute('data-label-pdf')||!event.submitter){if(!selected().length)throw new Error('Выберите хотя бы один объект.');layout(geometry(),selected().length);}}
        catch(error){event.preventDefault();tell(error.message,true);}
    });
    new ResizeObserver(()=>{if(!drag){draw();clearTimeout(timer);timer=setTimeout(verify,180);}}).observe(viewport);
    syncPaperControls();refresh();
})();
