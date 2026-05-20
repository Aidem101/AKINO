(function () {
  function initDropdowns() {
    const dropdowns = document.querySelectorAll('.custom-dropdown');

    if (!dropdowns.length) {
      return;
    }

    dropdowns.forEach((dropdown) => {
      const trigger = dropdown.querySelector('.dropdown-trigger');
      const triggerText = dropdown.querySelector('.dropdown-trigger-text');
      const items = dropdown.querySelectorAll('.dropdown-menu li');
      const filterKey = dropdown.dataset.filterKey || '';
      const form = dropdown.closest('form');
      const input = filterKey && form
        ? form.querySelector(`[data-filter-input="${filterKey}"]`)
        : null;

      if (!trigger) {
        return;
      }

      trigger.addEventListener('click', (event) => {
        event.stopPropagation();

        dropdowns.forEach((other) => {
          if (other !== dropdown) {
            other.classList.remove('active');
          }
        });

        dropdown.classList.toggle('active');
      });

      items.forEach((item) => {
        item.addEventListener('click', (event) => {
          event.stopPropagation();

          if (input) {
            input.value = item.dataset.value || '';
          }

          items.forEach((other) => {
            other.classList.toggle('is-selected', other === item);
          });

          if (triggerText) {
            triggerText.textContent = item.dataset.label || item.textContent.trim();
          }

          dropdown.classList.remove('active');
        });
      });
    });

    document.addEventListener('click', () => {
      dropdowns.forEach((dropdown) => dropdown.classList.remove('active'));
    });
  }

  document.addEventListener('DOMContentLoaded', initDropdowns);
})();
