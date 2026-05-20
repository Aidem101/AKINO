(function () {
  const inputs = document.querySelectorAll('.search-box input[name="q"]');

  if (!inputs.length) {
    return;
  }

  const datalist = document.createElement('datalist');
  datalist.id = 'akinoSearchSuggestions';
  document.body.appendChild(datalist);

  let debounceTimer = null;
  let abortController = null;

  function renderSuggestions(items) {
    datalist.innerHTML = '';

    items.forEach((item) => {
      const option = document.createElement('option');
      const meta = [item.typeLabel, item.year, item.director].filter(Boolean).join(' · ');
      option.value = item.title || '';
      option.label = meta;
      datalist.appendChild(option);
    });
  }

  function scheduleLoad(query) {
    window.clearTimeout(debounceTimer);

    if (query.trim().length < 2) {
      renderSuggestions([]);
      return;
    }

    debounceTimer = window.setTimeout(async () => {
      if (abortController) {
        abortController.abort();
      }

      abortController = new AbortController();

      try {
        const response = await fetch(`api/search-suggestions.php?q=${encodeURIComponent(query)}`, {
          credentials: 'same-origin',
          signal: abortController.signal,
        });
        const payload = await response.json().catch(() => null);

        if (response.ok && payload?.ok) {
          renderSuggestions(payload.items || []);
        }
      } catch (error) {
        if (error.name !== 'AbortError') {
          renderSuggestions([]);
        }
      }
    }, 180);
  }

  inputs.forEach((input) => {
    input.setAttribute('list', datalist.id);
    input.addEventListener('input', () => scheduleLoad(input.value));
    scheduleLoad(input.value || '');
  });
})();
