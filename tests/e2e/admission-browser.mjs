#!/usr/bin/env node
/**
 * Admission form browser e2e (headless Chromium, real form, Fake gateway).
 *
 * Run (needs Node 18+, the dev stack on BASE_URL, and Playwright + Chromium):
 *   npm i playwright && npx playwright install chromium     # once, in any dir on the module path
 *   BASE_URL=http://localhost:8080 BATCH=2 node tests/e2e/admission-browser.mjs
 * If playwright is not resolvable from this file, run from a directory that has it in node_modules
 * (the script falls back to resolving from the current working directory).
 *
 * Covers: happy path at 375px and 1280px (submit -> fake checkout -> Pay -> "Payment received",
 * REST status = approved), server-side 422 errors shown on the field itself (bad photo, 1-char institution),
 * duplicate phone message, Fail -> Retry -> Pay. No hidden fields are injected.
 * Every application first proves phone ownership through the form (Verify number -> SMS code from the fake outbox via
 * wpcli -> Confirm code). A dedicated journey covers the verification UI: submit disabled before verify, wrong code,
 * success locks the field, editing the phone resets it, resend cooldown, lockout after 5 wrong codes, server-side refusal
 * of a bad proof, keyboard operation. Rate-limit messages are not testable here (the limiter is off locally): noted below.
 * Exit code 0 = all passed, 1 = any failure.
 */
import { createRequire } from 'node:module';
import { verifyPhoneInForm, readApplyCode } from './lib/phone-proof.mjs';
import { appNoticeSelector } from './lib/app-notice.mjs';
// Resolve playwright from the repo/script location first, then from the current working directory.
let chromium;
try { ({ chromium } = await import('playwright')); }
catch { ({ chromium } = createRequire(process.cwd() + '/')('playwright')); }

const BASE = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const BATCH = process.env.BATCH || '2';
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const photo = { name: 'p.png', mimeType: 'image/png', buffer: PNG };
const fakePhoto = { name: 'fake.png', mimeType: 'image/png', buffer: Buffer.from('not an image') };

let failures = 0;
const check = (name, cond, extra = '') => {
  console.log(`${cond ? '  ok  ' : '  FAIL'} ${name}${cond ? '' : ' ' + extra}`);
  if (!cond) failures++;
};
const note = (msg) => console.log(`  note  ${msg}`);
const freshPhone = () => '017' + String(Date.now() + Math.floor(Math.random() * 1000)).slice(-8);

async function fillForm(p, { phone, photoFile = photo, institution = 'Test College', verify = true }) {
  await p.goto(`${BASE}/admissions/?batch=${BATCH}`);
  await p.fill('#adm-full_name', 'QA Tester');
  await p.selectOption('#adm-gender', 'm');
  await p.fill('#adm-dob', '2008-05-05');
  await p.selectOption('#adm-id_doc_type', 'nid');
  await p.fill('#adm-id_doc_number', '1234567890');
  await p.setInputFiles('#adm-photo', photoFile);
  if (verify) await verifyPhoneInForm(p, phone);
  else await p.fill('#adm-student_phone', phone);
  await p.fill('#adm-guardian_name', 'QA Parent');
  await p.fill('#adm-guardian_phone', '01912345678');
  if (await p.$('#adm-email')) await p.fill('#adm-email', 'qa@example.com');
  await p.fill('#adm-institution', institution);
  await p.fill('#adm-class_level', 'HSC');
  if (await p.$('#adm-passing_year')) await p.fill('#adm-passing_year', '2026');
  await p.check('#adm-consent');
}

const submitAndWaitCheckout = async (p) => {
  await p.click('#adm-submit');
  await p.waitForURL(/fake\/checkout/, { timeout: 20000 });
};
const refFrom = async (p) => p.getAttribute('#adm-status', 'data-ref');

async function happyPath(browser, width) {
  console.log(`Happy path @${width}px`);
  const p = await (await browser.newContext({ viewport: { width, height: 900 } })).newPage();
  await fillForm(p, { phone: freshPhone() });
  await submitAndWaitCheckout(p);
  check('reached fake checkout', /fake\/checkout/.test(p.url()));
  await p.click('button:text-is("Pay")');
  await p.waitForSelector('#adm-status', { timeout: 15000 });
  await p.waitForSelector('#adm-status-title:text-is("Payment received")', { timeout: 20000 }).catch(() => {});
  const title = (await p.textContent('#adm-status-title')) || '';
  check('confirmation shows "Payment received"', title.trim() === 'Payment received', `got "${title}"`);
  const ref = await refFrom(p);
  const res = await p.request.get(`${BASE}/wp-json/cc/v1/applications/${ref}/status`);
  const body = await res.json().catch(() => ({}));
  check(`status API approved (ref ${ref})`, body.status === 'approved', JSON.stringify(body));
  check('no horizontal overflow', (await p.evaluate(() => document.documentElement.scrollWidth - innerWidth)) <= 0);
  await p.context().close();
}

async function serverErrors(browser) {
  console.log('Server-side error visibility');
  const p = await (await browser.newContext({ viewport: { width: 375, height: 900 } })).newPage();
  await fillForm(p, { phone: freshPhone(), photoFile: fakePhoto });
  // Client check only looks at MIME type from the upload, so the fake PNG reaches the server.
  await p.click('#adm-submit');
  await p.waitForTimeout(2500);
  const vis = await p.isVisible('#adm-photo-err');
  const msg = ((await p.textContent('#adm-photo-err')) || '').trim();
  check('#adm-photo-err visible with text', vis && msg.length > 0, `visible=${vis} text="${msg}"`);
  check('focus is on photo input', (await p.evaluate(() => document.activeElement.id)) === 'adm-photo');
  await p.keyboard.press('Tab'); // blur must not wipe the server message before the user changes the file
  await p.waitForTimeout(300);
  console.log(`    photo error: "${msg}"`);
  await p.context().close();

  const q = await (await browser.newContext({ viewport: { width: 375, height: 900 } })).newPage();
  await fillForm(q, { phone: freshPhone(), institution: 'X' });
  await q.click('#adm-submit');
  await q.waitForTimeout(2500);
  const imsg = ((await q.textContent('#adm-institution-err').catch(() => '')) || '').trim();
  const stillForm = !/fake\/checkout/.test(q.url());
  console.log(`    institution outcome: url=${q.url()} err="${imsg}"`);
  check('1-char institution: shows field error or is rejected without leaving form', stillForm && imsg.length > 0, `err="${imsg}"`);
  await q.context().close();
}

async function duplicate(browser) {
  console.log('Duplicate phone');
  const phone = freshPhone();
  const p = await (await browser.newContext({ viewport: { width: 375, height: 900 } })).newPage();
  await fillForm(p, { phone });
  await submitAndWaitCheckout(p);
  const q = await (await browser.newContext({ viewport: { width: 375, height: 900 } })).newPage();
  await fillForm(q, { phone });
  await q.click('#adm-submit');
  await q.waitForTimeout(2500);
  const msg = ((await q.textContent('#adm-errors')) || '').trim();
  check('duplicate phone message shown', msg.length > 0 && !/fake\/checkout/.test(q.url()), `msg="${msg}"`);
  console.log(`    message: "${msg}"`);
  await p.context().close();
  await q.context().close();
}

async function failRetryPay(browser) {
  console.log('Fail -> Retry -> Pay');
  const p = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await fillForm(p, { phone: freshPhone() });
  await submitAndWaitCheckout(p);
  await p.click('button:text-is("Fail")');
  await p.waitForSelector('text=Retry payment', { timeout: 20000 });
  check('failed state offers Retry', true);
  await p.click('text=Retry payment');
  await p.waitForURL(/fake\/checkout/, { timeout: 15000 });
  await p.click('button:text-is("Pay")');
  await p.waitForSelector('#adm-status-title:text-is("Payment received")', { timeout: 25000 }).catch(() => {});
  check('Pay after retry -> Payment received', ((await p.textContent('#adm-status-title')) || '').trim() === 'Payment received');
  await p.context().close();
}

const text = async (p, sel) => ((await p.textContent(appNoticeSelector(sel)).catch(() => '')) || '').trim();

async function phoneVerification(browser) {
  console.log('Phone verification journey @375px');
  const ctx = await browser.newContext({ viewport: { width: 375, height: 900 } });
  const p = await ctx.newPage();
  const phone = freshPhone();
  await p.clock.install();
  await fillForm(p, { phone, verify: false });

  check('submit is disabled before verification', await p.isDisabled('#adm-submit'));
  check('hint explains why submit is disabled', await p.isVisible('#adm-submit-hint'));
  const attrs = await p.evaluate(() => {
    const c = document.getElementById('adm-verify-code');
    const m = document.getElementById('adm-verify-msg');
    return { mode: c.getAttribute('inputmode'), ac: c.getAttribute('autocomplete'), max: c.maxLength, live: m.getAttribute('aria-live'), label: !!document.querySelector('label[for="adm-verify-code"]'), proof: document.getElementById('adm-phone_proof').value };
  });
  check('code field is numeric, one-time-code, 6 digits, labelled; message is a live region', attrs.mode === 'numeric' && attrs.ac === 'one-time-code' && attrs.max === 6 && attrs.label && attrs.live === 'polite', JSON.stringify(attrs));
  check('Verify number button is visible, code field hidden', (await p.isVisible('#adm-verify-send')) && !(await p.isVisible('#adm-verify-code-wrap')));

  // Keyboard only: focus the button, press Enter.
  await p.focus('#adm-verify-send');
  await p.keyboard.press('Enter');
  await p.waitForSelector('#adm-verify-code-wrap:not([hidden])', { timeout: 15000 });
  const sent = await text(p, '#adm-verify-msg');
  check('"code sent to <masked number>" announced', /Code sent to \+88017\*+\d{3}/.test(sent), sent);
  check('focus moves to the code field', (await p.evaluate(() => document.activeElement.id)) === 'adm-verify-code');
  const resendText = await text(p, '#adm-verify-resend');
  check('resend is disabled with a visible countdown', (await p.isDisabled('#adm-verify-resend')) && /Resend code in \d+s/.test(resendText), resendText);
  check('submit still disabled after sending', await p.isDisabled('#adm-submit'));

  // Wrong code.
  const real = await readApplyCode(phone);
  check('code read from the fake outbox', !!real);
  const wrong = real === '111111' ? '222222' : '111111';
  await p.fill('#adm-verify-code', wrong);
  await p.keyboard.press('Enter');
  await p.waitForFunction(() => /not correct/i.test(document.getElementById('adm-verify-msg').textContent), null, { timeout: 15000 });
  check('wrong code: error message, still unverified', /not correct or has expired/i.test(await text(p, '#adm-verify-msg')) && (await p.isDisabled('#adm-submit')));
  check('wrong code: focus returns to the code field', (await p.evaluate(() => document.activeElement.id)) === 'adm-verify-code');
  await p.fill('#adm-verify-code', '12ab');
  check('non-digits are stripped from the code field', (await p.inputValue('#adm-verify-code')) === '12');
  await p.click('#adm-verify-confirm');
  check('short code is refused client-side', /6-digit/.test(await text(p, '#adm-verify-msg')));

  // Cooldown ends (fake clock), resend works.
  await p.clock.fastForward(61000);
  check('resend enabled after the cooldown', !(await p.isDisabled('#adm-verify-resend')) && (await text(p, '#adm-verify-resend')) === 'Resend code');
  await p.click('#adm-verify-resend');
  await p.waitForFunction(() => /Resend code in/.test(document.getElementById('adm-verify-resend').textContent), null, { timeout: 15000 });
  check('resend sent a new code and restarted the cooldown', /Code sent to/.test(await text(p, '#adm-verify-msg')) && (await p.isDisabled('#adm-verify-resend')));

  // Correct code locks the number.
  const fresh = await readApplyCode(phone);
  await p.fill('#adm-verify-code', fresh);
  await p.keyboard.press('Enter');
  await p.waitForFunction(() => /verified/i.test(document.getElementById('adm-verify-msg').textContent), null, { timeout: 15000 });
  check('verified message shown with a check mark', /✓ Number verified/.test(await text(p, '#adm-verify-msg')));
  check('phone field is read-only after verifying', await p.evaluate(() => document.getElementById('adm-student_phone').readOnly));
  check('submit is enabled and the hint is gone', !(await p.isDisabled('#adm-submit')) && !(await p.isVisible('#adm-submit-hint')));
  check('a proof is held for the server', (await p.inputValue('#adm-phone_proof')).length > 20);
  check('code row is hidden once verified', !(await p.isVisible('#adm-verify-code-wrap')));
  check('no horizontal overflow', (await p.evaluate(() => document.documentElement.scrollWidth - innerWidth)) <= 0);

  // Editing the phone resets verification.
  await p.click('#adm-verify-change');
  check('Change number unlocks the field and drops the proof', !(await p.evaluate(() => document.getElementById('adm-student_phone').readOnly)) && (await p.inputValue('#adm-phone_proof')) === '' && (await p.isDisabled('#adm-submit')));
  check('verify button is back after changing', await p.isVisible('#adm-verify-send'));
  check('focus is on the phone field', (await p.evaluate(() => document.activeElement.id)) === 'adm-student_phone');

  // Typing a different number after a code was sent also starts over.
  const phone2 = freshPhone();
  await p.fill('#adm-student_phone', phone2);
  await p.click('#adm-verify-send');
  await p.waitForSelector('#adm-verify-code-wrap:not([hidden])', { timeout: 15000 });
  await p.fill('#adm-student_phone', phone2.slice(0, -1) + ((+phone2.slice(-1) + 1) % 10));
  await p.waitForFunction(() => document.getElementById('adm-verify-code-wrap').hidden, null, { timeout: 5000 });
  check('editing the number after sending a code resets the widget', (await p.isVisible('#adm-verify-send')) && /changed/i.test(await text(p, '#adm-verify-msg')));

  // Lockout after 5 wrong codes.
  const phone3 = freshPhone();
  await p.fill('#adm-student_phone', phone3);
  await p.click('#adm-verify-send');
  await p.waitForSelector('#adm-verify-code-wrap:not([hidden])', { timeout: 15000 });
  const real3 = await readApplyCode(phone3);
  const wrong3 = real3 === '333333' ? '444444' : '333333';
  for (let i = 0; i < 5; i++) {
    await p.fill('#adm-verify-code', wrong3);
    await p.click('#adm-verify-confirm');
    await p.waitForFunction((n) => !document.getElementById('adm-verify-confirm').disabled || /wait 15 minutes/i.test(document.getElementById('adm-verify-msg').textContent), i, { timeout: 15000 });
  }
  await p.waitForFunction(() => /wait 15 minutes/i.test(document.getElementById('adm-verify-msg').textContent), null, { timeout: 15000 });
  check('five wrong codes lock the attempt with a clear message', /wait 15 minutes/i.test(await text(p, '#adm-verify-msg')));
  check('locked: code field, confirm and resend are disabled', (await p.isDisabled('#adm-verify-code')) && (await p.isDisabled('#adm-verify-confirm')) && (await p.isDisabled('#adm-verify-resend')) && (await p.isDisabled('#adm-submit')));
  await p.click('#adm-verify-change');
  check('Change number gets out of the locked state', await p.isVisible('#adm-verify-send'));

  // The server refuses a bad proof even when the client thinks it is verified.
  const phone4 = freshPhone();
  await p.fill('#adm-student_phone', phone4);
  await verifyPhoneInForm(p, phone4);
  await p.evaluate(() => { document.getElementById('adm-phone_proof').value = 'forged.token'; });
  await p.click('#adm-submit');
  await p.waitForFunction(() => document.getElementById('adm-submit').disabled && /verify/i.test(document.getElementById('adm-verify-msg').textContent) && !document.getElementById('adm-student_phone').readOnly, null, { timeout: 15000 });
  const summary = await text(p, '#adm-errors');
  check('server 422 phone_proof: shows the message and resets verification', /Verify your phone number first/.test(summary) && (await p.isVisible('#adm-verify-send')), summary);
  check('did not leave the form', !/fake\/checkout/.test(p.url()));

  note('rate-limit message (429 rate_limited) is not exercised: CC_RATE_LIMIT_DISABLED=1 locally. Limits are covered by tests/integration/phone-proof-test.php.');
  await ctx.close();
}

const browser = await chromium.launch();
try {
  await phoneVerification(browser);
  await happyPath(browser, 375);
  await happyPath(browser, 1280);
  await serverErrors(browser);
  await duplicate(browser);
  await failRetryPay(browser);
} catch (e) {
  console.log('  FAIL unexpected error:', e.message);
  failures++;
} finally {
  await browser.close();
}
console.log(failures ? `\n${failures} check(s) FAILED` : '\nAll checks passed');
process.exit(failures ? 1 : 0);
