#!/usr/bin/env node
/**
 * Sub-project 5 browser e2e: course content (modules/lessons/PDF), live classes (masked meeting link, Join) and
 * targeted notices (audience preview, critical SMS, public leakage). Headless Chromium, 375/768/1280px.
 *
 * Run from the repo root (Node 18+, docker compose dev stack on BASE_URL, Playwright + Chromium):
 *   npm i playwright && npx playwright install chromium     # once, in any dir on the module path
 *   BASE_URL=http://localhost:8080 node tests/e2e/content-live-browser.mjs
 * Playwright is resolved from this file first, then from the current working directory.
 * Optional: BATCH_A=4 BATCH_B=6 (two OPEN batches with no enrollments, so audience counts are exact),
 *           WPCLI="docker compose run --rm -T wpcli" (run from the repo root), SHOT_DIR=/dir for screenshots.
 *
 * Fixtures (throwaway, tagged QACL<random>, all removed in a finally block): a cc_staff user, two students created
 * through the real admission form + fake gateway Pay (Action Scheduler provisions them; temp passwords are read from
 * the fake SMS outbox), plus the modules/lessons/PDFs, live classes and notices created through wp-admin.
 * The meet.google.com host is stubbed in the browser, so no external network is needed.
 * Dev only (fake gateway + cc_fake_sms_outbox). Exit code 0 = all passed, 1 = any failure.
 */
import { createRequire } from 'node:module';
import { verifyPhoneInForm } from './lib/phone-proof.mjs';
import { appNoticeSelector } from './lib/app-notice.mjs';
import { execFile } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

let chromium;
try { ({ chromium } = await import('playwright')); }
catch { ({ chromium } = createRequire(process.cwd() + '/')('playwright')); }

const BASE = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const BATCH_A = parseInt(process.env.BATCH_A || '4', 10);
const BATCH_B = parseInt(process.env.BATCH_B || '6', 10);
const SHOT_DIR = process.env.SHOT_DIR || '';
const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const WPCLI = (process.env.WPCLI || 'docker compose run --rm -T wpcli').split(' ');
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
const TAG = 'QACL' + Math.random().toString(36).slice(2, 8).toUpperCase().replace(/[0-9]/g, (d) => 'GHIJKLMNOP'[d]);
const PW = 'Cl-Qa-' + Math.random().toString(36).slice(2, 10) + '-9!';
const STUDENT_PW = 'Student-new-' + Math.random().toString(36).slice(2, 8) + '-7';

const URL_A = 'https://meet.google.com/abc-defg-hij';
const URL_B = 'https://meet.google.com/bbb-cccc-ddd';
const URL_FAR = 'https://meet.google.com/fff-gggg-hhh';
const URL_FRAGMENTS = ['abc-defg-hij', 'bbb-cccc-ddd', 'fff-gggg-hhh'];

let failures = 0;
const check = (name, cond, extra = '') => {
  console.log(`${cond ? '  ok  ' : '  FAIL'} ${name}${cond ? '' : ' ' + extra}`);
  if (!cond) failures++;
};
const note = (s) => console.log(`  note ${s}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const freshPhone = () => '017' + String(Date.now() + Math.floor(Math.random() * 100000)).slice(-8);
const e164 = (p) => '+880' + p.slice(1);

const wp = (...args) => new Promise((resolve) => {
  execFile(WPCLI[0], [...WPCLI.slice(1), ...args], { cwd: REPO, maxBuffer: 50e6 }, (err, stdout) => resolve(err ? '' : stdout));
});
async function php(code) {
  const out = await wp('eval', code);
  const line = out.split('\n').reverse().find((l) => l.startsWith('JSON:'));
  try { return line ? JSON.parse(line.slice(5)) : null; } catch { return null; }
}
const q = (s) => JSON.stringify(String(s)); // PHP-compatible string literal for plain ASCII/UTF-8 text
async function outbox() {
  const out = await wp('option', 'get', 'cc_fake_sms_outbox', '--format=json');
  try { return JSON.parse(out.slice(out.indexOf('['))); } catch { return []; }
}
const resetLimits = () => wp('eval', 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%cc_rl_%\'");');
async function runQueues(page) {
  if (page) await page.request.get(`${BASE}/wp-cron.php?doing_wp_cron`).catch(() => {});
  await wp('cron', 'event', 'run', '--due-now');
  await wp('action-scheduler', 'run');
}
async function waitSms(phone, re, { timeoutMs = 90000, page } = {}) {
  const deadline = Date.now() + timeoutMs;
  let tick = 0;
  while (Date.now() < deadline) {
    const hit = (await outbox()).filter((m) => m.to === e164(phone) && re.test(m.body))[0];
    if (hit) return hit.body.match(re);
    if (tick++ % 2 === 0) await runQueues(page);
    await sleep(2000);
  }
  return null;
}
const localTimes = async (...offsets) => php(`echo "JSON:".json_encode([${offsets.map((o) => `wp_date('Y-m-d\\TH:i', time()+(${o}))`).join(',')}]);`);

// Notice selectors never match core nags (update-nag etc.), only the screen's own result notice.
const text = async (p, sel) => ((await p.textContent(appNoticeSelector(sel)).catch(() => '')) || '').replace(/\s+/g, ' ').trim();
const overflow = (p) => p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
const PHP_ERR = /(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)\b[^\n]{0,200}\bon line \d+|There has been a critical error/;
const consoleErrors = [];
const phpErrors = [];
const leaksUrl = (s) => URL_FRAGMENTS.some((f) => String(s).includes(f)) || /meet\.google\.com/.test(String(s));

async function newCtx(browser, width, { downloads = false } = {}) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, acceptDownloads: downloads });
  await ctx.route('https://meet.google.com/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>stub meet</body></html>' }));
  const watch = (pg) => {
    pg.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(`${m.text()} @ ${pg.url()}`); ctx.__console.push(m.text()); });
    pg.on('pageerror', (e) => consoleErrors.push(`pageerror ${e.message} @ ${pg.url()}`));
  };
  ctx.__console = [];
  ctx.__popups = [];
  ctx.on('page', (pg) => { ctx.__popups.push(pg); watch(pg); });
  const p = await ctx.newPage();
  ctx.__popups.pop();
  p.on('dialog', (d) => d.accept().catch(() => {}));
  return { ctx, p };
}
async function visit(p, url, shot = '') {
  const res = await p.goto(url.startsWith('http') ? url : `${BASE}${url}`, { waitUntil: 'load' });
  const hit = ((await p.content()) || '').match(PHP_ERR);
  if (hit) phpErrors.push(`${hit[0].slice(0, 160)} @ ${p.url()}`);
  if (SHOT_DIR && shot) {
    fs.mkdirSync(SHOT_DIR, { recursive: true });
    await p.screenshot({ path: path.join(SHOT_DIR, `${shot}-${p.viewportSize().width}.png`), fullPage: true }).catch(() => {});
  }
  return res;
}
const adminUrl = (page, extra = '') => `/wp-admin/admin.php?page=${page}${extra}`;

/* ---------------------------------------------------------------- fixtures */

async function mkStaff() {
  const login = `${TAG.toLowerCase()}_staff`;
  const out = await wp('user', 'create', login, `${login}@example.test`, '--role=cc_staff', `--user_pass=${PW}`, '--porcelain');
  return { id: parseInt(out.trim().split('\n').pop(), 10), login, pass: PW };
}
async function wpLogin(p, user) {
  await p.goto(`${BASE}/admin/login/`);
  await p.fill('#user_login', user.login);
  await p.fill('#user_pass', user.pass);
  await p.click('#wp-submit');
  await p.waitForURL(/wp-admin/, { timeout: 20000 }).catch(() => {});
  await p.waitForLoadState('networkidle').catch(() => {});
}
async function apply(p, { name, phone, batch }) {
  await p.goto(`${BASE}/admissions/?batch=${batch}`);
  await p.fill('#adm-full_name', name);
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
async function makeStudent(browser, name, batch) {
  const { ctx, p } = await newCtx(browser, 1280);
  const phone = freshPhone();
  await resetLimits();
  await apply(p, { name, phone, batch });
  const m = await waitSms(phone, /temporary password (\w+)/, { page: p });
  await ctx.close();
  if (!m) return null;
  const info = await php(`global $wpdb; $p=$wpdb->prefix; $in="'${phone}','${e164(phone)}'"; $r=$wpdb->get_row("SELECT user_id FROM {$p}cc_applications WHERE student_phone IN ($in) ORDER BY id DESC LIMIT 1", ARRAY_A); $e=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}cc_enrollments WHERE user_id=%d AND batch_id=%d", $r['user_id'], ${batch})); echo "JSON:".json_encode(['user'=>(int)$r['user_id'],'enrollment'=>(int)$e]);`);
  return { name, phone, temp: m[1], password: null, batch, userId: info?.user, enrollmentId: info?.enrollment };
}
/** Login; handles the forced first-login password change once per student. */
async function studentLogin(p, s) {
  await resetLimits();
  await p.goto(`${BASE}/student/login/`);
  await p.fill('#sl-phone', s.phone);
  await p.fill('#sl-password', s.password || s.temp);
  await p.click('#sl-submit');
  await p.waitForURL(/\/student\/(?!login)/, { timeout: 15000 }).catch(() => {});
  if (/change=1/.test(p.url())) {
    await p.fill('#pf-current_password', s.password || s.temp);
    await p.fill('#pf-new_password', STUDENT_PW);
    await p.click('#portal-password-form button[type=submit]');
    await p.waitForURL(/\/student\/$/, { timeout: 10000 }).catch(() => {});
    s.password = STUDENT_PW;
  }
  return /\/student\/$/.test(p.url());
}

async function cleanup(fx) {
  const phones = fx.students.map((s) => `'${s.phone}','${e164(s.phone)}'`).join(',');
  await wp('eval', `global $wpdb; $p=$wpdb->prefix; $tag=${q(TAG)};
    $like='%'.$wpdb->esc_like($tag).'%';
    foreach($wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='cc_notice' AND (post_title LIKE %s OR post_content LIKE %s)",$like,$like)) as $n){ $wpdb->delete($p.'cc_notice_targets',['notice_id'=>$n]); $wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_sms_log WHERE related_type='notice' AND related_id=%d",$n)); wp_delete_post((int)$n,true); }
    foreach($wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}cc_live_classes WHERE title LIKE %s",$like)) as $l){ $wpdb->delete($p.'cc_join_log',['live_class_id'=>$l]); CC_Live_Repository::delete((int)$l); }
    foreach([${BATCH_A},${BATCH_B}] as $b){ foreach(CC_Content_Repository::modules_for_batch($b) as $m){ CC_Content_Repository::delete_module((int)$m['id']); } }
    $phones=[${phones || "''"}];
    $in=implode(',',array_map(function($x) use($wpdb){return $wpdb->prepare('%s',$x);},$phones));
    $apps=$wpdb->get_col("SELECT id FROM {$p}cc_applications WHERE student_phone IN ($in)");
    $uids=array_filter($wpdb->get_col("SELECT user_id FROM {$p}cc_applications WHERE student_phone IN ($in)"));
    if($apps){ $a=implode(',',array_map('intval',$apps));
      $inv=$wpdb->get_col("SELECT id FROM {$p}cc_invoices WHERE application_id IN ($a)");
      if($inv){ $i=implode(',',array_map('intval',$inv));
        $pay=$wpdb->get_col("SELECT id FROM {$p}cc_payments WHERE invoice_id IN ($i)");
        if($pay){ $y=implode(',',array_map('intval',$pay)); $wpdb->query("DELETE FROM {$p}cc_payment_events WHERE payment_id IN ($y)"); $wpdb->query("DELETE FROM {$p}cc_payments WHERE id IN ($y)"); }
        $wpdb->query("DELETE FROM {$p}cc_invoices WHERE id IN ($i)"); }
      $wpdb->query("DELETE FROM {$p}cc_enrollments WHERE application_id IN ($a)");
      $wpdb->query("DELETE FROM {$p}cc_sms_log WHERE related_type='application' AND related_id IN ($a)");
      $wpdb->query("DELETE FROM {$p}cc_applications WHERE id IN ($a)"); }
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach($uids as $u){ $wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_join_log WHERE user_id=%d",$u)); $wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_students WHERE user_id=%d",$u)); $wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_sms_log WHERE related_type='student' AND related_id=%d",$u)); wp_delete_user((int)$u); }
    ${fx.staff ? `$wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_audit_log WHERE actor_id=%d",${fx.staff.id})); wp_delete_user(${fx.staff.id});` : ''}
    foreach(json_decode(${q(JSON.stringify(fx.seats))},true) as $b=>$s){ $wpdb->update($p.'cc_batches',['seats_taken'=>$s],['id'=>(int)$b]); }`);
}

/* ---------------------------------------------------------------- staff */

const rowTexts = (p, sel) => p.$$eval(sel, (rows) => rows.map((r) => r.textContent.replace(/\s+/g, ' ').trim()));

async function staffContent(browser, fx) {
  console.log('Staff: Course content @1280px');
  const { ctx, p } = await newCtx(browser, 1280);
  await wpLogin(p, fx.staff);
  check('staff can open wp-admin', /wp-admin/.test(p.url()), p.url());
  await visit(p, adminUrl('cc-content'), 'content-empty');
  check('Course content screen opens for cc_staff', /Course content/.test(await text(p, '.wrap h1')));
  await visit(p, adminUrl('cc-content', `&batch=${BATCH_A}`));
  const modTitle = `মডিউল ১ ${TAG}`;
  await p.fill('form:has(input[name=module_id][value="0"]) input[name=title]', modTitle);
  await p.click('form:has(input[name=module_id][value="0"]) button[type=submit]');
  check('module saved notice', /Module saved/.test(await text(p, '.notice')), await text(p, '.wrap'));
  check('module listed', (await text(p, '.postbox h3')).includes(modTitle));

  const t = await localTimes(600);
  const addLesson = async (title, { when = '', file = null } = {}) => {
    const f = 'form:has(input[name=lesson_id][value="0"])';
    await p.fill(`${f} input[name=title]`, title);
    if (when) await p.fill(`${f} input[name=scheduled_at]`, when);
    if (file) await p.setInputFiles(`${f} input[name=attachment]`, file);
    await p.click(`${f} button[type=submit]`);
    await p.waitForLoadState('load');
  };
  const L1 = `প্রথম পাঠ ${TAG}`;
  const L2 = `Lesson two ${TAG}`;
  const L3 = `Lesson to delete ${TAG}`;
  const L4 = `Long${'x'.repeat(150)}${TAG}`;
  await addLesson(L1, { when: t[0], file: { name: 'notes one.pdf', mimeType: 'application/pdf', buffer: PDF } });
  check('lesson 1 saved (scheduled + PDF)', /Lesson saved/.test(await text(p, '.notice')), await text(p, '.notice'));
  await addLesson(L2);
  check('lesson 2 saved', /Lesson saved/.test(await text(p, '.notice')));
  await addLesson(L3, { file: { name: 'del.pdf', mimeType: 'application/pdf', buffer: PDF } });
  await addLesson(L4);
  let rows = await rowTexts(p, '.postbox table tbody tr');
  check('4 lessons listed in creation order', rows.length === 4 && rows[0].includes(L1) && rows[1].includes(L2) && rows[2].includes(L3), JSON.stringify(rows.map((r) => r.slice(0, 40))));
  check('lesson 1 shows time and PDF name', rows[0].includes(t[0].replace('T', ' ')) && /notes.*\.pdf/i.test(rows[0]), rows[0]);
  fx.lessonIds = await php(`global $wpdb; $r=$wpdb->get_results("SELECT id,title,attachment_path FROM {$wpdb->prefix}cc_lessons WHERE title LIKE '%${TAG}%' ORDER BY id",ARRAY_A); echo "JSON:".json_encode($r);`);
  const lid = (needle) => fx.lessonIds.find((l) => l.title.includes(needle));
  fx.l1 = lid('প্রথম'); fx.l2 = lid('Lesson two'); fx.l3 = lid('to delete'); fx.l4 = lid('Long');

  // Refused uploads on lesson 2
  const editL2 = adminUrl('cc-content', `&batch=${BATCH_A}&edit_lesson=${fx.l2.id}`);
  await visit(p, editL2);
  const ef = `form:has(input[name=lesson_id][value="${fx.l2.id}"])`;
  await p.setInputFiles(`${ef} input[name=attachment]`, { name: 'notes.pdf', mimeType: 'application/pdf', buffer: Buffer.from('this is plain text, not a pdf\n') });
  await p.click(`${ef} button[type=submit]`);
  await p.waitForLoadState('load');
  const refusedMsg = await text(p, '.notice');
  check('.txt renamed .pdf is refused with a message', /could not be saved/i.test(refusedMsg), refusedMsg);
  const att = await php(`global $wpdb; echo "JSON:".json_encode($wpdb->get_var("SELECT attachment_path FROM {$wpdb->prefix}cc_lessons WHERE id=${fx.l2.id}"));`);
  check('refused upload stored nothing', att === null, JSON.stringify(att));
  note(`refusal message: ${refusedMsg}`);
  await visit(p, editL2);
  await p.setInputFiles(`${ef} input[name=attachment]`, { name: 'big.pdf', mimeType: 'application/pdf', buffer: Buffer.concat([Buffer.from('%PDF-1.4\n'), Buffer.alloc(10.5 * 1024 * 1024, 32)]) });
  await p.click(`${ef} button[type=submit]`);
  await p.waitForLoadState('load');
  const bigBody = await p.content();
  const bigNotice = await text(p, '.notice');
  check('10.5 MB PDF is refused with a size message (not a blank page / generic text)', /too large|10 ?MB|size/i.test(bigNotice) && !PHP_ERR.test(bigBody), `url=${p.url().slice(-60)} notice="${bigNotice}" body="${(await text(p, 'body')).slice(0, 80)}"`);
  await visit(p, editL2);
  await p.setInputFiles(`${ef} input[name=attachment]`, { name: 'fake.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nthis is not really a pdf\n') });
  await p.click(`${ef} button[type=submit]`);
  await p.waitForLoadState('load');
  note(`header-only fake %PDF upload -> "${await text(p, '.notice')}"`);
  const fakeStored = await php(`global $wpdb; echo "JSON:".json_encode($wpdb->get_var("SELECT attachment_path FROM {$wpdb->prefix}cc_lessons WHERE id=${fx.l2.id}"));`);
  if (fakeStored) await php(`global $wpdb; CC_Content_Repository::update_lesson(${fx.l2.id}, ['attachment_path'=>null,'attachment_name'=>null]); CC_Resource_Store::delete(${q(fakeStored)}); echo "JSON:1";`);

  // Reorder: lesson 2 up -> [L2, L1, L3, L4]
  await visit(p, adminUrl('cc-content', `&batch=${BATCH_A}`));
  await p.click(`tr:has-text("${L2}") button:text-is("Move up")`);
  await p.waitForLoadState('load');
  check('move-up notice', /Order updated/.test(await text(p, '.notice')));
  rows = await rowTexts(p, '.postbox table tbody tr');
  check('lesson 2 now first', rows[0].includes(L2) && rows[1].includes(L1), JSON.stringify(rows.map((r) => r.slice(0, 30))));
  check('first row has no Move up button', (await p.$$(`tr:has-text("${L2}") button:text-is("Move up")`)).length === 0);

  // Delete lesson 3 (+ its file)
  const l3path = fx.l3.attachment_path;
  await p.click(`tr:has-text("${L3}") button:text-is("Delete")`);
  await p.waitForLoadState('load');
  check('lesson deleted notice', /Lesson deleted/.test(await text(p, '.notice')));
  rows = await rowTexts(p, '.postbox table tbody tr');
  check('lesson 3 gone from list', rows.length === 3 && !rows.some((r) => r.includes(L3)), JSON.stringify(rows.map((r) => r.slice(0, 30))));
  const fileLeft = await php(`echo "JSON:".json_encode(CC_Resource_Store::path(${q(l3path)}));`);
  check('lesson 3 PDF file removed from disk', fileLeft === null, JSON.stringify(fileLeft));
  check('content admin no overflow @1280 (150-char unbroken lesson title)', (await overflow(p)) <= 0, String(await overflow(p)));
  await p.setViewportSize({ width: 768, height: 900 });
  await visit(p, adminUrl('cc-content', `&batch=${BATCH_A}`), 'content');
  check('content admin no overflow @768', (await overflow(p)) <= 0, String(await overflow(p)));
  await ctx.close();
}

async function staffLive(browser, fx) {
  console.log('Staff: Live classes @1280px');
  const { ctx, p } = await newCtx(browser, 1280);
  await wpLogin(p, fx.staff);
  const t = await localTimes(300, 3900, 3 * 86400, 3 * 86400 + 3600, -7200, -3600);
  const create = async ({ title, batch, start, end, url, provider = 'meet' }) => {
    await visit(p, adminUrl('cc-live'));
    await p.fill('#cc-live-title', title);
    await p.selectOption('#cc-live-batch', String(batch));
    await p.fill('#cc-live-start', start);
    await p.fill('#cc-live-end', end);
    await p.selectOption('#cc-live-provider', provider);
    await p.evaluate(() => { document.querySelector('#cc-live-url').closest('form').noValidate = true; });
    await p.fill('#cc-live-url', url);
    await p.click('input#submit');
    await p.waitForLoadState('load');
    return text(p, '.notice');
  };
  fx.liveTitles = { a: `Live A বাংলা ${TAG}`, b: `Live B ${TAG}`, far: `Live far ${TAG}`, ended: `Live ended ${TAG}` };
  let msg = await create({ title: fx.liveTitles.a, batch: BATCH_A, start: t[0], end: t[1], url: URL_A });
  check('live class A (starts in 5 min) saved', /Live class saved/.test(msg), msg);
  msg = await create({ title: fx.liveTitles.b, batch: BATCH_B, start: t[0], end: t[1], url: URL_B });
  check('live class B saved', /Live class saved/.test(msg), msg);
  msg = await create({ title: fx.liveTitles.far, batch: BATCH_A, start: t[2], end: t[3], url: URL_FAR });
  check('far-future live class saved', /Live class saved/.test(msg), msg);
  msg = await create({ title: fx.liveTitles.ended, batch: BATCH_A, start: t[4], end: t[5], url: URL_A });
  check('ended (past) live class saved', /Live class saved/.test(msg), msg);
  fx.liveIds = await php(`global $wpdb; $r=$wpdb->get_results("SELECT id,title,batch_id FROM {$wpdb->prefix}cc_live_classes WHERE title LIKE '%${TAG}%'",ARRAY_A); echo "JSON:".json_encode($r);`);
  const lid = (needle) => fx.liveIds.find((l) => l.title.includes(needle))?.id;
  fx.liveA = lid('Live A'); fx.liveB = lid('Live B'); fx.liveFar = lid('Live far'); fx.liveEnded = lid('Live ended');

  // Masking
  await visit(p, adminUrl('cc-live'), 'live');
  const html = await p.content();
  const list = await text(p, 'body');
  check('admin list: URL never in HTML (full link / unique id)', !leaksUrl(html.replace(/https:\/\/meet\.google\.com\/(…|&hellip;|&#8230;)/g, '')), 'link fragment present');
  check('admin list shows masked link https://meet.google.com/…hij', list.includes('https://meet.google.com/…hij'), list.slice(0, 300));
  check('admin list shows "never shown publicly" note', /never shown publicly/i.test(list));
  await visit(p, adminUrl('cc-live', `&edit=${fx.liveA}`));
  const editHtml = await p.content();
  check('edit form: URL not in HTML / value', !URL_FRAGMENTS.some((f) => editHtml.includes(f)), 'link fragment present in edit page');
  check('edit form: URL input empty, masked placeholder', (await p.inputValue('#cc-live-url')) === '' && (await p.getAttribute('#cc-live-url', 'placeholder')) === 'https://meet.google.com/…hij', await p.getAttribute('#cc-live-url', 'placeholder'));
  // Edit with blank URL keeps it
  await p.fill('#cc-live-title', fx.liveTitles.a + ' edited');
  await p.click('input#submit');
  await p.waitForLoadState('load');
  check('edit with blank link saves', /Live class saved/.test(await text(p, '.notice')), await text(p, '.notice'));
  fx.liveTitles.a += ' edited';
  const kept = await php(`echo "JSON:".json_encode(CC_Live_Repository::meeting_url(${fx.liveA}) === ${q(URL_A)});`);
  check('blank link on edit keeps the stored link', kept === true);

  // Invalid links
  const bad = ['http://meet.google.com/abc-defg-hij', 'javascript:alert(1)', 'https://meet.google.com.evil.com/x', 'https://user:pw@meet.google.com/abc-defg-hij', 'https://meet.google.com@evil.com/x', 'https://evil.com/meet.google.com/abc', 'ftp://meet.google.com/x', 'https://meet.google.com:8443/abc'];
  let i = 0;
  for (const url of bad) {
    const title = `Bad link ${i++} ${TAG}`;
    const m = await create({ title, batch: BATCH_A, start: t[2], end: t[3], url });
    const n = await php(`global $wpdb; echo "JSON:".(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cc_live_classes WHERE title=%s",${q(title)}));`);
    check(`invalid link refused: ${url}`, /notice-error|could not be saved|must|Enter/.test(m) || n === 0, m);
    check(`invalid link not stored: ${url}`, n === 0, `rows=${n} msg=${m}`);
  }
  // end before start
  const m2 = await create({ title: `Bad times ${TAG}`, batch: BATCH_A, start: t[1], end: t[0], url: URL_A });
  const n2 = await php(`global $wpdb; echo "JSON:".(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cc_live_classes WHERE title='Bad times ${TAG}'");`);
  check('end before start refused', n2 === 0, m2);
  note(`invalid link message sample: ${await text(p, '.notice')}`);
  // Long title boundary: maxlength attr enforced
  check('title input has maxlength', !!(await p.getAttribute('#cc-live-title', 'maxlength')));
  check('live admin no overflow @1280', (await overflow(p)) <= 0);
  await p.setViewportSize({ width: 768, height: 900 });
  await visit(p, adminUrl('cc-live'));
  check('live admin no overflow @768', (await overflow(p)) <= 0, String(await overflow(p)));
  await ctx.close();
}

async function staffNotices(browser, fx) {
  console.log('Staff: Notices @1280px');
  const { ctx, p } = await newCtx(browser, 1280);
  await wpLogin(p, fx.staff);
  const t = await localTimes(86400);
  const TOK_T = `QATGT${TAG}`;
  const TOK_P = `QAPUB${TAG}`;
  fx.tok = { targeted: TOK_T, pub: TOK_P };
  fx.titles = {
    draft: `Draft ${TAG}`,
    targeted: `বিজ্ঞপ্তি ${TOK_T}`,
    pub: `Public ${TOK_P}`,
    sched: `Scheduled QASCH${TAG}`,
    arch: `ToArchive QAARC${TAG}`,
  };
  const body = (s) => `<p>Body of ${s} <strong>bold</strong></p><script>alert(1)</script>`;
  async function fillForm({ title, content, audience, batches = [], critical = false, schedule = '' }) {
    await visit(p, adminUrl('cc-notices', '&view=edit'));
    await p.fill('#cc-title', title);
    await p.click('#cc_notice_content-html').catch(() => {});
    await p.fill('#cc_notice_content', content);
    await p.check(`input[name=audience][value=${audience}]`);
    for (const b of batches) await p.check(`input[name="batch_ids[]"][value="${b}"]`);
    if (critical) await p.check('input[name=critical]');
    if (schedule) await p.fill('#cc-schedule', schedule);
  }
  async function publish(spec, expectRe) {
    await fillForm(spec);
    await p.click('button[name=to_preview]');
    await p.waitForLoadState('load');
    const preview = await text(p, '.wrap');
    const btn = spec.schedule ? 'Confirm and schedule' : 'Confirm and publish';
    await p.click(`input[value="${btn}"]`);
    await p.waitForLoadState('load');
    return { preview, result: await text(p, '.notice') };
  }

  // Draft
  await fillForm({ title: fx.titles.draft, content: body('draft'), audience: 'public' });
  await p.click('input#submit[value="Save draft"]');
  await p.waitForLoadState('load');
  check('draft saved notice', /Notice saved/.test(await text(p, '.notice')), await text(p, '.notice'));
  check('draft listed in Draft tab', (await text(p, 'table.widefat')).includes(fx.titles.draft));

  // Targeted + critical
  // The fake outbox keeps only the last 50 messages, so an index offset goes stale once it is full: compare send times.
  fx.smsSince = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const tgt = await publish({ title: fx.titles.targeted, content: body('targeted'), audience: 'batches', batches: [BATCH_A], critical: true });
  const batchAName = await php(`echo "JSON:".json_encode(CC_Admin_Applications::batch_options()[${BATCH_A}] ?? '');`);
  check('audience preview shows 1 student / SMS to 1 phone', tgt.preview.includes(`Will be visible to 1 students in ${batchAName}; SMS to 1 phones.`), tgt.preview.slice(0, 300));
  check('publish result "Sent to 1 students."', /Sent to 1 students/.test(tgt.result), tgt.result);
  // Public
  const pub = await publish({ title: fx.titles.pub, content: body('public'), audience: 'public' });
  check('public preview says visible to everyone, no SMS', /visible to everyone/.test(pub.preview) && /no SMS/.test(pub.preview), pub.preview.slice(0, 200));
  check('public notice published', /Notice published|Sent to/.test(pub.result), pub.result);
  // Scheduled (public, tomorrow)
  const sch = await publish({ title: fx.titles.sched, content: body('sched'), audience: 'public', schedule: t[0] });
  check('scheduled notice confirmation', /Scheduled/.test(sch.result), sch.result);
  await visit(p, adminUrl('cc-notices', '&tab=scheduled'));
  check('scheduled notice in Scheduled tab', (await text(p, 'table.widefat')).includes(fx.titles.sched));
  // Published then archived
  await publish({ title: fx.titles.arch, content: body('archive'), audience: 'public' });
  await visit(p, adminUrl('cc-notices', '&tab=published'));
  const pubTab = await text(p, 'table.widefat');
  check('published tab lists targeted + public + to-archive', [fx.titles.targeted, fx.titles.pub, fx.titles.arch].every((x) => pubTab.includes(x)), pubTab.slice(0, 300));
  check('published targeted row shows SMS flag and batch', new RegExp(`${TAG.slice(0, 4)}.*`).test(pubTab) && /SMS/.test(await text(p, `tr:has-text("${TOK_T}")`)) && (await text(p, `tr:has-text("${TOK_T}")`)).includes(batchAName));
  await p.click(`tr:has-text("${fx.titles.arch}") input[value="Archive"]`);
  await p.waitForLoadState('load');
  check('archived notice message', /Notice archived/.test(await text(p, '.notice')));
  await visit(p, adminUrl('cc-notices', '&tab=archived'));
  check('archived tab lists it', (await text(p, 'table.widefat')).includes(fx.titles.arch));
  await visit(p, adminUrl('cc-notices', '&tab=published'));
  check('archived notice left the Published tab', !(await text(p, 'table.widefat')).includes(fx.titles.arch));
  // Validation
  await visit(p, adminUrl('cc-notices', '&view=edit'));
  await p.fill('#cc-title', `No batch ${TAG}`);
  await p.check('input[name=audience][value=batches]');
  await p.click('input#submit[value="Save draft"]');
  await p.waitForLoadState('load');
  check('batches audience without a batch is refused', /Choose at least one valid batch/.test(await text(p, '.notice')), await text(p, '.notice'));
  // XSS sanitising
  const ids = await php(`global $wpdb; $r=$wpdb->get_results("SELECT ID,post_title,post_status,post_content,post_name FROM {$wpdb->posts} WHERE post_type='cc_notice' AND post_title LIKE '%${TAG}%'",ARRAY_A); echo "JSON:".json_encode($r);`);
  const byT = (s) => ids.find((r) => r.post_title.includes(s));
  fx.n = { draft: byT('Draft'), targeted: byT(TOK_T), pub: byT(TOK_P), sched: byT('QASCH'), arch: byT('QAARC') };
  check('notice content is sanitised (no <script>)', ids.every((r) => !/<script/i.test(r.post_content)));
  check('notice statuses draft/publish/future', fx.n.draft?.post_status === 'draft' && fx.n.targeted?.post_status === 'publish' && fx.n.sched?.post_status === 'future', JSON.stringify(ids.map((r) => r.post_status)));
  // Admin list overflow
  check('notices admin no overflow @1280', (await overflow(p)) <= 0);
  await p.setViewportSize({ width: 768, height: 900 });
  await visit(p, adminUrl('cc-notices', '&tab=published'), 'notices');
  check('notices admin no overflow @768', (await overflow(p)) <= 0, String(await overflow(p)));
  await ctx.close();
}

/* ---------------------------------------------------------------- student A / B / anonymous */

const COURSES = '/student/courses/';
async function allPagesOverflow(p, width, ids, label) {
  const pages = ['/student/', COURSES, `${COURSES}${ids.batch}/`, '/student/notices/'];
  for (const u of pages) {
    await visit(p, u, `${label}${u.replace(/\W+/g, '_')}`);
    const o = await overflow(p);
    check(`${label} ${u} no horizontal overflow @${width}`, o <= 0, String(o));
  }
}

async function studentA(browser, fx, width) {
  console.log(`Student A @${width}px`);
  const A = fx.A;
  const { ctx, p } = await newCtx(browser, width, { downloads: true });
  const responses = [];
  ctx.on('response', async (r) => {
    try {
      const u = r.url();
      if (!u.startsWith(BASE) || /\/live-classes\/\d+\/join/.test(u)) return;
      const ct = r.headers()['content-type'] || '';
      if (/text|json|javascript/.test(ct)) responses.push({ u, body: await r.text() });
    } catch { /* body unavailable (redirect) */ }
  });
  check('student A logs in', await studentLogin(p, A), p.url());
  await visit(p, '/student/', `A-dashboard`);
  const t = fx.titles;
  const L = fx.liveTitles;
  const dash = await text(p, 'main, body');
  check('dashboard shows today live class (title + batch)', dash.includes(L.a), dash.slice(0, 300));
  check('dashboard shows today lesson (Bangla title)', dash.includes(`প্রথম পাঠ ${TAG}`));
  check('dashboard top notices: targeted + public', dash.includes(t.targeted) && dash.includes(t.pub), dash.slice(0, 400));
  check('dashboard hides draft/scheduled/archived notices', !dash.includes(t.draft) && !dash.includes(t.sched) && !dash.includes(t.arch));
  check('dashboard shows at most 3 notices', (await p.$$('.notice-feed--compact li')).length <= 3);
  const btn = p.locator(`.live-join[data-live-id="${fx.liveA}"]`);
  check('Join button for class A present, enabled, text "Join class"', (await btn.isEnabled()) && /Join class/.test(await btn.textContent()), await btn.textContent());
  const aria = await btn.getAttribute('aria-label');
  check('Join button aria-label states title and state', !!aria && aria.includes(L.a) && /live now/i.test(aria), String(aria));
  check('lesson row has no Join button', (await p.$$(`.live-row:has-text("প্রথম পাঠ") .live-join`)).length === 0);
  check(`dashboard no overflow @${width}`, (await overflow(p)) <= 0, String(await overflow(p)));
  const farRow = await p.$(`.live-join[data-live-id="${fx.liveFar}"]`);
  note(`far-future class on dashboard: ${farRow ? await farRow.textContent() : 'not listed (>7 days? it is +3 days so it should be "Coming up")'}`);

  // Join by click
  await resetLimits();
  const before = ctx.__popups.length;
  await btn.click();
  await p.waitForFunction(() => true);
  const deadline = Date.now() + 10000;
  while (Date.now() < deadline && !(ctx.__popups.length > before && /meet\.google\.com/.test(ctx.__popups[ctx.__popups.length - 1].url()))) await sleep(200);
  const popup = ctx.__popups[ctx.__popups.length - 1];
  check('Join opens a new page', ctx.__popups.length > before);
  check('new page navigated to the Meet URL', !!popup && popup.url() === URL_A, popup ? popup.url() : 'none');
  if (popup) check('popup has no opener (noopener semantics)', (await popup.evaluate(() => window.opener).catch(() => 'err')) === null);
  await sleep(500);
  const domAfter = await p.content();
  check('Meet URL is not in the DOM after Join', !leaksUrl(domAfter));
  const storage = await p.evaluate(() => JSON.stringify([localStorage, sessionStorage, document.cookie]));
  check('Meet URL is not in localStorage/sessionStorage/cookies', !leaksUrl(storage));
  check('Meet URL never in console output', !ctx.__console.some(leaksUrl), ctx.__console.filter(leaksUrl).join(' | '));
  check('Meet URL not in any page/script/json response body', !responses.some((r) => leaksUrl(r.body)), responses.filter((r) => leaksUrl(r.body)).map((r) => r.u).join(' '));
  check('button is back to "Join class" after launching', /Join class/.test(await btn.textContent()), await btn.textContent());

  // Courses
  await visit(p, COURSES, 'A-courses');
  const courses = await text(p, 'main, body');
  check('/student/courses/ lists the batch', courses.includes(fx.batchAName), courses.slice(0, 200));
  const courseLink = await p.getAttribute(`a[href*="/student/courses/${BATCH_A}/"]`, 'href').catch(() => null);
  check('/student/courses/ links to the batch page', !!courseLink);
  await visit(p, `${COURSES}${BATCH_A}/`, 'A-course');
  const course = await text(p, 'main, body');
  check('batch page lists module', course.includes(`মডিউল ১ ${TAG}`));
  const lessonOrder = await p.$$eval('.lesson-list__title', (els) => els.map((e) => e.textContent.trim()));
  check('lessons in admin-set order, deleted one absent', lessonOrder.length === 3 && lessonOrder[0].includes('Lesson two') && lessonOrder[1].includes('প্রথম') && lessonOrder[2].startsWith('Long') && !course.includes('Lesson to delete'), JSON.stringify(lessonOrder.map((x) => x.slice(0, 20))));
  check('lesson time shown for scheduled lesson', /\d{1,2}:\d\d (AM|PM)/.test(await text(p, `li:has-text("প্রথম পাঠ")`)));
  check('batch page lists live classes (A, far, ended)', course.includes(L.a) && course.includes(L.far) && course.includes(L.ended));
  check(`batch page no overflow @${width} (incl. 150-char unbroken lesson title)`, (await overflow(p)) <= 0, String(await overflow(p)));
  // Inactive / ended states
  const far = p.locator(`.live-join[data-live-id="${fx.liveFar}"]`);
  const ended = p.locator(`.live-join[data-live-id="${fx.liveEnded}"]`);
  const farTxt = (await far.textContent()).trim();
  check('far-future class: disabled, "Starts at …" with countdown', (await far.isDisabled()) && /^Starts at \d{1,2}:\d\d (AM|PM) \(/.test(farTxt), farTxt);
  check('far-future class aria-label says not open yet', /not open yet/i.test((await far.getAttribute('aria-label')) || ''), String(await far.getAttribute('aria-label')));
  const styles = async (l) => l.evaluate((e) => { const s = getComputedStyle(e); return `${s.backgroundColor}|${s.color}|${s.opacity}|${s.cursor}`; });
  const sFar = await styles(far); const sAct = await styles(btn); const sEnd = await styles(ended);
  check('inactive Join is visually muted (differs from active)', sFar !== sAct, `${sFar} vs ${sAct}`);
  note(`styles active=${sAct} inactive=${sFar} ended=${sEnd}`);
  const endTxt = (await ended.textContent()).trim();
  check('ended class: disabled, "Class ended"', (await ended.isDisabled()) && /^Class ended/.test(endTxt), endTxt);
  check('ended aria-label', /class ended/i.test((await ended.getAttribute('aria-label')) || ''));

  // PDF
  const dl = await p.getAttribute('a:has-text("Download PDF")', 'href');
  const pdfRes = await ctx.request.get(new URL(dl, BASE).toString());
  const bytes = await pdfRes.body();
  check('PDF download: 200 + application/pdf + bytes start %PDF', pdfRes.status() === 200 && /application\/pdf/.test(pdfRes.headers()['content-type'] || '') && bytes.subarray(0, 4).toString() === '%PDF', `${pdfRes.status()} ${pdfRes.headers()['content-type']}`);
  check('PDF headers: attachment, no-store, nosniff', /attachment; filename="[\w.-]+\.pdf"/.test(pdfRes.headers()['content-disposition'] || '') && /no-store/.test(pdfRes.headers()['cache-control'] || '') && pdfRes.headers()['x-content-type-options'] === 'nosniff', JSON.stringify(pdfRes.headers()));
  const [download] = await Promise.all([p.waitForEvent('download', { timeout: 15000 }).catch(() => null), p.click('a:has-text("Download PDF")')]);
  if (download) {
    const f = await download.path();
    check('browser download saves a PDF', fs.readFileSync(f).subarray(0, 4).toString() === '%PDF' && /\.pdf$/.test(download.suggestedFilename()), download.suggestedFilename());
  } else check('browser download event fired', false, 'no download');

  // Notices feed
  await visit(p, '/student/notices/', 'A-notices');
  const feed = await text(p, 'main, body');
  check('/student/notices/ shows targeted + public', feed.includes(t.targeted) && feed.includes(t.pub), feed.slice(0, 300));
  check('/student/notices/ hides draft, scheduled, archived', !feed.includes(t.draft) && !feed.includes(t.sched) && !feed.includes(t.arch));
  // Open targeted notice permalink as the target student
  const nHref = await p.getAttribute(`a:has-text("${fx.tok.targeted}")`, 'href').catch(() => null);
  fx.targetedUrl = nHref;
  if (nHref) {
    const r = await p.goto(nHref);
    check('target student can open the targeted notice permalink', r.status() === 200 && (await text(p, 'body')).includes('Body of targeted'), String(r.status()));
  } else check('targeted notice has a link in the feed', false);

  // Keyboard
  await visit(p, '/student/');
  await resetLimits();
  let reached = false;
  for (let i = 0; i < 80 && !reached; i++) {
    await p.keyboard.press('Tab');
    reached = await p.evaluate((id) => document.activeElement?.getAttribute('data-live-id') === id, String(fx.liveA));
  }
  check('keyboard: Tab reaches the active Join button', reached);
  if (reached) {
    const focusStyle = await p.evaluate(() => { const s = getComputedStyle(document.activeElement); return { o: `${s.outlineStyle} ${s.outlineWidth}`, b: s.boxShadow }; });
    check('Join button focus is visible', (focusStyle.o && !/^none/.test(focusStyle.o) && !/ 0px/.test(focusStyle.o)) || (focusStyle.b && focusStyle.b !== 'none'), JSON.stringify(focusStyle));
    const n0 = ctx.__popups.length;
    await p.keyboard.press('Enter');
    const d2 = Date.now() + 10000;
    while (Date.now() < d2 && !(ctx.__popups.length > n0 && /meet\.google\.com/.test(ctx.__popups[ctx.__popups.length - 1].url()))) await sleep(200);
    check('keyboard: Enter on Join opens the Meet page', ctx.__popups.length > n0 && ctx.__popups[ctx.__popups.length - 1].url() === URL_A);
  }
  const unnamed = await p.evaluate(() => [...document.querySelectorAll('button,a,input,select')].filter((e) => !(e.textContent || '').trim() && !e.getAttribute('aria-label') && !e.getAttribute('aria-labelledby') && e.type !== 'hidden').length);
  check('dashboard: every interactive element has an accessible name', unnamed === 0, String(unnamed));
  await allPagesOverflow(p, width, { batch: BATCH_A }, 'A');
  await ctx.close();
  return A;
}

async function studentAWidths(browser, fx) {
  for (const w of [768]) {
    const { ctx, p } = await newCtx(browser, w);
    await studentLogin(p, fx.A);
    await allPagesOverflow(p, w, { batch: BATCH_A }, 'A');
    await ctx.close();
  }
}

async function studentB(browser, fx) {
  console.log('Student B @1280px');
  const B = fx.B;
  const { ctx, p } = await newCtx(browser, 1280);
  check('student B logs in', await studentLogin(p, B), p.url());
  await visit(p, '/student/');
  const dash = await text(p, 'main, body');
  const t = fx.titles;
  check('B does not see batch A notice on dashboard', !dash.includes(t.targeted) && !dash.includes(fx.tok.targeted));
  check('B sees public notice', dash.includes(t.pub));
  check('B sees its own live class, not A\'s', dash.includes(fx.liveTitles.b) && !dash.includes(fx.liveTitles.a) && !dash.includes(fx.liveTitles.far));
  check('B does not see batch A lesson', !dash.includes('প্রথম পাঠ'));
  await visit(p, '/student/notices/');
  const feed = await text(p, 'main, body');
  check('B notices feed: public yes, targeted no', feed.includes(t.pub) && !feed.includes(fx.tok.targeted));
  await visit(p, `${COURSES}${BATCH_A}/`);
  const na = await text(p, 'main, body');
  check('B /student/courses/<A>/ shows friendly "not available"', /not available/i.test(na) && !na.includes(`মডিউল ১`) && !na.includes(fx.liveTitles.a), na.slice(0, 200));
  await visit(p, `${COURSES}999999/`);
  check('nonexistent batch page identical message', (await text(p, 'main, body')).includes('not available'));
  check('B /student/courses/<B>/ is available', !/not available/i.test((await visit(p, `${COURSES}${BATCH_B}/`), await text(p, 'main, body'))));
  await visit(p, `${COURSES}`);
  check('B courses list excludes batch A', !(await text(p, 'main, body')).includes(fx.batchAName) || fx.batchAName === fx.batchBName);
  // PDF 404 identical
  const real = await ctx.request.get(`${BASE}/student/resources/${fx.l1.id}/`, { failOnStatusCode: false });
  const none = await ctx.request.get(`${BASE}/student/resources/99999999/`, { failOnStatusCode: false });
  const sig = async (r) => JSON.stringify([r.status(), await r.text(), r.headers()['content-type'], r.headers()['cache-control']]);
  check('B: batch A PDF -> 404 identical to nonexistent lesson', real.status() === 404 && (await sig(real)) === (await sig(none)), await sig(real));
  const nopdf = await ctx.request.get(`${BASE}/student/resources/${fx.l4.id}/`, { failOnStatusCode: false });
  check('lesson without PDF -> same 404', (await sig(nopdf)) === (await sig(none)));
  // Join
  await visit(p, '/student/');
  const nonce = await p.evaluate(() => window.ASTONA_PORTAL && window.ASTONA_PORTAL.nonce);
  const join = (id, ctxReq = ctx.request, n = nonce) => ctxReq.post(`${BASE}/wp-json/cc/v1/live-classes/${id}/join`, { headers: n ? { 'X-WP-Nonce': n } : {}, failOnStatusCode: false });
  await resetLimits();
  const rA = await join(fx.liveA);
  const bodyA = await rA.text();
  check('B join batch A class -> 403 not_enrolled, no URL', rA.status() === 403 && /not_enrolled/.test(bodyA) && !leaksUrl(bodyA), `${rA.status()} ${bodyA}`);
  check('join denial is no-store', /no-store/.test(rA.headers()['cache-control'] || ''));
  const rF = await join(fx.liveFar);
  check('B join batch A far class -> 403', rF.status() === 403 && !leaksUrl(await rF.text()));
  const r404 = await join(99999999);
  check('join nonexistent class -> 404, no URL', r404.status() === 404 && !leaksUrl(await r404.text()));
  const noNonce = await join(fx.liveB, ctx.request, '');
  check('join without REST nonce -> 401/403, no URL', [401, 403].includes(noNonce.status()) && !leaksUrl(await noNonce.text()), String(noNonce.status()));
  // Positive: B joins own class via UI popup
  const btn = p.locator(`.live-join[data-live-id="${fx.liveB}"]`);
  const n0 = ctx.__popups.length;
  await btn.click();
  const d = Date.now() + 10000;
  while (Date.now() < d && !(ctx.__popups.length > n0 && /meet\.google\.com/.test(ctx.__popups[ctx.__popups.length - 1].url()))) await sleep(200);
  check('B joins own class: Meet page opens with B\'s URL', ctx.__popups.length > n0 && ctx.__popups[ctx.__popups.length - 1].url() === URL_B);
  // Targeted notice permalink as B
  if (fx.targetedUrl) {
    const r = await p.goto(fx.targetedUrl);
    check('B: targeted notice permalink -> 404', r.status() === 404 && !(await p.content()).includes(fx.tok.targeted), String(r.status()));
  }
  // A cannot join B's class
  const ca = await newCtx(browser, 1280);
  await studentLogin(ca.p, fx.A);
  await visit(ca.p, '/student/');
  const nA = await ca.p.evaluate(() => window.ASTONA_PORTAL && window.ASTONA_PORTAL.nonce);
  const aJoinB = await join(fx.liveB, ca.ctx.request, nA);
  check('A join batch B class -> 403, no URL', aJoinB.status() === 403 && !leaksUrl(await aJoinB.text()));
  const aa = await visit(ca.p, `${COURSES}${BATCH_B}/`);
  check('A cannot open batch B course page', /not available/i.test(await text(ca.p, 'main, body')));
  await ca.ctx.close();
  await ctx.close();
}

async function anonymous(browser, fx) {
  console.log('Anonymous @1280px');
  const { ctx, p } = await newCtx(browser, 1280);
  const T = fx.tok.targeted;
  const n = fx.n;
  const titlesHidden = [T, fx.titles.targeted, 'Body of targeted', 'বিজ্ঞপ্তি QATGT'];
  const noLeak = (s) => !titlesHidden.some((x) => s.includes(x));
  const get = (u) => ctx.request.get(u.startsWith('http') ? u : `${BASE}${u}`, { failOnStatusCode: false, maxRedirects: 3 });

  await visit(p, '/notices/', 'anon-notices');
  const arch = await text(p, 'main, body');
  check('public /notices/ shows the Public notice', arch.includes(fx.titles.pub), arch.slice(0, 300));
  check('public /notices/ hides targeted, draft, scheduled, archived', noLeak(arch) && !arch.includes(fx.titles.draft) && !arch.includes(fx.titles.sched) && !arch.includes(fx.titles.arch));
  await p.setViewportSize({ width: 375, height: 900 });
  await visit(p, '/notices/');
  check('public /notices/ no overflow @375', (await overflow(p)) <= 0);
  await p.setViewportSize({ width: 1280, height: 900 });

  const permalink = async (row) => php(`echo "JSON:".json_encode(get_permalink(${row.ID}));`);
  const targetedLink = await permalink(n.targeted);
  const checks = [
    ['targeted permalink', targetedLink], ['targeted ?p=', `/?p=${n.targeted.ID}`], ['targeted ?p=&post_type', `/?p=${n.targeted.ID}&post_type=cc_notice`],
    ['targeted ?cc_notice=slug', `/?cc_notice=${n.targeted.post_name}`], ['targeted ?preview=true', `/?p=${n.targeted.ID}&preview=true`],
    ['REST list', '/wp-json/wp/v2/cc_notice?per_page=100'], ['REST ?include=', `/wp-json/wp/v2/cc_notice?include=${n.targeted.ID}`],
    ['REST ?search=', `/wp-json/wp/v2/cc_notice?search=${T}`], ['REST single', `/wp-json/wp/v2/cc_notice/${n.targeted.ID}`],
    ['REST ?slug=', `/wp-json/wp/v2/cc_notice?slug=${n.targeted.post_name}`], ['REST ?status=any', '/wp-json/wp/v2/cc_notice?status=any&per_page=100'],
    ['REST global search', `/wp-json/wp/v2/search?search=${T}`], ['oEmbed', `/wp-json/oembed/1.0/embed?url=${encodeURIComponent(targetedLink)}`],
    ['feed cc_notice', '/feed/?post_type=cc_notice'], ['feed main', '/feed/'], ['post type feed', '/notices/feed/'], ['sitemap index', '/wp-sitemap.xml'],
    ['sitemap cc_notice', '/wp-sitemap-posts-cc_notice-1.xml'], ['search page', `/?s=${T}`], ['search cc_notice', `/?s=${T}&post_type=cc_notice`],
    ['search by Bangla word', `/?s=${encodeURIComponent('বিজ্ঞপ্তি')}`],
  ];
  for (const [label, url] of checks) {
    const r = await get(url);
    const body = await r.text();
    const isPermalink = /permalink|\?p=|cc_notice=|preview/.test(label);
    const scrubbed = /search/i.test(label) ? body.split(T).join('') : body; // the search page echoes the query itself
    const ok = noLeak(scrubbed) && !(isPermalink && r.status() === 200 && body.includes('Body of targeted')) && !(n.targeted.ID && new RegExp(`/${n.targeted.post_name}/|[?&]p=${n.targeted.ID}\\b`).test(body) && /sitemap|feed/i.test(label));
    check(`anon ${label} does not leak the targeted notice`, ok, `${r.status()} ${body.slice(0, 120)}`);
    if (isPermalink || label === 'REST single') check(`anon ${label} is 404/401/403 (not 200)`, r.status() !== 200 || /sitemap|\[\]/.test(label), String(r.status()));
  }
  // Positive controls: public notice IS reachable; archived/draft/scheduled are not
  const pl = await get(await permalink(n.pub));
  check('anon: public notice permalink 200', pl.status() === 200 && (await pl.text()).includes('Body of public'), String(pl.status()));
  for (const [label, row] of [['archived', n.arch], ['draft', n.draft], ['scheduled', n.sched]]) {
    const r = await get(await permalink(row));
    check(`anon: ${label} notice permalink is not 200`, r.status() !== 200 || !(await r.text()).includes('Body of'), String(r.status()));
  }
  const restPub = await get(`/wp-json/wp/v2/cc_notice?search=${fx.tok.pub}`);
  note(`REST list of public notice by search: ${restPub.status()} ${(await restPub.text()).slice(0, 60)}`);
  const feedPub = await (await get('/feed/?post_type=cc_notice')).text();
  note(`feed contains public notice title: ${feedPub.includes(fx.titles.pub)}`);
  // Anonymous cannot download PDF or join
  const res = await get(`/student/resources/${fx.l1.id}/`);
  check('anon PDF URL does not return a PDF', !/application\/pdf/.test(res.headers()['content-type'] || ''), `${res.status()} ${res.headers()['content-type']}`);
  const jr = await ctx.request.post(`${BASE}/wp-json/cc/v1/live-classes/${fx.liveA}/join`, { failOnStatusCode: false });
  check('anon join -> 401/403, no URL', [401, 403].includes(jr.status()) && !leaksUrl(await jr.text()), String(jr.status()));
  // Page HTML scan
  for (const u of ['/', '/notices/', '/courses/', '/student/login/']) {
    const html = await (await get(u)).text();
    check(`anon ${u} HTML has no meeting URL`, !leaksUrl(html));
  }
  const js = await (await get('/wp-content/themes/coaching-theme/assets/js/student-live.js')).text();
  check('student-live.js contains no meeting URL', !leaksUrl(js));
  await ctx.close();
}

async function deactivation(browser, fx) {
  console.log('Deactivation of student A');
  const A = fx.A;
  const stale = await newCtx(browser, 1280);
  await studentLogin(stale.p, A);
  await visit(stale.p, '/student/');
  const nonce = await stale.p.evaluate(() => window.ASTONA_PORTAL.nonce);
  const joinBefore = await stale.ctx.request.post(`${BASE}/wp-json/cc/v1/live-classes/${fx.liveA}/join`, { headers: { 'X-WP-Nonce': nonce }, failOnStatusCode: false });
  check('before deactivation: A join returns 200 + https URL', joinBefore.status() === 200 && (await joinBefore.json()).url === URL_A, String(joinBefore.status()));
  check('join response is private/no-store', /no-store/.test(joinBefore.headers()['cache-control'] || ''));

  const staff = await newCtx(browser, 1280);
  await wpLogin(staff.p, fx.staff);
  await visit(staff.p, adminUrl('cc-students', `&user=${A.userId}`));
  await staff.p.click(`tr:has-text("${fx.batchAName}") button:text-is("Deactivate")`);
  await staff.p.waitForLoadState('load');
  const st = await php(`global $wpdb; echo "JSON:".json_encode($wpdb->get_var("SELECT status FROM {$wpdb->prefix}cc_enrollments WHERE id=${A.enrollmentId}"));`);
  check('enrollment deactivated by staff in admin', st === 'deactivated', String(st));
  await staff.ctx.close();

  // Stale open page: click Join
  const btn = stale.p.locator(`.live-join[data-live-id="${fx.liveA}"]`);
  await resetLimits();
  await btn.click();
  await stale.p.waitForFunction((id) => /access has ended/i.test(document.querySelector(`.live-join[data-live-id="${id}"]`).textContent), fx.liveA, { timeout: 8000 }).catch(() => {});
  check('stale page: Join shows "Your access has ended" and is disabled', /access has ended/i.test(await btn.textContent()) && (await btn.isDisabled()), await btn.textContent());
  const popupsWithUrl = stale.ctx.__popups.filter((pg) => /meet\.google\.com/.test(pg.url()));
  check('stale page: no Meet page opened after deactivation', popupsWithUrl.length === 0, popupsWithUrl.map((x) => x.url()).join());
  check('stale page announces denial in status region', /access/i.test(await text(stale.p, '#portal-status')), await text(stale.p, '#portal-status'));

  const rj = await stale.ctx.request.post(`${BASE}/wp-json/cc/v1/live-classes/${fx.liveA}/join`, { headers: { 'X-WP-Nonce': nonce }, failOnStatusCode: false });
  const rjb = await rj.text();
  check('REST join after deactivation -> 403, no URL', rj.status() === 403 && !leaksUrl(rjb), `${rj.status()} ${rjb}`);
  const pdf = await stale.ctx.request.get(`${BASE}/student/resources/${fx.l1.id}/`, { failOnStatusCode: false });
  check('PDF after deactivation -> 404', pdf.status() === 404, String(pdf.status()));
  await visit(stale.p, '/student/', 'A-ended');
  const dash = await text(stale.p, 'main, body');
  check('dashboard says access ended', /access (has )?ended/i.test(dash) || /ended/i.test(dash), dash.slice(0, 400));
  check('dashboard no longer shows class, lesson or targeted notice', !dash.includes(fx.liveTitles.a) && !dash.includes('প্রথম পাঠ') && !dash.includes(fx.tok.targeted), dash.slice(0, 500));
  await visit(stale.p, `${COURSES}${BATCH_A}/`);
  check('course page "not available" after deactivation', /not available/i.test(await text(stale.p, 'main, body')));
  await visit(stale.p, '/student/notices/');
  check('notices feed hides targeted after deactivation', !(await text(stale.p, 'main, body')).includes(fx.tok.targeted));
  if (fx.targetedUrl) {
    const r = await stale.p.goto(fx.targetedUrl);
    check('targeted permalink 404 for deactivated student', r.status() === 404, String(r.status()));
  }
  note(`deactivated dashboard: ${dash.slice(0, 160)}`);
  await stale.ctx.close();
}

/* ---------------------------------------------------------------- main */

const browser = await chromium.launch();
const fx = { students: [], seats: {} };
try {
  fx.seats = (await php(`global $wpdb; $o=[]; foreach($wpdb->get_results("SELECT id,seats_taken FROM {$wpdb->prefix}cc_batches WHERE id IN (${BATCH_A},${BATCH_B})",ARRAY_A) as $x){ $o[$x['id']]=(int)$x['seats_taken']; } echo "JSON:".json_encode($o ?: new stdClass);`)) || {};
  fx.batchAName = await php(`echo "JSON:".json_encode(CC_Admin_Applications::batch_options()[${BATCH_A}] ?? '');`);
  fx.batchBName = await php(`echo "JSON:".json_encode(CC_Admin_Applications::batch_options()[${BATCH_B}] ?? '');`);
  console.log(`TAG=${TAG} batch A=${BATCH_A} (${fx.batchAName}) batch B=${BATCH_B} (${fx.batchBName})`);
  fx.staff = await mkStaff();
  check('staff user created', fx.staff.id > 0);
  fx.A = await makeStudent(browser, `QA Student A ${TAG}`, BATCH_A);
  if (fx.A) fx.students.push(fx.A);
  fx.B = await makeStudent(browser, `QA Student B ${TAG}`, BATCH_B);
  if (fx.B) fx.students.push(fx.B);
  check('students A and B provisioned (temp password SMS)', !!fx.A && !!fx.B);
  if (!fx.A || !fx.B) throw new Error('could not provision students');

  await staffContent(browser, fx);
  await staffLive(browser, fx);
  await staffNotices(browser, fx);

  // SMS: exactly one for A, none for B
  console.log('Notice SMS');
  await resetLimits();
  const re = new RegExp(`Astona notice: .*${fx.tok.targeted}`);
  const got = await waitSms(fx.A.phone, re, { timeoutMs: 90000 });
  check('student A received the critical notice SMS', !!got);
  await runQueues();
  await runQueues();
  const box = await outbox();
  const toA = box.filter((m) => m.to === e164(fx.A.phone) && re.test(m.body));
  const toB = box.filter((m) => m.to === e164(fx.B.phone) && /Astona notice/.test(m.body));
  check('exactly one notice SMS for student A', toA.length === 1, String(toA.length));
  check('no notice SMS for student B (other batch)', toB.length === 0, String(toB.length));
  const noticeSmsTotal = box.filter((m) => m.time >= fx.smsSince && /Astona notice/.test(m.body)).length;
  check('exactly one notice SMS in total for this run (non-critical/public/scheduled send none)', noticeSmsTotal === 1, String(noticeSmsTotal));
  if (toA[0]) note(`SMS body: ${toA[0].body}`);

  await studentA(browser, fx, 1280);
  await studentA(browser, fx, 375);
  await studentAWidths(browser, fx);
  await studentB(browser, fx);
  await anonymous(browser, fx);
  await deactivation(browser, fx);

  const real = consoleErrors.filter((e) => !/favicon|status of 4\d\d|fonts\.(googleapis|gstatic)|ViewTransition|net::ERR_(INTERNET|NAME|CONNECTION|FAILED|BLOCKED)/.test(e));
  check('no console errors in any page', real.length === 0, real.slice(0, 5).join(' ; '));
  check('no PHP warnings/notices in any page', phpErrors.length === 0, phpErrors.slice(0, 3).join(' ; '));
} catch (e) {
  console.log('  FAIL unexpected error:', e.stack || e.message);
  failures++;
} finally {
  await cleanup(fx).catch((e) => console.log('cleanup error', e.message));
  await browser.close();
}
console.log(failures ? `\n${failures} FAILED` : '\nall passed');
process.exit(failures ? 1 : 0);
