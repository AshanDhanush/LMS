const authForm = document.querySelector('[data-auth-action]');

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!(input instanceof HTMLInputElement)) return;

    const isVisible = input.type === 'text';
    input.type = isVisible ? 'password' : 'text';
    button.setAttribute('aria-pressed', String(!isVisible));
    button.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
    button.title = isVisible ? 'Show password' : 'Hide password';
  });
});

if (authForm) {
  const action = authForm.dataset.authAction;
  const isRegistration = action === 'register' || action === 'admin_create_user';
  const endpoint = authForm.dataset.authEndpoint || 'api/auth_api.php';
  const status = document.getElementById(authForm.dataset.statusId);
  const submitButton = authForm.querySelector('button[type="submit"]');
  const submitLabel = submitButton.querySelector('span');
  const emailInput = isRegistration ? authForm.elements.email : null;
  const emailError = emailInput ? document.getElementById(emailInput.getAttribute('aria-describedby')) : null;
  let emailCheckRequest = 0;
  const nameInput = action === 'admin_create_user' ? authForm.elements.name : null;
  const nameError = nameInput ? document.getElementById(nameInput.getAttribute('aria-describedby')) : null;

  function validateFullname() {
    if (!nameInput || !nameError) return true;

    const name = nameInput.value.trim();
    const namePattern = /^[\p{L}\p{M}]+(?: [\p{L}\p{M}]+)*$/u;

    let message = '';
    if (name === '') {
      message = 'Full name is required.';
    } else if (name.length > 100) {
      message = 'Full name must be 100 characters or less.';
    } else if (!namePattern.test(name)) {
      message = 'Full name can only contain letters and spaces.';
    }

    nameError.textContent = message;
    nameError.hidden = message === '';
    nameInput.setAttribute('aria-invalid', String(message !== ''));

    return message === '';
  }
  function showEmailError(message) {
    if (!emailInput || !emailError) return;
    emailError.textContent = message;
    emailError.hidden = message === '';
    emailInput.setAttribute('aria-invalid', String(message !== ''));
  }

  function emailFormatError() {
    if (emailInput.validity.valueMissing) return 'Enter an email address.';
    if (emailInput.validity.typeMismatch || emailInput.validity.tooLong) return 'Enter a valid email address.';
    return '';
  }

  async function checkEmailAvailability() {
    if (!emailInput || action !== 'admin_create_user') return true;

    const formatError = emailFormatError();
    if (formatError) {
      showEmailError(formatError);
      return false;
    }

    const requestId = ++emailCheckRequest;
    const value = emailInput.value.trim();
    try {
      const response = await fetch(`${endpoint}?action=admin_check_email`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ email: value }),
      });
      const result = await response.json();

      if (requestId !== emailCheckRequest || value !== emailInput.value.trim()) return false;
      if (!response.ok || result.status !== 'success') {
        showEmailError(result.message || 'The email address could not be checked. Please try again.');
        return false;
      }
      showEmailError(result.available ? '' : result.message);
      return result.available === true;
    } catch (error) {
      if (requestId === emailCheckRequest && value === emailInput.value.trim()) {
        showEmailError('Could not check this email address. Please try again.');
      }
      return false;
    }
  }

  if (emailInput && action === 'admin_create_user') {
    emailInput.addEventListener('input', () => {
      emailCheckRequest += 1;
      showEmailError(emailFormatError());
    });

    if (nameInput) {
      nameInput.addEventListener('input', () => {
        validateFullname();
      });
      nameInput.addEventListener('blur', () => {
        validateFullname();
      });
    }
    emailInput.addEventListener('blur', () => {
      if (!emailFormatError() && emailInput.value.trim() !== '') checkEmailAvailability();
    });
  }


  /* ------------------------------------------------------------------
     Sign-in form validation (inline messages under each field).
     The server checks the same rules again, so skipping this script does
     not skip the checks.
       Identifier: an email address, or a username (letters, numbers, - and _).
       Email: one @, letters/numbers/. _ % + ' - before it, a real domain after it.
       Password: not trimmed; no tabs/line breaks/control characters; 128 max.
     ------------------------------------------------------------------ */
  const identifierInput = action === 'login' ? authForm.elements.identifier : null;
  const passwordInput = action === 'login' ? authForm.elements.password : null;
  const CONTROL_CHARACTERS = /[\u0000-\u001F\u007F-\u009F]/;
  const USERNAME_PATTERN = /^[\p{L}\p{M}\p{N}_-]{3,64}$/u;
  const DOMAIN_LABEL = /^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/;
  const MAX_IDENTIFIER = 190;
  const MAX_PASSWORD = 128;

  function isValidEmail(value) {
    const parts = value.split('@');
    if (parts.length !== 2) return false;
    const [local, domain] = parts;
    if (local.length < 1 || local.length > 64 || !/^[A-Za-z0-9._%+'-]+$/.test(local)) return false;
    if (local.startsWith('.') || local.endsWith('.') || local.includes('..')) return false;
    const labels = domain.split('.');
    if (labels.length < 2 || !labels.every((label) => DOMAIN_LABEL.test(label))) return false;
    return /^[A-Za-z]{2,}$/.test(labels[labels.length - 1]);
  }

  function identifierProblem(raw) {
    const value = raw.trim();
    if (value === '') return 'Enter your username or email address.';
    if (CONTROL_CHARACTERS.test(value)) return 'Remove tabs, line breaks and other hidden characters.';
    if (value.length > MAX_IDENTIFIER) return `Use ${MAX_IDENTIFIER} characters or fewer.`;
    if (value.includes('@')) return isValidEmail(value) ? '' : 'Enter a valid email address, like name@example.com.';
    return USERNAME_PATTERN.test(value) ? '' : 'Enter a valid email address, or a username using letters, numbers, - and _.';
  }

  function passwordProblem(value) {
    if (value === '') return 'Enter your password.';
    if (CONTROL_CHARACTERS.test(value)) return 'Password contains invalid characters.';
    if (value.length > MAX_PASSWORD) return `Password must be ${MAX_PASSWORD} characters or fewer.`;
    return '';
  }

  function showFieldError(input, message) {
    const error = document.getElementById(input.getAttribute('aria-describedby'));
    if (error) {
      error.textContent = message;
      error.hidden = message === '';
    }
    input.setAttribute('aria-invalid', String(message !== ''));
  }

  function checkIdentifier() {
    const message = identifierProblem(identifierInput.value);
    showFieldError(identifierInput, message);
    return message === '';
  }

  function checkPassword() {
    const message = passwordProblem(passwordInput.value);
    showFieldError(passwordInput, message);
    return message === '';
  }

  function validateLogin() {
    // Check both so every problem is shown at once, then focus the first one.
    const identifierOk = checkIdentifier();
    const passwordOk = checkPassword();
    if (!identifierOk) identifierInput.focus();
    else if (!passwordOk) passwordInput.focus();
    return identifierOk && passwordOk;
  }

  if (identifierInput && passwordInput) {
    // Tidy the identifier when the person leaves the field (spaces around it are dropped).
    identifierInput.addEventListener('blur', () => {
      identifierInput.value = identifierInput.value.trim();
      if (identifierInput.value !== '') checkIdentifier();
    });
    // Once a message is showing, re-check while typing so it clears as soon as the problem is fixed.
    identifierInput.addEventListener('input', () => {
      if (identifierInput.getAttribute('aria-invalid') === 'true') checkIdentifier();
    });
    passwordInput.addEventListener('input', () => {
      if (passwordInput.getAttribute('aria-invalid') === 'true') checkPassword();
    });
  }

  authForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (submitButton.disabled) return; // a request is already on its way: never send a second one
    if (action === 'login' && !validateLogin()) return;
    if (nameInput && !validateFullname()) {
      nameInput.focus();
      return;
    }

    if (isRegistration && emailInput && action === 'admin_create_user' && !(await checkEmailAvailability())) {
      emailInput.focus();
      return;
    }

    if (action !== 'login' && !authForm.reportValidity()) return;

    if (isRegistration && authForm.elements.password.value !== authForm.elements.password_confirmation.value) {
      status.dataset.state = 'error';
      status.textContent = 'The passwords do not match.';
      status.hidden = false;
      return;
    }

    status.hidden = true;
    status.textContent = '';
    submitButton.disabled = true;
    submitButton.setAttribute('aria-busy', 'true');
    submitLabel.textContent = isRegistration ? 'Creating account…' : 'Signing in…';

    const payload = Object.fromEntries(new FormData(authForm).entries());
    if (identifierInput) payload.identifier = identifierInput.value.trim(); // the password is sent exactly as typed

    try {
      const response = await fetch(`${endpoint}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      const result = await response.json();

      if (!response.ok || result.status !== 'success') {
        if (result.field === 'email' && emailError) {
          showEmailError(result.message || 'Check this email address.');
          emailInput.focus();
          return;
        }
        if (identifierInput && (result.field === 'identifier' || result.field === 'password')) {
          const input = result.field === 'identifier' ? identifierInput : passwordInput;
          showFieldError(input, result.message || 'Check this field.');
          input.focus();
          return;
        }
        if (result.field === 'name' && nameError) {
          nameError.textContent = result.message || 'Check the full name.';
          nameError.hidden = false;
          nameInput.setAttribute('aria-invalid', 'true');
          nameInput.focus();
          return;
        }
        throw new Error(result.message || 'The request failed. Please try again.');
      }

      status.dataset.state = 'success';
      status.textContent = result.message;
      status.hidden = false;
      // replace(), not assign(): the sign-in page must not stay in the history, or Back would return to it.
      if (action === 'login') window.location.replace(result.redirect || 'index.php');
      else window.location.assign(result.redirect || 'index.php');
    } catch (error) {
      status.dataset.state = 'error';
      status.textContent = error instanceof Error
        ? error.message
        : 'We could not connect to the sign-in service. Please try again.';
      status.hidden = false;
    } finally {
      submitButton.disabled = false;
      submitButton.removeAttribute('aria-busy');
      submitLabel.textContent = isRegistration ? 'Create account' : 'Sign in';
    }
  });
}

const loginPanel = document.getElementById('login-panel');
const forgotPanel = document.getElementById('forgot-panel');

if (loginPanel && forgotPanel) {
  const resetEmailForm = document.getElementById('reset-email-form');
  const resetSendForm = document.getElementById('reset-send-form');
  const resetOtpForm = document.getElementById('reset-otp-form');
  const resetPasswordForm = document.getElementById('reset-password-form');
  const resetEmail = document.getElementById('reset-email');
  const resetOtp = document.getElementById('reset-otp');
  const resetStatus = document.getElementById('reset-status');
  const resetForms = [resetEmailForm, resetSendForm, resetOtpForm, resetPasswordForm];
  const resendOtpButton = document.getElementById('resend-otp-button');
  const resendCountdown = document.getElementById('otp-resend-countdown');
  let resendCountdownTimer;

  function showResetStatus(message, state = 'error') {
    resetStatus.dataset.state = state;
    resetStatus.textContent = message;
    resetStatus.hidden = false;
  }

  async function submitResetAction(action, payload, formOrButton) {
    const button = formOrButton instanceof HTMLButtonElement
      ? formOrButton
      : formOrButton.querySelector('button[type="submit"]');
    const originalLabel = button.textContent;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(`api/auth_api.php?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      const result = await response.json();

      if (!response.ok || result.status !== 'success') {
        throw new Error(result.message || 'The request failed. Please try again.');
      }

      return result;
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.textContent = originalLabel;
    }
  }

  function startResendCountdown() {
    window.clearInterval(resendCountdownTimer);
    let secondsRemaining = 60;
    resendOtpButton.hidden = true;
    resendOtpButton.disabled = true;
    resendCountdown.hidden = false;

    const updateCountdown = () => {
      const minutes = Math.floor(secondsRemaining / 60);
      const seconds = secondsRemaining % 60;
      resendCountdown.textContent = `You can request another code in ${minutes}:${String(seconds).padStart(2, '0')}.`;

      if (secondsRemaining === 0) {
        window.clearInterval(resendCountdownTimer);
        resendCountdown.hidden = true;
        resendOtpButton.hidden = false;
        resendOtpButton.disabled = false;
        return;
      }
      secondsRemaining -= 1;
    };

    updateCountdown();
    resendCountdownTimer = window.setInterval(updateCountdown, 1000);
  }

  function resetRecoveryFlow() {
    window.clearInterval(resendCountdownTimer);
    resetForms.forEach((form) => {
      form.reset();
      form.hidden = form !== resetEmailForm;
    });
    resendOtpButton.hidden = true;
    resendOtpButton.disabled = true;
    resendCountdown.hidden = true;
    resendCountdown.textContent = '';
    resetStatus.hidden = true;
    resetStatus.textContent = '';
  }

  document.querySelectorAll('[data-auth-view]').forEach((button) => {
    button.addEventListener('click', () => {
      const showForgot = button.dataset.authView === 'forgot';
      loginPanel.hidden = showForgot;
      forgotPanel.hidden = !showForgot;

      if (showForgot) {
        resetRecoveryFlow();
        resetEmail.focus();
      } else {
        resetRecoveryFlow();
      }
    });
  });

  resetEmailForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!resetEmail.checkValidity()) {
      resetEmail.reportValidity();
      return;
    }

    try {
      const result = await submitResetAction('check_reset_email', { email: resetEmail.value.trim() }, resetEmailForm);
      showResetStatus(result.message, 'success');
      resetSendForm.hidden = false;
      resetEmailForm.hidden = true;
      resetSendForm.querySelector('button').focus();
    } catch (error) {
      showResetStatus(error instanceof Error ? error.message : 'We could not check that email. Please try again.');
    }
  });

  resetSendForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    try {
      const result = await submitResetAction('send_reset_otp', { email: resetEmail.value.trim() }, resetSendForm);
      showResetStatus(result.message, 'success');
      resetEmailForm.hidden = true;
      resetSendForm.hidden = true;
      resetOtpForm.hidden = false;
      startResendCountdown();
      resetOtp.focus();
    } catch (error) {
      showResetStatus(error instanceof Error ? error.message : 'We could not send the code. Please try again.');
    }
  });

  resendOtpButton.addEventListener('click', async () => {
    try {
      const result = await submitResetAction('send_reset_otp', {
        email: resetEmail.value.trim(),
      }, resendOtpButton);
      showResetStatus(result.message, 'success');
      startResendCountdown();
    } catch (error) {
      showResetStatus(error instanceof Error ? error.message : 'We could not send the code. Please try again.');
    }
  });

  resetOtpForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (!resetOtp.checkValidity()) {
      resetOtp.reportValidity();
      return;
    }

    try {
      const result = await submitResetAction('verify_reset_otp', {
        email: resetEmail.value.trim(),
        otp: resetOtp.value.trim(),
      }, resetOtpForm);
      showResetStatus(result.message, 'success');
      window.clearInterval(resendCountdownTimer);
      resendCountdown.hidden = true;
      resendOtpButton.hidden = true;
      resetPasswordForm.hidden = false;
      resetPasswordForm.querySelector('input').focus();
    } catch (error) {
      showResetStatus(error instanceof Error ? error.message : 'We could not verify the code. Please try again.');
    }
  });

  resetPasswordForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const password = resetPasswordForm.elements.password;
    const confirmation = resetPasswordForm.elements.password_confirmation;

    if (password.value !== confirmation.value) {
      confirmation.setCustomValidity('Passwords do not match.');
      confirmation.reportValidity();
      confirmation.addEventListener('input', () => confirmation.setCustomValidity(''), { once: true });
      return;
    }

    try {
      const result = await submitResetAction('reset_password', {
        email: resetEmail.value.trim(),
        otp: resetOtp.value.trim(),
        password: password.value,
        password_confirmation: confirmation.value,
      }, resetPasswordForm);
      resetForms.forEach((form) => {
        form.hidden = true;
      });
      showResetStatus(result.message, 'success');
    } catch (error) {
      showResetStatus(error instanceof Error ? error.message : 'We could not update your password. Please try again.');
    }
  });
}