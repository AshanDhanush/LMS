/* Student assignment page: instructions, PDF download, upload your answers, see your mark.
   Data comes from api/student_assignment_api.php. Server text is always set with textContent. */
(() => {
  'use strict';

  const page = document.querySelector('[data-assignment-id]');
  if (!page) return;

  const endpoint = page.dataset.endpoint;
  const assignmentId = page.dataset.assignmentId;
  const csrf = page.dataset.csrf;
  const $ = (selector) => page.querySelector(selector);
  const status = $('[data-status]');
  const content = $('[data-content]');
  const dateFormat = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

  let data = null;
  let chosen = null;
  let busy = false;

  function el(tag, className, text, attributes) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    if (attributes) Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
  }
  const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
  const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

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

  function timeLeft(iso) {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const steps = [['day', 86400], ['hour', 3600], ['minute', 60]];
    for (const [unit, length] of steps) {
      if (Math.abs(seconds) >= length) return relative.format(Math.round(seconds / length), unit);
    }
    return 'now';
  }

  /* ---------------------------------------------------------- Details */
  function renderDetails() {
    const a = data.assignment;
    document.title = `${a.title} | Learning Hub`;
    page.querySelectorAll('[data-back]').forEach((link) => { link.href = a.course.url; });
    $('[data-course-title]').textContent = a.course.title;
    $('[data-course-sub]').textContent = `By ${a.course.instructor}`;
    $('[data-title]').textContent = a.title;
    $('[data-due]').textContent = dateFormat.format(new Date(a.due));
    $('[data-max]').textContent = plural(a.max_mark, 'mark');

    const note = $('[data-due-note]');
    note.textContent = a.state === 'graded' || a.state === 'submitted' ? '' : (a.overdue ? `Overdue (${timeLeft(a.due)})` : `Due ${timeLeft(a.due)}`);

    const chip = $('[data-state]');
    const label = {
      graded: ['chip--success', `Graded · ${a.submission ? a.submission.mark : ''} / ${a.max_mark}`],
      submitted: ['chip--info', 'Submitted · awaiting mark'],
      overdue: ['chip--danger', 'Overdue'],
      open: ['', 'Not submitted'],
    }[a.state];
    chip.className = `chip ${label[0]}`;
    chip.textContent = label[1];

    const text = $('[data-description]');
    const paragraphs = a.description.split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean);
    text.replaceChildren(...(paragraphs.length ? paragraphs.map((p) => el('p', null, p)) : [el('p', 'text-muted', 'Your instructor has not added written instructions.')]));

    const brief = $('[data-brief]');
    brief.hidden = !a.file;
    if (a.file) {
      $('[data-brief-ext]').textContent = (a.file.ext || 'file').toUpperCase();
      $('[data-brief-name]').textContent = a.file.name;
      $('[data-brief-link]').href = a.file.url;
      $('[data-brief-link]').setAttribute('aria-label', `Download ${a.file.name}`);
    }
  }

  /* ------------------------------------------------------- Submission */
  function fileProblem(file) {
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (!data.assignment.limits.types.includes(ext)) return 'Only PDF, DOC or DOCX files are allowed.';
    if (file.size <= 0) return 'That file is empty.';
    if (file.size > data.assignment.limits.max_mb * 1048576) return `The file must be ${data.assignment.limits.max_mb} MB or smaller.`;
    return '';
  }

  function uploadForm(replacing) {
    const a = data.assignment;
    const form = el('div', 'upload');
    const input = el('input', 'upload__input', null, { type: 'file', id: `answerFile${replacing ? 'Replace' : ''}`, accept: '.pdf,.doc,.docx' });
    const label = el('label', 'upload__drop', null, { for: input.id });
    label.append(el('strong', null, replacing ? 'Choose a new file' : 'Choose your answers file'),
      el('small', 'text-muted', `PDF, DOC or DOCX · up to ${a.limits.max_mb} MB`));
    const picked = el('div', 'upload__picked', null, { hidden: '' });
    const message = el('p', 'form-message', null, { role: 'alert' });
    const progress = el('div', 'progress upload__progress', null, { role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': '0', 'aria-label': 'Upload progress', hidden: '' });
    const bar = el('div', 'progress__bar');
    progress.append(bar);
    const submit = el('button', 'btn btn-primary btn-block', replacing ? 'Replace file' : 'Submit answers', { type: 'button', disabled: '' });

    function choose(file) {
      message.textContent = '';
      chosen = null;
      picked.hidden = true;
      submit.disabled = true;
      if (!file) return;
      const problem = fileProblem(file);
      if (problem) { message.textContent = problem; input.value = ''; return; }
      chosen = file;
      picked.replaceChildren(el('strong', null, file.name), el('small', 'text-muted', size(file.size)));
      picked.hidden = false;
      submit.disabled = false;
    }
    input.addEventListener('change', () => choose(input.files[0]));
    ['dragover', 'dragenter'].forEach((name) => label.addEventListener(name, (e) => { e.preventDefault(); label.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((name) => label.addEventListener(name, () => label.classList.remove('is-over')));
    label.addEventListener('drop', (e) => {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; choose(input.files[0]); }
    });

    submit.addEventListener('click', () => {
      if (!chosen || busy) return;
      busy = true;
      submit.disabled = true;
      message.textContent = '';
      progress.hidden = false;
      const body = new FormData();
      body.append('action', 'submit');
      body.append('assignment_id', assignmentId);
      body.append('csrf_token', csrf);
      body.append('file', chosen);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.addEventListener('progress', (event) => {
        if (!event.lengthComputable) return;
        const percent = Math.round((event.loaded * 100) / event.total);
        bar.style.width = `${percent}%`;
        progress.setAttribute('aria-valuenow', String(percent));
      });
      xhr.addEventListener('load', () => {
        busy = false;
        let result = {};
        try { result = JSON.parse(xhr.responseText); } catch (e) { /* handled below */ }
        if (xhr.status === 200 && result.status === 'ok') {
          data.assignment = result.assignment;
          chosen = null;
          renderDetails();
          renderSubmission(result.message);
          $('#submissionTitle').focus({ preventScroll: true });
          return;
        }
        progress.hidden = true;
        bar.style.width = '0';
        submit.disabled = false;
        message.textContent = result.message || `The upload failed (HTTP ${xhr.status}). Please try again.`;
        if (result.assignment) { data.assignment = result.assignment; renderDetails(); renderSubmission(); }
      });
      xhr.addEventListener('error', () => {
        busy = false;
        progress.hidden = true;
        submit.disabled = false;
        message.textContent = 'The upload could not reach the server. Check your connection and try again.';
      });
      xhr.send(body);
    });

    // The input comes first so the label can be styled when the input has keyboard focus.
    form.append(input, label, picked, progress, message, submit);
    return form;
  }

  function fileRow(file, title, extra) {
    const row = el('div', 'assignment-file');
    row.append(el('span', 'assignment-file__icon', (file.ext || 'file').toUpperCase(), { 'aria-hidden': 'true' }));
    const text = el('span', 'assignment-file__text');
    text.append(el('strong', null, file.file_name || file.name), el('small', 'text-muted', title));
    row.append(text, el('a', 'btn btn-outline btn-sm', 'Download', { href: file.url, 'aria-label': `Download ${file.file_name || file.name}` }));
    if (extra) row.append(extra);
    return row;
  }

  function renderSubmission(notice) {
    const a = data.assignment;
    const s = a.submission;
    const host = $('[data-submission]');
    host.replaceChildren();

    if (notice) host.append(el('p', 'alert alert--success', notice, { role: 'status' }));

    if (!s) {
      if (a.overdue) host.append(el('p', 'alert alert--warning', 'This assignment is overdue. You can still submit, but it will be marked as late.'));
      host.append(el('p', 'text-muted', 'Upload your completed answers as one file.'), uploadForm(false));
      return;
    }

    const meta = el('div', 'assignment-submitted');
    meta.append(el('span', 'text-muted', `Submitted ${dateFormat.format(new Date(s.submitted))}`));
    if (s.late) meta.append(el('span', 'chip chip--warning', 'Late'));
    host.append(meta, fileRow(s, 'Your submitted file'));

    if (s.graded) {
      const grade = el('div', 'assignment-grade');
      const score = el('div', 'assignment-grade__score');
      score.append(el('strong', null, `${s.mark} / ${a.max_mark}`), el('span', 'text-muted', `${s.percent}%`));
      const track = el('div', 'progress', null, { role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(s.percent), 'aria-label': 'Your mark' });
      const fill = el('div', 'progress__bar progress__bar--done');
      fill.style.width = `${s.percent}%`;
      track.append(fill);
      grade.append(el('h3', null, 'Your mark'), score, track);
      if (s.graded_at) grade.append(el('small', 'text-muted', `Marked ${dateFormat.format(new Date(s.graded_at))}`));
      host.append(grade);

      const feedback = el('div', 'assignment-feedback');
      feedback.append(el('h3', null, 'Instructor feedback'),
        el('p', s.feedback ? null : 'text-muted', s.feedback || 'No written feedback was added.'));
      host.append(feedback, el('p', 'text-muted', 'This assignment has been marked, so your file can no longer be changed.'));
      return;
    }

    host.append(el('p', 'text-muted', 'Waiting for your instructor to mark this. You can replace your file until it is marked.'));
    const details = el('details', 'assignment-replace');
    details.append(el('summary', null, 'Replace your file'), uploadForm(true));
    host.append(details);
  }

  /* ------------------------------------------------------------- Load */
  async function load() {
    try {
      const response = await fetch(`${endpoint}?id=${encodeURIComponent(assignmentId)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (response.status === 401) { window.location.href = page.dataset.loginUrl; return; }
      const result = await response.json().catch(() => {
        throw new Error(`The server did not return JSON (HTTP ${response.status}). Check the PHP error log.`);
      });
      if (result.code === 'not_enrolled' && result.redirect) { window.location.href = result.redirect; return; }
      if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Could not load this assignment.');
      data = result;
      status.hidden = true;
      content.hidden = false;
      renderDetails();
      renderSubmission();
    } catch (error) {
      content.hidden = true;
      showStatus(error.message || 'Could not load this assignment.', 'danger', true);
    }
  }

  window.addEventListener('beforeunload', (event) => { if (busy) { event.preventDefault(); event.returnValue = ''; } });
  load();
})();
