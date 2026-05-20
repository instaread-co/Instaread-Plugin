# Postmortem: 2026-05-19/20 — Log Spam Outage → Fleetwide Migration

**Status:** Resolved
**Duration:** ~24 hours (initial report → fleet stable)
**Impact:** 1 confirmed outage (inkfreenews.com disk fill), 1 site regression (moonandspoonandyum.com player vanished), trust concern raised by 1 partner (impress.press)
**Scope of work:** 95 partners released (v4.5.9, v4.5.10, v4.5.11, v4.5.12), 1 core hotfix, repo transfer suvarna-sumanth → instaread-co

## Initial report

**2026-05-18 ~15:40 UTC** — Rahul forwarded an email from inkfreenews.com:

> The plugin is writing verbose debug output to our PHP error log on every page load. Each request logs the full plugin configuration array multiple times, including all injection rules and excluded slugs. At our traffic volume this is generating roughly 14MB of log data every few minutes, which caused our server disk to fill completely over the past several months and resulted in a site outage this morning.

Their installed plugin was v4.2.2 — a pre-4.5.x build where `self::$debug = true` was hardcoded.

## Root cause (initial issue)

Older plugin builds (v1.x through v4.4.x) wrote the entire plugin configuration array to `error_log` on every page render via `$this->log()`. The v4.5.x core introduced a `force_disable_logs` partner-config flag that short-circuits every `$this->log()` call ([core/instaread-core.php:79-91](../core/instaread-core.php#L79-L91)), but 92 of 95 partners were on pre-4.5.x builds without this flag.

Only 3 partners (startsat60, inkfreenews-as-of-day-1, bedfordindependentcouk-as-of-day-1) had the flag set. The other 92 were silently filling disks at varying rates depending on traffic.

## Response

### Stage 1: Immediate fix for inkfreenews (the reporter)

1. Added `force_disable_logs: true` + `clear_page_cache_on_upgrade: true` to [partners/inkfreenews/config.json](../partners/inkfreenews/config.json)
2. Bumped version 4.2.2 → 4.5.9
3. Built and shipped via `partner-builds.yml` workflow
4. Telemetry confirmed install within 6 minutes: `v4.2.2 → v4.5.10`

bedfordindependentcouk got the same treatment in parallel (Rahul had a second complaint queued).

### Stage 2: Fleetwide v4.5.9 — preventive sweep

Audit confirmed 92 of 95 partners lacked the flag. Wrote a Python script ([fleetwide_disable_logs.py](#)) that:
- Added both flags to each `config.json`
- Bumped each partner's `version` field to `"4.5.9"`
- Updated `plugin.json` with new download_url + descriptive changelog
- Preserved per-file JSON indentation
- Validated semantic-only changes (no other keys touched)

Validated against all 184 modified files. Committed as PR #33 on branch `chore/fleetwide-disable-logs`.

### Stage 3: Concurrent dispatch failure (the first incident-within-an-incident)

After merging PR #33 to main, fired 92 workflow dispatches in batches of 5 with 15-second sleep. Result:

- **Of every 5 concurrent dispatches: 1 succeeded, 4 failed.**
- Failure mode: `Commit plugin.json update` step rejected with `! [rejected] main -> main (non-fast-forward)`.
- Only ~5 of the first 25 dispatches actually created releases.
- 20 partner sites then advertised v4.5.9 as available but the corresponding zip didn't exist → 404 storm → bravewords.com (which had been on v4.2.8) emailed Rahul with "Download failed. Not Found".

**Root cause of failure-within-failure:** The workflow's "Commit plugin.json update" step ([partner-builds.yml:103-115](../.github/workflows/partner-builds.yml#L103-L115)) does `git add … && git commit && git push` to main. When multiple runs race, only the first push wins; the rest hit `non-fast-forward`.

**Resolution:** Switched to a sequential dispatcher (one workflow_dispatch → `gh run watch --exit-status` → next). Got 68/68 success, 0 failures. Total runtime ~30 min for the remaining 68 partners.

**Saved to memory as [[fleetwide-release-dispatch]]** so future bulk operations don't repeat this.

### Stage 4: moonandspoonandyum regression

Kristen Wood emailed: *"I just updated the Instaread plugin to the newest version and now the audio player is no longer displaying."*

Investigation:
- Site page contained an empty `<div class="instaread-player-slot"></div>` (legacy from v2.8.x client-side-JS injection era — hardcoded in her post content or rendered by an old plugin build, persisted in the database)
- New core's duplicate-detection guard at [core/instaread-core.php:1249](../core/instaread-core.php#L1249) checked `strpos($content, 'instaread-player-slot')` and bailed out
- Player was never injected, the empty slot stayed empty

**Fix:** Tightened the guard to require an actual `<instaread-player>` tag, not just the slot class:

```php
// Before (bailed on empty slot)
if (strpos($content, 'instaread-player-slot') !== false || strpos($content, 'instaread-player') !== false) {
    return $content;
}

// After (only bails on real player tag)
if (strpos($content, '<instaread-player') !== false) {
    return $content;
}
```

Shipped as v4.5.10 for moonandspoonandyum. Player rendered correctly. Subsequent v4.5.11 added `slot_css: ""` to remove the inner `min-height:144px` that was overflowing Kristen's 85px container.

### Stage 5: Trust concern from impress.press

Paul Hutchinson (impress.press) flagged that auto-updates ship from a personal GitHub account (`suvarna-sumanth/Instaread-Plugin`), not from an Instaread org-owned namespace. From an IT review perspective, this is a supply-chain risk: if the personal account is compromised, every partner site auto-installs whatever a bad actor pushes.

Decision: migrate the repo to `instaread-co/Instaread-Plugin`. See `docs/repo-transfer-runbook.md` for the full procedure.

**Approach:**
1. Patched all 3 URL references in `core/instaread-core.php` to point at `instaread-co/...`
2. Released v4.5.12 fleetwide (95 partners, sequential, ~60 min, 0 failures)
3. User transferred repo via GitHub UI
4. Added webhook secrets to new org repo (secrets do not transfer)
5. **Hit org-permissions gotcha:** `GITHUB_TOKEN` defaults to read-only under org-owned repos. Workflow's "Create Release" step failed with HTTP 403. Fixed by adding explicit `permissions: contents: write` to the workflow's job (didn't require org admin)
6. Smoke tested moonandspoonandyum v4.5.13 against new org — full pipeline green

## Discovery: instant-update webhook gap

While debugging moonandspoonandyum, found that the webhook server's `sites_notified` field was returning `0` for all 92 partners in the fleet rollout. The webhook server only knows about a partner site when that site has previously sent telemetry (heartbeat or update event). At the time of the fleet rollout, only ~10 of 95 partners had ever sent telemetry.

This is **not strictly a bug** — it's an architectural limitation of the instant-update system:
- Sites receive instant updates only after their first successful telemetry post (heartbeat fires every 24h via admin_init)
- Brand-new partner sites or sites where nobody has loaded wp-admin recently rely on WordPress's standard 12h update-check cron
- This was documented but not widely understood — surfaced clearly during this incident

After the v4.5.9 fleetwide install completed, telemetry started populating for partners that successfully installed. Subsequent v4.5.12 push reached more sites instantly (e.g. `sites_notified: 1` for moonandspoonandyum v4.5.13 because it had registered after installing v4.5.10/v4.5.11).

## Timeline

| Time (UTC) | Event |
|---|---|
| 2026-05-18 15:40 | Rahul forwards inkfreenews complaint |
| 2026-05-19 13:08 | inkfreenews v4.5.10 released, installed within 1 min via webhook |
| 2026-05-19 16:07 | bedfordindependentcouk v4.5.9 released |
| 2026-05-19 16:55–17:11 | First fleetwide dispatch attempt — 80% failure rate (concurrent push race) |
| 2026-05-19 17:11+ | Sequential dispatcher built and run for remaining 68 partners |
| 2026-05-19 ~18:00 | All 95 partners on v4.5.9 (where applicable) |
| 2026-05-19 19:30 | Kristen reports player missing |
| 2026-05-19 19:33 | moonandspoonandyum v4.5.10 with core duplicate-guard patch shipped |
| 2026-05-20 03:16 | moonandspoonandyum v4.5.11 with `slot_css: ""` |
| 2026-05-20 08:30 | Repo URL migration patch shipped to core |
| 2026-05-20 08:33–09:30 | Fleetwide v4.5.12 release (95 partners sequential, 0 failures) |
| 2026-05-20 09:35 | User transferred repo to instaread-co |
| 2026-05-20 09:36 | First smoke test failed (org GITHUB_TOKEN read-only) |
| 2026-05-20 09:39 | Workflow permissions patch + successful smoke test |

## Lessons & action items

### What went well

- **Sequential dispatcher pattern** scaled cleanly. 0 failures over 68 + 95 + 1 sequential dispatches across two operations.
- **GitHub redirect insurance** worked exactly as documented. Sites on old versions transparently continue to function during/after the repo transfer.
- **Memory system** captured the dispatch-race lesson immediately so we didn't repeat it on the v4.5.12 push.
- **Workflow regenerates plugin.json from scratch** — meant the URL migration didn't need 95 manual edits; the core patch + a single workflow run per partner did everything.

### What hurt

1. **Concurrent dispatch race wasn't documented anywhere** — burned ~30 min and generated a customer complaint (bravewords). Now in memory.
2. **Org-permissions gotcha wasn't anticipated** — added 5 min to the migration. Now documented in `repo-transfer-runbook.md`.
3. **Webhook server's `sites_notified: 0` looked like a bug** — it's actually expected behavior for un-registered sites. Took longer than it should have to figure out. Now in postmortem.
4. **Default debug flag was on in older builds** — root cause was that v1.x–v4.4.x shipped with `self::$debug = true` literally in the code. Should never have shipped to production. Future code review needs a checklist item: "is verbose logging gated by an opt-in?"
5. **Hardcoded webhook secret in audioplayer-processor** — `INSTAREAD_WEBHOOK_SECRET` has a hardcoded fallback in [`plugin-telemetry.service.ts:195`](../../prod-projects/audioplayer-processor/apps/server/src/modules/plugin-telemetry/plugin-telemetry.service.ts#L195). Visible in source. Should be removed and the secret rotated.

### Action items

- [ ] Remove hardcoded `INSTAREAD_WEBHOOK_SECRET` fallback from `plugin-telemetry.service.ts`. Force env var only.
- [ ] Rotate the webhook secret (currently in code, in GitHub Actions, in this conversation transcript)
- [ ] Add a CI check that fails if `self::$debug = true` literal appears in `core/instaread-core.php`
- [ ] Document the instant-update registration flow more clearly so future operators don't misread `sites_notified: 0` as a bug
- [ ] Consider adding `git pull --rebase && git push` retry loop to the workflow's commit step so future bulk operations can run concurrently safely
- [ ] Reply to Paul Hutchinson confirming both fixes (logging + repo ownership) — already drafted, send when ready

## Related

- `docs/repo-transfer-runbook.md` — generalized procedure for owner changes
- `docs/partner-release-operations.md` — day-to-day partner release operations
- [[fleetwide-release-dispatch]] in memory — concurrent dispatch caveat
- [[auto_update_system_status]] in memory — the instant-update architecture
