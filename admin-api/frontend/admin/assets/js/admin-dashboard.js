document.addEventListener('DOMContentLoaded', () => {
  const element = document.getElementById('dashboardMap'), data = document.getElementById('dashboardMapData'), status = document.getElementById('dashboardMapStatus');
  if (!element || !data) return;
  const points = JSON.parse(data.textContent);
  if (!points.length) { element.hidden = true; status.textContent = 'No valid in-city report points to map. No illustrative markers are shown.'; return; }
  const selector = document.createElement('select'); selector.className = 'form-select form-select-sm mb-2'; selector.setAttribute('aria-label', 'Saved Cabadbaran report point');
  for (const point of points) { const option = document.createElement('option'); option.value = point.id; option.textContent = point.code + ' · ' + point.status; selector.appendChild(option); }
  element.before(selector);
  const link = document.createElement('a'); link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = 'Open selected report point in Google Maps'; link.className = 'btn btn-outline-success btn-sm mt-2'; element.after(link);
  const frame = document.createElement('iframe'); frame.title = 'Saved report point in Google Maps'; frame.width = '100%'; frame.height = '290'; frame.style.border = '0'; frame.loading = 'lazy'; frame.referrerPolicy = 'no-referrer'; element.appendChild(frame);
  const select = () => {
    const point = points.find(p => String(p.id) === selector.value);
    if (!point) return;
    const query = encodeURIComponent(point.latitude + ',' + point.longitude);
    frame.src = 'https://maps.google.com/maps?q=' + query + '&z=16&t=m&output=embed';
    link.href = 'https://www.google.com/maps/search/?api=1&query=' + query;
    status.textContent = point.code + ' · ' + point.source + '. One selected saved report point is shown; no farm or illustrative point is substituted. If the preview fails, use the exact-point link.';
  };
  selector.addEventListener('change', select); select();
});
