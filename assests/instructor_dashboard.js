// assests/instructor.js  (instructor dashboard)
// 1. Sign out: asks the server to remove the login cookie, then opens the login page.
// 2. Courses overview: filter by name and by status without reloading the page.

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

if (courseTable) {
  const searchInput = document.querySelector('#courseSearch');
  const statusSelect = document.querySelector('#courseStatus');
  const noMatch = document.querySelector('#courseNoMatch');
  const rows = Array.from(courseTable.querySelectorAll('tbody tr'));

  const applyFilter = () => {
    const text = searchInput.value.trim().toLowerCase();
    const status = statusSelect.value;
    let visible = 0;

    rows.forEach((row) => {
      const matches = row.dataset.title.includes(text) && (status === '' || row.dataset.status === status);
      row.hidden = !matches;
      if (matches) visible += 1;
    });

    courseTable.closest('.ins-table-wrap').hidden = visible === 0;
    noMatch.hidden = visible !== 0;
  };

  searchInput.addEventListener('input', applyFilter);
  statusSelect.addEventListener('change', applyFilter);
}

const createCourseForm = document.querySelector('#createCourseForm');

if (createCourseForm) {
  const submitButton = document.querySelector('#createCourseButton');
  const message = document.querySelector('#courseFormMessage');

  createCourseForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!createCourseForm.reportValidity()) {
      return;
    }

    const originalText = submitButton.textContent;

    submitButton.disabled = true;
    submitButton.textContent = 'Creating course…';
    message.hidden = true;
    message.textContent = '';

    try {
      const response = await fetch(window.courseApiUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
        },
        body: new FormData(createCourseForm),
      });

      const result = await response.json();

      if (!response.ok || result.status !== 'success') {
        throw new Error(
          result.message || 'Unable to create the course.'
        );
      }

      window.location.assign(
        result.redirect ||
        window.location.href
      );
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
