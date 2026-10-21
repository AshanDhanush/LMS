const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const adminShell = document.querySelector('.admin-shell');
const adminSidebar = document.getElementById('admin-sidebar');

if (sidebarToggle instanceof HTMLButtonElement && adminShell && adminSidebar) {
  const mobileNavigation = window.matchMedia('(max-width: 759px)');

  function setSidebarOpen(isOpen) {
    adminShell.classList.toggle('is-sidebar-open', isOpen);
    sidebarToggle.setAttribute('aria-expanded', String(isOpen));
    sidebarToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
  }

  sidebarToggle.addEventListener('click', () => {
    setSidebarOpen(!adminShell.classList.contains('is-sidebar-open'));
  });

  adminShell.addEventListener('click', (event) => {
    if (event.target === adminShell && adminShell.classList.contains('is-sidebar-open')) {
      setSidebarOpen(false);
    }
  });

  adminSidebar.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => setSidebarOpen(false));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && adminShell.classList.contains('is-sidebar-open')) {
      setSidebarOpen(false);
      sidebarToggle.focus();
    }
  });

  mobileNavigation.addEventListener('change', (event) => {
    if (!event.matches) setSidebarOpen(false);
  });
}

document.querySelectorAll('.admin-status-form select[name="status"]').forEach((statusSelect) => {
  statusSelect.addEventListener('change', () => {
    const statusForm = statusSelect.closest('form');
    if (statusForm instanceof HTMLFormElement) statusForm.requestSubmit();
  });
});

const searchForm = document.querySelector('[data-user-search-form]');

if (searchForm) {
  const input = searchForm.querySelector('[data-user-search-input]');
  const toggle = searchForm.querySelector('[data-user-search-toggle]');
  const suggestions = searchForm.querySelector('[data-user-search-suggestions]');

  const searchEndpoint = searchForm.dataset.userSearchEndpoint;
  const userProfileUrl = searchForm.dataset.userProfileUrl;
  let debounceTimer;
  let requestNumber = 0;

  function closeSuggestions() {
    suggestions.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-expanded', 'false');
  }

  function openSuggestions() {
    suggestions.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-expanded', 'true');
  }

  function renderSuggestions(users) {
    suggestions.replaceChildren();

    if (!users.length) {
      closeSuggestions();
      return;
    }

    users.forEach((user) => {
      const item = document.createElement('li');
      const option = document.createElement('a');
      const profileUrl = new URL(userProfileUrl, window.location.origin);
      option.href = profileUrl.href;

      option.addEventListener('click', (event) => {
        event.preventDefault();

        const selectionForm = document.createElement('form');
        selectionForm.method = 'post';
        selectionForm.action = profileUrl.href;
        selectionForm.hidden = true;

        const selectedUserId = document.createElement('input');
        selectedUserId.type = 'hidden';
        selectedUserId.name = 'user_id';
        selectedUserId.value = String(user.id);
        selectionForm.append(selectedUserId);
        document.body.append(selectionForm);
        selectionForm.submit();
      });

      const name = document.createElement('strong');
      name.textContent = user.name;

      const email = document.createElement('span');
      email.textContent = user.email;

      option.append(name, email);
      item.append(option);
      suggestions.append(item);
    });

    openSuggestions();
  }

  async function searchUsers(query) {
    const currentRequest = ++requestNumber;

    if (query.length < 1) {
      suggestions.replaceChildren();
      closeSuggestions();
      return;
    }

    try {
      if (!searchEndpoint || !userProfileUrl) throw new Error('User search is not configured.');

      const url = new URL(searchEndpoint, window.location.origin);
      url.searchParams.set('q', query);

      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error('Unable to search users.');
      }

      const data = await response.json();

      // Ignore outdated responses from earlier keystrokes.
      if (currentRequest !== requestNumber) return;

      renderSuggestions(data.users || []);
    } catch (error) {
      if (currentRequest !== requestNumber) return;

      suggestions.replaceChildren();
      closeSuggestions();
    }
  }

  input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      searchUsers(input.value.trim());
    }, 250);
  });

  toggle.addEventListener('click', () => {
    if (!suggestions.hidden) {
      closeSuggestions();
      return;
    }

    if (input.value.trim().length >= 1) {
      searchUsers(input.value.trim());
    } else {
      input.focus();
    }
  });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeSuggestions();
    }
  });

  document.addEventListener('click', (event) => {
    if (!searchForm.contains(event.target)) {
      closeSuggestions();
    }
  });
}