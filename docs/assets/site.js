// Flurfunk website: theme toggle, mobile nav, docs helpers (contents sidebar,
// heading anchors, copy buttons) and the animated mocks on the home page.
// No dependencies.
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

  function each(selector, fn, scope) {
    Array.prototype.forEach.call((scope || document).querySelectorAll(selector), fn);
  }

  // Light is the default; dark only when the visitor picked it.
  function currentTheme() {
    return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }

  function initTheme() {
    var btn = document.querySelector('.theme-toggle');
    if (!btn) { return; }
    var label = function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      btn.setAttribute('aria-label', 'Switch to ' + next + ' theme');
      btn.setAttribute('title', 'Switch to ' + next + ' theme');
    };
    label();
    btn.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem(STORE, next); } catch (e) { /* storage blocked: still switch for this page */ }
      label();
    });
  }

  function initNav() {
    var toggle = document.querySelector('.nav-toggle');
    var nav = document.getElementById('site-nav');
    if (!toggle || !nav) { return; }
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
    each('a', function (a) {
      a.addEventListener('click', function () { setOpen(false); });
    }, nav);
  }

  function initDocs() {
    var prose = document.querySelector('.prose');

    // The Markdown contents list (in-page links before the first h2).
    if (prose) {
      var first = prose.querySelector(':scope > ul');
      var firstH2 = prose.querySelector(':scope > h2');
      if (first && (!firstH2 || (first.compareDocumentPosition(firstH2) & Node.DOCUMENT_POSITION_FOLLOWING))) {
        var links = first.querySelectorAll(':scope > li > a[href^="#"]');
        if (links.length && links.length === first.children.length) { first.classList.add('toc-list'); }
      }
    }

    // A "#" link after each h2/h3 with an id.
    each('.prose h2[id], .prose h3[id]', function (h) {
      var a = document.createElement('a');
      a.className = 'heading-anchor';
      a.href = '#' + h.id;
      a.setAttribute('aria-label', 'Link to this section: ' + h.textContent);
      a.textContent = '#';
      h.appendChild(a);
    });

    // Sticky "On this page" sidebar on wide screens.
    var toc = document.querySelector('.doc-toc');
    var heads = prose ? prose.querySelectorAll(':scope > h2[id]') : [];
    if (toc && heads.length > 2) {
      var list = toc.querySelector('ol');
      var items = [];
      Array.prototype.forEach.call(heads, function (h) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = '#' + h.id;
        a.textContent = h.firstChild && h.firstChild.nodeType === 3 ? h.firstChild.textContent : h.textContent.replace(/#$/, '');
        li.appendChild(a);
        list.appendChild(li);
        items.push({ h: h, a: a });
      });
      toc.hidden = false;
      document.body.classList.add('has-toc');
      if ('IntersectionObserver' in window) {
        var current = null;
        var io = new IntersectionObserver(function () {
          // The active section is the last heading above the upper third of the screen.
          var line = window.innerHeight * 0.3;
          var active = items[0];
          items.forEach(function (it) { if (it.h.getBoundingClientRect().top < line) { active = it; } });
          if (active !== current) {
            if (current) { current.a.classList.remove('is-active'); current.a.removeAttribute('aria-current'); }
            active.a.classList.add('is-active');
            active.a.setAttribute('aria-current', 'location');
            current = active;
          }
        }, { rootMargin: '0px 0px -60% 0px', threshold: [0, 1] });
        items.forEach(function (it) { io.observe(it.h); });
      }
    }

    // Language label and copy button on code blocks.
    each('.prose div.highlighter-rouge, .code figure.highlight, .code div.highlighter-rouge', function (block) {
      if (block.querySelector('.code-label') || block.parentElement.closest('.highlighter-rouge')) { return; }
      var code = block.querySelector('code');
      if (!code) { return; }
      var m = (block.className + ' ' + code.className).match(/language-(\w+)/);
      var lang = m && m[1] !== 'plaintext' ? m[1].replace(/^(bash|sh|shell)$/, 'shell') : '';
      var bar = document.createElement('div');
      bar.className = 'code-label';
      if (lang && !block.closest('.code')) {
        var span = document.createElement('span');
        span.textContent = lang;
        bar.appendChild(span);
      }
      if (navigator.clipboard) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'copy-button';
        btn.textContent = 'Copy';
        btn.addEventListener('click', function () {
          navigator.clipboard.writeText(code.textContent.replace(/\n$/, '')).then(function () {
            btn.textContent = 'Copied';
            btn.classList.add('is-done');
            setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('is-done'); }, 1600);
          });
        });
        bar.appendChild(btn);
      }
      if (bar.children.length) { block.appendChild(bar); }
    });

    // Screenshots open at full size (useful on phones).
    each('.prose img', function (img) {
      if (img.closest('a')) { return; }
      var a = document.createElement('a');
      a.href = img.currentSrc || img.src;
      a.className = 'img-link';
      a.setAttribute('aria-label', 'Open the image at full size: ' + (img.alt || ''));
      img.parentNode.insertBefore(a, img);
      a.appendChild(img);
    });

    // Hide screenshots that are referenced but not (yet) in the repository.
    each('.prose img', function (img) {
      var hide = function () { img.classList.add('is-missing'); };
      if (img.complete && img.naturalWidth === 0) { hide(); } else { img.addEventListener('error', hide); }
    });
  }

  // Animated mocks: each adds step classes over time; CSS does the rest.
  // Without JS or with reduced motion every mock keeps its static end state.
  var SCRIPTS = {
    hero: {
      loop: 17000,
      steps: [
        [900, 's-typing'], [2900, 's-msg'], [3700, 's-mention'], [5400, 's-pick'],
        [5900, 's-lightbox'], [8100, 's-lbclose'], [9300, 's-done']
      ]
    },
    board: {
      loop: 13000,
      steps: [
        [900, 's-lift'], [1300, 's-doing'], [3300, 's-question'], [5300, 's-done'],
        [6400, 's-drawer'], [10600, 's-drawerclose']
      ]
    },
    notify: {
      loop: 9000,
      steps: [[900, 's-pushon'], [2000, 's-push1']]
    }
  };

  function initDemos() {
    var reduce = media('(prefers-reduced-motion: reduce)');
    var demos = [];
    each('[data-demo]', function (el) {
      var name = el.getAttribute('data-demo') || 'hero';
      var script = SCRIPTS[name] || SCRIPTS.hero;
      demos.push(makeDemo(el, script, reduce));
    });
    if (!demos.length) { return; }
    if (reduce.addEventListener) {
      reduce.addEventListener('change', function () {
        demos.forEach(function (d) { if (reduce.matches) { d.stop(); } else { d.start(); } });
      });
    }
    document.addEventListener('visibilitychange', function () {
      demos.forEach(function (d) { if (document.hidden) { d.stop(); } else { d.start(); } });
    });
  }

  function makeDemo(el, script, reduce) {
    var timers = [];
    var visible = !('IntersectionObserver' in window);
    var running = false;

    function clearTimers() { timers.forEach(clearTimeout); timers = []; }
    function clearSteps() { script.steps.forEach(function (s) { el.classList.remove(s[1]); }); }

    function cycle(first) {
      clearTimers();
      if (first) {
        // Jump to the start state without fading out the end state first.
        el.classList.add('no-anim');
        clearSteps();
        void el.offsetWidth;
        el.classList.remove('no-anim');
      } else {
        clearSteps(); // fades back to the start state
      }
      var offset = first ? 0 : 700;
      script.steps.forEach(function (s) {
        timers.push(setTimeout(function () { el.classList.add(s[1]); }, s[0] + offset));
      });
      timers.push(setTimeout(function () { cycle(false); }, script.loop));
    }

    function start() {
      if (running || reduce.matches || !visible || document.hidden) { return; }
      running = true;
      el.classList.add('is-playing');
      cycle(true);
    }

    function stop() {
      clearTimers();
      if (!running) { return; }
      running = false;
      el.classList.add('no-anim');
      el.classList.remove('is-playing');
      clearSteps();
      void el.offsetWidth;
      el.classList.remove('no-anim');
    }

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        visible = entries[0].isIntersecting;
        if (visible) { start(); } else { stop(); }
      }, { threshold: 0.25 }).observe(el);
    }
    start();
    return { start: start, stop: stop };
  }

  ready(function () {
    initTheme();
    initNav();
    initDocs();
    initDemos();
  });
})();

// Hero window: leans a little towards the mouse while it is over the window, flat otherwise.
(function () {
  var win = document.querySelector('.ua');
  if (!win || !window.matchMedia('(hover: hover) and (pointer: fine)').matches || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }
  var frame = 0;
  win.addEventListener('pointermove', function (e) {
    if (frame) { return; }
    frame = requestAnimationFrame(function () {
      frame = 0;
      var r = win.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width * 2 - 1;  // -1 … 1, left to right
      var y = (e.clientY - r.top) / r.height * 2 - 1;  // -1 … 1, top to bottom
      win.classList.add('is-tilting');
      // The window turns its face a touch towards the pointer: at most 1.5° sideways, 1° up/down.
      win.style.setProperty('--tilt-y', (x * 1.5).toFixed(2) + 'deg');
      win.style.setProperty('--tilt-x', (y * -1).toFixed(2) + 'deg');
    });
  });
  win.addEventListener('pointerleave', function () {
    win.classList.remove('is-tilting');
    win.style.setProperty('--tilt-x', '0deg');
    win.style.setProperty('--tilt-y', '0deg');
  });
})();

