# SiteGround Optimizer JS Combine — Incident Postmortem

**Date:** 2026-06-23
**Partner affected:** iowaspulse.com (SiteGround-hosted, Foxiz theme)
**Resolved in:** v4.7.10
**Time to resolve:** 5 release iterations (v4.7.6 → v4.7.10)

## Symptom

After installing v4.7.5 on iowaspulse:

- ✅ Plugin meta tag rendered: `instaread-version="4.7.5" data-partner="iowaspulse"`
- ✅ `<div class="instaread-player-slot">` injected in DOM
- ✅ `<instaread-player publication="iowaspulse">` tag inside slot
- ❌ **No player UI rendered** — iframe never appeared
- ❌ `instaread.iowaspulse.js` NOT in Network tab
- ❌ No `<script src="player.instaread.co/...">` in rendered HTML

## Root cause

The site runs **SiteGround Optimizer** (`sg-cachepress` plugin) with "Combine JavaScript Files" enabled. SG's `Js_Combinator` class:

1. Hooks `wp_print_footer_scripts` at high priority
2. Walks `wp_scripts()->queue`, picks all enqueued scripts
3. Fetches each script's JS content (including cross-origin URLs like `player.instaread.co`)
4. Writes the concatenated+minified output to `wp-content/uploads/siteground-optimizer-assets/siteground-optimizer-combined-js-<hash>.js`
5. **Strips the original `<script src=…>` tags** from the rendered HTML
6. Emits one `<script src="...combined-js-<hash>.js">` tag in the footer

Result for our plugin:
- `instaread.iowaspulse.js` (cross-origin from `player.instaread.co`) was inlined into the combined file
- Future bundle updates from our CDN wouldn't propagate until SG rebuilt
- Worse: the combined file itself had a **JS syntax error** (`Identifier 'Zn' has already been declared` — SG's per-file minifier produced colliding short identifiers across multiple combined files). All scripts in the combined file died on parse, including our bundle.

## Why it took 5 iterations

We had a "fix" in the codebase for SG Optimizer since at least v4.7.5, but it was pushing exclusions to the WRONG filter name. The filter `sgo_javascript_combine_excluded` (with trailing `d`) **does not exist in SG's code** — that line of code was a silent no-op.

Each subsequent escape hatch attempted to work around what we thought was SG-being-stubborn, when actually the exclusion was never running:

| Version | Approach | Why it failed |
|---|---|---|
| v4.7.6 | Added `data-no-combine` attribute to raw `<script>` tags | SG's HTML rewriter ignored the attribute and combined anyway |
| v4.7.7 | `script_loader_tag` filter to inject `data-no-combine` on WP-enqueued tags | SG intercepts scripts from `wp_scripts()->queue` BEFORE WP renders the tag — `script_loader_tag` never fires for SG-grabbed scripts |
| v4.7.8 | Detect SG, skip `wp_enqueue_script` entirely, `echo` raw `<script>` on `wp_footer` | SG also runs an output-buffer HTML rewriter that scans the rendered page for `<script src=…>` URLs — caught the raw echo too |
| v4.7.9 | Emit inline IIFE that calls `document.createElement('script')` at runtime | The IIFE itself got combined into the SG file. The IIFE ran, but the `_bundle_loaded` flag mechanism + race with custom-element define + syntax error in combined file → still nothing |
| **v4.7.10** | **Fetched SG's actual source from `plugins.svn.wordpress.org/sg-cachepress/trunk/core/Combinator/Js_Combinator.php`. Pushed exclusions to the REAL filter names.** | **Works.** |

## The real filter names (memorize these)

From `Js_Combinator.php`:

```php
// Excludes by script HANDLE (no trailing 'd' in "exclude")
apply_filters('sgo_javascript_combine_exclude', $handles_array)

// Excludes by script ID (handle + '-js')
apply_filters('sgo_javascript_combine_exclude_ids', $ids_array)

// Excludes CROSS-ORIGIN scripts by URL fragment match
apply_filters('sgo_javascript_combine_excluded_external_paths', $url_fragments_array)

// Excludes SAME-ORIGIN scripts by URL fragment
apply_filters('sgo_javascript_combine_excluded_internal_paths', $url_fragments_array)

// Excludes inline <script> blocks whose content matches a fragment
// CRITICAL when our JS-mover uses document.currentScript.previousElementSibling
apply_filters('sgo_javascript_combine_excluded_inline_content', $content_fragments_array)

// Excludes from async/defer (separate from combine)
apply_filters('sgo_javascript_async_exclude', $handles_array)
```

**Note especially the inline-content filter.** SG can also pull inline `<script>` tags into the combined file. When it does that to a script that uses `document.currentScript` or `document.currentScript.previousElementSibling` (like our JS-mover from `inject_with_safe_string_manipulation`), those DOM references stop pointing at the right element — they now reference the SG combined-js script tag instead. Our slot positioning logic silently breaks.

## What the v4.7.10 fix does

In `init_optimization_exclusions()`:

```php
$sg_handles = ['instaread-remote-player', 'instaread-player-loader', 'instaread-partner-js'];

// Exclude by registered handle
foreach ($sg_handles as $handle) {
    $this->add_array_exclusion('sgo_javascript_combine_exclude', $handle);
    $this->add_array_exclusion('sgo_javascript_combine_exclude_ids', $handle . '-js');
}

// Exclude by external URL fragment (the critical one for cross-origin player.instaread.co)
$this->add_array_exclusion('sgo_javascript_combine_excluded_external_paths', 'player.instaread.co');
$this->add_array_exclusion('sgo_javascript_combine_excluded_external_paths', 'instaread.co/js/');

// Exclude inline scripts that use currentScript-based positioning
foreach (['instaread-player-slot', 'instaread-player', 'instaread.co'] as $marker) {
    $this->add_array_exclusion('sgo_javascript_combine_excluded_inline_content', $marker);
}

// Skip from async/defer
foreach ($sg_handles as $handle) {
    $this->add_array_exclusion('sgo_javascript_async_exclude', $handle);
}
```

Plus retained `is_siteground_optimizer_active()` detection + dynamic-load IIFE on wp_footer as a safety net (self-skips if a `<script src="player.instaread.co/...">` is already in the DOM).

## Verification protocol

After v4.7.10 install on an SG-hosted partner:

1. **Force-update URL**: `https://<partner>/?instaread_force_update_check=<id>&_=test`
2. **Touch wp-admin once** (any page) to fire `clear_page_cache_on_upgrade()` → SG cache purges
3. **Load article with cache-buster**: `https://<partner>/<article>/?cb=$(date +%s)`
4. **DevTools Network → filter `instaread`**:
   - Expect `instaread.<pub>.js` from `player.instaread.co` — status 200
5. **Validate SG combined-js no longer contains our bundle**:
   ```bash
   COMBINED=$(curl -s "...?cb=..." | grep -oE 'siteground-optimizer-combined-js-[a-f0-9]+\.js' | head -1)
   curl -s "https://<partner>/wp-content/uploads/siteground-optimizer-assets/$COMBINED" | grep -c 'class InstareadPlayer'
   # expect: 0
   ```

## Lessons

1. **Verify external integration filter names against source.** Don't trust prior code or memory — fetch the actual plugin source (WordPress SVN is the canonical mirror: `plugins.svn.wordpress.org/<slug>/trunk/`). A typo like `excluded` vs `exclude` killed years of supposed protection.

2. **When a fix doesn't work, suspect the fix code itself before adding workarounds.** v4.7.6 through v4.7.9 escalated complexity, never re-questioning the original v4.7.5 SG-exclusion code. A single `grep "sgo_javascript_combine"` against SG's source would have revealed the typo immediately.

3. **For escape-hatch features, log the active path.** `is_siteground_optimizer_active()` should always log when it triggers, so we can confirm from telemetry whether the SG path was taken on a problem report.

4. **External CDN-loaded scripts are an entire class of edge cases.** Any per-page-optimizer plugin (SG Optimizer, WP Rocket, Autoptimize, LiteSpeed, NitroPack, Ezoic, Hummingbird) has its own combine/minify/defer behavior with its own filter names. Maintain a per-vendor exclusion table in core, sourced from each plugin's actual public API.

## Related

- [SKILL.md "SiteGround Optimizer" section](.claude/skills/create-partner/SKILL.md) — operational playbook
- [granitegrok-incident-postmortem.md](granitegrok-incident-postmortem.md) — different cache layer, similar diagnostic pattern
- Memories: [[cloudflare_cache_verification]], [[fleetwide-release-dispatch]]
