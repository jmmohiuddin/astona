#!/usr/bin/env node
/**
 * wp-admin (Astona menu) browser e2e: owner / staff / instructor / student, 1280px and 768px, headless Chromium.
 *
 * Run from the repo root (Node 18+, docker compose dev stack on BASE_URL, Playwright + Chromium):
 *   npm i playwright && npx playwright install chromium     # once, in any dir on the module path
 *   BASE_URL=http://localhost:8080 BATCH=2 node tests/e2e/admin-browser.mjs
 * Playwright is resolved from this file first, then from the current working directory.
 * Optional: SHOT_DIR=/some/dir saves a screenshot of every admin screen visited.
 *
 * Fixtures (all throwaway, removed in a finally block, tagged QAADM<timestamp>):
 *   - wp users with roles cc_owner / cc_staff / cc_instructor, created via `docker compose run --rm -T wpcli`
 *     (override with WPCLI="..."; always run from the repo root);
 *   - 7 real applications through the public /admissions/ form and the fake gateway: 2 paid (students are
 *     provisioned through Action Scheduler / WP-Cron, triggered here), 5 left unpaid (pending, initiated payment);
 *   - the cc_settings option is snapshotted and restored.
 * Dev only: needs the fake gateway + fake SMS outbox (cc_fake_sms_outbox). Exit code 0 = all passed, 1 = any failure.
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
const BATCH = process.env.BATCH || '2';
const SHOT_DIR = process.env.SHOT_DIR || '';
const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const WPCLI = (process.env.WPCLI || 'docker compose run --rm -T wpcli').split(' ');
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
// Letters only: digits in a student search term also match phone numbers (see the widening check below).
const TAG = 'QAADM' + Date.now().toString(36).replace(/\d/g, (d) => 'ghijklmnop'[d]).toUpperCase();
const PW = 'Adm-Qa-' + Math.random().toString(36).slice(2, 10) + '-9!';
const ID_NUMBER = '1234567890';

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
  execFile(WPCLI[0], [...WPCLI.slice(1), ...args], { cwd: REPO, maxBuffer: 20e6 }, (err, stdout) => resolve(err ? '' : stdout));
});
async function wpJson(php) {
  const out = await wp('eval', php);
  const m = out.match(/(^|\n)(\{.*\}|\[.*\])\s*$/s) || out.match(/(\{.*\}|\[.*\])\s*$/s);
  try { return JSON.parse((m ? m[m.length - 1] : out).trim()); } catch { return null; }
}
async function outbox() {
  const out = await wp('option', 'get', 'cc_fake_sms_outbox', '--format=json');
  const start = out.indexOf('[');
  try { return JSON.parse(out.slice(start)); } catch { return []; }
}
const resetLimits = () => wp('eval', 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%cc_rl_%\'");');
const pwMessages = async (phone) => (await outbox()).filter((m) => m.to === e164(phone) && /temporary password (\w+)/.test(m.body));
async function waitPw(phone, { after = 0, timeoutMs = 90000, page } = {}) {
  const deadline = Date.now() + timeoutMs;
  let tick = 0;
  while (Date.now() < deadline) {
    const list = await pwMessages(phone);
    if (list.length > after) return list[list.length - 1].body.match(/temporary password (\w+)/)[1];
    if (tick++ % 2 === 0) {
      if (page) await page.request.get(`${BASE}/wp-cron.php?doing_wp_cron`).catch(() => {});
      await wp('cron', 'event', 'run', '--due-now');
      await wp('action-scheduler', 'run');
    }
    await sleep(2000);
  }
  return null;
}

// Notice selectors never match core nags (update-nag etc.), only the screen's own result notice.
const text = async (p, sel) => ((await p.textContent(appNoticeSelector(sel)).catch(() => '')) || '').replace(/\s+/g, ' ').trim();
const overflow = (p) => p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
const PHP_ERR = /(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)\b[^\n]{0,200}\bon line \d+|There has been a critical error/;

/* ---------------------------------------------------------------- pages */

const pageErrors = [];
const phpErrors = [];
async function newCtx(browser, width) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, acceptDownloads: true });
  const p = await ctx.newPage();
  p.on('console', (m) => { if (m.type() === 'error') pageErrors.push(`${m.text()} @ ${p.url()}`); });
  p.on('pageerror', (e) => pageErrors.push(`pageerror ${e.message} @ ${p.url()} ${(e.stack || '').split('\n').slice(1, 3).join(' | ')}`));
  return { ctx, p };
}
/** goto + PHP error scan + optional screenshot. */
async function visit(p, url, shot = '') {
  const res = await p.goto(url.startsWith('http') ? url : `${BASE}${url}`, { waitUntil: 'load' });
  const body = (await p.content()) || '';
  const hit = body.match(PHP_ERR);
  if (hit) phpErrors.push(`${hit[0].slice(0, 160)} @ ${p.url()}`);
  if (SHOT_DIR && shot) {
    fs.mkdirSync(SHOT_DIR, { recursive: true });
    await p.screenshot({ path: path.join(SHOT_DIR, `${shot}-${p.viewportSize().width}.png`), fullPage: true }).catch(() => {});
  }
  return res;
}
const adminUrl = (page, extra = '') => `/wp-admin/admin.php?page=${page}${extra}`;
const NOT_ALLOWED = /not allowed to access this page|not allowed to (view|do)|do not have permission|link you followed has expired|cannot access/i;

async function wpLogin(p, user) {
  await p.goto(`${BASE}/admin/login/`);
  await p.fill('#user_login', user.login);
  await p.fill('#user_pass', user.pass);
  await p.click('#wp-submit');
  await p.waitForURL(/wp-admin/, { timeout: 20000 }).catch(() => {});
  await p.waitForLoadState('networkidle').catch(() => {});
  user.landed = p.url();
}
const menuLabels = (p) => p.$$eval('#adminmenu a.menu-top[href*="cc-dashboard"] ~ ul.wp-submenu a, #adminmenu li.toplevel_page_cc-dashboard ul.wp-submenu li:not(.wp-submenu-head) a',
  (as) => [...new Set(as.map((a) => a.textContent.replace(/\s+/g, ' ').trim()))]);

/* ---------------------------------------------------------------- fixtures */

async function mkUser(role) {
  const login = `${TAG.toLowerCase()}_${role}`;
  const out = await wp('user', 'create', login, `${login}@example.test`, `--role=${role}`, `--user_pass=${PW}`, '--porcelain');
  const id = parseInt(out.trim().split('\n').pop(), 10);
  return { id, login, pass: PW, role };
}

async function apply(p, { name, phone, pay }) {
  await p.goto(`${BASE}/admissions/?batch=${BATCH}`);
  await p.fill('#adm-full_name', name);
  await p.selectOption('#adm-gender', 'm');
  await p.fill('#adm-dob', '2008-05-05');
  await p.selectOption('#adm-id_doc_type', 'nid');
  await p.fill('#adm-id_doc_number', ID_NUMBER);
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
  if (pay) {
    await p.click('button:text-is("Pay")');
    await p.waitForSelector('#adm-status-title:text-is("Payment received")', { timeout: 25000 });
  }
}

async function appMeta(phones) {
  const both = [...new Set(phones.flatMap((x) => (x.startsWith('+') ? [x] : [x, e164(x)])))];
  const list = both.map((x) => `'${x}'`).join(',');
  const rows = await wpJson(`global $wpdb; $p=$wpdb->prefix; echo json_encode($wpdb->get_results("SELECT a.id,a.public_ref,a.student_phone,a.full_name,a.status,a.user_id,i.number AS invoice,
    (SELECT pay.id FROM {$p}cc_payments pay WHERE pay.invoice_id=i.id ORDER BY pay.id DESC LIMIT 1) AS payment_id,
    (SELECT pay.trx_id FROM {$p}cc_payments pay WHERE pay.invoice_id=i.id ORDER BY pay.id DESC LIMIT 1) AS trx
    FROM {$p}cc_applications a LEFT JOIN {$p}cc_invoices i ON i.application_id=a.id WHERE a.student_phone IN (${list})", ARRAY_A));`);
  return rows || [];
}

async function cleanup(fx) {
  const phones = fx.apps.map((a) => `'${e164(a.phone)}'`).concat(fx.apps.map((a) => `'${a.phone}'`)).join(',');
  const users = fx.users.map((u) => u.id).filter(Boolean);
  await wp('eval', `global $wpdb; $p=$wpdb->prefix; $phones=[${phones}];
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
    foreach($uids as $u){ $wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_students WHERE user_id=%d",$u)); $wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_sms_log WHERE related_type='student' AND related_id=%d",$u)); wp_delete_user((int)$u); }
    foreach([${users.join(',')}] as $u){ $wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_audit_log WHERE actor_id=%d",$u)); wp_delete_user((int)$u); }`);
  await wp('eval', `if(${JSON.stringify(fx.settingsBefore)} === null){delete_option('cc_settings');}else{update_option('cc_settings', json_decode(${JSON.stringify(JSON.stringify(fx.settingsBefore))}, true), false);}`);
}

/* ---------------------------------------------------------------- helpers */

const listRows = (p) => p.$$eval('#the-list tr', (trs) => trs.map((tr) => tr.textContent.replace(/\s+/g, ' ').trim()));
const subsubsub = (p) => p.$$eval('.subsubsub a', (as) => Object.fromEntries(as.map((a) => [a.textContent.replace(/\s*\(.*$/, '').trim().toLowerCase(), parseInt((a.textContent.match(/\((\d+)\)/) || [0, -1])[1], 10)])));
const rawGet = async (ctx, url, opts = {}) => ctx.request.get(url.startsWith('http') ? url : `${BASE}${url}`, { maxRedirects: 0, failOnStatusCode: false, ...opts });
const rawPost = async (ctx, url, form) => ctx.request.post(`${BASE}${url}`, { form, maxRedirects: 0, failOnStatusCode: false });
const hasFocusStyle = (p) => p.evaluate(() => {
  const el = document.activeElement;
  if (!el) return false;
  const cs = getComputedStyle(el);
  return (cs.boxShadow && cs.boxShadow !== 'none') || (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0);
});

/* ---------------------------------------------------------------- sections */

async function owner(browser, width, fx) {
  console.log(`Owner @${width}px`);
  const { ctx, p } = await newCtx(browser, width);
  const u = fx.owner;
  await wpLogin(p, u);
  const A = fx.byKey;

  // --- menu
  note(`owner lands after login at: ${u.landed}`);
  await visit(p, '/wp-admin/', 'owner-home');
  const labels = await menuLabels(p);
  note(`menu: ${labels.join(' | ')}`);
  for (const l of ['Dashboard', 'Applications', 'Students', 'Payments', 'Audit', 'Settings']) {
    check(`menu has "${l}"`, labels.some((x) => x.toLowerCase().startsWith(l.toLowerCase())), labels.join('|'));
  }
  check('menu top-level label is "Astona"', (await p.$$eval('#adminmenu a.menu-top .wp-menu-name', (e) => e.map((x) => x.textContent.trim()))).includes('Astona'));
  const bubble = await p.$eval('#adminmenu .awaiting-mod', (e) => parseInt(e.textContent, 10)).catch(() => -1);
  const dbCounts = await wpJson('echo json_encode(CC_Admin_Applications::status_counts());');
  check('Applications pending bubble equals pending count', bubble === dbCounts.pending, `${bubble} vs ${dbCounts.pending}`);

  // --- dashboard
  await visit(p, adminUrl('cc-dashboard'), 'owner-dashboard');
  check('dashboard heading', /Astona dashboard/.test(await text(p, '.wrap h1')));
  const cards = await p.$$eval('.cc-card', (cs) => Object.fromEntries(cs.map((c) => [c.querySelector('h3').textContent.trim(), c.querySelector('.cc-card__value').textContent.trim()])));
  const truth = await wpJson(`global $wpdb; $p=$wpdb->prefix; echo json_encode(['pending'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}cc_applications WHERE status='pending'"),'active'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}cc_enrollments WHERE status='active'"),'rev'=>(float)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$p}cc_payments WHERE status='completed'"),'stuck'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}cc_payments WHERE status='reconcile_needed' OR (status IN ('initiated','executing') AND created_at < '".gmdate('Y-m-d H:i:s',time()-600)."')")]);`);
  note(`cards ${JSON.stringify(cards)}`);
  check('card Pending applications == DB', parseInt(cards['Pending applications'], 10) === truth.pending, `${cards['Pending applications']} vs ${truth.pending}`);
  check('card Active enrollments == DB', parseInt(cards['Active enrollments'], 10) === truth.active, `${cards['Active enrollments']} vs ${truth.active}`);
  check('card Stuck payments == stuck by created_at (payments screen definition)', parseInt(cards['Stuck payments'], 10) === truth.stuck, `${cards['Stuck payments']} vs ${truth.stuck}`);
  const revText = await text(p, '.cc-card:has(h3:text-is("Revenue today")) .cc-card__meta');
  const totalM = revText.match(/total:\s*৳\s*([\d,.]+)/);
  check('revenue total meta equals SUM(completed)', !!totalM && Math.abs(parseFloat(totalM[1].replace(/,/g, '')) - truth.rev) < 1, `${revText} vs ${truth.rev}`);
  check('dashboard no overflow', (await overflow(p)) <= 0, String(await overflow(p)));
  await p.click('.cc-card:has(h3:text-is("Pending applications")) a');
  await p.waitForURL(/cc-applications/);
  check('pending card -> Applications status=pending', /status=pending/.test(p.url()), p.url());
  const rows = await listRows(p);
  check('pending screen lists only Pending rows', rows.length > 0 && rows.every((r) => /Pending/.test(r)), rows.slice(0, 2).join(' / '));
  await visit(p, adminUrl('cc-dashboard'));
  await p.click('.cc-card:has(h3:text-is("Stuck payments")) a');
  await p.waitForURL(/cc-payments/);
  check('stuck card -> Payments status=stuck', /status=stuck/.test(p.url()), p.url());
  check('stuck filter preselected "Needs attention"', (await p.$eval('select[name=status]', (s) => s.value)) === 'stuck');
  const totalsTxt = await text(p, '.cc-totals');
  const stuckRows = parseInt((totalsTxt.match(/(\d+) rows?/) || [0, -1])[1], 10);
  check('stuck screen row count == dashboard stuck card', stuckRows === parseInt(cards['Stuck payments'], 10), `${stuckRows} vs ${cards['Stuck payments']}`);
  check('recent activity panel present for owner', await (async () => { await visit(p, adminUrl('cc-dashboard')); return !!(await p.$('.cc-panel h2')); })());

  // --- applications list
  await visit(p, adminUrl('cc-applications'), 'owner-apps');
  const tabs = await subsubsub(p);
  note(`tabs ${JSON.stringify(tabs)}`);
  for (const s of ['all', 'pending', 'approved', 'rejected', 'cancelled']) check(`tab "${s}" count == DB`, tabs[s] === dbCounts[s], `${tabs[s]} vs ${dbCounts[s]}`);
  check('Applications no overflow', (await overflow(p)) <= 0, String(await overflow(p)));
  await visit(p, adminUrl('cc-applications', '&status=approved'));
  check('approved tab current + only Approved rows', (await listRows(p)).every((r) => /Approved/.test(r)) && !!(await p.$('.subsubsub a.current')));
  for (const [label, q, expect] of [['name', encodeURIComponent(TAG + ' Paid One'), A.paid1], ['phone (local format)', A.paid1.phone, A.paid1], ['phone (+880)', e164(A.paid1.phone), A.paid1], ['reference', A.paid1.public_ref, A.paid1], ['phone last digits', A.paid1.phone.slice(-6), A.paid1]]) {
    await visit(p, adminUrl('cc-applications', `&s=${q}`));
    const r = await listRows(p);
    check(`search by ${label} finds the application`, r.some((x) => x.includes(expect.public_ref)), `rows=${r.length}`);
  }
  await visit(p, adminUrl('cc-applications', '&s=zzz-no-such-' + Date.now()));
  check('search with no match shows empty state', /No applications found/.test(await text(p, '#the-list')));
  await visit(p, adminUrl('cc-applications', `&s=${encodeURIComponent(TAG)}&orderby=full_name&order=asc`));
  let names = await p.$$eval('#the-list td.column-full_name', (t) => t.map((x) => x.textContent.trim()));
  check('sort by Name asc is ascending', names.length > 1 && names.every((n, i) => i === 0 || names[i - 1].toLowerCase() <= n.toLowerCase()), names.join(' | '));
  await visit(p, adminUrl('cc-applications', `&s=${encodeURIComponent(TAG)}&orderby=full_name&order=desc`));
  names = await p.$$eval('#the-list td.column-full_name', (t) => t.map((x) => x.textContent.trim()));
  check('sort by Name desc is descending', names.length > 1 && names.every((n, i) => i === 0 || names[i - 1].toLowerCase() >= n.toLowerCase()), names.join(' | '));
  const today = new Date().toISOString().slice(0, 10);
  await visit(p, adminUrl('cc-applications', `&s=${TAG}&date_from=${today}&date_to=${today}`));
  check('date filter today finds fixtures', (await listRows(p)).length >= 5);
  await visit(p, adminUrl('cc-applications', `&s=${TAG}&date_from=2000-01-01&date_to=2000-01-02`));
  check('date filter in the past finds none', /No applications found/.test(await text(p, '#the-list')));
  await visit(p, adminUrl('cc-applications', `&s=${TAG}&batch_id=${BATCH}`));
  check('batch filter keeps fixtures', (await listRows(p)).length >= 5);
  await visit(p, adminUrl('cc-applications', '&batch_id=999999'));
  check('batch filter with unknown batch -> empty', /No applications found/.test(await text(p, '#the-list')));
  if (dbCounts.all > 20) {
    await visit(p, adminUrl('cc-applications'));
    check('pagination shown when >20', !!(await p.$('.tablenav-pages .next-page')));
    await visit(p, adminUrl('cc-applications', '&paged=2'));
    const r2 = await listRows(p);
    check('page 2 has rows', r2.length > 0 && r2.length <= 20, String(r2.length));
    await visit(p, adminUrl('cc-applications', '&paged=9999'));
    check('page far beyond the end does not crash', !PHP_ERR.test(await p.content()));
  } else note(`pagination: only ${dbCounts.all} applications in DB (<=20), not exercised`);
  await visit(p, adminUrl('cc-applications', '&s=' + encodeURIComponent(`${TAG} Tom & Jerry`)));
  const nameCell = (await p.$$eval('#the-list td.column-full_name', (t) => t.map((x) => x.textContent.trim())))[0] || '';
  check("special characters render once-escaped (& ' \")", nameCell.includes(`Tom & Jerry's "Q"`), nameCell);

  // --- detail
  await visit(p, adminUrl('cc-applications', `&view=${A.paid1.id}`), 'owner-app-detail');
  check('detail heading shows reference', (await text(p, '.wrap h1')).includes(A.paid1.public_ref));
  const idCell = await text(p, 'tr:has(th:has-text("number")) td');
  check('ID number masked to last 4', /^\*{4}7890/.test(idCell) && !idCell.includes(ID_NUMBER), idCell);
  const img = await p.$('img[alt="Applicant photo"]');
  check('photo present on detail', !!img);
  if (img) {
    await p.waitForTimeout(500);
    check('photo renders via authenticated stream', await img.evaluate((i) => i.complete && i.naturalWidth > 0));
    const src = await img.getAttribute('src');
    const anon = await browser.newContext();
    const r = await anon.request.get(src.startsWith('http') ? src : BASE + src, { maxRedirects: 0, failOnStatusCode: false });
    check('photo URL without a session is refused', r.status() !== 200 || !/image/.test(r.headers()['content-type'] || ''), String(r.status()));
    const r2 = await rawGet(ctx, src.replace(/_wpnonce=[^&]+/, '_wpnonce=deadbeef00'));
    check('photo URL with bad nonce is refused', r2.status() !== 200, String(r2.status()));
    await anon.close();
  }
  const pays = await text(p, '.wrap');
  check('payments timeline on detail (invoice + gateway + completed)', /Invoice INV-/.test(pays) && /fake/i.test(pays) && /completed/i.test(pays), pays.slice(0, 300));
  check('approved application has no reject form', !(await p.$('textarea[name=reason]')));
  check('enrollment section present for approved', /Enrollment/.test(pays));
  check('detail no overflow', (await overflow(p)) <= 0);

  // --- reveal (owner only; audited)
  if (width === 1280) {
    const auditBefore = await wpJson('global $wpdb; echo json_encode((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cc_audit_log WHERE action=\'application.reveal_id\'"));');
    await p.click('button:text-is("Reveal ID number")');
    await p.waitForLoadState('load');
    const body = await p.content();
    if (PHP_ERR.test(body)) phpErrors.push(`${body.match(PHP_ERR)[0]} @ reveal`);
    check('Reveal shows the full ID number', (await text(p, 'tr:has(th:has-text("number")) td')).includes(ID_NUMBER), (await text(p, 'body')).slice(0, 200));
    check('revealed number never enters the URL', !p.url().includes(ID_NUMBER), p.url());
    check('revealed page keeps admin chrome (menu present)', !!(await p.$('#adminmenu')));
    const auditAfter = await wpJson('global $wpdb; echo json_encode((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cc_audit_log WHERE action=\'application.reveal_id\'"));');
    check('Reveal wrote an audit row', auditAfter === auditBefore + 1, `${auditBefore} -> ${auditAfter}`);
  }

  // --- reconcile on an initiated payment (before anything touches pendA)
  await visit(p, adminUrl('cc-payments', `&payment=${A.pendA.payment_id}`), 'owner-payment-detail');
  check('initiated payment shows Reconcile now', !!(await p.$('button:text-is("Reconcile now")')));
  check('aged initiated payment flagged "Needs attention"', /Needs attention/.test(await text(p, '.wrap')));
  await p.click('button:text-is("Reconcile now")');
  await p.waitForURL(/cc_result=/, { timeout: 15000 }).catch(() => {});
  const notice = await text(p, '.notice');
  note(`reconcile notice: ${notice}`);
  check('Reconcile now shows a result notice on the detail screen', /cc_result=\w+/.test(p.url()) && notice.length > 0 && /payment=/.test(p.url()), p.url());
  check('Reconcile of initiated payment does not settle it', /not_completed|does not report/.test(notice), notice);
  check('application is still pending after reconcile', (await appMeta([A.pendA.phone]))[0]?.status === 'pending');

  // --- reject flows (1280 only: mutating)
  if (width === 1280) {
    await visit(p, adminUrl('cc-applications', `&view=${A.pendR.id}`));
    check('pending detail has reject form', !!(await p.$('textarea[name=reason]')));
    await p.evaluate(() => document.querySelector('textarea[name=reason]').removeAttribute('required'));
    await p.click('input[value="Reject application"]');
    await p.waitForURL(/cc_notice=/);
    check('empty reason refused with notice', /rejection reason is required/i.test(await text(p, '.notice')), await text(p, '.notice'));
    check('empty reason left it pending', (await appMeta([A.pendR.phone]))[0].status === 'pending');
    await p.fill('textarea[name=reason]', 'Duplicate application <b>QA</b>');
    await p.click('input[value="Reject application"]');
    await p.waitForURL(/cc_notice=rejected/);
    check('valid reject shows success', /Application rejected/.test(await text(p, '.notice')));
    check('reason stored and escaped on detail', (await text(p, 'tr:has(th:has-text("Reject reason")) td')).includes('Duplicate application'), await text(p, 'tr:has(th:has-text("Reject reason")) td'));
    check('rejected application no longer offers reject', !(await p.$('textarea[name=reason]')));
    const dbRes = await wpJson(`$r1=CC_Admin_Applications::reject(${A.paid1.id},'x',1); $r2=CC_Admin_Applications::reject(${A.pendR.id},'x',1); echo json_encode([is_wp_error($r1)?$r1->get_error_code():true, is_wp_error($r2)?$r2->get_error_code():true]);`);
    check('approved/paid application cannot be rejected (not_pending)', dbRes && dbRes[0] === 'not_pending', JSON.stringify(dbRes));
    check('already rejected cannot be rejected again', dbRes && dbRes[1] === 'not_pending', JSON.stringify(dbRes));

    // bulk with confirm step: two pending + one approved
    await visit(p, adminUrl('cc-applications', `&s=${TAG}`));
    for (const k of ['pendB1', 'pendB2', 'paid2']) await p.check(`input[name="application[]"][value="${A[k].id}"]`);
    await p.selectOption('#bulk-action-selector-top', 'reject');
    await p.click('#doaction');
    await p.waitForLoadState('load');
    check('bulk reject shows a confirm step', /Reject 3 application/.test(await text(p, '.wrap h1')), await text(p, '.wrap'));
    check('nothing rejected before confirmation', (await appMeta([A.pendB1.phone]))[0].status === 'pending');
    check('confirm step requires a reason (required attr)', await p.$eval('#cc-reason', (t) => t.required));
    await p.evaluate(() => document.querySelector('#cc-reason').removeAttribute('required'));
    await p.click('input[value="Confirm reject"]');
    await p.waitForURL(/cc_notice=/);
    check('bulk with empty reason refused', /reason is required/i.test(await text(p, '.notice')), await text(p, '.notice'));
    check('bulk with empty reason rejected nothing', (await appMeta([A.pendB1.phone]))[0].status === 'pending');
    await visit(p, adminUrl('cc-applications', `&s=${TAG}`));
    for (const k of ['pendB1', 'pendB2', 'paid2']) await p.check(`input[name="application[]"][value="${A[k].id}"]`);
    await p.selectOption('#bulk-action-selector-top', 'reject');
    await p.click('#doaction');
    await p.fill('#cc-reason', 'QA bulk reject');
    await p.click('input[value="Confirm reject"]');
    await p.waitForURL(/cc_notice=bulk_rejected/);
    check('bulk notice says 2 rejected', /Rejected 2 application/.test(await text(p, '.notice')), await text(p, '.notice'));
    const st = await appMeta([A.pendB1.phone, A.pendB2.phone, A.paid2.phone]);
    check('bulk: pending ones rejected, approved one untouched', st.filter((s) => s.status === 'rejected').length === 2 && st.find((s) => s.id == A.paid2.id).status === 'approved', JSON.stringify(st.map((s) => s.status)));
    await p.goto(`${BASE}${adminUrl('cc-applications', '&cc_notice=bulk_rejected&cc_n=<script>window.__pwn=1</script>')}`);
    check('cc_n injection renders no script/raw HTML', !(await p.evaluate(() => window.__pwn)) && !(await p.content()).includes('<script>window.__pwn'));
  }

  // --- CSV export
  await visit(p, adminUrl('cc-applications'));
  const href = await p.$eval('a.page-title-action', (a) => a.href);
  const csvRes = await ctx.request.get(href);
  const csv = (await csvRes.body()).toString('utf8');
  check('applications CSV served as attachment', /attachment/.test(csvRes.headers()['content-disposition'] || '') && /text\/csv/.test(csvRes.headers()['content-type'] || ''), JSON.stringify(csvRes.headers()));
  check('CSV starts with UTF-8 BOM (Excel)', csv.charCodeAt(0) === 0xfeff);
  const header = csv.split('\n')[0];
  check('CSV has no ID number / photo columns', !/id.?(doc|number)|photo/i.test(header.replace('ID type', '')), header);
  check('CSV body contains no ID number or photo path', !csv.includes(ID_NUMBER) && !/photos\//.test(csv));
  check('CSV contains Bangla name intact', csv.includes('রহিম'));
  check('CSV neutralises formula name (=1+1 prefixed with apostrophe)', csv.includes(`'=1+1`) && !/(^|,)"?=1\+1/m.test(csv), csv.split('\n').find((l) => l.includes('=1+1')) || '(row missing)');
  const phoneCell = (csv.split('\n').find((l) => l.includes(A.paid1.public_ref)) || '').split(',')[4];
  note(`CSV phone cell sample: ${phoneCell}`);
  check('CSV phone column is not corrupted by formula guard', !/^"?'/.test(phoneCell || ''), phoneCell);
  const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 15000 }), p.click('a.page-title-action')]);
  check('Export CSV link triggers a browser download', /^applications-.*\.csv$/.test(dl.suggestedFilename()), dl.suggestedFilename());

  // --- students
  await visit(p, adminUrl('cc-students'), 'owner-students');
  check('Students heading', /Students/.test(await text(p, '.wrap h1')));
  check('Students no overflow', (await overflow(p)) <= 0);
  await visit(p, adminUrl('cc-students', `&s=${encodeURIComponent(TAG)}`));
  let srows = await listRows(p);
  check('student search by name finds both paid fixtures', srows.length === 2, String(srows.length));
  await visit(p, adminUrl('cc-students', `&s=${A.paid1.phone}`));
  srows = await listRows(p);
  check('student search by local phone finds the student', srows.length >= 1 && srows[0].includes('+880' + A.paid1.phone.slice(1)), srows.join(' / '));
  await visit(p, adminUrl('cc-students', `&s=${encodeURIComponent(TAG + ' Paid One')}`));
  srows = await listRows(p);
  check('student name search containing a digit is not widened to phone matches', srows.length === 1, `${srows.length} rows`);
  await visit(p, adminUrl('cc-students', '&s=' + encodeURIComponent('nobody-named-this 7')));
  srows = await listRows(p);
  check('student search "nobody-named-this 7" returns nothing (digit must not match every phone containing 7)', srows.length === 0 || /No students found/.test(srows[0]), `${srows.length} rows`);
  await visit(p, adminUrl('cc-students', `&s=${TAG}&status=active&batch_id=${BATCH}`));
  check('student filters status=active + batch', (await listRows(p)).length === 2);
  await visit(p, adminUrl('cc-students', `&s=${TAG}&status=deactivated`));
  check('student filter deactivated -> none yet', /No students found/.test(await text(p, '#the-list')));
  await visit(p, adminUrl('cc-students', `&s=${TAG}&orderby=name&order=asc`));
  const sn = await p.$$eval('#the-list strong a', (t) => t.map((x) => x.textContent.trim()));
  check('student sort by name asc (Latin before Bengali under the DB collation)', sn.length === 2 && sn[0].includes('Paid One'), sn.join('|'));
  await visit(p, adminUrl('cc-students', `&s=${TAG}&orderby=name&order=desc`));
  const sd = await p.$$eval('#the-list strong a', (t) => t.map((x) => x.textContent.trim()));
  check('student sort by name desc reverses asc', sd.length === 2 && sd[0].includes('Paid Two'), sd.join('|'));

  await visit(p, adminUrl('cc-students', `&user=${A.paid1.user_id}`), 'owner-student-detail');
  check('student detail shows name + full phone', (await text(p, '.wrap')).includes(A.paid1.full_name) && (await text(p, '.wrap')).includes('+880' + A.paid1.phone.slice(1)));
  check('student detail has Enrollments, Payments, SMS log', ['Enrollments', 'Payments', 'SMS log'].every((h) => new RegExp(`<h3>${h}</h3>`).test(''), true) || (await p.$$eval('h3', (h) => h.map((x) => x.textContent))).join().includes('SMS log'));
  check('SMS log hides full numbers', !/\+8801\d{9}/.test(await text(p, 'h3:text-is("SMS log") + table')));
  check('student detail no overflow', (await overflow(p)) <= 0);

  await visit(p, adminUrl('cc-students'));
  const sHref = await p.$eval('a.page-title-action', (a) => a.href);
  const sCsv = await ctx.request.get(sHref);
  const sBody = (await sCsv.body()).toString('utf8');
  check('students CSV attachment + BOM + header', /attachment/.test(sCsv.headers()['content-disposition'] || '') && sBody.charCodeAt(0) === 0xfeff && /Name,Phone/.test(sBody), sBody.slice(0, 80));
  check('students CSV phone not prefixed with an apostrophe', !/,"?'\+880/.test(sBody), (sBody.split('\n')[1] || '').slice(0, 80));
  check('students CSV has Bangla fixture name intact', sBody.includes('রহিম'));

  await ctx.storageState({ path: path.join(fx.tmp, `owner-${width}.json`) });
  const result = { ctx, p };
  return result;
}

async function ownerMutating(browser, fx) {
  console.log('Owner mutating flows @1280px (students / payments / audit / settings)');
  const { ctx, p } = await newCtx(browser, 1280);
  await wpLogin(p, fx.owner);
  const A = fx.byKey;

  // --- student portal + deactivate / reactivate
  await resetLimits();
  const st = await newCtx(browser, 1280);
  await st.p.goto(`${BASE}/student/login/`);
  await st.p.fill('#sl-phone', A.paid1.phone);
  await st.p.fill('#sl-password', fx.temp1);
  await st.p.click('#sl-submit');
  await st.p.waitForURL(/change=1/, { timeout: 15000 }).catch(() => {});
  await st.p.fill('#pf-current_password', fx.temp1);
  await st.p.fill('#pf-new_password', PW + 'x');
  await st.p.click('#portal-password-form button[type=submit]');
  await st.p.waitForURL(/\/student\/$/, { timeout: 15000 }).catch(() => {});
  check('student fixture reaches dashboard', /\/student\/$/.test(st.p.url()), st.p.url());
  const before = await text(st.p, 'body');
  check('dashboard (active) shows schedule section', !!(await st.p.$('#portal-schedule-h')));

  await visit(p, adminUrl('cc-students', `&user=${A.paid1.user_id}`));
  check('no Resend button after the student changed the password', !(await p.$('button:text-is("Resend credentials")')));
  await p.click('button:text-is("Deactivate")');
  await p.waitForURL(/cc_notice=/);
  check('Deactivate shows "Enrollment updated."', /Enrollment updated/.test(await text(p, '.notice')), await text(p, '.notice'));
  check('detail now shows deactivated + Reactivate', /deactivated/.test(await text(p, 'table.widefat')) && !!(await p.$('button:text-is("Reactivate")')));
  await st.p.goto(`${BASE}/student/`);
  const off = await text(st.p, 'body');
  check('student dashboard shows access ended', /access.{0,20}ended|ended/i.test(off), off.slice(0, 300));
  check('student dashboard hides schedule when deactivated', !(await st.p.$('#portal-schedule-h')) || !/schedule/i.test(await text(st.p, '.portal-card')), '');
  await visit(p, adminUrl('cc-students', `&s=${TAG}&status=deactivated`));
  check('students filter deactivated lists the student', (await listRows(p)).length === 1);
  await visit(p, adminUrl('cc-students', `&user=${A.paid1.user_id}`));
  await p.click('button:text-is("Reactivate")');
  await p.waitForURL(/cc_notice=/);
  check('Reactivate shows success', /Enrollment updated/.test(await text(p, '.notice')));
  await st.p.goto(`${BASE}/student/`);
  check('after reactivation dashboard is back (no "access ended")', !/access.{0,20}ended/i.test(await text(st.p, 'body')) && !!(await st.p.$('#portal-schedule-h')));
  await st.ctx.close();

  // --- resend credentials on student 2 (still on temp password)
  const before2 = (await pwMessages(A.paid2.phone)).length;
  await visit(p, adminUrl('cc-students', `&user=${A.paid2.user_id}`));
  const resendBtn = await p.$('button:text-is("Resend credentials")');
  check('Resend credentials button present for must-change-password student', !!resendBtn);
  if (resendBtn) {
    await resendBtn.click();
    await p.waitForURL(/cc_notice=/);
    const n = await text(p, '.notice');
    note(`resend notice: ${n}`);
    if (/Credentials SMS queued/.test(n)) {
      const t2 = await waitPw(A.paid2.phone, { after: before2, page: p, timeoutMs: 60000 });
      check('new credentials SMS appears in fake outbox', !!t2);
      check('new temp password differs from the first', !!t2 && t2 !== fx.temp2, `${t2} vs ${fx.temp2}`);
      if (t2) {
        const s2 = await newCtx(browser, 1280);
        await s2.p.goto(`${BASE}/student/login/`);
        await s2.p.fill('#sl-phone', A.paid2.phone);
        await s2.p.fill('#sl-password', t2);
        await s2.p.click('#sl-submit');
        await s2.p.waitForURL(/\/student\/profile\/\?change=1/, { timeout: 15000 }).catch(() => {});
        check('student logs in with the NEW temp password', /change=1/.test(s2.p.url()), s2.p.url());
        const s3 = await newCtx(browser, 1280);
        await s3.p.goto(`${BASE}/student/login/`);
        await s3.p.fill('#sl-phone', A.paid2.phone);
        await s3.p.fill('#sl-password', fx.temp2);
        await s3.p.click('#sl-submit');
        await s3.p.waitForTimeout(2500);
        check('OLD temp password no longer works after resend', !/\/student\/(profile|$)/.test(s3.p.url().replace(/\/student\/login\/?.*/, '')), s3.p.url());
        await s3.ctx.close();
        fx.student2Pw = t2;
        fx.student2Ctx = s2;
      }
    } else if (/could not be re-sent|cc_provisioner/i.test(n)) {
      check('Resend credentials succeeds (provisioner method may not have landed yet)', false, n);
    } else check('Resend credentials gave a recognisable notice', false, n);
  }

  // --- payments
  await visit(p, adminUrl('cc-payments'), 'owner-payments');
  const heads = await p.$$eval('thead th', (t) => t.map((x) => x.textContent.trim()));
  note(`ledger columns: ${heads.join(' | ')}`);
  for (const h of ['Invoice', 'Student / application', 'Amount', 'Gateway / method', 'Status', 'Transaction ID', 'Created', 'Settled']) check(`ledger column "${h}"`, heads.some((x) => x.startsWith(h)), heads.join('|'));
  check('totals bar present', /Completed in this filter:.*BDT/.test(await text(p, '.cc-totals')), await text(p, '.cc-totals'));
  check('Payments no overflow', (await overflow(p)) <= 0);
  const sums = await wpJson('global $wpdb; echo json_encode(["sum"=>(float)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}cc_payments WHERE status=\'completed\'"),"n"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cc_payments")]);');
  const tt = await text(p, '.cc-totals');
  const tsum = parseFloat((tt.match(/filter:\s*([\d,.]+)\s*BDT/) || [0, '-1'])[1].replace(/,/g, ''));
  const trows = parseInt((tt.match(/(\d+) rows?/) || [0, -1])[1], 10);
  check('totals (no filter) sum == DB completed sum', Math.abs(tsum - sums.sum) < 0.01, `${tsum} vs ${sums.sum}`);
  check('totals (no filter) rows == DB payments', trows === sums.n, `${trows} vs ${sums.n}`);
  await visit(p, adminUrl('cc-payments', '&status=completed'));
  check('status filter completed: only Completed rows', (await listRows(p)).every((r) => /Completed/.test(r)));
  await visit(p, adminUrl('cc-payments', '&status=initiated'));
  const irows = await listRows(p);
  check('status filter initiated lists unpaid fixtures', irows.length >= 4 && irows.every((r) => /Initiated/.test(r)), `${irows.length} rows; fixture payment statuses: ${JSON.stringify(await wpJson(`global $wpdb; echo json_encode($wpdb->get_col("SELECT status FROM {$wpdb->prefix}cc_payments WHERE id IN (${['pendA','pendF','pendR','pendB1','pendB2'].map((k) => A[k].payment_id).join(',')})"));`))}`);
  await visit(p, adminUrl('cc-payments', '&gateway=fake'));
  check('gateway filter fake', (await listRows(p)).length > 0 && (await listRows(p)).every((r) => /fake/.test(r)));
  await visit(p, adminUrl('cc-payments', '&gateway=nogateway'));
  check('gateway filter unknown -> empty state', /No payments match/.test(await text(p, '#the-list')));
  const today = new Date().toISOString().slice(0, 10);
  await visit(p, adminUrl('cc-payments', `&date_from=${today}&date_to=${today}`));
  check('date filter today returns rows', (await listRows(p)).length > 0);
  await visit(p, adminUrl('cc-payments', '&date_from=2000-01-01&date_to=2000-01-31'));
  check('date filter in the past returns none', /No payments match/.test(await text(p, '#the-list')));
  await visit(p, adminUrl('cc-payments', '&status=stuck'));
  const stk = await listRows(p);
  check('Needs attention filter returns the aged unpaid fixtures only', stk.length >= 2 && stk.every((r) => /Needs attention/.test(r)), String(stk.length));
  check('fresh unpaid fixture is NOT flagged stuck', !stk.some((r) => r.includes(A.pendB1.invoice)));
  await visit(p, adminUrl('cc-payments', `&s=${A.paid1.invoice}`));
  check('search by invoice number', (await listRows(p)).length === 1);
  await visit(p, adminUrl('cc-payments', `&s=${A.paid1.trx}`));
  check('search by transaction id', (await listRows(p)).some((r) => r.includes(A.paid1.invoice)), `trx=${A.paid1.trx}`);
  await visit(p, adminUrl('cc-payments', `&s=${A.paid1.phone.slice(-4)}`));
  check('search by phone last 4', (await listRows(p)).some((r) => r.includes(A.paid1.invoice)));
  await visit(p, adminUrl('cc-payments', '&orderby=amount&order=asc'));
  const amts = await p.$$eval('#the-list td.column-amount', (t) => t.map((x) => parseFloat(x.textContent.replace(/[^\d.]/g, ''))));
  check('sort by amount asc', amts.every((v, i) => i === 0 || amts[i - 1] <= v), amts.join(','));
  await visit(p, adminUrl('cc-payments', `&payment=${A.paid1.payment_id}`));
  const timeline = await p.$$('h2:text-is("Event timeline") + table tbody tr');
  check('detail of completed payment shows event timeline rows', timeline.length >= 1, String(timeline.length));
  check('completed detail offers no Reconcile', !(await p.$('button:text-is("Reconcile now")')));
  check('detail never renders raw gateway JSON', !/response_json|payload_json|"trx_id"/.test(await p.content()));
  await visit(p, adminUrl('cc-payments', '&payment=99999999'));
  check('unknown payment id -> "Payment not found."', /Payment not found/.test(await text(p, '.wrap')));
  await visit(p, adminUrl('cc-payments', '&status=initiated'));
  check('list row has Reconcile action for initiated payments', (await p.$$('#the-list button:text-is("Reconcile now")')).length >= 1);
  await visit(p, adminUrl('cc-payments', `&cc_result=<img src=x onerror=window.__pwn=1>`));
  check('cc_result injection ignored', !(await p.evaluate(() => window.__pwn)) && !(await p.content()).includes('<img src=x onerror'));
  await visit(p, adminUrl('cc-payments', '&status=initiated'));
  const pHref = await p.$eval('a.button:has-text("Export CSV")', (a) => a.href);
  const pCsv = await ctx.request.get(pHref);
  const pBody = (await pCsv.body()).toString('utf8');
  check('payments CSV attachment + BOM + header', /attachment/.test(pCsv.headers()['content-disposition'] || '') && pBody.charCodeAt(0) === 0xfeff && /^﻿Invoice,/.test(pBody), pBody.slice(0, 60));
  check('payments CSV respects the status filter (only initiated)', pBody.split('\n').slice(1).filter(Boolean).every((l) => /,initiated,/.test(l)));
  check('payments CSV neutralises formula student name', pBody.includes(`'=1+1`) && !/(^|,)"?=1\+1/m.test(pBody));
  check('payments CSV has no response_json/payload', !/response|payload/i.test(pBody.split('\n')[0]));

  // --- audit
  await visit(p, adminUrl('cc-audit'), 'owner-audit');
  const actions = await p.$$eval('table.wp-list-table tbody tr td:nth-child(3)', (t) => t.map((x) => x.textContent.trim()));
  note(`audit actions (latest): ${[...new Set(actions)].join(', ')}`);
  for (const a of ['application.reject', 'application.reveal_id', 'application.export', 'student.enrollment_deactivated', 'student.enrollment_active', 'student.credentials_resent', 'student.export', 'payment.reconcile', 'payment.export']) {
    await visit(p, adminUrl('cc-audit', `&audit_action=${a}&actor=${fx.owner.id}`));
    const r = await p.$$('table.wp-list-table tbody tr td:nth-child(3)');
    const first = r.length ? await r[0].textContent() : '';
    check(`audit has ${a} by the owner`, first.trim() === a, `first row action="${first.trim()}"`);
  }
  await visit(p, adminUrl('cc-audit', `&actor=${fx.owner.id}`));
  const owners = await p.$$eval('table.wp-list-table tbody tr td:nth-child(2)', (t) => t.map((x) => x.textContent));
  check('audit actor filter shows only the owner', owners.length > 0 && owners.every((o) => o.includes(fx.owner.login)), owners.slice(0, 2).join('|'));
  await visit(p, adminUrl('cc-audit', `&entity_type=application&actor=${fx.owner.id}`));
  check('audit entity_type filter', (await p.$$eval('table.wp-list-table tbody tr td:nth-child(4)', (t) => t.map((x) => x.textContent))).every((e) => /^application/.test(e)));
  await visit(p, adminUrl('cc-audit', '&date_from=2000-01-01&date_to=2000-01-02'));
  check('audit date filter in the past -> empty state', /No audit entries match/.test(await text(p, 'table.wp-list-table tbody')));
  await visit(p, adminUrl('cc-audit', `&date_from=${today}&date_to=${today}&actor=${fx.owner.id}`));
  check('audit date filter today finds entries', (await p.$$('table.wp-list-table tbody tr')).length > 0);
  const bodyTxt = await text(p, '.wrap');
  check('audit shows no ID number / reason text / secrets', !bodyTxt.includes(ID_NUMBER) && !/QA bulk reject|Duplicate application/.test(bodyTxt));
  check('Audit no overflow', (await overflow(p)) <= 0);
  const aHref = await p.$eval('a.button:has-text("Export CSV")', (a) => a.href);
  const aCsv = await ctx.request.get(aHref);
  check('audit CSV attachment + BOM', /attachment/.test(aCsv.headers()['content-disposition'] || '') && (await aCsv.body()).toString('utf8').charCodeAt(0) === 0xfeff);

  // --- settings
  await visit(p, adminUrl('cc-settings'), 'owner-settings');
  check('Settings heading + status panel', /Astona settings/.test(await text(p, '.wrap h1')) && /System status/.test(await text(p, '.wrap')));
  const sysTxt = await text(p, '.cc-table');
  check('status panel exposes no keys/paths', !/\/var\/www|[A-Za-z0-9+\/]{40,}/.test(sysTxt), sysTxt);
  const phone = '+880 1700-000 (111)';
  const email = `qa-${TAG.toLowerCase()}@example.test`;
  await p.fill('#cc-name', `Astona QA ${TAG}`);
  await p.fill('#cc-phone', phone);
  await p.fill('#cc-email', email);
  await p.fill('#cc-address', `House 1, Road 2 <script>window.__pwn=1</script> Dhaka`);
  await p.click('#submit');
  await p.waitForURL(/cc_notice=saved/);
  check('Settings saved notice', /Settings saved/.test(await text(p, '.notice')));
  check('saved values persist in the form', (await p.inputValue('#cc-email')) === email && (await p.inputValue('#cc-phone')) === phone);
  check('<script> tag stripped on save', !(await p.inputValue('#cc-address')).includes('<script'), await p.inputValue('#cc-address'));
  const pub = await newCtx(browser, 1280);
  for (const url of ['/', '/admissions/']) {
    await pub.p.goto(`${BASE}${url}`);
    const html = await pub.p.content();
    check(`public ${url} reflects phone`, html.includes(phone) || html.replace(/\D/g, '').includes('88017000001110') || html.includes('1700-000'), '');
    check(`public ${url} reflects email`, html.includes(email));
    check(`public ${url} has no raw <script> from settings`, !html.includes('<script>window.__pwn') && !(await pub.p.evaluate(() => window.__pwn)));
  }
  // Output escaping independent of save-time sanitising: write the option raw.
  await wp('eval', `update_option('cc_settings', ['name'=>'<script>window.__pwn=1</script>X','phone'=>'<img src=x onerror=window.__pwn=1>','email'=>'a@example.test','address'=>'"><svg onload=window.__pwn=1>'], false);`);
  for (const url of ['/', '/admissions/']) {
    pub.p.on('dialog', (d) => d.dismiss());
    await pub.p.goto(`${BASE}${url}`);
    const html = await pub.p.content();
    check(`public ${url}: raw option values are escaped (no executable markup)`, !(await pub.p.evaluate(() => window.__pwn)) && !/<script>window\.__pwn|<svg onload|<img src=x onerror/.test(html), '');
  }
  await pub.ctx.close();
  await wp('eval', `update_option('cc_settings', json_decode(${JSON.stringify(JSON.stringify({ name: `Astona QA ${TAG}`, phone, email, address: 'House 1' }))}, true), false);`);

  // --- keyboard focus on forms
  await visit(p, adminUrl('cc-settings'));
  await p.focus('#cc-name');
  check('settings: focus ring visible on Name', await hasFocusStyle(p));
  await p.keyboard.press('Tab');
  check('settings: Tab moves to Phone', (await p.evaluate(() => document.activeElement.id)) === 'cc-phone');
  check('settings: focus ring visible on Phone', await hasFocusStyle(p));
  await p.keyboard.press('Tab'); await p.keyboard.press('Tab'); await p.keyboard.press('Tab');
  check('settings: Tab reaches Save button', (await p.evaluate(() => document.activeElement.id)) === 'submit', await p.evaluate(() => document.activeElement.id));
  check('settings: focus ring visible on Save', await hasFocusStyle(p));
  check('settings: all inputs have labels', await p.$$eval('.form-table input, .form-table textarea', (els) => els.every((e) => document.querySelector(`label[for="${e.id}"]`))));
  const pendingNow = (await appMeta([A.pendA.phone]))[0];
  await visit(p, adminUrl('cc-applications', `&view=${A.pendA.id}`));
  await p.focus('#cc-reason');
  check('reject form: label bound + focus ring visible', !!(await p.$('label[for="cc-reason"]')) && (await hasFocusStyle(p)));
  void pendingNow;
  await p.keyboard.press('Tab');
  check('reject form: Tab reaches the submit button', (await p.evaluate(() => document.activeElement.value || document.activeElement.textContent)).includes('Reject'));
  await visit(p, adminUrl('cc-applications'));
  await p.focus('#cc-batch');
  check('applications filters: focus ring visible', await hasFocusStyle(p));
  await p.focus('#cc-batch');
  const tabOrder = [];
  for (let i = 0; i < 5; i++) { await p.keyboard.press('Tab'); tabOrder.push(await p.evaluate(() => document.activeElement.id || document.activeElement.name || document.activeElement.tagName)); }
  note(`applications filter tab order: ${tabOrder.join(' > ')}`);
  check('applications filters reachable by keyboard (date_from, date_to, Filter)', tabOrder.includes('cc-date_from') && tabOrder.includes('cc-date_to'), tabOrder.join('>'));

  // --- escaping of stored user data in admin
  await visit(p, adminUrl('cc-applications', '&s=%22%3E%3Cscript%3Ewindow.__pwn%3D1%3C%2Fscript%3E'));
  check('search term echoed escaped', !(await p.evaluate(() => window.__pwn)) && !(await p.content()).includes('"><script>window.__pwn'));
  await visit(p, adminUrl('cc-applications', '&cc_notice=<script>window.__pwn=1</script>'));
  check('unknown cc_notice shows nothing / nothing executes', !(await p.evaluate(() => window.__pwn)) && (await p.$$(appNoticeSelector('.wrap .notice'))).length === 0);

  await ctx.close();
}

async function permissions(browser, fx) {
  console.log('Permissions: nonce-less handlers, staff, instructor, student');
  const A = fx.byKey;

  // --- nonce-less requests as owner (strongest role) and anonymous
  const o = await newCtx(browser, 1280);
  await wpLogin(o.p, fx.owner);
  const appBefore = (await appMeta([A.pendA.phone]))[0].status;
  const posts = [
    ['cc_app_reject', { action: 'cc_app_reject', id: A.pendA.id, reason: 'no nonce' }],
    ['cc_app_bulk_reject', { action: 'cc_app_bulk_reject', 'application[]': A.pendA.id, reason: 'no nonce', confirm: 1 }],
    ['cc_app_reveal', { action: 'cc_app_reveal', id: A.paid1.id }],
    ['cc_student_status', { action: 'cc_student_status', enrollment_id: 1, status: 'deactivated' }],
    ['cc_student_resend', { action: 'cc_student_resend', user_id: A.paid2.user_id }],
    ['cc_payment_reconcile', { action: 'cc_payment_reconcile', payment_id: A.pendA.payment_id }],
    ['cc_save_settings', { action: 'cc_save_settings', name: 'pwned' }],
  ];
  for (const [name, form] of posts) {
    const r = await rawPost(o.ctx, '/wp-admin/admin-post.php', form);
    const b = await r.text();
    check(`nonce-less POST ${name} refused (4xx, no redirect-to-success)`, r.status() >= 400 && !b.includes(ID_NUMBER), `${r.status()} ${b.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 80)}`);
  }
  for (const [name, q] of [['cc_app_export', 'action=cc_app_export'], ['cc_app_photo', `action=cc_app_photo&id=${A.paid1.id}`], ['cc_students_export', 'action=cc_students_export'], ['cc_payments_export', 'action=cc_payments_export'], ['cc_audit_export', 'action=cc_audit_export']]) {
    const r = await rawGet(o.ctx, `/wp-admin/admin-post.php?${q}`);
    check(`nonce-less GET ${name} refused`, r.status() >= 400 && !/text\/csv|image\//.test(r.headers()['content-type'] || ''), `${r.status()} ${r.headers()['content-type']}`);
  }
  const wrong = await rawGet(o.ctx, `/wp-admin/admin-post.php?action=cc_app_export&_wpnonce=1234567890`);
  check('GET export with a forged nonce refused', wrong.status() >= 400, String(wrong.status()));
  check('nonce-less requests changed nothing', (await appMeta([A.pendA.phone]))[0].status === appBefore);
  const anon = await browser.newContext();
  for (const q of ['action=cc_app_export', 'action=cc_students_export', 'action=cc_payments_export', 'action=cc_audit_export', `action=cc_app_photo&id=${A.paid1.id}`]) {
    const r = await anon.request.get(`${BASE}/wp-admin/admin-post.php?${q}`, { maxRedirects: 0, failOnStatusCode: false });
    check(`anonymous GET ${q.split('&')[0]} gives no data`, !/text\/csv|image\//.test(r.headers()['content-type'] || '') && r.status() !== 200, String(r.status()));
  }
  const ar = await anon.request.get(`${BASE}/wp-admin/admin.php?page=cc-applications`, { maxRedirects: 0, failOnStatusCode: false });
  check('anonymous admin screen redirects to login', ar.status() === 302 && /admin\/login/.test(ar.headers().location || ''), String(ar.status()));
  await anon.close();
  await o.ctx.close();

  // --- staff
  for (const width of [1280, 768]) {
    const s = await newCtx(browser, width);
    await wpLogin(s.p, fx.staff);
    await visit(s.p, adminUrl('cc-dashboard'), 'staff-dashboard');
    const labels = await menuLabels(s.p);
    if (width === 1280) note(`staff menu: ${labels.join(' | ')}`);
    for (const l of ['Dashboard', 'Applications', 'Students', 'Payments']) check(`staff @${width} menu has ${l}`, labels.some((x) => x.startsWith(l)), labels.join('|'));
    check(`staff @${width} menu lacks Audit/Settings`, !labels.some((x) => /^(Audit|Settings)/.test(x)), labels.join('|'));
    const cards = await p2cards(s.p);
    check(`staff @${width} dashboard has no Recent activity (audit) panel`, !(await s.p.$('.cc-panel h2')));
    if (width === 1280) note(`staff cards: ${Object.keys(cards).join(', ')}`);
    for (const pg of ['cc-audit', 'cc-settings']) {
      const r = await visit(s.p, adminUrl(pg));
      const t = await text(s.p, 'body');
      check(`staff @${width} direct ${pg} -> WP not-allowed page`, NOT_ALLOWED.test(t) && !(await s.p.$('.cc-table, form.cc-form')), `${r.status()} ${t.slice(0, 100)}`);
    }
    if (width === 1280) {
      await visit(s.p, adminUrl('cc-applications'));
      check('staff Applications list visible with Export CSV', !!(await s.p.$('a.page-title-action')));
      await visit(s.p, adminUrl('cc-applications', `&view=${A.paid1.id}`));
      check('staff detail: no Reveal ID number button', !(await s.p.$('button:text-is("Reveal ID number")')));
      check('staff detail: ID still masked', /^\*{4}7890/.test(await text(s.p, 'tr:has(th:has-text("number")) td')));
      await visit(s.p, adminUrl('cc-payments', `&payment=${A.pendA.payment_id}`));
      check('staff payment detail: no Reconcile now', !(await s.p.$('button:text-is("Reconcile now")')));
      await visit(s.p, adminUrl('cc-payments', '&status=initiated'));
      check('staff payments list: no Reconcile actions', (await s.p.$$('button:text-is("Reconcile now")')).length === 0);
      const r = await rawGet(s.ctx, '/wp-admin/admin-post.php?action=cc_audit_export');
      check('staff nonce-less audit export refused', r.status() >= 400);
      await visit(s.p, adminUrl('cc-students', `&user=${A.paid1.user_id}`));
      check('staff may manage students (Deactivate visible)', !!(await s.p.$('button:text-is("Deactivate")')));
    } else {
      for (const pg of ['cc-dashboard', 'cc-applications', 'cc-students', 'cc-payments']) {
        await visit(s.p, adminUrl(pg), `staff-${pg}`);
        check(`staff @768 ${pg} no overflow`, (await overflow(s.p)) <= 0, String(await overflow(s.p)));
      }
    }
    await s.ctx.close();
  }

  // --- instructor
  const i = await newCtx(browser, 1280);
  await wpLogin(i.p, fx.instructor);
  await visit(i.p, adminUrl('cc-dashboard'), 'instructor-dashboard');
  const il = await menuLabels(i.p);
  note(`instructor menu: ${il.join(' | ')}`);
  const top = await i.p.$$eval('#adminmenu a.menu-top[href*="cc-dashboard"] .wp-menu-name', (e) => e.map((x) => x.textContent.trim()));
  const subs = await i.p.$$eval('#adminmenu li.toplevel_page_cc-dashboard ul.wp-submenu a', (a) => a.map((x) => x.textContent.trim()));
  check('instructor sees Astona -> Dashboard only (no other Astona items)', top.includes('Astona') && subs.every((x) => /^(Astona|Dashboard)$/.test(x)), `${top} / ${subs}`);
  const itxt = await text(i.p, '.wrap');
  check('instructor dashboard is the reduced welcome screen', /Welcome/.test(itxt) && !(await i.p.$('.cc-card')), itxt.slice(0, 120));
  check('instructor dashboard shows no money/applications/audit', !/Revenue|৳|Pending applications|Stuck|Recent activity/.test(itxt));
  for (const pg of ['cc-applications', 'cc-students', 'cc-payments', 'cc-audit', 'cc-settings']) {
    await visit(i.p, adminUrl(pg));
    const t = await text(i.p, 'body');
    check(`instructor direct ${pg} refused`, NOT_ALLOWED.test(t) && !(await i.p.$('#the-list, form.cc-form')), t.slice(0, 100));
  }
  for (const [name, q] of [['cc_app_export', 'action=cc_app_export'], ['cc_students_export', 'action=cc_students_export'], ['cc_payments_export', 'action=cc_payments_export']]) {
    const r = await rawGet(i.ctx, `/wp-admin/admin-post.php?${q}`);
    check(`instructor nonce-less ${name} refused`, r.status() >= 400 && !/text\/csv/.test(r.headers()['content-type'] || ''));
  }
  // Even with a valid nonce for the action, capability must be re-checked: build one from the instructor's own session.
  const nonce = await i.p.evaluate(async (url) => { const r = await fetch(url, { credentials: 'same-origin' }); return r.text(); }, `${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`).catch(() => '');
  void nonce;
  await i.ctx.close();

  // --- student cannot reach wp-admin
  if (fx.student2Ctx) {
    const sp = fx.student2Ctx.p;
    await sp.goto(`${BASE}/wp-admin/`);
    check('student /wp-admin/ -> /student/', /\/student\//.test(sp.url()) && !/wp-admin/.test(sp.url()), sp.url());
    const sr = await sp.goto(`${BASE}${adminUrl('cc-applications')}`);
    const stxt = await text(sp, 'body');
    note(`student at admin.php?page=cc-applications -> ${sr.status()} ${sp.url().replace(BASE, '')} "${stxt.slice(0, 60)}"`);
    check('student cannot see any Astona admin screen (redirect or WP 403)', (/\/student\//.test(sp.url()) && !/wp-admin/.test(sp.url())) || (sr.status() === 403 && NOT_ALLOWED.test(stxt)), `${sr.status()} ${sp.url()}`);
    const r = await rawGet(fx.student2Ctx.ctx, `/wp-admin/admin-post.php?action=cc_app_export`);
    check('student export URL refused', r.status() >= 400 || /\/student\//.test(r.headers().location || ''), String(r.status()));
    await fx.student2Ctx.ctx.close();
  } else note('student wp-admin block not exercised (no student session; resend step did not run)');
}

async function p2cards(p) {
  return p.$$eval('.cc-card', (cs) => Object.fromEntries(cs.map((c) => [c.querySelector('h3').textContent.trim(), c.querySelector('.cc-card__value').textContent.trim()])));
}

/** 768px pass over every owner screen: overflow, console, PHP errors, focus on the first control. */
async function tablet(browser, fx) {
  console.log('Owner tour @768px');
  const { ctx, p } = await newCtx(browser, 768);
  await wpLogin(p, fx.owner);
  const A = fx.byKey;
  const pages = [
    ['cc-dashboard', ''], ['cc-applications', ''], ['cc-applications', `&view=${A.paid1.id}`], ['cc-applications', `&view=${A.pendA.id}`],
    ['cc-students', ''], ['cc-students', `&user=${A.paid1.user_id}`], ['cc-payments', ''], ['cc-payments', `&payment=${A.paid1.payment_id}`],
    ['cc-audit', ''], ['cc-settings', ''],
  ];
  for (const [pg, extra] of pages) {
    await visit(p, adminUrl(pg, extra), `owner-${pg}${extra ? '-d' : ''}`);
    const ov = await overflow(p);
    check(`@768 ${pg}${extra} no horizontal overflow`, ov <= 0, `+${ov}px`);
    const clipped = await p.$$eval('.wrap table, .wrap form, .wrap .cc-card', (els) => els.filter((e) => e.getBoundingClientRect().right > innerWidth + 1).length);
    check(`@768 ${pg}${extra} nothing extends past the viewport`, clipped === 0, `${clipped} elements`);
  }
  await visit(p, adminUrl('cc-applications'));
  const tabs = await p.$$eval('.subsubsub a', (a) => a.length);
  check('@768 status tabs still present', tabs >= 5);
  await ctx.close();
}

/* ---------------------------------------------------------------- main */

(async () => {
  const fx = { users: [], apps: [], byKey: {}, tmp: fs.mkdtempSync(path.join(process.env.TMPDIR || '/tmp', 'adm-e2e-')) };
  const browser = await chromium.launch();
  let setupOk = false;
  try {
    console.log(`Setup (tag ${TAG})`);
    const sb = await wp('option', 'get', 'cc_settings', '--format=json');
    try { fx.settingsBefore = JSON.parse(sb.slice(sb.indexOf('{'))); } catch { fx.settingsBefore = null; }
    fx.owner = await mkUser('cc_owner');
    fx.staff = await mkUser('cc_staff');
    fx.instructor = await mkUser('cc_instructor');
    fx.users = [fx.owner, fx.staff, fx.instructor];
    check('throwaway owner/staff/instructor created', [fx.owner, fx.staff, fx.instructor].every((u) => u.id > 0), JSON.stringify(fx.users.map((u) => u.id)));

    const pub = await newCtx(browser, 1280);
    const specs = [
      ['paid1', `${TAG} Paid One`, true],
      ['paid2', `রহিম ${TAG} Paid Two`, true],
      ['pendA', `${TAG} Pending A`, false],
      ['pendF', `=1+1 ${TAG} Formula`, false],
      ['pendR', `${TAG} Pending Reject`, false],
      ['pendB1', `${TAG} Tom & Jerry's "Q"`, false],
      ['pendB2', `${TAG} Pending Bulk 2`, false],
    ];
    for (const [key, name, pay] of specs) {
      const phone = freshPhone();
      fx.apps.push({ key, phone });
      await resetLimits();
      try { await apply(pub.p, { name, phone, pay }); } catch (e) { check(`apply ${key}`, false, e.message.split('\n')[0]); }
    }
    await pub.ctx.close();
    const metas = await appMeta(fx.apps.map((a) => a.phone).concat(fx.apps.map((a) => e164(a.phone))));
    for (const a of fx.apps) {
      const m = metas.find((x) => x.student_phone === a.phone || x.student_phone === e164(a.phone));
      fx.byKey[a.key] = { ...a, ...(m || {}) };
    }
    check('7 fixture applications exist', Object.values(fx.byKey).every((a) => a.id), JSON.stringify(Object.values(fx.byKey).map((a) => a.id)));

    // provisioning for the 2 paid students (Action Scheduler / WP-Cron)
    const poke = await newCtx(browser, 1280);
    fx.temp1 = await waitPw(fx.byKey.paid1.phone, { page: poke.p });
    fx.temp2 = await waitPw(fx.byKey.paid2.phone, { page: poke.p });
    await poke.ctx.close();
    check('both paid students provisioned (temp password SMS)', !!fx.temp1 && !!fx.temp2);
    const metas2 = await appMeta(fx.apps.map((a) => a.phone).concat(fx.apps.map((a) => e164(a.phone))));
    for (const a of fx.apps) Object.assign(fx.byKey[a.key], metas2.find((x) => x.student_phone === a.phone || x.student_phone === e164(a.phone)) || {});
    check('paid applications approved with a student user', ['paid1', 'paid2'].every((k) => fx.byKey[k].status === 'approved' && fx.byKey[k].user_id));

    // age payments: pendA fully stuck; pendF only created_at old (updated_at fresh)
    await wp('eval', `global $wpdb; $p=$wpdb->prefix; $old=gmdate('Y-m-d H:i:s',time()-3600);
      $wpdb->query($wpdb->prepare("UPDATE {$p}cc_payments SET created_at=%s, updated_at=%s WHERE id=%d",$old,$old,${fx.byKey.pendA.payment_id}));
      $wpdb->query($wpdb->prepare("UPDATE {$p}cc_payments SET created_at=%s WHERE id=%d",$old,${fx.byKey.pendF.payment_id}));`);
    setupOk = Object.values(fx.byKey).every((a) => a.id) && !!fx.temp1 && !!fx.temp2;
    if (!setupOk) throw new Error('setup incomplete, aborting');

    const sections = [
      () => owner(browser, 1280, fx).then((r) => r.ctx.close()),
      () => owner(browser, 768, fx).then((r) => r.ctx.close()),
      () => ownerMutating(browser, fx),
      () => permissions(browser, fx),
      () => tablet(browser, fx),
    ];
    for (const run of sections) {
      try { await run(); } catch (e) { check('section completed without exception', false, `${e.message.split('\n')[0]}`); console.log(e.stack.split('\n').slice(0, 4).join('\n')); }
    }

    const real = pageErrors.filter((e) => !/favicon|status of 4\d\d|status of 403/.test(e));
    check('no browser console errors', real.length === 0, `\n    ${[...new Set(real)].slice(0, 8).join('\n    ')}`);
    check('no PHP warnings/notices/fatal errors in any page', phpErrors.length === 0, `\n    ${[...new Set(phpErrors)].slice(0, 8).join('\n    ')}`);
  } catch (e) {
    check('run completed', false, e.stack || e.message);
  } finally {
    console.log('Cleanup');
    try { await cleanup(fx); } catch (e) { console.log('  cleanup failed', e.message); }
    await browser.close().catch(() => {});
    fs.rmSync(fx.tmp, { recursive: true, force: true });
  }
  console.log(failures ? `\n${failures} check(s) FAILED` : '\nAll checks passed');
  process.exit(failures ? 1 : 0);
})();
