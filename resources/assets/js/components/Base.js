export default class Base {

  constructor ($element, Tooltip) {
    this.Tooltip = Tooltip;
    this.initAppData();
    this.initBootstrapTooltips();
  }

  initAppData () {
    // Load data passed by the backend to the JS
    let data = document.querySelector('meta[property="la-app-data"]')?.getAttribute('content');
    if (data) {
      window.appData = JSON.parse(data);
    }
  }

  initBootstrapTooltips () {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    tooltipTriggerList.map((tooltipTriggerEl) => new this.Tooltip(tooltipTriggerEl))
  }
}
