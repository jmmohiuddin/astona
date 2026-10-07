#!/usr/bin/env node
/**
 * Sub-project 6 (v1 content) browser e2e: Astona > Media, Blog, Gallery, Results, Contact + Inquiries, branch editing,
 * role gating. Headless Chromium at 1280 / 768 / 375 px. Not run as part of any CI: dev stack only.
 *
 * Run from the repo root (Node 18+, docker compose dev stack on BASE_URL, Playwright + Chromium):
 *   npm i playwright && npx playwright install chromium     # once, in any dir on the module path
 *   BASE_URL=http://localhost:8080 node tests/e2e/v1-browser.mjs
 * Playwright is resolved from this file first, then from the current working directory.
 * Optional: SHOT_DIR=/some/dir saves screenshots; WPCLI="docker compose run --rm -T wpcli" overrides the wp-cli runner.
 *
 * Fixtures (throwaway, tagged QAV1<timestamp>, removed in a finally block): wp users (owner, 2 staff, instructor,
 * student), uploaded images, articles, gallery items + categories, results, a branch, inquiries.
 * The contact rate limit (3/hour/IP) is NOT asserted: CC_RATE_LIMIT_DISABLED=1 disables it on the dev stack.
 * Exit code 0 = all checks passed, 1 = any failure.
 */
import { createRequire } from 'node:module';
import { appNoticeSelector } from './lib/app-notice.mjs';
import { execFile } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
import zlib from 'node:zlib';
import crypto from 'node:crypto';

let chromium;
try { ({ chromium } = await import('playwright')); }
catch { ({ chromium } = createRequire(process.cwd() + '/')('playwright')); }

const BASE = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const SHOT_DIR = process.env.SHOT_DIR || '';
const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const WPCLI = (process.env.WPCLI || 'docker compose run --rm -T wpcli').split(' ');
const TAG = 'QAV1' + Date.now().toString(36).replace(/\d/g, (d) => 'ghijklmnop'[d]).toUpperCase();
const LOW = TAG.toLowerCase();
const PW = 'V1-Qa-' + Math.random().toString(36).slice(2, 10) + '-9!';
const BANGLA_TITLE = `ভর্তি পরীক্ষার প্রস্তুতি ${TAG}`;
const MB = 1048576;

let failures = 0;
const check = (name, cond, extra = '') => {
  console.log(`${cond ? '  ok  ' : '  FAIL'} ${name}${cond ? '' : ' :: ' + String(extra).slice(0, 300)}`);
  if (!cond) failures++;
};
const note = (s) => console.log(`  note ${s}`);
const section = (s) => console.log(`\n== ${s}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const freshPhone = () => '017' + String(Date.now() + Math.floor(Math.random() * 100000)).slice(-8);

const wp = (...args) => new Promise((resolve) => {
  execFile(WPCLI[0], [...WPCLI.slice(1), ...args], { cwd: REPO, maxBuffer: 20e6 }, (err, stdout) => resolve(err ? '' : stdout));
});
async function wpJson(php) {
  const out = await wp('eval', php);
  const m = out.match(/(\{.*\}|\[.*\])\s*$/s);
  try { return JSON.parse((m ? m[m.length - 1] : out).trim()); } catch { return null; }
}
const q = (s) => JSON.stringify(String(s)); // PHP-safe string literal (JSON strings are valid PHP double-quoted without $ or \ surprises below)
const phpStr = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

/* ---------------------------------------------------------------- binary fixtures */

const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
const crc32 = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type), data]);
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(body));
  return Buffer.concat([len, body, crc]);
}
/** A real PNG. noise=true fills with random bytes and stores them uncompressed (used for the >5 MB case). */
function png(w, h, [r, g, b], noise = false) {
  const row = noise ? null : Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: w }, () => [r, g, b]).flat())]);
  const raw = noise
    ? Buffer.concat(Array.from({ length: h }, () => Buffer.concat([Buffer.from([0]), crypto.randomBytes(w * 3)])))
    : Buffer.concat(Array.from({ length: h }, () => row));
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw, { level: noise ? 0 : 6 })), chunk('IEND', Buffer.alloc(0))]);
}
const RED = png(120, 80, [200, 30, 30]);

/* ---------------------------------------------------------------- browser helpers */

const consoleErrors = [];
const phpErrors = [];
const httpErrors = [];
const alerts = [];
const PHP_ERR = /(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)\b[^\n]{0,200}\bon line \d+|There has been a critical error/;
const BENIGN_CONSOLE = /favicon|status of 422 \(Unprocessable|Failed to load resource: the server responded with a status of 404 \(Not Found\) @ .*\.(map|ico)/;
// Core block editor preloads these for every user; staff roles deliberately lack the caps, so core answers 403.
const EDITOR_CAP_NOISE = /\/wp-json\/wp\/v2\/(global-styles|blocks|taxonomies)\b|[?&]rest_route=\/wp\/v2\/(global-styles|blocks|taxonomies)\b/;

async function newCtx(browser, width, label = '') {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, acceptDownloads: true });
  const p = await ctx.newPage();
  const expectedNoise = /-gating$|^instructor-rest$/.test(label);
  p.on('console', (m) => {
    if (m.type() !== 'error' || expectedNoise || BENIGN_CONSOLE.test(m.text())) return;
    const src = (m.location() && m.location().url) || ''; // for "Failed to load resource" this is the failing request URL
    if (/status of 403/.test(m.text()) && EDITOR_CAP_NOISE.test(src)) return;
    consoleErrors.push(`[${label || width}] ${m.text().slice(0, 200)} (src ${src.replace(BASE, '').slice(0, 140) || '?'}) @ ${p.url()}`);
  });
  p.on('response', (r) => { if (r.status() >= 400 && r.url().startsWith(BASE) && !/favicon/.test(r.url())) httpErrors.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '').slice(0, 140)} [${label || width}]`); });
  p.on('pageerror', (e) => consoleErrors.push(`[${label || width}] pageerror ${e.message.slice(0, 200)} @ ${p.url()}`));
  p.on('dialog', async (d) => { if (d.type() !== 'confirm' && d.type() !== 'beforeunload') alerts.push(`${d.type()}: ${d.message()} @ ${p.url()}`); await d.accept().catch(() => {}); });
  return { ctx, p };
}
async function visit(p, url, shot = '') {
  const res = await p.goto(url.startsWith('http') ? url : `${BASE}${url}`, { waitUntil: 'load' });
  const hit = ((await p.content()) || '').match(PHP_ERR);
  if (hit) phpErrors.push(`${hit[0].slice(0, 160)} @ ${p.url()}`);
  if (SHOT_DIR && shot) { fs.mkdirSync(SHOT_DIR, { recursive: true }); await p.screenshot({ path: path.join(SHOT_DIR, `${shot}-${p.viewportSize().width}.png`), fullPage: true }).catch(() => {}); }
  return res;
}
const adminUrl = (page, extra = '') => `/wp-admin/admin.php?page=${page}${extra}`;
const overflow = (p) => p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
// Notice selectors never match core nags (update-nag etc.), only the screen's own result notice.
const txt = async (p, sel) => ((await p.textContent(appNoticeSelector(sel)).catch(() => '')) || '').replace(/\s+/g, ' ').trim();
const focusStyled = (p) => p.evaluate(() => {
  const el = document.activeElement; if (!el) return false; const cs = getComputedStyle(el);
  return (cs.boxShadow && cs.boxShadow !== 'none') || (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0);
});
async function wpLogin(p, user) {
  await p.goto(`${BASE}/wp-login.php`);
  await p.fill('#user_login', user.login);
  await p.fill('#user_pass', user.pass);
  await p.click('#wp-submit');
  await p.waitForLoadState('load').catch(() => {});
  await p.waitForURL((u) => !/wp-login\.php$/.test(u.pathname), { timeout: 20000 }).catch(() => {});
  user.landed = p.url();
}
const anonGet = async (ctx, url, opts = {}) => ctx.request.get(url.startsWith('http') ? url : `${BASE}${url}`, { maxRedirects: 0, failOnStatusCode: false, ...opts });

/* ---------------------------------------------------------------- fixtures */

async function mkUser(role, suffix, extraCaps = {}) {
  const login = `${LOW}_${suffix}`;
  const out = await wp('user', 'create', login, `${login}@example.test`, `--role=${role}`, `--user_pass=${PW}`, '--porcelain');
  const id = parseInt(out.trim().split('\n').pop(), 10);
  for (const [cap, grant] of Object.entries(extraCaps)) {
    await wp('eval', `$u=new WP_User(${id}); $u->add_cap(${phpStr(cap)}, ${grant ? 'true' : 'false'});`);
  }
  return { id, login, pass: PW, role };
}
async function cleanup(fx) {
  await wp('eval', `global $wpdb; $p=$wpdb->prefix; $tag=${phpStr(TAG)}; $like='%'.$wpdb->esc_like($tag).'%';
    require_once ABSPATH.'wp-admin/includes/user.php'; require_once ABSPATH.'wp-admin/includes/post.php';
    $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_type<>'revision'",$like));
    foreach($ids as $i){ wp_delete_post((int)$i,true); }
    $att=$wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attachment_image_alt' AND meta_value LIKE %s",$like));
    foreach($att as $i){ wp_delete_attachment((int)$i,true); }
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_inquiries WHERE name LIKE %s OR message LIKE %s",$like,$like));
    foreach(['cc_article_category','cc_gallery_cat'] as $t){ foreach(get_terms(['taxonomy'=>$t,'hide_empty'=>false,'name__like'=>$tag]) as $term){ wp_delete_term($term->term_id,$t); } }
    foreach([${fx.users.map((u) => u.id).filter(Boolean).join(',')}] as $u){ $wpdb->query($wpdb->prepare("DELETE FROM {$p}cc_audit_log WHERE actor_id=%d",$u)); wp_delete_user((int)$u); }`);
}

/* ---------------------------------------------------------------- Gutenberg helpers */

async function editorReady(p, type) {
  await p.goto(`${BASE}/wp-admin/post-new.php?post_type=${type}`, { waitUntil: 'load' });
  await p.waitForFunction((t) => window.wp?.data?.select('core/editor')?.getCurrentPostType?.() === t && !!document.querySelector('.editor-visual-editor, .edit-post-visual-editor'), type, { timeout: 40000 });
  await p.evaluate(() => {
    const prefs = wp.data.dispatch('core/preferences');
    prefs.set('core/edit-post', 'welcomeGuide', false); prefs.set('core/edit-post', 'fullscreenMode', false); prefs.set('core/edit-post', 'welcomeGuideStyles', false);
  });
  await p.waitForTimeout(400);
}
const canvas = async (p) => ((await p.$('iframe[name="editor-canvas"]')) ? p.frameLocator('iframe[name="editor-canvas"]') : p);
async function typeArticle(p, { title, body }) {
  const root = await canvas(p);
  const titleBox = root.locator('.editor-post-title__input, h1.wp-block-post-title, [aria-label="Add title"]').first();
  await titleBox.click();
  await p.keyboard.insertText(title);
  await p.keyboard.press('Enter');
  await p.keyboard.insertText(body);
}
async function openPanel(p, name) {
  await p.evaluate(() => wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/document'));
  const btn = p.locator(`.components-panel__body-toggle:has-text("${name}")`).first();
  await btn.waitFor({ timeout: 10000 });
  if ((await btn.getAttribute('aria-expanded')) !== 'true') await btn.click();
}
async function publishFromEditor(p, label) {
  await p.getByRole('button', { name: label, exact: true }).first().click();
  const confirm = p.locator('.editor-post-publish-panel__header-publish-button button, .editor-post-publish-panel .editor-post-publish-button').first();
  if (await confirm.isVisible({ timeout: 4000 }).catch(() => false)) await confirm.click();
  await p.waitForFunction(() => ['publish', 'future'].includes(wp.data.select('core/editor').getCurrentPost().status), null, { timeout: 30000 });
  await p.waitForLoadState('networkidle').catch(() => {});
  await p.waitForTimeout(1500); // meta box form posts after the REST save
}
const postRow = (title) => wpJson(`$p=get_posts(['post_type'=>['cc_article','cc_gallery_item','cc_result','cc_branch'],'post_status'=>'any','title'=>${phpStr(title)},'posts_per_page'=>1]); $x=$p?$p[0]:null; echo json_encode($x?['id'=>$x->ID,'status'=>$x->post_status,'slug'=>$x->post_name,'author'=>(int)$x->post_author]:null);`);

/* ---------------------------------------------------------------- media helpers */

async function serverUpload(p, name, bytes, alt, { raw = false } = {}) {
  return p.evaluate(async ({ name, b64, alt, raw }) => {
    const cfg = window.ccMedia;
    const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    const body = new FormData();
    body.append('action', cfg.action); body.append('_wpnonce', cfg.nonce); body.append('ajax', '1'); body.append('alt', alt);
    body.append('file', new File([bin], name, { type: raw ? 'image/png' : '' }));
    const res = await fetch(cfg.endpoint, { method: 'POST', body, credentials: 'same-origin' });
    return { status: res.status, body: await res.json().catch(() => null) };
  }, { name, b64: bytes.toString('base64'), alt, raw });
}
const attachmentCount = async () => (await wpJson(`echo json_encode((int)wp_count_posts('attachment')->inherit);`)) ?? -1;
const attIdsByAlt = async () => (await wpJson(`global $wpdb; $r=$wpdb->get_results($wpdb->prepare("SELECT post_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attachment_image_alt' AND meta_value LIKE %s",'%'.$wpdb->esc_like(${phpStr(TAG)}).'%'),ARRAY_A); $o=[]; foreach($r as $x){$o[$x['meta_value']]=(int)$x['post_id'];} echo json_encode($o);`)) || {};

/* ================================================================ sections */

async function mediaSection(browser, fx) {
  section('(1) Media: upload, validation, alt, delete (staff, 1280)');
  const { ctx, p } = await newCtx(browser, 1280, 'staff-media');
  fx.staffCtx = ctx; fx.staffPage = p;
  await wpLogin(p, fx.staff);
  note(`staff landed: ${fx.staff.landed}`);
  await visit(p, adminUrl('cc-media'), 'media');
  check('Media screen renders for staff', /Media/.test(await txt(p, '.wrap h1')) && !!(await p.$('#cc-media-drop')));
  check('upload zone is a focusable button with a label', (await p.getAttribute('#cc-media-drop', 'role')) === 'button' && (await p.getAttribute('#cc-media-drop', 'tabindex')) === '0' && !!(await p.getAttribute('#cc-media-drop', 'aria-label')));

  // keyboard operation of the zone
  await p.focus('#cc-media-drop');
  const zoneStyle = await p.$eval('#cc-media-drop', (e) => { const f = getComputedStyle(e); return { outline: f.outlineStyle, ring: f.boxShadow, border: f.borderTopColor, bg: f.backgroundColor }; });
  await p.evaluate(() => document.activeElement.blur());
  const zoneBlur = await p.$eval('#cc-media-drop', (e) => { const f = getComputedStyle(e); return { border: f.borderTopColor, bg: f.backgroundColor }; });
  check('upload zone has a clear focus indicator (outline or ring, not just a border-colour tint)', zoneStyle.outline !== 'none' || zoneStyle.ring !== 'none', `focused=${JSON.stringify(zoneStyle)} blurred=${JSON.stringify(zoneBlur)} (css: .cc-media-drop:focus{outline:none})`);
  await p.focus('#cc-media-drop');
  const chooser = p.waitForEvent('filechooser', { timeout: 5000 }).catch(() => null);
  await p.keyboard.press('Enter');
  check('Enter on the upload zone opens the file chooser', !!(await chooser));
  const chooser2 = p.waitForEvent('filechooser', { timeout: 5000 }).catch(() => null);
  await p.keyboard.press('Space');
  check('Space on the upload zone opens the file chooser', !!(await chooser2));

  // drag-and-drop WITHOUT alt text
  const dropFile = (name, buf) => p.evaluate(({ name, b64 }) => {
    const bin = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    const dt = new DataTransfer(); dt.items.add(new File([bin], name, { type: 'image/png' }));
    document.getElementById('cc-media-drop').dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
  }, { name, b64: buf.toString('base64') });
  const before = await attachmentCount();
  await dropFile('qa-noalt.png', RED);
  check('dropped PNG is queued with an alt-text field', (await p.$$('#cc-media-queue li input[type=text]')).length === 1);
  check('Upload is disabled while alt text is empty', await p.isDisabled('#cc-media-start'));
  // The reason lives in the aria-live problem list (ul.cc-media-problems) rendered just above the Upload button.
  const problemsVisible = await p.isVisible('.cc-media-problems li');
  const problemsLive = (await p.getAttribute('.cc-media-problems', 'aria-live')) === 'polite';
  const visibleMsg = [await txt(p, '.cc-media-problems'), await txt(p, '#cc-media-status')].join(' ').trim();
  check('client explains WHY Upload is disabled (visible, announced message mentions alt text)', problemsVisible && problemsLive && /alt text required/i.test(visibleMsg), `visible=${problemsVisible} live=${problemsLive} text: "${visibleMsg}"`);
  const srv = await serverUpload(p, 'qa-noalt.png', RED, '', { raw: true });
  check('server refuses an image without alt text (422 + clear message)', srv.status === 422 && /Alt text is required/.test(srv.body?.data?.message || ''), JSON.stringify(srv));
  check('no attachment was created by the refused uploads', (await attachmentCount()) === before);

  // with alt
  await p.fill('#cc-media-queue li input[type=text]', `${TAG} alt first`);
  check('Upload enables once alt text is typed', !(await p.isDisabled('#cc-media-start')));
  await Promise.all([p.waitForNavigation({ timeout: 30000 }).catch(() => {}), p.click('#cc-media-start')]);
  await p.waitForSelector('.cc-media-grid');
  const first = p.locator(`.cc-media-item:has(img[alt="${TAG} alt first"])`).first();
  check('uploaded image appears in the grid with its alt as the thumbnail alt', (await first.count()) === 1);
  const chip = await first.locator('.cc-chip--ok').allTextContents();
  check('WebP chip shown on the new image', chip.some((t) => /WebP/.test(t)), `chips: ${chip}`);
  check('stored filename is randomised (no original name)', !/qa-noalt/.test(await first.locator('.cc-media-name').innerText()));

  // refused types: client-side messages
  const bad = [
    ['evil.svg', Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    ['page.html', Buffer.from('<html><script>alert(1)</script></html>')],
    ['shell.php', Buffer.from('<?php system($_GET["c"]); ?>')],
    ['setup.exe', Buffer.concat([Buffer.from('MZ'), Buffer.alloc(200)])],
    ['shell.php.png', RED],
    ['huge.png', png(1500, 1200, [0, 0, 0], true)],
  ];
  check('oversize fixture really is > 5 MB', bad[5][1].length > 5 * MB, String(bad[5][1].length));
  await visit(p, adminUrl('cc-media'));
  await p.setInputFiles('#cc-media-input', bad.map(([name, buffer]) => ({ name, mimeType: 'application/octet-stream', buffer })));
  const msgs = await p.$$eval('#cc-media-queue li', (lis) => lis.map((li) => li.textContent.replace(/\s+/g, ' ').trim()));
  for (const [i, [name]] of bad.entries()) {
    check(`client refuses ${name} BEFORE upload with a message`, /allowed|Rename|too large/i.test(msgs[i] || '') && !(await p.$$(`#cc-media-queue li:nth-child(${i + 1}) input`)).length, msgs[i]);
  }
  check('oversize message mentions the 5 MB limit', /5 MB/.test(msgs[5] || ''), msgs[5]);
  check('Upload stays disabled with refused files queued', await p.isDisabled('#cc-media-start'));
  const removable = await p.$$('#cc-media-queue li button');
  check('a refused file in the queue can be removed (otherwise one bad file blocks the batch)', removable.length > 0, 'no remove control; page reload is the only way to clear the queue');

  // refused types: server side, bypassing the client check
  const countBefore = await attachmentCount();
  const polyglot = Buffer.concat([RED, Buffer.from('<?php system($_GET[1]); ?>')]);
  const svgAsPng = Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>');
  const serverCases = [
    ['evil.svg', bad[0][1], /Only JPEG/], ['page.html', bad[1][1], /Only JPEG/], ['shell.php', bad[2][1], /Only JPEG/], ['setup.exe', bad[3][1], /Only JPEG/],
    ['notes.png', Buffer.from('just some text, not a picture at all'), /Only JPEG|does not match/], ['svg-as.png', svgAsPng, /Only JPEG|does not match/],
    ['shell.php.png', RED, /blocked extension/], ['huge.png', bad[5][1], /too large/], ['poly.png', polyglot, /rejected|could not/i], ['empty.png', Buffer.alloc(0), /empty|Choose a file|Only JPEG/i],
  ];
  for (const [name, bytes, re] of serverCases) {
    const r = await serverUpload(p, name, bytes, `${TAG} should not exist`);
    check(`server refuses ${name} (422, clear message)`, r.status === 422 && re.test(r.body?.data?.message || ''), JSON.stringify(r).slice(0, 200));
  }
  check('server-side refusals created no attachments', (await attachmentCount()) === countBefore);

  // client allows a .png-named text file; full flow through the UI shows the server message
  await visit(p, adminUrl('cc-media'));
  await p.setInputFiles('#cc-media-input', [{ name: 'notes.png', mimeType: 'image/png', buffer: Buffer.from('this is plain text renamed to png') }]);
  await p.fill('#cc-media-queue li input[type=text]', `${TAG} text file`);
  await p.click('#cc-media-start');
  await p.waitForFunction(() => /Only JPEG|does not match|failed/i.test(document.getElementById('cc-media-queue').textContent), null, { timeout: 15000 }).catch(() => {});
  const renamed = await txt(p, '#cc-media-queue');
  check('.png-renamed text file is refused after upload with a clear message', /Only JPEG|does not match/i.test(renamed), renamed);

  // batch upload of the fixture images used later
  const defs = [['del', [90, 90, 90]], ['feat', [20, 120, 220]], ['g1', [200, 120, 20]], ['g2', [20, 200, 120]], ['g3', [120, 20, 200]], ['g4', [200, 20, 120]], ['r1', [10, 60, 90]], ['r2', [90, 60, 10]], ['r3', [60, 90, 10]]];
  await visit(p, adminUrl('cc-media'));
  await p.setInputFiles('#cc-media-input', defs.map(([k, rgb]) => ({ name: `qa-${k}.png`, mimeType: 'image/png', buffer: png(160, 120, rgb) })));
  for (const [k] of defs) await p.fill(`input[aria-label="Alt text for qa-${k}.png"]`, `${TAG} alt ${k}`);
  await Promise.all([p.waitForNavigation({ timeout: 60000 }).catch(() => {}), p.click('#cc-media-start')]);
  fx.att = await attIdsByAlt();
  const idOf = (k) => fx.att[`${TAG} alt ${k}`];
  check('all 10 fixture images stored with alt text', Object.keys(fx.att).length === 10, JSON.stringify(fx.att));
  fx.att.first = fx.att[`${TAG} alt first`];

  // edit alt
  await visit(p, adminUrl('cc-media'));
  const aid = idOf('del');
  await p.fill(`#cc-alt-${aid}`, `${TAG} alt del edited`);
  await Promise.all([p.waitForNavigation(), p.click(`.cc-media-item:has(#cc-alt-${aid}) button:text-is("Save alt")`)]);
  check('editing alt shows "Alt text saved."', /Alt text saved/.test(await txt(p, '.notice')));
  check('edited alt is shown on the thumbnail', (await p.getAttribute(`.cc-media-item:has(#cc-alt-${aid}) img`, 'alt')) === `${TAG} alt del edited`);
  const emptyAlt = await p.evaluate(async (id) => {
    const f = document.querySelector(`#cc-alt-${id}`).form; const fd = new FormData(f); fd.set('alt', '   ');
    const r = await fetch(f.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'follow' });
    return { url: r.url, html: await r.text() };
  }, aid);
  check('server refuses blank alt on edit with a message', /Alt text is required/.test(emptyAlt.html), emptyAlt.url);
  check('alt input is required/maxlength-limited in the browser', (await p.getAttribute(`#cc-alt-${aid}`, 'required')) !== null && (await p.getAttribute(`#cc-alt-${aid}`, 'maxlength')) === '250');

  // delete unused
  await visit(p, adminUrl('cc-media'));
  await Promise.all([p.waitForNavigation(), p.click(`.cc-media-item:has(#cc-alt-${aid}) button:text-is("Delete")`)]);
  check('deleting an unused image succeeds ("File deleted.")', /File deleted/.test(await txt(p, '.notice')));
  check('deleted attachment is gone from the DB', (await wpJson(`echo json_encode(get_post(${aid}) ? 1 : 0);`)) === 0);

  // used as featured image
  const featId = idOf('feat');
  const holder = (await wp('post', 'create', '--post_type=cc_article', `--post_title=${TAG} feature holder`, '--post_status=draft', `--meta_input={"_thumbnail_id":"${featId}"}`, '--porcelain')).trim().split('\n').pop();
  fx.holderId = parseInt(holder, 10);
  await visit(p, adminUrl('cc-media'));
  const item = p.locator(`.cc-media-item:has(#cc-alt-${featId})`);
  check('in-use image shows an "In use (1)" chip', /In use \(1\)/.test(await item.innerText()));
  check('staff (no cc_manage_staff) does not get the force-delete checkbox', (await item.locator('input[name=force]').count()) === 0);
  await Promise.all([p.waitForNavigation(), item.locator('button:text-is("Delete")').click()]);
  check('deleting an in-use image is refused with a clear message', /used by 1 item\(s\) and cannot be deleted/.test(await txt(p, '.notice')), await txt(p, '.notice'));
  const forced = await p.evaluate(async (id) => {
    const f = [...document.querySelectorAll('form')].find((x) => x.querySelector('input[name=action][value=cc_media_delete]') && x.querySelector(`input[name=id][value="${id}"]`));
    const fd = new FormData(f); fd.set('force', '1');
    await fetch(f.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' });
  }, featId);
  check('staff cannot force-delete by posting force=1', (await wpJson(`echo json_encode(get_post(${featId}) ? 1 : 0);`)) === 1);
  note(`force post returned: ${forced}`);

  // owner can force
  const owner = await newCtx(browser, 1280, 'owner-media');
  await wpLogin(owner.p, fx.owner);
  await visit(owner.p, adminUrl('cc-media'));
  const oitem = owner.p.locator(`.cc-media-item:has(#cc-alt-${featId})`);
  check('owner sees the force-delete checkbox for an in-use image', (await oitem.locator('input[name=force]').count()) === 1);
  await oitem.locator('input[name=force]').check();
  await Promise.all([owner.p.waitForNavigation(), oitem.locator('button:text-is("Delete")').click()]);
  check('owner can force-delete an in-use image', /File deleted/.test(await txt(owner.p, '.notice')) && (await wpJson(`echo json_encode(get_post(${featId}) ? 1 : 0);`)) === 0);
  await owner.ctx.close();

  // layout
  for (const w of [768, 375]) {
    await p.setViewportSize({ width: w, height: 900 });
    await visit(p, adminUrl('cc-media'), 'media');
    check(`Media screen has no horizontal overflow at ${w}px`, (await overflow(p)) <= 0, String(await overflow(p)));
  }
  await p.setViewportSize({ width: 1280, height: 900 });
}

async function blogSection(browser, fx) {
  section('(2) Blog (staff native editor; anonymous checks)');
  const p = fx.staffPage;
  const siteName = (await wpJson(`echo json_encode(get_bloginfo('name'));`)) || 'Astona';
  const catName = `${TAG} Cat`;
  fx.catId = parseInt((await wp('term', 'create', 'cc_article_category', catName, '--porcelain')).trim(), 10);

  // published article via the editor
  await editorReady(p, 'cc_article');
  const mainTitle = `${TAG} How to plan revision`;
  await typeArticle(p, { title: mainTitle, body: 'Start by listing every chapter, then give each week one theme for revision.' });
  await openPanel(p, 'Article Categories');
  const catBox = p.getByLabel(catName, { exact: true });
  await catBox.check();
  await p.getByRole('button', { name: 'Add an excerpt…' }).click();
  await p.locator('textarea[placeholder*="excerpt" i], .editor-post-excerpt__textarea textarea, .editor-post-excerpt textarea').first().fill('A simple weekly plan for the whole syllabus.');
  if (!(await p.isVisible('#cc_meta_title'))) { const t = p.getByRole('button', { name: 'Meta Boxes', exact: true }); await t.focus(); await p.keyboard.press('Enter'); }
  if (!(await p.isVisible('#cc_meta_title'))) await p.locator('#cc_article_seo .handlediv').click();
  await p.locator('#cc_meta_title').scrollIntoViewIfNeeded();
  await p.fill('#cc_meta_title', `${TAG} SEO title override`);
  await p.fill('#cc_meta_description', 'Meta description written by staff for the revision article.');
  await publishFromEditor(p, 'Publish');
  fx.main = await postRow(mainTitle);
  check('article published through the editor', fx.main?.status === 'publish', JSON.stringify(fx.main));
  const meta = await wpJson(`echo json_encode(['t'=>get_post_meta(${fx.main?.id || 0},'cc_meta_title',true),'d'=>get_post_meta(${fx.main?.id || 0},'cc_meta_description',true),'terms'=>wp_get_object_terms(${fx.main?.id || 0},'cc_article_category',['fields'=>'ids']),'ex'=>get_post(${fx.main?.id || 0})->post_excerpt]);`);
  check('SEO title + meta description saved by the meta box', meta?.t === `${TAG} SEO title override` && /Meta description written/.test(meta?.d || ''), JSON.stringify(meta));
  check('category and excerpt saved', (meta?.terms || []).includes(fx.catId) && /weekly plan/.test(meta?.ex || ''), JSON.stringify(meta));

  // Bangla article via the editor
  await editorReady(p, 'cc_article');
  await typeArticle(p, { title: BANGLA_TITLE, body: 'প্রথম সপ্তাহে সিলেবাস ভাগ করে নিন এবং প্রতিদিন প্রশ্ন সমাধান করুন।' });
  await openPanel(p, 'Article Categories');
  await p.getByLabel(catName, { exact: true }).check();
  await publishFromEditor(p, 'Publish');
  fx.bn = await postRow(BANGLA_TITLE);
  check('Bangla article published', fx.bn?.status === 'publish', JSON.stringify(fx.bn));

  // scheduled via the editor
  await editorReady(p, 'cc_article');
  const schedTitle = `${TAG} Scheduled future`;
  await typeArticle(p, { title: schedTitle, body: 'This article is scheduled for the future.' });
  await p.evaluate(() => wp.data.dispatch('core/editor').editPost({ date: new Date(Date.now() + 40 * 86400000).toISOString().slice(0, 19) }));
  await publishFromEditor(p, 'Schedule');
  fx.sched = await postRow(schedTitle);
  check('article scheduled for the future (status=future)', fx.sched?.status === 'future', JSON.stringify(fx.sched));

  // archived: created by wp-cli, archived through the list row action
  const archTitle = `${TAG} Archived article`;
  const archId = parseInt((await wp('post', 'create', '--post_type=cc_article', `--post_title=${archTitle}`, '--post_status=publish', '--post_content=<p>Archive me</p>', `--post_author=${fx.staff.id}`, '--porcelain')).trim(), 10);
  await wp('post', 'term', 'set', String(archId), 'cc_article_category', String(fx.catId));
  await visit(p, `/wp-admin/edit.php?post_type=cc_article&s=${encodeURIComponent(archTitle)}`);
  const row = p.locator(`#the-list tr:has-text("${archTitle}")`).first();
  check('article list shows a State column', /State/.test(await txt(p, 'table.wp-list-table thead')));
  await row.hover();
  await Promise.all([p.waitForNavigation(), row.locator('.row-actions a:text-is("Archive")').click()]);
  check('archive row action confirms ("Archived: hidden from the public site.")', /Archived: hidden/.test(await txt(p, '.notice')), await txt(p, '.notice'));
  fx.arch = await wpJson(`$x=get_post(${archId}); echo json_encode(['id'=>$x->ID,'status'=>$x->post_status,'slug'=>$x->post_name]);`);
  check('archived article keeps status publish + flag', (await wpJson(`echo json_encode(get_post_meta(${archId},'cc_archived',true));`)) === '1');

  // ---- public surface (anonymous)
  const anon = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const ap = await anon.newPage();
  ap.on('pageerror', (e) => consoleErrors.push(`[anon-blog] pageerror ${e.message}`));
  ap.on('console', (m) => { if (m.type() === 'error' && !BENIGN_CONSOLE.test(m.text())) consoleErrors.push(`[anon-blog] ${m.text().slice(0, 200)} @ ${ap.url()}`); });
  await visit(ap, '/blog/', 'blog');
  const list = await ap.textContent('main, body');
  check('/blog/ lists the published article', list.includes(mainTitle));
  check('/blog/ lists the Bangla article', list.includes(BANGLA_TITLE));
  check('/blog/ hides the scheduled article', !list.includes(schedTitle));
  check('/blog/ hides the archived article', !list.includes(archTitle));
  check('/blog/ no overflow @1280', (await overflow(ap)) <= 0);

  const singleUrl = `/blog/${fx.main.slug}/`;
  await visit(ap, singleUrl, 'article');
  check('single article shows title', (await txt(ap, 'h1')).includes(mainTitle));
  check('single article shows a date (time element)', !!(await ap.$('article time[datetime]')));
  check('single article byline is the site name, not a login', /By Astona/.test(await txt(ap, 'article .page-head')) && !(await ap.content()).includes(fx.staff.login));
  check('single article shows related articles (same category)', /Related articles/.test(await ap.textContent('body')) && (await ap.textContent('body')).includes(BANGLA_TITLE));
  const html = await (await anonGet(anon, singleUrl)).text();
  check('view-source has the staff-written meta description', html.includes('<meta name="description" content="Meta description written by staff for the revision article.">'));
  check('view-source has Open Graph tags (type/title/url/site_name/description)', ['og:type" content="article"', `og:title" content="${TAG} SEO title override"`, 'og:url', 'og:site_name', 'og:description'].every((s) => html.includes(s)), html.match(/<meta property="og:[^>]+>/g)?.join(' '));
  check('<title> uses the SEO title override', new RegExp(`<title>[^<]*${TAG} SEO title override`).test(html));
  const ld = [...html.matchAll(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/gs)].flatMap((m) => { try { const j = JSON.parse(m[1]); return j['@graph'] || [j]; } catch { return []; } });
  const art = ld.find((n) => n['@type'] === 'Article');
  check('Article JSON-LD present with headline and dates', !!art && art.headline === mainTitle && !!art.datePublished, JSON.stringify(art));
  check('Article JSON-LD author is the SITE NAME', art?.author?.name === siteName && art?.publisher?.name === siteName, JSON.stringify(art?.author));

  // no staff login anywhere
  const probes = ['/blog/', singleUrl, '/blog/feed/', '/feed/', '/?feed=rss2&post_type=cc_article', `/wp-json/wp/v2/cc_article/${fx.main.id}`, '/wp-json/wp/v2/cc_article?per_page=100', '/wp-sitemap.xml', '/wp-sitemap-posts-cc_article-1.xml',
    `/wp-json/oembed/1.0/embed?url=${encodeURIComponent(BASE + singleUrl)}`, '/wp-json/wp/v2/users', `/?author=${fx.staff.id}`, `/author/${fx.staff.login}/`];
  const leaks = [];
  for (const u of probes) { const r = await anonGet(anon, u); const body = await r.text().catch(() => ''); if (body.includes(fx.staff.login) || (u.includes('cc_article') && /"author":\s*\d+/.test(body))) leaks.push(`${u} (${r.status()})`); }
  check('staff login/author id appears on no public surface (page, feed, REST, sitemap, oEmbed, users)', leaks.length === 0, leaks.join(', '));
  const feed = await (await anonGet(anon, '/blog/feed/')).text();
  check('blog feed lists the published article and credits the site name as creator', feed.includes(mainTitle) && /<dc:creator>(<!\[CDATA\[)?Astona/.test(feed), feed.match(/<dc:creator>.*?<\/dc:creator>/)?.[0]);

  // scheduled + archived are invisible everywhere
  for (const [label, row] of [['scheduled', fx.sched], ['archived', fx.arch]]) {
    const urls = [`/blog/${row.slug}/`, `/?p=${row.id}`, `/?post_type=cc_article&p=${row.id}`];
    for (const u of urls) { const r = await anonGet(anon, u); check(`${label} article direct URL ${u} is 404 for the public`, r.status() === 404, String(r.status())); }
    const rest = await anonGet(anon, `/wp-json/wp/v2/cc_article/${row.id}`);
    check(`${label} article REST item is 404 for anonymous`, rest.status() === 404, String(rest.status()));
    const restList = await (await anonGet(anon, '/wp-json/wp/v2/cc_article?per_page=100&status=any')).text();
    check(`${label} article absent from the REST collection`, !restList.includes(row === fx.sched ? schedTitle : archTitle));
    for (const u of ['/blog/feed/', '/feed/', '/?s=' + encodeURIComponent(TAG), '/wp-sitemap-posts-cc_article-1.xml', `/blog/category/${(await wpJson(`echo json_encode(get_term(${fx.catId})->slug);`))}/`]) {
      const body = await (await anonGet(anon, u)).text();
      check(`${label} article absent from ${u}`, !body.includes(row === fx.sched ? schedTitle : archTitle));
    }
  }

  // Bangla rendering
  await visit(ap, `/blog/${fx.bn.slug}/`, 'article-bn');
  check('Bangla title renders on the article page', (await txt(ap, 'h1')) === BANGLA_TITLE);
  check('Bangla article carries lang="bn"', (await ap.getAttribute('article', 'lang')) === 'bn');
  const fam = await ap.$eval('h1', (e) => getComputedStyle(e).fontFamily);
  note(`Bangla h1 font-family: ${fam}`);
  const noTofu = await ap.evaluate(async (s) => { await document.fonts.ready; return document.fonts.check('16px sans-serif', s); }, BANGLA_TITLE);
  check('Bangla glyphs are covered by an available font', noTofu);

  // 375px
  const m = await newCtx(browser, 375, 'anon-375-blog');
  await visit(m.p, '/blog/', 'blog'); check('/blog/ no overflow @375', (await overflow(m.p)) <= 0, String(await overflow(m.p)));
  await visit(m.p, singleUrl, 'article'); check('article page no overflow @375', (await overflow(m.p)) <= 0, String(await overflow(m.p)));
  await visit(m.p, `/blog/${fx.bn.slug}/`); check('Bangla article no overflow @375', (await overflow(m.p)) <= 0, String(await overflow(m.p)));
  await m.ctx.close();
  await anon.close();
}

async function createClassic(p, type, fields, opts = {}) {
  await visit(p, `/wp-admin/post-new.php?post_type=${type}`);
  await p.fill('#title', fields.title);
  for (const [sel, val] of Object.entries(fields.inputs || {})) await p.fill(sel, String(val));
  for (const sel of fields.checks || []) await p.check(sel);
  await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('#publish')]);
  return p.url();
}

async function gallerySection(browser, fx) {
  section('(3) Gallery');
  const p = fx.staffPage;
  const catA = `${TAG} Alpha`; const catB = `${TAG} Beta`; const catEmpty = `${TAG} Empty`;
  fx.gcats = {};
  for (const [k, name] of [['a', catA], ['b', catB], ['e', catEmpty]]) fx.gcats[k] = parseInt((await wp('term', 'create', 'cc_gallery_cat', name, '--porcelain')).trim(), 10);
  const item = (k, n) => ({ title: `${TAG} Gallery ${n}`, inputs: { '#cc-gallery-image': fx.att[`${TAG} alt ${k}`], '#cc-gallery-caption': `${TAG} caption ${n}` }, checks: [`#cc_gallery_catchecklist input[value="${fx.gcats[n < 3 ? 'a' : 'b']}"]`] });

  // missing alt -> stays draft with notice
  const noAlt = item('g1', 1);
  await createClassic(p, 'cc_gallery_item', noAlt);
  check('gallery item saved without alt text shows the draft notice', /alt text is required/i.test(await txt(p, '.notice-error')), await txt(p, '.notice-error'));
  const row = await postRow(noAlt.title);
  check('gallery item without alt text stays a draft', row?.status === 'draft', JSON.stringify(row));
  // now add alt and publish
  await p.fill('#cc-gallery-alt', `${TAG} gallery alt 1`);
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  check('gallery item publishes once alt text is added', (await postRow(noAlt.title))?.status === 'publish');
  for (const n of [2, 3, 4]) {
    const it = item(`g${n}`, n); it.inputs['#cc-gallery-alt'] = `${TAG} gallery alt ${n}`;
    await createClassic(p, 'cc_gallery_item', it);
  }
  check('4 gallery items published (2 + 2 per category)', (await wpJson(`echo json_encode(count(get_posts(['post_type'=>'cc_gallery_item','post_status'=>'publish','s'=>${phpStr(TAG)},'posts_per_page'=>-1])));`)) === 4);
  // a draft-only item in the "Empty" category
  await createClassic(p, 'cc_gallery_item', { title: `${TAG} Gallery draft`, inputs: { '#cc-gallery-image': fx.att[`${TAG} alt g1`], '#cc-gallery-alt': 'x' }, checks: [`#cc_gallery_catchecklist input[value="${fx.gcats.e}"]`] }).catch(() => {});
  await wp('post', 'update', String((await postRow(`${TAG} Gallery draft`))?.id || 0), '--post_status=draft');

  for (const w of [1280, 375]) {
    const { ctx, p: ap } = await newCtx(browser, w, `anon-gallery-${w}`);
    await visit(ap, '/gallery/', 'gallery');
    const heads = await ap.$$eval('.gallery-group h2', (hs) => hs.map((h) => h.textContent.trim()));
    check(`@${w} gallery shows both categories with published items`, heads.includes(catA) && heads.includes(catB), heads.join('|'));
    check(`@${w} gallery hides categories with no published items`, !heads.includes(catEmpty));
    const imgs = await ap.$$eval(`.gallery-group:has(h2:text-is("${catA}")) img, .gallery-group:has(h2:text-is("${catB}")) img`, (is) => is.map((i) => ({ lazy: i.getAttribute('loading'), w: i.getAttribute('width'), h: i.getAttribute('height'), alt: i.alt })));
    check(`@${w} gallery images are lazy with width/height and alt`, imgs.length === 4 && imgs.every((i) => i.lazy === 'lazy' && i.w && i.h && i.alt.startsWith(TAG)), JSON.stringify(imgs));
    check(`@${w} no horizontal overflow`, (await overflow(ap)) <= 0, String(await overflow(ap)));
    const links = ap.locator(`.gallery-group:has(h2:text-is("${catA}")) a[data-gallery-link]`);
    if (w === 1280) {
      // mouse open + Esc
      await links.nth(0).click();
      check('lightbox opens on click', await ap.isVisible('dialog.lightbox[open]'));
      check('focus moves into the lightbox', await ap.evaluate(() => !!document.activeElement.closest('dialog.lightbox')));
      await ap.keyboard.press('Escape');
      check('Esc closes the lightbox', !(await ap.isVisible('dialog.lightbox[open]')));
      check('focus returns to the opener after Esc', await ap.evaluate(() => document.activeElement?.matches('a[data-gallery-link]') && document.activeElement.dataset.caption.includes('caption 1') || document.activeElement?.matches('a[data-gallery-link]')));
      // keyboard-only: focus the first link via Tab order, open with Enter, arrows, Esc
      await links.nth(0).focus();
      check('gallery link shows a visible focus style', await focusStyled(ap));
      await ap.keyboard.press('Enter');
      check('Enter opens the lightbox (keyboard only)', await ap.isVisible('dialog.lightbox[open]'));
      const cap1 = await txt(ap, '.lightbox__caption');
      await ap.keyboard.press('ArrowRight');
      const cap2 = await txt(ap, '.lightbox__caption');
      check('ArrowRight shows the next image', cap2 !== cap1 && /caption/.test(cap2), `${cap1} -> ${cap2}`);
      await ap.keyboard.press('ArrowLeft');
      check('ArrowLeft goes back', (await txt(ap, '.lightbox__caption')) === cap1);
      await ap.keyboard.press('ArrowLeft');
      check('arrow navigation wraps around', (await txt(ap, '.lightbox__caption')) === cap2);
      const trapped = [];
      for (let i = 0; i < 5; i++) { await ap.keyboard.press('Tab'); trapped.push(await ap.evaluate(() => (document.activeElement.closest('dialog.lightbox') ? 'dialog' : document.activeElement === document.body ? 'chrome' : 'page'))); }
      check('Tab never reaches page content behind the open lightbox', trapped.every((x) => x !== 'page'), trapped.join(','));
      check('lightbox buttons have accessible names', (await ap.$$eval('dialog.lightbox button', (bs) => bs.map((b) => b.getAttribute('aria-label')))).every(Boolean));
      await ap.keyboard.press('Escape');
      check('focus returns to the opener after keyboard close', await ap.evaluate(() => document.activeElement?.matches('a[data-gallery-link]')));
    } else {
      await links.nth(0).tap().catch(() => links.nth(0).click());
      const box = await ap.evaluate(() => { const i = document.querySelector('.lightbox__img'); const r = i.getBoundingClientRect(); return { right: r.right, left: r.left, vw: innerWidth }; });
      check('@375 lightbox image fits the viewport', box.left >= 0 && box.right <= box.vw, JSON.stringify(box));
      await ap.keyboard.press('Escape');
    }
    await ctx.close();
  }
}

async function resultsSection(browser, fx) {
  section('(4) Results visibility and private photos');
  const p = fx.staffPage;
  const names = { ok: `${TAG} Verified Consented`, nc: `${TAG} Verified NoConsent`, uv: `${TAG} Unverified Consented` };
  const alts = { ok: `${TAG} portrait ok`, nc: `${TAG} portrait nc`, uv: `${TAG} portrait uv` };
  const colours = { ok: [200, 30, 30], nc: [30, 200, 30], uv: [30, 30, 200] };
  const pngFile = (k) => ({ name: `qa-${k}.png`, mimeType: 'image/png', buffer: png(160, 120, colours[k]) });
  const photoState = (id) => wpJson(`echo json_encode(['path'=>(string)get_post_meta(${id},'cc_result_photo_path',true),'alt'=>(string)get_post_meta(${id},'cc_result_photo_alt',true),'url'=>(string)parse_url(CC_Result_Photo_Access::signed_url(${id}),PHP_URL_PATH)]);`);
  const errorNotice = (text) => p.locator(appNoticeSelector('.notice-error'), { hasText: text }).count();
  const attachmentsBefore = await attachmentCount();
  const ids = {};

  for (const [k, verified, consent] of [['ok', true, true], ['nc', true, false], ['uv', false, true]]) {
    await visit(p, '/wp-admin/post-new.php?post_type=cc_result');
    await p.fill('#title', names[k]);
    await p.fill('#cc_result_exam', 'HSC Science'); await p.fill('#cc_result_year', '2026'); await p.fill('#cc_result_score', 'GPA 5.00');
    if (verified) await p.check('input[name=cc_result_verified]');
    if (consent) await p.check('input[name=cc_result_consent]');
    if (k === 'ok') await p.setInputFiles('#cc-result-photo-file', pngFile(k)); // no alt text on purpose
    if (k === 'nc') { await p.setInputFiles('#cc-result-photo-file', { name: 'qa-nc.png', mimeType: 'image/png', buffer: Buffer.from('this is not an image') }); await p.fill('#cc-result-photo-alt', alts[k]); }
    if (k === 'uv') { await p.setInputFiles('#cc-result-photo-file', pngFile(k)); await p.fill('#cc-result-photo-alt', alts[k]); }
    await Promise.all([p.waitForNavigation(), p.click('#publish')]);
    ids[k] = (await postRow(names[k]))?.id;
    const state = await photoState(ids[k]);
    if (k === 'ok') {
      check('photo without alt text is refused with a notice', (await errorNotice('Photo description is required')) === 1 && state?.path === '', JSON.stringify(state));
    }
    if (k === 'nc') {
      check('a non-image renamed .png is refused with a notice', (await errorNotice('JPEG, PNG or WebP')) === 1 && state?.path === '', JSON.stringify(state));
    }
    if (k !== 'uv') {
      await p.setInputFiles('#cc-result-photo-file', pngFile(k));
      await p.fill('#cc-result-photo-alt', alts[k]);
      await Promise.all([p.waitForNavigation(), p.click('#publish')]);
    }
  }
  const states = {};
  for (const k of ['ok', 'nc', 'uv']) states[k] = await photoState(ids[k]);
  check('all three photos are stored as private paths with alt text', ['ok', 'nc', 'uv'].every((k) => /^result-photos\/[a-f0-9]{32}\.png$/.test(states[k]?.path || '') && states[k].alt === alts[k]), JSON.stringify(states));
  check('uploading results photos created no media-library attachment', (await attachmentCount()) === attachmentsBefore, `${attachmentsBefore} -> ${await attachmentCount()}`);
  const inUploads = await wpJson(`$d=wp_upload_dir(null,false)['basedir']; $o=[]; foreach([${Object.values(states).map((x) => phpStr(x.path)).join(',')}] as $r){ $n=substr(basename($r),0,32); $f=trim((string)shell_exec('find '.escapeshellarg($d).' -name '.escapeshellarg($n.'*').' 2>/dev/null')); if($f!==''){$o[]=$r;} } echo json_encode($o);`);
  check('no result photo file exists anywhere under wp-content/uploads', Array.isArray(inUploads) && inUploads.length === 0, JSON.stringify(inUploads));

  await visit(p, `/wp-admin/post.php?post=${ids.nc}&action=edit`);
  const previewLoaded = await p.waitForFunction(() => { const i = document.querySelector('#cc-result-details img'); return !!i && i.complete && i.naturalWidth > 0; }, null, { timeout: 10000 }).then(() => true).catch(() => false);
  check('manager sees the preview of a non-consented photo in the metabox (signed URL)', previewLoaded);
  check('metabox preview is served from the signed route, not wp-content', ((await p.getAttribute('#cc-result-details img', 'src')) || '').includes('/cc-result-photo/'));

  const anon = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const ap = await anon.newPage();
  const publicNames = async () => { await visit(ap, '/results/', 'results'); return ap.textContent('body'); };
  let body = await publicNames();
  check('/results/ shows the verified + consented record', body.includes(names.ok));
  check('/results/ hides verified-but-not-consented', !body.includes(names.nc));
  check('/results/ hides unverified', !body.includes(names.uv));
  const photoImgs = await ap.$$eval('img.results-card__photo', (els) => els.map((e) => ({ alt: e.alt, src: e.getAttribute('src'), current: e.currentSrc })));
  const mine = photoImgs.filter((i) => i.alt.startsWith(TAG));
  check('only the public record shows a photo <img>, with its alt text', mine.length === 1 && mine[0].alt === alts.ok, JSON.stringify(mine));
  await ap.locator(`img[alt="${alts.ok}"]`).scrollIntoViewIfNeeded();
  const loaded = await ap.waitForFunction((alt) => { const i = document.querySelector(`img[alt="${alt}"]`); return !!i && i.complete && i.naturalWidth > 0; }, alts.ok, { timeout: 10000 }).then(() => true).catch(() => false);
  check('the public photo actually renders (signed URL returns image bytes)', loaded);
  const okSrc = new URL(mine[0].src, BASE).pathname;
  check('photo <img> src is a signed /cc-result-photo/ URL', /^\/cc-result-photo\/\d+\/\d+\/[a-f0-9]{64}\.(png|jpg|webp)$/.test(okSrc), okSrc);
  const okRes = await anonGet(anon, okSrc);
  const okHeaders = okRes.headers();
  check('signed URL headers: image type, nosniff, private max-age=300, inline', okRes.status() === 200 && /^image\//.test(okHeaders['content-type']) && okHeaders['x-content-type-options'] === 'nosniff' && okHeaders['cache-control'] === 'private, max-age=300' && /^inline/.test(okHeaders['content-disposition']), JSON.stringify(okHeaders));
  const flipped = okSrc.replace(/\/([a-f0-9]{63})([a-f0-9])\./, (m, head, last) => `/${head}${last === '0' ? '1' : '0'}.`);
  check('tampered signature is a 404', (await anonGet(anon, flipped)).status() === 404);
  check('/results/ is not cacheable by shared caches (private, no-cache)', ((await (await anonGet(anon, '/results/')).headers())['cache-control'] || '') === 'private, no-cache');
  check('non-consented photo URL is 404 for the public', (await anonGet(anon, (await photoState(ids.nc)).url)).status() === 404);
  check('unverified photo URL is 404 for the public', (await anonGet(anon, (await photoState(ids.uv)).url)).status() === 404);
  check('anonymous media REST route is not exposed at all', (await anonGet(anon, '/wp-json/wp/v2/media')).status() === 404);
  check('result CPT is not exposed through REST', (await anonGet(anon, `/wp-json/wp/v2/cc_result/${ids.nc}`)).status() === 404);
  const direct = await anonGet(anon, `/wp-content/uploads/${states.nc.path}`);
  check('the private path is not served under /wp-content/uploads', direct.status() === 404, String(direct.status()));

  // toggle in the admin list
  const toggle = async (title, col) => {
    await visit(p, `/wp-admin/edit.php?post_type=cc_result&s=${encodeURIComponent(title)}`);
    const cell = p.locator(`#the-list tr:has-text("${title}") td.column-cc_${col}`).first();
    await Promise.all([p.waitForNavigation(), cell.locator('a').click()]);
    return txt(p, `#the-list tr:has-text("${title}") td.column-cc_${col}`);
  };
  check('list toggle: consent on for the non-consented record', /^Yes/.test(await toggle(names.nc, 'consent')));
  body = await publicNames();
  check('/results/ now shows the record after consent is turned on', body.includes(names.nc));
  const ncNow = (await photoState(ids.nc)).url;
  check('its photo URL now streams (state is re-read per request)', (await anonGet(anon, ncNow)).status() === 200);
  check('list toggle: verified off for the first record', /^No/.test(await toggle(names.ok, 'verified')));
  body = await publicNames();
  check('/results/ hides the record after verified is turned off', !body.includes(names.ok));
  check('the URL already handed out for it stops working at once', (await anonGet(anon, okSrc)).status() === 404);
  check('a manager can still open it (preview of any state)', (await fx.staffCtx.request.get(`${BASE}${okSrc}`, { failOnStatusCode: false })).status() === 200);
  // logged-in non-managers get exactly the public view
  const ins = await newCtx(browser, 1280, 'instructor-rest');
  await wpLogin(ins.p, fx.instructor);
  check('logged-in instructor gets 404 for the now-hidden photo URL', (await ins.ctx.request.get(`${BASE}${okSrc}`, { failOnStatusCode: false })).status() === 404);
  check('logged-in instructor still gets the public photo', (await ins.ctx.request.get(`${BASE}${ncNow}`, { failOnStatusCode: false })).status() === 200);
  await ins.ctx.close();

  // replace and remove through the metabox: old private files are deleted
  const before = states.uv.path;
  await visit(p, `/wp-admin/post.php?post=${ids.uv}&action=edit`);
  await p.setInputFiles('#cc-result-photo-file', { name: 'qa-uv2.png', mimeType: 'image/png', buffer: png(160, 120, [10, 10, 10]) });
  await p.fill('#cc-result-photo-alt', `${alts.uv} replaced`);
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const replaced = await photoState(ids.uv);
  const gone = (rel) => wpJson(`$b=CC_Photo_Store::base_dir(); echo json_encode(file_exists($b.'/'.${phpStr(rel)}) || file_exists($b.'/'.${phpStr(rel)}.'.webp'));`);
  check('replacing stores a new private file and deletes the old one (with its webp)', replaced?.path && replaced.path !== before && (await gone(before)) === false && (await gone(replaced.path)) === true, JSON.stringify(replaced));
  await visit(p, `/wp-admin/post.php?post=${ids.uv}&action=edit`);
  await p.check('input[name=cc_result_photo_remove]');
  await Promise.all([p.waitForNavigation(), p.click('#publish')]);
  const removed = await photoState(ids.uv);
  check('removing clears the photo and deletes its private files', removed?.path === '' && (await gone(replaced.path)) === false, JSON.stringify(removed));
  const audit = await wpJson(`global $wpdb; echo json_encode(array_map('intval', [$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cc_audit_log WHERE entity_type='result' AND entity_id=%d AND action='result.photo_set'",${ids.uv})),$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cc_audit_log WHERE entity_type='result' AND entity_id=%d AND action='result.photo_replace'",${ids.uv})),$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cc_audit_log WHERE entity_type='result' AND entity_id=%d AND action='result.photo_remove'",${ids.uv}))]));`);
  check('set, replace and remove are each audited once', JSON.stringify(audit) === '[1,1,1]', JSON.stringify(audit));
  await anon.close();
  const m = await newCtx(browser, 375, 'anon-375-results');
  await visit(m.p, '/results/', 'results'); check('/results/ no overflow @375', (await overflow(m.p)) <= 0, String(await overflow(m.p)));
  await m.ctx.close();
}

async function contactSection(browser, fx) {
  section('(5) Contact form (anonymous)');
  const SEND_RESULT = [];
  for (const w of [375, 1280]) {
    const { ctx, p } = await newCtx(browser, w, `anon-contact-${w}`);
    await visit(p, '/contact/', 'contact');
    check(`@${w} contact form is shown`, await p.isVisible('#ct-form'));
    check(`@${w} no horizontal overflow`, (await overflow(p)) <= 0, String(await overflow(p)));
    const branches = await p.$$eval('.ct-branch', (bs) => bs.map((b) => ({ iframe: !!b.querySelector('iframe.ct-map'), sandbox: b.querySelector('iframe')?.getAttribute('sandbox'), lazy: b.querySelector('iframe')?.getAttribute('loading'), title: b.querySelector('iframe')?.getAttribute('title'), tel: !!b.querySelector('a[href^="tel:"]'), text: b.textContent.replace(/\s+/g, ' ').trim().slice(0, 80) })));
    check(`@${w} branch cards with sandboxed lazy map iframes`, branches.length >= 2 && branches.every((b) => !b.iframe || (b.sandbox && !/allow-top-navigation|allow-forms|allow-popups/.test(b.sandbox) && b.lazy === 'lazy' && b.title)), JSON.stringify(branches));
    check(`@${w} phone + address visible`, /Phone:/.test(await txt(p, '.ct-details')) && branches.every((b) => b.text.length > 10));
    if (w === 375) {
      await p.evaluate(() => { document.getElementById('ct-form').scrollIntoView(); });
      // inline validation
      await p.click('#ct-submit');
      const errs = await p.$$eval('.ct-error:not([hidden])', (es) => es.map((e) => e.textContent));
      check('empty submit shows inline errors for name, phone, topic, message', errs.length >= 4, errs.join(' | '));
      check('first invalid field gets focus', await p.evaluate(() => document.activeElement?.id === 'ct-name'));
      check('invalid inputs get aria-invalid', (await p.$$('[aria-invalid=true]')).length >= 4);
      await p.fill('#ct-name', `${TAG} Visitor`); await p.fill('#ct-phone', '12345'); await p.fill('#ct-email', 'not-an-email'); await p.selectOption('#ct-topic', 'admission'); await p.fill('#ct-message', 'too short');
      await p.click('#ct-submit');
      check('bad phone message', /valid Bangladeshi mobile/.test(await txt(p, '#ct-phone-err')));
      check('bad email message', /valid email/.test(await txt(p, '#ct-email-err')));
      check('short message error', /10 to 2000/.test(await txt(p, '#ct-message-err')));
      check('summary announces the problem (role=alert)', /correct the highlighted/.test(await txt(p, '#ct-errors')));
      // honeypot
      const hp = await p.$eval('#ct-hp', (e) => { const r = e.getBoundingClientRect(); return { x: r.right, w: r.width, vis: getComputedStyle(e).visibility, tab: e.tabIndex, hidden: e.closest('[aria-hidden=true]') !== null }; });
      check('honeypot is off-screen / not tabbable', hp.x < 0 && hp.tab === -1, JSON.stringify(hp));
      await p.focus('#ct-name');
      const seen = [];
      for (let i = 0; i < 14; i++) { await p.keyboard.press('Tab'); seen.push(await p.evaluate(() => document.activeElement?.id)); }
      check('Tab order never lands on the honeypot', !seen.includes('ct-hp'), seen.join(','));
      const before = await inquiryCount();
      await p.fill('#ct-phone', freshPhone()); await p.fill('#ct-email', ''); await p.fill('#ct-message', 'Honeypot message from a bot test');
      await p.fill('#ct-name', `${TAG} Bot`);
      await p.evaluate(() => { document.getElementById('ct-hp').value = 'http://spam.example'; });
      await p.click('#ct-submit');
      await p.waitForSelector('#ct-success:not([hidden])', { timeout: 15000 }).catch(() => {});
      check('honeypot submission shows success (bot learns nothing)', await p.isVisible('#ct-success'));
      await sleep(500);
      check('honeypot submission stored NO row', (await inquiryCount()) === before);
    }
    await ctx.close();
  }

  // real submissions at 1280: success, disabled button, double click
  const { ctx, p } = await newCtx(browser, 1280, 'anon-contact-submit');
  const fill = async (name, msg, extra = {}) => {
    await visit(p, '/contact/');
    await p.fill('#ct-name', name); await p.fill('#ct-phone', freshPhone()); await p.selectOption('#ct-topic', extra.topic || 'course');
    await p.fill('#ct-message', msg);
  };
  await fill(`${TAG} Visitor`, 'Hello, I would like to know about the HSC batch timings.');
  const urlBefore = p.url();
  const reqs = []; p.on('request', (r) => { if (r.url().includes('/cc/v1/contact')) reqs.push(r.method()); });
  await p.click('#ct-submit');
  check('submit button disabled right after the first click', await p.isDisabled('#ct-submit').catch(() => true));
  await p.waitForSelector('#ct-success:not([hidden])', { timeout: 15000 });
  check('inline success shown without redirect', p.url() === urlBefore && /Thank you/.test(await txt(p, '#ct-success')));
  check('form hidden after success and focus moved to the success message', !(await p.isVisible('#ct-form')) && (await p.evaluate(() => document.activeElement?.id)) === 'ct-success');
  await sleep(300);
  check('exactly one row for the single submission', (await inquiryCount(`${TAG} Visitor`)) === 1);

  await fill(`${TAG} Dbl`, 'Double click should create exactly one row please.');
  const rowsBefore = await inquiryCount(`${TAG} Dbl`);
  reqs.length = 0;
  await p.dblclick('#ct-submit');
  await p.waitForSelector('#ct-success:not([hidden])', { timeout: 15000 });
  await sleep(600);
  check('double-click sends one request and creates one row', reqs.length === 1 && (await inquiryCount(`${TAG} Dbl`)) === rowsBefore + 1, `requests=${reqs.length}, rows=${await inquiryCount(`${TAG} Dbl`)}`);

  await fill(`=1+1 ${TAG} Formula`, '=1+1 formula-start message for the csv check');
  await p.click('#ct-submit'); await p.waitForSelector('#ct-success:not([hidden])');
  await fill(`রহিম ${TAG} বাংলা`, 'আমি এইচএসসি ব্যাচ সম্পর্কে জানতে চাই। ধন্যবাদ।', { topic: 'admission' });
  await p.click('#ct-submit'); await p.waitForSelector('#ct-success:not([hidden])');
  await fill(`${TAG} Xss`, 'Hello <script>alert(1)</script> world please <b>call</b> me');
  await p.click('#ct-submit'); await p.waitForSelector('#ct-success:not([hidden])');
  const xss = await wpJson(`global $wpdb; echo json_encode($wpdb->get_row($wpdb->prepare("SELECT message,name FROM {$wpdb->prefix}cc_inquiries WHERE name=%s",${phpStr(TAG + ' Xss')}),ARRAY_A));`);
  check('form-submitted HTML/script is stripped before storage', xss && !/<script|<b>/.test(xss.message), JSON.stringify(xss));
  note('rate limit (4th submission/hour/IP) NOT asserted: CC_RATE_LIMIT_DISABLED=1 disables the limiter on this stack');
  await ctx.close();
}
const inquiryCount = async (name = '') => (await wpJson(`global $wpdb; $t=$wpdb->prefix.'cc_inquiries'; echo json_encode((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE name LIKE %s",'%'.$wpdb->esc_like(${phpStr(name || TAG)}).'%')));`)) ?? -1;

async function inquiriesSection(browser, fx) {
  section('(5b) Inquiries admin (staff)');
  const p = fx.staffPage;
  // raw hostile row, inserted directly (the public form strips tags)
  await wp('eval', `global $wpdb; $wpdb->insert($wpdb->prefix.'cc_inquiries',['name'=>${phpStr('<img src=x onerror=alert(1)> ' + TAG + ' Raw')},'phone'=>'+8801712345678','topic'=>'other','message'=>${phpStr('<script>alert(1)</script> raw message ' + TAG)},'status'=>'new','ip_hash'=>'x','created_at'=>gmdate('Y-m-d H:i:s')]);`);
  const newCount = (await wpJson(`global $wpdb; echo json_encode((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cc_inquiries WHERE status='new'"));`));
  await visit(p, adminUrl('cc-dashboard'), 'dashboard');
  const cards = await p.$$eval('.cc-card', (cs) => Object.fromEntries(cs.map((c) => [c.querySelector('h3').textContent.trim(), c.querySelector('.cc-card__value').textContent.trim()])));
  check('dashboard shows a "New inquiries" card equal to the DB count', parseInt(cards['New inquiries'], 10) === newCount, JSON.stringify(cards));
  const bubble = await p.$eval('#adminmenu a[href*="cc-inquiries"] .awaiting-mod', (e) => parseInt(e.textContent, 10)).catch(() => -1);
  check('Inquiries menu bubble equals the new-inquiry count', bubble === newCount, `${bubble} vs ${newCount}`);

  await visit(p, adminUrl('cc-inquiries', `&s=${encodeURIComponent(TAG)}&status=all`), 'inquiries');
  const rows = await p.$$eval('#the-list tr', (trs) => trs.map((t) => t.textContent.replace(/\s+/g, ' ').trim()));
  check('search finds the 6 stored test inquiries (the honeypot one was not stored)', rows.length === 6, `${rows.length}: ${rows.map((r) => r.slice(0, 40)).join(' || ')}`);
  check('staff (has cc_manage_students) sees the full phone number', rows.some((r) => /\+8801\d{9}/.test(r)) && !rows.some((r) => /\*{3}/.test(r)));
  check('hostile row is displayed escaped: no dialog fired and tag text visible', alerts.length === 0 && rows.some((r) => r.includes('<script>alert(1)</script>') || r.includes('<img src=x onerror=alert(1)>')), JSON.stringify(alerts));
  check('no injected <img>/<script> element exists in the list', (await p.$$('#the-list img[src="x"], #the-list script')).length === 0);
  check('Bangla name + message intact in the list', rows.some((r) => r.includes('রহিম') && r.includes('বাংলা')) && rows.some((r) => r.includes('আমি এইচএসসি')));
  check('inquiries list no overflow @1280', (await overflow(p)) <= 0);
  await p.fill('#cc-inquiry-search-input', 'Visitor'); await Promise.all([p.waitForNavigation(), p.click('#search-submit')]);
  check('search narrows results to the matching inquiry', (await p.$$('#the-list tr')).length === 1);
  await visit(p, adminUrl('cc-inquiries', `&s=${encodeURIComponent(fx.visitorPhoneFragment || 'zzzzzznone')}&status=all`));
  check('search with no matches shows the empty state', /No inquiries found/.test(await txt(p, '#the-list')));

  // detail + handled with note
  await visit(p, adminUrl('cc-inquiries', `&s=${encodeURIComponent(TAG + ' Visitor')}&status=all`));
  await Promise.all([p.waitForNavigation(), p.click('#the-list tr:first-child strong a')]);
  check('detail view shows name, message and note form', /Visitor/.test(await txt(p, '.cc-panel h2')) && !!(await p.$('#cc-inquiry-note')));
  await p.fill('#cc-inquiry-note', `Called back ${TAG} नोट`);
  await Promise.all([p.waitForNavigation(), p.click('input[value="Mark handled"]')]);
  check('Mark handled shows "Inquiry updated." and the note is displayed', /Inquiry updated/.test(await txt(p, '.notice')) && (await p.textContent('.wrap')).includes(`Called back ${TAG}`));
  await Promise.all([p.waitForNavigation(), p.click('input[value="Mark spam"]')]);
  check('Mark spam from the detail view works', /Inquiry updated/.test(await txt(p, '.notice')));
  await Promise.all([p.waitForNavigation(), p.click('input[value="Reopen"]')]);
  check('Reopen works', /Inquiry updated/.test(await txt(p, '.notice')));
  const st = await wpJson(`global $wpdb; echo json_encode($wpdb->get_row($wpdb->prepare("SELECT status,handled_by,staff_note FROM {$wpdb->prefix}cc_inquiries WHERE name=%s",${phpStr(TAG + ' Visitor')}),ARRAY_A));`);
  check('after Reopen: status new, handled_by cleared, note kept', st?.status === 'new' && st.handled_by === null && /Called back/.test(st.staff_note || ''), JSON.stringify(st));
  // list row actions
  await visit(p, adminUrl('cc-inquiries', `&s=${encodeURIComponent(TAG + ' Dbl')}&status=all`));
  const r1 = p.locator('#the-list tr').first(); await r1.hover();
  await Promise.all([p.waitForNavigation(), r1.locator('button:text-is("Mark handled")').click()]);
  check('list row action "Mark handled" works (not "The link you followed has expired")', /Inquiry updated/.test(await txt(p, '.notice')), (await txt(p, 'body')).slice(0, 120));
  // Recovery only when the row action really did not mark it handled: the detail view of a handled inquiry has no
  // "Mark handled" button, so keying this off the notice text alone would hang on that click.
  const dblStatus = await wpJson(`global $wpdb; echo json_encode($wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}cc_inquiries WHERE name=%s",${phpStr(TAG + ' Dbl')})));`);
  if (dblStatus !== 'handled') {
    await visit(p, adminUrl('cc-inquiries', `&s=${encodeURIComponent(TAG + ' Dbl')}&status=all`));
    await Promise.all([p.waitForNavigation(), p.click('#the-list tr:first-child strong a')]);
    await Promise.all([p.waitForNavigation(), p.click('input[value="Mark handled"]')]);
  }
  await visit(p, adminUrl('cc-inquiries', '&status=handled'));
  check('Handled tab lists it and the tab count is correct', /Dbl/.test(await txt(p, '#the-list')) && (await p.$$eval('.subsubsub a.current .count', (e) => e.length)) === 1);

  // CSV
  await visit(p, adminUrl('cc-inquiries', `&s=${encodeURIComponent(TAG)}&status=all`));
  const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 20000 }), p.click('a.page-title-action:text-is("Export CSV")')]);
  const csv = fs.readFileSync(await dl.path());
  const text = csv.toString('utf8');
  check('CSV starts with a UTF-8 BOM', csv[0] === 0xef && csv[1] === 0xbb && csv[2] === 0xbf);
  check('CSV neutralises a formula-start name/message (leading apostrophe)', text.includes(`"'=1+1 ${TAG} Formula"`) || text.includes(`'=1+1 ${TAG} Formula`), text.split('\n').find((l) => /Formula/.test(l)));
  check('CSV: no cell starts with a raw = + - @', !text.split('\n').slice(1).some((l) => /(^|,)"?[=+@]/.test(l.replace(/^\d+,/, ''))), 'raw formula cell present');
  check('CSV keeps Bangla intact', text.includes('রহিম') && text.includes('আমি এইচএসসি'));
  check('CSV honours the search filter (only test rows)', text.trim().split('\n').length >= 7 && !/\n\d+,[^\n]*example-unrelated/.test(text));

  // masked phone for a user without cc_manage_students
  const s2 = await newCtx(browser, 1280, 'staff2-inquiries');
  await wpLogin(s2.p, fx.staffNoStudents);
  await visit(s2.p, adminUrl('cc-inquiries', `&s=${encodeURIComponent(TAG)}&status=all`), 'inquiries-masked');
  const mrows = await s2.p.$$eval('#the-list tr', (trs) => trs.map((t) => t.textContent));
  check('role without cc_manage_students sees masked phones (+88017*****xyz)', mrows.length > 0 && mrows.every((r) => /\+8801\d\*{5}\d{3}/.test(r)) && !mrows.some((r) => /\+8801\d{9}/.test(r)), mrows[0]);
  await s2.ctx.close();
  // layout
  for (const w of [768, 375]) { await p.setViewportSize({ width: w, height: 900 }); await visit(p, adminUrl('cc-inquiries', '&status=all'), 'inquiries'); check(`Inquiries list no horizontal overflow at ${w}px`, (await overflow(p)) <= 0, String(await overflow(p))); }
  await p.setViewportSize({ width: 1280, height: 900 });
}

async function branchSection(browser, fx) {
  section('(6) Branch editing (staff)');
  const p = fx.staffPage;
  const title = `${TAG} Branch`;
  const id = parseInt((await wp('post', 'create', '--post_type=cc_branch', `--post_title=${title}`, '--post_status=publish', '--menu_order=99', '--porcelain')).trim(), 10);
  const setMap = async (url) => {
    await visit(p, `/wp-admin/post.php?post=${id}&action=edit`);
    await p.fill('input[name=cc_branch_address]', `${TAG} Road 1, Dhaka`); await p.fill('input[name=cc_branch_phone]', '+880 1700-000099');
    await p.evaluate((u) => { const i = document.querySelector('input[name=cc_branch_map_embed_url]'); i.type = 'text'; i.value = u; }, url);
    await Promise.all([p.waitForNavigation(), p.click('#publish')]);
    return p.inputValue('input[name=cc_branch_map_embed_url]');
  };
  const iframeFor = async () => { const c = await newCtx(browser, 1280, 'anon-branch'); await visit(c.p, '/contact/'); const src = await c.p.$$eval('.ct-branch', (bs, t) => bs.filter((b) => b.textContent.includes(t)).map((b) => b.querySelector('iframe')?.src || null), TAG); const sandbox = await c.p.$$eval('.ct-branch iframe', (is) => is.map((i) => i.getAttribute('sandbox'))); await c.ctx.close(); return { src, sandbox }; };
  for (const bad of ['https://evil.example/x', 'javascript:alert(1)', 'https://www.openstreetmap.org/export/embed.html?bbox=1"onload="alert(1)', 'https://www.google.com.evil.example/maps/embed?pb=1', 'http://www.openstreetmap.org/export/embed.html?bbox=1']) {
    const saved = await setMap(bad);
    const r = await iframeFor();
    check(`map URL ${bad.slice(0, 50)} is dropped on save`, saved === '', `field value after save: "${saved}"`);
    check(`... and no iframe is rendered for it`, r.src.length === 1 && r.src[0] === null, JSON.stringify(r.src));
  }
  const good = 'https://www.openstreetmap.org/export/embed.html?bbox=90.38%2C23.74%2C90.42%2C23.78&layer=mapnik';
  const saved = await setMap(good);
  check('valid OpenStreetMap embed is saved', saved === good, saved);
  const r = await iframeFor();
  check('valid OpenStreetMap embed is rendered in a sandboxed iframe', r.src.includes(good.replace(/&/g, '&')) || r.src.some((s) => s && s.startsWith('https://www.openstreetmap.org/export/embed.html')), JSON.stringify(r));
  check('every rendered map iframe has sandbox + no top navigation', r.sandbox.every((s) => s && !/top-navigation|popups|forms/.test(s)), JSON.stringify(r.sandbox));
}

async function rolesSection(browser, fx) {
  section('(7) Role gating');
  const NOPE = /not allowed to access this page|do not have permission|not allowed to (view|do)|Sorry, you are not allowed/i;
  const targets = [adminUrl('cc-media'), adminUrl('cc-inquiries'), '/wp-admin/edit.php?post_type=cc_article', '/wp-admin/post-new.php?post_type=cc_article', '/wp-admin/edit.php?post_type=cc_gallery_item', '/wp-admin/edit.php?post_type=cc_result', '/wp-admin/edit.php?post_type=cc_branch', '/wp-admin/admin-post.php?action=cc_inquiry_export'];
  for (const [label, user] of [['instructor', fx.instructor], ['student', fx.student]]) {
    const { ctx, p } = await newCtx(browser, 1280, `${label}-gating`);
    await wpLogin(p, user);
    note(`${label} landed: ${p.url()}`);
    for (const t of targets) {
      const res = await p.goto(`${BASE}${t}`, { waitUntil: 'load' }).catch(() => null);
      const body = (await p.content()) || '';
      const blocked = (res && res.status() >= 400) || NOPE.test(body) || !/\/wp-admin\//.test(p.url());
      const leaked = !!(await p.$('#cc-media-drop, .wp-list-table, #cc-inquiry-search-input, #post-body'));
      check(`${label} cannot reach ${t}`, blocked && !leaked, `status=${res && res.status()} url=${p.url()}`);
    }
    if (label === 'instructor') {
      await visit(p, adminUrl('cc-dashboard'));
      check('instructor dashboard does not show the New inquiries card', !(await p.$('.cc-card:has(h3:text-is("New inquiries"))')));
      check('instructor menu has no Media / Inquiries / Blog entries', !(await p.$('#adminmenu a[href*="cc-media"], #adminmenu a[href*="cc-inquiries"], #adminmenu a[href*="cc_article"]')));
      const up = await p.evaluate(async (base) => { const fd = new FormData(); fd.append('action', 'cc_media_upload'); fd.append('ajax', '1'); fd.append('alt', 'x'); const r = await fetch(base + '/wp-admin/admin-post.php', { method: 'POST', body: fd, credentials: 'same-origin' }); return r.status; }, BASE);
      check('instructor POST to the media upload endpoint is refused (403)', up === 403, String(up));
      const rest = await p.evaluate(async (base) => (await fetch(base + '/wp-json/wp/v2/cc_article', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ title: 'x', status: 'publish' }), credentials: 'same-origin' })).status, BASE);
      check('instructor cannot create an article via REST', [401, 403].includes(rest), String(rest));
    }
    await ctx.close();
  }
}

async function accessibilitySection(browser, fx) {
  section('(8) Misc admin layout');
  const p = fx.staffPage;
  for (const w of [768, 375]) {
    await p.setViewportSize({ width: w, height: 900 });
    for (const [label, url] of [['gallery list', '/wp-admin/edit.php?post_type=cc_gallery_item'], ['results list', '/wp-admin/edit.php?post_type=cc_result'], ['blog list', '/wp-admin/edit.php?post_type=cc_article'], ['dashboard', adminUrl('cc-dashboard')]]) {
      await visit(p, url);
      check(`${label} no horizontal overflow at ${w}px`, (await overflow(p)) <= 0, String(await overflow(p)));
    }
  }
  await p.setViewportSize({ width: 1280, height: 900 });
  const pub = await newCtx(browser, 768, 'anon-768');
  for (const u of ['/contact/', '/gallery/', '/results/', '/blog/', `/blog/${fx.main.slug}/`]) {
    await visit(pub.p, u, 'pub');
    check(`${u} no horizontal overflow at 768px`, (await overflow(pub.p)) <= 0, String(await overflow(pub.p)));
  }
  await pub.ctx.close();
}

/* ================================================================ main */

const fx = { users: [] };
const browser = await chromium.launch({ headless: true });
try {
  console.log(`v1 browser e2e against ${BASE}  tag=${TAG}`);
  fx.owner = await mkUser('cc_owner', 'owner');
  fx.staff = await mkUser('cc_staff', 'staff');
  fx.staffNoStudents = await mkUser('cc_staff', 'staffns', { cc_manage_students: false });
  fx.instructor = await mkUser('cc_instructor', 'instr');
  fx.student = await mkUser('cc_student', 'stud');
  fx.users = [fx.owner, fx.staff, fx.staffNoStudents, fx.instructor, fx.student];
  check('fixture users created', fx.users.every((u) => u.id > 0), JSON.stringify(fx.users.map((u) => u.id)));

  await mediaSection(browser, fx);
  await blogSection(browser, fx);
  await gallerySection(browser, fx);
  await resultsSection(browser, fx);
  await contactSection(browser, fx);
  await inquiriesSection(browser, fx);
  await branchSection(browser, fx);
  await rolesSection(browser, fx);
  await accessibilitySection(browser, fx);

  section('Global');
  note(`HTTP >=400 responses seen by browser pages: ${[...new Set(httpErrors)].join(' ; ') || 'none'}`);
  check('no JS console errors / page errors', consoleErrors.length === 0, `\n    ${[...new Set(consoleErrors)].join('\n    ')}`);
  check('no PHP warnings/notices in any rendered page', phpErrors.length === 0, phpErrors.join(' | '));
  check('no unexpected alert() dialogs fired', alerts.length === 0, alerts.join(' | '));
} catch (e) {
  failures++;
  console.log(`  FAIL unexpected exception: ${e.stack || e}`);
} finally {
  await browser.close().catch(() => {});
  await cleanup(fx).catch((e) => console.log(`cleanup error: ${e}`));
  console.log(`\n${failures ? 'FAILED' : 'PASSED'} (${failures} failing checks)`);
  process.exitCode = failures ? 1 : 0;
}
