(function () {
  let floatingFeedback = null;
  let feedbackTimer = null;
  let hideTimer = null;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const cookieConsentKey = 'akinoCookieConsent';

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
        'X-CSRF-Token': csrfToken,
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

  function initMiniTracks() {
    document.querySelectorAll('.film-mini [data-mini-carousel]').forEach((carousel) => {
      const track = carousel.querySelector('[data-mini-track]');
      const previousButton = carousel.querySelector('[data-mini-prev]');
      const nextButton = carousel.querySelector('[data-mini-next]');

      if (!track || !previousButton || !nextButton) {
        return;
      }

      let scrollFrame = null;

      const updateControls = () => {
        const maxScrollLeft = Math.max(0, track.scrollWidth - track.clientWidth);
        const isScrollable = maxScrollLeft > 2;

        carousel.classList.toggle('is-scrollable', isScrollable);
        previousButton.disabled = !isScrollable || track.scrollLeft <= 2;
        nextButton.disabled = !isScrollable || track.scrollLeft >= maxScrollLeft - 2;
      };

      const scheduleControlsUpdate = () => {
        if (scrollFrame !== null) {
          return;
        }

        scrollFrame = window.requestAnimationFrame(() => {
          scrollFrame = null;
          updateControls();
        });
      };

      const scrollTrack = (direction) => {
        track.scrollBy({
          left: direction * Math.max(280, track.clientWidth * 0.88),
          behavior: 'smooth',
        });
      };

      previousButton.addEventListener('click', () => scrollTrack(-1));
      nextButton.addEventListener('click', () => scrollTrack(1));
      track.addEventListener('scroll', scheduleControlsUpdate, { passive: true });

      if ('ResizeObserver' in window) {
        const resizeObserver = new ResizeObserver(scheduleControlsUpdate);
        resizeObserver.observe(track);
      } else {
        window.addEventListener('resize', scheduleControlsUpdate);
      }

      updateControls();
    });
  }

  function hasCookieConsent() {
    try {
      if (window.localStorage.getItem(cookieConsentKey) === '1') {
        return true;
      }
    } catch (error) {
      // localStorage can be disabled; the consent cookie below is the fallback.
    }

    return document.cookie
      .split(';')
      .some((item) => item.trim() === 'akino_cookie_consent=1');
  }

  function rememberCookieConsent() {
    try {
      window.localStorage.setItem(cookieConsentKey, '1');
    } catch (error) {
      // Ignore storage errors and still set the browser cookie.
    }

    document.cookie = 'akino_cookie_consent=1; Max-Age=31536000; Path=/; SameSite=Lax';
  }

  function initCookieBanner() {
    const banner = document.getElementById('cookieBanner');
    const acceptButton = document.getElementById('cookieAcceptBtn');

    if (!banner || !acceptButton || hasCookieConsent()) {
      return;
    }

    banner.hidden = false;
    window.requestAnimationFrame(() => {
      banner.classList.add('is-visible');
    });

    acceptButton.addEventListener('click', () => {
      rememberCookieConsent();
      banner.classList.remove('is-visible');
      window.setTimeout(() => {
        banner.hidden = true;
      }, 220);
    });
  }

  function initHeaderScrollState() {
    const header = document.querySelector('.main-header');

    if (!header) {
      return;
    }

    const isHomeHeader = document.body.classList.contains('Black_page');
    const scrollRange = 160;
    let currentProgress = 0;
    let targetProgress = 0;
    let animationFrame = null;

    const getTargetProgress = () => {
      const rawProgress = Math.max(0, Math.min(window.scrollY / scrollRange, 1));

      return rawProgress * rawProgress * (3 - (2 * rawProgress));
    };

    const applyHeaderProgress = (progress) => {
      header.style.setProperty('--akino-header-bg-opacity', (0.94 * progress).toFixed(3));
      header.style.setProperty('--akino-header-border-opacity', (0.18 * progress).toFixed(3));
      header.style.setProperty('--akino-header-shadow-opacity', (0.35 * progress).toFixed(3));
      header.style.setProperty('--akino-header-blur', `${(12 * progress).toFixed(2)}px`);
      header.classList.toggle('is-scrolled', progress > 0.01);
    };

    const animateHeader = () => {
      const distance = targetProgress - currentProgress;

      if (Math.abs(distance) < 0.003) {
        currentProgress = targetProgress;
        applyHeaderProgress(currentProgress);
        animationFrame = null;
        return;
      }

      currentProgress += distance * 0.22;
      applyHeaderProgress(currentProgress);
      animationFrame = window.requestAnimationFrame(animateHeader);
    };

    const syncHeaderState = () => {
      if (!isHomeHeader) {
        header.classList.toggle('is-scrolled', window.scrollY > 12);
        return;
      }

      targetProgress = getTargetProgress();

      if (animationFrame === null) {
        animationFrame = window.requestAnimationFrame(animateHeader);
      }
    };

    currentProgress = isHomeHeader ? getTargetProgress() : 1;
    targetProgress = currentProgress;
    applyHeaderProgress(currentProgress);
    window.addEventListener('scroll', syncHeaderState, { passive: true });
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
    initHeaderScrollState();
    initFavoriteButtons();
    initMiniTracks();
    initCookieBanner();
  }

  initGlobalApi();
  document.addEventListener('DOMContentLoaded', init);
})();
