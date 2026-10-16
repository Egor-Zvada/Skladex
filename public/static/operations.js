/* Continuous inventory input. No navigation from scanned data; no offline success. */
document.addEventListener('DOMContentLoaded', () => {
  const panel = document.querySelector('[data-inventory-scanner]');
  if (!panel) return;
  const form = panel.querySelector('[data-inventory-form]');
  const video = panel.querySelector('[data-inventory-video]');
  const status = panel.querySelector('[data-inventory-status]');
  const start = panel.querySelector('[data-inventory-camera-start]');
  const stopButton = panel.querySelector('[data-inventory-camera-stop]');
  const retryButton = panel.querySelector('[data-inventory-retry]');
  let controls = null, busy = false, pending = null, last = null, cameraStarting = false, cameraGeneration = 0;
  const key = () => crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
  const stop = () => {
    cameraGeneration++;
    if (controls) controls.stop(); controls = null;
    if (video.srcObject) video.srcObject.getTracks().forEach(track => track.stop());
    video.srcObject = null; video.hidden = true; stopButton.hidden = true; start.hidden = false;
  };
  const send = async (data) => {
    if (busy) return;
    busy = true; status.textContent = 'Сохраняем наблюдение…';
    try {
      const response = await fetch(form.action, {method:'POST', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, body:data, credentials:'same-origin'});
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || (response.status === 409 ? 'Кампания изменилась. Обновите страницу.' : 'Наблюдение не сохранено.'));
      pending = null; retryButton.hidden = true;
      form.elements.expected_version.value = result.version;
      status.textContent = (result.known ? 'Сохранено: ' + result.asset_code : 'Сохранена неизвестная метка для разбора') + ' · ' + result.location;
      const count = document.getElementById('inventory-observed-count');
      if (count && result.progress) count.textContent = result.progress.observed;
      form.elements.code.value = ''; form.elements.client_key.value = key();
    } catch (error) {
      stop(); pending = data; retryButton.hidden = false;
      status.textContent = 'НЕ СОХРАНЕНО. ' + error.message + ' Повтор использует тот же ключ операции.';
    } finally { busy = false; }
  };
  form.addEventListener('submit', event => {
    event.preventDefault(); if (!form.reportValidity() || busy) return;
    if (pending) { status.textContent = 'Сначала повторите несохранённое наблюдение.'; return; }
    form.elements.method.value = 'manual'; form.elements.client_key.value = key();
    form.elements.idempotency_key.value = form.elements.client_key.value; send(new FormData(form));
  });
  retryButton.addEventListener('click', () => { if (pending) send(pending); });
  stopButton.addEventListener('click', stop);
  start.addEventListener('click', async () => {
    if (cameraStarting || controls) return;
    if (!form.elements.location_uuid.value) { status.textContent = 'Выберите фактический кабинет.'; return; }
    if (pending) { status.textContent = 'Есть несохранённое наблюдение. Сначала повторите его.'; return; }
    cameraStarting = true;
    const generation = ++cameraGeneration;
    try {
      const ZXing = await window.Skladex.loadQrLibrary();
      if (generation !== cameraGeneration) return;
      video.hidden = false; start.hidden = true; stopButton.hidden = false;
      const reader = new ZXing.BrowserQRCodeReader();
      const activeControls = await reader.decodeFromConstraints({video:{facingMode:'environment'},audio:false}, video, (result) => {
        if (generation !== cameraGeneration || !result || busy || pending) return;
        const text = result.getText();
        const identity = form.elements.location_uuid.value + ':' + text;
        if (identity === last) return;
        // Frame coalescing: presenting another code resets the previous code.
        last = identity; form.elements.code.value = text; form.elements.method.value = 'qr';
        form.elements.client_key.value = key(); form.elements.idempotency_key.value = form.elements.client_key.value;
        send(new FormData(form));
      });
      if (generation !== cameraGeneration) { activeControls.stop(); return; }
      controls = activeControls;
      status.textContent = 'Камера включена. Наблюдения сохраняются после подтверждения сервера.';
    } catch (error) { stop(); status.textContent = 'Камера недоступна. Используйте ручной код или сканер-клавиатуру.'; }
    finally { cameraStarting = false; }
  });
  form.elements.location_uuid.addEventListener('change', () => { last = null; });
  window.addEventListener('pagehide', stop);
  document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });
});
