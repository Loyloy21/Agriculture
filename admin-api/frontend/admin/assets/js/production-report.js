// Production Report filters and record details. Filters submit as a normal GET form (server-side filtering,
// totals and pagination); this script only provides searchable, dependent pickers and the read-only details modal.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('productionFilters');
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
  // Same matching as the farmer app: case-insensitive, accents ignored, every typed word must appear.
  const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  const matches = (textValue, query) => normalize(query).split(' ').filter(Boolean).every((word) => normalize(textValue).includes(word));

  const refresh = document.getElementById('refreshProductionReport');
  refresh?.addEventListener('click', () => {
    refresh.disabled = true;
    refresh.innerHTML = '<i class="bi bi-arrow-repeat" aria-hidden="true"></i> Refreshing...';
    window.location.reload();
  });

  // Custom dates are only sent (and only required) for the custom period.
  const period = document.getElementById('period');
  const customRange = document.getElementById('customRange');
  const syncPeriod = () => {
    const custom = period?.value === 'custom';
    if (customRange) customRange.hidden = !custom;
    customRange?.querySelectorAll('input').forEach((input) => { input.disabled = !custom; input.required = custom; });
  };
  period?.addEventListener('change', syncPeriod);
  syncPeriod();

  let commodities = [];
  try { commodities = JSON.parse(document.getElementById('productionCommodities')?.textContent || '[]'); } catch { commodities = []; }
  const category = document.getElementById('category');
  const categoryLabels = Object.fromEntries(Array.from(category?.options || []).map((option) => [option.value, option.textContent]));

  /** Accessible combobox: a text field that filters a listbox and stores the chosen id in a hidden input. */
  const combobox = (root, loadOptions, emptyText) => {
    const input = root.querySelector('[role="combobox"]');
    const hidden = root.querySelector('input[type="hidden"]');
    const list = root.querySelector('[role="listbox"]');
    const clear = root.querySelector('.production-combobox-clear');
    let options = [];
    let active = -1;
    let request = 0;
    let timer = null;
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
    const highlight = (index) => {
      active = index;
      list.querySelectorAll('[role="option"]').forEach((item, position) => item.classList.toggle('active', position === index));
      const current = list.querySelector(`[data-index="${index}"]`);
      if (current) { input.setAttribute('aria-activedescendant', current.id); current.scrollIntoView({ block: 'nearest' }); }
    };
    const render = (items, message) => {
      options = items;
      list.innerHTML = items.length
        ? items.map((item, index) => `<li role="option" id="${list.id}-${index}" data-index="${index}" aria-selected="${String(item.id) === hidden.value}"><span>${escapeHtml(item.label)}</span>${item.detail ? `<small>${escapeHtml(item.detail)}</small>` : ''}</li>`).join('')
        : `<li class="production-combobox-empty" role="presentation">${escapeHtml(message || emptyText)}</li>`;
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      highlight(items.length ? 0 : -1);
    };
    const choose = (item) => {
      hidden.value = item ? String(item.id) : '';
      input.value = item ? item.label : '';
      input.dataset.selectedLabel = input.value;
      if (clear) clear.hidden = !item;
      close();
      root.dispatchEvent(new CustomEvent('combobox-change', { detail: item }));
    };
    const update = () => {
      const current = ++request;
      clearTimeout(timer);
      // Remote lists wait for a pause in typing, so typing does not send a request per key.
      timer = setTimeout(async () => {
        try {
          const items = await loadOptions(input.value === input.dataset.selectedLabel ? '' : input.value);
          if (current === request && document.activeElement === input) render(items);
        } catch (error) {
          if (current === request) render([], error.message || 'The list could not be loaded.');
        }
      }, loadOptions.remote ? 250 : 0);
    };
    input.addEventListener('focus', update);
    input.addEventListener('input', update);
    input.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (list.hidden) { update(); return; }
        if (options.length) highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
      } else if (event.key === 'Enter' && !list.hidden && active >= 0) {
        event.preventDefault();
        choose(options[active]);
      } else if (event.key === 'Escape') {
        close();
      }
    });
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
      const item = event.target.closest('[role="option"]');
      if (item) choose(options[Number(item.dataset.index)]);
    });
    input.addEventListener('blur', () => {
      close();
      // Free text that was not chosen from the list is not a filter: show the applied choice again.
      if (input.value.trim() === '') choose(null);
      else input.value = input.dataset.selectedLabel || '';
    });
    clear?.addEventListener('click', () => { choose(null); input.focus(); });
    return { choose, root, hidden };
  };

  // Commodity: depends on the selected category (all valid commodities when "All categories").
  const commodityRoot = document.querySelector('[data-combobox="commodity"]');
  const commodityOptions = async (query) => commodities
    .filter((item) => (!category?.value || item.category === category.value) && matches(`${item.name} ${item.group} ${item.searchNames}`, query))
    .slice(0, 80)
    .map((item) => ({ id: item.id, label: item.name, detail: `${categoryLabels[item.category] || item.category} · ${item.group}${item.active ? '' : ' · inactive'}` }));
  const commodityBox = commodityRoot ? combobox(commodityRoot, commodityOptions, 'No matching commodity found.') : null;
  category?.addEventListener('change', () => {
    const selected = commodities.find((item) => String(item.id) === commodityBox?.hidden.value);
    if (selected && category.value && selected.category !== category.value) commodityBox.choose(null);
  });

  const remote = (lookup, extra = () => ({})) => {
    const load = async (query) => {
      const params = new URLSearchParams({ lookup, q: query, ...extra() });
      const response = await fetch(`production-report-data.php?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const payload = await response.json().catch(() => null);
      if (response.status === 401) throw new Error('Your admin session expired. Reload the page and log in again.');
      if (!response.ok || !payload?.success) throw new Error(payload?.message || 'The list could not be loaded.');
      return payload.data;
    };
    load.remote = true;
    return load;
  };
  const farmerRoot = document.querySelector('[data-combobox="farmer"]');
  const farmerBox = farmerRoot ? combobox(farmerRoot, remote('farmers'), 'No matching farmer found.') : null;
  const farmRoot = document.querySelector('[data-combobox="farm"]');
  // Farm areas narrow to the selected farmer's farms.
  if (farmRoot) combobox(farmRoot, remote('farms', () => (farmerBox?.hidden.value ? { farmer_id: farmerBox.hidden.value } : {})), 'No matching farm area found.');

  form?.addEventListener('submit', () => {
    // Keep links short: unset filters and the default sort are not sent.
    form.querySelectorAll('input[type="hidden"], input[name="search"]').forEach((input) => { if (input.value === '') input.disabled = true; });
  });

  // Read-only record details (the row's own data; no editing, approval or deletion here).
  const modalElement = document.getElementById('productionDetailsModal');
  const modal = modalElement ? bootstrap.Modal.getOrCreateInstance(modalElement) : null;
  const formatDate = (value, withTime = false) => {
    if (!value) return 'Not recorded';
    const date = new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00+08:00` : `${String(value).replace(' ', 'T')}+08:00`);
    return Number.isFinite(date.getTime()) ? date.toLocaleString('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric', ...(withTime ? { hour: 'numeric', minute: '2-digit' } : {}) }) : 'Not recorded';
  };
  document.querySelectorAll('.view-production-btn').forEach((button) => button.addEventListener('click', () => {
    let record;
    try { record = JSON.parse(document.getElementById(`production-record-${button.dataset.recordId}`)?.textContent || 'null'); } catch { record = null; }
    if (!record || !modal) return;
    document.getElementById('productionDetailsCode').textContent = record.code;
    const rows = [
      ['Report ID', record.code],
      ['Farmer', record.farmerName],
      ['Farm area', `${record.farmName || 'Not recorded'}${record.farmBarangay ? ` · Brgy. ${record.farmBarangay}` : ''}${record.farmArchived ? ' (archived farm)' : ''}`],
      ['Production category', record.categoryLabel],
      ['Commodity', `${record.commodityName} (${record.commodityGroup})${record.commodityInactive ? ' · no longer offered for new records' : ''}`],
      ['Quantity produced', record.quantityText],
      ['Unit of measurement', record.quantityKg === null ? `${record.unitName} · not added to kilogram totals` : record.unitCode === 'kg' ? record.unitName : `${record.unitName} · ${record.quantityKg.toLocaleString('en-PH', { maximumFractionDigits: 2 })} kg equivalent`],
      ['Date harvested / produced', formatDate(record.harvestDate)],
      ['Date submitted', formatDate(record.submittedAt, true)],
      ['Record status', `${record.status}${record.counted ? ' · counted in totals' : ' · not counted in totals'}`],
    ];
    document.getElementById('productionDetailsList').innerHTML = rows.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('');
    document.getElementById('productionDetailsRemarks').textContent = record.remarks || 'No remarks.';
    modal.show();
  }));
  // Opened from a dashboard link to one exact report: show its details straight away.
  const openRecord = document.getElementById('productionOpenRecord')?.textContent.trim();
  if (openRecord) document.querySelector(`.view-production-btn[data-record-id="${openRecord}"]`)?.click();
});
