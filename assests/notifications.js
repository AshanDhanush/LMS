/* Notification bell: unread count, dropdown list, mark as read.
   Data comes from api/notifications_api.php. Server text is always set with textContent. */
(() => {
  'use strict';

  const ICONS = {
    grade: '✅', assignment: '📄', quiz: '✎', reminder: '⏰', reply: '💬', badge: '🏅',
    course: '🎓', submission: '📥', enrollment: '👋', review: '⭐', comment: '💬', security: '🔒',
  };
  const POLL_MS = 60000;
  const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

  function el(tag, className, text, attributes) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    if (attributes) Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
  }

  function ago(iso) {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]]) {
      if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit);
    }
    return 'just now';
  }

  function init(root) {
    const endpoint = root.dataset.endpoint;
    const button = root.querySelector('[data-notif-toggle]');
    const panel = root.querySelector('.notif__panel');
    const badge = root.querySelector('[data-notif-badge]');
    const list = root.querySelector('[data-notif-list]');
    const empty = root.querySelector('[data-notif-empty]');
    const error = root.querySelector('[data-notif-error]');
    const readAll = root.querySelector('[data-notif-readall]');
    const live = root.querySelector('[data-notif-live]');

    let unread = 0;
    let loaded = false;
    let first = true;
    let timer = null;

    async function call(method, fields) {
      const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } };
      let target = endpoint;
      if (method === 'POST') {
        options.body = new URLSearchParams(fields);
      } else if (fields) {
        target += `?${new URLSearchParams(fields)}`;
      }
      const response = await fetch(target, options);
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Notifications could not be loaded.');
      return data;
    }

    function setUnread(count, announce) {
      const increased = count > unread;
      unread = count;
      badge.hidden = count === 0;
      badge.textContent = count > 99 ? '99+' : String(count);
      button.setAttribute('aria-label', count ? `Notifications, ${count} unread` : 'Notifications');
      readAll.disabled = count === 0;
      if (announce && increased) live.textContent = count === 1 ? 'You have a new notification.' : `You have ${count} unread notifications.`;
    }

    function itemNode(item) {
      const li = el('li', 'notif__item');
      const tag = item.url ? 'a' : 'div';
      const row = el(tag, `notif__row${item.read ? '' : ' is-new'}`);
      if (item.url) row.setAttribute('href', item.url);
      row.append(el('span', 'notif__icon', ICONS[item.type] || '🔔', { 'aria-hidden': 'true' }));
      const text = el('span', 'notif__text');
      text.append(el('strong', null, item.title));
      if (item.body) text.append(el('span', 'notif__body', item.body));
      text.append(el('small', null, ago(item.created)));
      row.append(text);
      if (!item.read) row.append(el('span', 'notif__dot', null, { 'aria-label': 'Unread' }));
      row.addEventListener('click', (event) => {
        if (item.read) return;
        item.read = true;
        // Tell the server; a normal click then follows the link (the request is sent first).
        call('POST', { action: 'read', id: String(item.id) }).catch(() => {});
        if (item.url && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
          event.preventDefault();
          setTimeout(() => { window.location.href = item.url; }, 120);
        }
      });
      li.append(row);
      return li;
    }

    function render(data, announce) {
      list.replaceChildren(...data.items.map(itemNode));
      empty.hidden = data.items.length > 0;
      error.hidden = true;
      setUnread(data.unread, announce);
    }

    async function refresh(announce) {
      try {
        const data = await call('GET', first ? { remind: '1' } : null);
        first = false;
        loaded = true;
        render(data, announce);
      } catch (e) {
        if (!loaded) { error.textContent = e.message; error.hidden = false; }
      }
    }

    function open() {
      panel.hidden = false;
      button.setAttribute('aria-expanded', 'true');
      refresh(false);
    }
    function close(returnFocus) {
      panel.hidden = true;
      button.setAttribute('aria-expanded', 'false');
      if (returnFocus) button.focus();
    }

    button.addEventListener('click', () => (panel.hidden ? open() : close(false)));
    document.addEventListener('click', (event) => { if (!panel.hidden && !root.contains(event.target)) close(false); });
    root.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !panel.hidden) { event.stopPropagation(); close(true); } });

    readAll.addEventListener('click', async () => {
      readAll.disabled = true;
      try {
        const data = await call('POST', { action: 'read_all' });
        list.querySelectorAll('.is-new').forEach((row) => { row.classList.remove('is-new'); row.querySelector('.notif__dot')?.remove(); });
        setUnread(data.unread, false);
      } catch (e) {
        error.textContent = e.message;
        error.hidden = false;
        readAll.disabled = false;
      }
    });

    function schedule() {
      clearInterval(timer);
      timer = setInterval(() => { if (!document.hidden) refresh(true); }, POLL_MS);
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(true); });

    refresh(false);
    schedule();
  }

  function start() { document.querySelectorAll('[data-notifications]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
