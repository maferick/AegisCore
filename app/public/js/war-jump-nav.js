// Jump-nav active-state tracker for the war report HUD. Toggles
// `.active` on the matching `<a>` based on (a) URL hash on load /
// hashchange and (b) which section is currently most-visible while
// scrolling (IntersectionObserver). Public CSP allows same-origin JS,
// no inline.
(function () {
  'use strict';
  function init() {
    var nav = document.querySelector('.wr-jump-nav');
    if (!nav) return;
    var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
    if (!links.length) return;

    var byId = {};
    links.forEach(function (a) {
      var id = a.getAttribute('href').slice(1);
      if (id) byId[id] = a;
    });

    function clear() { links.forEach(function (a) { a.classList.remove('active'); }); }
    function activateById(id) { clear(); if (byId[id]) byId[id].classList.add('active'); }

    function fromHash() {
      var h = (location.hash || '').replace(/^#/, '');
      if (h && byId[h]) activateById(h);
    }
    fromHash();
    window.addEventListener('hashchange', fromHash);

    // IntersectionObserver: mark whichever target is most visible.
    if ('IntersectionObserver' in window) {
      var seen = {};
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (e.isIntersecting) seen[e.target.id] = e.intersectionRatio;
          else delete seen[e.target.id];
        });
        var best = null, bestR = 0;
        Object.keys(seen).forEach(function (id) {
          if (seen[id] > bestR) { best = id; bestR = seen[id]; }
        });
        if (best) activateById(best);
      }, { rootMargin: '-25% 0px -55% 0px', threshold: [0, 0.25, 0.5, 0.75, 1] });
      Object.keys(byId).forEach(function (id) {
        var el = document.getElementById(id);
        if (el) io.observe(el);
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
