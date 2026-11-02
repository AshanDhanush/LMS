/* Course detail page: Overview, Lessons and Reviews tabs.
   Data comes from api/student_course_api.php. Server text is always set with textContent. */
(() => {
  'use strict';

  const page = document.querySelector('[data-course-id]');
  if (!page) return;

  const endpoint = page.dataset.endpoint;
  const courseId = page.dataset.courseId;
  const csrf = page.dataset.csrf;
  const $ = (selector) => page.querySelector(selector);
  const status = $('[data-status]');
  const content = $('[data-content]');
  const TABS = ['overview', 'lessons', 'reviews'];
  const TYPE_LABEL = { video: 'Video', pdf: 'PDF', powerpoint: 'Slides', text: 'Text' };
  const dateFormat = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' });

  let data = null;
  let chosenRating = 0;

  function el(tag, className, text, attributes) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    if (attributes) Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
  }
  const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
  const when = (iso) => (iso ? dateFormat.format(new Date(iso)) : '');

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

  function stars(value, label) {
    const wrap = el('span', 'stars', null, { role: 'img', 'aria-label': label || `${value} out of 5 stars` });
    for (let i = 1; i <= 5; i += 1) {
      wrap.append(el('span', i <= Math.round(value) ? 'stars__on' : 'stars__off', i <= Math.round(value) ? '★' : '☆', { 'aria-hidden': 'true' }));
    }
    return wrap;
  }

  function bar(percent, done, label) {
    const track = el('div', 'progress', null, {
      role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(percent), 'aria-label': label,
    });
    const fill = el('div', `progress__bar${done ? ' progress__bar--done' : ''}`);
    fill.style.width = `${percent}%`;
    track.append(fill);
    return track;
  }

  /* ---------------------------------------------------------- Header */
  function renderHeader() {
    const { course, enrolled, completed, progress, reviews } = data;
    document.title = `${course.title} | Learning Hub`;

    const tags = $('[data-tags]');
    tags.replaceChildren();
    if (course.category) tags.append(el('span', 'chip chip--primary', course.category));
    tags.append(el('span', `chip ${completed ? 'chip--success' : (enrolled ? 'chip--info' : '')}`,
      completed ? 'Completed' : (enrolled ? 'In progress' : 'Not enrolled')));

    $('[data-title]').textContent = course.title;
    const summary = course.description.split(/\n\s*\n/)[0] || '';
    $('[data-summary]').textContent = summary.length > 200 ? `${summary.slice(0, 197)}…` : summary;
    $('[data-instructor]').textContent = course.instructor;
    $('[data-instructor-avatar]').textContent = course.initials;

    const line = $('[data-rating-line]');
    line.replaceChildren();
    if (reviews.average !== null) {
      line.append(stars(reviews.average), el('span', 'fw-600', reviews.average.toFixed(1)),
        el('span', 'text-muted', `(${plural(reviews.count, 'review')})`));
    } else {
      line.append(el('span', 'text-muted', 'No ratings yet'));
    }
    line.append(el('span', 'text-muted', `· ${plural(course.students, 'learner')} enrolled`));

    const art = $('[data-art]');
    art.replaceChildren();
    if (course.thumbnail_url) {
      art.append(el('img', null, null, { src: course.thumbnail_url, alt: '' }));
    } else {
      art.append(el('span', 'course-hero__letters', course.title.slice(0, 2)));
    }

    // Progress card
    const card = $('[data-progress]');
    card.replaceChildren();
    if (!enrolled) {
      const row = el('div', 'course-progress__row');
      row.append(el('div', null, null));
      row.firstChild.append(el('strong', null, 'Enrol to start learning'),
        el('p', 'text-muted', `${plural(progress.total, 'lesson')} · learn at your own pace`));
      const button = el('button', 'btn btn-primary', 'Enrol in this course', { type: 'button' });
      button.addEventListener('click', () => enrol(button));
      row.append(button);
      card.append(row);
      return;
    }
    const next = progress.next;
    const row = el('div', 'course-progress__row');
    const left = el('div', 'course-progress__main');
    left.append(el('strong', null, completed ? 'You finished this course' : (next ? `Up next: ${next.index}. ${next.title}` : 'No lessons yet')));
    const meta = el('div', 'course-progress__meta');
    meta.append(el('span', 'text-muted', `${progress.done} of ${progress.total} lessons completed`), el('span', 'fw-600', `${progress.percent}%`));
    left.append(meta, bar(progress.percent, completed, 'Course progress'));
    row.append(left);
    if (next) {
      const label = completed ? 'Review course' : (progress.done > 0 ? 'Continue learning' : 'Start learning');
      row.append(el('a', 'btn btn-primary', label, { href: `${data.links.lesson}${next.id}` }));
    }
    card.append(row);
    if (completed) {
      card.append(el('a', 'btn btn-outline btn-sm', 'View certificate', { href: data.links.certificate }));
    }
  }

  async function enrol(button) {
    button.disabled = true;
    try {
      const body = new URLSearchParams({ action: 'enroll', course_id: courseId, csrf_token: csrf });
      const response = await fetch(page.dataset.catalogEndpoint, { method: 'POST', body, credentials: 'same-origin' });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Could not enrol.');
      await load();
    } catch (error) {
      button.disabled = false;
      showStatus(error.message, 'danger');
    }
  }

  /* -------------------------------------------------------- Overview */
  function renderOverview() {
    const { course, modules, progress } = data;
    const about = $('[data-about]');
    const paragraphs = course.description.split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean);
    about.replaceChildren(...(paragraphs.length ? paragraphs.map((p) => el('p', null, p)) : [el('p', 'text-muted', 'The instructor has not added a description yet.')]));

    const titles = modules.length > 1 || (modules[0] && modules[0].id)
      ? modules.map((m) => m.title)
      : modules.flatMap((m) => m.lessons).slice(0, 6).map((l) => l.title);
    $('[data-learn]').replaceChildren(...(titles.length
      ? titles.map((t) => { const li = el('li'); li.append(el('span', 'learn-list__tick', '✓', { 'aria-hidden': 'true' }), document.createTextNode(t)); return li; })
      : [el('li', 'text-muted', 'Lessons will appear here once they are published.')]));

    const real = modules.filter((m) => m.id);
    const glance = [
      [plural(progress.total, 'lesson'), 'Video, text and PDF resources'],
      real.length ? [plural(real.length, 'module'), 'Organised in a clear order'] : null,
      ['Certificate of completion', 'Earn it after the last lesson'],
      [plural(course.students, 'learner'), 'Already enrolled'],
    ].filter(Boolean);
    $('[data-glance]').replaceChildren(...glance.map(([title, sub]) => {
      const li = el('li');
      li.append(el('strong', null, title), el('span', 'text-muted', sub));
      return li;
    }));

    $('[data-teacher-title]').textContent = `Learn with ${course.instructor}`;
    $('[data-teacher-text]').textContent = `${course.instructor} teaches this course. Ask questions in the comments under each lesson.`;
  }

  /* --------------------------------------------------------- Lessons */
  function renderLessons() {
    const { modules, enrolled, progress } = data;
    const real = modules.filter((m) => m.id).length;
    $('[data-path-meta]').textContent = `${real ? `${plural(real, 'module')} · ` : ''}${plural(progress.total, 'lesson')}`;

    const nextId = progress.next && !progress.next.done ? progress.next.id : null;
    const host = $('[data-path]');
    host.replaceChildren(...modules.map((module, mi) => {
      const done = module.lessons.filter((l) => l.done).length;
      const section = el('section', 'card path-module');
      const head = el('div', 'path-module__head');
      const titles = el('div');
      titles.append(el('h3', null, `${String(mi + 1).padStart(2, '0')} · ${module.title}`));
      if (module.description) titles.append(el('p', 'text-muted', module.description));
      head.append(titles, el('span', `chip ${done === module.lessons.length ? 'chip--success' : ''}`, `${done} of ${module.lessons.length} complete`));
      const list = el('ol', 'path-list');
      module.lessons.forEach((lesson) => {
        const li = el('li');
        const isNext = lesson.id === nextId;
        const inner = el(enrolled ? 'a' : 'div', `path-row${isNext ? ' path-row--next' : ''}`);
        if (enrolled) inner.setAttribute('href', `${data.links.lesson}${lesson.id}`);
        const text = el('span', 'path-row__text');
        text.append(el('strong', null, lesson.title),
          el('span', 'text-muted', `${TYPE_LABEL[lesson.type] || 'Lesson'} · ${lesson.done ? 'Completed' : (isNext ? 'Up next' : 'Not started')}`));
        inner.append(el('span', 'path-row__num', String(lesson.index).padStart(2, '0')), text,
          el('span', `path-row__state${lesson.done ? ' is-done' : ''}`, lesson.done ? '✓' : (isNext ? '→' : '○'),
            { 'aria-label': lesson.done ? 'Completed' : (isNext ? 'Up next' : 'Not started') }));
        li.append(inner);
        list.append(li);
      });
      section.append(head, list);
      return section;
    }));
    if (!modules.length) host.append(el('p', 'text-muted', 'No lessons have been published yet.'));

    const note = $('[data-path-note]');
    note.hidden = !enrolled && progress.total === 0;
    if (!enrolled) {
      note.textContent = 'Enrol in this course to open its lessons.';
      note.hidden = false;
    } else if (progress.total > 0 && progress.done < progress.total) {
      const left = progress.total - progress.done;
      note.textContent = `You are ${progress.percent}% of the way there. Complete the final ${plural(left, 'lesson')} to earn your certificate.`;
      note.hidden = false;
    } else {
      note.hidden = true;
    }
  }

  /* --------------------------------------------------------- Reviews */
  function reviewCard(review) {
    const card = el('article', 'card review');
    const head = el('div', 'review__head');
    head.append(el('span', 'avatar-chip', review.initials, { 'aria-hidden': 'true' }));
    const who = el('div');
    who.append(el('strong', null, review.mine ? `${review.name} · You` : review.name),
      el('div', 'text-muted review__sub', `${review.rating} / 5 stars · ${when(review.date)}`));
    head.append(who);
    card.append(head, el('p', null, review.review));
    if (review.reply) {
      const reply = el('div', 'review__reply');
      reply.append(el('strong', null, `Reply from ${data.course.instructor}`), el('p', null, review.reply));
      card.append(reply);
    }
    return card;
  }

  function renderSummary() {
    const { reviews } = data;
    const card = $('[data-summary-card]');
    card.replaceChildren(el('h3', 'card__title', 'Rated by our learners'));
    if (reviews.average === null) {
      card.append(el('p', 'text-muted', 'No ratings yet. Be the first to share your experience.'));
      return;
    }
    const big = el('div', 'rating-big');
    big.append(el('span', 'rating-big__num', reviews.average.toFixed(1)), el('span', 'text-muted', 'out of 5'));
    card.append(big, stars(reviews.average), el('p', 'text-muted', plural(reviews.count, 'review')));
    [5, 4, 3, 2, 1].forEach((n) => {
      const count = reviews.dist[n] || 0;
      const row = el('div', 'dist-row');
      row.append(el('span', null, `${n} ★`), bar(reviews.count ? Math.round(count * 100 / reviews.count) : 0, false, `${n} star reviews`), el('span', 'text-muted', String(count)));
      card.append(row);
    });
  }

  function renderForm() {
    const card = $('[data-review-form-card]');
    card.replaceChildren();
    if (!data.enrolled) {
      card.append(el('h3', 'card__title', 'Share your experience'),
        el('p', 'text-muted', 'Only enrolled students can rate and review this course.'));
      return;
    }
    chosenRating = data.mine ? data.mine.rating : 0;
    const head = el('div', 'review-form__head');
    head.append(el('h3', 'card__title', 'Share your experience'), el('span', 'chip chip--success', 'Enrolled student'));

    const group = el('div', 'star-input', null, { role: 'radiogroup', 'aria-label': 'Your rating' });
    const buttons = [1, 2, 3, 4, 5].map((n) => el('button', 'star-input__star', '★', {
      type: 'button', role: 'radio', 'aria-label': `${n} star${n === 1 ? '' : 's'}`, 'aria-checked': 'false',
    }));
    const paint = () => buttons.forEach((b, i) => {
      b.classList.toggle('is-on', i < chosenRating);
      b.setAttribute('aria-checked', String(i + 1 === chosenRating));
    });
    buttons.forEach((b, i) => b.addEventListener('click', () => { chosenRating = i + 1; paint(); }));
    group.append(...buttons);
    paint();

    const label = el('label', 'form-label', 'Your review', { for: 'reviewText' });
    const text = el('textarea', 'form-control', null, { id: 'reviewText', rows: '4', maxlength: '500', placeholder: 'What did you learn? What could be better?' });
    text.value = data.mine ? data.mine.review : '';
    const counter = el('small', 'text-muted', `${text.value.length} / 500`);
    text.addEventListener('input', () => { counter.textContent = `${text.value.length} / 500`; });
    const message = el('p', 'form-message', null, { role: 'status' });
    const submit = el('button', 'btn btn-primary', data.mine ? 'Update review' : 'Submit review', { type: 'button' });
    submit.addEventListener('click', async () => {
      if (!chosenRating) { message.textContent = 'Choose a rating from 1 to 5 stars.'; return; }
      submit.disabled = true;
      message.textContent = '';
      try {
        const body = new URLSearchParams({ action: 'review', course_id: courseId, rating: String(chosenRating), review: text.value, csrf_token: csrf });
        const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Could not save your review.');
        data.reviews = result.reviews;
        data.mine = { rating: chosenRating, review: text.value.trim() };
        renderHeader();
        renderReviews();
        $('[data-review-form-card] .form-message').textContent = result.message;
      } catch (error) {
        message.textContent = error.message;
      } finally {
        submit.disabled = false;
      }
    });
    card.append(head, el('p', 'text-muted', 'How has the course helped you so far?'), group, label, text, counter, message, submit);
  }

  function renderReviews() {
    renderSummary();
    renderForm();
    const { reviews } = data;
    const list = $('[data-review-list]');
    list.replaceChildren(...(reviews.list.length ? reviews.list.map(reviewCard) : [el('p', 'text-muted', 'No written reviews yet.')]));
    $('[data-review-count]').textContent = reviews.list.length ? `Showing ${reviews.list.length} of ${plural(reviews.count, 'review')}` : '';
    $('[data-more]').hidden = !reviews.more;
  }

  $('[data-more]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      const response = await fetch(`${endpoint}?id=${courseId}&part=reviews&page=${data.reviews.page + 1}`, { credentials: 'same-origin' });
      const result = await response.json();
      if (!response.ok || result.status !== 'ok') throw new Error(result.message);
      const more = result.reviews;
      data.reviews = { ...more, list: [...data.reviews.list, ...more.list] };
      renderReviews();
    } catch (error) {
      showStatus(error.message || 'Could not load more reviews.', 'danger');
    } finally {
      button.disabled = false;
    }
  });

  /* ------------------------------------------------------------ Tabs */
  function showTab(name, focus) {
    const tab = TABS.includes(name) ? name : 'overview';
    page.querySelectorAll('[data-tab]').forEach((button) => {
      const on = button.dataset.tab === tab;
      button.setAttribute('aria-selected', String(on));
      button.tabIndex = on ? 0 : -1;
      button.classList.toggle('is-active', on);
      if (on && focus) button.focus();
    });
    page.querySelectorAll('[data-panel]').forEach((panel) => { panel.hidden = panel.dataset.panel !== tab; });
  }
  page.querySelectorAll('[data-tab]').forEach((button) => {
    button.addEventListener('click', () => { history.replaceState(null, '', `#${button.dataset.tab}`); showTab(button.dataset.tab); });
    button.addEventListener('keydown', (event) => {
      const i = TABS.indexOf(button.dataset.tab);
      const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
      if (!step) return;
      event.preventDefault();
      const name = TABS[(i + step + TABS.length) % TABS.length];
      history.replaceState(null, '', `#${name}`);
      showTab(name, true);
    });
  });
  window.addEventListener('hashchange', () => showTab(location.hash.slice(1)));

  /* ------------------------------------------------------------ Load */
  async function load() {
    try {
      const response = await fetch(`${endpoint}?id=${encodeURIComponent(courseId)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (response.status === 401) { window.location.href = page.dataset.loginUrl; return; }
      const result = await response.json().catch(() => {
        throw new Error(`The server did not return JSON (HTTP ${response.status}). Check the PHP error log.`);
      });
      if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Could not load this course.');
      data = result;
      status.hidden = true;
      content.hidden = false;
      renderHeader();
      renderOverview();
      renderLessons();
      renderReviews();
      showTab(location.hash.slice(1));
    } catch (error) {
      content.hidden = true;
      showStatus(error.message || 'Could not load this course.', 'danger', true);
    }
  }

  load();
})();
