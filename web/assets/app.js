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
const L = window.LIMITS || { peak1: 5000, peak2: 5000, maxPower: 10000, maxLoad: 16000 };

const W = (v) => (v == null ? '–' : Math.round(v).toLocaleString('cs-CZ') + ' W');
const kWh = (v, d = 1) => (v == null ? '–' : v.toFixed(d).replace('.', ',') + ' kWh');
const pct = (v) => (v == null ? '–' : Math.round(v) + ' %');
const deg = (v) => (v == null ? '–' : Math.round(v) + ' °C');
const mode = (v) => (v == null || INVERTER_MODE[v] == null ? '–' : INVERTER_MODE[v]);

// ---------- živé dlaždice ----------
async function refreshCurrent() {
  let s;
  try {
    const r = await fetch('?api=current', { cache: 'no-store' });
    s = (await r.json()).sample;
  } catch (e) { setStatus('chyba spojení', 'stale'); return; }

  if (!s) { setStatus('zatím žádná data', 'stale'); return; }
  setStatus(s.age <= 30 ? 'živě' : `naposledy před ${s.age}s`, s.age <= 30 ? 'live' : 'stale');

  const signed = (v, unit) => {
    const cls = v >= 0 ? 'pos' : 'neg';
    return `<span class="val ${cls}">${unit(v)}</span>`;
  };

  document.getElementById('tiles').innerHTML = `
    <div class="group">
      <h3>Panely</h3>
      <div class="row"><span class="label">Celkem</span><span class="val big">${W(s.totalPower)}</span></div>
      <div class="bar"><i style="width:${bar(s.totalPower, L.peak1 + L.peak2)}%"></i></div>
      <div class="row"><span class="label">String 1</span><span class="val">${W(s.pv1Power)}</span></div>
      <div class="bar"><i style="width:${bar(s.pv1Power, L.peak1)}%"></i></div>
      <div class="row"><span class="label">String 2</span><span class="val">${W(s.pv2Power)}</span></div>
      <div class="bar"><i style="width:${bar(s.pv2Power, L.peak2)}%"></i></div>
      <div class="row"><span class="label">Dnes DC</span><span class="val">${kWh(s.totalProduction)}</span></div>
    </div>
    <div class="group">
      <h3>Baterie</h3>
      <div class="row"><span class="label">Nabití</span><span class="val big">${pct(s.batterySoC)}</span></div>
      <div class="bar"><i style="width:${bar(s.batterySoC, 100)}%"></i></div>
      <div class="row"><span class="label">${s.batteryPower >= 0 ? 'Nabíjení' : 'Vybíjení'}</span>${signed(s.batteryPower, W)}</div>
      <div class="row"><span class="label">Kapacita</span><span class="val">${kWh(s.batteryCap)}</span></div>
      <div class="row"><span class="label">Dnes nabito</span><span class="val">${kWh(s.totalChargedIn)}</span></div>
      <div class="row"><span class="label">Dnes vybito</span><span class="val">${kWh(s.totalChargedOut)}</span></div>
      <div class="row"><span class="label">Teplota</span><span class="val">${deg(s.batteryTemp)}</span></div>
    </div>
    <div class="group">
      <h3>Střídač <span class="badge">${mode(s.inverterMode)}</span></h3>
      <div class="row"><span class="label">Výkon</span><span class="val big">${W(s.inverterPower)}</span></div>
      <div class="bar"><i style="width:${bar(s.inverterPower, L.maxPower)}%"></i></div>
      <div class="row"><span class="label">Dnes AC</span><span class="val">${kWh(s.totalProductionInclBatt)}</span></div>
      <div class="row"><span class="label">Teplota</span><span class="val">${deg(s.inverterTemp)}</span></div>
    </div>
    <div class="group">
      <h3>Distribuční síť</h3>
      <div class="row"><span class="label">${s.feedInPower >= 0 ? 'Dodávka' : 'Odběr'}</span>${signed(s.feedInPower, W)}</div>
      <div class="row"><span class="label">Dnes odebráno</span><span class="val">${kWh(s.totalGridIn, 2)}</span></div>
      <div class="row"><span class="label">Dnes dodáno</span><span class="val">${kWh(s.totalGridOut, 2)}</span></div>
    </div>
    <div class="group">
      <h3>Dům</h3>
      <div class="row"><span class="label">Aktuální odběr</span><span class="val big">${W(s.load)}</span></div>
      <div class="bar"><i style="width:${bar(s.load, L.maxLoad)}%"></i></div>
      <div class="row"><span class="label">Dnes spotřeba</span><span class="val">${kWh(s.totalConsumption, 2)}</span></div>
      <div class="row"><span class="label">Soběstačnost</span><span class="val">${pct(s.selfSufficiencyRate)}</span></div>
      <div class="bar"><i style="width:${bar(s.selfSufficiencyRate, 100)}%"></i></div>
    </div>`;
}

const bar = (v, max) => Math.max(0, Math.min(100, ((v || 0) / max) * 100)).toFixed(0);

function setStatus(text, cls) {
  const el = document.getElementById('status');
  el.textContent = text;
  el.className = 'status ' + (cls || '');
}

// ---------- grafy ----------
let currentRange = 'live';
const charts = {};

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
  let d;
  try {
    const r = await fetch('?api=series&range=' + range, { cache: 'no-store' });
    d = await r.json();
  } catch (e) { return; }
  if (!d.ok || !d.count) { drawEmpty(); return; }

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
  const sSeries = [{}, { label: 'SoC %', stroke: '#f5b301', width: 1.5, fill: 'rgba(245,179,1,.12)' }];
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

// ---------- ovládání ----------
document.querySelectorAll('.range button').forEach((b) => {
  b.addEventListener('click', () => {
    document.querySelector('.range button.active').classList.remove('active');
    b.classList.add('active');
    currentRange = b.dataset.range;
    loadSeries(currentRange);
  });
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
