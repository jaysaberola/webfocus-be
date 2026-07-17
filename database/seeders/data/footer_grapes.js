(function () {
  var yearEl = document.getElementById("wsi-footer-year");
  if (yearEl) {
    yearEl.textContent = String(new Date().getFullYear());
  }
})();
