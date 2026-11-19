// True mobile screenshots via Chrome DevTools Protocol device emulation.
// Usage: node design-samples/render-mobile.mjs
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { setTimeout as sleep } from 'node:timers/promises';

const chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const port = 9223;
const root = 'C:/xampp/htdocs/www/agentqai.cx';
const out = `${root}/design-samples/screenshots`;
mkdirSync(out, { recursive: true });

const samples = [
  ['01-console', '01-console.html'],
  ['02-ledger', '02-ledger.html'],
  ['03-orbit', '03-orbit.html'],
  ['04-handoff', '04-handoff.html'],
  ['05-north', '05-north.html'],
];

const profile = `${process.env.TEMP}\\dsh-chrome-cdp`;
const proc = spawn(chrome, [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
  '--no-first-run', '--no-default-browser-check', '--disable-breakpad',
  '--disable-crash-reporter', '--disable-background-networking',
  `--remote-debugging-port=${port}`, '--remote-allow-origins=*',
  `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore' });

async function waitForDevtools() {
  for (let i = 0; i < 60; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${port}/json/version`);
      if (r.ok) return;
    } catch {}
    await sleep(200);
  }
  throw new Error('DevTools did not start');
}

function rpc(ws) {
  let id = 0;
  const pending = new Map();
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const { res, rej } = pending.get(m.id);
      pending.delete(m.id);
      m.error ? rej(new Error(m.error.message)) : res(m.result);
    }
  });
  return (method, params = {}) => new Promise((res, rej) => {
    const i = ++id;
    pending.set(i, { res, rej });
    ws.send(JSON.stringify({ id: i, method, params }));
  });
}

async function shot(url, file, width, height, mobile = true) {
  const target = await (await fetch(
    `http://127.0.0.1:${port}/json/new?${encodeURIComponent(url)}`,
    { method: 'PUT' },
  )).json();
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener('open', r, { once: true }));
  const send = rpc(ws);
  await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', {
    width, height, deviceScaleFactor: 2, mobile,
  });
  const loaded = new Promise((resolve) => {
    const h = (ev) => {
      const m = JSON.parse(ev.data);
      if (m.method === 'Page.loadEventFired') {
        ws.removeEventListener('message', h);
        resolve();
      }
    };
    ws.addEventListener('message', h);
  });
  await send('Page.navigate', { url });
  await loaded;
  await sleep(700);
  const { data } = await send('Page.captureScreenshot', { format: 'png', fromSurface: true });
  writeFileSync(file, Buffer.from(data, 'base64'));
  ws.close();
  await fetch(`http://127.0.0.1:${port}/json/close/${target.id}`).catch(() => {});
  console.log('mobile', file.split('/').pop(), width + 'x' + height);
}

try {
  await waitForDevtools();
  for (const [name, file] of samples) {
    const url = `file:///${root}/design-samples/landing/${file}`;
    await shot(url, `${out}/${name}-mobile.png`, 390, 844);
  }
  await shot(`file:///${root}/design-samples/landing/02-ledger.html`, `${out}/02-ledger-desktop.png`, 1440, 1000, false);
  await shot(`file:///${root}/design-samples/index.html`, `${out}/index-desktop.png`, 1440, 1500, false);
  console.log('done');
} finally {
  proc.kill();
}
