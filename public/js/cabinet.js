(function () {
  const state = {
    user: null,
  };

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

  const editButton = $('#editBtn');
  const cancelButton = $('#cancelBtn');
  const userDetails = $('#userDetails');
  const sidebarItems = $$('.nav-item[data-target]');
  const tabContents = $$('.tab-content');
  const desktopProfile = $('.profile');
  const subscribeButton = $('.subscribe-btn');
  const mobileAvatar = $('.mobile-avatar');
  const logoutLink = $('.nav-item.logout');
  const avatarInput = $('#profileAvatar');
  const avatarUploadButton = $('.avatar-upload-btn');
  const avatarUploadHint = $('#avatarUploadHint');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const defaultAvatar = 'img/avatars/default-neutral.svg';
  const legacyDefaultAvatar = 'img/people/image_2025-11-10_00-02-43.png';

  function normalizeAvatar(avatar) {
    return avatar === legacyDefaultAvatar || !avatar ? defaultAvatar : avatar;
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

  function postMultipart(url, data) {
    return fetchJson(url, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken,
      },
      body: data,
    });
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (symbol) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[symbol]));
  }

  function showNotice(message, type = 'success', duration = 2200) {
    if (!window.AkinoToast) {
      return;
    }

    if (!message) {
      window.AkinoToast.hide();
      return;
    }

    window.AkinoToast.show(message, type, duration);
  }

  function setAvatarUploadState(isUploading, message = '', type = '') {
    if (avatarInput) {
      avatarInput.disabled = isUploading;
    }

    if (avatarUploadButton) {
      avatarUploadButton.classList.toggle('is-uploading', isUploading);
      avatarUploadButton.textContent = isUploading ? 'Загружаем...' : 'Загрузить аватарку';
    }

    if (avatarUploadHint) {
      avatarUploadHint.textContent = message || 'JPG, PNG или WEBP до 2 МБ';
      avatarUploadHint.classList.toggle('is-error', type === 'error');
      avatarUploadHint.classList.toggle('is-success', type === 'success');
    }
  }

  function openTab(tabId, syncUrl = true) {
    sidebarItems.forEach((item) => {
      item.classList.toggle('active', item.dataset.target === tabId);
    });

    tabContents.forEach((tab) => {
      tab.classList.toggle('active', tab.id === tabId);
    });

    if (syncUrl) {
      const params = new URLSearchParams(window.location.search);
      params.set('tab', tabId.replace(/^tab-/, ''));
      const query = params.toString();
      const nextUrl = query ? `${window.location.pathname}?${query}` : window.location.pathname;
      window.history.replaceState({}, '', nextUrl);
    }
  }

  function toggleEditMode(isEditing) {
    if (!editButton || !userDetails) {
      return;
    }

    userDetails.classList.toggle('is-editing', isEditing);
    editButton.textContent = isEditing ? 'Сохранить изменения' : 'Редактировать профиль';
  }

  function setField(viewIndex, inputIndex, viewValue, inputValue) {
    const views = $$('.info-item.view-mode', userDetails);
    const inputs = $$('.edit-input', userDetails);

    if (views[viewIndex]) {
      views[viewIndex].textContent = viewValue;
    }

    if (inputs[inputIndex]) {
      inputs[inputIndex].value = inputValue;
    }
  }

  function syncHeader(user) {
    if (!desktopProfile || !subscribeButton) {
      return;
    }

    const profileLabel = $('span', desktopProfile);
    const profileImage = $('img', desktopProfile);
    const mobileAvatarImage = mobileAvatar ? $('img', mobileAvatar) : null;

    desktopProfile.href = 'Cabinet.php';
    profileLabel.textContent = user.name;
    profileImage.src = normalizeAvatar(user.avatar);
    subscribeButton.textContent = user.subscription.active ? 'Продлить' : 'Подписка';

    if (mobileAvatarImage) {
      mobileAvatarImage.src = normalizeAvatar(user.avatar);
    }
  }

  function renderSubscriptionSummary(user) {
    const box = $('#subscriptionContent');

    if (!box) {
      return;
    }

    box.innerHTML = `
      <p>${user.subscription.active ? `Ваша текущая подписка: <strong>${escapeHtml(user.subscription.name)}</strong>` : 'Подписка AKINO пока не активна.'}</p>
      <p>${user.subscription.active ? `Действует до: ${escapeHtml(user.subscription.endsAtDisplay)}` : `Стоимость: ${escapeHtml(user.subscription.priceDisplay)} за ${escapeHtml(String(user.subscription.durationDays))} дней.`}</p>
      <div class="subscription-action-row">
        <button class="edit-profile-btn" id="renewSubscriptionBtn">${user.subscription.active ? 'Продлить подписку' : 'Подключить подписку'}</button>
        ${user.subscription.active ? '<button class="cancel-subscription-btn" id="cancelSubscriptionBtn">Отменить подписку</button>' : ''}
      </div>
    `;

    const renewButton = $('#renewSubscriptionBtn');
    const cancelSubscriptionButton = $('#cancelSubscriptionBtn');

    if (renewButton) {
      renewButton.addEventListener('click', renewSubscription);
    }

    if (cancelSubscriptionButton) {
      cancelSubscriptionButton.addEventListener('click', cancelSubscription);
    }
  }

  function buildMediaCollectionMarkup(items, emptyText, detailsBuilder) {
    if (!items || !items.length) {
      return `<p class="account-media-empty">${escapeHtml(emptyText)}</p>`;
    }

    return `
      <div class="account-media-grid">
        ${items.map((item) => `
          <a href="${escapeHtml(item.url)}" class="account-media-card">
            <img src="${escapeHtml(item.cardPath)}" alt="${escapeHtml(item.title)}">
            <div class="account-media-meta">
              <h3>${escapeHtml(item.title)}</h3>
              <p>${escapeHtml(item.typeLabel)} | ${escapeHtml(String(item.releaseYear))} | ${escapeHtml(item.genre)}</p>
              <span class="account-media-badge">${detailsBuilder(item)}</span>
            </div>
          </a>
        `).join('')}
      </div>
    `;
  }

  function renderMediaCollection(containerId, items, emptyText, detailsBuilder) {
    const box = document.getElementById(containerId);

    if (!box) {
      return;
    }

    box.innerHTML = buildMediaCollectionMarkup(items, emptyText, detailsBuilder);
  }

  function buildContinueWatchingMarkup(items) {
    if (!items || !items.length) {
      return '';
    }

    return `
      <div class="account-subsection">
        <div class="account-subsection-head">
          <h2>Продолжить просмотр</h2>
          <span>Доступно на любом устройстве</span>
        </div>
        <div class="continue-watch-grid continue-watch-grid-account">
          ${items.map((item) => `
            <article class="continue-watch-card">
              <a href="${escapeHtml(item.continueUrl)}" class="continue-watch-link">
                <div class="continue-watch-media">
                  <img src="${escapeHtml(item.coverPath || item.cardPath)}" alt="${escapeHtml(item.title)}">
                  <div class="continue-watch-overlay">
                    <span class="continue-watch-kicker">${escapeHtml(item.episodeMeta || 'Смотреть')}</span>
                    <span class="continue-watch-action">${escapeHtml(item.actionLabel || 'Продолжить просмотр')}</span>
                  </div>
                </div>
                <div class="continue-watch-copy">
                  <h3>${escapeHtml(item.title)}</h3>
                  <p>${escapeHtml(item.statusLabel || '')}</p>
                  <div class="continue-watch-progress" aria-hidden="true">
                    <span style="width: ${Number(item.progressPercentWidth || 0).toFixed(2)}%;"></span>
                  </div>
                  <div class="continue-watch-meta">
                    <span>${escapeHtml(item.progressDisplay || '00:00 / 00:00')}</span>
                    <span>${escapeHtml(item.secondaryMeta || '')}</span>
                  </div>
                </div>
              </a>
            </article>
          `).join('')}
        </div>
      </div>
    `;
  }

  function renderHistory(items, continueItems = []) {
    const box = document.getElementById('historyContent');

    if (!box) {
      return;
    }

    const continueMarkup = buildContinueWatchingMarkup(continueItems);
    const historyMarkup = `
      <div class="account-subsection">
        <div class="account-subsection-head">
          <h2>Недавняя история</h2>
        </div>
        ${buildMediaCollectionMarkup(
          items,
          'Вы ещё не открывали фильмы или сериалы в аккаунте.',
          (item) => {
            const views = item.viewsCount > 1 ? ` | просмотров: ${item.viewsCount}` : '';
            return `Смотрели: ${escapeHtml(item.viewedAtDisplay || 'только что')}${views}`;
          }
        )}
      </div>
    `;

    box.innerHTML = `${continueMarkup}${historyMarkup}`;
  }

  function renderFavorites(items) {
    const box = document.getElementById('favoritesContent');

    if (!box) {
      return;
    }

    if (!items || !items.length) {
      box.innerHTML = '<p class="account-media-empty">В избранном пока ничего нет.</p>';
      return;
    }

    box.innerHTML = `
      <div class="account-media-grid">
        ${items.map((item) => `
          <article class="account-media-card account-favorite-card">
            <a href="${escapeHtml(item.url)}" class="account-media-cover-link">
              <img src="${escapeHtml(item.cardPath)}" alt="${escapeHtml(item.title)}">
            </a>
            <div class="account-media-meta">
              <a href="${escapeHtml(item.url)}" class="account-media-title-link">
                <h3>${escapeHtml(item.title)}</h3>
              </a>
              <p>${escapeHtml(item.typeLabel)} | ${escapeHtml(String(item.releaseYear))} | ${escapeHtml(item.genre)}</p>
              <div class="account-media-actions">
                <span class="account-media-badge">Добавлено: ${escapeHtml(item.favoritedAtDisplay || 'недавно')}</span>
                <button type="button" class="account-favorite-remove-btn" data-favorite-remove data-movie-id="${escapeHtml(String(item.id))}">
                  Убрать
                </button>
              </div>
            </div>
          </article>
        `).join('')}
      </div>
    `;
  }

  function syncFavoriteButtons(movieId, active) {
    $$(`[data-favorite-button][data-movie-id="${movieId}"]`).forEach((button) => {
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

  function renderUser(user) {
    state.user = user;
    syncHeader(user);

    const avatar = $('.avatar-img');
    const userName = $('.user-name');
    const subName = $('.sub-name');
    const subDate = $('.sub-date');
    const payment = $('.payment-link');

    if (avatar) {
      avatar.src = normalizeAvatar(user.avatar);
    }

    if (userName) {
      userName.textContent = user.name;
    }

    if (subName) {
      subName.textContent = user.subscription.name;
    }

    if (subDate) {
      subDate.textContent = user.subscription.active
        ? `(Активна до ${user.subscription.endsAtDisplay})`
        : '(Не активна)';
    }

    if (payment) {
      payment.textContent = `${user.subscription.name} / ${user.subscription.priceDisplay} / ${user.subscription.durationDays} дней`;
    }

    setField(0, 0, user.email || 'E-mail не указан', user.email || '');
    setField(1, 1, user.gender || 'Пол не указан', user.gender || '');
    setField(2, 2, user.phoneDisplay, user.phoneDisplay);
    setField(3, 3, user.birthDateDisplay || 'Дата рождения не указана', user.birthDateDisplay || '');

    renderSubscriptionSummary(user);
    renderHistory(user.history || [], user.continueWatching || []);
    renderFavorites(user.favorites || []);
  }

  function collectProfileData() {
    const inputs = $$('.edit-input', userDetails);

    return {
      email: inputs[0] ? inputs[0].value : '',
      gender: inputs[1] ? inputs[1].value : '',
      phone: inputs[2] ? inputs[2].value : '',
      birthDate: inputs[3] ? inputs[3].value : '',
    };
  }

  async function saveProfile() {
    editButton.setAttribute('disabled', 'disabled');

    try {
      const payload = await postForm('api/profile.php', collectProfileData());
      renderUser(payload.user);
      toggleEditMode(false);
      showNotice(payload.message, 'success');
    } catch (error) {
      showNotice(error.message, 'error', 2800);
    } finally {
      editButton.removeAttribute('disabled');
    }
  }

  async function uploadAvatar(file) {
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    const maxBytes = 2 * 1024 * 1024;

    if (!allowedTypes.includes(file.type)) {
      setAvatarUploadState(false, 'Поддерживаются JPG, PNG и WEBP.', 'error');
      showNotice('Поддерживаются JPG, PNG и WEBP.', 'error', 2800);
      return;
    }

    if (file.size > maxBytes) {
      setAvatarUploadState(false, 'Размер файла должен быть до 2 МБ.', 'error');
      showNotice('Размер файла должен быть до 2 МБ.', 'error', 2800);
      return;
    }

    const formData = new FormData();
    formData.append('action', 'avatar');
    formData.append('avatar', file);
    setAvatarUploadState(true, 'Загружаем аватарку...');

    try {
      const payload = await postMultipart('api/profile.php', formData);
      renderUser(payload.user);
      setAvatarUploadState(false, 'Аватарка обновлена.', 'success');
      showNotice(payload.message, 'success');
    } catch (error) {
      setAvatarUploadState(false, error.message, 'error');
      showNotice(error.message, 'error', 3200);
    } finally {
      if (avatarInput) {
        avatarInput.value = '';
      }
    }
  }

  async function renewSubscription() {
    const renewButton = $('#renewSubscriptionBtn');

    if (renewButton) {
      renewButton.setAttribute('disabled', 'disabled');
    }

    try {
      const payload = await postForm('api/subscription.php', {});
      renderUser(payload.user);
      openTab('tab-subscription');
      showNotice(payload.message, 'success');
    } catch (error) {
      if (error.status === 401) {
        window.location.href = 'Home.php?auth=required';
        return;
      }

      showNotice(error.message, 'error', 2800);
    } finally {
      if (renewButton) {
        renewButton.removeAttribute('disabled');
      }
    }
  }

  async function cancelSubscription() {
    const confirmed = window.confirm('Отменить подписку? Доступ к просмотру будет прекращён сразу.');

    if (!confirmed) {
      return;
    }

    const cancelSubscriptionButton = $('#cancelSubscriptionBtn');
    const renewButton = $('#renewSubscriptionBtn');

    cancelSubscriptionButton?.setAttribute('disabled', 'disabled');
    renewButton?.setAttribute('disabled', 'disabled');

    try {
      const payload = await postForm('api/subscription-cancel.php', {});
      renderUser(payload.user);
      openTab('tab-subscription');
      showNotice(payload.message, 'success', 3200);
    } catch (error) {
      if (error.status === 401) {
        window.location.href = 'Home.php?auth=required';
        return;
      }

      showNotice(error.message, 'error', 3600);
    } finally {
      cancelSubscriptionButton?.removeAttribute('disabled');
      renewButton?.removeAttribute('disabled');
    }
  }

  async function removeFavorite(movieId, triggerButton) {
    if (!movieId) {
      return;
    }

    if (triggerButton) {
      triggerButton.setAttribute('disabled', 'disabled');
    }

    try {
      const payload = await postForm('api/favorites.php', {
        movieId,
        action: 'remove',
      });

      syncFavoriteButtons(movieId, false);
      renderUser(payload.user);
      openTab('tab-favorites', false);
      showNotice(payload.message, 'success');
    } catch (error) {
      if (error.status === 401) {
        window.location.href = 'Home.php?auth=required';
        return;
      }

      showNotice(error.message, 'error', 2800);
    } finally {
      if (triggerButton) {
        triggerButton.removeAttribute('disabled');
      }
    }
  }

  function bindTabs() {
    sidebarItems.forEach((item) => {
      item.addEventListener('click', (event) => {
        event.preventDefault();
        openTab(item.dataset.target);
      });
    });
  }

  function bindProfileActions() {
    if (editButton) {
      editButton.addEventListener('click', async () => {
        const isEditing = userDetails.classList.contains('is-editing');

        if (isEditing) {
          await saveProfile();
        } else {
          showNotice('');
          toggleEditMode(true);
        }
      });
    }

    if (cancelButton) {
      cancelButton.addEventListener('click', () => {
        if (state.user) {
          renderUser(state.user);
        }

        toggleEditMode(false);
        showNotice('');
      });
    }

    if (subscribeButton) {
      subscribeButton.addEventListener('click', (event) => {
        event.preventDefault();
        renewSubscription();
      });
    }

    if (logoutLink) {
      logoutLink.addEventListener('click', (event) => {
        event.preventDefault();
        window.location.href = logoutLink.href;
      });
    }

    if (avatarInput) {
      avatarInput.addEventListener('change', () => {
        const file = avatarInput.files && avatarInput.files[0];

        if (!file) {
          return;
        }

        uploadAvatar(file);
      });
    }

    document.addEventListener('click', (event) => {
      const removeButton = event.target.closest('[data-favorite-remove]');

      if (!removeButton) {
        return;
      }

      event.preventDefault();
      removeFavorite(removeButton.dataset.movieId || '', removeButton);
    });

    document.addEventListener('akino:favorites-updated', (event) => {
      const payload = event.detail || {};

      if (!payload.user) {
        return;
      }

      renderUser(payload.user);

      if (payload.movie && payload.movie.id) {
        syncFavoriteButtons(String(payload.movie.id), Boolean(payload.active));
      }
    });
  }

  function applyQueryState() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');

    if (tab) {
      openTab(`tab-${tab}`, false);
    }

    if (params.get('subscribed') === '1') {
      showNotice('Подписка AKINO успешно активирована.', 'success');
    }
  }

  async function loadProfile() {
    try {
      const payload = await fetchJson('api/profile.php');
      renderUser(payload.user);
      applyQueryState();
    } catch (error) {
      if (error.status === 401) {
        window.location.href = 'Home.php?auth=required';
        return;
      }

      showNotice(error.message, 'error', 2800);
    }
  }

  async function init() {
    bindTabs();
    bindProfileActions();
    toggleEditMode(false);
    await loadProfile();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
