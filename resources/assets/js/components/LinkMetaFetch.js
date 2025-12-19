export default class LinkMetaFetch {

  constructor ($el) {
    this.$button = $el;
    this.$url = document.querySelector(this.$button.dataset.urlTarget ?? '#url');
    this.$title = document.querySelector(this.$button.dataset.titleTarget ?? '#title');
    this.$description = document.querySelector(this.$button.dataset.descriptionTarget ?? '#description');
    this.$status = this.$button.dataset.statusTarget
      ? document.querySelector(this.$button.dataset.statusTarget)
      : null;
    this.originalText = this.$button.textContent;

    if (!this.$url || !this.$title || !this.$description) {
      return;
    }

    this.$button.addEventListener('click', this.onClick.bind(this));
  }

  onClick () {
    const url = this.$url.value.trim();

    if (!url) {
      this.setStatus(this.$button.dataset.missingUrlText, 'danger');
      return;
    }

    this.setLoading(true);
    this.setStatus(this.$button.dataset.loadingText, 'muted');

    fetch(window.appData.routes.fetch.metaForUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        _token: window.appData.user.token,
        url: url
      })
    })
      .then(response => response.json())
      .then(data => {
        if (data.success !== true) {
          this.setStatus(this.$button.dataset.errorText, 'danger');
          return;
        }

        if (data.title) {
          this.$title.value = data.title;
        }

        if (data.description !== null) {
          this.$description.value = data.description;
        }

        this.setStatus(this.$button.dataset.successText, 'success');
      })
      .catch(() => {
        this.setStatus(this.$button.dataset.errorText, 'danger');
      })
      .finally(() => {
        this.setLoading(false);
      });
  }

  setLoading (isLoading) {
    this.$button.disabled = isLoading;
    if (isLoading && this.$button.dataset.loadingLabel) {
      this.$button.textContent = this.$button.dataset.loadingLabel;
    } else {
      this.$button.textContent = this.originalText;
    }
  }

  setStatus (message, type) {
    if (!this.$status || !message) {
      return;
    }

    this.$status.classList.remove('d-none', 'text-danger', 'text-success', 'text-muted');
    this.$status.classList.add(`text-${type}`);
    this.$status.textContent = message;
  }
}
