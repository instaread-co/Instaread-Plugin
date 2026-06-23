---
name: create-partner
description: Onboard a new Instaread WordPress partner — analyze their article page, pick the right injection strategy, scaffold config.json/plugin.json/styles.css, then release + verify. Use when the user says "create a plugin for <site>", "new partner <site>", "onboard <site>", or pastes a partner article URL/HTML and asks to inject the player.
---

# Create / Onboard a New Partner

End-to-end playbook for adding a new partner to the Instaread WordPress plugin. Produces three files in `partners/<partner_id>/` and ships a GitHub release.

## Inputs you need from the user

1. **Article URL** (a real published post, not the homepage)
2. **partner_id** — directory name + identifier inside this plugin repo (e.g. `boxrox`, `independentng`, `thisdayinbaseball`). Usually the domain without TLD/dots. The user may specify it explicitly — if they do, use exactly that.
3. **publication** — name of the JS bundle at `player.instaread.co/js/instaread.<publication>.js`. **This is OFTEN DIFFERENT from partner_id.** Examples observed:
   - `partner_id: independentng`, `publication: independent` (bundle is `instaread.independent.js`)
   - `partner_id: thisdayinbaseball`, `publication: thisdayinbaseball` (but the site is `thisdayinsport.com` too — uses `dynamic_publication_from_host: true`)
   - `partner_id: hangman`, `publication: ""` (multiple-domain partner, `dynamic_publication_from_host: true`)
   - Most partners: `partner_id == publication` (boxrox, asamnews, sixtyandme, etc.)
   **Always ask the user OR verify the bundle exists at `https://player.instaread.co/js/instaread.<publication>.js` returns HTTP 200 before shipping.** A wrong publication name means the JS bundle 404s and player never loads.
4. **Desired player position** (e.g. "above the first `<p>`", "after the byline", "inside the existing slot")
5. **Style breakpoints** if non-standard (default below)

If the user only gives a URL, infer partner_id from the domain and **confirm the publication name with the user** before releasing.

### Quick bundle-name check
```bash
# Verify the canonical bundle exists before shipping
curl -sI --max-time 10 "https://player.instaread.co/js/instaread.<publication>.js" | head -3
# Expect: HTTP/2 200, content-type text/javascript, non-zero content-length
```

---

## STEP 1 — Analyze the article page

Fetch the article and find the article-body container + first paragraph:

```bash
URL="https://<partner>/<some-article>/"
curl -sS -L --max-time 30 -A "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15" \
  "${URL}?_=$(date +%s%N)$RANDOM" -o /tmp/p.html -w "HTTP %{http_code} size=%{size_download}\n"

# body class (theme + confirm it's a single post)
grep -oE '<body[^>]*class="[^"]+"' /tmp/p.html | head -1

# find the article body wrapper + first <p>
python3 - <<'PY'
import re
html = open('/tmp/p.html').read()
for cls in ['entry-content','post-content','td-post-content','single-content',
            'details-content-wrap','post-content-column','inner-post-entry','article-content']:
    m = re.search(rf'<[a-zA-Z]+[^>]*class="[^"]*\b{cls}\b[^"]*"[^>]*>', html)
    if m:
        print(f'{cls} @{m.start()}: {m.group()[:160]}')
PY
```

**If you get HTTP 403 / "Just a moment..."** → the site is behind Cloudflare bot protection (or NitroPack). You can't fetch it from the shell. Work from the user's pasted DOM/screenshot instead. (See [[cloudflare_cache_verification]] memory.)

Note the theme from the body class — it tells you a lot:
- `wp-theme-soledad`, tagdiv/`td-*`, `wp-theme-boxrox`, `wp-theme-sixtyandme-2020`, smart-mag, morenews-pro → custom themes that wrap the article body in a template container that does NOT appear inside the `the_content` filter string.

---

## STEP 2 — Decide the injection strategy

This is the core decision. Three patterns:

### Pattern A — WordPress-native (empty selector + prepend)
```json
"target_selector": "", "insert_position": "prepend"
```
Use when: the article body inside the template container IS the raw `the_content` output (paragraphs flow directly, no exotic wrapper between container and `<p>`). The player gets prepended to `$content` → lands above the first `<p>`. Simplest, no JS-mover.
Examples: `boxrox`, `bedfordindependentcouk`, `bravewords`.

### Pattern B — Selector + before_element (server-side JS-mover)  ← most common for custom themes
```json
"target_selector": ".<article-body-class> p", "insert_position": "before_element"
```
Use when: the player must land at a specific spot (above first `<p>`, after byline) AND the target is a theme template element (`.td-post-content`, `.entry-content`, `.details-content-wrap`, `.entry-meta`). The selector won't match inside `the_content`, so core falls through to its JS-mover (`inject_with_safe_string_manipulation` → emits an inline `<script>` that finds the selector in the rendered DOM and moves the slot). Supports `before_element`, `after_element`, `prepend`, `append`.
Examples: `asamnews` & `independentng` (`.td-post-content p` / `.entry-content p` + before_element), `hangman` (`.entry-meta` + after_element → player after byline), `sixtyandme` (`.details-content-wrap p` + before_element).

### Pattern C — Footer JS fallback
```json
"target_selector": "", "insert_position": "prepend",
"enable_footer_js_fallback": true,
"footer_js_fallback_selector": ".<article-body-class>"
```
Use when: `the_content` filter does NOT reach our handler at all (theme bypasses it — symptom: plugin meta tag present but ZERO `<instaread-player>` on the page even though the slug isn't excluded). The footer hook (`maybe_inject_via_footer`) runs on `wp_footer`, queries the rendered DOM, and injects. NOTE: footer fallback is **prepend-only**. If you need precise placement use Pattern B.
Examples: `bravewords`, `thisdayinbaseball` (smart-mag + LiteSpeed bypass `the_content`).

### How to choose
1. Default to **Pattern B** for custom-theme partners (covers most cases, exact placement).
2. Use **Pattern A** only if the user just wants "top of article, anywhere is fine" and the body is clean `the_content`.
3. Escalate to **Pattern C** only after observing the symptom (meta tag present, no player). Don't pre-emptively add footer fallback.

See `docs/precise-placement-debugging.md` and [[precise_placement_fix_pattern]] memory for the deep dive.

---

## STEP 3 — Standard config flags (ALWAYS include these)

Every new partner config gets:

```json
{
  "partner_id": "<id>",
  "domain": "<domain.tld>",
  "publication": "<id>",
  "injection_context": "post",
  "injection_strategy": "first",
  "force_disable_logs": true,
  "clear_page_cache_on_upgrade": true,
  "enqueue_remote_player_script_sitewide": true,
  "injection_rules": [ { ...from Step 2... , "exclude_slugs": [...] } ],
  "version": "<fleet-baseline>"
}
```

Why each flag:
- **`force_disable_logs: true`** — silences verbose `[InstareadPlayer]` error_log output. MANDATORY in production (an old build without it filled a partner's disk and caused an outage — see [[plugin_telemetry_system]] / the 2026-05 incident).
- **`clear_page_cache_on_upgrade: true`** — flushes WP-level caches (WP Rocket, LiteSpeed, Autoptimize, etc.) on plugin upgrade so visitors don't get stale HTML. Does NOT clear Cloudflare/NitroPack (see Step 6).
- **`enqueue_remote_player_script_sitewide: true`** — loads `https://player.instaread.co/js/instaread.<publication>.js` via `wp_enqueue_script` in `<head>`, independent of injection path. Without it the `<instaread-player>` element renders but its JS never loads → silent failure. Include this for every new partner.

Default `exclude_slugs` (trim/add per site): `/`, `/privacy-policy/`, `/terms-of-service/`, `/terms-of-use/`, `/about/`, `/about-us/`, `/contact/`, `/contact-us/`, `/advertise/`, `/advertise-with-us/`, `/shop/`, `/cart/`, `/checkout/`, `/my-account/`.

`injection_context`: `"post"` (= `is_single()`, strictly WP posts) for almost all partners. Use `"singular"` for posts+pages+CPTs, `"page"` for pages only.

---

## STEP 4 — Version alignment

Match the current **fleet baseline version** (NOT 1.0.0). As of the last fleetwide release that was `4.7.2`. Check what the fleet is on:

```bash
# what most partners are on right now
for f in partners/*/plugin.json; do python3 -c "import json;print(json.load(open('$f'))['version'])"; done | sort | uniq -c | sort -rn | head
```

Use the most common current version. config.json and plugin.json MUST have the SAME version (the workflow keeps them in sync going forward, but set both when scaffolding).

---

## STEP 5 — Scaffold the three files

Copy from `.claude/skills/create-partner/templates/` and fill in `<id>`, `<domain>`, selector, position, version:

- `partners/<id>/config.json` — see `templates/config.json`
- `partners/<id>/plugin.json` — see `templates/plugin.json`
- `partners/<id>/styles.css` — see `templates/styles.css`

**Standard styles** (player slot height by viewport). Default breakpoint 650px:
```css
@media only screen and (max-width: 649px) { .instaread-player-slot { height: 236px !important; } }
@media only screen and (min-width: 650px)  { .instaread-player-slot { height: 160px !important; } }
```
Adjust heights/breakpoint only if the user specifies.

Validate JSON before committing:
```bash
python3 -c "import json; json.load(open('partners/<id>/config.json')); json.load(open('partners/<id>/plugin.json')); print('valid')"
```

---

## STEP 6 — Commit, release, verify

```bash
git add partners/<id>/ && git commit -m "feat(<id>): initial partner setup"
git pull --rebase origin main && git push origin main

# dispatch the build (creates the release zip + commits regenerated plugin.json)
gh workflow run partner-builds.yml -R instaread-co/Instaread-Plugin \
  -f partner_id=<id> -f version=<version>

# wait + watch
sleep 4
RUN_ID=$(gh run list -R instaread-co/Instaread-Plugin --workflow partner-builds.yml --limit 1 --json databaseId -q '.[0].databaseId')
gh run watch -R instaread-co/Instaread-Plugin "$RUN_ID" --exit-status --interval 3
gh release view <id>-v<version> -R instaread-co/Instaread-Plugin | head -6
```

**Bulk / fleetwide releases must be dispatched SEQUENTIALLY** — concurrent `gh workflow run` calls race on the workflow's `git push` step (~80% fail). One at a time only. See [[fleetwide-release-dispatch]] memory.

Give the partner the install URL:
```
https://github.com/instaread-co/Instaread-Plugin/releases/download/<id>-v<version>/<id>-v<version>.zip
```
→ wp-admin → Plugins → Add New → Upload Plugin → Install → Activate.

---

## STEP 7 — Verify on the live site

After the partner installs (or via force-update), verify. Cache layers will hide changes — bust them:

```bash
# cache-busted fetch (Cloudflare caches by URL)
curl -sS -L -A "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15" \
  "https://<partner>/<article>/?v=<version>&_=$(date +%s%N)" -o /tmp/v.html
grep -oE '<meta[^>]*instaread-version[^>]*>' /tmp/v.html        # expect content="<version>"
grep -c '<instaread-player' /tmp/v.html                          # expect >= 1 (0 if injection is purely client-side JS)
grep -oE 'instaread\.[a-z]+\.js' /tmp/v.html                     # the publication bundle
```

Force-update a registered/cron-bound site without waiting 12h (open in a BROWSER, not curl — Cloudflare blocks curl):
```
https://<partner>/?instaread_force_update_check=<partner_id>&_=test123
```

Confirm install via telemetry:
```bash
curl -sS https://player-api.instaread.co/api/plugin-telemetry | \
  python3 -c "import json,sys; rows=[r for r in json.load(sys.stdin) if r['partner_id']=='<id>']; print(rows[-3:])"
```

### Why visitors still see the old version after install

After the plugin updates, **regular visitors keep seeing OLD HTML** for hours because of edge HTML caches (Cloudflare, Ezoic, NitroPack). Compare these two fetches:

```bash
# what real visitors see (cached)
curl -sI -A "Mozilla/5.0" "https://<partner>/<article>/" | grep -iE "cf-cache-status|age|instaread-version"
# expect: cf-cache-status: HIT, age: <large>, meta tag with OLD version

# what origin currently emits (fresh)
curl -sI -A "Mozilla/5.0" "https://<partner>/<article>/?_=$(date +%s%N)" | grep -iE "cf-cache-status|age"
# expect: cf-cache-status: MISS, fresh version
```

If origin already serves the new version but visitors get old HTML, **the partner admin must purge their CDN**:
- Cloudflare dashboard → Caching → Configuration → **"Purge Everything"** (or purge the specific URL).
- Ezoic dashboard → Speed → CDN Settings → **"Clear cache"**.
- NitroPack: wp-admin → NitroPack → Purge Cache (or Pagely dashboard for drop-in mode).
- Without purge, visitors get the update gradually as cache entries expire (1–4 hours typical TTL).

`clear_page_cache_on_upgrade: true` in our config flushes WP-level caches (WP Rocket / LiteSpeed / Autoptimize) but NOT external CDNs — we don't have credentials for them.

In your handoff message to the partner, always include the cache-purge step.

### Cache-buster query params per CDN (verify fast without waiting for TTL)

When a partner reports "still not seeing it," before asking them to purge — try a cache-buster URL in the browser. If the player appears with the buster but not without, it's pure cache staleness; the fix is already deployed.

| Partner uses | Cache-buster that bypasses it | Notes |
|---|---|---|
| Cloudflare | `?_=12345` (any unknown query) | Default behavior; respects query string |
| **Ezoic** | **`?_ez=1`** | Ezoic's internal bypass; confirmed working on **thecoastnews** (PenNews + Cloudflare + Ezoic stack) |
| NitroPack | `?nocache=1` | Plus `Cache-Control: no-cache` header |
| Generic / any | `?cb=$(date +%s)` | Works against most edge caches |

**TTL expectations** (typical, before natural expiry):
- Cloudflare HTML: 1–4 hours
- Ezoic edge (level 1): ~2 hours (see `ezcache_level` in their `_ezaq` telemetry block)
- NitroPack: 24h+ (must be manually purged in most cases)

**Detecting Ezoic** in pasted HTML: look for `__ez`, `_ezaq`, `ezoicTestActive`, or `edge_cache_status` in `<script>` tags. Cookies prefixed `ezoab_`/`ezoadgid_`/`active_template` are also Ezoic.

---

## Known gotchas (check these when something looks broken)

| Symptom | Cause | Fix |
|---|---|---|
| `curl` returns 403 / "Just a moment..." | Cloudflare bot challenge | Use a browser; work from pasted DOM. [[cloudflare_cache_verification]] |
| Site shows old version after update | Cloudflare / Ezoic / NitroPack HTML cache | Cache-bust URL to verify: `?_=12345` (Cloudflare), `?_ez=1` (Ezoic), `?nocache=1` (NitroPack). If player appears with buster, ask partner to purge their CDN |
| Player script loads from `cdn-*.nitrocdn.com` not `player.instaread.co` | NitroPack proxies scripts; ignores `data-no-optimize` | Core emits `data-nitro-exclude` (added v4.7.3). Ensure partner is on a build that has it + purge NitroPack |
| After plugin update, `x-nitro-cache: HIT` + `x-nitro-rev:` stays the same | NitroPack still serves pre-update cached HTML. Core's auto-purge (v4.7.4+) tries 4 NitroPack PHP entry points but doesn't reach Pagely's drop-in NitroPack mode (`x-nitro-cache-from: drop-in`) | **DO NOT ship more guesses** — Pagely-drop-in NitroPack uses an undocumented API. Ask partner to purge ONCE manually from wp-admin → NitroPack → Purge Cache (or Pagely dashboard). After that, v4.7.3+ HTML emits `data-nitro-exclude` so future cache rebuilds stay clean |
| `<instaread-player>` tag present but no audio player renders | Publication bundle (`instaread.<pub>.js`) hybrid-markup handling, OR JS bundle didn't load | Check `enqueue_remote_player_script_sitewide: true`; check console for bundle logs. Bundle source: `prod-projects/Instaread Website/player-ui/src/main/webapp/js/instaread.<pub>.js` |
| TWO players on the page | Publication bundle builds its own `.playerContainer` AND ignores the plugin's `.instaread-player-slot` | Patch the bundle to reuse `.instaread-player-slot` (see lakersnation fix) |
| Plugin loaded (meta tag) but ZERO `<instaread-player>` anywhere | Theme bypasses `the_content` filter | Switch to Pattern C (footer JS fallback) |
| Slot+player tag IN DOM, but iframe doesn't render. `instaread.<pub>.js` missing from network. Combined-js file `siteground-optimizer-combined-js-*.js` is in page. | **SiteGround Optimizer** "Combine JavaScript Files" inlined our bundle into the combined file, then dropped the original cross-origin `<script src="player.instaread.co/...">` from the rendered HTML. Often the combined file has a syntax error (duplicate-identifier from minify collisions) and ALL site JS dies. v4.7.6–v4.7.9 layered attempts (data-no-combine, script_loader_tag, raw echo on wp_footer, createElement IIFE) all failed because SG's HTML-output rewriter still saw the URL. v4.7.10+ pushes exclusions to the **real** SG filter names (we'd been pushing to a non-existent filter for years). | Ensure partner is on v4.7.10+. After upgrade, hit force-update URL + visit any wp-admin page to fire `clear_page_cache_on_upgrade` which purges SG. Verify: combined-js file no longer contains `class InstareadPlayer`, network tab shows `instaread.<pub>.js` direct from `player.instaread.co`. See SiteGround Optimizer section below. |
| WordPress perpetually shows "Update available" | config.json version lagged plugin.json version | Workflow now syncs both; ensure config.json version == plugin.json version |
| `sites_notified: 0` in webhook | Site never sent telemetry (not registered) | Normal for brand-new partners; first install registers it. Update arrives via 12h WP cron until then |

## SiteGround Optimizer ("sg-cachepress" plugin) — JS Combine gotcha

SG Optimizer is shipped pre-installed on SiteGround-hosted WordPress sites. When the **"Combine JavaScript Files"** setting is ON, SG concatenates enqueued JS into `wp-content/uploads/siteground-optimizer-assets/siteground-optimizer-combined-js-<hash>.js` and strips the originals from the rendered HTML. This includes our cross-origin `instaread.<pub>.js` bundle — which SG inlines into the combined file (so it goes stale on player.instaread.co updates) and sometimes also produces a syntax-error combined file that kills ALL site JS.

### The exclusion filter trap (fixed in v4.7.10)

The plugin used to push exclusions to a filter named `sgo_javascript_combine_excluded` (with trailing `d`). **That filter does not exist in SG's code.** v4.7.6–v4.7.9 layered escalating workarounds (data-no-combine attr, `script_loader_tag` filter, raw `<script>` echo on wp_footer, dynamic createElement IIFE) — all failed for the same reason: SG's URL scanner caught the URL no matter how we emitted the tag.

The **real** filter names — reverse-engineered from `plugins.svn.wordpress.org/sg-cachepress/trunk/core/Combinator/Js_Combinator.php`:

| Filter | Excludes by |
|---|---|
| `sgo_javascript_combine_exclude` (no `d`) | Script HANDLES |
| `sgo_javascript_combine_exclude_ids` | Script IDs (e.g. `instaread-remote-player-js`) |
| **`sgo_javascript_combine_excluded_external_paths`** | **Cross-origin URL fragments** ← use this for `player.instaread.co` |
| `sgo_javascript_combine_excluded_internal_paths` | Same-origin URL fragments |
| `sgo_javascript_combine_excluded_inline_content` | Markers to skip inline scripts (e.g. `instaread-player-slot`) — important because SG's combine breaks `document.currentScript.previousElementSibling` semantics |
| `sgo_javascript_async_exclude` | Skip from async/defer |

v4.7.10 pushes to all of these correctly. Older versions silently fail — exclusion does nothing.

### Verifying the fix worked

After install + cache purge:

```bash
# 1. Confirm bundle now loads from player.instaread.co directly (not combined)
curl -s -A "Mozilla/5.0 ... Safari/605" "https://<partner>/<article>/?cb=$(date +%s)" | \
  grep -oE '<script[^>]*src="[^"]*instaread[^"]*"'
# expect: <script ... src="https://player.instaread.co/js/instaread.<pub>.js">

# 2. Confirm SG combined-js file no longer contains our bundle
COMBINED=$(curl -s "...?cb=$(date +%s)" | grep -oE 'siteground-optimizer-combined-js-[a-f0-9]+\.js' | head -1)
curl -s "https://<partner>/wp-content/uploads/siteground-optimizer-assets/$COMBINED" | grep -c 'class InstareadPlayer'
# expect: 0
```

### Diagnosing SG combine syntax errors

If combined-js throws on parse, ALL combined scripts die — not just ours. Use Node to validate:

```bash
node -e "try { new Function(require('fs').readFileSync('/tmp/sg-combined.js','utf8')); console.log('OK'); } catch(e) { console.log('ERROR:', e.message); }"
# Common error: "Identifier 'Zn' has already been declared" — SG's per-file minifier
# produces colliding short names across files. Not our bug to fix, but visible
# blast radius: the entire site's JS may be broken until partner disables combine.
```

If the partner reports widespread JS breakage, recommend they toggle **SG Optimizer → Frontend → JavaScript → "Combine JavaScript Files"** OFF. After v4.7.10+ install + SG cache purge, our player works whether combine is on or off.

### Detection in the plugin

`is_siteground_optimizer_active()` ([core/instaread-core.php:1244](core/instaread-core.php#L1244)) detects SG via (in order):
1. `function_exists('sg_cachepress_purge_cache')` — canonical SG helper
2. `class_exists('SiteGround_Optimizer\Loader\Loader')` — main class
3. `is_plugin_active('sg-cachepress/sg-cachepress.php')` — plugin slug
4. Option `siteground_optimizer_combine_javascript === '1'`

When SG is detected, the bundle is enqueued normally (with proper exclusions in place) AND a dynamic-load IIFE is emitted as a safety net on wp_footer. The IIFE self-skips if a `<script src="player.instaread.co/...">` is already in the DOM.

## Related references
- `docs/precise-placement-debugging.md` — selector/placement decision tree + diagnostics
- `docs/partner-release-operations.md` — day-to-day release ops
- `docs/repo-transfer-runbook.md` — repo/host migration
- Memories: [[precise_placement_fix_pattern]], [[wordpress_injection_rule]], [[cloudflare_cache_verification]], [[fleetwide-release-dispatch]]
