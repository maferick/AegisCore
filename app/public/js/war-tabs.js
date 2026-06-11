// War-report tabs. Reads the URL hash on load (#tab-X), defaults to
// "overview", and toggles `body[data-active-tab]` so the CSS rules
// in hud-elevated.css show only the matching pane.
(function () {
  'use strict';
  var DEFAULT_TAB = 'overview';

  function init() {
    var nav = document.querySelector('.wr-jump-nav');
    if (!nav) return;
    var links = Array.prototype.slice.call(nav.querySelectorAll('a[data-tab-link]'));
    if (!links.length) return;

    function activate(tab, push) {
      tab = tab || DEFAULT_TAB;
      document.body.setAttribute('data-active-tab', tab);
      links.forEach(function (a) {
        a.classList.toggle('active', a.getAttribute('data-tab-link') === tab);
      });
      if (push) {
        var hash = '#tab-' + tab;
        if (location.hash !== hash) history.replaceState(null, '', hash);
      }
      // Scroll to top of content so the user lands on the new pane.
      window.scrollTo({ top: 0, behavior: 'instant' });
    }

    function fromHash() {
      var h = (location.hash || '').replace(/^#tab-/, '');
      var valid = links.some(function (a) { return a.getAttribute('data-tab-link') === h; });
      activate(valid ? h : DEFAULT_TAB, false);
    }

    links.forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        activate(a.getAttribute('data-tab-link'), true);
      });
    });
    window.addEventListener('hashchange', fromHash);
    fromHash();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
