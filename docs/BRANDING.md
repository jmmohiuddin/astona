# Branding: apply your brand in 15 minutes

The site uses a **neutral placeholder palette** (indigo and amber) and the Inter + Noto Sans Bengali typefaces. No brand has
been chosen or guessed. This guide shows exactly where to put a real brand once the owner supplies it (OQ-12).

All brand-dependent values live in one block: the `:root { ... }` design-token block at the top of
`wp-content/themes/coaching-theme/assets/css/main.css` (lines 8 to 41 at time of writing, commented
`BRAND TOKENS`). Component rules read those tokens, so changing a token restyles the whole site.

Line numbers in this document are as of the day it was written. Other work appends to `main.css`, so search for the selector
if a number has drifted.

## 1. What you need from the owner first

See the question list in section 8. You need at minimum: primary colour, a dark variant, a tint, an accent, a logo file and a
typeface decision.

## 2. Logo (5 minutes)

The header and footer currently show a letter mark ("A" in a rounded square) plus the site name. The site name comes from
**Settings > General > Site Title** in wp-admin.

**Header**: `wp-content/themes/coaching-theme/header.php`, lines 14 to 17:

```php
<a class="brand" href="..." aria-label="... home">
	<span class="brand__mark" aria-hidden="true">A</span>
	<span class="brand__name"><?php bloginfo( 'name' ); ?></span>
</a>
```

**Footer**: `wp-content/themes/coaching-theme/footer.php`, line 7:

```php
<p class="brand brand--footer"><span class="brand__mark" aria-hidden="true">A</span><span class="brand__name">...</span></p>
```

To use a logo image, put the file in `wp-content/themes/coaching-theme/assets/img/` (create the folder) and replace the
`<span class="brand__mark" ...>A</span>` in both files with:

```php
<img class="brand__logo" src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo.svg' ) ); ?>" alt="" width="36" height="36">
```

Keep `alt=""` while the site name text stays next to it (the link already has an `aria-label`). If the logo already contains
the name and you remove the `brand__name` span, set `alt` to the institution name instead. Then add a rule for `.brand__logo`
at the end of `main.css` (for example `.brand__logo { width: auto; height: 36px; }`). The existing `.brand__mark` rule (line 86)
can be left alone or removed.

**Recommended sizes**

| Use | Format | Size |
|---|---|---|
| Header logo | SVG (preferred) or PNG at 2x | Displayed 36 px high. Supply at least 72 px high for PNG. Keep under 20 KB. |
| Footer logo | Same file | The footer is dark (`#0f172a`). Supply a light or one-colour-white version if the logo is dark. |
| Social share image | PNG or JPG | 1200 x 630 px |
| Site Icon | PNG | at least 512 x 512 px, square |

Always set explicit `width` and `height` attributes so the layout does not shift while the image loads.

## 3. Favicon (2 minutes)

Use WordPress itself: **Appearance > Customize > Site Identity > Site Icon**. Upload a square PNG of at least 512 x 512 px.
WordPress writes the icon tags for browsers and phones. No code change is needed.

## 4. Colours (5 minutes)

Edit only the token values in the `:root` block of `main.css`. Do not hunt through component rules.

| Token | What it controls | Used by |
|---|---|---|
| `--c-brand` | Primary colour: buttons, links, focus ring, logo mark, active filters, hero gradient end | Many |
| `--c-brand-dark` | Dark variant: button hover, hero gradient start, tag text | Many |
| `--c-brand-tint` | Very light tint: ghost-button hover, tags | Many |
| `--c-accent` | Accent: the primary button on the hero | Hero |
| `--c-text`, `--c-muted` | Body text, secondary text | Many |
| `--c-line` | Borders and dividers | Many |
| `--c-bg`, `--c-bg-alt` | Page background, alternate sections | Many |
| `--c-ok-bg`/`--c-ok`, `--c-warn-bg`/`--c-warn`, `--c-off-bg`/`--c-off` | Status chips: open, filling fast, closed | Chips |
| `--radius`, `--shadow` | Card corner radius and shadow | Cards |
| `--font`, `--font-display` | Typeface stacks (section 6) | All text |
| `--gutter` | Side padding on small screens | Layout |

**Reserved tokens** (the last group in the block: `--c-on-brand`, `--c-surface`, `--c-accent-hover`, `--c-on-accent`,
`--c-hero-lead`, `--c-hero-eyebrow`, `--c-danger*`, `--c-notice-ink`, `--c-footer-*`) hold the values of literals that are
still hard-coded in component rules. They are **not consumed yet**, so changing them does nothing until the rules in
"Known literals to tokenise" (section 9) are switched to `var(...)`. If your brand changes the hero, error or footer colours,
do that small refactor first (section 9 gives every line).

### WCAG 2.1 AA contrast rules

- Normal text (under 18 pt, or under 14 pt bold): contrast of at least **4.5:1** against its background.
- Large text (18 pt / 24 px and up, or 14 pt / about 19 px bold): at least **3:1**.
- Non-text parts needed to understand the interface (input borders, focus ring, icons that carry meaning): at least **3:1**
  against the neighbouring colour.
- Decorative lines and borders do not have to meet a ratio.
- Never use colour alone to carry meaning (status chips also carry text).

Contrast ratio = (L1 + 0.05) / (L2 + 0.05), where L1 is the lighter and L2 the darker colour's relative luminance. You can
check a pair at any WCAG contrast checker, or with this snippet (Python 3):

```python
def lum(h):
    h = h.lstrip('#'); c = [int(h[i:i+2], 16) / 255 for i in (0, 2, 4)]
    c = [x / 12.92 if x <= .03928 else ((x + .055) / 1.055) ** 2.4 for x in c]
    return .2126 * c[0] + .7152 * c[1] + .0722 * c[2]
def ratio(a, b):
    hi, lo = sorted([lum(a), lum(b)], reverse=True); return (hi + .05) / (lo + .05)
print(round(ratio('#4338ca', '#ffffff'), 2))   # 7.9
```

### Minimum contrast pairs the design relies on

Ratios for the current neutral palette (computed with the formula above). When you change a token, every row that uses it must
still meet the **Required** column.

| Pair (foreground on background) | Tokens | Current | Required |
|---|---|---|---|
| White on brand (primary button, logo mark, active filter) | `#fff` on `--c-brand` | 7.90 | 4.5 |
| Brand on white (links, ghost button text) | `--c-brand` on `--c-bg` | 7.90 | 4.5 |
| Brand on alt background | `--c-brand` on `--c-bg-alt` | 7.55 | 4.5 |
| Brand on tint (ghost button hover) | `--c-brand` on `--c-brand-tint` | 7.07 | 4.5 |
| Brand-dark on tint (tags) | `--c-brand-dark` on `--c-brand-tint` | 10.22 | 4.5 |
| White on brand-dark (button hover, hero start) | `#fff` on `--c-brand-dark` | 11.42 | 4.5 |
| Body text on page | `--c-text` on `--c-bg` | 17.74 | 4.5 |
| Muted text on page | `--c-muted` on `--c-bg` | 7.56 | 4.5 |
| Muted text on alt background | `--c-muted` on `--c-bg-alt` | 7.22 | 4.5 |
| Chip: open | `--c-ok` on `--c-ok-bg` | 6.49 | 4.5 |
| Chip: filling | `--c-warn` on `--c-warn-bg` | 6.37 | 4.5 |
| Chip: closed | `--c-off` on `--c-off-bg` | 6.87 | 4.5 |
| Hero button text on accent | `#1f1300` on `--c-accent` | 8.49 | 4.5 |
| Hero lead text on hero (worst end of gradient) | `#e0e7ff` on `--c-brand` | 6.41 | 4.5 |
| Hero eyebrow on hero (worst end) | `#c7d2fe` on `--c-brand` | 5.30 | 4.5 |
| Footer text on footer | `#cbd5e1` on `#0f172a` | 12.02 | 4.5 |
| Footer muted text | `#94a3b8` on `#0f172a` | 6.96 | 4.5 |
| Error text on page | `#b91c1c` on `--c-bg` | 6.47 | 4.5 |
| Error box | `#7f1d1d` on `#fee2e2` | 8.20 | 4.5 |
| Focus ring on page | `--c-brand` on `--c-bg` | 7.90 | 3 (non-text) |
| Form control border | `#6b7280` on `--c-bg` | 4.83 | 3 (non-text) |

The hero text is placed on a gradient from `--c-brand-dark` to `--c-brand`: check the lighter end (`--c-brand`), because that
is the weakest. A light brand colour (yellow, light green, orange) will fail white-on-brand: pick a darker `--c-brand` and keep
the lighter colour for `--c-accent` or `--c-brand-tint`.

One existing item to know about: the batch card border `#9ca3af` (line 170) is 2.54:1 on white, below the 3:1 non-text
guideline. When tokenising literals, consider darkening it.

After changing colours, also tab through a page to confirm the focus ring is visible on the white, hero and footer areas.
(`.hero :focus-visible, .site-footer :focus-visible` use white; line 55.)

## 5. Radius, shadow, spacing

`--radius` (14 px) sets the card and form corners and `--shadow` the card elevation. Change them in the token block. A sharper
brand might use `--radius: 4px`. Buttons are fully rounded pills by design (`border-radius: 999px` in `.btn`); that is component
CSS, not a token.

## 6. Fonts

The site is self-hosted: no request goes to Google Fonts or any other third party, and the Privacy Policy states this. Keep it
that way.

Current setup:

- Files: `wp-content/themes/coaching-theme/assets/fonts/inter-latin-var.woff2` and `noto-sans-bengali-var.woff2` (variable fonts).
- `@font-face` declarations: top of `main.css`, lines 3 to 6 (Inter, Noto Sans Bengali, and the metric-matched `Inter Fallback`).
- The stack: `--font` in the token block.
- Preload of the Latin file: `wp-content/themes/coaching-theme/functions.php`, lines 83 to 94 (the `wp_head` callback).
  The Bengali face is not preloaded; it loads only when Bangla text appears (`unicode-range`).

To switch to a brand typeface:

1. **Licence.** Use only fonts you may self-host. Fonts from Google Fonts are under the SIL Open Font License (OFL) or Apache
   licence and can be downloaded and self-hosted. Commercial fonts need a web licence that allows self-hosting. Keep the licence
   file with the font in the repo.
2. **Get woff2.** Download the family and convert TTF/OTF to `woff2` (for example with `fonttools`: `pyftsubset Font.ttf
   --flavor=woff2 --unicodes=...`) or use the Latin and Bengali woff2 files from a tool such as google-webfonts-helper. A
   variable font keeps the file count low. Subset to Latin and Bengali to keep each file small (aim for under 60 KB each).
3. **Add the files** to `assets/fonts/`.
4. **Replace the `@font-face` rules** at the top of `main.css`: change `font-family`, the `src: url(...)` file name, the
   `font-weight` range, and keep `font-display: swap`. Keep the `unicode-range` split so Bangla loads only when needed (use the
   Bengali range already in the file).
5. **Update the stack** `--font` (and `--font-display` if headings use a different face), for example
   `--font: "Brand Sans", "Brand Sans Fallback", "Noto Sans Bengali", system-ui, sans-serif;`.
6. **Update the preload** in `functions.php`: change `inter-latin-var.woff2` to your Latin file name. Preload only the one file
   used above the fold.
7. **Check fallback metrics.** The `Inter Fallback` rule (`size-adjust`, `ascent-override` and similar) stops layout shift while
   the font loads. Make an equivalent for the new font (tools such as Fontaine or Capsize compute the numbers) or accept a small
   shift.
8. **Bangla.** Pick a Bangla face deliberately: Latin brand fonts have no Bengali glyphs, so the Bangla text falls through to the
   next font in the stack. Test real Bangla content, including conjuncts (juktakkhor), at body and heading sizes.

`--font-display` is defined (defaults to `--font`) but headings do not use it yet, because component CSS was left unchanged.
To give headings a separate face, add `font-family: var(--font-display);` to the `h1, h2, h3` rule (line 48) once a display
typeface is chosen.

## 7. Verify (2 minutes)

1. Reload the site with the cache cleared. Check the home page, a course page, the admission form, the student login and the
   footer.
2. Run through the contrast table above for any token you changed.
3. Check the page at phone width (375 px).
4. Check the print view of a receipt (`/student/payments/`, Print) if you changed text or border colours.
5. Run `./tests/e2e/smoke.sh` to confirm the pages still return 200.

## 8. Questions for the owner

1. Primary brand colour (hex)? Is there a darker variant, a light tint and an accent colour? Please send the official palette.
2. Logo files: SVG preferred, plus PNG, a version for dark backgrounds, and a square version for the Site Icon.
3. English typeface: do you have a brand font and a web licence, or should we choose an open-licence font?
4. Bangla typeface: a preference, or the same family as English if it supports Bengali? Are there any Bangla logos or wordmarks?
5. Tone of voice: formal, friendly, youthful? Should headlines be in English, Bangla, or both?
6. Is the name "Astona" final in both scripts (the Bangla spelling used in SMS is "আস্তোনা")?
7. Any colours to avoid, or competitors' styles you do not want to resemble?
8. Is there a brand guideline document (PDF) we can follow?

## 9. Known literals to tokenise

These brand-coloured or neutral colour literals remain hard-coded in component CSS (outside the token block). They were not
changed, so the current rendering is identical. Line numbers are in
`wp-content/themes/coaching-theme/assets/css/main.css` as at time of writing.

| Literal | Meaning | Lines | Suggested token |
|---|---|---|---|
| `#fff` | text on brand or dark, and white surfaces | 55, 63, 67, 86, 87, 91, 98, 100, 105, 124, 137, 143, 148, 157, 165, 170, 176, 179, 184, 187, 220, 224, 290, 292, 309, 333, 343, 359, 365, 366, 377 | `--c-on-brand` / `--c-surface` |
| `rgb(255 255 255 / .96)` | sticky header background | 83 | derived from `--c-surface` |
| `rgb(255 255 255 / .12)`, `/ .15`, `/ .35` | translucent white on hero and lightbox | 106, 365, 366 | overlay tokens |
| `#e0e7ff` | hero lead text (a brand tint) | 101 | `--c-hero-lead` |
| `#c7d2fe` | hero eyebrow text (a brand tint) | 107 | `--c-hero-eyebrow` |
| `#1f1300` | text on the accent button | 103 | `--c-on-accent` |
| `#fbbf24` | accent hover | 104 | `--c-accent-hover` |
| `#5b3205` | notice box text | 152 | `--c-notice-ink` |
| `#b91c1c` | error text and borders | 230, 231, 240, 256, 270, 293, 297, 312, 379, 381, 382 | `--c-danger` |
| `#fee2e2`, `#7f1d1d` | error box background and text | 235, 312 | `--c-danger-bg`, `--c-danger-ink` |
| `#6b7280` | form control border | 157 | `--c-control-border` |
| `#9ca3af` | batch card border (fails 3:1, see section 4) | 170 | `--c-control-border` (darker) |
| `rgb(16 24 40 / .08)` | shadow | 179 | `--shadow` variant |
| `#0f172a`, `#cbd5e1`, `#e2e8f0`, `#94a3b8`, `#1e293b` | footer background, text, links, muted text, divider | 182, 182, 183, 185, 189 | `--c-footer-*` |
| `#000` | receipt print border | 284 | none needed (print only) |
| `rgb(0 0 0 / .85)` | lightbox backdrop | 361 | none needed |

The reserved tokens already exist in the `:root` block with the same values, so tokenising a rule is a one-word edit such as
`color: #e0e7ff;` to `color: var(--c-hero-lead);`.

**Other stylesheets**: `wp-content/plugins/coaching-platform/assets/admin.css` and `admin-media.css` style the WordPress admin
screens. They use WordPress admin colours (greys, `#2271b1`, status greens/reds), contain **no brand colours**, and do not need
the brand applied. There are no brand-coloured literals to tokenise in them.

There is no analytics, tracking pixel or third-party font on the site, so branding work does not need to touch consent banners.
