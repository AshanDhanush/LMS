/* Student "My courses": shows the courses the student is enrolled in, using
   api/student_dashboard_api.php. Server values are set with textContent only. */
(() => {
  'use strict';

  /* ---- Sidebar (off-canvas on small screens) ---- */
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  const menuButton = document.getElementById('menuBtn');
  function setMenu(open) {
    if (!sidebar || !overlay || !menuButton) return;
    sidebar.classList.toggle('open', open);
    overlay.classList.toggle('show', open);
    menuButton.setAttribute('aria-expanded', String(open));
  }
  menuButton?.addEventListener('click', () => setMenu(!sidebar.classList.contains('open')));
  overlay?.addEventListener('click', () => setMenu(false));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setMenu(false); });
  sidebar?.addEventListener('click', (event) => { if (event.target.closest('a')) setMenu(false); });

  /* ---- Page ---- */
  const page = document.querySelector('[data-dashboard-endpoint]');
  if (!page) return;

  const status = page.querySelector('[data-status]');
  const content = page.querySelector('[data-content]');
  const grid = page.querySelector('[data-grid]');
  const count = page.querySelector('[data-count]');
  const tabs = page.querySelector('[data-tabs]');
  const search = document.getElementById('mySearch');
  const sort = document.getElementById('mySort');

  const FILTERS = [
    ['all', 'All'],
    ['progress', 'In progress'],
    ['new', 'Not started'],
    ['done', 'Completed'],
  ];
  const state = { filter: 'all', q: '', sort: 'recent' };
  let courses = [];

  function el(tag, className, text, attributes) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    if (attributes) Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
  }

  const percent = (course) => Math.max(0, Math.min(100, Math.round(Number(course.progress) || 0)));
  const kind = (course) => (course.completed || percent(course) >= 100 ? 'done' : (course.lessons_done > 0 ? 'progress' : 'new'));

  function showStatus(message, type, retry) {
    status.className = `alert alert--${type}`;
    status.textContent = message;
    status.hidden = false;
    if (retry) {
      const button = el('button', 'btn btn-outline btn-sm dash-retry', 'Try again', { type: 'button' });
      button.addEventListener('click', load);
      status.append(button);
    }
  }

  function drawTabs() {
    tabs.replaceChildren(...FILTERS.map(([key, label]) => {
      const total = key === 'all' ? courses.length : courses.filter((c) => kind(c) === key).length;
      const button = el('button', 'filter-chip catalog-chip', `${label} (${total})`, {
        type: 'button', 'data-key': key, 'aria-pressed': String(state.filter === key),
      });
      return button;
    }));
  }

  function card(course) {
    const done = kind(course) === 'done';
    const value = percent(course);
    const article = el('article', 'course-card catalog-card my-card');

    const thumb = el('div', 'course-card__thumb');
    if (course.thumbnail_url) {
      thumb.append(el('img', null, null, { src: course.thumbnail_url, alt: '', loading: 'lazy' }));
    } else {
      thumb.textContent = '📚';
      thumb.setAttribute('aria-hidden', 'true');
    }

    const body = el('div', 'course-card__body');
    if (course.category) body.append(el('div', 'course-card__category', course.category));
    body.append(
      el('h3', 'course-card__title', course.title),
      el('div', 'course-card__meta', `By ${course.instructor}`),
    );

    const progress = el('div', 'my-card__progress');
    const label = el('div', 'catalog-card__facts');
    label.append(
      el('span', 'text-muted', `${course.lessons_done} of ${course.lessons_total} lessons`),
      el('span', 'fw-600', `${value}%`),
    );
    const track = el('div', 'progress', null, {
      role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(value),
      'aria-label': `${course.title} progress`,
    });
    const bar = el('div', `progress__bar${done ? ' progress__bar--done' : ''}`);
    bar.style.width = `${value}%`;
    track.append(bar);
    progress.append(label, track);
    body.append(progress);

    const footer = el('div', 'my-card__footer');
    if (done) footer.append(el('span', 'chip chip--success', 'Completed'));
    footer.append(el('a', `btn btn-sm ${done ? 'btn-outline' : 'btn-primary'}`,
      done ? 'Review course' : (course.lessons_done > 0 ? 'Continue' : 'Start course'),
      { href: `${page.dataset.courseUrl}/${course.id}`, 'aria-label': `Open ${course.title}` }));
    body.append(footer);

    article.append(thumb, body);
    return article;
  }

  function render() {
    const q = state.q.toLowerCase();
    let list = courses.filter((c) => (state.filter === 'all' || kind(c) === state.filter)
      && (!q || `${c.title} ${c.instructor} ${c.category || ''}`.toLowerCase().includes(q)));

    if (state.sort === 'title') list = [...list].sort((a, b) => a.title.localeCompare(b.title));
    if (state.sort === 'progress-high') list = [...list].sort((a, b) => percent(b) - percent(a));
    if (state.sort === 'progress-low') list = [...list].sort((a, b) => percent(a) - percent(b));

    count.textContent = `${list.length} course${list.length === 1 ? '' : 's'}`;

    if (!list.length) {
      const empty = el('div', 'dash-empty');
      if (!courses.length) {
        empty.append(
          el('p', null, 'You have not enrolled in any courses yet.'),
          el('a', 'btn btn-primary', 'Explore courses', { href: page.dataset.exploreUrl }),
        );
      } else {
        empty.append(el('p', null, 'No courses match your search or filter.'));
      }
      grid.replaceChildren(empty);
      return;
    }
    grid.replaceChildren(...list.map(card));
  }

  async function load() {
    status.className = 'alert alert--info';
    status.textContent = 'Loading your courses…';
    status.hidden = false;
    try {
      const response = await fetch(page.dataset.dashboardEndpoint, {
        credentials: 'same-origin', headers: { Accept: 'application/json' },
      });
      if (response.status === 401) { window.location.href = page.dataset.loginUrl; return; }
      const data = await response.json().catch(() => {
        throw new Error(`The server did not return JSON (HTTP ${response.status}). Check the PHP error log.`);
      });
      if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Could not load your courses.');
      courses = Array.isArray(data.courses) ? data.courses : [];
      status.hidden = true;
      content.hidden = false;
      drawTabs();
      render();
    } catch (error) {
      content.hidden = true;
      showStatus(error.message || 'Could not load your courses.', 'danger', true);
    }
  }

  tabs.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-key]');
    if (!button) return;
    state.filter = button.dataset.key;
    tabs.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
    render();
  });
  let timer;
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { state.q = search.value.trim(); render(); }, 200);
  });
  sort.addEventListener('change', () => { state.sort = sort.value; render(); });

  load();
})();
