/**
 * Core admin nags share the `.notice` class with the application's own notices: for a user without update_core,
 * core prints `<div class="notice notice-warning update-nag inline">WordPress X is available! Please notify the
 * site administrator.</div>` before the page, so `textContent('.notice')` would read the nag instead of the
 * screen's result notice. appNoticeSelector() rewrites every `.notice` / `.notice-*` class in a selector so it can
 * only match application notices; all other selectors are returned unchanged.
 */
export const CORE_NAG_EXCLUSION = ':not(.update-nag):not(#update-nag):not(.update-message)';

export const appNoticeSelector = (sel) => sel.replace(/\.notice(?:-[\w-]+)?(?![\w-])/g, (cls) => `${cls}${CORE_NAG_EXCLUSION}`);
