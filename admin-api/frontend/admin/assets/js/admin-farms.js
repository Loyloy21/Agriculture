/* Only server-provided, authenticated linked farms; never infer a farm from a name. */
window.renderAdminFarms = (farms) => {
  const esc = value => String(value ?? 'Not recorded').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  if (!Array.isArray(farms) || !farms.length) return '<p>No linked farms recorded.</p>';
  return farms.map(f => `<article class="linked-farm"><header><strong>${esc(f.farm_name)} <small>#${esc(f.id)}</small></strong><span class="account-status ${f.status === 'Active' ? 'active' : 'inactive'}">${esc(f.status)}${Number(f.is_primary) ? ' · Primary' : ''}</span></header><p>${esc(f.agricultural_category)} · ${esc(f.farm_type)} · ${esc(f.land_area)} hectares</p><p>${esc(f.farm_address)}</p><small>Main product: ${esc(f.main_product)} · Location review: ${esc(f.location_review_status)}</small></article>`).join('');
};
