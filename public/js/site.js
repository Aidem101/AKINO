(function () {
  let floatingFeedback = null;
  let feedbackTimer = null;
  let hideTimer = null;

  function initMenu() {
    const openButton = document.getElementById('openMenu');
    const closeButton = document.getElementById('closeMenu');
    const menu = document.getElementById('mobileMenu');

    if (!menu) {
      return;
    }

    if (openButton) {
      openButton.addEventListener('click', () => {
        menu.classList.add('open');
        document.body.style.overflow = 'hidden';
      });
    }

    if (closeButton) {
      closeButton.addEventListener('click', () => {
        menu.classList.remove('open');
        document.body.style.overflow = '';
      });
    }
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'same-origin',
      ...options,
    });

    const payload = await response.json().catch(() => ({
      ok: false,
      message: 'Сервер вернул некорректный ответ.',
    }));

    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.message || 'Не удалось выполнить запрос.');
      error.status = response.status;
      throw error;
    }

    return payload;
  }

  function postForm(url, data) {
    return fetchJson(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: new URLSearchParams(data),
    });
  }

  function ensureFloatingFeedback() {
    if (floatingFeedback) {
      return floatingFeedback;
    }

    floatingFeedback = document.createElement('div');
    floatingFeedback.className = 'site-floating-feedback';
    floatingFeedback.hidden = true;
    document.body.appendChild(floatingFeedback);

    return floatingFeedback;
  }

  function hideFloatingFeedback() {
    const node = ensureFloatingFeedback();

    if (feedbackTimer) {
      window.clearTimeout(feedbackTimer);
      feedbackTimer = null;
    }

    if (hideTimer) {
      window.clearTimeout(hideTimer);
      hideTimer = null;
    }

    node.classList.remove('is-visible');
    hideTimer = window.setTimeout(() => {
      node.hidden = true;
      node.textContent = '';
      node.className = 'site-floating-feedback';
    }, 220);
  }

  function showFloatingFeedback(message, type = 'success', duration = 2200) {
    if (!message) {
      hideFloatingFeedback();
      return;
    }

    const node = ensureFloatingFeedback();

    if (feedbackTimer) {
      window.clearTimeout(feedbackTimer);
    }

    if (hideTimer) {
      window.clearTimeout(hideTimer);
    }

    node.hidden = false;
    node.textContent = message;
    node.className = `site-floating-feedback is-${type}`;
    window.requestAnimationFrame(() => {
      node.classList.add('is-visible');
    });

    feedbackTimer = window.setTimeout(() => {
      hideFloatingFeedback();
    }, duration);
  }

  function showFeedback(message, type = 'success', duration = 2200) {
    showFloatingFeedback(message, type, duration);
  }

  function syncFavoriteButtons(movieId, active) {
    const selector = `[data-favorite-button][data-movie-id="${movieId}"]`;
    const buttons = document.querySelectorAll(selector);

    buttons.forEach((button) => {
      button.dataset.active = active ? '1' : '0';
      button.classList.toggle('is-active', active);

      const addLabel = button.dataset.addLabel || 'В избранное';
      const addedLabel = button.dataset.addedLabel || 'В избранном';
      const label = active ? addedLabel : addLabel;
      const ariaLabel = active ? 'Убрать из избранного' : 'Добавить в избранное';

      button.setAttribute('aria-label', ariaLabel);
      button.setAttribute('title', ariaLabel);

      if (button.classList.contains('hero-favorite-btn')) {
        button.textContent = label;
      }
    });
  }

  async function handleFavoriteToggle(button) {
    const isAuthenticated = document.body.dataset.authenticated === '1';

    if (!isAuthenticated) {
      window.location.href = 'Home.php?auth=required';
      return;
    }

    const movieId = button.dataset.movieId || '';

    if (!movieId) {
      return;
    }

    button.setAttribute('disabled', 'disabled');

    try {
      const payload = await postForm('api/favorites.php', { movieId });
      syncFavoriteButtons(movieId, Boolean(payload.active));
      document.dispatchEvent(new CustomEvent('akino:favorites-updated', {
        detail: payload,
      }));
      showFeedback(payload.message, 'success');
    } catch (error) {
      if (error.status === 401) {
        window.location.href = 'Home.php?auth=required';
        return;
      }

      showFeedback(error.message, 'error', 2800);
    } finally {
      button.removeAttribute('disabled');
    }
  }

  function initFavoriteButtons() {
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-favorite-button]');

      if (!button) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      handleFavoriteToggle(button);
    });
  }

  function initGlobalApi() {
    window.AkinoToast = {
      show(message, type = 'success', duration = 2200) {
        showFloatingFeedback(message, type, duration);
      },
      hide() {
        hideFloatingFeedback();
      },
    };
  }

  function init() {
    initMenu();
    initFavoriteButtons();
  }

  initGlobalApi();
  document.addEventListener('DOMContentLoaded', init);
})();
