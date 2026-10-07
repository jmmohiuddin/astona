/**
 * Shared helpers for browser e2e scripts: read the admission verification code from the fake SMS outbox (wpcli)
 * and verify the student phone through the real form UI.
 *   import { verifyPhoneInForm } from './lib/phone-proof.mjs';
 * WPCLI can be overridden like in student-browser.mjs (default "docker compose run --rm -T wpcli", run from the repo root).
 */
import { execFile } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const WPCLI = (process.env.WPCLI || 'docker compose run --rm -T wpcli').split(' ');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export const e164 = (phone) => '+880' + phone.replace(/\D/g, '').replace(/^(?:880|0)/, '');

const wp = (...args) => new Promise((resolve) => {
  execFile(WPCLI[0], [...WPCLI.slice(1), ...args], { cwd: REPO, maxBuffer: 20e6 }, (err, stdout) => resolve(err ? '' : stdout));
});

/** The newest 6 digit admission code sent to this phone (any format), or null after the timeout. */
export async function readApplyCode(phone, { timeoutMs = 30000 } = {}) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const out = await wp('eval-file', '/tests/e2e/lib/read-apply-code.php', e164(phone));
    const m = /CODE:(\d{6})/.exec(out);
    if (m) return m[1];
    await sleep(1000);
  }
  return null;
}

/** Fills the student phone, runs Verify number -> code -> Confirm code on the admissions form, waits for "verified". */
export async function verifyPhoneInForm(p, phone) {
  await p.fill('#adm-student_phone', phone);
  await p.click('#adm-verify-send');
  await p.waitForSelector('#adm-verify-code-wrap:not([hidden])', { timeout: 15000 });
  const code = await readApplyCode(phone);
  if (!code) throw new Error('no verification code in the fake SMS outbox for ' + phone);
  await p.fill('#adm-verify-code', code);
  await p.click('#adm-verify-confirm');
  await p.waitForFunction(() => /verified/i.test(document.getElementById('adm-verify-msg').textContent) && !document.getElementById('adm-submit').disabled, null, { timeout: 15000 });
}
