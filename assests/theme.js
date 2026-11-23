/* Load in <head> (no defer) so the browser preference is applied before the page paints. */
(() => {
  'use strict';
  const root = document.documentElement;
  const storageKey = 'lms_theme';
  let saved = null;
  try {
    saved = localStorage.getItem(storageKey);
  } catch (err) {
    saved = null;
  }
  const preference = window.matchMedia('(prefers-color-scheme: dark)');

  const applyTheme = (theme) => {
    root.setAttribute('data-theme', theme);
  };

  if (saved === 'dark' || saved === 'light') {
    applyTheme(saved);
  } else {
    applyTheme(preference.matches ? 'dark' : 'light');
  }

  const onPrefChange = (event) => {
    try {
      if (!localStorage.getItem(storageKey)) {
        applyTheme(event.matches ? 'dark' : 'light');
      }
    } catch (err) {
      applyTheme(event.matches ? 'dark' : 'light');
    }
  };

  if (preference.addEventListener) {
    preference.addEventListener('change', onPrefChange);
  } else if (preference.addListener) {
    preference.addListener(onPrefChange);
  }

  window.toggleTheme = () => {
    const current = root.getAttribute('data-theme') || (preference.matches ? 'dark' : 'light');
    const next = current === 'dark' ? 'light' : 'dark';
    try {
      localStorage.setItem(storageKey, next);
    } catch (err) {
      // storage unavailable
    }
    applyTheme(next);
    return next;
  };
})();

