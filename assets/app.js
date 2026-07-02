'use strict';

// Pořadí sloupců odpovídá FIELDS v index.php
const F = {
  timestamp: 0, pv1Power: 1, pv2Power: 2, totalPower: 3, totalProduction: 4,
  totalProductionInclBatt: 5, feedInPower: 6, totalGridIn: 7, totalGridOut: 8,
  load: 9, batteryPower: 10, totalChargedIn: 11, totalChargedOut: 12,
  batterySoC: 13, batteryCap: 14, batteryTemp: 15, inverterTemp: 16,
  inverterPower: 17, totalConsumption: 18, selfSufficiencyRate: 19,
  inverterMode: 20, batteryMode: 21,
};

// Režimy měniče (z reverzního inženýrství Solax JSONu)
const INVERTER_MODE = [
  'Waiting', 'Checking', 'Normal', 'Off', 'Permanent Fault', 'Updating',
  'EPS Check', 'EPS Mode', 'Self Test', 'Idle', 'Standby',
];

// Meze pro ukazatele (bary) – ze solax.conf, předané PHP do window.LIMITS
const L = window.LIMITS || { peak1: 5000, peak2: 5000, maxPower: 10000, maxLoad: 16000, batteryMinSoC: 15 };
if (L.batteryMinSoC == null) L.batteryMinSoC = 15;

// Hodnoty vrací { n: číslo, u: jednotka } – číslo a jednotka mají vlastní
// zarovnaný sloupec v mřížce (čísla vpravo, jednotky vlevo) jako v TUI.
const W = (v) => (v == null ? { n: '–', u: '' } : { n: String(Math.round(v)), u: 'W' });
const kWh = (v, d = 1) => (v == null ? { n: '–', u: '' } : { n: v.toFixed(d).replace('.', ','), u: 'kWh' });
const pct = (v) => (v == null ? { n: '–', u: '' } : { n: String(Math.round(v)), u: '%' });
const tempTxt = (v) => (v == null ? '–' : Math.round(v) + ' °C');
const socTxt = (v) => (v == null ? '–' : Math.round(v) + ' %');
const mode = (v) => (v == null || INVERTER_MODE[v] == null ? '–' : INVERTER_MODE[v]);

// Odhad času do plna / do vybití (k rezervě L.batteryMinSoC). Vrací {label,n} nebo null.
function batteryEta(s) {
  const soc = s.batterySoC, cap = s.batteryCap, p = s.batteryPower;
  if (soc == null || cap == null || p == null || soc <= 0) return null;
  const total = cap / (soc / 100);   // celková kapacita [kWh]
  const IDLE = 25;                   // práh klidu [W]
  let label, energy;
  if (p > IDLE)       { label = 'do plna';   energy = total * (100 - soc) / 100; }
  else if (p < -IDLE) { label = 'do vybití'; energy = total * (soc - L.batteryMinSoC) / 100; }
  else return { label: 'baterie', n: 'klid' };
  if (energy <= 0) return { label, n: '–' };
  return { label, n: fmtDur(energy / (Math.abs(p) / 1000)) };
}
// kompaktní formát h:mm (např. 2:34), ať se vejde do sloupce s čísly
function fmtDur(h) {
  const m = Math.round(h * 60);
  return `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}`;
}

// ---------- živé dlaždice ----------
async function refreshCurrent() {
  let s;
  try {
    const r = await fetch('?api=current', { cache: 'no-store' });
    s = (await r.json()).sample;
  } catch (e) { setStatus('chyba spojení', 'stale'); return; }

  if (!s) { setStatus('zatím žádná data', 'stale'); return; }
  setStatus(s.age <= 30 ? 'živě' : `naposledy před ${s.age}s`, s.age <= 30 ? 'live' : 'stale');

  const eta = batteryEta(s);
  document.getElementById('tiles').innerHTML = `
    <div class="group">
      <h3>Panely</h3>
      <div class="metrics">
        ${row('celkem', W(s.totalPower), { bar: bar(s.totalPower, L.peak1 + L.peak2), hl: true })}
        ${row('string 1', W(s.pv1Power), { bar: bar(s.pv1Power, L.peak1) })}
        ${row('string 2', W(s.pv2Power), { bar: bar(s.pv2Power, L.peak2) })}
        ${row('dnes výroba DC', kWh(s.totalProduction))}
      </div>
    </div>
    <div class="group">
      <h3><span class="htitle">Baterie</span><span class="hmeta"><span class="hsoc">${socTxt(s.batterySoC)}</span><span class="htemp">${tempTxt(s.batteryTemp)}</span></span></h3>
      <div class="metrics">
        ${row('nabití', kWh(s.batteryCap), { bar: bar(s.batterySoC, 100) })}
        ${row(s.batteryPower >= 0 ? 'nabíjení' : 'vybíjení', W(s.batteryPower), { cls: s.batteryPower >= 0 ? 'pos' : 'neg' })}
        ${eta ? row(eta.label, { n: eta.n, u: '' }) : ''}
        ${row('dnes nabito', kWh(s.totalChargedIn))}
        ${row('vybito', kWh(s.totalChargedOut))}
      </div>
    </div>
    <div class="group">
      <h3><span class="htitle">Střídač</span><span class="badge">${mode(s.inverterMode)}</span><span class="hmeta"><span class="htemp">${tempTxt(s.inverterTemp)}</span></span></h3>
      <div class="metrics">
        ${row('výkon', W(s.inverterPower), { bar: bar(s.inverterPower, L.maxPower), hl: true })}
        ${row('dnes výroba AC', kWh(s.totalProductionInclBatt))}
      </div>
    </div>
    <div class="group">
      <h3>Distribuční síť</h3>
      <div class="metrics">
        ${row(s.feedInPower >= 0 ? 'dodávka' : 'odběr', W(s.feedInPower), { cls: s.feedInPower >= 0 ? 'pos' : 'neg' })}
        ${row('dnes odebráno', kWh(s.totalGridIn, 2))}
        ${row('dodáno', kWh(s.totalGridOut, 2))}
      </div>
    </div>
    <div class="group">
      <h3>Dům</h3>
      <div class="metrics">
        ${row('aktuální odběr', W(s.load), { bar: bar(s.load, L.maxLoad), hl: true })}
        ${row('dnes spotřeba', kWh(s.totalConsumption, 2))}
        ${row('soběstačnost', pct(s.selfSufficiencyRate), { bar: bar(s.selfSufficiencyRate, 100) })}
      </div>
    </div>`;
}

const bar = (v, max) => Math.max(0, Math.min(100, ((v || 0) / max) * 100)).toFixed(0);

// Čtyři buňky mřížky .metrics: popisek | číslo (vpravo) | jednotka (vlevo) | bar.
// Sdílené sloupce v rámci panelu => čísla, jednotky i bary jsou přesně pod sebou.
// val = { n, u }. o.hl = zvýraznit, o.cls = 'pos'/'neg', o.bar = % naplnění baru.
function row(label, val, o = {}) {
  const cls = o.cls ? ' ' + o.cls : (o.hl ? ' hl' : '');
  const b = o.bar == null ? '<span></span>' : `<span class="ibar"><i style="width:${o.bar}%"></i></span>`;
  return `<span class="label">${label}</span><span class="num${cls}">${val.n}</span><span class="unit">${val.u}</span>${b}`;
}

function setStatus(text, cls) {
  const el = document.getElementById('status');
  el.textContent = text;
  el.className = 'status ' + (cls || '');
}

// ---------- grafy ----------
let currentRange = 'live';
const charts = {};

// ---------- navigace po dnech (režim „Den") ----------
const pad2 = (n) => String(n).padStart(2, '0');
const dayStr = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
const todayStr = () => dayStr(new Date());
let currentDay = todayStr();

// 'YYYY-MM-DD' -> lokální Date v poledne (poledne kvůli DST, ať krok o den nepřeskočí)
function parseDay(s) {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(y, m - 1, d, 12, 0, 0);
}
function stepDay(delta) {
  const d = parseDay(currentDay);
  d.setDate(d.getDate() + delta);
  setDay(dayStr(d));
}
function setDay(s) {
  if (s > todayStr()) s = todayStr();   // do budoucna nechodíme
  currentDay = s;
  syncDayNav();
  loadSeries('day');
}
function syncDayNav() {
  const pick = document.getElementById('dayPick');
  pick.value = currentDay;
  pick.max = todayStr();
  document.getElementById('dayNext').disabled = currentDay >= todayStr();
}

function makeOpts(series, extra = {}) {
  return {
    width: document.querySelector('main').clientWidth - 32,
    height: 260,
    series,
    axes: [
      { stroke: '#8b949e', grid: { stroke: '#21262d' } },
      { stroke: '#8b949e', grid: { stroke: '#21262d' } },
    ],
    legend: { live: true },
    ...extra,
  };
}

async function loadSeries(range) {
  let url = '?api=series&range=' + range;
  if (range === 'day') url += '&date=' + currentDay;
  let d;
  try {
    const r = await fetch(url, { cache: 'no-store' });
    d = await r.json();
  } catch (e) { return; }

  const balEl = document.getElementById('balance');
  if (!d.ok || !d.count) { drawEmpty(); if (range === 'day') balEl.hidden = true; return; }
  if (range === 'day') { balEl.innerHTML = balanceHtml(d); balEl.hidden = false; }

  const col = (name) => d.data[F[name]].map((x) => (x === null ? null : +x));
  const ts = d.data[F.timestamp].map((x) => +x);

  const pData = [ts, col('totalPower'), col('load'), col('batteryPower'), col('feedInPower')];
  const pSeries = [
    {},
    { label: 'Panely', stroke: '#f5b301', width: 1.5 },
    { label: 'Odběr', stroke: '#58a6ff', width: 1.5 },
    { label: 'Baterie', stroke: '#3fb950', width: 1.5 },
    { label: 'Síť', stroke: '#ff6b6b', width: 1.5 },
  ];
  rebuild('chartPower', 'power', pData, pSeries);

  const sData = [ts, col('batterySoC')];
  const sSeries = [{}, { label: 'SoC %', stroke: '#3fb950', width: 1.5, fill: 'rgba(63,185,80,.12)' }];
  rebuild('chartSoc', 'soc', sData, sSeries, { scales: { y: { range: [0, 100] } } });
}

function rebuild(elId, key, data, series, extra) {
  const el = document.getElementById(elId);
  if (charts[key]) charts[key].destroy();
  charts[key] = new uPlot(makeOpts(series, extra), data, el);
}

function drawEmpty() {
  ['chartPower', 'chartSoc'].forEach((id) => {
    if (charts[id]) { charts[id].destroy(); }
    document.getElementById(id).innerHTML = '<p style="color:#8b949e">Žádná data pro toto období.</p>';
  });
  charts.power = charts.soc = null;
}

// ---------- denní bilance (režim „Den") ----------
function lastVal(arr) { for (let i = arr.length - 1; i >= 0; i--) if (arr[i] != null) return arr[i]; return null; }

function balanceHtml(d) {
  const lv = (name) => lastVal(d.data[F[name]]);
  const k = (v) => (v == null ? '–' : (+v).toFixed(1).replace('.', ','));
  const soc = lv('selfSufficiencyRate');
  const items = [
    ['Výroba DC', k(lv('totalProduction')), 'kWh'],
    ['Výroba AC', k(lv('totalProductionInclBatt')), 'kWh'],
    ['Spotřeba', k(lv('totalConsumption')), 'kWh'],
    ['Ze sítě', k(lv('totalGridIn')), 'kWh'],
    ['Do sítě', k(lv('totalGridOut')), 'kWh'],
    ['Nabito', k(lv('totalChargedIn')), 'kWh'],
    ['Vybito', k(lv('totalChargedOut')), 'kWh'],
    ['Soběstačnost', soc == null ? '–' : Math.round(soc), '%'],
  ];
  return items.map(([l, v, u]) =>
    `<div class="bal-item"><span class="bal-label">${l}</span><span class="bal-val">${v}<small>${u}</small></span></div>`).join('');
}

// ---------- denní sloupcový přehled (Týden/Měsíc) ----------
async function loadDaily(range) {
  let d;
  try {
    const r = await fetch('?api=daily&range=' + range, { cache: 'no-store' });
    d = await r.json();
  } catch (e) { return; }
  const el = document.getElementById('chartDaily');
  if (!d.ok || !d.count) { el.innerHTML = '<p style="color:#8b949e">Žádná data pro toto období.</p>'; return; }

  const max = Math.max(1, ...d.production.map((v) => v || 0), ...d.consumption.map((v) => v || 0));
  const fmt = (v) => (v == null ? '–' : v.toFixed(1).replace('.', ','));
  const h = (v) => ((v || 0) / max * 100).toFixed(1);
  const bars = d.ts.map((t, i) => {
    const dt = new Date(t * 1000);
    const lbl = `${dt.getDate()}.${dt.getMonth() + 1}.`;
    const p = d.production[i], c = d.consumption[i];
    return `<div class="day"><div class="bars">` +
      `<i class="prod" style="height:${h(p)}%" title="${lbl} výroba ${fmt(p)} kWh"></i>` +
      `<i class="cons" style="height:${h(c)}%" title="${lbl} spotřeba ${fmt(c)} kWh"></i>` +
      `</div><span class="dlabel">${lbl}</span></div>`;
  }).join('');
  el.innerHTML =
    `<div class="daily-legend"><span><i style="background:var(--accent)"></i>Výroba</span>` +
    `<span><i style="background:var(--blue)"></i>Spotřeba</span></div>` +
    `<div class="daily">${bars}</div>`;
}

// ---------- ovládání ----------
function applyRange(range) {
  currentRange = range;
  const isDay = range === 'day';
  const isAgg = range === 'week' || range === 'month';
  document.getElementById('daynav').hidden = !isDay;
  document.getElementById('balance').hidden = !isDay;
  document.getElementById('cardPower').hidden = isAgg;   // řádkové grafy jen Živě/Den
  document.getElementById('cardSoc').hidden = isAgg;
  document.getElementById('cardDaily').hidden = !isAgg;  // sloupce jen Týden/Měsíc
  if (isDay) { currentDay = todayStr(); syncDayNav(); }
  if (isAgg) loadDaily(range);
  else loadSeries(range);
}

document.querySelectorAll('.range button').forEach((b) => {
  b.addEventListener('click', () => {
    document.querySelector('.range button.active').classList.remove('active');
    b.classList.add('active');
    applyRange(b.dataset.range);
  });
});

document.getElementById('dayPrev').addEventListener('click', () => stepDay(-1));
document.getElementById('dayNext').addEventListener('click', () => stepDay(1));
document.getElementById('dayPick').addEventListener('change', (e) => {
  if (e.target.value) setDay(e.target.value);
});

window.addEventListener('resize', () => {
  const w = document.querySelector('main').clientWidth - 32;
  Object.values(charts).forEach((c) => c && c.setSize({ width: w, height: 260 }));
});

// ---------- smyčky ----------
refreshCurrent();
loadSeries(currentRange);
setInterval(refreshCurrent, 5000);
setInterval(() => { if (currentRange === 'live') loadSeries('live'); }, 15000);
