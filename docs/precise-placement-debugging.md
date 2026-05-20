# Precise Player Placement on WordPress Sites

How to diagnose and fix partner sites where the player needs to render at a specific DOM position (not just "anywhere in article body").

Written from the 2026-05-20 bravewords + hangman debug session. Covers the lessons from the moonandspoonandyum and granitegrok cases too.

---

## When to use this guide

You're here if a partner says "the audio player isn't showing up" OR "the player is in the wrong place" AND the partner site:

- Runs WordPress with a theme that has `<header class="entry-header">`, `<ul class="entry-meta">`, or similar **template containers** wrapping the article body
- Is fronted by Cloudflare (most are)
- May or may not have aggressive ad/SEO plugins (Yoast Premium, AdInserter, Jetpack, etc.)

If they just need the player at the top of the article body and don't care about precise placement, use the standard [WordPress-native injection](partner-config-reference.md#wordpress-injection-rule) pattern instead (empty `target_selector` + `prepend`).

---

## Quick decision tree

```
Partner needs player at SPECIFIC DOM position?
├── No  → use empty target_selector + insert_position: prepend
│         (player at top of .entry-content, anywhere is fine)
│
└── Yes → does the_content filter reach our handler reliably?
          ├── Yes → use target_selector + insert_position
          │         (e.g. ".entry-meta" + "after_element")
          │         + enqueue_remote_player_script_sitewide: true
          │
          └── No  → use enable_footer_js_fallback (currently prepend-only)
                    + footer_js_fallback_selector
                    + enqueue_remote_player_script_sitewide: true
```

---

## The three flags you'll reach for

| Flag | What it does | When to enable |
|---|---|---|
| `target_selector` + `insert_position` | Tells `inject_server_side_player()` where to put the player. If selector doesn't match in `the_content`, falls through to an inline JS mover (supports all 4 positions). | Default — set this to control placement |
| `enable_footer_js_fallback` | Adds a `wp_footer` JS injection path that runs even when `the_content` filter doesn't reach our handler at all. Currently **prepend-only**. | When `the_content` is bypassed by the theme entirely (smart-mag + Litespeed is one observed case) |
| `enqueue_remote_player_script_sitewide` | Loads `instaread.{publication}.js` via `wp_enqueue_script` in `<head>`. Independent of injection path. | When server-side `the_content` injection isn't running reliably — the player web component needs its JS to render |

**You will almost always need `enqueue_remote_player_script_sitewide: true` together with either of the other two.** Without it, the slot div + `<instaread-player>` tag appears, but the JS that powers the web component never loads, and the player silently fails to render.

---

## Pattern 1: Server-side JS-mover (preferred)

Use this when `the_content` filter does reach our handler (most partners) and you need precise placement.

```json
{
  "injection_rules": [
    {
      "target_selector": ".entry-meta",
      "insert_position": "after_element"
    }
  ],
  "enqueue_remote_player_script_sitewide": true
}
```

### How it actually works

1. WordPress fires `the_content` filter → our handler runs
2. Looks for `.entry-meta` in `$content` (article body string) — **not found**, because `.entry-meta` is a template element, not part of post body
3. `inject_with_safe_string_manipulation()` ([core/instaread-core.php:1417](../core/instaread-core.php#L1417)) falls through to the JS-mover branch at [line 1462](../core/instaread-core.php#L1462)
4. Emits an inline `<script>` next to the (prepended) slot. The script runs in the browser, queries `.entry-meta` in the rendered DOM (where the template containers DO exist), and moves the slot to the configured position
5. `enqueue_remote_player_script_sitewide` loads the player JS in `<head>` via `wp_enqueue_script` — independent of injection path

### Supported `insert_position` values

- `before_element` — slot inserted as previous sibling of target
- `after_element` — slot inserted as next sibling of target ← most common for "after the byline"
- `prepend` / `inside_first_child` — slot inserted as first child of target
- `append` / `inside_last_child` / `inside_element` — slot inserted as last child of target

### Confirmed example

**Partner:** `hangman` (theevreport.com, selfdrivenews.com)
**Goal:** player must render inside `<header class="entry-header">`, right after `<ul class="entry-meta">`
**Solution:** `target_selector: ".entry-meta"` + `insert_position: "after_element"` + `enqueue_remote_player_script_sitewide: true`
**Result:** v4.7.2 ships with the slot rendering exactly where intended

---

## Pattern 2: Footer JS fallback (when `the_content` is bypassed)

Use this when `the_content` filter doesn't reach our handler at all. Symptom: `<meta name="instaread-version">` is present (plugin is loaded) but no `<instaread-player>` tag anywhere on the page even when the slug isn't excluded.

```json
{
  "injection_rules": [
    {
      "target_selector": "",
      "insert_position": "prepend"
    }
  ],
  "enable_footer_js_fallback": true,
  "footer_js_fallback_selector": ".entry-content",
  "enqueue_remote_player_script_sitewide": true
}
```

### How it works

1. `the_content` injection runs (or doesn't — doesn't matter)
2. `maybe_inject_via_footer()` ([core/instaread-core.php:1373](../core/instaread-core.php#L1373)) runs on `wp_footer`
3. Calls `get_the_content()` directly (bypasses the filter chain)
4. If no player in returned content AND `enable_footer_js_fallback` is true, emits a `<script>` that does `t.insertBefore(slot, t.firstChild)` — **prepend only**
5. The script finds `footer_js_fallback_selector` in rendered DOM and prepends the slot

### Limitation: prepend-only

The footer fallback emits a hardcoded `t.insertBefore(d.firstChild, t.firstChild)` at [line 1410](../core/instaread-core.php#L1410). You cannot configure `after_element` or `append` for the footer path. If you need precise placement, use Pattern 1.

(We considered patching this in the bravewords debug session but chose not to — the server-side JS-mover already supports all positions, so partners needing precise placement should use that path instead.)

### Confirmed example

**Partner:** `bravewords` (bravewords.com)
**Symptom:** Smart-Mag theme + LiteSpeed Cache combo. `the_content` doesn't reach our handler. Player never appears.
**Solution:** Pattern 2 with `.entry-content` selector → player appears at top of article body
**Result:** v4.7.2 confirmed working

---

## Verifying a fix on a Cloudflare-fronted site

After shipping a fix, **regular page loads will keep showing the OLD HTML for 1-4 hours** because Cloudflare caches HTML at edge POPs. To verify yourself:

### Step 1: Confirm the plugin actually updated (telemetry email)

When auto-update fires, the partner site sends a `Plugin Updated — partner-id vX.Y.Z` email. If that arrived, PHP is on the new version. Trust this over what you see on the page.

### Step 2: Cache-bust the article URL

Open the article in a browser tab with a **never-before-seen query string**:

```
https://partner.com/some-article/?v=4.7.2&_=1779297500
```

Cloudflare caches by URL — a new URL = MISS = fresh HTML from origin. You'll see whatever PHP currently emits.

### Step 3 (for real visitors): purge Cloudflare cache

Telling the partner admin: log into Cloudflare → Caching → Configuration → Purge Everything. Without this, regular visitors keep seeing old HTML.

### Anti-pattern: don't use curl

`curl` with browser user agent **does not** pass Cloudflare's bot challenge (the "Just a moment..." JS page). Use a real browser tab. If you really need curl, route through the `browserless` Docker container running on port 3004.

---

## Diagnostic checklist

When a partner reports "no player":

```bash
# 1. Plugin version (does NOT prove the_content fired, only that the plugin file is loaded)
curl -s https://partner.com/ | grep -oE '<meta[^>]*instaread-version[^>]*>'

# 2. Player tag on article (the real test)
curl -s "https://partner.com/some-article/?_=$(date +%s%N)" | grep -c '<instaread-player'
# Expect: 1

# 3. Player JS loaded
curl -s "https://partner.com/some-article/?_=$(date +%s%N)" | grep -oE 'instaread\.[a-z]+\.js'
# Expect: instaread.{publication}.js

# 4. Where the slot landed
curl -s "https://partner.com/some-article/?_=$(date +%s%N)" | grep -B5 '<instaread-player'
```

Results matrix:

| Meta tag | `<instaread-player>` | `instaread.*.js` | Diagnosis |
|---|---|---|---|
| ✅ | ✅ | ✅ | Working — done |
| ✅ | ❌ | ❌ | Plugin loaded but `the_content` didn't reach our handler → try Pattern 2 |
| ✅ | ✅ | ❌ | Slot injected but JS not loaded → add `enqueue_remote_player_script_sitewide: true` |
| ✅ | ✅ in wrong place | ✅ | Placement issue → use precise selector + `insert_position` (Pattern 1) |
| ❌ | ❌ | ❌ | Plugin not running at all → check it's activated; check `injection_context` matches page type |

---

## When the placement is wrong, NOT missing

If the player IS rendering but at the wrong DOM position (e.g. at the bottom of `.entry-content` instead of after `.entry-meta`), it usually means the server-side JS-mover is running with a stale `insert_position` value, OR the partner config's selector matches an unexpected element.

Common case: config has `target_selector: ".entry-meta"` + `insert_position: "append"` — this puts player **inside** `<ul class="entry-meta">` as last child (semantically inside the byline). Switch to `after_element` to make it a sibling instead.

The DOM semantics:

| `insert_position` | Result on `.entry-meta` |
|---|---|
| `append` | Slot becomes last `<li>` (or any child) **inside** the UL — wrong for a 144px player |
| `prepend` | Slot becomes first child **inside** the UL — same problem |
| `before_element` | Slot becomes previous sibling of UL (above byline) |
| `after_element` | Slot becomes next sibling of UL (below byline) ← usually what's intended |

---

## Related docs

- [partner-config-reference.md](partner-config-reference.md) — full list of config flags
- [granitegrok-incident-postmortem.md](granitegrok-incident-postmortem.md) — earlier debugging of the_content bypass on a different theme
- [config-scenarios.md](config-scenarios.md) — common config patterns
- [partner-release-operations.md](partner-release-operations.md) — how to ship a partner release
