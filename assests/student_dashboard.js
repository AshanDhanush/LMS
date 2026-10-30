/* Student dashboard: loads api/student_dashboard_api.php and draws the page.
   Every value from the server is put in with textContent, never as HTML. */
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

  const endpoint = page.dataset.dashboardEndpoint;
  const status = page.querySelector('[data-status]');
  const content = page.querySelector('[data-content]');
  const numberFormat = new Intl.NumberFormat();
  const dayFormat = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' });
  const dayTimeFormat = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });

  /** Small helper: el('div', 'class names', 'text', { href: '...' }) */
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

  function progressBar(percent, done) {
    const track = el('div', 'progress', null, {
      role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(percent),
    });
    const bar = el('div', `progress__bar${done ? ' progress__bar--done' : ''}`);
    bar.style.width = `${Math.max(0, Math.min(100, percent))}%`;
    track.append(bar);
    return track;
  }

  function progressLabel(left, right) {
    const row = el('div', 'progress-label');
    row.append(el('span', null, left), el('span', 'tabular', right));
    return row;
  }

  function emptyMessage(container, message) {
    container.replaceChildren(el('p', 'dash-empty', message));
  }

  function greetingFor(name) {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
    const first = String(name || '').trim().split(/\s+/u)[0] || '';
    return first ? `${part}, ${first}` : part;
  }

  /* ---- Pieces ---- */
  function renderGreeting(student) {
    page.querySelector('[data-greeting]').textContent = greetingFor(student.name);
    page.querySelector('[data-points]').textContent = `⭐ ${numberFormat.format(student.points)} points`;
    page.querySelector('[data-badge-count]').textContent =
      `🏅 ${numberFormat.format(student.badge_count)} badge${student.badge_count === 1 ? '' : 's'}`;
  }

  function renderContinue(next) {
    const section = page.querySelector('[data-continue]');

    if (!next) {
      const card = el('div', 'continue-card');
      const main = el('div', 'continue-card__main');
      main.append(
        el('p', 'continue-card__eyebrow', 'Continue learning'),
        el('p', 'continue-card__lesson', 'Nothing in progress right now'),
        el('p', 'text-muted', 'Enrol in a course to start learning, or finish the lessons you have already opened.'),
      );
      card.append(main);
      section.replaceChildren(card);
      return;
    }

    const card = el('div', 'continue-card');
    const main = el('div', 'continue-card__main');
    const title = el('p', 'continue-card__lesson', next.lesson_title, { id: 'continue-title' });
    const progress = el('div', 'continue-card__progress');
    progress.append(
      progressLabel(`${next.lessons_done} of ${next.lessons_total} lessons`, `${next.progress}%`),
      progressBar(next.progress, false),
    );
    main.append(el('p', 'continue-card__eyebrow', 'Continue learning'), title, el('p', 'continue-card__course', next.course_title), progress);

    card.append(main);
    if (next.url) {
      card.append(el('a', 'btn btn-primary btn-lg', next.started ? 'Continue' : 'Start learning', { href: next.url }));
    }
    section.replaceChildren(card);
  }

  function renderStats(stats) {
    const items = [
      { value: stats.enrolled, label: 'Courses enrolled', href: '#my-courses' },
      { value: stats.completed, label: 'Courses completed', href: '#my-courses' },
      {
        value: stats.average_quiz_score === null ? '–' : Math.round(stats.average_quiz_score),
        unit: stats.average_quiz_score === null ? '' : '%',
        label: 'Average quiz score',
      },
      { value: stats.pending_assignments, label: 'Pending assignments', href: '#deadlines' },
    ];

    page.querySelector('[data-stats]').replaceChildren(...items.map((item) => {
      const card = el(item.href ? 'a' : 'div', 'stat-card', null, item.href ? { href: item.href } : undefined);
      const value = el('div', 'stat-card__value', String(item.value));
      if (item.unit) value.append(el('small', null, item.unit));
      card.append(value, el('div', 'stat-card__label', item.label));
      return card;
    }));
  }

  function renderCourses(courses) {
    const grid = page.querySelector('[data-courses]');
    if (!courses.length) {
      grid.className = '';
      emptyMessage(grid, 'You are not enrolled in any course yet.');
      return;
    }
    grid.className = 'grid grid--auto';

    grid.replaceChildren(...courses.map((course) => {
      const card = el(course.url ? 'a' : 'div', 'course-card', null, course.url ? { href: course.url } : undefined);

      const thumb = el('div', 'course-card__thumb');
      if (course.thumbnail_url) {
        thumb.append(el('img', null, null, { src: course.thumbnail_url, alt: '', loading: 'lazy' }));
      } else {
        thumb.textContent = '📚';
        thumb.setAttribute('aria-hidden', 'true');
      }

      const body = el('div', 'course-card__body');
      if (course.category) body.append(el('div', 'course-card__category', course.category));
      body.append(el('h3', 'course-card__title', course.title));
      if (course.instructor) body.append(el('div', 'course-card__meta', course.instructor));
      body.append(
        progressLabel(`${course.lessons_done} of ${course.lessons_total} lessons`, `${course.progress}%`),
        progressBar(course.progress, course.completed),
      );

      const footer = el('div', 'course-card__footer');
      footer.append(
        course.completed
          ? el('span', 'chip chip--success', 'Completed')
          : el('span', 'chip chip--primary', course.lessons_done > 0 ? 'In progress' : 'Not started'),
      );
      body.append(footer);

      card.append(thumb, body);
      return card;
    }));
  }

  function dueText(seconds) {
    const abs = Math.abs(seconds);
    const hours = Math.round(abs / 3600);
    const days = Math.round(abs / 86400);
    let amount;
    if (abs < 3600) amount = 'less than an hour';
    else if (hours < 48) amount = `${hours} hour${hours === 1 ? '' : 's'}`;
    else amount = `${days} days`;
    return seconds < 0 ? `Overdue by ${amount}` : `Due in ${amount}`;
  }

  function renderDeadlines(deadlines) {
    const box = page.querySelector('[data-deadlines]');
    if (!deadlines.length) {
      emptyMessage(box, 'No deadlines coming up. You are all caught up.');
      return;
    }

    const list = el('ul', 'list dash-list');
    deadlines.forEach((item) => {
      const row = el(item.url ? 'a' : 'div', 'list__item', null, item.url ? { href: item.url } : undefined);
      const main = el('div', 'list__main');
      main.append(
        el('span', 'list__title', item.title),
        el('span', 'list__sub', `${item.course} · ${dayTimeFormat.format(new Date(item.due_at))}`),
      );

      const state = item.seconds_left < 0 ? ' deadline--overdue' : item.seconds_left < 172800 ? ' deadline--soon' : '';
      row.append(main, el('span', `deadline${state}`, dueText(item.seconds_left)));

      const li = el('li');
      li.append(row);
      list.append(li);
    });
    box.replaceChildren(list);
  }

  function renderGrades(grades) {
    const box = page.querySelector('[data-grades]');
    if (!grades.length) {
      emptyMessage(box, 'Your grades will appear here once an assignment is marked or you take a quiz.');
      return;
    }

    const list = el('ul', 'list dash-list');
    grades.forEach((grade) => {
      const percent = grade.percent === null ? null : Math.round(grade.percent);
      const tone = percent === null ? '' : percent >= 70 ? ' chip--success' : percent >= 50 ? ' chip--warning' : ' chip--danger';

      const main = el('div', 'list__main');
      main.append(
        el('span', 'list__title', grade.title),
        el('span', 'list__sub',
          `${grade.kind === 'quiz' ? 'Quiz' : 'Assignment'} · ${grade.course} · ${dayFormat.format(new Date(grade.at))}`),
      );

      const result = el('div');
      result.append(
        el('span', `chip${tone}`, percent === null ? `${grade.score}` : `${percent}%`),
      );
      result.append(el('div', 'list__sub text-right tabular', `${grade.score} / ${grade.total}`));

      const li = el('li', 'list__item');
      li.append(main, result);
      list.append(li);
    });
    box.replaceChildren(list);
  }

  function renderBadges(badges) {
    const box = page.querySelector('[data-badges]');
    if (!badges.length) {
      emptyMessage(box, 'No badges yet. Complete a lesson to earn your first one.');
      return;
    }

    const grid = el('div', 'badge-grid');
    badges.forEach((badge) => {
      const tile = el('div', 'badge-tile');
      tile.append(
        el('div', 'badge-tile__icon', badge.icon, { 'aria-hidden': 'true' }),
        el('div', 'badge-tile__name', badge.name),
        el('div', 'badge-tile__hint', badge.hint),
      );
      grid.append(tile);
    });
    box.replaceChildren(grid);
  }

  function render(data) {
    renderGreeting(data.student);
    renderContinue(data.continue);
    renderStats(data.stats);
    renderCourses(data.courses);
    renderDeadlines(data.deadlines);
    renderGrades(data.grades);
    renderBadges(data.badges);
    status.hidden = true;
    content.hidden = false;
  }

  /* ---- Load ---- */
  async function load() {
    showStatus('Loading your dashboard…', 'info');
    content.hidden = true;

    try {
      const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });

      if (response.status === 401) {
        window.location.assign(page.dataset.loginUrl || '/');
        return;
      }

      const data = await response.json().catch(() => null);
      if (!response.ok || !data || data.status !== 'ok') {
        showStatus((data && data.message) || 'Your dashboard could not be loaded.', 'danger', true);
        return;
      }
      render(data);
    } catch (error) {
      showStatus('Your dashboard could not be loaded. Check your connection.', 'danger', true);
    }
  }

  load();
})();
