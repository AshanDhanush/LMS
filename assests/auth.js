const loginForm = document.querySelector('.account-auth__form');

if (loginForm) {
  const status = document.querySelector('#login-status');
  const submitButton = loginForm.querySelector('button[type="submit"]');
  const submitLabel = submitButton.querySelector('span');

  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    status.hidden = true;
    status.textContent = '';
    submitButton.disabled = true;
    submitButton.setAttribute('aria-busy', 'true');
    submitLabel.textContent = 'Signing in…';

    try {
      const response = await fetch('../api/auth_api.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          email: loginForm.elements.email.value,
          password: loginForm.elements.password.value,
        }),
      });
      const result = await response.json();

      if (!response.ok || result.status !== 'success') {
        throw new Error(result.message || 'Sign-in failed. Please try again.');
      }

      status.dataset.state = 'success';
      status.textContent = result.message;
      status.hidden = false;
      window.location.assign(result.redirect);
    } catch (error) {
      status.dataset.state = 'error';
      status.textContent = error instanceof Error
        ? error.message
        : 'We could not connect to the sign-in service. Please try again.';
      status.hidden = false;
    } finally {
      submitButton.disabled = false;
      submitButton.removeAttribute('aria-busy');
      submitLabel.textContent = 'Sign in';
    }
  });
}