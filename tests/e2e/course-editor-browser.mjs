#!/usr/bin/env node
/**
 * Browser e2e for the Course editor (Batch > Module > Lesson on one screen), headless Chromium.
 *   BASE_URL=http://localhost:8080 node tests/e2e/course-editor-browser.mjs
 * Playwright is resolved from this file first, then from the current working directory (see admin-browser.mjs).
 * Fixtures are a throwaway staff user and course created through WP-CLI (WPCLI="docker compose run --rm -T wpcli" by
 * default; override with WPCLI="wp" where WP-CLI is installed; CHROMIUM_PATH points at a browser binary if Playwright has none) and removed at the end. Dev only: needs
 * CC_STAFF_SECURITY_RELAXED=1 so the staff login needs no authenticator code. Exit code 0 = all passed.
 */
import { createRequire } from 'node:module';
import { execFile } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); }
catch { ({ chromium } = createRequire(process.cwd() + '/')('playwright')); }

const BASE = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const WPCLI = (process.env.WPCLI || 'docker compose run --rm -T wpcli').split(' ');
const TAG = 'QACE' + Date.now().toString(36).toUpperCase();
const PW = 'Ce-Qa-' + Math.random().toString(36).slice(2, 10) + '-9!';

let failures = 0;
const check = (name, cond, extra = '') => {
  console.log(`${cond ? '  ok  ' : '  FAIL'} ${name}${cond ? '' : ' ' + extra}`);
  if (!cond) failures++;
};
const wp = (...args) => new Promise((resolve) => {
  execFile(WPCLI[0], [...WPCLI.slice(1), ...args], { cwd: REPO, maxBuffer: 20e6 }, (err, stdout) => resolve(err ? '' : stdout));
});
const lastJson = (out) => { const m = out.match(/(\{.*\}|\[.*\])\s*$/s); try { return JSON.parse(m[1]); } catch { return null; } };

const login = `${TAG.toLowerCase()}_staff`;
const created = await wp('user', 'create', login, `${login}@example.test`, '--role=cc_staff', `--user_pass=${PW}`, '--porcelain');
const userId = parseInt(created.trim().split('\n').pop(), 10);
const courseId = parseInt((await wp('post', 'create', '--post_type=cc_course', '--post_status=publish', `--post_title=${TAG} Course`, '--porcelain')).trim().split('\n').pop(), 10);
const dump = async () => lastJson(await wp('eval', `echo wp_json_encode( CC_Course_Tree::load( ${courseId} ) );`));

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const p = await ctx.newPage();
const consoleErrors = [];
// Failed-resource lines are the deliberate 409/422 answers and blocked third-party avatars; script errors are what matter.
p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) consoleErrors.push(m.text()); });
p.on('pageerror', (e) => consoleErrors.push('pageerror ' + e.message));

try {
  await p.goto(`${BASE}/admin/login/`);
  await p.fill('#user_login', login);
  await p.fill('#user_pass', PW);
  await p.click('#wp-submit');
  await p.waitForURL(/wp-admin/, { timeout: 20000 });

  console.log('course picker');
  await p.goto(`${BASE}/wp-admin/admin.php?page=cc-course-editor`);
  check('the picker lists the course', await p.locator(`text=${TAG} Course`).count() > 0);
  await p.click(`tr:has-text("${TAG} Course") a.button`);
  await p.waitForSelector('.cce', { timeout: 15000 });
  check('the editor loads for the course', (await p.textContent('.cce-bar')).includes(`${TAG} Course`));
  check('Save is disabled until something changes', await p.locator('.cce-bar .button-primary').isDisabled());

  console.log('build a batch, a module and lessons');
  await p.click('text=+ Add batch');
  await p.fill('#cce-batches-0-name', 'Evening A');
  await p.selectOption('#cce-batches-0-status', 'open');
  await p.fill('#cce-batches-0-capacity', '20');
  await p.fill('#cce-batches-0-price', '12000');
  await p.fill('#cce-batches-0-start_date', '2030-03-01');
  await p.check('text=Allow two-part payment');
  await p.fill('#cce-batches-0-first_payment_percent', '40');
  check('the unsaved-changes indicator appears', (await p.textContent('.cce-bar')).includes('Unsaved changes'));
  await p.click('text=+ Add module');
  await p.fill('#cce-batches-0-modules-0-title', 'Physics');
  await p.click('text=+ Add lesson');
  await p.fill('#cce-batches-0-modules-0-lessons-0-title', 'Motion');
  await p.fill('#cce-batches-0-modules-0-lessons-0-scheduled_local', '2030-03-02T18:30');
  await p.click('text=+ Add lesson');
  await p.fill('#cce-batches-0-modules-0-lessons-1-title', 'Force');

  console.log('navigating away asks first');
  let dialogSeen = false;
  p.once('dialog', async (d) => { dialogSeen = d.type() === 'beforeunload'; await d.dismiss(); });
  await p.evaluate(() => { window.dispatchEvent(new Event('beforeunload', { cancelable: true })); });
  const warned = await p.evaluate(() => { const e = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(e); return e.defaultPrevented; });
  check('an unsaved editor blocks leaving the page', warned);

  console.log('save');
  await p.click('.cce-bar .button-primary');
  await p.waitForSelector('.cce-saved', { timeout: 15000 });
  let tree = await dump();
  const b = tree && tree.batches[0];
  check('one batch saved with its fields', !!b && b.name === 'Evening A' && b.status === 'open' && b.capacity === 20 && b.price === 12000 && b.installments_enabled === true && b.first_payment_percent === 40);
  check('module and both lessons saved in order', !!b && b.modules.length === 1 && b.modules[0].lessons.map((l) => l.title).join() === 'Motion,Force');
  check('the lesson time round-trips in site time', !!b && b.modules[0].lessons[0].scheduled_local === '2030-03-02T18:30');
  check('Save is disabled again', await p.locator('.cce-bar .button-primary').isDisabled());

  console.log('validation errors show on the field');
  await p.fill('#cce-batches-0-name', '');
  await p.click('.cce-bar .button-primary');
  await p.waitForSelector('#cce-batches-0-name-err', { timeout: 15000 });
  check('an empty batch name is reported next to the field', (await p.textContent('#cce-batches-0-name-err')).includes('name'));
  check('the field is marked invalid for assistive tech', (await p.getAttribute('#cce-batches-0-name', 'aria-invalid')) === 'true');
  check('nothing was saved', (await dump()).batches[0].name === 'Evening A');
  await p.fill('#cce-batches-0-name', 'Evening B');

  console.log('reorder and move');
  await p.click('text=+ Add module');
  await p.fill('#cce-batches-0-modules-1-title', 'Chemistry');
  await p.click('[aria-label="Move module up"] >> nth=1');
  await p.click('.cce-bar .button-primary');
  await p.waitForSelector('.cce-saved', { timeout: 15000 });
  tree = await dump();
  check('modules were reordered', tree.batches[0].modules.map((m) => m.title).join() === 'Chemistry,Physics' && tree.batches[0].name === 'Evening B');

  console.log('a stale editor cannot overwrite a newer save');
  await wp('eval', `$t = CC_Course_Tree::load( ${courseId} ); $t['batches'][0]['name'] = 'Changed elsewhere'; wp_set_current_user( ${userId} ); CC_Course_Tree::save( ${courseId}, $t, $t['rev'], ${userId} );`);
  await p.fill('#cce-batches-0-name', 'My edit');
  await p.click('.cce-bar .button-primary');
  await p.waitForSelector('.notice-error', { timeout: 15000 });
  check('the conflict is explained with a reload button', (await p.textContent('.cce-msg')).includes('Someone else changed') && await p.locator('.cce-msg >> text=Reload now').count() === 1);
  check("the other person's change is intact", (await dump()).batches[0].name === 'Changed elsewhere');
  await p.click('.cce-msg >> text=Reload now');
  await p.waitForFunction(() => document.querySelector('.cce-saved'));
  check('reloading shows their version', (await p.locator('.cce-toggle').first().textContent()).includes('Changed elsewhere'));

  console.log('keyboard and labels');
  const unlabelled = await p.$$eval('.cce input:not([type=checkbox]), .cce select', (els) => els.filter((e) => !document.querySelector(`label[for="${e.id}"]`) && !e.getAttribute('aria-label')).length);
  check('every input has a label', unlabelled === 0, `(${unlabelled} without)`);
  check('icon buttons have names', (await p.$$eval('.cce-icon', (els) => els.filter((e) => !e.getAttribute('aria-label')).length)) === 0);
  check('no horizontal scroll at 1280px', (await p.evaluate(() => document.documentElement.scrollWidth - innerWidth)) <= 0);
  await p.setViewportSize({ width: 390, height: 800 });
  check('no horizontal scroll at 390px', (await p.evaluate(() => document.documentElement.scrollWidth - innerWidth)) <= 2);
  check('no console errors', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (e) {
  console.log('  FAIL unexpected error: ' + e.message);
  failures++;
} finally {
  await browser.close();
  await wp('eval', `global $wpdb; $p = $wpdb->prefix; foreach ( $wpdb->get_col( "SELECT id FROM {$p}cc_batches WHERE course_id = ${courseId}" ) as $b ) { $wpdb->query( "DELETE FROM {$p}cc_lessons WHERE module_id IN (SELECT id FROM {$p}cc_modules WHERE batch_id = $b)" ); $wpdb->delete( "{$p}cc_modules", array( 'batch_id' => $b ) ); $wpdb->delete( "{$p}cc_batches", array( 'id' => $b ) ); } wp_delete_post( ${courseId}, true ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( ${userId} ); $wpdb->query( "DELETE FROM {$p}cc_audit_log WHERE action = 'course.tree_save'" );`);
}
console.log(failures ? `\n${failures} check(s) failed` : '\nall checks passed');
process.exit(failures ? 1 : 0);
