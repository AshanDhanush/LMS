/* Sidebar menu (off-canvas on small screens) for the student pages. */
(() => {
  'use strict';
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  const button = document.getElementById('menuBtn');
  if (!sidebar || !overlay || !button) return;
  const set = (open) => {
    sidebar.classList.toggle('open', open);
    overlay.classList.toggle('show', open);
    button.setAttribute('aria-expanded', String(open));
  };
  button.addEventListener('click', () => set(!sidebar.classList.contains('open')));
  overlay.addEventListener('click', () => set(false));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') set(false); });
  sidebar.addEventListener('click', (event) => { if (event.target.closest('a')) set(false); });
})();
