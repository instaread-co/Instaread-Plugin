<?php
/**
 * Plugin Name: Instaread Audio Player
 * Plugin URI: https://instaread.co
 * Description: Instaread auto-injecting player with partner configuration and full server-side rendering (no DOMDocument parsing, safer string injection)
 * Version: 4.4.6
 * Author: Instaread Team
 */

defined('ABSPATH') || exit;

// FIX #1: Namespaced globals to prevent collision with other plugins/themes
//         Old names ($partner_config, $partner_css) are dangerously generic.
$instaread_partner_config_file = __DIR__ . '/config.json';
$instaread_partner_css_file    = __DIR__ . '/styles.css';

// FIX #6: Validate config.json with json_last_error() — silent malformed JSON
//         previously caused the plugin to fall back to WP options with no warning.
if (file_exists($instaread_partner_config_file)) {
    $instaread_raw_config         = file_get_contents($instaread_partner_config_file);
    $instaread_partner_config     = json_decode($instaread_raw_config, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('[InstareadPlayer] CRITICAL: config.json is invalid JSON — ' . json_last_error_msg() . '. Falling back to WP options.');
        $instaread_partner_config = null;
    }
} else {
    $instaread_partner_config = null;
}

// FIX #10: Only read CSS file when actually needed (front-end non-admin requests).
//          Previously read on every request including admin, AJAX, REST, cron.
$instaread_partner_css = null;
if (!is_admin() && file_exists($instaread_partner_css_file)) {
    $instaread_partner_css = file_get_contents($instaread_partner_css_file);
}

// FIX #2: Guard require_once — missing update-checker previously caused a fatal
//         error that brought the entire partner site down on a corrupted install.
if (!file_exists(__DIR__ . '/plugin-update-checker/plugin-update-checker.php')) {
    error_log('[InstareadPlayer] FATAL: plugin-update-checker is missing. Auto-updates disabled.');
} else {
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
}
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class InstareadPlayer {
    private static $instance;
    private static $debug = false;

    private $settings;
    private $partner_config;
    private $plugin_version;

    /**
     * FIX #5: Plugin version defined as a constant — read once at class load,
     * never triggers a file read on every request like get_plugin_data() did.
     * IMPORTANT: Keep this in sync with the Version header above.
     */
    const PLUGIN_VERSION = '4.4.6';

    /**
     * Domain pattern used to exclude our scripts from all caching/optimization plugins.
     * 'instaread.co' matches player.instaread.co, instaread.co, and any future *.instaread.co subdomains.
     */
    const SCRIPT_EXCLUDE_PATTERN = 'instaread.co';

    /**
     * WP option key to track the last installed plugin version.
     * Used to detect upgrades and trigger a one-time automatic cache clear.
     */
    const VERSION_OPTION_KEY = 'instaread_installed_version';

    /**
     * Transient key used as a mutex lock during cache clearing.
     * Prevents thundering herd when multiple PHP workers race on upgrade detection.
     */
    const CACHE_CLEAR_LOCK_KEY = 'instaread_cache_clearing';

    private function is_debug_enabled() {
        // Partner-level opt-out: when "force_disable_logs": true is set in the
        // partner config, we suppress every $this->log() call regardless of
        // WP_DEBUG / INSTAREAD_DEBUG / instaread_debug filter. Useful for
        // partners who run WP_DEBUG=true site-wide for their own debugging
        // and don't want our verbose lines polluting their debug.log.
        if (!empty($this->partner_config['force_disable_logs'])) {
            return false;
        }
        return (defined('WP_DEBUG') && WP_DEBUG)
            || (defined('INSTAREAD_DEBUG') && INSTAREAD_DEBUG)
            || apply_filters('instaread_debug', false);
    }

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // FIX #1: Reference the namespaced global, not the generic $partner_config
        global $instaread_partner_config;
        $this->partner_config = $instaread_partner_config;

        $this->settings = $this->get_settings();

        // Version is sourced from partner config (already loaded above) so each
        // partner release drives upgrade detection. Falls back to PLUGIN_VERSION
        // constant when partner config is missing or has no version field.
        $this->plugin_version = !empty($this->partner_config['version'])
            ? (string) $this->partner_config['version']
            : self::PLUGIN_VERSION;

        // Only set up update checker if the library was successfully loaded (FIX #2)
        if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
            $this->init_update_checker();
        }

        // Register exclusions with all caching/optimization plugins as early as possible
        $this->init_optimization_exclusions();

        // One-time automatic cache clear when plugin upgrades to a new version
        $this->maybe_clear_cache_on_upgrade();

        add_action('admin_init',         [$this, 'register_settings']);
        add_action('admin_menu',         [$this, 'add_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_head',            [$this, 'emit_version_meta'], 1);

        // Run very late by default so other plugins (Social Warfare etc.) have already
        // modified content. Some themes/plugins (e.g. content rebuilders running at very
        // high priority) wipe late injections — partners can override via config:
        //   "the_content_priority": 99
        // Default 9999 keeps us late but below any plugin pinned to PHP_INT_MAX.
        $the_content_priority = isset($this->partner_config['the_content_priority'])
            ? (int) $this->partner_config['the_content_priority']
            : (PHP_INT_MAX - 1);
        add_filter('the_content',        [$this, 'inject_server_side_player'], $the_content_priority, 1);

        // Footer fallback — only logs, never injects, to avoid duplicates
        add_action('wp_footer',          [$this, 'maybe_inject_via_footer'], 999);

        // Strip SRI integrity attributes from Instaread script tags in the final HTML output.
        // Some CDNs (Cloudflare) and security/caching plugins inject integrity="sha384-..." onto
        // external scripts after our content is built. When the remote JS is updated the stale
        // hash triggers a browser integrity mismatch error. Running at priority 0 (before output
        // is sent) ensures we catch attributes added by plugins that run late on template_redirect.
        add_action('template_redirect', [$this, 'start_sri_strip_buffer'], 0);

        add_filter('auto_update_plugin',      [$this, 'enable_auto_updates'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'on_plugin_updated'], 10, 2);
        add_action('admin_notices',            [$this, 'show_update_admin_notice']);
        add_action('admin_init',               [$this, 'maybe_send_heartbeat']);
        add_action('admin_init',               [$this, 'maybe_send_activation_telemetry']);
        add_action('wp_loaded',                [$this, 'maybe_force_update_check']);
        add_action('wp_loaded',                [$this, 'handle_loopback_update_check']);
        add_action('instaread_maybe_auto_update', [$this, 'trigger_auto_update_now']);

        $this->maybe_migrate_old_settings();
        $this->log('Instaread Player initialized.');
        $this->log([
            'settings'       => $this->settings,
            'partner_config' => $this->partner_config,
            'plugin_version' => $this->plugin_version,
        ]);
    }

    private function log($msg) {
        if (self::$debug || $this->is_debug_enabled()) {
            error_log('[InstareadPlayer] ' . print_r($msg, true));
        }
    }

    // =========================================================================
    // OPTIMIZATION / CACHING PLUGIN EXCLUSIONS
    //
    // Covers: WP Rocket, Autoptimize, W3 Total Cache, LiteSpeed Cache,
    //         SiteGround Optimizer, Hummingbird, NitroPack, Swift Performance,
    //         Flying Scripts, Asset CleanUp, Cloudflare Rocket Loader
    //
    // Pattern 'instaread.co' matches all current and future *.instaread.co domains.
    // Registered in __construct so it runs as early as possible on every request.
    // =========================================================================

    private function add_array_exclusion($filter, $pattern) {
        add_filter($filter, function ($excluded) use ($pattern) {
            if (!is_array($excluded)) $excluded = [];
            $excluded[] = $pattern;
            return $excluded;
        });
    }

    private function init_optimization_exclusions() {
        $pattern = self::SCRIPT_EXCLUDE_PATTERN;

        // --- WP Rocket ---
        $this->add_array_exclusion('rocket_exclude_js', $pattern);
        $this->add_array_exclusion('rocket_delay_js_exclusions', $pattern);
        $this->add_array_exclusion('rocket_exclude_defer_js', $pattern);
        $this->add_array_exclusion('rocket_cdn_reject_files', $pattern);

        // --- Autoptimize ---
        // autoptimize_filter_js_exclude expects a comma-separated string
        add_filter('autoptimize_filter_js_exclude', function ($excluded) use ($pattern) {
            return (is_string($excluded) ? $excluded : '') . ', ' . $pattern;
        });
        $this->add_array_exclusion('autoptimize_filter_js_defer_not_aggregate', $pattern);

        // --- W3 Total Cache ---
        add_filter('w3tc_minify_js_do_tag_minification', function ($do, $script_tag) use ($pattern) {
            return strpos($script_tag, $pattern) !== false ? false : $do;
        }, 10, 2);
        add_filter('w3tc_cdn_reject_request', function ($reject, $url) use ($pattern) {
            return strpos($url, $pattern) !== false ? true : $reject;
        }, 10, 2);

        // --- LiteSpeed Cache ---
        $this->add_array_exclusion('litespeed_optimize_js_excludes', $pattern);
        $this->add_array_exclusion('litespeed_optm_js_defer_exc', $pattern);

        // --- SiteGround Optimizer ---
        // Reverse-engineered from sg-cachepress/trunk/core/Combinator/Js_Combinator.php
        // (rev 3582924, 2026-06): the actual filter names SG uses are different from
        // what we had been pushing to. v4.7.6 through v4.7.9 all failed on iowaspulse
        // because our exclusion was registered against `sgo_javascript_combine_excluded`
        // (with trailing 'd') — a filter that LITERALLY DOES NOT EXIST in SG's code.
        // The real filter names are:
        //   sgo_javascript_combine_exclude              → array of HANDLES (no 'd')
        //   sgo_javascript_combine_excluded_external_paths → array of external URL fragments
        //   sgo_javascript_combine_excluded_internal_paths → array of internal URL fragments
        //   sgo_javascript_combine_exclude_ids          → array of script ids
        $sg_handles = ['instaread-remote-player', 'instaread-player-loader', 'instaread-partner-js'];

        // Exclude by registered handle (SG's primary path).
        foreach ($sg_handles as $handle) {
            $this->add_array_exclusion('sgo_javascript_combine_exclude', $handle);
            $this->add_array_exclusion('sgo_javascript_combine_exclude_ids', $handle . '-js');
        }

        // Exclude by external URL fragment. SG checks src against this list for
        // cross-origin scripts — exactly what player.instaread.co/js/instaread.*.js is.
        $this->add_array_exclusion('sgo_javascript_combine_excluded_external_paths', 'player.instaread.co');
        $this->add_array_exclusion('sgo_javascript_combine_excluded_external_paths', 'instaread.co/js/');

        // Exclude inline scripts that contain our markers from combine (e.g. footer-fallback
        // <script> blocks and the JS-mover snippets emitted by inject_with_safe_string_manipulation).
        // Combining those into the combined file breaks `document.currentScript.previousElementSibling`
        // semantics: that DOM reference only makes sense in the script's original position.
        foreach (['instaread-player-slot', 'instaread-player', 'instaread.co'] as $marker) {
            $this->add_array_exclusion('sgo_javascript_combine_excluded_inline_content', $marker);
        }

        // Skip from JS async/defer too — same handles.
        foreach ($sg_handles as $handle) {
            $this->add_array_exclusion('sgo_javascript_async_exclude', $handle);
        }

        // --- Hummingbird (WPMU Dev) ---
        add_filter('wphb_minify_resource', function ($minify, $handle) {
            return strpos((string) $handle, 'instaread') !== false ? false : $minify;
        }, 10, 2);

        // --- NitroPack ---
        $this->add_array_exclusion('nitropack_js_exclude_patterns', $pattern);

        // --- Swift Performance ---
        $this->add_array_exclusion('swift_performance_exclude_from_minify', $pattern);
        $this->add_array_exclusion('swift_performance_exclude_from_merge', $pattern);

        // --- Flying Scripts (WP Speed Matters) ---
        $this->add_array_exclusion('flying_scripts_excluded_patterns', $pattern);

        // --- Asset CleanUp Pro ---
        $this->add_array_exclusion('wpacu_get_js_hrefs_to_ignore_minification', $pattern);

        // --- Cloudflare Rocket Loader + generic optimizers ---
        // NOTE: This filter only applies to scripts registered via wp_enqueue_script().
        // Our injected player script goes directly into post content via inject_server_side_player()
        // and never passes through the WP script queue — the data-* attributes in render_single() do that job.
        // This filter is kept only as a safety net for any future enqueued scripts.
        add_filter('script_loader_tag', function ($tag, $handle) use ($pattern) {
            if (strpos($tag, $pattern) === false) {
                return $tag;
            }
            // Strip SRI integrity + crossorigin attributes injected by caching/CDN plugins
            // (e.g. Cloudflare, WP Rocket SRI, Autoptimize). When the remote JS is updated
            // the cached hash becomes stale and causes an integrity mismatch error in browsers.
            $tag = preg_replace('/\s+integrity="[^"]*"/', '', $tag);
            $tag = preg_replace('/\s+crossorigin="[^"]*"/', '', $tag);
            if (strpos($tag, 'data-cfasync') === false) {
                $tag = str_replace(
                    '<script ',
                    '<script data-cfasync="false" data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-nitro-exclude ',
                    $tag
                );
            }
            return $tag;
        }, PHP_INT_MAX, 2);
    }

    // =========================================================================
    // ONE-TIME CACHE CLEAR ON PLUGIN UPGRADE
    //
    // Safety guarantees:
    //   1. DB-option guard — runs exactly ONCE per version bump, never repeats
    //   2. FIX #3: Transient mutex — prevents thundering herd when multiple
    //              PHP-FPM workers race between get_option and update_option
    //   3. Skips AJAX, REST API, WP-CLI, and cron — no race conditions
    //   4. Version option updated BEFORE clearing — partial failures don't retry
    //   5. Every cache function guarded with function_exists / class_exists
    //   6. FIX #4: wp_cache_flush() only called when no external object cache
    //              (Redis/Memcached) is active — prevents thundering herd on DB
    //   7. Entire block wrapped in try/catch — exceptions logged, never surface
    //   8. Only clears cache files — never touches content, posts, or DB data
    // =========================================================================
    private function maybe_clear_cache_on_upgrade() {
        // Skip background/API contexts to avoid race conditions
        if (
            wp_doing_ajax()
            || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
        ) {
            return;
        }

        $stored_version = get_option(self::VERSION_OPTION_KEY, '0');

        // Only proceed if current plugin version is strictly newer than what's stored
        if (!version_compare($stored_version, $this->plugin_version, '<')) {
            return;
        }

        // FIX #3: Transient mutex — only ONE PHP worker runs the cache clear.
        // If another worker already acquired the lock, skip silently.
        // Lock expires in 60 seconds as a safety net against crashes mid-clear.
        if (get_transient(self::CACHE_CLEAR_LOCK_KEY)) {
            $this->log('Cache clear already in progress by another worker. Skipping.');
            return;
        }
        set_transient(self::CACHE_CLEAR_LOCK_KEY, 1, 60);

        $this->log("Plugin upgraded from {$stored_version} to {$this->plugin_version}. Triggering one-time cache clear.");

        // Store the new version FIRST — if cache clear partially fails, we don't retry forever
        update_option(self::VERSION_OPTION_KEY, $this->plugin_version, false);

        $this->clear_partner_cache();

        // Release lock after clear completes
        delete_transient(self::CACHE_CLEAR_LOCK_KEY);
    }

    /**
     * Clears only minified/combined JS caches — NOT the entire site page cache.
     *
     * Why targeted instead of full-site purge:
     *   - init_optimization_exclusions() already tells every plugin to skip Instaread
     *     scripts going forward, so page caches will naturally serve correct content.
     *   - The only stale artifacts are previously-minified JS bundles that baked in
     *     an older version of our script. Clearing those is enough.
     *   - A full-site purge (rocket_clean_domain, w3tc_flush_all, etc.) causes a
     *     thundering herd of cache rebuilds on high-traffic partner sites.
     *
     * Opt-in escape hatch:
     *   - Set "clear_page_cache_on_upgrade": true in partner config.json to also
     *     purge WP Rocket's full page cache on version bump. Use only when stale
     *     HTML caches are demonstrably hiding the player on upgrade.
     *
     * Safe by design:
     *   - Every call is guarded with function_exists / class_exists / method_exists
     *   - Only deletes minified JS caches, never page cache, content, or DB data
     *   - Wrapped in try/catch so any unexpected error is logged, never shown to visitors
     */
    private function clear_partner_cache() {
        $clear_page_cache = !empty($this->partner_config['clear_page_cache_on_upgrade']);

        try {
            // --- WP Rocket (JS minify only, NOT page cache) ---
            if (function_exists('rocket_clean_minify')) {
                rocket_clean_minify('js');
                $this->log('Cleared WP Rocket minified JS cache.');
            }

            // --- WP Rocket page cache (opt-in per partner) ---
            // Triggers a full-site page cache purge so the next visitor to each URL
            // re-runs the_content filter and gets the updated server-side injection.
            // Only enable for partners where stale page caches are causing missing
            // players after plugin upgrades. Causes a brief CPU/DB load spike on
            // high-traffic sites until the cache re-warms.
            //
            // Belt-and-suspenders: rocket_clean_domain() alone sometimes misses cache
            // variants on multi-cookie / multi-device setups. We also call:
            //   - rocket_clean_files() with the home URL (forces filesystem deletion)
            //   - rocket_clean_post() per recent post (catches post-specific cache keys)
            //   - flush_rocket_htaccess() (rebuilds rewrite rules)
            if ($clear_page_cache) {
                if (function_exists('rocket_clean_domain')) {
                    rocket_clean_domain();
                    $this->log('Cleared WP Rocket page cache (rocket_clean_domain).');
                }
                if (function_exists('rocket_clean_files')) {
                    rocket_clean_files(home_url('/'));
                    $this->log('Forced WP Rocket cache file deletion for home URL.');
                }
                if (function_exists('flush_rocket_htaccess')) {
                    flush_rocket_htaccess();
                    $this->log('Flushed WP Rocket htaccess rules.');
                }
                // Per-post purge for the most recent published posts — catches cases
                // where the global purge missed individual post cache variants.
                if (function_exists('rocket_clean_post')) {
                    $recent_post_ids = get_posts([
                        'post_type'      => 'post',
                        'post_status'    => 'publish',
                        'numberposts'    => 50,
                        'fields'         => 'ids',
                        'no_found_rows'  => true,
                        'suppress_filters' => true,
                    ]);
                    foreach ($recent_post_ids as $pid) {
                        rocket_clean_post($pid);
                    }
                    $this->log('Per-post WP Rocket purge for ' . count($recent_post_ids) . ' recent posts.');
                }
            }

            // --- Autoptimize (JS/CSS aggregation cache) ---
            if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
                autoptimizeCache::clearall();
                $this->log('Cleared Autoptimize cache.');
            }

            // --- Redis Object Cache (and any drop-in object cache: Memcached, etc.) ---
            // Some hosts (Kinsta, WP Engine, manual setups) cache rendered HTML fragments,
            // post content, and serialized objects in Redis via wp-content/object-cache.php.
            // Stale entries can cause the new plugin's the_content output to be ignored on
            // upgrade. Only runs when clear_page_cache_on_upgrade is true (opt-in) because
            // a global object-cache flush briefly hits the DB until the cache rewarms.
            if ($clear_page_cache && wp_using_ext_object_cache()) {
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                    $this->log('Flushed external object cache (Redis/Memcached) via wp_cache_flush().');
                }
                // Till Krüss "Redis Object Cache Pro" exposes a richer API; use it when present.
                if (class_exists('RedisCachePro\\Plugin') && method_exists('RedisCachePro\\Plugin', 'flush')) {
                    \RedisCachePro\Plugin::flush();
                    $this->log('Flushed Redis Object Cache Pro.');
                }
            }

            // --- W3 Total Cache (minify only) ---
            if (function_exists('w3tc_flush_minify')) {
                w3tc_flush_minify();
                $this->log('Cleared W3 Total Cache minify cache.');
            }

            // --- LiteSpeed Cache (CSS/JS only) ---
            if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge')) {
                LiteSpeed_Cache_API::purge('css_js');
                $this->log('Cleared LiteSpeed CSS/JS cache.');
            }

            // --- Swift Performance (JS only where available) ---
            if (class_exists('Swift_Performance_Cache') && method_exists('Swift_Performance_Cache', 'clear_assets_cache')) {
                Swift_Performance_Cache::clear_assets_cache();
                $this->log('Cleared Swift Performance assets cache.');
            }

            // --- NitroPack (FULL purge required) ---
            // NitroPack caches both rendered HTML AND proxies external scripts to
            // its CDN (cdn-*.nitrocdn.com). Even with our scripts excluded from
            // optimization, the cached HTML contains the previously-proxied URLs.
            // Observed on lakersnation 2026-05 to 2026-06: site stayed on the
            // NitroPack-proxied script for days after the plugin upgraded.
            //
            // NitroPack's PHP API changed names across plugin versions. Fire all
            // known purge entry points unconditionally — at most one succeeds, the
            // rest no-op silently. The do_action() calls are safe even when no
            // listener is registered (WP just iterates an empty queue).
            $nitro_detected = class_exists('NitroPack\\WordPress\\NitroPack')
                || class_exists('NitroPack\\NitroPack')
                || function_exists('nitropack_sdk_purge_all')
                || function_exists('nitropack_purge_all');

            if ($nitro_detected) {
                // 1. Older helper functions (NitroPack < 2.x)
                if (function_exists('nitropack_sdk_purge_all')) {
                    @nitropack_sdk_purge_all();
                    $this->log('Called nitropack_sdk_purge_all().');
                }
                if (function_exists('nitropack_purge_all')) {
                    @nitropack_purge_all();
                    $this->log('Called nitropack_purge_all().');
                }

                // 2. Canonical action hook (NitroPack 2.x+). Always fire — listener
                //    is registered by NitroPack at plugin init regardless of our
                //    call order, so has_action() may underreport in shutdown phases.
                do_action('nitropack_integration_purge_all');
                $this->log('Fired do_action(nitropack_integration_purge_all).');

                // 3. NitroPack singleton (current namespaced API). Wrapped in
                //    method_exists because the method name changed once
                //    (purgeCache → purgeAllCache).
                if (class_exists('NitroPack\\WordPress\\NitroPack')) {
                    try {
                        $instance = call_user_func(['NitroPack\\WordPress\\NitroPack', 'getInstance']);
                        if (is_object($instance)) {
                            if (method_exists($instance, 'purgeAllCache')) {
                                $instance->purgeAllCache();
                                $this->log('Called NitroPack::purgeAllCache().');
                            } elseif (method_exists($instance, 'purgeCache')) {
                                $instance->purgeCache();
                                $this->log('Called NitroPack::purgeCache().');
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->log('NitroPack instance purge failed (non-fatal): ' . $e->getMessage());
                    }
                }
            }

            // --- SiteGround Optimizer (FULL dynamic + file cache purge) ---
            // SiteGround caches the rendered HTML in its dynamic cache (NGINX) and
            // file-based cache. When our enqueued player script is dropped/skipped by
            // SiteGround's JS combine/defer at the moment a page is cached, the stale
            // HTML keeps serving WITHOUT the script even though our exclusion filters
            // are registered — the exclusions only apply when the tag is re-rendered.
            // Observed on iowaspulse 2026-06: <instaread-player> slot present but
            // instaread.{publication}.js missing because the page was a stale SG cache.
            //
            // Only runs when clear_page_cache_on_upgrade is true (opt-in) because a
            // full SG purge forces every URL to re-render on next visit. SiteGround's
            // PHP API has changed names across versions, so fire all known entry points
            // unconditionally — at most one succeeds, the rest no-op silently.
            if ($clear_page_cache) {
                // 1. Helper function (SG Optimizer all versions)
                if (function_exists('sg_cachepress_purge_cache')) {
                    @sg_cachepress_purge_cache();
                    $this->log('Purged SiteGround cache (sg_cachepress_purge_cache).');
                }

                // 2. Supercacher class (namespaced API, SG Optimizer 5.x+).
                //    Method name differs across versions: purge_everything (full)
                //    or purge_cache (current request scope). Prefer the broadest.
                if (class_exists('SiteGround_Optimizer\\Supercacher\\Supercacher')) {
                    try {
                        $sg = 'SiteGround_Optimizer\\Supercacher\\Supercacher';
                        if (method_exists($sg, 'purge_everything')) {
                            call_user_func([$sg, 'purge_everything']);
                            $this->log('Purged SiteGround Supercacher::purge_everything().');
                        } elseif (method_exists($sg, 'purge_cache')) {
                            call_user_func([$sg, 'purge_cache']);
                            $this->log('Purged SiteGround Supercacher::purge_cache().');
                        }
                    } catch (\Throwable $e) {
                        $this->log('SiteGround Supercacher purge failed (non-fatal): ' . $e->getMessage());
                    }
                }

                // 3. Action hooks — fired unconditionally; safe with no listener.
                do_action('sg_cachepress_purge_cache');
                do_action('siteground_optimizer_flush_cache');
                $this->log('Fired SiteGround purge action hooks.');
            }

            // Plugins without targeted JS-only purge APIs (Hummingbird, WP Super Cache,
            // Cache Enabler, Breeze, Comet Cache) are intentionally skipped here. Their
            // page caches will naturally serve correct content because
            // init_optimization_exclusions() already excludes Instaread scripts from
            // minification/combination.

        } catch (\Exception $e) {
            $this->log('Cache clear error (non-fatal): ' . $e->getMessage());
        }
    }

    // =========================================================================
    // CORE PLUGIN METHODS
    // =========================================================================

    private function init_update_checker() {
        $update_url = $this->partner_config
            ? "https://raw.githubusercontent.com/instaread-co/Instaread-Plugin/main/partners/{$this->partner_config['partner_id']}/plugin.json"
            : 'https://instaread-co.github.io/Instaread-Plugin/plugin.json';

        PucFactory::buildUpdateChecker(
            $update_url,
            __FILE__,
            $this->partner_config ? 'instaread-' . $this->partner_config['partner_id'] : 'instaread-audio-player'
        );
        $this->log("Update checker initialized: $update_url");
    }

    // FIX #5: Removed get_plugin_version() — version now comes from PLUGIN_VERSION constant.
    // get_plugin_data() was reading and parsing the plugin file header on every single request.

    private function get_settings() {
        // suppress_urls always comes from WP admin settings, regardless of config.json
        $wp_raw       = get_option('instaread_settings', []);
        $raw_suppress  = $wp_raw['suppress_urls'] ?? '';
        $suppress_urls = array_values(array_filter(array_map('trim', explode("\n", $raw_suppress))));

        if ($this->partner_config) {
            $this->log('Loaded settings from partner config');
            return [
                'publication'         => $this->partner_config['publication'] ?? 'default',
                'injection_rules'     => $this->partner_config['injection_rules'] ?? [
                    ['target_selector' => '.entry-content', 'insert_position' => 'append'],
                ],
                'injection_context'   => $this->partner_config['injection_context'] ?? 'singular',
                'use_player_loader'   => !empty($this->partner_config['use_player_loader']),
                'suppress_urls'       => $suppress_urls,
            ];
        }

        $wp = $wp_raw;
        $this->log('Loaded settings from WP options');

        return [
            'publication'         => $wp['publication'] ?? 'default',
            'injection_rules'     => [[
                'target_selector' => $wp['target_selector'] ?? '.entry-content',
                'insert_position' => $wp['insert_position'] ?? 'append',
            ]],
            'injection_context'   => $wp['injection_context'] ?? 'singular',
            'use_player_loader'   => !empty($wp['use_player_loader']),
            'suppress_urls'       => $suppress_urls,
        ];
    }

    public function enable_auto_updates($update, $item) {
        $plugin_basename = plugin_basename(__FILE__);
        $result          = (isset($item->plugin) && $item->plugin === $plugin_basename) ? true : $update;
        $this->log('Auto-update checked for: ' . $plugin_basename . ' Result: ' . ($result ? 'Enabled' : 'Disabled'));
        return $result;
    }

    /**
     * Fires immediately on plugin activation via register_activation_hook.
     * Sets a flag so telemetry is sent on next admin_init.
     */
    public function on_plugin_activated() {
        set_transient('instaread_send_activation_telemetry', 1, HOUR_IN_SECONDS);
    }

    /**
     * Fires after WordPress completes any plugin upgrade.
     * Sets a transient so we can show an admin notice, then pings telemetry.
     */
    public function on_plugin_updated($upgrader, $hook_extra) {
        $action = $hook_extra['action'] ?? '';
        $type   = $hook_extra['type']   ?? '';

        if ($type !== 'plugin' || !in_array($action, ['install', 'update'], true)) {
            return;
        }

        // WordPress uses 'plugin' (singular) for manual installs and direct Plugin_Upgrader calls,
        // and 'plugins' (plural array) for bulk/auto-updates via WP_Automatic_Updater.
        $our_basename = plugin_basename(__FILE__);
        $affected = array_filter(array_merge(
            (array) ($hook_extra['plugin']  ?? []),
            (array) ($hook_extra['plugins'] ?? [])
        ));

        if (!in_array($our_basename, $affected, true)) {
            return;
        }

        // The in-memory plugin object was instantiated from the OLD code at the
        // start of this request. WordPress has just replaced the files on disk,
        // but PHP does not reload classes mid-request — so $this->plugin_version
        // still reflects the pre-upgrade version. Re-read config.json from disk
        // to get the new version string.
        $old_version = $this->plugin_version;
        $new_version = $this->read_version_from_disk();

        update_option(self::VERSION_OPTION_KEY, $new_version);

        if ($action === 'install') {
            set_transient('instaread_just_installed', $new_version, DAY_IN_SECONDS);
            $this->send_telemetry('install', null, $new_version);
        } else {
            set_transient('instaread_just_updated', $new_version, DAY_IN_SECONDS);
            $this->send_telemetry('update', $old_version, $new_version);
        }
    }

    /**
     * Re-read the partner config.json from disk to get the freshly-installed
     * version string. Used by upgrade telemetry, where the in-memory
     * $this->plugin_version still reflects the pre-upgrade code.
     * Falls back to $this->plugin_version if the file can't be read.
     */
    private function read_version_from_disk() {
        $config_path = __DIR__ . '/config.json';
        if (!file_exists($config_path)) {
            return $this->plugin_version;
        }
        $raw = @file_get_contents($config_path);
        if ($raw === false) {
            return $this->plugin_version;
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data['version'])) {
            return $this->plugin_version;
        }
        return (string) $data['version'];
    }

    /**
     * Send activation telemetry on first admin_init after plugin activation.
     * Triggered by transient set in on_plugin_activated().
     */
    public function maybe_send_activation_telemetry() {
        if (!get_transient('instaread_send_activation_telemetry')) {
            return;
        }

        delete_transient('instaread_send_activation_telemetry');

        $old_version = get_option(self::VERSION_OPTION_KEY, '0');
        update_option(self::VERSION_OPTION_KEY, $this->plugin_version);

        $this->send_telemetry('install', $old_version, $this->plugin_version);
        $this->log('Activation telemetry sent via admin_init fallback.');
    }

    /**
     * Handles instant update check triggered by webhook
     * When webhook sends ?instaread_force_update_check=partner_id,
     * immediately check for updates (don't wait 12 hours)
     */
    public function maybe_force_update_check() {
        // Get parameter if present
        $force_check = isset($_GET['instaread_force_update_check'])
            ? sanitize_text_field($_GET['instaread_force_update_check'])
            : '';

        if (empty($force_check)) {
            return;
        }

        // Verify it matches our partner_id
        if ($force_check !== ($this->partner_config['partner_id'] ?? '')) {
            return;
        }

        $partner_id = $this->partner_config['partner_id'] ?? '';
        $this->log('Webhook triggered instant update check for ' . $partner_id);

        // Clear all update cache transients to force immediate check
        delete_transient('plugin_update_checker_' . $partner_id);
        delete_transient('update_plugins');
        // Clear PUC's own state option (stores cached update check result)
        delete_option('external_updates-instaread-' . $partner_id);

        // Set a transient flag to trigger auto-update on next request
        set_transient('instaread_force_update', $partner_id, HOUR_IN_SECONDS);

        // Immediately trigger update check
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
            $this->log('Update check executed for ' . $partner_id);
        }

        // Schedule immediate cron event to apply updates
        if (!wp_next_scheduled('instaread_maybe_auto_update')) {
            wp_schedule_single_event(time(), 'instaread_maybe_auto_update');
        }

        // Also trigger a loopback request to ensure WordPress runs and processes updates
        $this->trigger_loopback_update_check($partner_id);

        $this->log('Auto-update triggered for ' . $partner_id);
    }

    /**
     * Trigger a loopback request to force WordPress to run and process pending updates.
     * This ensures the upgrade process runs even if no one visits the site.
     */
    private function trigger_loopback_update_check($partner_id) {
        // Get site URL
        $site_url = home_url('/?instaread_update_check=' . urlencode($partner_id));

        // Use a loopback request with a unique marker
        $response = wp_safe_remote_get(
            $site_url,
            [
                'blocking'  => false,  // Non-blocking so webhook doesn't wait
                'timeout'   => 5,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
            ]
        );

        if (is_wp_error($response)) {
            $this->log('Loopback request failed: ' . $response->get_error_message());
        } else {
            $this->log('Loopback update check triggered');
        }
    }

    /**
     * Handle loopback update check request.
     * This runs WordPress' update process in a proper context.
     */
    public function handle_loopback_update_check() {
        $partner_id = isset($_GET['instaread_update_check']) ? sanitize_text_field($_GET['instaread_update_check']) : '';

        if (empty($partner_id) || $partner_id !== ($this->partner_config['partner_id'] ?? '')) {
            return;
        }

        $this->log('Loopback check: Applying auto-update directly from plugin.json');

        // Directly trigger auto-update — fetches plugin.json directly, no transient dependency
        $this->trigger_auto_update_now();

        // Exit silently (don't output HTML)
        wp_die('', '', ['response' => 200]);
    }

    /**
     * Trigger auto-update now (called via scheduled cron event).
     * This allows the webhook to initiate updates without waiting for admin page load.
     */
    public function trigger_auto_update_now() {
        $partner_id = get_transient('instaread_force_update');
        if (!$partner_id || $partner_id !== ($this->partner_config['partner_id'] ?? '')) {
            return;
        }

        // Directly fetch plugin.json — bypasses update_plugins transient which may not be populated yet
        $plugin_json_url = "https://raw.githubusercontent.com/instaread-co/Instaread-Plugin/main/partners/{$partner_id}/plugin.json";
        $response = wp_safe_remote_get($plugin_json_url, ['timeout' => 15, 'sslverify' => true]);

        if (is_wp_error($response)) {
            $this->log('Failed to fetch plugin.json: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $plugin_data = json_decode($body, true);

        if (empty($plugin_data['version']) || empty($plugin_data['download_url'])) {
            $this->log('Invalid plugin.json response: ' . $body);
            return;
        }

        $remote_version = $plugin_data['version'];
        $current_version = $this->partner_config['version'] ?? self::PLUGIN_VERSION;

        $this->log("Remote version: {$remote_version}, Current version: {$current_version}");

        if (!version_compare($remote_version, $current_version, '>')) {
            $this->log("No update needed: remote {$remote_version} <= current {$current_version}");
            delete_transient('instaread_force_update');
            return;
        }

        $this->log("Applying update: {$current_version} -> {$remote_version}");

        // Load all required admin dependencies for upgrader
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        // Initialize WP_Filesystem — required for Plugin_Upgrader to write files
        WP_Filesystem();

        // Disable file modification checks that block upgrades on some hosts
        add_filter('file_mod_allowed', '__return_true');

        // Use install() with the direct download URL — bypasses Plugin_Upgrader's internal
        // version check (which would block if the transient doesn't agree) and installs
        // the zip directly over the existing plugin directory.
        $our_basename    = plugin_basename(__FILE__);
        $our_plugin_dir  = dirname(WP_PLUGIN_DIR . '/' . $our_basename);
        $download_url    = $plugin_data['download_url'];

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);

        // install() downloads + extracts the zip; the second arg tells it to replace
        // an existing plugin (destination folder) rather than create a fresh one.
        $result = $upgrader->install($download_url, ['overwrite_package' => true]);

        $this->log('Auto-update result: ' . print_r($result, true));
        $this->log('Skin feedback: ' . print_r($skin->get_upgrade_messages(), true));

        // Send telemetry email only if install ACTUALLY succeeded.
        // Plugin_Upgrader::install() returns either:
        //   - true on success
        //   - false on a generic failure (e.g. ZIP missing)
        //   - WP_Error on a structured failure (file permissions, extraction
        //     error, fs_unavailable, etc.)
        // is_wp_error() is required because WP_Error objects are TRUTHY in PHP,
        // so a bare `if ($result)` would report success for a failed upgrade.
        if ($result === true) {
            // Reset PHP OPcache so the freshly-written files are loaded on next request.
            // Without this, PHP keeps serving old compiled bytecode for hours even though
            // the plugin files on disk have been replaced — install reports success but
            // the running code stays at the old version. This was the root cause of
            // startsat60's `4.5.7 → 4.5.8` install loop where each install genuinely
            // succeeded on disk but OPcache served the prior version's PHP forever.
            // opcache_reset() is safe — invalidates all cached scripts; PHP recompiles
            // on next access. No-op if OPcache extension isn't loaded.
            if (function_exists('opcache_reset')) {
                @opcache_reset();
                $this->log('OPcache reset after install — fresh PHP files will load on next request.');
            }
            // Belt-and-suspenders: invalidate just our plugin's specific files.
            if (function_exists('opcache_invalidate')) {
                $plugin_dir = dirname(__FILE__);
                foreach (glob($plugin_dir . '/*.php') as $php_file) {
                    @opcache_invalidate($php_file, true);
                }
            }

            update_option(self::VERSION_OPTION_KEY, $remote_version);
            $this->send_telemetry('update', $current_version, $remote_version);
            set_transient('instaread_just_updated', $remote_version, DAY_IN_SECONDS);
        } else {
            $error_msg = is_wp_error($result)
                ? $result->get_error_code() . ': ' . $result->get_error_message()
                : 'install() returned ' . var_export($result, true);
            $this->log('Auto-update FAILED for ' . $partner_id . ' v' . $remote_version . ' — ' . $error_msg);
            $this->send_telemetry('update_failed', $current_version, $remote_version);
        }

        delete_transient('instaread_force_update');
    }

    /**
     * Shows a dismissible admin notice once after an auto-update completes.
     */
    public function show_update_admin_notice() {
        $partner = $this->partner_config['partner_id'] ?? '';
        $label   = $partner ? "Instaread Player ({$partner})" : 'Instaread Player';

        if ($version = get_transient('instaread_just_installed')) {
            delete_transient('instaread_just_installed');
            echo '<div class="notice notice-success is-dismissible"><p>'
                . '<strong>' . esc_html($label) . '</strong> v<strong>' . esc_html($version)
                . '</strong> has been installed successfully.</p></div>';
            return;
        }

        if ($version = get_transient('instaread_just_updated')) {
            delete_transient('instaread_just_updated');
            echo '<div class="notice notice-success is-dismissible"><p>'
                . '<strong>' . esc_html($label) . '</strong> was updated to version <strong>'
                . esc_html($version) . '</strong>.</p></div>';
        }
    }

    /**
     * Sends a once-daily heartbeat ping so we know the plugin is active.
     */
    public function maybe_send_heartbeat() {
        if (get_transient('instaread_heartbeat_sent')) {
            return;
        }
        set_transient('instaread_heartbeat_sent', 1, DAY_IN_SECONDS);
        $this->send_telemetry('heartbeat', null, $this->plugin_version);
    }

    /**
     * Non-blocking fire-and-forget POST to player-api telemetry endpoint.
     * Used for both 'update' and 'heartbeat' events.
     */
    private function send_telemetry($event, $old_version, $new_version) {
        $telemetry_endpoint = defined('INSTAREAD_TELEMETRY_ENDPOINT')
            ? INSTAREAD_TELEMETRY_ENDPOINT
            : 'https://player-api.instaread.co/api/plugin-telemetry';

        wp_remote_post($telemetry_endpoint, [
            'body'     => wp_json_encode([
                'event'       => $event,
                'partner_id'  => $this->partner_config['partner_id'] ?? 'unknown',
                'version'     => $new_version,
                'old_version' => $old_version,
                'site_url'    => get_site_url(),
                'timestamp'   => time(),
            ]),
            'headers'  => ['Content-Type' => 'application/json'],
            'timeout'  => 5,
            'blocking' => false,
        ]);
        $this->log("Telemetry sent: event={$event} version={$new_version}");
    }

    public function register_settings() {
        register_setting('instaread_settings', 'instaread_settings', [$this, 'sanitize_settings']);
        add_settings_section('instaread_main', 'Instaread Config', null, 'instaread-settings');
        add_settings_field('instaread_publication', 'Publication', [$this, 'field_text'], 'instaread-settings', 'instaread_main', ['key' => 'publication']);
        add_settings_field(
            'instaread_suppress_urls',
            'Suppress Player on URLs',
            [$this, 'field_textarea'],
            'instaread-settings',
            'instaread_main',
            [
                'key'         => 'suppress_urls',
                'description' => 'Please paste the complete URL for suppressing the player. For adding multiple URLs, please paste each URL on another line.',
            ]
        );
        $this->log('Registered WP admin settings');
    }

    public function field_text($args) {
        $key   = $args['key'];
        $value = esc_attr($this->settings[$key] ?? '');
        echo "<input type='text' name='instaread_settings[$key]' value='$value'>";
        $this->log("Rendered field for key: $key, value: $value");
    }

    public function field_textarea($args) {
        $key   = $args['key'];
        $raw   = $this->settings[$key] ?? [];
        $value = esc_textarea(is_array($raw) ? implode("\n", $raw) : (string) $raw);
        $desc  = isset($args['description']) ? '<p class="description">' . esc_html($args['description']) . '</p>' : '';
        echo "<textarea name='instaread_settings[$key]' rows='6' cols='50' style='font-family:monospace;'>$value</textarea>$desc";
    }

    public function add_settings_page() {
        add_options_page('Instaread Settings', 'Instaread Player', 'manage_options', 'instaread-settings', [$this, 'render_admin']);
        $this->log('Added WP admin settings page');
    }

    public function render_admin() {
        ?>
        <div class="wrap"><h1>Instaread Player Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('instaread_settings');
                do_settings_sections('instaread-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
        $this->log('Rendered admin page');
    }

    /**
     * Returns true when the current request matches the configured injection_context.
     *
     * Called from both enqueue_assets() (to gate partner.js) and
     * inject_server_side_player() (redundant safety check). WordPress
     * conditional tags are fully available by the time either hook fires.
     *
     * Supports:
     *   "post"     / "single"  → is_single()  (standard WP posts only)
     *   "singular"             → is_singular() (posts + pages + CPTs)
     *   "page"                 → is_page()
     *   ["post", "review", …]  → is_singular() restricted to those post types
     */
    private function should_inject() {
        // Body class suppression — checked first because it is the most authoritative
        // signal for custom post types (e.g. 'post-author' CPT) that share the /author/
        // URL prefix but resolve as is_singular() = true, which would otherwise pass
        // the injection_context gate below.
        //
        // WordPress adds  'single-post-{post_type}'  to the body for every singular CPT.
        // A regular article gets 'single-post'; an author profile CPT gets 'single-post-author'.
        //
        // Config key: suppress_body_classes  (array, e.g. ["single-post-author"])
        // The script (enqueue_remote_player_script_sitewide) is NOT gated here — it is
        // controlled separately by maybe_enqueue_remote_instaread_player_script() so the
        // floating player JS still loads on suppressed pages.
        if (!empty($this->partner_config['suppress_body_classes']) && is_array($this->partner_config['suppress_body_classes'])) {
            $body_classes = (array) get_body_class();
            foreach ($this->partner_config['suppress_body_classes'] as $blocked_class) {
                if (in_array(trim((string) $blocked_class), $body_classes, true)) {
                    return false;
                }
            }
        }

        // Admin-configured URL suppression — checked after body-class but before context gate.
        if (!empty($this->settings['suppress_urls']) && is_array($this->settings['suppress_urls'])) {
            $request_path = strtok(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', '?');
            $request_path = '/' . trim($request_path, '/');
            foreach ($this->settings['suppress_urls'] as $suppressed) {
                // Accept either a full URL or a bare path — extract path either way.
                if (strpos($suppressed, '://') !== false) {
                    $parsed    = parse_url($suppressed);
                    $suppressed = $parsed['path'] ?? $suppressed;
                }
                $normalized = '/' . trim($suppressed, '/');
                if ($request_path === $normalized) {
                    return false;
                }
            }
        }

        $ctx = $this->settings['injection_context'];

        // Array form: ["post", "custom_post_type", ...]
        if (is_array($ctx)) {
            if (!is_singular()) {
                return false;
            }
            global $post;
            return !empty($post) && in_array($post->post_type, $ctx, true);
        }

        switch ($ctx) {
            case 'post':
            case 'single':
                return is_single();          // strictly WP posts (post_type = 'post')
            case 'singular':
                return is_singular();        // posts + pages + CPTs
            case 'page':
                return is_page();
            default:
                return false;
        }
    }

    /**
     * Opt-in (per partner config.json): load instaread.{publication}.js site-wide so the floating
     * player can work off article pages. Default false — preserves legacy behavior unless enabled.
     *
     * Filter: instaread_enqueue_remote_player_script_sitewide — override boolean; receives settings and partner_config.
     */
    private function should_enqueue_remote_player_script_sitewide() {
        $from_config = !empty($this->partner_config['enqueue_remote_player_script_sitewide']);
        return (bool) apply_filters(
            'instaread_enqueue_remote_player_script_sitewide',
            $from_config,
            $this->settings,
            $this->partner_config
        );
    }

    /**
     * Publication id used in player URLs and instaread.{publication}.js (aligned with inject_server_side_player).
     */
    private function get_resolved_publication() {
        $publication = $this->settings['publication'];
        if (!empty($this->partner_config['dynamic_publication_from_host'])) {
            $host_parts  = explode('.', parse_url(home_url(), PHP_URL_HOST));
            $publication = reset($host_parts) ?: $publication;
        }
        return $publication;
    }

    /**
     * When true, emit instaread.playerv3.js (stable URL for partner SRI) instead of
     * instaread.{publication}.js. playerv3 loads the publication bundle at runtime
     * without an integrity attribute, so Instaread can ship publication updates freely.
     *
     * Enabled via partner config.json "use_player_loader": true, or WP option
     * instaread_settings["use_player_loader"], or filter instaread_use_player_loader.
     */
    private function should_use_player_loader() {
        $from_settings = !empty($this->settings['use_player_loader']);
        return (bool) apply_filters(
            'instaread_use_player_loader',
            $from_settings,
            $this->settings,
            $this->partner_config
        );
    }

    /**
     * Cache-buster for remote script URL (aligned with former inline script query string).
     */
    private function get_remote_instaread_script_version() {
        return (int) (floor(time() / 60) * 60000);
    }

    /**
     * Inline publisher script tag when not using site-wide wp_enqueue_script (legacy / default).
     */
    private function get_inline_instaread_player_script_tag($publication) {
        // Per-optimizer opt-out attributes:
        //   data-cfasync="false"  → Cloudflare Rocket Loader
        //   data-no-optimize="1"  → WP Rocket / Autoptimize
        //   data-no-defer="1"     → WP Rocket
        //   data-no-minify="1"    → WP Rocket
        //   data-nitro-exclude    → NitroPack (it does NOT honor data-no-optimize/minify;
        //                          without this attribute NitroPack's HTML post-processor
        //                          proxies the script to cdn-*.nitrocdn.com/.../nitro-min-…)
        //   data-no-combine="1"   → SiteGround Optimizer. SG's combine step on raw <script>
        //                          tags (i.e. not via wp_enqueue_script) ignores the handle
        //                          exclusion list; observed on iowaspulse 2026-06: footer
        //                          fallback emitted instaread.{publication}.js raw, SG
        //                          combined it into siteground-optimizer-combined-js-*.js
        //                          and then dropped it because the URL is cross-origin.
        return sprintf(
            '<script defer
                data-cfasync="false"
                data-no-optimize="1"
                data-no-defer="1"
                data-no-minify="1"
                data-no-combine="1"
                data-nitro-exclude
                src="https://player.instaread.co/js/instaread.%s.js">
            </script>',
            esc_attr($publication)
        );
    }

    /**
     * Inline playerv3.js loader script tag — used when should_use_player_loader() is true.
     *
     * playerv3.js is a thin, stable bootstrapper that reads the publication attribute from
     * <instaread-player> elements already in the DOM and dynamically loads
     * instaread.{publication}.js at runtime via document.createElement('script').
     * Because the publication bundle is fetched without an integrity attribute, Instaread
     * can update it freely without causing hash-mismatch errors on the partner's side.
     *
     * Partners who pin playerv3.js with their own integrity="..." are safe to do so — the
     * file itself is intentionally stable and changes only on major architectural updates.
     */
    private function get_inline_playerv3_script_tag() {
        $url = !empty($this->partner_config['player_loader_url'])
            ? esc_url($this->partner_config['player_loader_url'])
            : 'https://player.instaread.co/js/instaread.playerv3.js';
        return sprintf(
            '<script defer
                data-cfasync="false"
                data-no-optimize="1"
                data-no-defer="1"
                data-no-minify="1"
                data-no-combine="1"
                data-nitro-exclude
                src="%s">
            </script>',
            $url
        );
    }

    /**
     * Enqueues https://player.instaread.co/js/instaread.{publication}.js on all front-end pages
     * when should_enqueue_remote_player_script_sitewide() is true. Playlist partners use a different
     * bundle from render_playlist(); skipped here.
     *
     * Filter: instaread_enqueue_remote_player_script — return false to disable; receives settings and partner_config.
     */
    private function is_siteground_optimizer_active() {
        // The SG Optimizer plugin's directory slug is sg-cachepress.
        // Detect via the helper function it always exposes (works across all versions),
        // its namespaced classes, or the combine-JS option being on.
        if (function_exists('sg_cachepress_purge_cache')) return true;
        if (function_exists('\\SiteGround_Optimizer\\Supercacher\\Supercacher::purge_cache')) return true;
        if (class_exists('\\SiteGround_Optimizer\\Loader\\Loader')) return true;
        if (class_exists('\\SiteGround_Optimizer\\Helper\\Helper')) return true;
        if (class_exists('\\SiteGround_Optimizer\\Supercacher\\Supercacher')) return true;
        if (defined('SG_OPTIMIZER_VERSION')) return true;
        // Plugin slug check via WP's plugin functions if loaded.
        if (function_exists('is_plugin_active') && is_plugin_active('sg-cachepress/sg-cachepress.php')) return true;
        // Last-ditch: combine-JS option key set to truthy.
        $combine = get_option('siteground_optimizer_combine_javascript');
        return $combine === '1' || $combine === 1 || $combine === true;
    }

    private function maybe_enqueue_remote_instaread_player_script() {
        if (is_admin() || is_feed()) {
            return;
        }
        if (!apply_filters('instaread_enqueue_remote_player_script', true, $this->settings, $this->partner_config)) {
            return;
        }
        if (!empty($this->partner_config['isPlaylist'])) {
            return;
        }
        if (!$this->should_enqueue_remote_player_script_sitewide()) {
            return;
        }

        // SiteGround Optimizer belt-and-braces safety net.
        //
        // The exclusion filters in init_optimization_exclusions() (using the real
        // SG filter names from sg-cachepress/trunk/core/Combinator/Js_Combinator.php)
        // SHOULD keep our bundle out of siteground-optimizer-combined-js-*.js. As a
        // safety net for older SG versions or future regressions, also emit a
        // dynamic-load IIFE on wp_footer when SG is detected. The IIFE creates the
        // <script> element at runtime via document.createElement('script') — SG's
        // output-buffer rewriter scans static <script src=""> tags, not dynamically-
        // created ones, so it can't combine this away. The IIFE self-guards against
        // double-load with __instaread_bundle_loaded.
        if ($this->is_siteground_optimizer_active()) {
            add_action('wp_footer', function () {
                if (!empty($this->partner_config['isPlaylist'])) return;
                $bundle_url = $this->should_use_player_loader()
                    ? (!empty($this->partner_config['player_loader_url'])
                        ? $this->partner_config['player_loader_url']
                        : 'https://player.instaread.co/js/instaread.playerv3.js')
                    : sprintf(
                        'https://player.instaread.co/js/instaread.%s.js',
                        rawurlencode($this->get_resolved_publication())
                    );
                printf(
                    '<script data-cfasync="false" data-no-optimize="1" data-no-combine="1" data-nitro-exclude>(function(){' .
                    'if(window.__instaread_bundle_loaded)return;' .
                    // Skip if WP-enqueued tag already loaded the bundle (regular path worked).
                    'if(document.querySelector(\'script[src*="player.instaread.co/js/instaread."]\'))return;' .
                    'window.__instaread_bundle_loaded=true;' .
                    'var s=document.createElement("script");s.defer=true;s.src=%s;' .
                    '(document.head||document.documentElement).appendChild(s);' .
                    '})();</script>',
                    wp_json_encode($bundle_url)
                );
            }, 5);
            $this->log('SG Optimizer detected: emitted dynamic-load IIFE safety-net (will be a no-op if the WP-enqueued script tag is already in DOM).');
            // FALL THROUGH to wp_enqueue_script — that's the primary path now that
            // exclusions use the real SG filter names.
        }

        // Config guard: when use_player_loader is enabled, enqueue playerv3.js sitewide instead
        // of the publication-specific bundle. playerv3.js reads <instaread-player publication="...">
        // from the DOM and dynamically loads the publication bundle — no integrity risk on updates.
        if ($this->should_use_player_loader()) {
            $loader_url = !empty($this->partner_config['player_loader_url'])
                ? $this->partner_config['player_loader_url']
                : 'https://player.instaread.co/js/instaread.playerv3.js';
            wp_enqueue_script(
                'instaread-player-loader',
                $loader_url,
                [],
                null,
                true
            );
            if (function_exists('wp_script_add_data')) {
                wp_script_add_data('instaread-player-loader', 'strategy', 'defer');
            }
            $this->log('Enqueued player loader script sitewide: ' . $loader_url);
            return;
        }

        $publication = $this->get_resolved_publication();
        $url         = sprintf(
            'https://player.instaread.co/js/instaread.%s.js',
            rawurlencode($publication)
        );

        wp_enqueue_script(
            'instaread-remote-player',
            $url,
            [],
            null,
            true
        );

        if (function_exists('wp_script_add_data')) {
            wp_script_add_data('instaread-remote-player', 'strategy', 'defer');
        }

        $this->log('Enqueued remote Instaread publisher script (sitewide / floating persistence).');
    }

    /**
     * Emits a <meta> tag in <head> with plugin version + partner id on every front-end page.
     * Lets us verify the deployed plugin version on any partner site without telemetry —
     * just `curl <url> | grep instaread-version`. Useful when telemetry POSTs are blocked
     * by host firewalls / security plugins.
     */
    public function emit_version_meta() {
        if (is_admin()) {
            return;
        }
        $partner_id = $this->partner_config['partner_id'] ?? 'default';
        printf(
            '<meta name="instaread-version" content="%s" data-partner="%s">' . "\n",
            esc_attr($this->plugin_version),
            esc_attr($partner_id)
        );
    }

    public function enqueue_assets() {
        // FIX #10: $instaread_partner_css is only populated on front-end non-admin
        //          requests — so this global is safely null on admin/AJAX/REST/cron.
        global $instaread_partner_css, $instaread_partner_css_file;
        if ($instaread_partner_css && file_exists($instaread_partner_css_file)) {
            $local_css_handle = 'instaread-local-style';
            wp_register_style($local_css_handle, false);
            wp_enqueue_style($local_css_handle);
            wp_add_inline_style($local_css_handle, $instaread_partner_css);
            $this->log('Enqueued local styles.css');
        }

        $partner_js_file = __DIR__ . '/partner.js';
        if (file_exists($partner_js_file)) {
            // Only enqueue partner.js on pages that match injection_context.
            // Previously this ran on every frontend page, causing partner.js to
            // inject the player on login/static/page routes via DOM selectors.
            if ($this->should_inject()) {
                wp_enqueue_script(
                    'instaread-partner-js',
                    plugin_dir_url(__FILE__) . 'partner.js',
                    [],
                    filemtime($partner_js_file),
                    true
                );

                $this->log('Enqueued partner.js.');
            } else {
                $this->log('Skipped partner.js: injection_context does not match current page.');
            }
        }

        // Opt-in: load publisher JS on every front-end URL when config requests it (floating player off post pages).
        $this->maybe_enqueue_remote_instaread_player_script();
    }

    public function inject_server_side_player($content) {
        // Static flag: the_content can fire multiple times per request (e.g. in
        // social-sharing plugins, SEO plugins, related-post widgets).  One
        // injection is enough; subsequent calls return the content unmodified.
        static $already_injected = false;
        if ($already_injected) {
            return $content;
        }

        $debug_mode = $this->is_debug_enabled();

        if (is_front_page() || is_home()) {
            if ($debug_mode) {
                $this->log('Skipping injection: front page / posts index');
            }
            return $content;
        }

        if (is_admin() || !is_main_query()) {
            return $content;
        }

        global $post;
        if (empty($post)) {
            return $content;
        }

        if (!is_string($content) || trim($content) === '') {
            return $content;
        }

        // Context check — single authoritative gate using should_inject().
        // Replaces the previous inline if/elseif chain and is consistent with
        // the gate added in enqueue_assets() for partner.js.
        if (!$this->should_inject()) {
            if ($debug_mode) $this->log('Skipping injection: injection_context does not match current page.');
            return $content;
        }

        // Prevent double injection from content already containing the player.
        // Only skip when a real <instaread-player> web component is present.
        // An empty <div class="instaread-player-slot"></div> can be left behind
        // by older client-side-injection plugin versions or hand-edited posts;
        // those should NOT block injection (regression seen on moonandspoonandyum
        // after the v2.8.x -> v4.5.x upgrade, 2026-05-19).
        if (strpos($content, '<instaread-player') !== false) {
            if ($debug_mode) $this->log('Skipping: <instaread-player> tag already present in content');
            return $content;
        }

        // Collect exclude slugs from all rules
        $exclude_slugs = [];
        foreach ($this->settings['injection_rules'] as $rule) {
            if (!empty($rule['exclude_slugs']) && is_array($rule['exclude_slugs'])) {
                $exclude_slugs = array_merge($exclude_slugs, $rule['exclude_slugs']);
            }
        }

        // Resolve current slug
        $current_slug = '';
        if (is_singular() && !empty($post->ID)) {
            $permalink = get_permalink($post->ID);
            if ($permalink) {
                $parsed       = parse_url($permalink);
                $current_slug = $parsed['path'] ?? '';
            }
        }
        if (empty($current_slug) || $current_slug === '/') {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '';
            if (!empty($request_uri) && $request_uri !== '/') {
                $current_slug = $request_uri;
            }
        }
        $current_slug = trim($current_slug);
        if ($current_slug === '' || $current_slug === '/') {
            $current_slug = '/';
        } else {
            if ($current_slug[0] !== '/') $current_slug = '/' . $current_slug;
            $current_slug = rtrim($current_slug, '/');
            if ($current_slug === '') $current_slug = '/';
        }

        $normalized_exclude_slugs = array_map(function ($slug) {
            $slug = trim($slug);
            $slug = rtrim($slug, '/');
            return $slug === '' ? '/' : $slug;
        }, $exclude_slugs);

        if ($normalized_exclude_slugs && in_array($current_slug, $normalized_exclude_slugs, true)) {
            if ($debug_mode) $this->log('Skipping excluded slug: ' . $current_slug);
            return $content;
        }

        $publication = $this->get_resolved_publication();

        $is_playlist     = !empty($this->partner_config['isPlaylist']);
        $player_type     = $this->partner_config['playerType'] ?? '';
        $color           = $this->partner_config['color'] ?? '#59476b';
        $slot_css        = $this->partner_config['slot_css'] ?? 'min-height:144px;';
        $playlist_height = $this->partner_config['playlist_height'] ?? '80vh';

        $injected = false;
        foreach ($this->settings['injection_rules'] as $rule) {
            $pos             = $rule['insert_position'] ?? 'append';
            $target_selector = $rule['target_selector'] ?? null;

            $player_html = $is_playlist
                ? $this->render_playlist($publication, $playlist_height)
                : $this->render_single($publication, $player_type, $color, $slot_css);

            $new_content = $this->inject_with_safe_string_manipulation($content, $player_html, $target_selector, $pos, false);

            if ($new_content !== $content) {
                $content  = $new_content;
                $injected = true;
                if ($debug_mode) $this->log("Injection successful with selector: {$target_selector}.");
                break;
            }
        }

        // Fallback: use first rule with JS-mover if nothing matched.
        // Suppressed when partner config sets "fallback_injection": false — meaning
        // "only inject when one of the listed selectors is actually found in the content".
        // Use this when multiple ordered selectors are defined and injecting into an
        // unrelated location is worse than not injecting at all.
        $allow_fallback_injection = $this->partner_config['fallback_injection'] ?? true;
        if (!$injected && $allow_fallback_injection && !empty($this->settings['injection_rules'])) {
            $first_rule       = $this->settings['injection_rules'][0];
            $player_html      = $is_playlist
                ? $this->render_playlist($publication, $playlist_height)
                : $this->render_single($publication, $player_type, $color, $slot_css);

            $original_content = $content;
            $content          = $this->inject_with_safe_string_manipulation(
                $content,
                $player_html,
                $first_rule['target_selector'],
                $first_rule['insert_position'] ?? 'append',
                true
            );
            if ($content !== $original_content) {
                $injected = true;
            }
        } elseif (!$injected && !$allow_fallback_injection && $debug_mode) {
            $this->log('No selector matched and fallback_injection is disabled — skipping injection.');
        }

        // Mark as done ONLY when injection actually modified the content.
        // Previously this was set unconditionally, which caused a subtle bug:
        // plugins/themes that call the_content() on excerpts/related-posts/
        // social-share snippets BEFORE the main article render would burn the
        // injection budget. The early fire passes all guards (same slug, same
        // post type), tries to inject, then sets the flag — and when the main
        // render fires later, this line bails immediately so no player ever
        // appears in the actual article body. Observed on bravewords.com and
        // thisdayinsport.com which both run Yoast Premium + AdInserter.
        // (Setting flag only when $injected is true matches v4.2.8 behavior.)
        if ($injected) {
            $already_injected = true;
        }

        return $content;
    }

    public function maybe_inject_via_footer() {
        // Respect injection_context — same gate as enqueue_assets() and inject_server_side_player().
        if (is_admin() || is_front_page() || is_home() || !$this->should_inject()) {
            return;
        }
        global $post;
        if (empty($post)) return;

        $content = get_the_content();
        if (strpos($content, 'instaread-player') !== false) {
            return;
        }

        // Server-side the_content injection didn't run (theme bypassed the filter, content
        // rebuilt by another plugin, etc.). Opt-in JS fallback: locate the configured target
        // selector in the rendered DOM and prepend the player slot client-side.
        if (empty($this->partner_config['enable_footer_js_fallback'])) {
            $this->log('Footer fallback: player not found in content (footer JS fallback disabled in config).');
            return;
        }

        $target = $this->partner_config['footer_js_fallback_selector'] ?? '.entry-content';
        $publication = $this->get_resolved_publication();
        $player_type = $this->partner_config['playerType'] ?? '';
        $color       = $this->partner_config['color'] ?? '#59476b';
        $slot_css    = $this->partner_config['slot_css'] ?? 'min-height:144px;';

        $slot_html = sprintf(
            '<div class="instaread-player-slot" data-instaread-version="%s" data-instaread-source="footer-js-fallback" style="%s"><instaread-player publication="%s" playertype="%s" color="%s"></instaread-player></div>',
            esc_attr($this->plugin_version),
            esc_attr($slot_css),
            esc_html($publication),
            esc_html($player_type),
            esc_html($color)
        );

        printf(
            '<script data-cfasync="false" data-no-optimize="1">(function(){var t=document.querySelector(%s);if(t&&!t.querySelector(".instaread-player-slot")){var d=document.createElement("div");d.innerHTML=%s;t.insertBefore(d.firstChild,t.firstChild);}})();</script>',
            wp_json_encode($target),
            wp_json_encode($slot_html)
        );

        // Some themes (e.g. tagDiv Newspaper with custom tdb-template builder) skip the
        // normal wp_footer script queue, so wp_enqueue_script('instaread-remote-player')
        // never prints. When footer JS fallback is active AND sitewide enqueue is opted in,
        // we know wp_footer IS firing (this method runs on it) but wp_print_footer_scripts
        // may be dropped — emit the publication bundle directly so the player can render.
        if ($this->should_enqueue_remote_player_script_sitewide()
            && !wp_script_is('instaread-remote-player', 'done')
            && empty($this->partner_config['isPlaylist'])) {
            if ($this->should_use_player_loader()) {
                echo $this->get_inline_playerv3_script_tag();
            } else {
                echo $this->get_inline_instaread_player_script_tag($publication);
            }
            $this->log('Footer fallback: emitted raw bundle script tag (wp_footer script queue bypassed).');
        }

        $this->log('Footer fallback: injected client-side player into ' . $target);
    }

    private function inject_with_safe_string_manipulation($content, $player_html, $target_selector, $insert_position, $allow_fallback = true) {
        $debug_mode = $this->is_debug_enabled();

        if (!is_string($content) || !is_string($player_html) || trim($content) === '') {
            return $content;
        }

        if (empty($target_selector)) {
            // WordPress-native injection: $content is already just the article body
            // (from the_content filter), so CSS selectors won't match. Empty selector
            // means: directly prepend/append player to article body without selector
            // searching or JS mover fallback.
            if (in_array($insert_position, ['prepend', 'inside_first_child', 'before_element'], true)) {
                return $player_html . $content;
            }
            return $content . $player_html;
        }

        $target_info = $this->find_target_element($content, $target_selector);

        if (!$target_info) {
            if (!$allow_fallback) {
                return $content;
            }

            $mover = '';
            if (!empty($target_selector)) {
                if ($debug_mode) $this->log("Target selector '{$target_selector}' not found. Adding JS mover.");
                $mover = sprintf(
                    '<script data-cfasync="false" data-no-optimize="1">(function(){var t=document.querySelector("%s"),s=document.currentScript.previousElementSibling;if(t&&s){' .
                    'if("%s"==="before_element")t.parentNode.insertBefore(s,t);' .
                    'else if("%s"==="after_element")t.parentNode.insertBefore(s,t.nextSibling);' .
                    'else if("%s"==="prepend"||"%s"==="inside_first_child")t.insertBefore(s,t.firstChild);' .
                    'else t.appendChild(s);' .
                    '}})();</script>',
                    esc_js($target_selector),
                    esc_js($insert_position), esc_js($insert_position),
                    esc_js($insert_position), esc_js($insert_position)
                );
            }

            if (in_array($insert_position, ['inside_first_child', 'prepend', 'before_element'], true)) {
                return $player_html . $mover . $content;
            }
            return $content . $player_html . $mover;
        }

        if (!isset($target_info['open_pos']) || $target_info['open_pos'] < 0 || $target_info['open_pos'] >= strlen($content)) {
            return $content . $player_html;
        }

        try {
            return $this->inject_at_position($content, $player_html, $target_info, $insert_position);
        } catch (\Exception $e) {
            if ($debug_mode) $this->log('Error during injection: ' . $e->getMessage());
            return $content . $player_html;
        }
    }

    private function find_target_element($content, $selector) {
        if (!is_string($content) || !is_string($selector) || trim($content) === '' || trim($selector) === '') {
            return false;
        }

        $selector = trim($selector);

        // Descendant combinator: #parent .descendant
        if (preg_match('/^([^\s>]+)\s+(.+)$/', $selector, $desc_matches)
            && !preg_match('/^[^\s>]+\s*>\s*[^\s>]+$/', $selector)) {
            $parent_selector = trim($desc_matches[1]);
            $rest_selector   = trim($desc_matches[2]);

            $parent_info = $this->find_target_element($content, $parent_selector);
            if (!$parent_info) return false;

            $parent_after_open = $parent_info['after_open'];
            $parent_close_pos  = $parent_info['close_pos'] ?? strlen($content);
            $parent_content    = substr($content, $parent_after_open, $parent_close_pos - $parent_after_open);

            $descendant_info = $this->find_target_element($parent_content, $rest_selector);
            if (!$descendant_info) return false;

            return [
                'tag_name'     => $descendant_info['tag_name'],
                'open_pos'     => $parent_after_open + $descendant_info['open_pos'],
                'open_tag'     => $descendant_info['open_tag'],
                'open_tag_len' => $descendant_info['open_tag_len'],
                'close_pos'    => $descendant_info['close_pos'] !== null
                    ? $parent_after_open + $descendant_info['close_pos']
                    : null,
                'after_open'   => $parent_after_open + $descendant_info['after_open'],
            ];
        }

        // Child combinator: .parent > childTag
        if (preg_match('/^([^\s>]+)\s*>\s*([a-zA-Z0-9_-]+)(:.*)?$/', $selector, $child_matches)) {
            $parent_selector = trim($child_matches[1]);
            $child_tag       = $child_matches[2];

            $parent_info = $this->find_target_element($content, $parent_selector);
            if (!$parent_info) return false;

            $parent_after_open = $parent_info['after_open'];
            $parent_close_pos  = $parent_info['close_pos'] ?? strlen($content);
            $parent_content    = substr($content, $parent_after_open, $parent_close_pos - $parent_after_open);

            $child_tag_name   = strtolower($child_tag);
            $excluded_classes = ['wp-caption-text'];
            $search_offset    = 0;
            $child_match      = null;

            while (preg_match('/<' . preg_quote($child_tag, '/') . '\b[^>]*>/i', $parent_content, $match, PREG_OFFSET_CAPTURE, $search_offset)) {
                $tag_html = $match[0][0];
                $tag_pos  = $match[0][1];

                $before_tag      = substr($parent_content, 0, $tag_pos);
                $open_count      = preg_match_all('/<[^\/!][^>]*>/i', $before_tag);
                $close_count     = preg_match_all('/<\/[^>]+>/i', $before_tag);
                $is_direct_child = ($open_count === $close_count);

                if ($is_direct_child) {
                    $should_skip = false;
                    foreach ($excluded_classes as $excluded_class) {
                        if (preg_match('/\bclass\s*=\s*["\']([^"\']*\b' . preg_quote($excluded_class, '/') . '\b[^"\']*)["\']/i', $tag_html)) {
                            $should_skip = true;
                            break;
                        }
                    }
                    if (!$should_skip) {
                        $lookback         = substr($parent_content, max(0, $tag_pos - 500), $tag_pos);
                        $wp_caption_open  = preg_match_all('/<div[^>]*\bclass\s*=\s*["\'][^"\']*\bwp-caption\b[^"\']*["\'][^>]*>/i', $lookback);
                        $wp_caption_close = preg_match_all('/<\/div>/i', $lookback);
                        if ($wp_caption_open > $wp_caption_close) {
                            $should_skip = true;
                        }
                    }
                    if (!$should_skip) {
                        $child_match = $match;
                        break;
                    }
                }

                $search_offset = $tag_pos + strlen($tag_html);
            }

            if (!$child_match) {
                $search_offset    = 0;
                $wp_caption_depth = 0;
                while (preg_match('/<(' . preg_quote($child_tag, '/') . '\b[^>]*>|div[^>]*\bclass\s*=\s*["\'][^"\']*\bwp-caption\b[^"\']*["\'][^>]*>|<\/div>)/i', $parent_content, $match, PREG_OFFSET_CAPTURE, $search_offset)) {
                    $tag_html = $match[0][0];
                    $tag_pos  = $match[0][1];

                    if (preg_match('/<div[^>]*\bclass\s*=\s*["\'][^"\']*\bwp-caption\b[^"\']*["\'][^>]*>/i', $tag_html)) {
                        $wp_caption_depth++;
                    } elseif (preg_match('/<\/div>/i', $tag_html) && $wp_caption_depth > 0) {
                        $wp_caption_depth--;
                    } elseif (preg_match('/<' . preg_quote($child_tag, '/') . '\b[^>]*>/i', $tag_html) && $wp_caption_depth === 0) {
                        $should_skip = false;
                        foreach ($excluded_classes as $excluded_class) {
                            if (preg_match('/\bclass\s*=\s*["\']([^"\']*\b' . preg_quote($excluded_class, '/') . '\b[^"\']*)["\']/i', $tag_html)) {
                                $should_skip = true;
                                break;
                            }
                        }
                        if (!$should_skip) {
                            $child_match = $match;
                            break;
                        }
                    }

                    $search_offset = $tag_pos + strlen($tag_html);
                }
            }

            if ($child_match) {
                $child_open_pos   = $parent_after_open + $child_match[0][1];
                $child_open_tag   = $child_match[0][0];
                $child_after_open = $child_open_pos + strlen($child_open_tag);

                $remaining       = substr($content, $child_after_open);
                $depth           = 1;
                $search_pos      = 0;
                $child_close_pos = false;

                while ($depth > 0 && $search_pos < strlen($remaining)) {
                    if (!preg_match('/<\/?' . preg_quote($child_tag_name, '/') . '(\s[^>]*)?>/i', $remaining, $tag_match, PREG_OFFSET_CAPTURE, $search_pos)) break;
                    $match_str = $tag_match[0][0];
                    $match_pos = $tag_match[0][1];

                    if (preg_match('/\/\s*>$/', $match_str)) {
                        $search_pos = $match_pos + strlen($match_str);
                        continue;
                    }
                    if (strpos($match_str, '</') === 0) {
                        $depth--;
                    } else {
                        $depth++;
                    }
                    if ($depth === 0) {
                        $child_close_pos = $child_after_open + $match_pos + strlen($match_str);
                        break;
                    }
                    $search_pos = $match_pos + strlen($match_str);
                }

                return [
                    'tag_name'     => $child_tag_name,
                    'open_pos'     => $child_open_pos,
                    'open_tag'     => $child_open_tag,
                    'open_tag_len' => strlen($child_open_tag),
                    'close_pos'    => $child_close_pos ?: null,
                    'after_open'   => $child_after_open,
                ];
            }
            return false;
        }

        // Simple selectors (.class, #id, tag, tag.class)
        $is_class     = preg_match('/^\.([a-zA-Z0-9_-]+)/', $selector, $class_matches);
        $is_id        = preg_match('/^#([a-zA-Z0-9_-]+)/', $selector, $id_matches);
        $is_tag_class = preg_match('/^([a-zA-Z0-9_-]+)\.([a-zA-Z0-9_-]+)$/', $selector, $tag_class_matches);
        $is_tag       = preg_match('/^([a-zA-Z0-9_-]+)$/', $selector, $tag_matches);

        $pattern  = null;
        $tag_name = null;

        if ($is_class) {
            $class_name = $class_matches[1];
            $pattern    = '/<([a-zA-Z0-9]+)[^>]*\s+class\s*=\s*["\']([^"\']*\b' . preg_quote($class_name, '/') . '\b[^"\']*)["\'][^>]*>/i';
        } elseif ($is_id) {
            $id_name = $id_matches[1];
            $pattern = '/<([a-zA-Z0-9]+)[^>]*\s+id\s*=\s*["\']' . preg_quote($id_name, '/') . '["\'][^>]*>/i';
        } elseif ($is_tag_class) {
            $tag_name   = strtolower($tag_class_matches[1]);
            $class_name = $tag_class_matches[2];
            $pattern    = '/<' . preg_quote($tag_name, '/') . '\b[^>]*\s+class\s*=\s*["\']([^"\']*\b' . preg_quote($class_name, '/') . '\b[^"\']*)["\'][^>]*>/i';
        } elseif ($is_tag) {
            $tag_name = $tag_matches[1];
            $pattern  = '/<' . preg_quote($tag_name, '/') . '\b[^>]*>/i';
        }

        if (!$pattern || !preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $open_pos   = $matches[0][1];
        $open_tag   = $matches[0][0];
        $tag_name   = $tag_name ?: strtolower($matches[1][0]);
        $after_open = $open_pos + strlen($open_tag);

        $remaining  = substr($content, $after_open);
        $depth      = 1;
        $search_pos = 0;
        $close_pos  = false;

        while ($depth > 0 && $search_pos < strlen($remaining)) {
            if (!preg_match('/<\/?' . preg_quote($tag_name, '/') . '(\s[^>]*)?>/i', $remaining, $tag_match, PREG_OFFSET_CAPTURE, $search_pos)) break;
            $match_str = $tag_match[0][0];
            $match_pos = $tag_match[0][1];
            if (preg_match('/\/\s*>$/', $match_str)) {
                $search_pos = $match_pos + strlen($match_str);
                continue;
            }
            if (strpos($match_str, '</') === 0) {
                $depth--;
            } else {
                $depth++;
            }
            if ($depth === 0) {
                $close_pos = $after_open + $match_pos + strlen($match_str);
                break;
            }
            $search_pos = $match_pos + strlen($match_str);
        }

        return [
            'tag_name'     => $tag_name,
            'open_pos'     => $open_pos,
            'open_tag'     => $open_tag,
            'open_tag_len' => strlen($open_tag),
            'close_pos'    => $close_pos ?: null,
            'after_open'   => $after_open,
        ];
    }

    private function inject_at_position($content, $player_html, $target_info, $insert_position) {
        $open_pos    = (int) $target_info['open_pos'];
        $close_pos   = isset($target_info['close_pos']) ? (int) $target_info['close_pos'] : null;
        $after_open  = (int) $target_info['after_open'];
        $content_len = strlen($content);

        switch ($insert_position) {
            case 'before_element':
                $this->log("Injecting before element.");
                return substr_replace($content, $player_html, $open_pos, 0);

            case 'after_element':
                if ($close_pos !== null && $close_pos <= $content_len) {
                    $this->log("Injecting after element closing tag.");
                    return substr_replace($content, $player_html, $close_pos, 0);
                }
                return substr_replace($content, $player_html, $after_open, 0);

            case 'inside_first_child':
            case 'prepend':
                $this->log("Injecting as first child (inside).");
                return substr_replace($content, $player_html, $after_open, 0);

            case 'inside_last_child':
            case 'inside_element':
            case 'append':
            default:
                if ($close_pos !== null && $close_pos <= $content_len) {
                    $end_chunk    = substr($content, 0, $close_pos);
                    $last_tag_pos = strrpos($end_chunk, '<');
                    $this->log("Injecting as last child (inside).");
                    return substr_replace($content, $player_html, $last_tag_pos, 0);
                }
                return substr_replace($content, $player_html, $after_open, 0);
        }
    }

    private function render_single($publication, $type, $color, $slot_css) {
        // data-instaread-version: lets us verify the deployed plugin version on a partner
        // site by curling any article and grepping the slot tag — no telemetry dependency.
        $slot = sprintf(
            '<div class="instaread-player-slot" data-instaread-version="%s" style="%s">
                <instaread-player publication="%s" playertype="%s" color="%s"></instaread-player>
            </div>',
            esc_attr($this->plugin_version),
            esc_attr($slot_css),
            esc_html($publication),
            esc_html($type),
            esc_html($color)
        );

        // Site-wide enqueue: one copy via wp_enqueue_script when opt-in is on (see maybe_enqueue_remote_instaread_player_script).
        if (
            $this->should_enqueue_remote_player_script_sitewide()
            && apply_filters('instaread_enqueue_remote_player_script', true, $this->settings, $this->partner_config)
        ) {
            return $slot;
        }

        // Config guard: use playerv3.js as a stable loader when use_player_loader is set (config.json
        // or WP instaread_settings) or the instaread_use_player_loader filter returns true.
        // playerv3.js dynamically loads instaread.{publication}.js at runtime (no integrity attr on that
        // dynamic request), so partners can safely pin integrity to playerv3.js without breaking on
        // future publication bundle updates.
        if ($this->should_use_player_loader()) {
            return $slot . $this->get_inline_playerv3_script_tag();
        }

        // Legacy: inline publication script next to the slot.
        return $slot . $this->get_inline_instaread_player_script_tag($publication);
    }

    private function render_playlist($publication, $height) {
        return sprintf(
            '<div class="instaread-player-slot" data-instaread-version="%s" style="height:%s;min-height:%s;">
                <instaread-player publication="%s" p_type="playlist" height="%s"></instaread-player>
                <script type="module"
                    data-cfasync="false"
                    data-no-optimize="1"
                    data-no-minify="1"
                    src="https://instaread.co/js/v2/instaread.playlist.js"
                    crossorigin="true">
                </script>
            </div>',
            esc_attr($this->plugin_version),
            esc_attr($height),
            esc_attr($height),
            esc_html($publication),
            esc_attr($height)
        );
    }

    public function sanitize_settings($in) {
        return [
            'publication'   => sanitize_text_field($in['publication'] ?? 'default'),
            'suppress_urls' => sanitize_textarea_field($in['suppress_urls'] ?? ''),
        ];
    }

    private function maybe_migrate_old_settings() {
        $old = get_option('instaread_legacy_settings');
        if ($old) {
            update_option('instaread_settings', ['publication' => $old['publication'] ?? '']);
            delete_option('instaread_legacy_settings');
        }
    }

    // =========================================================================
    // SRI INTEGRITY STRIP — OUTPUT BUFFER
    //
    // Catches integrity="sha384-..." and crossorigin="anonymous" attributes
    // injected onto Instaread script tags by CDNs (Cloudflare SRI) or security/
    // caching plugins AFTER our content is assembled. Only active on front-end
    // non-admin requests. Buffer is only started when needed (front-end pages).
    // =========================================================================

    public function start_sri_strip_buffer() {
        if (is_admin()) {
            return;
        }
        ob_start([$this, 'strip_instaread_sri_attributes']);
    }

    public function strip_instaread_sri_attributes($html) {
        // Only process pages that contain an Instaread script — fast bail otherwise.
        if (strpos($html, 'instaread.co') === false) {
            return $html;
        }
        // Match any <script ...> tag referencing instaread.co and strip integrity/crossorigin.
        return preg_replace_callback(
            '/<script\b[^>]*\bsrc=["\'][^"\']*instaread\.co[^"\']*["\'][^>]*>/i',
            function ($matches) {
                $tag = $matches[0];
                $tag = preg_replace('/\s+integrity="[^"]*"/i', '', $tag);
                $tag = preg_replace('/\s+crossorigin="anonymous"/i', '', $tag);
                return $tag;
            },
            $html
        );
    }
}

register_activation_hook(__FILE__, function() {
    $player = InstareadPlayer::init();
    if (method_exists($player, 'on_plugin_activated')) {
        $player->on_plugin_activated();
    }
});

InstareadPlayer::init();

