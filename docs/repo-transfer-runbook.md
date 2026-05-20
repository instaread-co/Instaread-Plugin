# Repo Transfer Runbook

Reference for transferring the plugin repository between GitHub owners (e.g. personal account → org, or org → org). Written from the 2026-05-20 migration `suvarna-sumanth/Instaread-Plugin → instaread-co/Instaread-Plugin`.

## What's hardcoded to the repo URL

Three files reference the repo path:

| Location | What it does | Auto-updates on transfer? |
|---|---|---|
| `core/instaread-core.php:455` | `init_update_checker()` — PUC fetches plugin.json from `raw.githubusercontent.com/<owner>/Instaread-Plugin/main/partners/<id>/plugin.json` | ❌ Hardcoded literal |
| `core/instaread-core.php:456` | Fallback URL when partner_config missing — `<owner>.github.io/Instaread-Plugin/plugin.json` | ❌ Hardcoded literal |
| `core/instaread-core.php:706` | `handle_loopback_update_check()` — direct plugin.json fetch for webhook-triggered checks | ❌ Hardcoded literal |
| `.github/workflows/partner-builds.yml:36` | `REPO_URL` for embedding download URL in generated plugin.json | ✅ Uses `${{ github.repository }}` |
| `partners/*/plugin.json` `download_url` | Where WP downloads the zip | ⚠️ Frozen at build time, but workflow regenerates each release |

**Implication:** Every partner zip already deployed in the wild has the old owner hardcoded in `instaread-core.php`. Until a site upgrades to a build with the new URL, it depends on GitHub's redirect.

## Migration sequence (use this order)

### Stage 1 — Patch URLs in core (before transfer)

```bash
# Edit core/instaread-core.php — replace all references
sed -i 's|suvarna-sumanth/Instaread-Plugin|instaread-co/Instaread-Plugin|g' core/instaread-core.php
sed -i 's|suvarna-sumanth\.github\.io|instaread-co.github.io|g' core/instaread-core.php

# Verify no stragglers
grep -n suvarna-sumanth core/instaread-core.php  # should be empty

git add core/instaread-core.php
git commit -m "chore: migrate update-checker URLs to instaread-co"
git push origin main
```

### Stage 2 — Fleetwide release with new URLs baked in

Run the partner-builds workflow for every partner with a fresh version bump (e.g. v4.5.12). This bakes the new URLs into the shipped `instaread-core.php` for every partner zip.

Use the **sequential dispatcher** — concurrent dispatches race on `git push` (see [[fleetwide-release-dispatch]] in memory). Total runtime for 95 partners: ~60 min.

```bash
# Build partner list
ls partners/ > /tmp/partner_list.txt

# Run sequential dispatcher (one workflow_dispatch, watch to completion, then next)
bash /tmp/dispatch_sequential.sh
```

After this, every new install has the correct URLs.

### Stage 3 — Transfer the repo

Done via UI:

```
github.com/<old-owner>/Instaread-Plugin/settings
  → Danger Zone (scroll bottom)
  → Transfer
  → New owner: instaread-co
  → Confirm by typing: <old-owner>/Instaread-Plugin
```

GitHub redirects the old URLs indefinitely (web + raw + releases), with one caveat: redirects break if the old username is later reclaimed or a same-named repo is created under it. **Do not delete the old account or create another `Instaread-Plugin` repo under it.**

### Stage 4 — Re-add secrets to the org repo

GitHub Actions secrets are **NOT transferred**. Workflows that depend on them will fail with permission/auth errors until re-added.

Go to `github.com/<new-owner>/Instaread-Plugin/settings/secrets/actions`:

| Secret | Value source |
|---|---|
| `INSTAREAD_WEBHOOK_URL` | `https://player-api.instaread.co/api/plugin-telemetry/github-webhook` |
| `INSTAREAD_WEBHOOK_SECRET` | Hardcoded fallback in `audioplayer-processor/apps/server/src/modules/plugin-telemetry/plugin-telemetry.service.ts:195` — rotate this later |

### Stage 5 — Org permissions gotcha

When the repo is transferred to an org, `GITHUB_TOKEN` defaults to **read-only**. The workflow's `Create Release` and `Commit plugin.json update` steps will fail with HTTP 403.

Two fixes:

**A. Workflow-level** (recommended — no org admin needed):

```yaml
jobs:
  build-partner-plugin:
    runs-on: ubuntu-latest
    permissions:
      contents: write   # ADD THIS
    steps:
      - ...
```

**B. Org-level** (needs org admin):
`github.com/organizations/<org>/settings/actions → Workflow permissions → Read and write`

This was the failure mode on 2026-05-20. Fix shipped in `partner-builds.yml`.

### Stage 6 — Update local remote

```bash
git remote set-url origin git@github.com:<new-owner>/Instaread-Plugin.git
git remote -v   # verify
```

### Stage 7 — Smoke test

Fire one workflow_dispatch against the new owner to confirm end-to-end:

```bash
gh workflow run partner-builds.yml -R <new-owner>/Instaread-Plugin \
  -f partner_id=moonandspoonandyum -f version=4.5.13
```

Watch the run; verify:
- ✓ Create Release succeeds
- ✓ Commit plugin.json update succeeds
- ✓ Webhook returns HTTP 200 with `sites_notified: N` (N≥1 if partner is telemetry-registered)
- ✓ `plugin.json` `download_url` now points at the new owner

### Stage 8 — Monitor adoption

```bash
# Poll telemetry to watch sites pick up the new version
curl -sS https://player-api.instaread.co/api/plugin-telemetry | jq '[.[] | select(.version | startswith("4.5.12"))]'
```

Realistic adoption curve:
- 12h: ~30–50% of fleet (sites with active traffic firing WP cron)
- 48h: ~80%
- 1 week: ~95%
- The long tail are sites with disabled wp-cron or very low traffic

## Verifying redirects work

```bash
# Web URL — should show 301 to new owner
curl -sI https://github.com/<old-owner>/Instaread-Plugin | grep -i location

# Raw URL — should serve content from new repo (note: NOT a redirect, GitHub aliases internally)
curl -sS https://raw.githubusercontent.com/<old-owner>/Instaread-Plugin/main/partners/inkfreenews/plugin.json

# Release download URL — should redirect to release-assets.githubusercontent.com signed URL
curl -sI https://github.com/<old-owner>/Instaread-Plugin/releases/download/inkfreenews-v4.5.12/inkfreenews-v4.5.12.zip
```

## Rollback plan

If something goes catastrophically wrong post-transfer:

1. Transfer the repo back (`instaread-co/Instaread-Plugin → <old-owner>/Instaread-Plugin`) via Settings → Danger Zone. GitHub allows this.
2. Re-add secrets to the original repo.
3. The partner builds shipped during the broken window still work — their `download_url` points at `instaread-co/...`, which now redirects back.

In practice the transfer is low-risk because the redirect insurance covers both directions during the cutover.

## Related

- [[fleetwide-release-dispatch]] memory — concurrent dispatches race on `git push`, must run sequentially
- `docs/2026-05-incident-postmortem.md` — full timeline of the migration
- `docs/partner-release-operations.md` — ongoing operations after migration is complete
