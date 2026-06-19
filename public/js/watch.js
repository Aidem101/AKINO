(function () {
  const state = window.AKINO_WATCH_STATE || null;
  const player = document.getElementById('akinoPlayer');
  const progressFill = document.getElementById('watchProgressFill');
  const progressValue = document.getElementById('watchProgressValue');
  const saveState = document.getElementById('watchSaveState');
  const endOverlay = document.getElementById('watchEndOverlay');
  const cancelAutoNext = document.getElementById('watchCancelAutoNext');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  if (!state || !state.hasAccess || !player) {
    return;
  }

  let resumeApplied = false;
  let lastSavedPosition = Number(state.progress?.positionSeconds || 0);
  let saveInFlight = false;
  let pendingSave = false;
  let autoNextTimer = null;

  function formatTime(seconds) {
    const total = Math.max(0, Math.floor(seconds || 0));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const remainder = total % 60;

    if (hours > 0) {
      return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
    }

    return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
  }

  function renderProgress(current, duration) {
    const safeDuration = Number.isFinite(duration) && duration > 0 ? duration : Number(state.progress?.durationSeconds || 0);
    const percent = safeDuration > 0 ? Math.min(100, (current / safeDuration) * 100) : 0;

    if (progressFill) {
      progressFill.style.width = `${percent}%`;
    }

    if (progressValue) {
      progressValue.textContent = `${formatTime(current)} / ${formatTime(safeDuration)}`;
    }
  }

  function renderSavedAt(progress) {
    if (!saveState) {
      return;
    }

    if (progress?.updatedAtDisplay) {
      saveState.textContent = `Сохранено ${progress.updatedAtDisplay}`;
      return;
    }

    saveState.textContent = 'Прогресс ещё не сохранён';
  }

  function setSaveState(message) {
    if (saveState) {
      saveState.textContent = message;
    }
  }

  function showEndOverlay() {
    if (!endOverlay) {
      return;
    }

    endOverlay.hidden = false;

    if (state.nextEpisodeUrl) {
      autoNextTimer = window.setTimeout(() => {
        window.location.href = state.nextEpisodeUrl;
      }, 8000);
    }
  }

  function cancelAutoNextTimer() {
    if (autoNextTimer) {
      window.clearTimeout(autoNextTimer);
      autoNextTimer = null;
    }

    if (window.AkinoToast) {
      window.AkinoToast.show('Автопереход остановлен.', 'success', 1800);
    }
  }

  async function sendProgress(force = false, keepalive = false) {
    const current = Math.floor(player.currentTime || 0);
    const duration = Math.floor(Number.isFinite(player.duration) ? player.duration : Number(state.progress?.durationSeconds || 0));

    if (!force && Math.abs(current - lastSavedPosition) < 10) {
      return;
    }

    if (saveInFlight && !keepalive) {
      pendingSave = true;
      return;
    }

    saveInFlight = !keepalive;

    try {
      if (!keepalive) {
        setSaveState('Сохраняем прогресс...');
      }

      const response = await fetch('api/progress.php', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive,
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': csrfToken,
        },
        body: new URLSearchParams({
          movieId: String(state.movieId || 0),
          episodeId: String(state.episodeId || 0),
          positionSeconds: String(current),
          durationSeconds: String(duration),
        }),
      });

      const payload = await response.json().catch(() => null);

      if (!response.ok || !payload || payload.ok === false) {
        if (!keepalive && window.AkinoToast) {
          window.AkinoToast.show(payload?.message || 'Не удалось сохранить прогресс.', 'error', 2600);
        }
        return;
      }

      lastSavedPosition = current;
      renderSavedAt(payload.progress);
    } catch (error) {
      if (!keepalive && window.AkinoToast) {
        window.AkinoToast.show('Не удалось сохранить прогресс.', 'error', 2600);
      }
    } finally {
      if (!keepalive) {
        saveInFlight = false;
      }

      if (pendingSave && !keepalive) {
        pendingSave = false;
        sendProgress(false, false);
      }
    }
  }

  function applyResume() {
    if (resumeApplied) {
      return;
    }

    const savedPosition = Number(state.progress?.positionSeconds || 0);

    if (savedPosition > 15 && Number.isFinite(player.duration) && savedPosition < player.duration - 5) {
      player.currentTime = savedPosition;
    }

    resumeApplied = true;
    renderProgress(player.currentTime || 0, player.duration || Number(state.progress?.durationSeconds || 0));
    renderSavedAt(state.progress || null);
  }

  player.addEventListener('loadedmetadata', applyResume);

  player.addEventListener('timeupdate', () => {
    renderProgress(player.currentTime || 0, player.duration || 0);
  });

  player.addEventListener('pause', () => {
    sendProgress(true, false);
  });

  player.addEventListener('ended', () => {
    renderProgress(player.duration || 0, player.duration || 0);
    sendProgress(true, false);
    showEndOverlay();

    if (state.nextEpisodeUrl && window.AkinoToast) {
      window.AkinoToast.show('Серия завершена. Можно переходить к следующей.', 'success', 2600);
    }
  });

  player.addEventListener('error', () => {
    if (window.AkinoToast) {
      window.AkinoToast.show('Video could not be loaded. Check the media URL.', 'error', 3600);
    }
  });

  document.addEventListener('keydown', (event) => {
    const tagName = (event.target?.tagName || '').toLowerCase();

    if (tagName === 'input' || tagName === 'textarea' || event.target?.isContentEditable) {
      return;
    }

    if (event.key === ' ' || event.key === 'k') {
      event.preventDefault();

      if (player.paused) {
        player.play().catch(() => {});
      } else {
        player.pause();
      }
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      player.currentTime = Math.min((player.duration || player.currentTime + 10), player.currentTime + 10);
    } else if (event.key === 'ArrowLeft') {
      event.preventDefault();
      player.currentTime = Math.max(0, player.currentTime - 10);
    } else if (event.key === 'f') {
      event.preventDefault();

      if (document.fullscreenElement) {
        document.exitFullscreen().catch(() => {});
      } else {
        player.requestFullscreen?.();
      }
    } else if (event.key === 'm') {
      event.preventDefault();
      player.muted = !player.muted;
    }
  });

  if (cancelAutoNext) {
    cancelAutoNext.addEventListener('click', cancelAutoNextTimer);
  }

  window.setInterval(() => {
    if (!player.paused && !player.ended) {
      sendProgress(false, false);
    }
  }, 12000);

  window.addEventListener('pagehide', () => {
    sendProgress(true, true);
  });

  renderSavedAt(state.progress || null);
  renderProgress(Number(state.progress?.positionSeconds || 0), Number(state.progress?.durationSeconds || 0));
})();
