# Legacy PHP Transpile (one-off tool)

Produces a PHP 5.6-compatible build of the plugin for partners still on
end-of-life PHP runtimes.

## When to use

Only when a partner is on PHP < 7.0 AND cannot upgrade in a reasonable
timeframe. The default answer to a PHP-5.6-stuck partner is **still** "please
upgrade PHP" — that runtime has been EOL since Jan 2019 and is a real
security exposure regardless of what plugins run.

If you must ship a legacy build:

1. **Verify the partner absolutely cannot upgrade.** Ask their host directly.
2. Run this script + package the zip yourself. **Never merge the transpiled
   file back into `core/`** — main-branch core stays PHP 7+ so the fleet
   normal build path is unaffected.
3. Hand the zip to the partner as a one-off drop-in. Auto-updates on their
   install will pull the *normal* GitHub release (which will fatal-error on
   their runtime). Their choice: pin to this zip (no updates), or upgrade
   PHP and rejoin the fleet.

## How to run

Requires: docker (for verification via `php:5.6-cli` + `php:8.1-cli`).

```bash
# 1. Transpile
python3 tools/legacy-php-transpile/transpile_null_coalesce.py \
    core/instaread-core.php /tmp/instaread-core-legacy.php

# 2. Verify on target PHP versions
docker run --rm -v /tmp:/tmp php:5.6-cli php -l /tmp/instaread-core-legacy.php
docker run --rm -v /tmp:/tmp php:8.1-cli php -l /tmp/instaread-core-legacy.php

# 3. Build the zip (replicate the workflow, swap in transpiled core)
PARTNER_ID=agdaily
VERSION=4.7.19
BUILD_DIR=/tmp/legacy-build/${PARTNER_ID}
rm -rf /tmp/legacy-build
mkdir -p ${BUILD_DIR}
cp -r core/* ${BUILD_DIR}/
cp /tmp/instaread-core-legacy.php ${BUILD_DIR}/instaread-core.php  # KEY STEP
cp -r plugin-update-checker ${BUILD_DIR}/
mkdir -p ${BUILD_DIR}/mu-plugins
cp core/instaread-mu-plugin.php ${BUILD_DIR}/mu-plugins/instaread-auto-updater.php
cp partners/${PARTNER_ID}/{config,plugin}.json ${BUILD_DIR}/
cp partners/${PARTNER_ID}/styles.css ${BUILD_DIR}/
sed -i "s/Plugin Name: Instaread Audio Player/Plugin Name: Instaread Audio Player - ${PARTNER_ID} (PHP 5.6 legacy build)/" ${BUILD_DIR}/instaread-core.php
sed -i "s/Version: [0-9.]*/Version: ${VERSION}/" ${BUILD_DIR}/instaread-core.php
sed -i "s/const PLUGIN_VERSION = '[0-9.]*'/const PLUGIN_VERSION = '${VERSION}'/" ${BUILD_DIR}/instaread-core.php

# 4. Verify EVERY PHP file in the build parses on PHP 5.6
for f in $(find ${BUILD_DIR} -name "*.php"); do
    docker run --rm -v /tmp:/tmp php:5.6-cli php -l "$f" 2>&1 | grep -v "No syntax errors detected" | head -3
done

# 5. Zip
cd /tmp/legacy-build && zip -qr ${PARTNER_ID}-v${VERSION}-php56-legacy.zip ${PARTNER_ID}/
sha256sum ${PARTNER_ID}-v${VERSION}-php56-legacy.zip
```

## What the transpiler does

Only rewrites `$expr ?? $default` → `(isset($expr) ? $expr : $default)`.

- Token-aware: skips `??` inside strings, `//`, `#`, and `/* */` comments.
- Handles nested expressions: `(array) ($x['k'] ?? [])` → `(array) ((isset($x['k']) ? $x['k'] : []))`.
- Multi-line RHS: `$x[$k] ?? [\n  ...\n]` correctly captures the balanced `[...]`.
- Semantics: `??` returns fallback for undefined AND null; `isset()` also
  returns false for null. Equivalent for our usage (config-key reads).

## What the transpiler does NOT do

- Arrow functions (`fn() =>`): 0 in codebase, would need separate handling if added.
- `str_contains`/`str_starts_with`/`str_ends_with`: 0 in codebase.
- Typed properties: 0 in codebase.
- Named arguments: 0 real ones (12 false positives from ternaries).
- Match expressions, nullsafe operator, constructor promotion: 0.

If any of those get added to core later, this transpiler needs an update
and the audit re-run. Verification is `docker run php:5.6-cli php -l`.
