/* Load in <head> (no defer) so the browser preference is applied before the page paints. */
(() => {
  'use strict';
  const root = document.documentElement;
  const preference = window.matchMedia('(prefers-color-scheme: dark)');
  const applyPreference = (event) => {
    root.setAttribute('data-theme', event.matches ? 'dark' : 'light');
  };

  applyPreference(preference);
  if (preference.addEventListener) {
    preference.addEventListener('change', applyPreference);
  } else {
    preference.addListener(applyPreference);
  }
})();
