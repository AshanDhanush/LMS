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

/* Back/forward cache guard. Some browsers keep a finished page in memory and show it again when the person
   presses Back, even after they signed out. When that happens, reload so the server decides whether they may
   still see the page (a signed-out visitor is sent to the sign-in page). */
window.addEventListener('pageshow', (event) => {
  if (event.persisted) window.location.reload();
});
