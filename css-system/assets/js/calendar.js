/* Month calendar: PH holidays + school events + (optional) the teacher's weekly classes. Read-only rendering. */
(function () {
  'use strict';
  var dataEl = document.getElementById('cal-data'), root = document.getElementById('cal-root'), detail = document.getElementById('cal-detail');
  if (!dataEl || !root || !detail) return;
  var D = JSON.parse(dataEl.textContent);
  var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  var DOW = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  var HOL = {regular: 'Regular holiday', special: 'Special non-working day', working: 'Special working day', term: 'Semester date'};
  var EVT = {holiday: 'Holiday', event: 'School event', exam: 'Exam', suspension: 'Class suspension', other: 'Notice'};

  function p2(n) { return (n < 10 ? '0' : '') + n; }
  function key(y, m, d) { return y + '-' + p2(m + 1) + '-' + p2(d); }
  function parse(s) { var a = s.split('-'); return new Date(+a[0], +a[1] - 1, +a[2]); }
  function clock(t) { if (!t) return ''; var a = t.split(':'), h = +a[0], ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return h + ':' + a[1] + ' ' + ap; }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function short(ds) { var d = parse(ds); return MONTHS[d.getMonth()].slice(0, 3) + ' ' + d.getDate(); }

  var today = D.today, t = parse(today), view = {y: t.getFullYear(), m: t.getMonth()}, selected = today;

  function info(ds) {
    var hol = D.holidays[ds] || [];
    var evs = (D.events || []).filter(function (e) { return e.start <= ds && ds <= e.end; });
    var all = (D.classes && D.classes[parse(ds).getDay()]) || [];
    var r = D.termRange;
    var outside = !!(r && (ds < r.start || ds > r.end));
    var cls = outside ? [] : all;
    var off = hol.some(function (h) { return h.t !== 'working' && h.t !== 'term'; }) || evs.some(function (e) { return e.nc; });
    return {hol: hol, evs: evs, cls: cls, off: off, outside: outside && all.length > 0};
  }

  function shift(n) {
    view.m += n;
    if (view.m < 0) { view.m = 11; view.y--; } else if (view.m > 11) { view.m = 0; view.y++; }
    render();
  }

  function select(ds) {
    selected = ds;
    var d = parse(ds); view = {y: d.getFullYear(), m: d.getMonth()};
    render(); renderDetail();
    root.dispatchEvent(new CustomEvent('cal:select', {detail: {date: ds}}));
  }

  function render() {
    root.replaceChildren();
    var head = el('div', 'cal-head');
    var prev = el('button', 'cal-nav', '\u2039'); prev.type = 'button'; prev.setAttribute('aria-label', 'Previous month');
    var next = el('button', 'cal-nav', '\u203A'); next.type = 'button'; next.setAttribute('aria-label', 'Next month');
    var td = el('button', 'cal-today', 'Today'); td.type = 'button';
    prev.onclick = function () { shift(-1); };
    next.onclick = function () { shift(1); };
    td.onclick = function () { select(today); };
    var left = el('div', 'cal-navs'); left.appendChild(prev); left.appendChild(next);
    head.appendChild(left);
    head.appendChild(el('h3', 'cal-title', MONTHS[view.m] + ' ' + view.y));
    head.appendChild(td);
    root.appendChild(head);

    var grid = el('div', 'cal-grid');
    DOW.forEach(function (n, i) { grid.appendChild(el('div', 'cal-dow' + (i === 0 || i === 6 ? ' wknd' : ''), n.slice(0, 3))); });
    var first = new Date(view.y, view.m, 1), days = new Date(view.y, view.m + 1, 0).getDate();
    var weeks = Math.ceil((first.getDay() + days) / 7);
    for (var i = 0; i < weeks * 7; i++) {
      var d = new Date(view.y, view.m, 1 - first.getDay() + i), ds = key(d.getFullYear(), d.getMonth(), d.getDate()), inf = info(ds);
      var c = el('button', 'cal-cell'); c.type = 'button';
      if (d.getMonth() !== view.m) c.classList.add('out');
      if (ds === today) c.classList.add('today');
      if (ds === selected) c.classList.add('sel');
      if (d.getDay() === 0 || d.getDay() === 6) c.classList.add('wknd');
      if (inf.off) c.classList.add('off');
      c.setAttribute('aria-label', DOW[d.getDay()] + ' ' + MONTHS[d.getMonth()] + ' ' + d.getDate());
      c.appendChild(el('span', 'cal-num', d.getDate()));
      var items = [];
      inf.hol.forEach(function (h) { items.push({c: 'h-' + h.t, t: h.n}); });
      inf.evs.forEach(function (e) { items.push({c: 'e-' + e.type, t: e.title}); });
      if (inf.cls.length) items.push({c: inf.off ? 'c-off' : 'c-on', t: inf.off ? 'No classes' : inf.cls.length + (inf.cls.length > 1 ? ' classes' : ' class')});
      var chips = el('div', 'cal-chips'), dots = el('div', 'cal-dots');
      items.slice(0, 3).forEach(function (it) { chips.appendChild(el('span', 'cal-chip ' + it.c, it.t)); dots.appendChild(el('i', 'cal-dot ' + it.c)); });
      if (items.length > 3) chips.appendChild(el('span', 'cal-more', '+' + (items.length - 3) + ' more'));
      c.appendChild(chips); c.appendChild(dots);
      (function (v) { c.onclick = function () { select(v); }; })(ds);
      grid.appendChild(c);
    }
    root.appendChild(grid);
  }

  function renderDetail() {
    var inf = info(selected), d = parse(selected);
    detail.replaceChildren();
    var h = el('h3', 'cal-d-title', DOW[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear());
    if (selected === today) h.appendChild(el('span', 'cal-badge', 'Today'));
    detail.appendChild(h);
    var any = false;

    inf.hol.forEach(function (x) {
      any = true;
      var row = el('div', 'cal-item h-' + x.t);
      row.appendChild(el('strong', null, x.n));
      row.appendChild(el('span', 'cal-sub', HOL[x.t] + (x.t === 'working' ? ': classes continue' : x.t === 'term' ? '' : ': no classes')));
      detail.appendChild(row);
    });
    inf.evs.forEach(function (e) {
      any = true;
      var row = el('div', 'cal-item e-' + e.type);
      row.appendChild(el('strong', null, e.title));
      var when = e.start === e.end ? '' : short(e.start) + ' to ' + short(e.end);
      if (e.st) when += (when ? ', ' : '') + clock(e.st) + (e.et ? ' to ' + clock(e.et) : '');
      row.appendChild(el('span', 'cal-sub', EVT[e.type] + (when ? ' \u00B7 ' + when : '') + (e.nc ? ' \u00B7 No classes' : '')));
      if (e.desc) row.appendChild(el('p', 'cal-desc', e.desc));
      detail.appendChild(row);
    });
    if (inf.cls.length) {
      any = true;
      detail.appendChild(el('h4', 'cal-d-sub', D.classLabel + (inf.off ? ' (suspended today)' : '')));
      inf.cls.forEach(function (k) {
        var row = el('div', 'cal-class' + (inf.off ? ' struck' : ''));
        row.appendChild(el('div', 'cal-class-time', clock(k.s) + ' to ' + clock(k.e)));
        row.appendChild(el('strong', null, k.code + (k.title ? ' \u2013 ' + k.title : '')));
        row.appendChild(el('span', 'cal-sub', k.section + (k.room ? ' \u00B7 Room ' + k.room : '')));
        detail.appendChild(row);
      });
    }
    if (inf.outside) {
      any = true;
      detail.appendChild(el('p', 'cal-empty', 'No classes: this date is outside ' + D.termRange.label + ' (' + short(D.termRange.start) + ' to ' + short(D.termRange.end) + ').'));
    }
    if (!any) detail.appendChild(el('p', 'cal-empty', 'Nothing scheduled on this day.'));
  }

  render(); renderDetail();
})();
