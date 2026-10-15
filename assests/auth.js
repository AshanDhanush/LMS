const authForm = document.querySelector('[data-auth-action]');

if (authForm) {
  const action = authForm.dataset.authAction;
  const status = document.getElementById(authForm.dataset.statusId);
  const submitButton = authForm.querySelector('button[type="submit"]');
  const submitLabel = submitButton.querySelector('span');

  authForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (action === 'register' && authForm.elements.password.value !== authForm.elements.password_confirmation.value) {
      status.dataset.state = 'error';
      status.textContent = 'The passwords do not match.';
      status.hidden = false;
      return;
    }

    status.hidden = true;
    status.textContent = '';
    submitButton.disabled = true;
    submitButton.setAttribute('aria-busy', 'true');
    submitLabel.textContent = action === 'register' ? 'Creating account…' : 'Signing in…';

    const payload = Object.fromEntries(new FormData(authForm).entries());

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

      status.dataset.state = 'success';
      status.textContent = result.message;
      status.hidden = false;
      window.location.assign(result.redirect || 'index.php');
    } catch (error) {
      status.dataset.state = 'error';
      status.textContent = error instanceof Error
        ? error.message
        : 'We could not connect to the sign-in service. Please try again.';
      status.hidden = false;
    } finally {
      submitButton.disabled = false;
      submitButton.removeAttribute('aria-busy');
      submitLabel.textContent = action === 'register' ? 'Create account' : 'Sign in';
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