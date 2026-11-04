/* Student deadlines: a month calendar of assignment due dates and quiz closing times.
   Data comes from api/student_deadlines_api.php. Server text is always set with textContent. */
(() => {
  'use strict';

  const page = document.querySelector('[data-endpoint]');
  if (!page) return;

  const $ = (selector) => page.querySelector(selector);
  const status = $('[data-status]');
  const content = $('[data-content]');
  const daysBody = $('[data-days]');

  const monthFormat = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' });
  const longDay = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const shortDay = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
  const timeFormat = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });
  const dateTimeFormat = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
  const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

  // Week starts on the day that suits the visitor's locale (Monday if the browser can't say).
  const firstDay = (() => {
    try {
      const locale = new Intl.Locale(navigator.language);
      const info = locale.getWeekInfo ? locale.getWeekInfo() : locale.weekInfo;
      return info && info.firstDay ? info.firstDay % 7 : 1;
    } catch (e) { return 1; }
  })();

  const state = { events: [], view: null, selected: null, kind: 'all', hideDone: false };
  const today = new Date();

  const pad = (n) => String(n).padStart(2, '0');
  const key = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const parseKey = (k) => { const [y, m, d] = k.split('-').map(Number); return new Date(y, m - 1, d); };
  const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

  function el(tag, className, text, attributes) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    if (attributes) Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
  }

  function showStatus(message, kind, retry) {
    status.className = `alert alert--${kind}`;
    status.textContent = message;
    status.hidden = false;
    if (retry) {
      const button = el('button', 'btn btn-outline btn-sm dash-retry', 'Try again', { type: 'button' });
      button.addEventListener('click', load);
      status.append(button);
    }
  }

  const visible = (event) => (state.kind === 'all' || event.kind === state.kind) && !(state.hideDone && event.state === 'done');
  const eventsOn = (dayKey) => state.events.filter((e) => e.day === dayKey && visible(e));

  /* ---------------------------------------------------------- Summary */
  function relativeTo(date) {
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]]) {
      if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit);
    }
    return 'now';
  }

  function renderSummary() {
    const now = Date.now();
    const open = state.events.filter((e) => e.state === 'open' && e.date.getTime() >= now);
    const week = open.filter((e) => e.date.getTime() <= now + 7 * 86400000);
    const overdue = state.events.filter((e) => e.state === 'overdue');
    const next = open[0];

    const card = (label, value, sub) => {
      const box = el('div', 'dl-stat');
      box.append(el('span', 'text-muted', label), el('strong', 'dl-stat__value', value), el('span', 'text-muted', sub));
      return box;
    };
    $('[data-summary]').replaceChildren(
      card('Due in the next 7 days', String(week.length), week.length ? 'Still to hand in or attempt' : 'Nothing due this week'),
      card('Overdue', String(overdue.length), overdue.length ? 'Not handed in or attempted' : 'You are all caught up'),
      card('Next deadline', next ? relativeTo(next.date) : '—', next ? next.title : 'No upcoming deadlines'),
    );
  }

  /* ------------------------------------------------------------ Items */
  const icon = (kind) => (kind === 'quiz' ? '✎' : '📄');

  function chipFor(event) {
    const soon = event.state === 'open' && event.date.getTime() - Date.now() <= 2 * 86400000 && event.date.getTime() >= Date.now();
    const cls = event.state === 'done' ? 'chip--success' : (event.state === 'overdue' ? 'chip--danger' : (soon ? 'chip--warning' : ''));
    return el('span', `chip ${cls}`, soon ? `Due soon · ${event.label}` : event.label);
  }

  function item(event, withDate) {
    const row = el('article', `dl-item dl-item--${event.kind} is-${event.state}`);
    row.append(el('span', 'dl-item__icon', icon(event.kind), { 'aria-hidden': 'true' }));
    const body = el('div', 'dl-item__body');
    body.append(el('a', 'dl-item__title', event.title, { href: event.url }),
      el('span', 'text-muted dl-item__meta', `${event.kind === 'quiz' ? 'Quiz closes' : 'Due'} ${withDate ? dateTimeFormat.format(event.date) : timeFormat.format(event.date)} · ${event.course.title}`),
      el('span', 'text-muted dl-item__meta', event.detail));
    row.append(body, chipFor(event));
    return row;
  }

  /* --------------------------------------------------------- Calendar */
  function renderWeekdays() {
    const row = $('[data-weekdays]');
    row.replaceChildren(...[...Array(7).keys()].map((i) => {
      const dayIndex = (firstDay + i) % 7;
      const sample = new Date(2024, 0, 7 + dayIndex); // 7 Jan 2024 is a Sunday
      const th = el('th', 'dl-weekday', null, { scope: 'col' });
      th.append(el('span', null, new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(sample), { 'aria-hidden': 'true' }),
        el('span', 'dl-hidden', new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(sample)));
      return th;
    }));
  }

  function renderCalendar(focusKey) {
    const view = state.view;
    $('[data-month]').textContent = monthFormat.format(view);

    const first = new Date(view.getFullYear(), view.getMonth(), 1);
    const offset = (first.getDay() - firstDay + 7) % 7;
    const count = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
    const weeks = Math.ceil((offset + count) / 7);
    const selectedInView = state.selected && parseKey(state.selected).getMonth() === view.getMonth() && parseKey(state.selected).getFullYear() === view.getFullYear();
    const tabKey = selectedInView ? state.selected : key(first);
    const todayKey = key(today);

    const rows = [];
    for (let w = 0; w < weeks; w += 1) {
      const tr = el('tr');
      for (let d = 0; d < 7; d += 1) {
        const date = new Date(first.getFullYear(), first.getMonth(), 1 - offset + w * 7 + d);
        const k = key(date);
        const events = eventsOn(k);
        const other = date.getMonth() !== view.getMonth();
        const button = el('button', `dl-day${other ? ' is-other' : ''}${k === todayKey ? ' is-today' : ''}${k === state.selected ? ' is-selected' : ''}`, null, {
          type: 'button', 'data-date': k, tabindex: k === tabKey ? '0' : '-1',
          'aria-label': `${longDay.format(date)}, ${events.length ? plural(events.length, 'deadline') : 'no deadlines'}`,
          'aria-pressed': String(k === state.selected),
        });
        button.append(el('span', 'dl-day__num', String(date.getDate()), { 'aria-hidden': 'true' }));
        const pills = el('span', 'dl-pills', null, { 'aria-hidden': 'true' });
        events.slice(0, 2).forEach((e) => pills.append(el('span', `dl-pill dl-pill--${e.kind} is-${e.state}`, e.title)));
        button.append(pills);
        if (events.length > 2) button.append(el('span', 'dl-more', `+${events.length - 2} more`, { 'aria-hidden': 'true' }));
        if (events.length) button.append(el('span', 'dl-dots', null, { 'aria-hidden': 'true' }));
        const dots = button.querySelector('.dl-dots');
        if (dots) events.slice(0, 4).forEach((e) => dots.append(el('i', `dl-dot dl-dot--${e.kind} is-${e.state}`)));
        const td = el('td');
        td.append(button);
        tr.append(td);
      }
      rows.push(tr);
    }
    daysBody.replaceChildren(...rows);
    if (focusKey) {
      const target = daysBody.querySelector(`[data-date="${focusKey}"]`);
      if (target) target.focus();
    }
  }

  function renderDay() {
    const date = parseKey(state.selected);
    $('[data-day-title]').textContent = shortDay.format(date);
    const events = eventsOn(state.selected);
    $('[data-day-list]').replaceChildren(...(events.length
      ? events.map((e) => item(e, false))
      : [el('p', 'text-muted', state.events.some((e) => e.day === state.selected) ? 'Nothing to show for this day with the current filters.' : 'No deadlines on this day.')]));
  }

  function renderSoon() {
    const now = Date.now();
    const pool = state.events.filter((e) => (state.kind === 'all' || e.kind === state.kind) && e.state !== 'done');
    const overdue = pool.filter((e) => e.state === 'overdue').slice(-4).reverse();
    const upcoming = pool.filter((e) => e.state === 'open' && e.date.getTime() >= now).slice(0, 6);
    const parts = [];
    if (overdue.length) parts.push(el('h3', 'dl-group dl-group--overdue', 'Overdue'), ...overdue.map((e) => item(e, true)));
    if (upcoming.length) parts.push(el('h3', 'dl-group', 'Next up'), ...upcoming.map((e) => item(e, true)));
    $('[data-soon-list]').replaceChildren(...(parts.length ? parts : [el('p', 'text-muted', 'No upcoming deadlines. You are all caught up.')]));
  }

  function renderAll(focusKey) {
    renderCalendar(focusKey);
    renderDay();
    renderSoon();
  }

  function select(k, focus) {
    const date = parseKey(k);
    state.selected = k;
    if (date.getMonth() !== state.view.getMonth() || date.getFullYear() !== state.view.getFullYear()) {
      state.view = new Date(date.getFullYear(), date.getMonth(), 1);
    }
    renderAll(focus ? k : null);
  }

  /* --------------------------------------------------------- Controls */
  daysBody.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-date]');
    if (button) select(button.dataset.date, true);
  });

  daysBody.addEventListener('keydown', (event) => {
    const button = event.target.closest('button[data-date]');
    if (!button) return;
    const date = parseKey(button.dataset.date);
    const move = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];
    if (move) {
      event.preventDefault();
      date.setDate(date.getDate() + move);
      select(key(date), true);
    } else if (event.key === 'PageUp' || event.key === 'PageDown') {
      event.preventDefault();
      date.setMonth(date.getMonth() + (event.key === 'PageUp' ? -1 : 1));
      select(key(date), true);
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      const column = (date.getDay() - firstDay + 7) % 7;
      date.setDate(date.getDate() + (event.key === 'Home' ? -column : 6 - column));
      select(key(date), true);
    }
  });

  function stepMonth(delta) {
    state.view = new Date(state.view.getFullYear(), state.view.getMonth() + delta, 1);
    // Keep the same day number selected when it exists in the new month.
    const old = parseKey(state.selected);
    const last = new Date(state.view.getFullYear(), state.view.getMonth() + 1, 0).getDate();
    state.selected = key(new Date(state.view.getFullYear(), state.view.getMonth(), Math.min(old.getDate(), last)));
    renderAll();
  }
  $('[data-prev]').addEventListener('click', () => stepMonth(-1));
  $('[data-next]').addEventListener('click', () => stepMonth(1));
  $('[data-today]').addEventListener('click', () => { state.view = new Date(today.getFullYear(), today.getMonth(), 1); select(key(new Date()), false); });

  $('[data-kinds]').addEventListener('click', (event) => {
    const button = event.target.closest('button[data-kind]');
    if (!button) return;
    state.kind = button.dataset.kind;
    $('[data-kinds]').querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
    renderAll();
  });
  $('[data-hide-done]').addEventListener('change', (event) => { state.hideDone = event.target.checked; renderAll(); });

  /* ------------------------------------------------------------- Load */
  async function load() {
    try {
      const response = await fetch(page.dataset.endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (response.status === 401) { window.location.href = page.dataset.loginUrl; return; }
      const result = await response.json().catch(() => {
        throw new Error(`The server did not return JSON (HTTP ${response.status}). Check the PHP error log.`);
      });
      if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Could not load your deadlines.');
      state.events = result.events.map((e) => ({ ...e, date: new Date(e.at), day: key(new Date(e.at)) }));
      state.view = new Date(today.getFullYear(), today.getMonth(), 1);
      state.selected = key(today);
      status.hidden = true;
      content.hidden = false;
      renderWeekdays();
      renderSummary();
      renderAll();
    } catch (error) {
      content.hidden = true;
      showStatus(error.message || 'Could not load your deadlines.', 'danger', true);
    }
  }

  load();
})();
