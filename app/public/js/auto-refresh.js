// Public-page auto-refresh. Reload the page after N minutes when:
//   - the tab is visible (document.visibilityState === 'visible')
//   - the user has been idle for >= IDLE_S seconds (no scroll/click)
//   - the user is near the top of the page (or expanded a tab)
//
// Refusing to reload mid-scroll keeps long sections (kill feed, side
// breakdowns) from snapping to the top while the user is reading.
//
// Per-page interval set via <body data-auto-refresh-seconds="N">.
// Default = 300s (5 min). 0 disables.
(function () {
  'use strict';
  var DEFAULT_SECONDS = 300;
  var IDLE_S = 30;        // user must have stopped interacting this long
  var SCROLL_TOLERANCE = 250; // px from top — counts as "near top"

  function init() {
    var body = document.body;
    if (!body) return;
    var attr = body.getAttribute('data-auto-refresh-seconds');
    var seconds = attr === null ? DEFAULT_SECONDS : parseInt(attr, 10);
    if (!seconds || seconds < 30) return; // disabled or too aggressive

    var loadedAt = Date.now();
    var lastActivity = Date.now();
    var refreshDue = false;

    function bump() { lastActivity = Date.now(); }
    ['scroll', 'click', 'keydown', 'mousemove', 'touchstart'].forEach(function (ev) {
      window.addEventListener(ev, bump, { passive: true });
    });

    function check() {
      if (document.visibilityState !== 'visible') return;
      var now = Date.now();
      var elapsed = (now - loadedAt) / 1000;
      if (elapsed < seconds) return;
      // Time's up — wait for the user to be idle and near top.
      var idle = (now - lastActivity) / 1000;
      if (idle < IDLE_S) {
        refreshDue = true;
        return;
      }
      if (window.scrollY > SCROLL_TOLERANCE) {
        refreshDue = true;
        return;
      }
      // Preserve URL hash so tab selection survives.
      window.location.reload();
    }

    setInterval(check, 5000);
    // If we marked a refresh due, also fire when the user voluntarily
    // returns to the top of the page.
    window.addEventListener('scroll', function () {
      if (!refreshDue) return;
      if (window.scrollY <= SCROLL_TOLERANCE && (Date.now() - lastActivity) / 1000 >= IDLE_S) {
        window.location.reload();
      }
    }, { passive: true });
    // And when the tab gains focus after being hidden long enough.
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible' && refreshDue) {
        // Don't reload immediately on focus return — wait one tick of
        // the polling loop so click handlers etc. fire first.
        setTimeout(check, 250);
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
