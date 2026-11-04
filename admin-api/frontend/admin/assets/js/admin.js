document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.getElementById('adminSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const toggle = document.getElementById('sidebarToggle');
  const setOpen = (open) => { sidebar?.classList.toggle('open', open); backdrop?.classList.toggle('show', open); };
  toggle?.addEventListener('click', () => setOpen(!sidebar?.classList.contains('open')));
  backdrop?.addEventListener('click', () => setOpen(false));
  document.getElementById('refreshDashboard')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    button.innerHTML = '<i class="bi bi-arrow-repeat"></i> Refreshing...';
    window.location.reload();
  });
});
