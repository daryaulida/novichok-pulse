// Пульс новичка — интерфейс
(function () {
  const toast = (msg, ok = true) => {
    const t = document.getElementById('toast');
    if (!t) return alert(msg);
    t.innerHTML = msg;
    t.className = 'toast show ' + (ok ? 'ok' : 'err');
    clearTimeout(t._h);
    t._h = setTimeout(() => (t.className = 'toast'), 6000);
  };
  const post = async (data) => {
    const r = await fetch('api.php', { method: 'POST', body: new URLSearchParams(data) });
    try { return await r.json(); } catch (e) { return { ok: false, error: 'Сервер ответил ошибкой ' + r.status }; }
  };

  // Задача в Битрикс24
  document.querySelectorAll('.js-task').forEach((b) =>
    b.addEventListener('click', async (e) => {
      e.stopPropagation();
      b.disabled = true;
      const old = b.textContent;
      b.textContent = 'Создаю задачу…';
      const res = await post({ action: 'task', id: b.dataset.id });
      if (res.ok && res.local) {
        toast('Битрикс24 не подключён — задача сохранена только в пульте');
        setTimeout(() => location.reload(), 1800);
      } else if (res.ok) {
        toast(`Задача №${res.task_id} создана в Битрикс24. <a target="_blank" href="${res.url}">Открыть ↗</a>`);
        setTimeout(() => location.reload(), 1800);
      } else {
        toast(res.error || 'Ошибка', false);
        b.disabled = false;
        b.textContent = old;
      }
    })
  );

  // Формы: разговор, наставник
  document.querySelectorAll('.js-form').forEach((f) =>
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(f));
      data.action = f.dataset.action;
      data.id = f.dataset.id;
      const res = await post(data);
      if (res.ok) { toast('Сохранено'); setTimeout(() => location.reload(), 700); }
      else toast(res.error || 'Ошибка', false);
    })
  );

  // Сброс демо
  document.querySelectorAll('.js-reset').forEach((f) =>
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      const res = await post({ action: 'reset', key: f.key.value });
      if (res.ok) { toast('Демо-данные пересозданы'); setTimeout(() => (location.href = 'index.php'), 800); }
      else toast(res.error || 'Ошибка', false);
    })
  );

  // Копировать текст
  document.querySelectorAll('.js-copy').forEach((b) =>
    b.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(b.dataset.text); toast('Сообщение скопировано — вставьте в Viber'); }
      catch (e) { prompt('Скопируйте:', b.dataset.text); }
    })
  );

  // QR-коды
  if (window.QRCode) {
    document.querySelectorAll('[data-qr]').forEach((el) => {
      const size = el.classList.contains('sm') ? 88 : 140;
      new QRCode(el, { text: el.dataset.qr, width: size, height: size, correctLevel: QRCode.CorrectLevel.M });
    });
  }

  // Фильтры таблицы
  const tbl = document.getElementById('tbl');
  if (tbl) {
    const fd = document.getElementById('f-dept'), fr = document.getElementById('f-risk'), fq = document.getElementById('f-q');
    const apply = () => {
      const q = fq.value.trim().toLowerCase();
      tbl.querySelectorAll('tbody tr').forEach((tr) => {
        const show = (!fd.value || tr.dataset.dept === fd.value) && (!fr.value || tr.dataset.risk === fr.value) && (!q || tr.dataset.name.includes(q));
        tr.style.display = show ? '' : 'none';
      });
    };
    [fd, fr].forEach((x) => x.addEventListener('change', apply));
    fq.addEventListener('input', apply);
  }

  // График уходов
  const cv = document.getElementById('leftChart');
  if (cv && window.Chart && window.PULSE_CHART) {
    const P = window.PULSE_CHART;
    const colors = { sklad: '#0f766e', shop: '#16a34a', sales: '#84cc16', office: '#94a3b8' };
    new Chart(cv, {
      type: 'bar',
      data: {
        labels: P.buckets,
        datasets: Object.keys(P.depts).map((k) => ({
          label: P.depts[k], data: P.buckets.map((b) => P.data[k][b]), backgroundColor: colors[k], borderRadius: 4, maxBarThickness: 46,
        })),
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { family: 'Inter' } } }, tooltip: { mode: 'index' } },
        scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, ticks: { precision: 0 }, title: { display: true, text: 'человек' } } },
      },
    });
  }
})();
