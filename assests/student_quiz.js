/* Student quiz: intro -> questions (one at a time) -> result with answer review.
   Data comes from api/student_quiz_api.php. The correct answers only arrive after a submission.
   Server text is always set with textContent. */
(() => {
  'use strict';

  const page = document.querySelector('[data-quiz-id]');
  if (!page) return;

  const endpoint = page.dataset.endpoint;
  const quizId = page.dataset.quizId;
  const csrf = page.dataset.csrf;
  const firstName = (page.dataset.student || '').trim().split(/\s+/)[0] || 'there';
  const $ = (selector) => page.querySelector(selector);
  const status = $('[data-status]');
  const content = $('[data-content]');
  const views = { intro: $('[data-view="intro"]'), question: $('[data-view="question"]'), result: $('[data-view="result"]') };
  const LETTERS = 'ABCDEFGH';
  const dateFormat = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

  let quiz = null;
  let questions = [];
  let answers = {};
  let current = 0;
  let attemptNumber = 1;
  let inProgress = false;

  function el(tag, className, text, attributes) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    if (attributes) Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
  }
  const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

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

  function show(name) {
    Object.entries(views).forEach(([key, node]) => { node.hidden = key !== name; });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  async function request(method, fields) {
    const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    let target = endpoint;
    if (method === 'POST') options.body = new URLSearchParams({ quiz_id: quizId, csrf_token: csrf, ...fields });
    else target = `${endpoint}?id=${encodeURIComponent(quizId)}`;
    const response = await fetch(target, options);
    if (response.status === 401) { window.location.href = page.dataset.loginUrl; throw new Error('Please sign in again.'); }
    const result = await response.json().catch(() => {
      throw new Error(`The server did not return JSON (HTTP ${response.status}). Check the PHP error log.`);
    });
    if (result.code === 'not_enrolled' && result.redirect) { window.location.href = result.redirect; throw new Error(result.message); }
    if (!response.ok || result.status !== 'ok') {
      const error = new Error(result.message || 'Something went wrong.');
      error.quiz = result.quiz;
      throw error;
    }
    return result;
  }

  /* ----------------------------------------------------------- Intro */
  function renderIntro() {
    document.title = `${quiz.title} | Learning Hub`;
    page.querySelectorAll('[data-back]').forEach((a) => { a.href = quiz.course.url; });
    $('[data-course-title]').textContent = quiz.course.title;
    $('[data-course-sub]').textContent = `By ${quiz.course.instructor}`;
    $('[data-title]').textContent = quiz.title;
    $('[data-description]').textContent = quiz.description;

    const stat = (label, value, sub) => {
      const box = el('div', 'quiz-stat');
      box.append(el('span', 'text-muted', label), el('strong', 'quiz-stat__value', value), el('span', 'text-muted', sub));
      return box;
    };
    const kinds = quiz.essay_count ? `${quiz.mcq_count} single answer · ${quiz.essay_count} written` : 'Single answer';
    $('[data-stats]').replaceChildren(
      stat('Questions', String(quiz.question_count), kinds),
      stat('Pass mark', `${quiz.pass_mark}%`, quiz.total_marks ? `${quiz.marks_to_pass} of ${quiz.total_marks} marks to pass` : 'No marked questions'),
      stat('Attempts left', String(quiz.attempts_left), quiz.attempts_used ? `${quiz.attempts_used} of ${quiz.max_attempts} used` : 'Before you start'),
    );

    const rules = [
      'Choose one answer for each question. There is no time limit.',
      'Use Previous to revisit answers before you submit.',
      `Submit after all ${quiz.question_count} questions to see your score and answer review.`,
    ];
    if (quiz.essay_count) rules.push('Written questions are saved with your attempt but do not count towards your score.');
    if (quiz.end_time) rules.push(`This quiz closes on ${dateFormat.format(new Date(quiz.end_time))}.`);
    $('[data-rules]').replaceChildren(...rules.map((r) => el('li', null, r)));

    const reward = $('[data-reward]');
    reward.replaceChildren(el('strong', null, 'A little reward for your progress'),
      el('p', null, `Earn ${quiz.points_reward} points for passing. Below ${quiz.pass_mark}% earns 0 points. Each submission uses one attempt.`));

    const blocked = $('[data-blocked]');
    blocked.hidden = quiz.can_start;
    blocked.textContent = quiz.reason || '';

    const best = $('[data-best]');
    if (quiz.best) {
      best.hidden = false;
      best.replaceChildren(el('span', 'text-muted', 'Your best score'), el('strong', null, `${quiz.best.percent}%`),
        el('span', `chip ${quiz.best.passed ? 'chip--success' : 'chip--danger'}`, quiz.best.passed ? 'Passed' : 'Not passed'));
    } else {
      best.hidden = true;
    }

    const start = $('[data-start]');
    start.disabled = !quiz.can_start;
    start.textContent = quiz.attempts_used ? 'Start new attempt' : 'Start quiz';

    const history = $('[data-history]');
    history.hidden = !quiz.attempts.length;
    $('[data-history-list]').replaceChildren(...quiz.attempts.map((a) => {
      const li = el('li');
      li.append(el('span', 'fw-600', `Attempt ${a.number}`), el('span', null, `${a.score} / ${a.total} · ${a.percent}%`),
        el('span', `chip ${a.passed ? 'chip--success' : 'chip--danger'}`, a.passed ? 'Passed' : 'Not passed'),
        el('span', 'text-muted', dateFormat.format(new Date(a.date))));
      return li;
    }));
  }

  async function begin(button, messageBox) {
    button.disabled = true;
    messageBox.textContent = '';
    try {
      const result = await request('POST', { action: 'start' });
      questions = result.questions;
      attemptNumber = result.attempt_number;
      answers = {};
      current = 0;
      inProgress = true;
      $('[data-flow-title]').textContent = quiz.title;
      renderQuestion(true);
      show('question');
    } catch (error) {
      messageBox.textContent = error.message;
      if (error.quiz) { quiz = error.quiz; renderIntro(); show('intro'); }
    } finally {
      button.disabled = false;
    }
  }
  $('[data-start]').addEventListener('click', (event) => begin(event.currentTarget, $('[data-intro-message]')));

  /* -------------------------------------------------------- Questions */
  const isAnswered = (q) => {
    const value = answers[q.id];
    return q.type === 'mcq' ? Number.isInteger(value) : typeof value === 'string' && value.trim() !== '';
  };
  const unansweredRequired = () => questions.filter((q) => q.type === 'mcq' && !isAnswered(q));

  function updateProgress() {
    const total = questions.length;
    const done = questions.filter(isAnswered).length;
    $('[data-q-answered]').textContent = `${done} answered`;
    const track = $('[data-q-progress]');
    const percent = Math.round(done * 100 / total);
    track.setAttribute('aria-valuenow', String(percent));
    track.firstElementChild.style.width = `${percent}%`;
    track.firstElementChild.classList.toggle('progress__bar--done', done === total);

    const missing = unansweredRequired().length;
    const note = $('[data-q-note]');
    const last = current === total - 1;
    note.replaceChildren();
    if (missing === 0) {
      note.append(el('strong', null, `All ${total} questions are answered`),
        el('p', null, `${last ? 'This is the final question. ' : ''}Go back to check an answer, or submit to see your result. Submitting uses one attempt.`));
    } else {
      note.append(el('strong', null, `${plural(missing, 'question')} still need${missing === 1 ? 's' : ''} an answer`),
        el('p', null, 'Use Previous and Next to move between questions. You can submit once every single-answer question is answered.'));
    }
    $('[data-submit]').disabled = missing > 0;
  }

  function renderQuestion(focus) {
    const q = questions[current];
    $('[data-q-position]').textContent = `Question ${current + 1} of ${questions.length}`;
    $('[data-q-attempt]').textContent = `Attempt ${attemptNumber} of ${quiz.max_attempts} · Pass mark ${quiz.pass_mark}%`;

    const body = $('[data-q-body]');
    const legend = el('legend', 'quiz-question__text', q.text, { tabindex: '-1' });
    const hint = el('p', 'text-muted', q.type === 'mcq' ? 'Choose one answer.' : 'Write your answer. It is saved for review and does not count towards your score.');
    const parts = [legend, hint];

    if (q.type === 'mcq') {
      q.options.forEach((option, i) => {
        const label = el('label', 'quiz-option');
        const input = el('input', null, null, { type: 'radio', name: 'answer', value: String(i) });
        input.checked = answers[q.id] === i;
        const text = el('span', 'quiz-option__text');
        const state = el('small', 'text-muted', input.checked ? 'Selected' : 'Not selected');
        text.append(el('strong', null, `${LETTERS[i]}. ${option}`), state);
        label.append(input, el('span', 'quiz-option__mark', null, { 'aria-hidden': 'true' }), text);
        input.addEventListener('change', () => {
          answers[q.id] = i;
          body.querySelectorAll('.quiz-option').forEach((node, index) => {
            node.querySelector('small').textContent = index === i ? 'Selected' : 'Not selected';
            node.classList.toggle('is-selected', index === i);
          });
          updateProgress();
        });
        label.classList.toggle('is-selected', input.checked);
        parts.push(label);
      });
    } else {
      const area = el('textarea', 'form-control', null, { rows: '6', maxlength: '5000', 'aria-label': 'Your written answer' });
      area.value = typeof answers[q.id] === 'string' ? answers[q.id] : '';
      area.addEventListener('input', () => { answers[q.id] = area.value; updateProgress(); });
      parts.push(area);
    }
    body.replaceChildren(...parts);

    $('[data-prev]').disabled = current === 0;
    $('[data-next]').disabled = current === questions.length - 1;
    $('[data-q-message]').textContent = '';
    updateProgress();
    if (focus) legend.focus({ preventScroll: true });
  }

  $('[data-prev]').addEventListener('click', () => { if (current > 0) { current -= 1; renderQuestion(true); } });
  $('[data-next]').addEventListener('click', () => { if (current < questions.length - 1) { current += 1; renderQuestion(true); } });

  window.addEventListener('beforeunload', (event) => {
    if (inProgress) { event.preventDefault(); event.returnValue = ''; }
  });

  $('[data-submit]').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const message = $('[data-q-message]');
    if (unansweredRequired().length) { message.textContent = 'Answer every single-answer question before submitting.'; return; }
    if (!window.confirm('Submit your answers? This uses one attempt.')) return;
    button.disabled = true;
    message.textContent = '';
    try {
      const result = await request('POST', { action: 'submit', answers: JSON.stringify(answers) });
      inProgress = false;
      quiz = result.quiz;
      renderResult(result.result);
      renderIntro();
      show('result');
      $('[data-result-title]').focus({ preventScroll: true });
    } catch (error) {
      message.textContent = error.message;
      if (error.quiz) quiz = error.quiz;
    } finally {
      button.disabled = false;
    }
  });

  /* ----------------------------------------------------------- Result */
  function ring(percent, passed) {
    const NS = 'http://www.w3.org/2000/svg';
    const radius = 42;
    const length = 2 * Math.PI * radius;
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 100 100');
    svg.setAttribute('class', `quiz-ring__svg ${passed ? 'is-pass' : 'is-fail'}`);
    svg.setAttribute('aria-hidden', 'true');
    const track = document.createElementNS(NS, 'circle');
    track.setAttribute('class', 'quiz-ring__track');
    const bar = document.createElementNS(NS, 'circle');
    bar.setAttribute('class', 'quiz-ring__bar');
    [track, bar].forEach((c) => { c.setAttribute('cx', '50'); c.setAttribute('cy', '50'); c.setAttribute('r', String(radius)); });
    bar.setAttribute('stroke-dasharray', `${(length * percent) / 100} ${length}`);
    bar.setAttribute('transform', 'rotate(-90 50 50)');
    svg.append(track, bar);
    return svg;
  }

  function renderResult(r) {
    const passed = r.passed;
    $('[data-result-quiz]').textContent = quiz.title;
    const host = $('[data-ring]');
    const label = el('div', 'quiz-ring__label');
    label.append(el('strong', null, `${r.percent}%`), el('span', 'text-muted', 'SCORE'));
    host.replaceChildren(ring(r.percent, passed), label);
    host.setAttribute('role', 'img');
    host.setAttribute('aria-label', `Score ${r.percent} percent`);

    $('[data-result-attempt]').textContent = `ATTEMPT ${r.attempt_number} OF ${quiz.max_attempts} · COMPLETED`;
    $('[data-result-title]').textContent = passed ? `Quiz passed. Well done, ${firstName}!` : `Not passed — keep going, ${firstName}.`;
    $('[data-result-text]').textContent = passed
      ? 'Review the questions you missed to keep growing.'
      : 'Review the answers below, revisit the lessons and try again.';

    const banner = $('[data-result-banner]');
    banner.className = `alert ${passed ? 'alert--success' : 'alert--danger'}`;
    banner.replaceChildren(el('strong', null, passed ? 'Passed · You reached the pass mark' : 'Not passed · Below the pass mark'),
      el('p', null, `${r.percent}% scored · ${quiz.pass_mark}% required${r.total ? ` · ${r.score} of ${r.total} marks` : ''}`));

    const chips = $('[data-result-chips]');
    chips.replaceChildren();
    if (r.points > 0) chips.append(el('span', 'chip chip--points chip--lg', `+${r.points} points earned`));
    chips.append(el('span', 'chip chip--info chip--lg', plural(quiz.attempts_left, 'attempt') + ' left'));

    $('[data-result-reward]').textContent = passed
      ? (r.points > 0 ? `Earned ${r.points} points for passing.` : 'You have already earned the points for passing this quiz.')
      : `Pass with ${quiz.pass_mark}% or more to earn ${quiz.points_reward} points.`;

    const retry = $('[data-retry]');
    retry.hidden = !quiz.can_start;

    const wrong = r.graded - r.correct;
    const written = r.review.length - r.graded;
    $('[data-review-summary]').textContent = `${r.correct} correct · ${wrong} incorrect · ${plural(r.review.length, 'question')}`
      + (written ? ` (${written} written, not marked)` : '');

    $('[data-review]').replaceChildren(...r.review.map((item) => reviewCard(item, r.review.length)));
  }

  function reviewCard(item, total) {
    const card = el('article', 'card quiz-review');
    const head = el('div', 'quiz-review__head');
    head.append(el('span', 'text-muted', `Question ${item.number} of ${total}`));
    if (item.is_correct !== null) {
      head.append(el('span', `chip ${item.is_correct ? 'chip--success' : 'chip--danger'}`, item.is_correct ? '✓ Correct' : '✕ Incorrect'));
    } else {
      head.append(el('span', 'chip', 'Written answer'));
    }
    card.append(head, el('h3', 'quiz-review__text', item.text));

    const grid = el('div', 'quiz-review__grid');
    const box = (title, value, kind) => {
      const node = el('div', `quiz-answer ${kind}`);
      node.append(el('small', 'text-muted', title), el('strong', null, value));
      return node;
    };
    if (item.type === 'mcq') {
      const own = item.chosen === null ? 'No answer' : `${LETTERS[item.chosen]}. ${item.options[item.chosen]}`;
      grid.append(box('Your answer', own, item.is_correct ? 'is-right' : 'is-wrong'),
        box('Correct answer', `${LETTERS[item.correct]}. ${item.options[item.correct]}`, 'is-neutral'));
    } else {
      grid.append(box('Your answer', item.answer || 'No answer', 'is-neutral'));
      if (item.model_answer) grid.append(box('Model answer', item.model_answer, 'is-neutral'));
    }
    card.append(grid);
    return card;
  }

  $('[data-retry]').addEventListener('click', (event) => {
    // A failed retry is shown in the page banner at the top.
    const sink = { set textContent(message) { if (message) { showStatus(message, 'danger'); window.scrollTo({ top: 0 }); } } };
    begin(event.currentTarget, sink);
  });

  /* ------------------------------------------------------------- Load */
  async function load() {
    try {
      const result = await request('GET');
      quiz = result.quiz;
      status.hidden = true;
      content.hidden = false;
      renderIntro();
      show('intro');
    } catch (error) {
      content.hidden = true;
      showStatus(error.message || 'Could not load this quiz.', 'danger', true);
    }
  }

  load();
})();
