const profilePage = document.querySelector('[data-profile-endpoint]');

if (profilePage) {
  const endpoint = profilePage.dataset.profileEndpoint;
  const status = profilePage.querySelector('[data-profile-status]');
  const content = profilePage.querySelector('[data-profile-content]');
  const numberFormat = new Intl.NumberFormat();

  function showStatus(message, kind = 'info') {
    status.className = `alert alert--${kind}`;
    status.textContent = message;
    status.hidden = false;
  }

  function makeAvatar(name, photoUrl, large = false) {
    if (photoUrl) {
      const image = document.createElement('img');
      image.className = `avatar ${large ? 'avatar--lg' : 'avatar--sm'}${large ? ' profile-avatar__image' : ' profile-leaderboard__avatar'}`;
      image.src = photoUrl;
      image.alt = large ? `${name} profile photo` : '';
      if (!large) image.setAttribute('aria-hidden', 'true');
      return image;
    }

    const parts = String(name || '').trim().split(/\s+/u).filter(Boolean);
    const initials = parts.length > 1
      ? `${Array.from(parts[0])[0] || ''}${Array.from(parts.at(-1))[0] || ''}`
      : Array.from(parts[0] || '')[0] || '?';
    const placeholder = document.createElement('span');
    placeholder.className = `avatar ${large ? 'avatar--lg' : 'avatar--sm'}${large ? ' profile-avatar__placeholder' : ' profile-leaderboard__avatar'}`;
    placeholder.textContent = initials.toLocaleUpperCase();
    placeholder.setAttribute('aria-hidden', 'true');
    return placeholder;
  }

  function appendEmptyMessage(container, message) {
    const paragraph = document.createElement('p');
    paragraph.className = 'text-muted';
    paragraph.textContent = message;
    container.replaceChildren(paragraph);
  }

  function renderProfile(data) {
    const { profile, badges, leaderboard, user_id: userId } = data;

    profilePage.querySelector('[data-profile-avatar]').replaceChildren(
      makeAvatar(profile.name, profile.photo_url, true),
    );
    profilePage.querySelector('[data-profile-name]').textContent = profile.name;
    profilePage.querySelector('[data-profile-email]').textContent = profile.email;
    const points = profilePage.querySelector('[data-profile-points]');
    if (points) points.textContent = numberFormat.format(profile.points);

    if (profile.role === 'instructor' && data.instructor) {
      profilePage.querySelector('[data-profile-username]').textContent = profile.username || 'Not set';
      profilePage.querySelector('[data-profile-joined]').textContent = profile.joined_at;

      const emailStatus = profilePage.querySelector('[data-profile-email-status]');
      emailStatus.textContent = profile.email_verified ? 'Verified' : 'Not verified';
      emailStatus.className = profile.email_verified ? 'is-verified' : 'is-unverified';
      const securityStatus = profilePage.querySelector('[data-profile-security-status]');
      securityStatus.className = profile.email_verified ? 'chip chip--success' : 'chip chip--warning';
      securityStatus.textContent = profile.email_verified ? 'Email verified' : 'Email not verified';

      const stats = data.instructor;
      profilePage.querySelector('[data-instructor-active-courses]').textContent = numberFormat.format(stats.active_courses);
      profilePage.querySelector('[data-instructor-total-courses]').textContent = numberFormat.format(stats.total_courses);
      profilePage.querySelector('[data-instructor-learners]').textContent = numberFormat.format(stats.learners);
      profilePage.querySelector('[data-instructor-rating]').textContent = stats.average_rating === null
        ? '—'
        : Number(stats.average_rating).toFixed(2);
      profilePage.querySelector('[data-instructor-reviews]').textContent =
        `${numberFormat.format(stats.review_count)} ${stats.review_count === 1 ? 'review' : 'reviews'}`;

      const courseList = profilePage.querySelector('[data-instructor-courses]');
      if (!stats.courses.length) {
        appendEmptyMessage(courseList, 'You do not have any courses yet.');
      } else {
        const courseItems = stats.courses.map((course) => {
          const item = document.createElement('article');
          item.className = 'profile-course-item';

          const details = document.createElement('div');
          details.className = 'profile-course-item__details';
          const title = document.createElement('h3');
          title.textContent = course.title;
          const meta = document.createElement('p');
          meta.textContent = `${numberFormat.format(course.learners)} ${course.learners === 1 ? 'learner' : 'learners'}`
            + (course.average_rating === null ? '' : ` · ${Number(course.average_rating).toFixed(1)} rating`);
          details.append(title, meta);

          const status = document.createElement('span');
          status.className = `chip${course.status === 'published' ? ' chip--success' : ''}`;
          status.textContent = course.status === 'published' ? 'Published' : 'Draft';
          item.append(details, status);
          return item;
        });
        courseList.replaceChildren(...courseItems);
      }
    }

    const leaderboardContainer = profilePage.querySelector('[data-profile-leaderboard]');
    if (leaderboardContainer) {
      const myRank = leaderboard.findIndex((learner) => learner.id === userId) + 1;
      const rank = profilePage.querySelector('[data-profile-rank]');
      if (rank && myRank > 0) {
        rank.textContent = `Your rank #${myRank}`;
        rank.hidden = false;
      }

      if (!leaderboard.length) {
        appendEmptyMessage(leaderboardContainer, 'There are no active students on the leaderboard yet.');
      } else {
        const list = document.createElement('ol');
        list.className = 'leaderboard';
        leaderboard.forEach((learner) => {
          const item = document.createElement('li');
          if (learner.id === userId) item.classList.add('is-me');
          item.append(makeAvatar(learner.name, learner.photo_url));

          const learnerName = document.createElement('span');
          learnerName.className = 'leaderboard__name';
          learnerName.textContent = `${learner.name}${learner.id === userId ? ' (you)' : ''}`;

          const points = document.createElement('span');
          points.className = 'chip chip--points';
          points.textContent = `${numberFormat.format(learner.points)} pts`;
          item.append(learnerName, points);
          list.append(item);
        });
        leaderboardContainer.replaceChildren(list);
      }
    }

    const badgeGrid = profilePage.querySelector('[data-profile-badges]');
    if (badgeGrid) {
      const earnedBadgeCount = Object.values(badges).filter((badge) => badge.earned).length;
      const badgeCount = profilePage.querySelector('[data-profile-badge-count]');
      if (badgeCount) badgeCount.textContent = `${earnedBadgeCount} earned`;

      const badgeTiles = Object.values(badges).map((badge) => {
        const tile = document.createElement('article');
        tile.className = `badge-tile${badge.earned ? '' : ' is-locked'}`;

        const icon = document.createElement('span');
        icon.className = 'badge-tile__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = badge.icon;

        const name = document.createElement('span');
        name.className = 'badge-tile__name';
        name.textContent = badge.name;

        const hint = document.createElement('span');
        hint.className = 'badge-tile__hint';
        hint.textContent = badge.earned ? badge.earned_hint : badge.hint;

        const state = document.createElement('span');
        state.className = badge.earned || badge.requirement_met
          ? `chip ${badge.earned ? 'chip--points' : 'chip--success'}`
          : 'chip';
        state.textContent = badge.earned ? 'Earned' : badge.requirement_met ? 'Requirement met' : 'Locked';
        tile.append(icon, name, hint, state);
        return tile;
      });
      badgeGrid.replaceChildren(...badgeTiles);
    }
    content.hidden = false;
    status.hidden = true;
  }

  async function loadProfile() {
    try {
      const response = await fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const result = await response.json();
      if (!response.ok || result.status !== 'success') {
        throw new Error(result.message || 'Your profile could not be loaded.');
      }
      renderProfile(result.data);
    } catch (error) {
      showStatus(error instanceof Error ? error.message : 'Your profile could not be loaded. Please try again.', 'danger');
    }
  }

  const newPasswordInput = profilePage.querySelector('[name="new_password"]');
  if (newPasswordInput) {
    const meter = profilePage.querySelector('[data-password-meter]');
    const meterFill = profilePage.querySelector('[data-password-meter-fill]');
    const strengthLabel = profilePage.querySelector('[data-password-strength-label]');
    const lengthRule = profilePage.querySelector('[data-password-rule="length"]');
    const uppercaseRule = profilePage.querySelector('[data-password-rule="uppercase"]');
    const symbolRule = profilePage.querySelector('[data-password-rule="symbol"]');

    newPasswordInput.addEventListener('input', () => {
      const password = newPasswordInput.value;
      const rules = [
        { element: lengthRule, met: password.length >= 8 && password.length <= 72 },
        { element: uppercaseRule, met: /[A-Z]/.test(password) },
        { element: symbolRule, met: /[^A-Za-z0-9]/.test(password) },
      ];
      const score = rules.filter((rule) => rule.met).length;

      rules.forEach(({ element, met }) => element.classList.toggle('is-met', met));
      meter.dataset.level = String(score);
      meter.setAttribute('aria-valuenow', String(score));
      meterFill.style.width = `${(score / rules.length) * 100}%`;
      strengthLabel.textContent = password.length === 0
        ? 'Use 8 or more characters, an uppercase letter, and a symbol.'
        : score === rules.length
          ? 'Password meets all requirements.'
          : 'Add the remaining requirements to strengthen your password.';
    });
  }

  profilePage.querySelectorAll('[data-profile-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const action = form.dataset.profileForm;

      if (action === 'change_password') {
        const newPassword = form.querySelector('[name="new_password"]');

        if (newPassword) {
          const password = newPassword.value;
          if (password.length < 8 || password.length > 72) {
            showStatus('Your new password must be between 8 and 72 characters.', 'danger');
            newPassword.focus();
           return;
          }
          if (!/[A-Z]/.test(password)) {
            showStatus('Password must contain at least one uppercase letter.', 'danger');
            newPassword.focus();
            return;
          }
          if (!/[^A-Za-z0-9]/.test(password)) {
            showStatus('Password must contain at least one special character.', 'danger');
            newPassword.focus();
            return;
          }
          const confirmation = form.querySelector('[name="password_confirmation"]');
          if (confirmation && password !== confirmation.value) {
            showStatus('The new passwords do not match.', 'danger');
            confirmation.focus();
            return;
          }
        }
      }
      if (!form.reportValidity()) return;

      const button = form.querySelector('button[type="submit"]');
      const originalLabel = button.textContent;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      status.hidden = true;

      try {
        const formData = new FormData(form);
        formData.set('action', form.dataset.profileForm);
        const response = await fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          body: formData,
        });
        const result = await response.json();
        if (!response.ok || result.status !== 'success') {
          throw new Error(result.message || 'Your profile could not be updated.');
        }

        if (form.dataset.profileForm === 'upload_avatar') {
          profilePage.querySelector('[data-profile-avatar]').replaceChildren(
            makeAvatar(
              profilePage.querySelector('[data-profile-name]').textContent,
              result.photo_url,
              true,
            ),
          );
        } else {
          form.reset();
        }
        showStatus(result.message, 'success');
      } catch (error) {
        showStatus(error instanceof Error ? error.message : 'Your profile could not be updated. Please try again.', 'danger');
      } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.textContent = originalLabel;
      }
    });
  });

  profilePage.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = profilePage.querySelector(`#${CSS.escape(button.getAttribute('aria-controls'))}`);
      if (!input) return;

      const isVisible = input.type === 'text';
      input.type = isVisible ? 'password' : 'text';
      button.setAttribute('aria-pressed', String(!isVisible));
      button.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
      button.title = isVisible ? 'Show password' : 'Hide password';
    });
  });

  loadProfile();
}
