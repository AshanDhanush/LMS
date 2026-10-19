const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const adminShell = document.querySelector('.admin-shell');
const adminSidebar = document.getElementById('admin-sidebar');

if (sidebarToggle instanceof HTMLButtonElement && adminShell && adminSidebar) {
  const mobileNavigation = window.matchMedia('(max-width: 759px)');

  function setSidebarOpen(isOpen) {
    adminShell.classList.toggle('is-sidebar-open', isOpen);
    sidebarToggle.setAttribute('aria-expanded', String(isOpen));
    sidebarToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
  }

  sidebarToggle.addEventListener('click', () => {
    setSidebarOpen(!adminShell.classList.contains('is-sidebar-open'));
  });

  adminShell.addEventListener('click', (event) => {
    if (event.target === adminShell && adminShell.classList.contains('is-sidebar-open')) {
      setSidebarOpen(false);
    }
  });

  adminSidebar.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => setSidebarOpen(false));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && adminShell.classList.contains('is-sidebar-open')) {
      setSidebarOpen(false);
      sidebarToggle.focus();
    }
  });

  mobileNavigation.addEventListener('change', (event) => {
    if (!event.matches) setSidebarOpen(false);
  });
}

document.querySelectorAll('.admin-status-form select[name="status"]').forEach((statusSelect) => {
  statusSelect.addEventListener('change', () => {
    const statusForm = statusSelect.closest('form');
    if (statusForm instanceof HTMLFormElement) statusForm.requestSubmit();
  });
});