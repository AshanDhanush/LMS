/* Lesson viewer: media, mark as complete, previous/next, comments and the course lesson list.
   Data comes from api/student_lesson_api.php. Server text is always set with textContent. */
(() => {
  'use strict';

  const page = document.querySelector('[data-lesson-id]');
  if (!page) return;

  const endpoint = page.dataset.endpoint;
  const lessonId = page.dataset.lessonId;
  const csrf = page.dataset.csrf;
  const $ = (selector) => page.querySelector(selector);
  const status = $('[data-status]');
  const content = $('[data-content]');
  const TYPE_LABEL = { video: 'Video', pdf: 'PDF', powerpoint: 'Slides', text: 'Text' };
  const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
  let data = null;

  function el(tag, className, text, attributes) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    if (attributes) Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
  }
  const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

  function ago(iso) {
    if (!iso) return '';
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const steps = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
    for (const [unit, size] of steps) {
      if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit);
    }
    return 'just now';
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

  async function post(fields) {
    const body = new URLSearchParams({ lesson_id: lessonId, csrf_token: csrf, ...fields });
    const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Something went wrong.');
    return result;
  }

  /* ----------------------------------------------------------- Media */
  function renderMedia() {
    const { lesson } = data;
    const host = $('[data-media]');
    host.replaceChildren();
    host.hidden = false;
    const video = lesson.video;

    if (video && video.kind === 'iframe') {
      host.append(el('iframe', 'lesson-media__frame', null, {
        src: video.url, title: lesson.title, loading: 'lazy', allowfullscreen: '',
        allow: 'accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen',
        referrerpolicy: 'strict-origin-when-cross-origin',
      }));
    } else if (video && video.kind === 'video') {
      host.append(el('video', 'lesson-media__frame', null, { src: video.url, controls: '', preload: 'metadata' }));
    } else if (video) {
      const box = el('div', 'lesson-media__link');
      box.append(el('p', null, 'This video opens on another site.'),
        el('a', 'btn btn-primary', 'Watch video', { href: video.url, target: '_blank', rel: 'noopener noreferrer' }));
      host.append(box);
    } else if (lesson.pdf_url) {
      host.append(el('iframe', 'lesson-media__frame lesson-media__frame--doc', null, { src: lesson.pdf_url, title: `${lesson.title} (PDF)`, loading: 'lazy' }));
    } else {
      host.hidden = true;
    }
  }

  /* ---------------------------------------------------------- Lesson */
  function renderLesson() {
    const { lesson, course, progress, prev, next } = data;
    document.title = `${lesson.title} | Learning Hub`;

    page.querySelectorAll('[data-back]').forEach((a) => { a.href = course.url; });
    $('[data-course-title]').textContent = course.title;
    $('[data-course-sub]').textContent = `By ${course.instructor} · ${plural(progress.total, 'lesson')}`;

    renderMedia();
    $('[data-position]').textContent = `LESSON ${lesson.index} OF ${progress.total}`;
    $('[data-title]').textContent = lesson.title;

    const text = $('[data-text]');
    const paragraphs = lesson.content.split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean);
    text.replaceChildren(...paragraphs.map((p) => el('p', null, p)));

    const files = $('[data-files]');
    files.replaceChildren();
    if (lesson.pdf_url) files.append(el('a', 'btn btn-outline btn-sm', 'Open PDF', { href: lesson.pdf_url, target: '_blank', rel: 'noopener noreferrer' }));
    if (lesson.ppt_url) files.append(el('a', 'btn btn-outline btn-sm', 'Download slides', { href: lesson.ppt_url, download: '' }));

    const link = (anchor, target, label) => {
      if (target) {
        anchor.href = `${data.links.lesson}${target.id}`;
        anchor.removeAttribute('aria-disabled');
        anchor.classList.remove('is-disabled');
        anchor.replaceChildren(document.createTextNode(label));
        anchor.append(el('small', 'lesson-nav__title', `${String(target.index).padStart(2, '0')} · ${target.title}`));
      } else {
        anchor.removeAttribute('href');
        anchor.setAttribute('aria-disabled', 'true');
        anchor.classList.add('is-disabled');
        anchor.replaceChildren(document.createTextNode(label));
      }
    };
    link($('[data-prev]'), prev, '‹ Previous');
    link($('[data-next]'), next, 'Next ›');

    renderDone();
    renderSide();
  }

  function renderDone() {
    const { lesson } = data;
    const state = $('[data-state]');
    state.textContent = lesson.done ? 'Completed' : 'In progress';
    state.className = `chip ${lesson.done ? 'chip--success' : 'chip--info'}`;
    const button = $('[data-complete]');
    button.textContent = lesson.done ? '✓ Completed · Undo' : 'Mark as complete';
    button.className = `btn ${lesson.done ? 'btn-outline' : 'btn-primary'}`;
    button.setAttribute('aria-pressed', String(lesson.done));
    $('[data-complete-hint]').textContent = lesson.done ? 'Nice work. Your progress is saved.' : 'Finished learning? Save your progress.';
  }

  $('[data-complete]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const message = $('[data-lesson-message]');
    button.disabled = true;
    message.textContent = '';
    try {
      const result = await post({ action: 'complete', done: data.lesson.done ? '0' : '1' });
      data.lesson.done = result.done;
      data.progress = result.progress;
      const done = new Map(result.lessons.map((l) => [l.id, l.done]));
      data.outline.forEach((l) => { l.done = done.get(l.id) ?? l.done; });
      renderDone();
      renderSide();
      const finished = $('[data-finished]');
      finished.hidden = !result.finished;
      if (result.finished) {
        finished.textContent = 'Congratulations, you completed every lesson in this course. ';
        finished.append(el('a', null, 'View your course page', { href: data.course.url }));
      }
    } catch (error) {
      message.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });

  /* -------------------------------------------------------- Side list */
  function renderSide() {
    const { outline, progress, lesson } = data;
    $('[data-side-count]').textContent = plural(progress.total, 'lesson');
    const track = el('div', 'progress', null, {
      role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(progress.percent), 'aria-label': 'Course progress',
    });
    const fill = el('div', 'progress__bar');
    fill.style.width = `${progress.percent}%`;
    track.append(fill);
    const label = el('div', 'catalog-card__facts');
    label.append(el('span', 'text-muted', `${progress.done} of ${progress.total} completed`), el('span', 'fw-600', `${progress.percent}%`));
    $('[data-side-progress]').replaceChildren(label, track);

    const left = progress.total - progress.done;
    $('[data-side-note]').textContent = left > 0 ? `${plural(left, 'lesson')} to go.` : 'All lessons completed.';

    const list = $('[data-side-list]');
    list.replaceChildren(...outline.map((item) => {
      const current = item.id === lesson.id;
      const li = el('li');
      const a = el('a', `side-row${current ? ' is-current' : ''}`, null, { href: `${data.links.lesson}${item.id}` });
      if (current) a.setAttribute('aria-current', 'page');
      const text = el('span', 'side-row__text');
      text.append(el('strong', null, `${String(item.index).padStart(2, '0')} · ${item.title}`),
        el('span', 'text-muted', item.done ? 'Completed' : (current ? `Current lesson · ${TYPE_LABEL[item.type] || 'Lesson'}` : 'Not started')));
      a.append(el('span', `side-row__dot${item.done ? ' is-done' : ''}`, item.done ? '✓' : '', { 'aria-hidden': 'true' }), text);
      li.append(a);
      return li;
    }));
    const current = list.querySelector('.is-current');
    if (current && current.scrollIntoView && list.scrollHeight > list.clientHeight) current.scrollIntoView({ block: 'nearest' });
  }

  /* --------------------------------------------------------- Comments */
  function commentNode(comment, isReply) {
    const item = el('article', `card comment${comment.mine ? ' comment--mine' : ''}${isReply ? ' comment--reply' : ''}`);
    const head = el('div', 'comment__head');
    head.append(el('span', 'avatar-chip', comment.initials, { 'aria-hidden': 'true' }));
    const who = el('div');
    const role = comment.role === 'instructor' ? 'Instructor' : (comment.role === 'admin' ? 'Admin' : (comment.mine ? 'You' : 'Student'));
    who.append(el('strong', null, `${comment.name} · ${role}`), el('div', 'text-muted comment__sub', ago(comment.date)));
    head.append(who);
    item.append(head, el('p', null, comment.body));
    if (comment.mine) {
      const del = el('button', 'btn btn-ghost btn-sm comment__delete', 'Delete', { type: 'button', 'aria-label': 'Delete your comment' });
      del.addEventListener('click', async () => {
        if (!window.confirm('Delete this comment?')) return;
        del.disabled = true;
        try {
          data.comments = (await post({ action: 'delete_comment', comment_id: String(comment.id) })).comments;
          renderComments();
        } catch (error) {
          del.disabled = false;
          $('[data-comment-message]').textContent = error.message;
        }
      });
      item.append(del);
    }
    (comment.replies || []).forEach((reply) => item.append(commentNode(reply, true)));
    return item;
  }

  function countAll(list) { return list.reduce((n, c) => n + 1 + (c.replies ? c.replies.length : 0), 0); }

  function renderComments() {
    const list = data.comments;
    $('[data-comment-count]').textContent = plural(countAll(list), 'comment');
    $('[data-comments]').replaceChildren(...(list.length ? list.map((c) => commentNode(c, false)) : [el('p', 'text-muted', 'No comments yet. Start the conversation.')]));
  }

  const box = $('#commentText');
  const counter = $('[data-comment-counter]');
  box.addEventListener('input', () => { counter.textContent = `Be thoughtful and respectful. ${box.value.length} / 500`; });
  $('[data-post]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const message = $('[data-comment-message]');
    if (!box.value.trim()) { message.textContent = 'Write a comment first.'; return; }
    button.disabled = true;
    message.textContent = '';
    try {
      data.comments = (await post({ action: 'comment', body: box.value })).comments;
      box.value = '';
      counter.textContent = 'Be thoughtful and respectful. 0 / 500';
      renderComments();
    } catch (error) {
      message.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });

  /* ------------------------------------------------------------- Load */
  async function load() {
    try {
      const response = await fetch(`${endpoint}?id=${encodeURIComponent(lessonId)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (response.status === 401) { window.location.href = page.dataset.loginUrl; return; }
      const result = await response.json().catch(() => {
        throw new Error(`The server did not return JSON (HTTP ${response.status}). Check the PHP error log.`);
      });
      if (result.code === 'not_enrolled' && result.redirect) { window.location.href = result.redirect; return; }
      if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Could not load this lesson.');
      data = result;
      status.hidden = true;
      content.hidden = false;
      renderLesson();
      renderComments();
    } catch (error) {
      content.hidden = true;
      showStatus(error.message || 'Could not load this lesson.', 'danger', true);
    }
  }

  load();
})();
