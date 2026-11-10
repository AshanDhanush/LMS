/* Student course catalog: loads api/student_catalog_api.php, draws the cards,
   handles search, sort, filters, paging and enrolling.
   Every server value is inserted with textContent, never as HTML. */
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
  const page = document.querySelector('[data-catalog-endpoint]');
  if (!page) return;

  const endpoint = page.dataset.catalogEndpoint;
  const csrf = page.dataset.csrf;
  const status = page.querySelector('[data-status]');
  const content = page.querySelector('[data-content]');
  const grid = page.querySelector('[data-grid]');
  const count = page.querySelector('[data-count]');
  const pager = page.querySelector('[data-pagination]');
  const chips = page.querySelector('[data-categories]');
  const levelGroup = page.querySelector('[data-level-group]');
  const search = document.getElementById('catalogSearch');
  const sort = document.getElementById('catalogSort');
  const filtersToggle = document.getElementById('filtersToggle');
  const filtersPanel = document.getElementById('filtersPanel');
  const number = new Intl.NumberFormat();

  const state = { q: '', category: '', sort: 'recommended', level: '', lessons: '', rating: '', page: 1 };
  let requestId = 0;
  let categoriesDrawn = false;

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
      button.addEventListener('click', () => load());
      status.append(button);
    }
  }

  /* ---- Drawing ---- */
  function drawCategories(categories, allCount) {
    const make = (id, label, total) => {
      const button = el('button', 'filter-chip catalog-chip', total === null ? label : `${label} (${total})`, {
        type: 'button', 'aria-pressed': String(String(state.category) === String(id)), 'data-id': String(id),
      });
      return button;
    };
    chips.replaceChildren(
      make('', 'All courses', allCount),
      ...categories.map((c) => make(c.id, c.name, c.courses)),
    );
  }

  function markCategory() {
    chips.querySelectorAll('button').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.id === String(state.category)));
    });
  }

  function stars(rating) {
    const wrap = el('span', 'catalog-rating');
    if (rating === null) {
      wrap.append(el('span', 'text-faint', 'No ratings yet'));
      return wrap;
    }
    wrap.append(
      el('span', 'catalog-rating__star', '★', { 'aria-hidden': 'true' }),
      el('span', 'fw-600', rating.toFixed(1)),
    );
    return wrap;
  }

  function levelLabel(level) {
    return level ? level.charAt(0).toUpperCase() + level.slice(1) : '';
  }

  async function enroll(course, button) {
    button.disabled = true;
    button.textContent = 'Enrolling…';
    try {
      const body = new URLSearchParams({ action: 'enroll', course_id: String(course.id), csrf_token: csrf });
      const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Could not enrol.');
      course.enrolled = true;
      button.replaceWith(el('a', 'btn btn-outline btn-sm', 'Go to course', { href: course.url }));
    } catch (error) {
      button.disabled = false;
      button.textContent = 'Enrol';
      showStatus(error.message, 'danger');
    }
  }

  function card(course) {
    const article = el('article', 'course-card catalog-card');

    const thumb = el('div', 'course-card__thumb');
    if (course.thumbnail_url) {
      thumb.append(el('img', null, null, { src: course.thumbnail_url, alt: '', loading: 'lazy' }));
    } else {
      thumb.textContent = '📚';
      thumb.setAttribute('aria-hidden', 'true');
    }

    const body = el('div', 'course-card__body');
    const tag = [course.category, levelLabel(course.level)].filter(Boolean).join(' · ');
    if (tag) body.append(el('div', 'course-card__category', tag));
    body.append(
      el('h3', 'course-card__title', course.title),
      el('div', 'course-card__meta', `By ${course.instructor}`),
    );

    const facts = el('div', 'catalog-card__facts');
    facts.append(
      stars(course.rating),
      el('span', 'text-muted', `${number.format(course.lessons)} lesson${course.lessons === 1 ? '' : 's'}`),
      el('span', 'text-muted', `${number.format(course.students)} student${course.students === 1 ? '' : 's'}`),
    );
    body.append(facts);

    const footer = el('div', 'course-card__footer catalog-card__footer');
    if (course.enrolled) {
      footer.append(el('span', 'chip chip--success', 'Enrolled'), el('a', 'btn btn-outline btn-sm', 'Go to course', { href: course.url }));
    } else {
      const button = el('button', 'btn btn-primary btn-sm', 'Enrol', {
        type: 'button', 'aria-label': `Enrol in ${course.title}`,
      });
      button.addEventListener('click', () => enroll(course, button));
      footer.append(button);
    }
    body.append(footer);

    article.append(thumb, body);
    return article;
  }

  function drawPager(current, pages) {
    pager.replaceChildren();
    if (pages <= 1) return;

    const item = (label, target, options = {}) => {
      const li = el('li');
      if (options.disabled) {
        li.append(el('span', 'disabled', label));
      } else if (options.current) {
        li.append(el('span', 'current', label, { 'aria-current': 'page' }));
      } else {
        const link = el('a', null, label, { href: '#', 'aria-label': options.label || `Page ${label}` });
        link.addEventListener('click', (event) => {
          event.preventDefault();
          state.page = target;
          load();
          page.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        li.append(link);
      }
      return li;
    };

    pager.append(item('‹', current - 1, { disabled: current === 1, label: 'Previous page' }));
    for (let p = 1; p <= pages; p += 1) {
      pager.append(item(String(p), p, { current: p === current }));
    }
    pager.append(item('›', current + 1, { disabled: current === pages, label: 'Next page' }));
  }

  function render(data) {
    if (!categoriesDrawn) {
      drawCategories(data.categories, data.all_count);
      categoriesDrawn = true;
    }
    levelGroup.hidden = !data.has_level;
    status.hidden = true;
    content.hidden = false;

    const from = data.total === 0 ? 0 : (data.page - 1) * data.per_page + 1;
    const to = Math.min(data.total, data.page * data.per_page);
    count.textContent = `${number.format(data.total)} course${data.total === 1 ? '' : 's'} to explore`
      + (data.total ? ` · Showing ${from}–${to}` : '');

    if (!data.courses.length) {
      const empty = el('div', 'dash-empty');
      empty.append(el('p', null, 'No courses match your search. Try different words or reset the filters.'));
      grid.replaceChildren(empty);
    } else {
      grid.replaceChildren(...data.courses.map(card));
    }
    state.page = data.page;
    drawPager(data.page, data.pages);
  }

  async function load() {
    const id = ++requestId;
    const params = new URLSearchParams();
    Object.entries(state).forEach(([key, value]) => { if (value !== '' && value !== 1) params.set(key, String(value)); });
    if (state.page > 1) params.set('page', String(state.page));

    grid.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(`${endpoint}?${params}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (response.status === 401) { window.location.href = page.dataset.loginUrl; return; }
      const data = await response.json().catch(() => { throw new Error(`The server did not return JSON (HTTP ${response.status}). Check the PHP error log.`); });
      if (id !== requestId) return; // a newer request is on its way
      if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Could not load courses.');
      render(data);
    } catch (error) {
      if (id === requestId) showStatus(error.message || 'Could not load courses.', 'danger', true);
    } finally {
      if (id === requestId) grid.removeAttribute('aria-busy');
    }
  }

  /* ---- Controls ---- */
  let timer;
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { state.q = search.value.trim(); state.page = 1; load(); }, 300);
  });

  sort.addEventListener('change', () => { state.sort = sort.value; state.page = 1; load(); });

  chips.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-id]');
    if (!button) return;
    state.category = button.dataset.id;
    state.page = 1;
    markCategory();
    load();
  });

  filtersPanel.addEventListener('change', (event) => {
    const input = event.target;
    if (input.name in state) { state[input.name] = input.value; state.page = 1; load(); }
  });

  document.getElementById('resetFilters').addEventListener('click', () => {
    state.level = state.lessons = state.rating = '';
    state.page = 1;
    filtersPanel.querySelectorAll('input[type="radio"][value=""]').forEach((input) => { input.checked = true; });
    load();
  });

  filtersToggle.addEventListener('click', () => {
    const open = !filtersPanel.classList.contains('is-open');
    filtersPanel.classList.toggle('is-open', open);
    filtersToggle.setAttribute('aria-expanded', String(open));
  });

  load();
})();

/* Back/forward cache guard. Some browsers keep a finished page in memory and show it again when the person
   presses Back, even after they signed out. When that happens, reload so the server decides whether they may
   still see the page (a signed-out visitor is sent to the sign-in page). */
window.addEventListener('pageshow', (event) => {
  if (event.persisted) window.location.reload();
});
