/* Student gradebook: assignment marks and quiz results, by course.
   Data comes from api/student_grades_api.php. Server text is always set with textContent. */
(() => {
  'use strict';

  const page = document.querySelector('[data-endpoint]');
  if (!page) return;

  const status = page.querySelector('[data-status]');
  const content = page.querySelector('[data-content]');
  const dateFormat = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  let data = null;
  let selected = 'all';

  function el(tag, className, text, attributes) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    if (attributes) Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
  }
  const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
  const pct = (value) => (value === null || value === undefined ? '—' : `${value}%`);

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

  function bar(percent, label) {
    const value = percent === null ? 0 : percent;
    const track = el('div', 'progress', null, {
      role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(value), 'aria-label': label,
    });
    const fill = el('div', 'progress__bar');
    fill.style.width = `${value}%`;
    track.append(fill);
    return track;
  }

  /* ---------------------------------------------------------- Summary */
  function stat(label, value, sub) {
    const box = el('div', 'grades-stat');
    box.append(el('span', 'text-muted', label), el('strong', 'grades-stat__value', value), el('span', 'text-muted', sub));
    return box;
  }

  function renderSummary() {
    const s = data.summary;
    $summary.replaceChildren(
      stat('Overall average', pct(s.percent), s.possible ? `${s.earned} of ${s.possible} marks` : 'Nothing marked yet'),
      stat('Assignments marked', `${s.assignments_marked} / ${s.assignments}`, 'Marked by your instructors'),
      stat('Quizzes passed', `${s.quizzes_passed} / ${s.quizzes}`, `${s.quizzes_attempted} attempted`),
      stat('Points', String(s.points), 'From lessons, quizzes and courses'),
    );
  }

  /* ----------------------------------------------------------- Courses */
  function statusChip(item) {
    const map = {
      graded: ['chip--success', 'Marked'],
      submitted: ['chip--info', 'Submitted · awaiting mark'],
      overdue: ['chip--danger', 'Overdue'],
      open: ['', 'Not submitted'],
      passed: ['chip--success', 'Passed'],
      failed: ['chip--danger', 'Not passed'],
      not_attempted: ['', item.availability === 'scheduled' ? 'Not open yet' : (item.availability === 'closed' ? 'Closed' : 'Not attempted')],
    };
    const [cls, label] = map[item.state];
    return el('span', `chip ${cls}`, label);
  }

  function row(item) {
    const tr = el('tr');
    const first = el('td', 'grades-item');
    const link = el('a', 'grades-item__title', item.title, { href: item.url });
    const sub = item.kind === 'assignment'
      ? `Assignment · Due ${dateFormat.format(new Date(item.due))}`
      : `Quiz · Pass mark ${item.pass_mark}% · ${item.attempts} of ${item.max_attempts} attempts used`;
    first.append(link, el('span', 'text-muted grades-item__sub', sub));
    if (item.feedback) {
      const details = el('details', 'grades-feedback');
      details.append(el('summary', null, 'Instructor feedback'), el('p', null, item.feedback));
      first.append(details);
    }
    const score = el('td', 'grades-num', item.score === null ? '—' : `${item.score} / ${item.total}`);
    const result = el('td', 'grades-num grades-pct', pct(item.percent));
    tr.append(first, el('td'), score, result);
    tr.children[1].append(statusChip(item));
    return tr;
  }

  function courseCard(course) {
    const s = course.summary;
    const card = el('section', 'card grades-course', null, { 'aria-labelledby': `course-${course.id}` });
    const head = el('div', 'grades-course__head');
    const title = el('h2', null, null, { id: `course-${course.id}` });
    title.append(el('a', null, course.title, { href: course.url }));
    head.append(title, el('span', `chip ${s.percent === null ? '' : (s.percent >= 50 ? 'chip--success' : 'chip--danger')}`,
      s.percent === null ? 'No marks yet' : `Average ${s.percent}%`));
    card.append(head);
    card.append(el('p', 'text-muted grades-course__meta',
      `${s.marked} of ${plural(s.total_items, 'item')} counted · ${s.assignments_marked}/${s.assignments} assignments marked · ${s.quizzes_passed}/${s.quizzes} quizzes passed`
      + (s.possible ? ` · ${s.earned} of ${s.possible} marks` : '')));
    card.append(bar(s.percent, `${course.title} average`));

    if (!course.items.length) {
      card.append(el('p', 'text-muted', 'This course has no assignments or quizzes yet.'));
      return card;
    }
    const wrap = el('div', 'grades-table-wrap');
    const table = el('table', 'grades-table');
    table.append(el('caption', 'grades-hidden', `Marks for ${course.title}`));
    const header = el('tr');
    ['Item', 'Status', 'Score', 'Result'].forEach((label, i) => header.append(el('th', i > 1 ? 'grades-num' : null, label, { scope: 'col' })));
    const thead = el('thead');
    thead.append(header);
    const tbody = el('tbody');
    course.items.forEach((item) => tbody.append(row(item)));
    table.append(thead, tbody);
    wrap.append(table);
    card.append(wrap);
    return card;
  }

  function renderCourses() {
    const list = data.courses.filter((c) => selected === 'all' || String(c.id) === selected);
    if (!data.courses.length) {
      const empty = el('div', 'dash-empty');
      empty.append(el('p', null, 'You are not enrolled in any courses yet, so there are no grades to show.'),
        el('a', 'btn btn-primary', 'Explore courses', { href: page.dataset.exploreUrl }));
      $courses.replaceChildren(empty);
      return;
    }
    $courses.replaceChildren(...list.map(courseCard));
  }

  function renderFilter() {
    $filter.hidden = data.courses.length < 2;
    const make = (id, label) => {
      const button = el('button', 'filter-chip catalog-chip', label, { type: 'button', 'data-id': id, 'aria-pressed': String(selected === id) });
      return button;
    };
    $filter.replaceChildren(make('all', 'All courses'), ...data.courses.map((c) => make(String(c.id), c.title)));
  }

  /* -------------------------------------------------------------- Load */
  const $summary = page.querySelector('[data-summary]');
  const $filter = page.querySelector('[data-filter]');
  const $courses = page.querySelector('[data-courses]');

  $filter.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-id]');
    if (!button) return;
    selected = button.dataset.id;
    $filter.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
    renderCourses();
  });

  async function load() {
    try {
      const response = await fetch(page.dataset.endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (response.status === 401) { window.location.href = page.dataset.loginUrl; return; }
      const result = await response.json().catch(() => {
        throw new Error(`The server did not return JSON (HTTP ${response.status}). Check the PHP error log.`);
      });
      if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Could not load your grades.');
      data = result;
      status.hidden = true;
      content.hidden = false;
      renderSummary();
      renderFilter();
      renderCourses();
    } catch (error) {
      content.hidden = true;
      showStatus(error.message || 'Could not load your grades.', 'danger', true);
    }
  }

  load();
})();
