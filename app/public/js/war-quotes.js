// Rotating "Things heard during this war" ribbon for the war report.
// 150 EVE-flavoured one-liners, switched every 5 minutes. The choice
// is time-bucketed off floor(Date.now() / 300_000) so every visitor
// sees the same quote at the same moment without backend coordination.
(function () {
  'use strict';
  var quotes = [
    'gf in local','warp warp warp','primary is the Maller','broadcast for reps',
    'where is the cyno?','FC said hold cloak','i’m in fleet but in station','didn’t see the drag bubble',
    'fly safe o7','this is fine','f1 monkey reporting in','the dread is on field',
    'lights out','keepstar timer is up','PL is on grid','where is the scout?',
    'anchor up','bring more probes','i have local muted, what is happening','spy in fleet?',
    'paps please','form up at IHUB','got tackle','scrammed and webbed',
    'fleet warps to the gate','i voiced jump at the wrong time','we are winning the kbing','kb is just suggestions',
    'rorqual is going down','ratters detected','i’ll just rat real quick','BR in 30',
    'pre-pings: lots of red','your battlecruiser is gone','miners shoot back?','imperium has more rorquals than pilots',
    'WC have been spotted in local','where is the entosis?','GF as always','podded back to Jita',
    'the hot drop is real','blob arrived','outnumbered 5:1','logi anchor on me',
    'interceptors in front','warp to 0 not 100','called a bomb run','i forgot my modules',
    'shipping a Marshal','got dunked','another Naglfar bites the dust','the FC has spoken',
    'blue donut is real','no scram, ran','deployable noise','MTU got shot',
    'tackle up the FAX','carrier ratting was a mistake','shoot first FC','no fight tonight',
    'structure goes pop','armor cap stable','pinged the discord','fleet is at 200%',
    'keepstar is locked down','wtf is local doing','my zkill is a mess','neutral logi confirmed',
    'they brought blops','we anchored on the wrong ping','called pri before scrambled','five Nyx on field',
    'FC: align Jita','warp out, i have alarm clock','hot drop on mining op','WC pilot lost a freighter',
    'Imperium says peace','Initiative says no','keepstar timer in 12 hrs','they just dropped a fleet',
    'we are the bear, they are the salmon','ENTERED ASSEMBLY ARRAY','logi crossfeed broken','pyfa says the fit is fine',
    'EFT warrior','SISI works fine','Tranquility crashed again','downtime in 5',
    'post-DT lag','i have a meeting in 10','skip work, fight today','supercarrier got tackled',
    'titan jumps in','doomsday on gate','doomsday on FAX','FAX in glass cage',
    'T1 cruiser disco fleet','shitfit Punisher solo’d a Vexor','we trade kills like it is 2008','smartbomb gang on gate',
    'the FC is sober','it is downtime, brb','this just got expensive','implant pod popped',
    'Hydra implants gone','the math does not math','PL was here','i have stage fright',
    'rolled the wormhole twice','killed by a bear','asteroid belt is too dangerous','FC needs to log',
    'comms are quiet','we all died','reddit will figure it out','another sov flip',
    'blue moon is on','cap chained','neut of doom','Imperium accepting refugees',
    'WC reformed','Initiative still not blue','spy comms intercepted','Goons say sorry',
    'cyno alt is offline','rage form','fleet xup','join fleet, get podded',
    'hellcamp went well','evac ratters','broadcast for armor','broadcast for shields',
    'broadcast for capacitor','lost a fit Marauder','no fc, no fights','rolled hole, no roll',
    'warp to safe','moon goo bonanza','structure dead, party in TS','scratch one PL Naglfar',
    'another perfect comp','fed up of feeding','WC: trust the FCs','Imperium: trust the meta',
    'Init: no really we are not blue','fitting room is open','the Goon way is to disengage','we will get them next time',
    'fly safe','see you next downtime',
  ];

  var BUCKET_MS = 300000; // 5 minutes
  function pickIndex() {
    return Math.floor(Date.now() / BUCKET_MS) % quotes.length;
  }
  function quoteAt(idx) {
    return '“' + quotes[idx] + '”';
  }

  function init() {
    var stage = document.getElementById('wr-quote-stage');
    if (!stage) return;
    var node = stage.querySelector('.wr-q-current');
    if (!node) {
      node = document.createElement('span');
      node.className = 'wr-q-current';
      stage.appendChild(node);
    }
    var lastIdx = -1;

    function tick() {
      var idx = pickIndex();
      if (idx === lastIdx) return;
      lastIdx = idx;
      node.classList.add('fading');
      setTimeout(function () {
        node.textContent = quoteAt(idx);
        node.classList.remove('fading');
      }, 400);
    }
    // First paint — no fade.
    lastIdx = pickIndex();
    node.textContent = quoteAt(lastIdx);
    // Align next tick to the next 5-min bucket boundary, then poll
    // every 30s so we catch the boundary quickly.
    setInterval(tick, 30000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
