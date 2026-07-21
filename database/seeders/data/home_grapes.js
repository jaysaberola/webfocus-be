(function () {
  if (window.__wsiHomeCmsInit) return;
  window.__wsiHomeCmsInit = true;

  function initWsiHomeSlider() {
    var track = document.getElementById('wsi-slider-track');
    var prevBtn = document.getElementById('wsi-slider-prev');
    var nextBtn = document.getElementById('wsi-slider-next');
    if (!track || !prevBtn || !nextBtn || !track.firstElementChild) return;

    var row = track.firstElementChild;
    var slides = row.children;
    if (!slides.length) return;

    var currentSlide = 0;
    var autoplayTimer = null;

    function scrollToSlide() {
      var slide = slides[currentSlide];
      if (!slide) return;
      track.scrollLeft = slide.offsetLeft - row.offsetLeft;
    }

    function nextSlide() {
      currentSlide = (currentSlide + 1) % slides.length;
      scrollToSlide();
    }

    function prevSlide() {
      currentSlide = (currentSlide - 1 + slides.length) % slides.length;
      scrollToSlide();
    }

    function resetAutoplay() {
      if (autoplayTimer) clearInterval(autoplayTimer);
      autoplayTimer = null;
    }

    nextBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      resetAutoplay();
      nextSlide();
    });

    prevBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      resetAutoplay();
      prevSlide();
    });

    window.addEventListener('resize', scrollToSlide);
    autoplayTimer = setInterval(nextSlide, 4000);
    scrollToSlide();
  }

  function initPortfolioPreview() {
    if (window.__wsiPortfolioModal) return;

    var triggers = Array.prototype.slice.call(document.querySelectorAll('.wsi-portfolio-zoom'));
    if (!triggers.length) return;

    var items = triggers.map(function (btn) {
      return {
        src: btn.getAttribute('data-src') || '',
        title: btn.getAttribute('data-title') || 'Portfolio preview',
      };
    });

    var modal = document.createElement('div');
    modal.className = 'wsi-portfolio-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML =
      '<div class="wsi-portfolio-modal-panel">' +
        '<div class="wsi-portfolio-modal-header">' +
          '<h3 class="wsi-portfolio-modal-title"></h3>' +
          '<button type="button" class="wsi-portfolio-modal-close" aria-label="Close preview">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>' +
          '</button>' +
        '</div>' +
        '<div class="wsi-portfolio-modal-body">' +
          '<div class="wsi-portfolio-modal-loader"><span></span></div>' +
          '<img alt="" />' +
        '</div>' +
        '<div class="wsi-portfolio-modal-footer">' +
          '<div class="wsi-portfolio-modal-nav">' +
            '<button type="button" class="wsi-portfolio-modal-prev" aria-label="Previous project">' +
              '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 19l-7-7 7-7"/></svg>' +
            '</button>' +
            '<button type="button" class="wsi-portfolio-modal-next" aria-label="Next project">' +
              '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5l7 7-7 7"/></svg>' +
            '</button>' +
          '</div>' +
          '<span class="wsi-portfolio-modal-counter"></span>' +
        '</div>' +
      '</div>';

    document.body.appendChild(modal);
    window.__wsiPortfolioModal = modal;

    var panelEl = modal.querySelector('.wsi-portfolio-modal-panel');
    var titleEl = modal.querySelector('.wsi-portfolio-modal-title');
    var imageEl = modal.querySelector('.wsi-portfolio-modal-body img');
    var loaderEl = modal.querySelector('.wsi-portfolio-modal-loader');
    var counterEl = modal.querySelector('.wsi-portfolio-modal-counter');
    var closeBtn = modal.querySelector('.wsi-portfolio-modal-close');
    var prevModalBtn = modal.querySelector('.wsi-portfolio-modal-prev');
    var nextModalBtn = modal.querySelector('.wsi-portfolio-modal-next');
    var currentIndex = 0;
    var lastTrigger = null;
    var closeTimer = null;
    var isClosing = false;

    function showLoader() {
      loaderEl.style.display = 'flex';
      imageEl.classList.remove('is-loaded');
    }

    function hideLoader() {
      loaderEl.style.display = 'none';
      imageEl.classList.add('is-loaded');
    }

    function render(index) {
      var item = items[index];
      if (!item) return;

      currentIndex = index;
      titleEl.textContent = item.title;
      counterEl.textContent = (index + 1) + ' / ' + items.length;
      showLoader();
      imageEl.alt = item.title;
      imageEl.src = item.src;
    }

    function open(index, trigger) {
      if (isClosing) return;

      if (closeTimer) {
        clearTimeout(closeTimer);
        closeTimer = null;
      }

      lastTrigger = trigger || triggers[index] || null;
      render(index);
      modal.classList.remove('is-closing');
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('wsi-portfolio-modal-open');
      closeBtn.focus();
    }

    function close() {
      if (!modal.classList.contains('is-open') || isClosing) return;

      isClosing = true;
      modal.classList.remove('is-open');
      modal.classList.add('is-closing');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('wsi-portfolio-modal-open');

      if (lastTrigger && typeof lastTrigger.focus === 'function') {
        lastTrigger.focus();
      }

      closeTimer = setTimeout(function () {
        modal.classList.remove('is-closing');
        imageEl.removeAttribute('src');
        isClosing = false;
        closeTimer = null;
      }, 260);
    }

    function stopClick(event) {
      event.preventDefault();
      event.stopPropagation();
    }

    imageEl.addEventListener('load', hideLoader);
    imageEl.addEventListener('error', hideLoader);

    triggers.forEach(function (btn, index) {
      btn.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        open(index, btn);
      });
    });

    closeBtn.addEventListener('click', function (event) {
      stopClick(event);
      close();
    });

    prevModalBtn.addEventListener('click', function (event) {
      stopClick(event);
      render((currentIndex - 1 + items.length) % items.length);
    });

    nextModalBtn.addEventListener('click', function (event) {
      stopClick(event);
      render((currentIndex + 1) % items.length);
    });

    panelEl.addEventListener('click', function (event) {
      event.stopPropagation();
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        stopClick(event);
        close();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (!modal.classList.contains('is-open')) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
      }
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        render((currentIndex - 1 + items.length) % items.length);
      }
      if (event.key === 'ArrowRight') {
        event.preventDefault();
        render((currentIndex + 1) % items.length);
      }
    });
  }

  function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function initScrollReveal(rootSelector, groups) {
    var root = document.querySelector(rootSelector);
    if (!root) return;

    groups.forEach(function (group) {
      var nodes = root.querySelectorAll(group.selector);
      Array.prototype.forEach.call(nodes, function (node, index) {
        var variant = group.variant || 'up';
        node.classList.add('wsi-reveal', 'wsi-reveal-' + variant);

        if (group.stagger) {
          var step = group.staggerStep || 90;
          var maxDelay = group.staggerMax || 540;
          var delay = Math.min(index * step, maxDelay);
          node.style.setProperty('--wsi-reveal-delay', delay + 'ms');
        }

        if (typeof group.delay === 'number') {
          node.style.setProperty('--wsi-reveal-delay', group.delay + 'ms');
        }
      });
    });

    var revealNodes = root.querySelectorAll('.wsi-reveal');
    if (!revealNodes.length) return;

    if (prefersReducedMotion() || !('IntersectionObserver' in window)) {
      Array.prototype.forEach.call(revealNodes, function (node) {
        node.classList.add('is-visible');
      });
      return;
    }

    var observer = new IntersectionObserver(
      function (entries, obs) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-visible');
          obs.unobserve(entry.target);
        });
      },
      { threshold: 0.14, rootMargin: '0px 0px -48px 0px' }
    );

    Array.prototype.forEach.call(revealNodes, function (node) {
      observer.observe(node);
    });
  }

  function initWsiHome() {
    initWsiHomeSlider();
    initPortfolioPreview();
    initScrollReveal('#wsi-home-cms', [
      { selector: '.wsi-section-heading', variant: 'up' },
      { selector: '.wsi-slider-wrap', variant: 'up', delay: 120 },
      { selector: '.wsi-clients-cta', variant: 'fade', delay: 220 },
      { selector: '.wsi-portfolio-card', variant: 'up', stagger: true, staggerStep: 80 },
      { selector: '.wsi-benefit-card', variant: 'up', stagger: true, staggerStep: 100 },
    ]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWsiHome);
  } else {
    initWsiHome();
  }
})();
