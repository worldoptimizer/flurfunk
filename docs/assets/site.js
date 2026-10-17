// Flurfunk docs: theme toggle, mobile nav, heading anchors, hero demo. No dependencies.
(function () {
  'use strict';

  var root = document.documentElement;
  var STORE = 'flurfunk-theme';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function media(q) {
    return window.matchMedia ? window.matchMedia(q) : { matches: false };
  }

  // Dark is the default; light comes from the system or the visitor's choice.
  function currentTheme() {
    var set = root.getAttribute('data-theme');
    if (set === 'light' || set === 'dark') { return set; }
    return media('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }

  ready(function () {
    // Theme toggle
    var themeBtn = document.querySelector('.theme-toggle');
    if (themeBtn) {
      var label = function () {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        themeBtn.setAttribute('aria-label', 'Switch to ' + next + ' theme');
        themeBtn.setAttribute('title', 'Switch to ' + next + ' theme');
      };
      label();
      var sys = media('(prefers-color-scheme: light)');
      if (sys.addEventListener) { sys.addEventListener('change', label); }
      themeBtn.addEventListener('click', function () {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem(STORE, next); } catch (e) { /* storage blocked: still switch for this page */ }
        label();
      });
    }

    // Mobile nav
    var toggle = document.querySelector('.nav-toggle');
    var nav = document.getElementById('site-nav');
    if (toggle && nav) {
      var setOpen = function (open) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        nav.classList.toggle('is-open', open);
      };
      toggle.addEventListener('click', function () {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
          setOpen(false);
          toggle.focus();
        }
      });
    }

    // A small "#" link after each h2/h3 with an id, so sections can be linked.
    Array.prototype.forEach.call(document.querySelectorAll('.prose h2[id], .prose h3[id]'), function (h) {
      var a = document.createElement('a');
      a.className = 'heading-anchor';
      a.href = '#' + h.id;
      a.setAttribute('aria-label', 'Link to this section: ' + h.textContent);
      a.textContent = '#';
      h.appendChild(a);
    });

    // Hide screenshots that are referenced but not (yet) in the repository.
    Array.prototype.forEach.call(document.querySelectorAll('.prose img'), function (img) {
      var hide = function () { img.classList.add('is-missing'); };
      if (img.complete && img.naturalWidth === 0) { hide(); } else { img.addEventListener('error', hide); }
    });

    initDemo(document.querySelector('[data-demo]'));
  });

  // Hero demo: adds step classes over time; CSS does the rest.
  // Without JS or with reduced motion the app keeps its static end state.
  function initDemo(app) {
    if (!app) { return; }
    var steps = [
      [700, 's-mention'],
      [1900, 's-typing1'],
      [3500, 's-reply1'],
      [4500, 's-pick'],
      [5000, 's-lightbox'],
      [6900, 's-lbclose'],
      [7500, 's-typing2'],
      [8900, 's-reply2'],
      [10100, 's-newthread'],
      [11300, 's-tech'],
      [12200, 's-progress'],
      [14400, 's-toastoff'],
      [14800, 's-done']
    ];
    var LOOP = 21000;
    var timers = [];
    var reduce = media('(prefers-reduced-motion: reduce)');
    var visible = true;
    var running = false;

    function clearTimers() { timers.forEach(clearTimeout); timers = []; }
    function clearSteps() { steps.forEach(function (s) { app.classList.remove(s[1]); }); }

    function cycle(first) {
      clearTimers();
      if (first) {
        // Jump to the start state without fading out the end state first.
        app.classList.add('no-anim');
        clearSteps();
        void app.offsetWidth;
        app.classList.remove('no-anim');
      } else {
        clearSteps(); // fades everything back to the start state
      }
      var offset = first ? 0 : 700;
      steps.forEach(function (s) {
        timers.push(setTimeout(function () { app.classList.add(s[1]); }, s[0] + offset));
      });
      timers.push(setTimeout(function () { cycle(false); }, LOOP));
    }

    function start() {
      if (running || reduce.matches || !visible || document.hidden) { return; }
      running = true;
      app.classList.add('is-playing');
      cycle(true);
    }

    function stop() {
      clearTimers();
      running = false;
      app.classList.add('no-anim');
      app.classList.remove('is-playing');
      clearSteps();
      void app.offsetWidth;
      app.classList.remove('no-anim');
    }

    if (reduce.addEventListener) {
      reduce.addEventListener('change', function () { if (reduce.matches) { stop(); } else { start(); } });
    }
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stop(); } else { start(); }
    });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        visible = entries[0].isIntersecting;
        if (visible) { start(); } else { stop(); }
      }).observe(app);
    }
    start();
  }
})();
