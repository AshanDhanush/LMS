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
  const contentGroup = document.querySelector("#lessonContentGroup");
  const contentLabel = document.querySelector("#lessonContentLabel");
  const contentInput = document.querySelector("#lessonContent");
  const pdfGroup = document.querySelector("#lessonPdfGroup");
  const pdfInput = document.querySelector("#lessonPdf");
  const submitButton = document.querySelector("#lessonSubmitButton");
  const message = document.querySelector("#lessonFormMessage");

  function updateLessonFields() {
    if (!typeSelect) return;

    const type = typeSelect.value;
    const isPdf = type === "pdf";
    const isVideo = type === "video";

    if (contentGroup) {
      contentGroup.hidden = isPdf;
    }

    if (pdfGroup) {
      pdfGroup.hidden = !isPdf;
      pdfGroup.style.display = isPdf ? "block" : "none";
    }

    if (contentLabel) {
      contentLabel.textContent = isVideo
        ? "Video URL"
        : "Lesson content";
    }

    if (contentInput) {
      contentInput.placeholder = isVideo
        ? "https://www.youtube.com/watch?v=..."
        : "Write your lesson content here";

      contentInput.required = !isPdf;
    }

    if (pdfInput) {
      pdfInput.required = false;
      pdfInput.disabled = !isPdf;
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
})();
