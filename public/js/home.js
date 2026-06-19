(function () {
  const state = {
    intent: 'login',
    requestId: null,
    phone: '',
    user: null,
  };

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  const modal = $('#loginModal');
  const stepOne = $('#step-1');
  const stepTwo = $('#step-2');
  const phoneForm = $('#phoneForm');
  const codeForm = $('#codeForm');
  const phoneInput = $('#userPhoneInput');
  const displayPhone = $('#displayPhone');
  const digitInputs = $$('.digit-input');
  const feedback = $('#authFeedback');
  const demoCode = $('#authDemoCode');
  const resendLink = $('.resend-link');
  const desktopProfile = $('.profile');
  const mobileAvatar = $('.mobile-avatar');
  const defaultAvatar = 'img/avatars/default-neutral.svg';
  const legacyDefaultAvatar = 'img/people/image_2025-11-10_00-02-43.png';
  const subscribeButton = $('.subscribe-btn');
  const closeButton = modal ? $('.clos-but', modal) : null;
  const menuLinks = $$('.menu-link');
  const cabinetMenuLink = menuLinks.find((link) => link.querySelector('.fa-user'));
  const subscriptionMenuLink = menuLinks.find((link) => link.querySelector('.fa-bag-shopping'));
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

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
      const error = new Error(payload.message || 'Запрос завершился ошибкой.');
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

  function setFeedback(message, type = 'success', duration = 2200) {
    if (feedback) {
      feedback.hidden = true;
      feedback.textContent = '';
      feedback.className = 'auth-feedback';
    }

    if (!message || !window.AkinoToast) {
      return;
    }

    window.AkinoToast.show(message, type, duration);
  }

  function setDemoCode(code) {
    if (!demoCode) {
      return;
    }

    if (!code) {
      demoCode.hidden = true;
      demoCode.innerHTML = '';
      return;
    }

    demoCode.hidden = false;
    demoCode.innerHTML = `Тестовый код для локального входа: <strong>${code}</strong>`;
  }

  function resetCodeInputs() {
    digitInputs.forEach((input) => {
      input.value = '';
      input.classList.remove('filled');
    });
  }

  function resetModal() {
    state.requestId = null;
    stepOne.hidden = false;
    stepTwo.hidden = true;
    phoneForm.reset();
    codeForm.reset();
    resetCodeInputs();
    setFeedback('');
    setDemoCode('');
  }

  function openModal(intent = 'login') {
    if (!modal) {
      return;
    }

    state.intent = intent;
    resetModal();
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    phoneInput.focus();
  }

  function closeModal() {
    if (!modal) {
      return;
    }

    modal.classList.remove('open');
    document.body.style.overflow = '';
  }

  function syncHeader() {
    if (!desktopProfile || !subscribeButton) {
      return;
    }

    const profileLabel = $('span', desktopProfile);
    const profileImage = $('img', desktopProfile);
    const mobileAvatarImage = mobileAvatar ? $('img', mobileAvatar) : null;

    if (state.user) {
      const avatar = state.user.avatar === legacyDefaultAvatar ? defaultAvatar : state.user.avatar;

      desktopProfile.href = 'Cabinet.php';
      desktopProfile.dataset.authenticated = '1';
      profileLabel.textContent = state.user.name;
      profileImage.src = avatar;

      if (mobileAvatarImage) {
        mobileAvatarImage.src = avatar;
      }

      subscribeButton.textContent = state.user.subscription.active ? 'Продлить' : 'Подписка';

      if (cabinetMenuLink) {
        cabinetMenuLink.href = 'Cabinet.php';
      }
    } else {
      desktopProfile.href = '#';
      desktopProfile.dataset.authenticated = '0';
      profileLabel.textContent = 'Логин';
      profileImage.src = defaultAvatar;

      if (mobileAvatarImage) {
        mobileAvatarImage.src = defaultAvatar;
      }

      subscribeButton.textContent = 'Подписка';

      if (cabinetMenuLink) {
        cabinetMenuLink.href = '#';
      }
    }
  }

  async function loadSession() {
    try {
      const payload = await fetchJson('api/session.php');
      state.user = payload.authenticated ? payload.user : null;
    } catch (error) {
      state.user = null;
    }

    syncHeader();
  }

  async function activateSubscription() {
    if (!state.user) {
      openModal('subscribe');
      return;
    }

    subscribeButton.setAttribute('disabled', 'disabled');

    try {
      await postForm('api/subscription.php', {});
      window.location.href = 'Cabinet.php?tab=subscription&subscribed=1';
    } catch (error) {
      if (error.status === 401) {
        state.user = null;
        syncHeader();
        openModal('subscribe');
        setFeedback('Сначала войдите в аккаунт.', 'error', 2800);
      } else {
        openModal('subscribe');
        setFeedback(error.message, 'error', 2800);
      }
    } finally {
      subscribeButton.removeAttribute('disabled');
    }
  }

  function initMenu() {
    const openButton = $('#openMenu');
    const closeMenuButton = $('#closeMenu');
    const menu = $('#mobileMenu');

    if (openButton && menu) {
      openButton.addEventListener('click', () => {
        menu.classList.add('open');
        document.body.style.overflow = 'hidden';
      });
    }

    if (closeMenuButton && menu) {
      closeMenuButton.addEventListener('click', () => {
        menu.classList.remove('open');
        document.body.style.overflow = '';
      });
    }
  }

  function initSlider() {
    const track = $('.film-track');

    if (!track) {
      return;
    }

    const cards = Array.from(track.children);
    const total = cards.length;

    if (!total) {
      return;
    }

    const slider = track.parentElement;
    const isMobileSlider = () => window.innerWidth <= 700;
    const transition = 'transform 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94)';

    let currentPosition = 0;
    let isAnimating = false;
    let mode = null;
    let wheelHandler = null;
    let mobileScrollHandler = null;
    let mobileLoopWidth = 0;

    function centerMobileCard() {
      requestAnimationFrame(() => {
        const activeCard = cards[1] || cards[0];

        activeCard.scrollIntoView({
          block: 'nearest',
          inline: 'center',
        });
      });
    }

    function enableMobileSlider() {
      if (wheelHandler) {
        track.removeEventListener('wheel', wheelHandler);
        wheelHandler = null;
      }

      if (mobileScrollHandler) {
        track.removeEventListener('scroll', mobileScrollHandler);
        mobileScrollHandler = null;
      }

      track.replaceChildren(...cards);
      const clonesStart = cards.map((card) => card.cloneNode(true));
      const clonesEnd = cards.map((card) => card.cloneNode(true));
      track.prepend(...clonesStart);
      track.append(...clonesEnd);
      track.style.transition = 'none';
      track.style.transform = 'none';
      slider?.classList.add('is-native-mobile-slider');
      mode = 'mobile';
      mobileLoopWidth = track.children[total * 2].offsetLeft - track.children[total].offsetLeft;
      mobileScrollHandler = handleMobileScroll;
      track.addEventListener('scroll', mobileScrollHandler, { passive: true });
      centerMobileCard();
    }

    function enableDesktopSlider() {
      if (mobileScrollHandler) {
        track.removeEventListener('scroll', mobileScrollHandler);
        mobileScrollHandler = null;
      }

      if (mode === 'desktop') {
        updatePosition(false);
        return;
      }

      slider?.classList.remove('is-native-mobile-slider');
      track.replaceChildren(...cards);
      track.scrollLeft = 0;
      currentPosition = 0;
      isAnimating = false;

      const clonesStart = cards.map((card) => card.cloneNode(true));
      const clonesEnd = cards.map((card) => card.cloneNode(true));
      track.prepend(...clonesStart);
      track.append(...clonesEnd);

      if (!wheelHandler) {
        wheelHandler = handleSliderWheel;
        track.addEventListener('wheel', wheelHandler, { passive: false });
      }

      mode = 'desktop';
      updatePosition(false);
      requestAnimationFrame(() => {
        track.style.transition = transition;
      });
    }

    function updatePosition(withTransition = true) {
      const gap = parseFloat(getComputedStyle(track).gap || '0');
      const cardWidth = cards[0].offsetWidth;
      const cardStep = cardWidth + gap;
      const sliderWidth = track.parentElement?.clientWidth || window.innerWidth;
      const trackCenterOffset = (track.offsetWidth - sliderWidth) / 2;
      const visibleCards = Math.min(2, total);
      const visibleWidth = cardWidth * visibleCards + gap * (visibleCards - 1);
      const centerOffset = (sliderWidth - visibleWidth) / 2;
      const offset = trackCenterOffset - (currentPosition + total) * cardStep + centerOffset;

      track.style.transition = withTransition ? transition : 'none';
      if (!withTransition) {
        track.offsetWidth;
      }
      track.style.transform = `translateX(${offset}px)`;
      if (!withTransition) {
        track.offsetWidth;
      }
    }

    function normalizeLoop() {
      if (currentPosition <= -total) {
        currentPosition += total;
        updatePosition(false);
        requestAnimationFrame(() => {
          track.style.transition = transition;
        });
      } else if (currentPosition >= total) {
        currentPosition -= total;
        updatePosition(false);
        requestAnimationFrame(() => {
          track.style.transition = transition;
        });
      }
    }

    function handleSliderWheel(event) {
      event.preventDefault();

      if (isAnimating) {
        return;
      }

      isAnimating = true;
      const wheelDelta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;

      if (wheelDelta === 0) {
        isAnimating = false;
        return;
      }

      currentPosition += wheelDelta > 0 ? 1 : -1;
      updatePosition(true);

      window.setTimeout(() => {
        normalizeLoop();
        isAnimating = false;
      }, 620);
    }

    function handleMobileScroll() {
      if (mode !== 'mobile' || mobileLoopWidth <= 0) {
        return;
      }

      const maxScroll = track.scrollWidth - track.clientWidth;

      if (track.scrollLeft <= 1) {
        track.scrollLeft += mobileLoopWidth;
      } else if (track.scrollLeft >= maxScroll - 1) {
        track.scrollLeft -= mobileLoopWidth;
      }
    }

    if (isMobileSlider()) {
      enableMobileSlider();
    } else {
      enableDesktopSlider();
    }

    let resizeTimeout = null;
    window.addEventListener('resize', () => {
      window.clearTimeout(resizeTimeout);
      resizeTimeout = window.setTimeout(() => {
        if (isMobileSlider()) {
          enableMobileSlider();
        } else {
          enableDesktopSlider();
        }
      }, 200);
    });
  }

  function bindDigitInputs() {
    digitInputs.forEach((input, index) => {
      input.inputMode = 'numeric';

      input.addEventListener('input', () => {
        input.value = input.value.replace(/\D/g, '').slice(0, 1);

        if (input.value) {
          input.classList.add('filled');

          if (index < digitInputs.length - 1) {
            digitInputs[index + 1].focus();
          }
        } else {
          input.classList.remove('filled');
        }
      });

      input.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' && !input.value && index > 0) {
          digitInputs[index - 1].focus();
        }
      });
    });

    const codeInputs = $('.code-inputs');

    if (codeInputs) {
      codeInputs.addEventListener('paste', (event) => {
        const pasted = (event.clipboardData || window.clipboardData).getData('text');
        const digits = pasted.replace(/\D/g, '').slice(0, digitInputs.length).split('');

        if (!digits.length) {
          return;
        }

        event.preventDefault();
        digitInputs.forEach((input, index) => {
          input.value = digits[index] || '';
          input.classList.toggle('filled', Boolean(input.value));
        });

        const nextInput = digitInputs[Math.min(digits.length, digitInputs.length - 1)];

        if (nextInput) {
          nextInput.focus();
        }
      });
    }
  }

  function bindModal() {
    if (!modal || !phoneForm || !codeForm || !closeButton) {
      return;
    }

    let phoneRequestPending = false;
    let codeVerificationPending = false;

    function setFormPending(form, isPending) {
      const submitButton = form.querySelector('.login-btn');

      if (submitButton) {
        submitButton.disabled = isPending;
      }

      form.classList.toggle('is-pending', isPending);
    }

    closeButton.addEventListener('click', closeModal);

    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeModal();
      }
    });

    phoneForm.addEventListener('submit', async (event) => {
      event.preventDefault();

      if (phoneRequestPending) {
        return;
      }

      phoneRequestPending = true;
      setFormPending(phoneForm, true);

      try {
        const payload = await postForm('api/request-code.php', {
          phone: phoneInput.value,
          intent: state.intent,
        });

        state.requestId = payload.requestId;
        state.phone = payload.phone;
        displayPhone.textContent = payload.phone;
        stepOne.hidden = true;
        stepTwo.hidden = false;
        setFeedback(payload.message, 'success');
        setDemoCode(payload.demoCode);
        digitInputs[0].focus();
      } catch (error) {
        setFeedback(error.message, 'error', 2800);
        setDemoCode('');
      } finally {
        phoneRequestPending = false;
        setFormPending(phoneForm, false);
      }
    });

    codeForm.addEventListener('submit', async (event) => {
      event.preventDefault();

      if (codeVerificationPending) {
        return;
      }

      const code = digitInputs.map((input) => input.value).join('');

      if (!/^\d{4}$/.test(code) || !state.requestId) {
        setFeedback('Введите все 4 цифры кода.', 'error', 2800);
        return;
      }

      codeVerificationPending = true;
      setFormPending(codeForm, true);

      try {
        const payload = await postForm('api/verify-code.php', {
          requestId: String(state.requestId),
          code,
        });

        closeModal();
        window.location.href = payload.redirect || 'Cabinet.php';
      } catch (error) {
        setFeedback(error.message, 'error', 2800);
      } finally {
        codeVerificationPending = false;
        setFormPending(codeForm, false);
      }
    });

    if (resendLink) {
      resendLink.addEventListener('click', async (event) => {
        event.preventDefault();

        if (!state.phone) {
          setFeedback('Сначала запросите код заново.', 'error', 2800);
          return;
        }

        try {
          const payload = await postForm('api/request-code.php', {
            phone: state.phone,
            intent: state.intent,
          });

          state.requestId = payload.requestId;
          setFeedback('Новый код создан.', 'success');
          setDemoCode(payload.demoCode);
          resetCodeInputs();
          digitInputs[0].focus();
        } catch (error) {
          setFeedback(error.message, 'error', 2800);
        }
      });
    }
  }

  function bindTriggers() {
    if (desktopProfile) {
      desktopProfile.addEventListener('click', (event) => {
        if (!state.user) {
          event.preventDefault();
          openModal('login');
        }
      });
    }

    if (mobileAvatar) {
      mobileAvatar.setAttribute('role', 'button');
      mobileAvatar.setAttribute('tabindex', '0');
      mobileAvatar.addEventListener('click', () => {
        if (state.user) {
          window.location.href = 'Cabinet.php';
        } else {
          openModal('login');
        }
      });
      mobileAvatar.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();

          if (state.user) {
            window.location.href = 'Cabinet.php';
          } else {
            openModal('login');
          }
        }
      });
    }

    if (subscribeButton) {
      subscribeButton.addEventListener('click', (event) => {
        event.preventDefault();
        activateSubscription();
      });
    }

    if (cabinetMenuLink) {
      cabinetMenuLink.addEventListener('click', (event) => {
        if (!state.user) {
          event.preventDefault();
          openModal('login');
        }
      });
    }

    if (subscriptionMenuLink) {
      subscriptionMenuLink.addEventListener('click', (event) => {
        event.preventDefault();

        if (state.user) {
          activateSubscription();
        } else {
          openModal('subscribe');
        }
      });
    }
  }

  function applyQueryState() {
    const params = new URLSearchParams(window.location.search);

    if (!state.user && params.get('auth') === 'required') {
      openModal('login');
      setFeedback('Сначала войдите в аккаунт.', 'error', 2800);
    }
  }

  async function init() {
    initSlider();
    initMenu();
    bindDigitInputs();
    bindModal();
    bindTriggers();
    await loadSession();
    applyQueryState();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
