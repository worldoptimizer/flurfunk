// Flurfunk docs: mobile nav toggle and heading anchor links. No dependencies.
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
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
    var headings = document.querySelectorAll('.prose h2[id], .prose h3[id]');
    Array.prototype.forEach.call(headings, function (h) {
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
  });
})();
