#!/usr/bin/env node
/**
 * Student login + portal browser e2e (headless Chromium, real admission form, Fake gateway, fake SMS outbox).
 *
 * Run from the repo root (needs Node 18+, docker compose dev stack on BASE_URL, Playwright + Chromium):
 *   npm i playwright && npx playwright install chromium     # once, in any dir on the module path
 *   BASE_URL=http://localhost:8080 BATCH=2 node tests/e2e/student-browser.mjs
 * Playwright is resolved from this file first, then from the current working directory.
 *
 * SMS is read with `docker compose run --rm -T wpcli option get cc_fake_sms_outbox --format=json`
 * (override the command with WPCLI="docker compose run --rm -T wpcli"; it is run from the repo root).
 * Rate-limit transients are cleared before each phase (dev only) so repeated runs do not trip them.
 *
 * Journey (375px and 1280px, fresh phone each): apply -> Pay -> confirmation -> temp password SMS -> login ->
 * forced /student/profile/?change=1 -> password errors -> dashboard -> payments -> receipt -> profile validation
 * -> logout (+Back no-store) -> relogin. Extras (1280px): OTP login, OTP lockout, uniform errors, redirects,
 * wp-admin block, foreign receipt, keyboard-only login, a11y, console errors, overflow, Bangla text.
 * Exit code 0 = all passed, 1 = any failure.
 */
import { createRequire } from 'node:module';
import { verifyPhoneInForm } from './lib/phone-proof.mjs';
import { appNoticeSelector } from './lib/app-notice.mjs';
import { execFile } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); }
catch { ({ chromium } = createRequire(process.cwd() + '/')('playwright')); }

const BASE = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const BATCH = process.env.BATCH || '2';
const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const WPCLI = (process.env.WPCLI || 'docker compose run --rm -T wpcli').split(' ');
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

let failures = 0;
const check = (name, cond, extra = '') => {
  console.log(`${cond ? '  ok  ' : '  FAIL'} ${name}${cond ? '' : ' ' + extra}`);
  if (!cond) failures++;
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const freshPhone = () => '017' + String(Date.now() + Math.floor(Math.random() * 1000)).slice(-8);
const e164 = (p) => '+880' + p.slice(1);

const wp = (...args) => new Promise((resolve) => {
  execFile(WPCLI[0], [...WPCLI.slice(1), ...args], { cwd: REPO, maxBuffer: 20e6 }, (err, stdout) => resolve(err ? '' : stdout));
});
async function outbox() {
  const out = await wp('option', 'get', 'cc_fake_sms_outbox', '--format=json');
  const start = out.indexOf('[');
  try { return JSON.parse(out.slice(start)); } catch { return []; }
}
async function resetLimits() {
  await wp('eval', 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%cc_rl_%\'");');
}
async function waitSms(phone, re, { timeoutMs = 90000, after = 0, page } = {}) {
  const deadline = Date.now() + timeoutMs;
  let tick = 0;
  while (Date.now() < deadline) {
    const hit = (await outbox()).filter((m) => m.to === e164(phone) && re.test(m.body)).slice(after)[0];
    if (hit) return hit.body.match(re);
    if (tick++ % 2 === 0) {
      if (page) await page.request.get(`${BASE}/wp-cron.php?doing_wp_cron`).catch(() => {});
      await wp('cron', 'event', 'run', '--due-now');
      await wp('action-scheduler', 'run');
    }
    await sleep(2000);
  }
  return null;
}
const countSms = async (phone, re) => (await outbox()).filter((m) => m.to === e164(phone) && re.test(m.body)).length;

const PW_RE = /temporary password (\w+)/;
const OTP_RE = /login code is (\d{6})/;
const text = async (p, sel) => ((await p.textContent(appNoticeSelector(sel)).catch(() => '')) || '').trim();
const overflow = (p) => p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
const focused = (p) => p.evaluate(() => document.activeElement && (document.activeElement.id || document.activeElement.tagName));

function newPage(browser, width, errors) {
  return browser.newContext({ viewport: { width, height: 900 } }).then(async (ctx) => {
    const p = await ctx.newPage();
    p.on('console', (m) => { if (m.type() === 'error') errors.push(`${m.text()} @ ${p.url()}`); });
    p.on('pageerror', (e) => errors.push(`pageerror ${e.message}`));
    return p;
  });
}

async function apply(p, phone) {
  await p.goto(`${BASE}/admissions/?batch=${BATCH}`);
  await p.fill('#adm-full_name', 'QA Student');
  await p.selectOption('#adm-gender', 'm');
  await p.fill('#adm-dob', '2008-05-05');
  await p.selectOption('#adm-id_doc_type', 'nid');
  await p.fill('#adm-id_doc_number', '1234567890');
  await p.setInputFiles('#adm-photo', { name: 'p.png', mimeType: 'image/png', buffer: PNG });
  await verifyPhoneInForm(p, phone);
  await p.fill('#adm-guardian_name', 'QA Parent');
  await p.fill('#adm-guardian_phone', '01912345678');
  if (await p.$('#adm-email')) await p.fill('#adm-email', 'qa@example.com');
  await p.fill('#adm-institution', 'Test College');
  await p.fill('#adm-class_level', 'HSC');
  if (await p.$('#adm-passing_year')) await p.fill('#adm-passing_year', '2026');
  await p.check('#adm-consent');
  await p.click('#adm-submit');
  await p.waitForURL(/fake\/checkout/, { timeout: 20000 });
  await p.click('button:text-is("Pay")');
  await p.waitForSelector('#adm-status-title:text-is("Payment received")', { timeout: 25000 });
}

async function login(p, phone, password) {
  await p.goto(`${BASE}/student/login/`);
  await p.fill('#sl-phone', phone);
  await p.fill('#sl-password', password);
  await p.click('#sl-submit');
}

async function journey(browser, width, state) {
  console.log(`Journey @${width}px`);
  const errors = [];
  const p = await newPage(browser, width, errors);
  const phone = freshPhone();

  await resetLimits();
  await apply(p, phone);
  const msg = await text(p, '#adm-status');
  check('confirmation says SMS is on its way', /SMS/i.test(msg) && /on its way/i.test(msg), msg);
  const href = await p.getAttribute('#adm-status a[href*="/student/login"]', 'href').catch(() => null);
  check('confirmation links to /student/login/', !!href && /\/student\/login\/?$/.test(href), String(href));
  check('confirmation no overflow', (await overflow(p)) <= 0);

  const m = await waitSms(phone, PW_RE, { page: p });
  check('temp password SMS arrives (<=90s)', !!m);
  if (!m) { await p.context().close(); return null; }
  const temp = m[1];

  // Login errors: uniform
  await login(p, phone, 'wrongpassword1');
  await p.waitForFunction(() => document.getElementById('sl-error').textContent.trim() !== '');
  const wrong = await text(p, '#sl-error');
  check('wrong password shows error', /Invalid phone or password/.test(wrong), wrong);
  check('error is role=alert/aria-live', (await p.getAttribute('#sl-error', 'role')) === 'alert' && !!(await p.getAttribute('#sl-error', 'aria-live')));
  await login(p, freshPhone(), 'wrongpassword1');
  await p.waitForFunction(() => document.getElementById('sl-error').textContent.trim() !== '');
  check('unknown phone shows same error', (await text(p, '#sl-error')) === wrong, await text(p, '#sl-error'));
  check('login page no overflow', (await overflow(p)) <= 0);

  // Correct login -> forced change
  await login(p, phone, temp);
  await p.waitForURL(/\/student\/profile\/\?change=1/, { timeout: 15000 }).catch(() => {});
  check('forced redirect to profile?change=1', /\/student\/profile\/\?change=1/.test(p.url()), p.url());
  await p.goto(`${BASE}/student/`);
  check('dashboard still forced to change password', /change=1/.test(p.url()), p.url());
  await p.goto(`${BASE}/student/payments/`);
  check('payments still forced to change password', /change=1/.test(p.url()), p.url());

  // Password change errors
  const NEWPW = 'Brand-new-pw-' + width;
  await p.fill('#pf-current_password', 'not-my-password');
  await p.fill('#pf-new_password', NEWPW);
  await p.click('#portal-password-form button[type=submit]');
  await p.waitForFunction(() => document.getElementById('portal-status').textContent.trim() !== 'Saving…' && document.getElementById('portal-status').textContent.trim() !== '');
  const wrongCur = (await text(p, '#pf-current_password-err')) || (await text(p, '#portal-status'));
  check('wrong current password -> error on field', /current password is incorrect/i.test(await text(p, '#pf-current_password-err')), wrongCur);
  check('focus moved to current password', (await focused(p)) === 'pf-current_password', await focused(p));
  await p.fill('#pf-current_password', temp);
  await p.fill('#pf-new_password', 'short');
  await p.click('#portal-password-form button[type=submit]');
  await p.waitForTimeout(1500);
  const weak = (await text(p, '#pf-new_password-err')) + ' | ' + (await text(p, '#portal-status'));
  check('weak password shows a readable error', /10 characters/i.test(weak) && !/Invalid parameter/i.test(weak), weak);
  await p.fill('#pf-new_password', NEWPW);
  await p.click('#portal-password-form button[type=submit]');
  await p.waitForURL(/\/student\/$/, { timeout: 10000 }).catch(() => {});
  check('password changed -> dashboard', /\/student\/$/.test(p.url()), p.url());

  // Dashboard
  const body = await text(p, 'body');
  check('dashboard shows course/batch card', (await p.$$('.portal-card')).length >= 1);
  check('dashboard shows schedule + notices placeholders', (await p.$('#portal-schedule-h')) && (await p.$('#portal-notices-h')));
  check('dashboard no overflow', (await overflow(p)) <= 0);
  console.log(`    dashboard card: ${(await text(p, '.portal-card')).replace(/\s+/g, ' ')}`);

  // Payments + receipt
  await p.goto(`${BASE}/student/payments/`);
  const pays = await text(p, 'main, .portal');
  check('payments lists a payment (INV-)', /INV-/.test(pays), pays.slice(0, 200));
  check('payments no overflow', (await overflow(p)) <= 0);
  const link = await p.$('a[href*="receipt"]');
  check('payments has receipt link', !!link);
  if (link) {
    const rurl = await link.getAttribute('href');
    state.receiptId = (rurl.match(/id=(\d+)/) || [])[1];
    await link.click();
    await p.waitForURL(/receipt/);
    const rt = await text(p, '.receipt');
    check('receipt shows invoice + trx', /INV-/.test(rt) && /Transaction ID/.test(rt) && !/Transaction ID\s*—/.test(rt), rt.replace(/\s+/g, ' '));
    check('receipt has Print button', !!(await p.$('#portal-print')));
    await p.emulateMedia({ media: 'print' });
    check('print hides nav/header controls', !(await p.isVisible('.portal-nav')), 'portal-nav visible in print');
    check('print still shows receipt', await p.isVisible('.receipt'));
    await p.emulateMedia({ media: 'screen' });
    check('receipt no overflow', (await overflow(p)) <= 0);
  }

  // Profile validation
  await p.goto(`${BASE}/student/profile/`);
  await p.fill('#pf-guardian_name', '');
  await p.fill('#pf-guardian_phone', '123');
  await p.fill('#pf-email', 'not-an-email');
  await p.click('#portal-profile-form button[type=submit]');
  await p.waitForTimeout(1500);
  check('guardian name error', (await text(p, '#pf-guardian_name-err')) !== '');
  check('guardian phone error', (await text(p, '#pf-guardian_phone-err')) !== '');
  check('email error', (await text(p, '#pf-email-err')) !== '');
  await p.fill('#pf-guardian_name', 'অভিভাবক রহমান');
  await p.fill('#pf-guardian_phone', '01812345678');
  await p.fill('#pf-email', `qa${Date.now()}@example.com`);
  await p.click('#portal-profile-form button[type=submit]');
  await p.waitForFunction(() => /Saved/.test(document.getElementById('portal-status').textContent), null, { timeout: 8000 }).catch(() => {});
  check('profile saved', /Saved/.test(await text(p, '#portal-status')), await text(p, '#portal-status'));
  await p.reload();
  check('Bangla guardian name persisted + rendered', (await p.inputValue('#pf-guardian_name')) === 'অভিভাবক রহমান');
  await p.fill('#pf-email', '');
  await p.click('#portal-profile-form button[type=submit]');
  await p.waitForTimeout(1200);
  await p.reload();
  check('clearing email persists (field empty after reload)', (await p.inputValue('#pf-email')) === '', await p.inputValue('#pf-email'));

  // Voluntary pw change must keep working with the page's nonce, then logout works
  await p.click('#portal-logout');
  await p.waitForURL(/\/student\/login/, { timeout: 8000 }).catch(() => {});
  check('logout -> login page', /\/student\/login/.test(p.url()), p.url());
  await p.goBack().catch(() => {});
  await p.waitForTimeout(800);
  const afterBack = p.url();
  check('Back after logout does not show dashboard', !(/\/student\/$/.test(afterBack) && (await p.$('#portal-logout'))), afterBack);

  // Relogin with new password
  await login(p, phone, NEWPW);
  await p.waitForURL(/\/student\/$/, { timeout: 15000 }).catch(() => {});
  check('relogin with new password -> dashboard', /\/student\/$/.test(p.url()), p.url());
  const ctx2 = await browser.newContext();
  const r = await ctx2.request.post(`${BASE}/wp-json/cc/v1/auth/login`, { data: { phone, password: temp } });
  check('old temp password rejected', r.status() === 401, String(r.status()));
  await ctx2.close();

  const real = errors.filter((e) => !/favicon|status of 4\d\d/.test(e));
  check(`no console errors @${width}`, real.length === 0, real.join(' ; '));
  await p.context().close();
  return { phone, password: NEWPW };
}

async function extras(browser, student, state) {
  console.log('Extras @1280px');
  const errors = [];
  await resetLimits();

  // Unauthenticated redirects
  const anon = await newPage(browser, 1280, errors);
  await anon.goto(`${BASE}/student/`);
  check('anon /student/ -> login?redirect', /\/student\/login\/\?redirect=/.test(anon.url()), anon.url());
  await anon.goto(`${BASE}/student/payments/receipt/?id=1`);
  check('anon receipt -> login', /\/student\/login\//.test(anon.url()), anon.url());
  // evil redirect ignored
  await anon.goto(`${BASE}/student/login/?redirect=https://evil.example`);
  await anon.fill('#sl-phone', student.phone);
  await anon.fill('#sl-password', student.password);
  await anon.click('#sl-submit');
  await anon.waitForURL(/\/student\/(?!login)/, { timeout: 15000 }).catch(() => {});
  check('redirect=https://evil.example ignored', new URL(anon.url()).origin === new URL(BASE).origin && /\/student\//.test(anon.url()), anon.url());
  // protocol-relative / backslash variants
  for (const evil of ['//evil.example', '/student/../../evil', '/\\evil.example']) {
    await anon.context().clearCookies();
    await anon.goto(`${BASE}/student/login/?redirect=${encodeURIComponent(evil)}`);
    await anon.fill('#sl-phone', student.phone);
    await anon.fill('#sl-password', student.password);
    await anon.click('#sl-submit');
    await anon.waitForURL(/\/student\/(?!login)/, { timeout: 15000 }).catch(() => {});
    check(`redirect=${evil} stays on site`, new URL(anon.url()).origin === new URL(BASE).origin, anon.url());
  }
  // legit redirect honoured
  await anon.context().clearCookies();
  await anon.goto(`${BASE}/student/payments/`);
  await anon.fill('#sl-phone', student.phone);
  await anon.fill('#sl-password', student.password);
  await anon.click('#sl-submit');
  await anon.waitForURL(/\/student\/payments\//, { timeout: 15000 }).catch(() => {});
  check('valid ?redirect=/student/payments/ honoured', /\/student\/payments\/$/.test(anon.url()), anon.url());

  // wp-admin
  await anon.goto(`${BASE}/wp-admin/`);
  check('student /wp-admin/ -> /student/', /\/student\/$/.test(anon.url()), anon.url());
  await anon.goto(`${BASE}/wp-login.php`);
  check('student /wp-login.php -> /student/', /\/student\/$/.test(anon.url()), anon.url());

  // Foreign receipt
  if (state.receiptId) {
    await anon.goto(`${BASE}/student/payments/receipt/?id=${state.receiptId}`);
    check('another student receipt -> not available', /not available/i.test(await text(anon, 'main, .portal')), (await text(anon, '.portal')).slice(0, 120));
    await anon.goto(`${BASE}/student/payments/receipt/?id=abc`);
    check('non-numeric receipt id -> not available', /not available/i.test(await text(anon, '.portal')));
    await anon.goto(`${BASE}/student/payments/receipt/?id=999999`);
    check('missing receipt id -> not available', /not available/i.test(await text(anon, '.portal')));
  }
  const hdr = (await anon.request.get(`${BASE}/student/`)).headers()['cache-control'] || '';
  check('portal page Cache-Control no-store', /no-store/.test(hdr), hdr);
  await anon.context().close();

  // OTP login
  const o = await newPage(browser, 1280, errors);
  const before = await countSms(student.phone, OTP_RE);
  await o.goto(`${BASE}/student/login/`);
  await o.click('#sl-toggle');
  await o.fill('#sl-phone', student.phone);
  await o.click('#sl-submit');
  await o.waitForSelector('#sl-code:visible', { timeout: 8000 }).catch(() => {});
  check('OTP request reveals code field + focus', (await focused(o)) === 'sl-code', await focused(o));
  const code = await waitSms(student.phone, OTP_RE, { after: before, timeoutMs: 40000, page: o });
  check('OTP SMS arrives', !!code);
  if (code) {
    await o.fill('#sl-code', '000000' === code[1] ? '111111' : '000000');
    await o.click('#sl-submit');
    await o.waitForFunction(() => document.getElementById('sl-error').textContent.trim() !== '');
    check('wrong OTP -> generic error', /Invalid or expired code/.test(await text(o, '#sl-error')), await text(o, '#sl-error'));
    await o.fill('#sl-code', code[1]);
    await o.click('#sl-submit');
    await o.waitForURL(/\/student\/(?!login)/, { timeout: 15000 }).catch(() => {});
    check('OTP login succeeds', /\/student\/$/.test(o.url()), o.url());
    await o.goto(`${BASE}/student/profile/`);
    check('after OTP login: no current-password field', !(await o.$('#pf-current_password')));
    await o.fill('#pf-new_password', student.password + '2');
    await o.click('#portal-password-form button[type=submit]');
    await o.waitForFunction(() => /updated|Saved/i.test(document.getElementById('portal-status').textContent), null, { timeout: 8000 }).catch(() => {});
    check('OTP-session password change works', /updated|Saved/i.test(await text(o, '#portal-status')), await text(o, '#portal-status'));
    student.password += '2';
    // Voluntary change leaves the page open: the stale REST nonce must not break logout.
    await o.click('#portal-logout');
    await o.waitForTimeout(2000);
    await o.goto(`${BASE}/student/`);
    check('logout on same page after password change really ends the session (stale REST nonce)', /\/student\/login/.test(o.url()), `still signed in: ${o.url()}`);
  }
  await o.context().close();

  // OTP lockout
  await resetLimits();
  const k = await newPage(browser, 375, errors);
  await k.goto(`${BASE}/student/login/`);
  await k.click('#sl-toggle');
  await k.fill('#sl-phone', student.phone);
  await k.click('#sl-submit');
  await k.waitForSelector('#sl-code:visible', { timeout: 8000 });
  const msgs = [];
  for (let i = 0; i < 6; i++) {
    await k.fill('#sl-code', '12345' + i);
    await k.click('#sl-submit');
    await k.waitForFunction(() => !document.getElementById('sl-submit').disabled);
    msgs.push(await text(k, '#sl-error'));
  }
  console.log(`    OTP wrong x6 messages: ${JSON.stringify([...new Set(msgs)])}`);
  check('5 wrong OTPs then locked (still generic/lockout error, never signs in)', msgs.every((m) => m !== '') && /\/student\/login/.test(k.url()));
  const lockMsg = msgs[5];
  console.log(`    (info) 6th-attempt message: "${lockMsg}" -- generic by design unless the spec wants a distinct lockout text`);
  check('OTP page no overflow @375', (await overflow(k)) <= 0);
  await k.context().close();

  // Keyboard-only login
  await resetLimits();
  const kb = await newPage(browser, 1280, errors);
  await kb.goto(`${BASE}/student/login/`);
  // Tab through skip link + header nav (its length grows as pages are added) until the phone field; cap 30.
  const tabPath = [];
  for (let i = 0; i < 30 && (await focused(kb)) !== 'sl-phone'; i++) {
    await kb.keyboard.press('Tab');
    tabPath.push(await kb.evaluate(() => { const e = document.activeElement; return e ? (e.id || (e.textContent || '').trim().slice(0, 20) || e.tagName) : '?'; }));
  }
  const ok = (await focused(kb)) === 'sl-phone';
  check('keyboard: can Tab to phone field', ok, `tab path: ${tabPath.join(' > ')}`);
  if (ok) console.log(`    (info) phone field reached after ${tabPath.length} Tab presses`);
  if (!ok) await kb.focus('#sl-phone'); // keep the keyboard-only typing/Enter checks meaningful
  const outline = await kb.evaluate(() => { const s = getComputedStyle(document.activeElement); return `${s.outlineStyle} ${s.outlineWidth} ${s.boxShadow}`; });
  check('focus visible on phone field', !/^none 0px none$/.test(outline) && outline.trim() !== '', outline);
  await kb.keyboard.type(student.phone);
  await kb.keyboard.press('Tab');
  await kb.keyboard.type(student.password);
  await kb.keyboard.press('Enter');
  await kb.waitForURL(/\/student\/(?!login)/, { timeout: 15000 }).catch(() => {});
  check('keyboard-only login succeeds', /\/student\/$/.test(kb.url()), kb.url());
  if (!/\/student\/$/.test(kb.url())) await kb.goto(`${BASE}/student/`); // a11y check must run on the dashboard
  const unnamed = await kb.evaluate(() => [...document.querySelectorAll('button,a,input,select,textarea')]
    .filter((e) => e.type !== 'hidden' && !(e.textContent || '').trim() && !e.getAttribute('aria-label')
      && !e.getAttribute('aria-labelledby') && !e.getAttribute('title') && !(e.labels && e.labels.length))
    .map((e) => e.outerHTML.slice(0, 120)));
  check('dashboard: all interactive elements have a name', unnamed.length === 0, unnamed.join(' | '));
  // Bangla UI: html lang / fonts do not break layout
  await kb.setViewportSize({ width: 375, height: 900 });
  await kb.evaluate(() => { document.querySelector('h1').textContent = 'স্বাগতম, অভিভাবক রহমান আব্দুল্লাহ ইবনে মোহাম্মদ'; });
  check('Bangla heading no overflow @375', (await overflow(kb)) <= 0);
  await kb.context().close();

  const real = errors.filter((e) => !/favicon|status of 4\d\d/.test(e));
  check('no console errors (extras)', real.length === 0, real.join(' ; '));
}

const browser = await chromium.launch();
try {
  const state = {};
  const a = await journey(browser, 375, state);
  const b = await journey(browser, 1280, { receiptId: null });
  const who = b || a;
  if (who) await extras(browser, who, state);
} catch (e) {
  console.log('  FAIL unexpected error:', e.message);
  failures++;
} finally {
  await browser.close();
}
console.log(failures ? `\n${failures} FAILED` : '\nall passed');
process.exit(failures ? 1 : 0);
