// assests/instructor.js  (instructor dashboard)
// 1. Sign out: asks the server to remove the login cookie, then opens the login page.
// 2. Courses overview: filter by name and by status without reloading the page.

const instructorSidebarToggle = document.querySelector('[data-instructor-sidebar-toggle]');
const instructorLayout = document.querySelector('.ins-layout');
const instructorSidebar = document.getElementById('instructor-sidebar');

if (
  instructorSidebarToggle instanceof HTMLButtonElement &&
  instructorLayout &&
  instructorSidebar
) {
  const desktopNavigation = window.matchMedia('(min-width: 900px)');

  function setInstructorSidebarOpen(isOpen) {
    instructorLayout.classList.toggle('is-sidebar-open', isOpen);
    instructorSidebarToggle.setAttribute('aria-expanded', String(isOpen));
    instructorSidebarToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
  }

  instructorSidebarToggle.addEventListener('click', () => {
    setInstructorSidebarOpen(!instructorLayout.classList.contains('is-sidebar-open'));
  });

  instructorLayout.addEventListener('click', (event) => {
    if (event.target === instructorLayout && instructorLayout.classList.contains('is-sidebar-open')) {
      setInstructorSidebarOpen(false);
    }
  });

  instructorSidebar.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => setInstructorSidebarOpen(false));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && instructorLayout.classList.contains('is-sidebar-open')) {
      setInstructorSidebarOpen(false);
      instructorSidebarToggle.focus();
    }
  });

  desktopNavigation.addEventListener('change', (event) => {
    if (event.matches) setInstructorSidebarOpen(false);
  });
}

const signOutButton = document.querySelector('#signOut');

if (signOutButton) {
  signOutButton.addEventListener('click', async () => {
    signOutButton.disabled = true;
    signOutButton.textContent = 'Signing out…';

    try {
      const response = await fetch(signOutButton.dataset.logoutUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: '{}',
      });
      const result = await response.json();

      if (!response.ok || result.status !== 'success') {
        throw new Error(result.message || 'Sign-out failed. Please try again.');
      }

      window.location.assign(result.redirect || signOutButton.dataset.loginUrl);
    } catch (error) {
      signOutButton.disabled = false;
      signOutButton.textContent = 'Sign out';
      window.alert(error instanceof Error ? error.message : 'We could not reach the server. Please try again.');
    }
  });
}

const courseTable = document.querySelector('#courseTable');
const courseGrid = document.querySelector('#courseGrid');
const courseList = courseGrid || courseTable;

if (courseList instanceof HTMLElement) {
  const searchInput = document.querySelector('#courseSearch');
  const statusSelect = document.querySelector('#courseStatus');
  const noMatch = document.querySelector('#courseNoMatch');
  if (
    searchInput instanceof HTMLInputElement &&
    statusSelect instanceof HTMLSelectElement &&
    noMatch instanceof HTMLElement
  ) {
    const items = Array.from(
      courseList.querySelectorAll(courseGrid ? '.ins-grid-card' : 'tbody tr')
    );

    const applyFilter = () => {
      const text = searchInput.value.trim().toLowerCase();
      const status = statusSelect.value.toLowerCase();
      let visible = 0;

      items.forEach((item) => {
        const matchesTitle = (item.dataset.title || '').includes(text);
        const matchesStatus = status === '' || (item.dataset.status || '').toLowerCase() === status;
        item.hidden = !matchesTitle || !matchesStatus;
        if (!item.hidden) visible += 1;
      });

      if (courseGrid) {
        courseGrid.hidden = visible === 0;
      } else {
        const tableWrap = courseTable.closest('.ins-table-wrap');
        if (tableWrap instanceof HTMLElement) tableWrap.hidden = visible === 0;
      }
      noMatch.hidden = visible !== 0;
    };

    searchInput.addEventListener('input', applyFilter);
    statusSelect.addEventListener('change', applyFilter);
  }
}

document.querySelectorAll('[data-course-delete]').forEach((button) => {
  if (!(button instanceof HTMLButtonElement)) return;

  button.addEventListener('click', async () => {
    const courseId = button.dataset.courseId;
    const courseTitle = button.dataset.courseTitle || 'this course';
    const deleteUrl = button.dataset.deleteUrl;
    const csrfToken = button.dataset.csrfToken;
    const message = document.querySelector('#courseDeleteMessage');

    if (!courseId || !deleteUrl || !csrfToken || !(message instanceof HTMLElement)) {
      window.alert('Unable to delete this course. Reload the page and try again.');
      return;
    }

    if (!window.confirm(`Delete "${courseTitle}"? This action cannot be undone.`)) {
      return;
    }

    button.disabled = true;
    message.hidden = true;
    message.textContent = '';

    const formData = new FormData();
    formData.set('action', 'delete');
    formData.set('course_id', courseId);
    formData.set('csrf_token', csrfToken);

    try {
      const response = await fetch(deleteUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body: formData,
      });

      let result;
      try {
        result = await response.json();
      } catch {
        throw new Error('The server returned an invalid response. Please try again.');
      }

      if (!response.ok || result.status !== 'success') {
        throw new Error(result.message || 'Unable to delete this course.');
      }

      window.location.reload();
    } catch (error) {
      message.textContent = error instanceof Error
        ? error.message
        : 'Unable to delete this course. Please try again.';
      message.hidden = false;
      button.disabled = false;
    }
  });
});

const courseForm = document.querySelector(
  '#createCourseForm, #editCourseForm'
);

if (courseForm instanceof HTMLFormElement) {
  const isEditForm = courseForm.id === 'editCourseForm';

  const submitButton = document.querySelector(
    isEditForm ? '#editCourseButton' : '#createCourseButton'
  );

  const message = document.querySelector('#courseFormMessage');

  if (
    submitButton instanceof HTMLButtonElement &&
    message instanceof HTMLElement
  ) {
    courseForm.addEventListener('submit', async (event) => {
      event.preventDefault();

      if (!courseForm.reportValidity()) {
        return;
      }

      const originalText = submitButton.textContent;

      submitButton.disabled = true;
      submitButton.textContent = isEditForm
        ? 'Updating course…'
        : 'Creating course…';

      message.hidden = true;
      message.textContent = '';

      try {
        const response = await fetch(window.courseApiUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
          },
          body: new FormData(courseForm),
        });

        let result;

        try {
          result = await response.json();
        } catch {
          throw new Error(
            'The server returned an invalid response. Please try again.'
          );
        }

        if (!response.ok || result.status !== 'success') {
          throw new Error(
            result.message ||
            (isEditForm
              ? 'Unable to update the course.'
              : 'Unable to create the course.')
          );
        }

        // Navigate to the URL returned by the PHP API.
        if (result.redirect) {
          window.location.assign(result.redirect);
        } else {
          window.location.reload();
        }
      } catch (error) {
        message.textContent =
          error instanceof Error
            ? error.message
            : 'Something went wrong. Please try again.';

        message.hidden = false;
      } finally {
        submitButton.disabled = false;
        submitButton.textContent = originalText;
      }
    });
  }
}

/* =========================================================
   LESSON MANAGEMENT
   Add, edit, delete lessons and switch lesson-type fields.
   ========================================================= */

(() => {
  const form = document.querySelector("#lessonForm");
  const typeSelect = document.querySelector("#lessonType");
  const textGroup = document.querySelector("#lessonTextGroup");
  const contentInput = document.querySelector("#lessonContent");
  const pdfGroup = document.querySelector("#lessonPdfGroup");
  const pdfInput = document.querySelector("#lessonPdf");
  const powerpointGroup = document.querySelector("#lessonPowerPointGroup");
  const powerpointInput = document.querySelector("#lessonPowerPoint");
  const videoInput = document.querySelector("#lessonVideoUrl");
  const submitButton = document.querySelector("#lessonSubmitButton");
  const message = document.querySelector("#lessonFormMessage");
  const isEditing = form?.querySelector('[name="action"]')?.value === "update";

  function updateLessonFields() {
    if (!typeSelect) return;

    const type = typeSelect.value;
    const isPdf = type === "pdf";
    const isPowerPoint = type === "powerpoint";
    const isVideo = type === "video";

    if (textGroup) {
      textGroup.hidden = type !== "text";
    }

    if (pdfGroup) {
      pdfGroup.hidden = !isPdf;
    }

    if (powerpointGroup) {
      powerpointGroup.hidden = !isPowerPoint;
    }

    if (contentInput) {
      contentInput.required = type === "text";
    }

    if (pdfInput) {
      pdfInput.disabled = !isPdf;
      pdfInput.required = isPdf && !isEditing;
    }

    if (powerpointInput) {
      powerpointInput.disabled = !isPowerPoint;
      powerpointInput.required = isPowerPoint && !isEditing;
    }

    if (videoInput) {
      videoInput.required = isVideo;
    }
  }

  function showLessonMessage(text, isError = true) {
    if (!message) return;

    message.textContent = text;
    message.style.color = isError ? "#b42318" : "#18753c";
  }

  if (typeSelect) {
    typeSelect.addEventListener("change", updateLessonFields);
    updateLessonFields();
  }

  if (form) {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();

      if (!form.reportValidity()) return;

      const apiUrl = window.lessonApiUrl || form.action;
      const formData = new FormData(form);

      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = "Saving...";
      }

      showLessonMessage("");

      try {
        const response = await fetch(apiUrl, {
          method: "POST",
          body: formData,
          credentials: "same-origin",
          headers: {
            Accept: "application/json"
          }
        });

        const result = await response.json();

        if (!response.ok || result.status !== "success") {
          throw new Error(result.message || "Could not save the lesson.");
        }

        showLessonMessage(result.message || "Lesson saved successfully.", false);

        if (result.redirect) {
          window.location.assign(result.redirect);
        }
      } catch (error) {
        showLessonMessage(
          error.message || "An unexpected error occurred."
        );
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent =
            form.querySelector('[name="action"]')?.value === "update"
              ? "Save Changes"
              : "Create Lesson";
        }
      }
    });
  }

  // Delete lesson using the existing CSRF-protected API.
  document.querySelectorAll(".lessonDeleteForm").forEach((deleteForm) => {
    deleteForm.addEventListener("submit", async (event) => {
      event.preventDefault();

      if (!window.confirm(
        "Are you sure you want to delete this lesson? Student progress and comments for this lesson will also be deleted."
      )) {
        return;
      }

      const button = deleteForm.querySelector('button[type="submit"]');
      const pageMessage = document.querySelector("#lessonPageMessage");

      if (button) {
        button.disabled = true;
        button.textContent = "Deleting...";
      }

      if (pageMessage) {
        pageMessage.textContent = "";
      }

      try {
        const response = await fetch(
          window.lessonApiUrl || deleteForm.action,
          {
            method: "POST",
            body: new FormData(deleteForm),
            credentials: "same-origin",
            headers: {
              Accept: "application/json"
            }
          }
        );

        const result = await response.json();

        if (!response.ok || result.status !== "success") {
          throw new Error(result.message || "Could not delete the lesson.");
        }

        if (result.redirect) {
          window.location.assign(result.redirect);
        } else {
          window.location.reload();
        }
      } catch (error) {
        if (pageMessage) {
          pageMessage.textContent =
            error.message || "An unexpected error occurred.";
          pageMessage.style.color = "#b42318";
        }

        if (button) {
          button.disabled = false;
          button.textContent = "Delete";
        }
      }
    });
  });

  document.querySelectorAll(".moduleDeleteForm").forEach((deleteForm) => {
    if (!(deleteForm instanceof HTMLFormElement)) return;

    deleteForm.addEventListener("submit", async (event) => {
      event.preventDefault();

      const moduleTitle = deleteForm.dataset.moduleTitle || "this module";
      const lessonCount = Number(deleteForm.dataset.lessonCount || 0);
      const lessonWarning = lessonCount > 0
        ? ` This will also permanently delete ${lessonCount} lesson${lessonCount === 1 ? "" : "s"} and their progress.`
        : "";

      if (!window.confirm(`Delete "${moduleTitle}"?${lessonWarning} This action cannot be undone.`)) {
        return;
      }

      const button = deleteForm.querySelector('button[type="submit"]');
      const pageMessage = document.querySelector("#lessonPageMessage");
      const originalButtonText = button?.textContent || "Delete module";

      if (button instanceof HTMLButtonElement) {
        button.disabled = true;
        button.textContent = "Deleting...";
      }

      if (pageMessage instanceof HTMLElement) {
        pageMessage.hidden = true;
        pageMessage.textContent = "";
      }

      try {
        const response = await fetch(deleteForm.action, {
          method: "POST",
          body: new FormData(deleteForm),
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });

        let result;
        try {
          result = await response.json();
        } catch {
          throw new Error("The server returned an invalid response. Please try again.");
        }

        if (!response.ok || result.status !== "success") {
          throw new Error(result.message || "Could not delete the module.");
        }

        window.location.assign(result.redirect || window.location.href);
      } catch (error) {
        if (pageMessage instanceof HTMLElement) {
          pageMessage.textContent = error instanceof Error
            ? error.message
            : "Could not delete the module. Please try again.";
          pageMessage.hidden = false;
          pageMessage.style.color = "#b42318";
        } else {
          window.alert(error instanceof Error
            ? error.message
            : "Could not delete the module. Please try again.");
        }

        if (button instanceof HTMLButtonElement) {
          button.disabled = false;
          button.textContent = originalButtonText;
        }
      }
    });
  });

  })();

(() => {
  const form = document.querySelector("#editModuleForm");
  const message = document.querySelector("#moduleFormMessage");
  const submitButton = document.querySelector("#editModuleSubmitButton");

  if (
    !(form instanceof HTMLFormElement) ||
    !(message instanceof HTMLElement) ||
    !(submitButton instanceof HTMLButtonElement)
  ) {
    return;
  }

  const originalButtonText = submitButton.textContent;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    if (!form.reportValidity()) return;

    submitButton.disabled = true;
    submitButton.textContent = "Saving...";
    message.hidden = true;
    message.textContent = "";

    try {
      const response = await fetch(window.moduleApiUrl || form.action, {
        method: "POST",
        body: new FormData(form),
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });

      let result;
      try {
        result = await response.json();
      } catch {
        throw new Error("The server returned an invalid response. Please try again.");
      }

      if (!response.ok || result.status !== "success") {
        throw new Error(result.message || "Could not save the module.");
      }

      window.location.assign(result.redirect || window.moduleRedirectUrl);
    } catch (error) {
      message.textContent = error instanceof Error
        ? error.message
        : "Could not save the module. Please try again.";
      message.hidden = false;
      submitButton.disabled = false;
      submitButton.textContent = originalButtonText;
    }
  });
})();


  /* Course search for the Assignments and Quizzes page. */
(() => {
    'use strict';

    const searchInput = document.getElementById('assessSearch');
    const cards = document.querySelectorAll('[data-assess-card]');
    const noResults = document.getElementById('assessNoResults');

    if (!searchInput || !cards.length) {
        return;
    }

    function filterCards() {
        const query = searchInput.value.trim().toLowerCase();
        let visible = 0;

        cards.forEach((card) => {
            const title = card.dataset.assessTitle || '';
            const matches = query === '' || title.includes(query);

            card.hidden = !matches;
            if (matches) {
                visible++;
            }
        });

        if (noResults) {
            noResults.hidden = visible > 0;
        }
    }

    searchInput.addEventListener('input', filterCards);
})();

/* Type switching, file checks and the quiz builder for the Add Assignment or Quiz form. */
(() => {
    'use strict';

    const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx'];
    const MAX_CSV_SIZE = 1024 * 1024; // 1 MB
    const MAX_QUESTIONS = 50;
    const MIN_OPTIONS = 4;
    const MAX_OPTIONS = 5;

    const form = document.getElementById('assessmentForm');
    const typeSelect = document.getElementById('assessmentType');
    const panels = document.querySelectorAll('[data-type-panel]');
    const submitButton = document.getElementById('assessmentSubmit');
    const fileInput = document.getElementById('assignmentFile');
    const fileMessage = document.getElementById('assignmentFileMessage');

    if (!form || !typeSelect) {
        return;
    }

    const methodPanels = document.querySelectorAll('[data-quiz-method-panel]');
    const methodRadios = document.querySelectorAll('input[name="quiz_method"]');
    const csvInput = document.getElementById('quizCsv');
    const csvMessage = document.getElementById('quizCsvMessage');
    const questionList = document.getElementById('quizQuestions');
    const emptyNote = document.getElementById('quizEmptyNote');
    const totalsLine = document.getElementById('quizTotals');
    const addMcqButton = document.getElementById('quizAddMcq');
    const addEssayButton = document.getElementById('quizAddEssay');

    let questionCounter = 0;

    function selectedMethod() {
        const checked = document.querySelector('input[name="quiz_method"]:checked');
        return checked ? checked.value : 'manual';
    }

    /* Disabled fields are neither validated nor submitted, so a hidden
       panel never blocks the form or sends stray values. */
    function setPanelActive(panel, active) {
        panel.hidden = !active;
        panel.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = !active;
        });
    }

    function showSelectedType() {
        const selected = typeSelect.value;

        panels.forEach((panel) => {
            setPanelActive(panel, panel.dataset.typePanel === selected);
        });

        if (submitButton) {
            submitButton.textContent = selected === 'quiz' ? 'Save quiz' : 'Save assignment';
        }

        if (selected === 'quiz') {
            showSelectedMethod();
        }
    }

    function showSelectedMethod() {
        const method = selectedMethod();

        methodPanels.forEach((panel) => {
            setPanelActive(panel, panel.dataset.quizMethodPanel === method);
        });
    }

    typeSelect.addEventListener('change', showSelectedType);
    methodRadios.forEach((radio) => radio.addEventListener('change', showSelectedMethod));

    /* ---- Check the chosen files ---- */

    function setFileMessage(text) {
        if (fileMessage) {
            fileMessage.textContent = text;
        }
    }

    if (fileInput) {
        fileInput.addEventListener('change', () => {
            setFileMessage('');

            const file = fileInput.files && fileInput.files[0];
            if (!file) {
                return;
            }

            const extension = file.name.split('.').pop().toLowerCase();

            if (!ALLOWED_EXTENSIONS.includes(extension)) {
                setFileMessage('Only PDF, DOC or DOCX files are allowed.');
                fileInput.value = '';
                return;
            }

            if (file.size > MAX_FILE_SIZE) {
                setFileMessage('The file must be 10 MB or smaller.');
                fileInput.value = '';
            }
        });
    }

    if (csvInput) {
        csvInput.addEventListener('change', () => {
            csvMessage.textContent = '';

            const file = csvInput.files && csvInput.files[0];
            if (!file) {
                return;
            }

            if (file.name.split('.').pop().toLowerCase() !== 'csv') {
                csvMessage.textContent = 'The questions file must be a .csv file.';
                csvInput.value = '';
            } else if (file.size > MAX_CSV_SIZE) {
                csvMessage.textContent = 'The CSV file must be 1 MB or smaller.';
                csvInput.value = '';
            }
        });
    }

    /* ---- Manual question builder ---- */

    function el(tag, attributes = {}, children = []) {
        const node = document.createElement(tag);

        Object.entries(attributes).forEach(([name, value]) => {
            if (name === 'class') {
                node.className = value;
            } else if (name === 'text') {
                node.textContent = value;
            } else if (value === true) {
                node.setAttribute(name, '');
            } else if (value !== false && value !== null && value !== undefined) {
                node.setAttribute(name, value);
            }
        });

        children.forEach((child) => node.append(child));
        return node;
    }

    function field(labelText, control, forId) {
        return el('div', { class: 'ins-lesson-field' }, [
            el('label', { for: forId, text: labelText }),
            control,
        ]);
    }

    function questionCards() {
        return Array.from(questionList.querySelectorAll('.ins-quiz-q'));
    }

    /* Number the cards, keep the Add buttons and the totals up to date. */
    function refreshBuilder() {
        const cards = questionCards();

        cards.forEach((card, position) => {
            card.querySelector('.ins-quiz-q__number').textContent = 'Question ' + (position + 1);
        });

        if (emptyNote) {
            emptyNote.hidden = cards.length > 0;
        }

        const full = cards.length >= MAX_QUESTIONS;
        addMcqButton.disabled = full;
        addEssayButton.disabled = full;

        let total = 0;
        cards.forEach((card) => {
            total += parseInt(card.querySelector('.ins-quiz-q__marks').value, 10) || 0;
        });

        totalsLine.textContent = cards.length
            ? cards.length + ' question' + (cards.length === 1 ? '' : 's') + ', ' + total + ' marks in total.'
            : '';
    }

    function addOptionRow(card, index, optionIndex, text, checked) {
        const row = el('div', { class: 'ins-quiz-option' });
        const radioId = 'q' + index + 'c' + optionIndex;

        row.append(
            el('input', {
                type: 'radio',
                id: radioId,
                name: 'quiz_questions[' + index + '][correct]',
                value: String(optionIndex),
                checked: checked,
                'aria-label': 'Mark option ' + (optionIndex + 1) + ' as the correct answer',
            }),
            el('input', {
                type: 'text',
                name: 'quiz_questions[' + index + '][options][]',
                maxlength: '500',
                placeholder: 'Option ' + (optionIndex + 1) + (optionIndex >= MIN_OPTIONS ? ' (optional)' : ''),
                required: optionIndex < MIN_OPTIONS,
                value: text,
                'aria-label': 'Option ' + (optionIndex + 1),
            })
        );

        card.querySelector('.ins-quiz-options').append(row);
    }

    function buildQuestion(type, data = {}) {
        const index = questionCounter++;
        const isMcq = type === 'mcq';

        const card = el('section', { class: 'ins-quiz-q ins-card' });

        const removeButton = el('button', {
            type: 'button',
            class: 'ins-btn ins-btn--small ins-btn--danger',
            text: 'Remove',
        });

        card.append(
            el('header', { class: 'ins-quiz-q__head' }, [
                el('div', {}, [
                    el('strong', { class: 'ins-quiz-q__number', text: 'Question' }),
                    el('span', { class: 'ins-quiz-q__type', text: isMcq ? 'Multiple choice' : 'Essay' }),
                ]),
                removeButton,
            ]),
            el('input', { type: 'hidden', name: 'quiz_questions[' + index + '][type]', value: type })
        );

        const textArea = el('textarea', {
            id: 'q' + index + 'text',
            name: 'quiz_questions[' + index + '][text]',
            maxlength: '2000',
            rows: '3',
            required: true,
        });
        textArea.value = data.text || '';
        card.append(field('Question', textArea, 'q' + index + 'text'));

        const marksInput = el('input', {
            type: 'number',
            id: 'q' + index + 'marks',
            class: 'ins-quiz-q__marks',
            name: 'quiz_questions[' + index + '][marks]',
            min: '1',
            max: '100',
            step: '1',
            required: true,
            value: data.marks || (isMcq ? '1' : '10'),
        });
        marksInput.addEventListener('input', refreshBuilder);
        card.append(field('Marks', marksInput, 'q' + index + 'marks'));

        if (isMcq) {
            card.append(
                el('p', { class: 'ins-quiz-hint', text: 'Enter 4 or 5 options and select the correct answer.' }),
                el('div', { class: 'ins-quiz-options' })
            );

            const options = Array.isArray(data.options) ? data.options : [];
            const correct = data.correct !== undefined && data.correct !== '' ? String(data.correct) : '';

            for (let i = 0; i < MAX_OPTIONS; i += 1) {
                addOptionRow(card, index, i, options[i] || '', correct === String(i));
            }
        } else {
            const modelAnswer = el('textarea', {
                id: 'q' + index + 'model',
                name: 'quiz_questions[' + index + '][model_answer]',
                maxlength: '5000',
                rows: '3',
                placeholder: 'Key points or a sample answer to help you mark this question',
            });
            modelAnswer.value = data.model_answer || '';
            card.append(field('Model answer (optional)', modelAnswer, 'q' + index + 'model'));
        }

        removeButton.addEventListener('click', () => {
            card.remove();
            refreshBuilder();
        });

        questionList.append(card);
        refreshBuilder();
        return card;
    }

    if (questionList) {
        addMcqButton.addEventListener('click', () => {
            buildQuestion('mcq').querySelector('textarea').focus();
        });

        addEssayButton.addEventListener('click', () => {
            buildQuestion('essay').querySelector('textarea').focus();
        });

        /* Put back what the instructor typed if the server sent the form back. */
        try {
            const initial = JSON.parse(document.getElementById('quizInitialQuestions').textContent || '[]');
            if (Array.isArray(initial)) {
                initial.forEach((question) => {
                    if (question && (question.type === 'mcq' || question.type === 'essay')) {
                        buildQuestion(question.type, question);
                    }
                });
            }
        } catch (error) {
            /* Nothing to restore. */
        }

        refreshBuilder();
    }

    /* ---- Checks and double-submit protection ---- */

    function quizProblem() {
        if (selectedMethod() === 'csv') {
            return csvInput && csvInput.files && csvInput.files.length ? '' : 'Please choose a CSV file.';
        }

        const cards = questionCards();
        if (!cards.length) {
            return 'Add at least one question.';
        }

        for (const card of cards) {
            const isMcq = card.querySelector('.ins-quiz-options') !== null;
            if (isMcq && !card.querySelector('.ins-quiz-option input[type="radio"]:checked')) {
                const number = card.querySelector('.ins-quiz-q__number').textContent;
                card.scrollIntoView({ block: 'center' });
                return number + ': select the correct answer.';
            }
        }

        return '';
    }

    form.addEventListener('submit', (event) => {
        if (!form.reportValidity()) {
            event.preventDefault();
            return;
        }

        if (typeSelect.value === 'quiz') {
            const problem = quizProblem();
            if (problem) {
                event.preventDefault();
                window.alert(problem);
                return;
            }
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Saving...';
        }
    });

    showSelectedType();
})();
