(function () {
  if (window.__wsiAboutCmsInit) return;
  window.__wsiAboutCmsInit = true;

  function animateCounter(el) {
    var target = parseFloat(el.getAttribute('data-target') || '0');
    var suffix = el.getAttribute('data-suffix') || '';
    var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);
    var duration = 1400;
    var start = 0;
    var startTime = null;

    function frame(timestamp) {
      if (!startTime) startTime = timestamp;
      var progress = Math.min((timestamp - startTime) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      var value = start + (target - start) * eased;

      if (decimals > 0) {
        el.textContent = value.toFixed(decimals) + suffix;
      } else {
        el.textContent = Math.round(value) + suffix;
      }

      if (progress < 1) {
        window.requestAnimationFrame(frame);
      }
    }

    window.requestAnimationFrame(frame);
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

  function initAboutHeroReveal() {
    var hero = document.querySelector('.wsi-about-page-hero-inner');
    if (!hero) return;

    var children = hero.children;
    Array.prototype.forEach.call(children, function (child, index) {
      child.classList.add('wsi-reveal', 'wsi-reveal-up');
      child.style.setProperty('--wsi-reveal-delay', index * 130 + 'ms');

      if (prefersReducedMotion()) {
        child.classList.add('is-visible');
        return;
      }

      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
          child.classList.add('is-visible');
        });
      });
    });
  }

  function initAboutCounters() {
    var counters = document.querySelectorAll('.wsi-about-counter');
    if (!counters.length) return;

    if (!('IntersectionObserver' in window)) {
      counters.forEach(animateCounter);
      return;
    }

    var observer = new IntersectionObserver(
      function (entries, obs) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          animateCounter(entry.target);
          obs.unobserve(entry.target);
        });
      },
      { threshold: 0.35 }
    );

    counters.forEach(function (counter) {
      observer.observe(counter);
    });
  }

  function initAboutPage() {
    initAboutHeroReveal();
    initScrollReveal('#wsi-about-cms', [
      { selector: '.wsi-about-stats-bar-inner', variant: 'scale' },
      { selector: '.wsi-about-intro-copy', variant: 'left' },
      { selector: '.wsi-about-intro-visual', variant: 'right' },
      { selector: '.wsi-about-head', variant: 'up' },
      { selector: '.wsi-about-service', variant: 'up', stagger: true, staggerStep: 90 },
      { selector: '.wsi-about-mission', variant: 'left' },
      { selector: '.wsi-about-vision', variant: 'right' },
      { selector: '.wsi-about-values-grid article', variant: 'up', stagger: true, staggerStep: 70 },
      { selector: '.wsi-about-ceo-inner', variant: 'up' },
      { selector: '.wsi-about-cta-inner', variant: 'scale', delay: 80 },
    ]);
    initAboutCounters();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAboutPage);
  } else {
    initAboutPage();
  }
})();
