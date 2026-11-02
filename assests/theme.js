/* Light / dark switch. Load in <head> (no defer) so the saved choice is applied before the page paints.
   Without a saved choice the page follows the browser setting (see theme.css). */
(() => {
  'use strict';
  const KEY = 'lh-theme';
  const root = document.documentElement;
  const read = () => { try { return localStorage.getItem(KEY); } catch (e) { return null; } };
  const saved = read();
  if (saved === 'dark' || saved === 'light') root.setAttribute('data-theme', saved);

  const isDark = () => (root.getAttribute('data-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')) === 'dark';

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
      const sync = () => button.setAttribute('aria-pressed', String(isDark()));
      sync();
      button.addEventListener('click', () => {
        const next = isDark() ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem(KEY, next); } catch (e) { /* private mode: keep for this visit only */ }
        document.querySelectorAll('[data-theme-toggle]').forEach((b) => b.setAttribute('aria-pressed', String(next === 'dark')));
      });
    });
  });
})();
