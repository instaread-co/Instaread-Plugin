# Partner Config Reference

Complete reference for every key in `partners/<partner_id>/config.json`.

This file is the single source of truth for partner-specific behavior. The plugin reads it at request boot time. Keys are grouped by purpose. Anything not listed here is silently ignored by the plugin code.

---

## Identity

### `partner_id` (string, required)
The slug used everywhere — file paths, GitHub release tags, telemetry events, the remote JS URL (`player.instaread.co/js/instaread.{publication}.js`). Must match the directory name under `partners/`.

### `domain` (string, informational)
The partner's primary domain. Not read by the plugin — it exists for human reference and external tooling.

### `publication` (string, required)
The publication identifier embedded in the player web component's `publication` attribute and used to construct the remote JS URL. Usually equal to `partner_id`.

### `version` (string, required)
The currently-released plugin version. Used by:
- The auto-update checker — when GitHub's `plugin.json` advertises a higher version, WordPress upgrades the plugin
- Telemetry emails to report `oldVersion → newVersion`
- The `data-instaread-version` HTML attribute

Must match the version in the partner's `plugin.json`.

---

## Where the player is injected

### `injection_context` (string, default: `"singular"`)
Which page types should get the player. One of:

| Value | Matches WP function |
|---|---|
| `"post"` or `"single"` | `is_single()` — only standard `post` post type |
| `"singular"` | `is_singular()` — posts + pages + custom post types |
| `"page"` | `is_page()` — only pages |
| `["post", "review", ...]` (array) | `is_singular()` AND post type is in the array |

Use `"post"` for news sites that should only show players on articles, not on `/about`, `/contact`, etc.

### `injection_rules` (array, required)
Ordered list of injection attempts. The plugin tries each rule in order; the first rule that successfully modifies the content wins. Each rule is an object with:

- **`target_selector`** (string) — A CSS selector to inject relative to. Empty string `""` means "don't search, inject at the very top/bottom of `the_content` directly" (the WordPress-native fast path — recommended for most WP partners).
- **`insert_position`** (string) — Where to inject relative to the target. One of: `"prepend"`, `"append"`, `"before_element"`, `"after_element"`, `"inside_first_child"`, `"inside_last_child"`.
- **`exclude_slugs`** (array of strings) — URL paths where injection should be skipped (e.g. `["/about", "/contact"]`). Slugs are normalized — leading slash optional, trailing slash stripped.

### `injection_strategy` (string, optional)
**Currently unused by the plugin code** — `injection_rules` ordering is what actually controls strategy. Keeping the key in configs for forward compatibility doesn't hurt; the plugin ignores it.

### `fallback_injection` (boolean, default: `true`)
Controls what happens when none of the `injection_rules` selectors match the content:

- `true` (default) — Falls back to injecting the player at top/bottom of content using the first rule's `insert_position`, plus a JS "mover" snippet that re-locates the player to the target selector at runtime
- `false` — Skips injection entirely. Use when "no injection at all" is better than "injection in the wrong place" (e.g. partners with strict layout requirements)

### `the_content_priority` (integer, default: `PHP_INT_MAX - 1`)
WordPress filter priority for the `the_content` injection hook. Default runs very late so other plugins (Social Warfare, related-posts, etc.) finish modifying content before us.

Override per partner when:
- A high-priority plugin/theme rebuilds content from raw post meta after our injection (wipes our changes) — use `99` to run earlier
- A page cache snapshots content before our late injection runs

**Granitegrok uses `99`** — late priority caused content rebuilders to strip our injection.

---

## Time-scoped injection (new articles only + manual back-catalogue)

Two **opt-in, per-partner** keys let a partner start the player on **articles published from the install date forward**, while still turning it on for **specific older articles** by URL. Both default OFF — a partner without these keys behaves exactly as before (player on every matching post). Added for **aconsciousrethink** (July 2026).

### `inject_from_install_date` (boolean, default: `false`)
When `true`, the player is only auto-injected on posts whose **publish date is on or after** the day this partner first activated the plugin. Older posts are skipped by the auto path (reach them via `manual_include_enabled` below).

**How the cutoff is stored/compared:**
- On activation, `on_plugin_activated()` writes `add_option('instaread_install_date_gmt', current_time('mysql', true))` — recorded **once** (never overwritten on re-activation/upgrade), in **GMT**.
- The gate (`passes_install_date_gate()` in `core/instaread-core.php`) compares `$post->post_date_gmt` (from the WordPress DB `wp_posts` table — **not** scraped from the DOM) against that option.
- Both values are GMT, so the comparison is timezone-safe: `strtotime($post->post_date_gmt) >= strtotime($install_date)`.

**Fail-open safeguards (never block injection on a data gap):**
- Config key absent → gate returns `true` (no effect for other partners).
- `instaread_install_date_gmt` missing (e.g. partner **upgraded into** this version instead of a clean activation, so the activation hook never fired) → lazily backfilled to "now" so the cutoff is stable from that point forward.
- Post has no reliable `post_date_gmt` → returns `true` (inject rather than silently drop).

### `manual_include_enabled` (boolean, default: `false`)
When `true`:
1. A **WP admin field** appears at *Settings → Instaread Player* titled **"Enable Player on Older Article URLs"** (a textarea, one URL per line). Registered conditionally in `register_settings()`; hidden for partners who don't opt in.
2. Any request whose path matches one of those URLs gets the player **regardless of the install-date gate**.

Admin input is stored in the `instaread_settings` option under `manual_include_urls` (string), split into a list by `get_settings()`, and preserved by `sanitize_settings()` (must stay listed there or a Save would wipe it). URLs are matched on **path only** — full URL or bare path both work; `http/https`, `www`, and trailing slashes are normalized away (same logic as `suppress_urls`, just inverted).

### Priority order inside `should_inject()`
The gates are evaluated top-to-bottom; the **first** decision wins:

| Order | Check | Result if matched |
|---|---|---|
| 1 | `suppress_body_classes` / `suppress_urls` | **Block** (suppress always wins, even over manual-include) |
| 2 | `manual_include_urls` | **Inject** — overrides the date gate (this is how old articles are enabled) |
| 3 | `inject_from_install_date` gate | Skip posts older than install date |
| 4 | `injection_context` (post/page/…) | Existing context logic — unchanged |

So: **suppress > manual-include > install-date > context.** A URL listed in *both* suppress and manual-include stays suppressed (safety: "off" beats "on").

### Example (aconsciousrethink)
```json
{
  "injection_context": "post",
  "inject_from_install_date": true,
  "manual_include_enabled": true
}
```
Result: articles published after install auto-get the player; the partner pastes URLs of pre-install articles into the admin field to enable those too.

### When changing this later
- The install-date is **frozen at first activation** — deactivating/reactivating does **not** reset it (`add_option` is a no-op if the option exists). To re-baseline intentionally, delete the `instaread_install_date_gmt` option.
- Injection decisions run **server-side in PHP** before the DOM exists — do not confuse this date with the partner JS `publishedDateSelector`, which scrapes the DOM for **audio metadata** and has nothing to do with whether the player is injected.

---

## Footer JS fallback (last-resort injection)

For sites where the `the_content` filter is bypassed entirely by the theme (some block themes, custom page builders, headless setups), the player can be injected client-side at `wp_footer`.

### `enable_footer_js_fallback` (boolean, default: `false`)
Opt-in switch. When `true`, the plugin emits an inline `<script>` in `wp_footer` that:
1. Finds the configured DOM element via `document.querySelector(...)`
2. Prepends the player slot as the first child
3. No-ops if a player slot already exists in that element (prevents duplicate injection when `the_content` injection succeeded too)

Use this when server-side `the_content` injection is unreliable on a partner site. Confirmed needed for: **granitegrok**.

### `footer_js_fallback_selector` (string, default: `".entry-content"`)
CSS selector for `document.querySelector` in the fallback script. Should match the article-body wrapper in the rendered DOM. Common values: `.entry-content`, `.post-content`, `article .content`.

---

## Cache invalidation

### `clear_page_cache_on_upgrade` (boolean, default: `false`)
On the first request after a plugin upgrade, calls cache-clearing APIs for known caching plugins so visitors don't get stale HTML missing the new player.

When `true`, runs (if the corresponding plugin is active):
- WP Rocket: `rocket_clean_domain()`, `rocket_clean_files(home_url('/'))`, `flush_rocket_htaccess()`, plus `rocket_clean_post()` for the 50 most recent posts
- Always tries to clear minify caches: WP Rocket JS minify, Autoptimize, W3 Total Cache minify, LiteSpeed CSS/JS, Swift Performance assets

**Does NOT clear Cloudflare HTML cache** — that's an out-of-band concern. For Cloudflare-fronted sites, a manual purge is still required after major changes.

---

## Player rendering

### `playerType` (string, optional)
Value placed in the `playertype` attribute on `<instaread-player>`. Partner-specific.

### `color` (string, default: `"#59476b"`)
Hex color for the player accent. Set on the `color` attribute of `<instaread-player>`.

### `slot_css` (string, default: `"min-height:144px;"`)
Inline CSS applied to the `<div class="instaread-player-slot">` wrapper. Use to constrain dimensions, set background, etc.

### `isPlaylist` (boolean, default: `false`)
When `true`, renders the playlist player (`<instaread-player p_type="playlist">` plus `instaread.playlist.js`) instead of the single-article player.

### `playlist_height` (string, default: `"80vh"`)
Height for the playlist container. Only used when `isPlaylist: true`.

---

## Remote JS strategies

### `enqueue_remote_player_script_sitewide` (boolean, default: `false`)
When `true`, the publication's remote JS (`player.instaread.co/js/instaread.{publication}.js`) is enqueued on **every front-end page**, not just article pages. Required for floating/persistent players that follow the user across the site.

When `false`, the JS only loads on pages where the player is actually injected.

### `use_player_loader` (boolean, default: `false`)
When `true`, emits the stable `instaread.playerv3.js` loader instead of `instaread.{publication}.js`. The loader fetches the publication bundle at runtime, so partners can pin SRI integrity to `playerv3.js` and avoid integrity mismatches when the publication bundle updates.

### `player_loader_url` (string, optional)
Overrides the default loader URL when `use_player_loader: true`. Instead of `https://player.instaread.co/js/instaread.playerv3.js`, the plugin will enqueue and emit whichever URL is set here.

Use this when a partner needs a different loading chain. For example, `swimmingworldmagazine` uses:
```json
"use_player_loader": true,
"player_loader_url": "https://instaread.co/js/instaread.player.js"
```
This loads `instaread.player.js` (the Web Component definition), which then auto-triggers `instaread.{publication}.js` via its `addScript()` call inside `connectedCallback()` — once the `<instaread-player>` element is in the DOM.

Falls back to `https://player.instaread.co/js/instaread.playerv3.js` if `use_player_loader: true` but `player_loader_url` is absent.

> **Required: `styles.css` with explicit height rules**
> When `player_loader_url` points to `instaread.player.js`, the player slot has no intrinsic height — the iframe is cross-origin and cannot auto-size itself. You **must** ship a `styles.css` in the partner directory with responsive `min-height`/`height` rules on `.instaread-player-slot`, otherwise the player collapses to 0px and is invisible.
>
> When using the default `playerv3.js` flow, the publication-specific JS handles sizing itself and `styles.css` is optional.

### `dynamic_publication_from_host` (boolean, default: `false`)
When `true`, derives the publication slug from the request host's first DNS label (e.g. `news.example.com` → `news`). Use for multi-tenant partners that serve many sub-brands from one WordPress install.

---

## Body-class suppression

### `suppress_body_classes` (array of strings, default: `[]`)
List of WordPress body classes that, if present, suppress player injection on the page. Useful for custom post types that share URL prefixes with regular posts.

Example: A site has both `/author/john` (a real `post` listing) and `/author/jane` (a `post-author` CPT singular). Both pass `is_singular()` but only the second has body class `single-post-author`. Setting `"suppress_body_classes": ["single-post-author"]` suppresses injection on the CPT.

The remote JS enqueue (`enqueue_remote_player_script_sitewide`) is **not** affected by this — only the slot injection is. Floating players still load on suppressed pages.

---

## Verifying a deployed version

### From the command line

Every front-end page emits a `<meta>` tag with the deployed version:

```bash
curl -s https://granitegrok.com/ | grep instaread-version
```

Expected output:
```html
<meta name="instaread-version" content="4.4.6" data-partner="granitegrok">
```

This works on the homepage, archive pages, and articles — anywhere the plugin loads on the front-end. No telemetry dependency, no admin access required.

### Detecting how the player was injected

On article pages, the player slot itself is also tagged:

```bash
curl -s https://granitegrok.com/some-article | grep -oE 'data-instaread-(version|source)="[^"]*"'
```

Possible outputs:

- `data-instaread-version="4.4.6"` (no `data-instaread-source` attribute) — Server-side injection via `the_content` filter worked normally. This is the healthy default.
- `data-instaread-version="4.4.6" data-instaread-source="footer-js-fallback"` — `the_content` injection was bypassed and the JS footer fallback rescued it. Indicates a theme/plugin is interfering with normal injection on this site.

### Confirming an auto-update worked

After an update is triggered, two independent signals confirm success:

1. **Telemetry email**: subject `Plugin Updated — <partner> (<partner>) v<X.Y.Z>` with `SITE URL: https://<partner-domain>`. May not arrive if the partner host blocks outbound HTTP from PHP — check method 2 if absent.
2. **Meta tag check**: `curl -s https://<partner-domain>/ | grep instaread-version` shows the new version. This is the authoritative ground truth — if the meta tag updated, the install succeeded regardless of telemetry.

If telemetry is silent but the meta tag shows the new version, the install worked and the telemetry POST was blocked at the partner site (firewall, security plugin, egress rules). The plugin is fine; only diagnostic email delivery is degraded.

### Manually triggering an update

If a partner site missed an automatic webhook ping (audio-processor doesn't have it in the DB, or webhook delivery failed), trigger the update directly:

```bash
curl -s -A "Instaread-Update-Checker/1.0" \
  "https://<partner-domain>/?instaread_force_update_check=<partner_id>&_=$(date +%s%N)"
```

The unique `_=` query string forces Cloudflare to bypass cache and reach origin PHP. Expected response: HTTP 200 with a 1–4 second response time (PHP execution). Then wait ~30 seconds and re-check the meta tag.

### Detecting a failed install

When `Plugin_Upgrader::install()` returns a `WP_Error` (file permissions, ZIP extraction failed, host policy blocks file modifications, etc.), the plugin sends an `update_failed` telemetry event instead of a successful one. Look for emails with that event type, or check the partner's PHP error log for `[InstareadPlayer] Auto-update FAILED for <partner>`.

---

## Example: granitegrok config (annotated)

```json
{
  "partner_id": "granitegrok",
  "domain": "granitegrok.com",
  "publication": "granitegrok",

  "enqueue_remote_player_script_sitewide": true,
  "injection_context": "post",
  "the_content_priority": 99,
  "injection_strategy": "first",
  "clear_page_cache_on_upgrade": true,
  "fallback_injection": false,
  "enable_footer_js_fallback": true,
  "footer_js_fallback_selector": ".entry-content",

  "injection_rules": [
    {
      "target_selector": "",
      "insert_position": "prepend",
      "exclude_slugs": ["/", "/about/", "/contact/"]
    }
  ],

  "version": "4.4.6"
}
```

What each line does for granitegrok:

- `enqueue_remote_player_script_sitewide: true` — the publication JS loads on every page, not just articles (floating-player support)
- `injection_context: "post"` — only inject on standard WP posts
- `the_content_priority: 99` — early priority instead of `PHP_INT_MAX - 1`, to dodge a content-rebuilder on this site
- `injection_strategy: "first"` — informational, ignored by code
- `clear_page_cache_on_upgrade: true` — purge known WP cache plugins on upgrade
- `fallback_injection: false` — if selector matching fails, do nothing (don't inject in a wrong place)
- `enable_footer_js_fallback: true` — when `the_content` injection is bypassed entirely, inject via `wp_footer` JS
- `footer_js_fallback_selector: ".entry-content"` — the JS fallback targets `.entry-content` in the rendered DOM
- `injection_rules[0].target_selector: ""` — don't search, just prepend at the top of `the_content`
- `injection_rules[0].insert_position: "prepend"` — at the very start of the content
- `injection_rules[0].exclude_slugs: [...]` — never inject on these listed paths

This particular combination is needed because granitegrok's theme bypasses `the_content` for the main post body — only the footer JS fallback reliably injects the player. The server-side rules are kept as a defense-in-depth layer in case the theme is ever fixed.
