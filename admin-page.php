<?php
/**
 * Admin screen for Nyuchi WordPress Optimization.
 *
 * Included from AutoSEOManager::admin_page(), so $this is the plugin instance.
 * Styling follows the Mzizi/Bundu brand system: tanzanite primary, cobalt for
 * links and focus, warm-stone borders, pill buttons, 14px cards.
 *
 * @package AutoSEOManager
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
$valid_tabs  = array('settings', 'integrations', 'logs', 'tools');
if (!in_array($current_tab, $valid_tabs, true)) {
    $current_tab = 'settings';
}

$post_type_objects  = get_post_types(array('public' => true), 'objects');
$current_post_types = (array) get_option('auto_seo_post_types', array('post', 'page'));
$title_templates    = (array) get_option('auto_seo_title_templates', array());
$log_stats          = $this->get_log_stats();
$recent_logs        = $this->get_log_entries(25);
$integrations       = $this->get_available_integrations();

$settings_updated = false;
$db_notice = get_transient('auto_seo_db_notice');

if ($db_notice) {
    delete_transient('auto_seo_db_notice');
}

if (get_transient('auto_seo_settings_saved')) {
    $settings_updated = true;
    delete_transient('auto_seo_settings_saved');
}

/** Render a pill switch bound to a checkbox. */
function auto_seo_switch($name, $checked, $label, $description = '') {
    $id = 'sw-' . $name;
    ?>
    <div class="nyx-switch-row">
        <label class="nyx-switch" for="<?php echo esc_attr($id); ?>">
            <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"
                   value="1" <?php checked($checked); ?>>
            <span class="nyx-track" aria-hidden="true"><span class="nyx-thumb"></span></span>
            <span class="nyx-switch-text">
                <span class="nyx-switch-label"><?php echo esc_html($label); ?></span>
                <?php if ($description) : ?>
                    <span class="nyx-switch-desc"><?php echo esc_html($description); ?></span>
                <?php endif; ?>
            </span>
        </label>
    </div>
    <?php
}

$tabs = array(
    'settings'     => array('Settings', 'admin-generic'),
    'integrations' => array('Integrations', 'admin-plugins'),
    'logs'         => array('Activity Log', 'list-view'),
    'tools'        => array('Tools', 'admin-tools'),
    'database'     => array('Database', 'database'),
);

// The database module is optional; without it the tab has nothing to show, so
// it is not offered rather than opening onto an empty panel.
if (!class_exists('AutoSEODatabase')) {
    unset($tabs['database']);
}
?>

<div class="wrap nyx">

    <header class="nyx-head">
        <div class="nyx-head-id">
            <span class="nyx-wordmark">nyuchi</span>
            <h1 class="nyx-title">WordPress Optimization</h1>
            <span class="nyx-version">v<?php echo esc_html(AUTO_SEO_VERSION); ?></span>
        </div>
        <div class="nyx-chips">
            <?php
            $master = (bool) get_option('auto_seo_enabled', 1);
            $yoast  = $this->is_yoast_active();
            ?>
            <span class="nyx-chip <?php echo $master ? 'is-on' : 'is-off'; ?>">
                <?php echo $master ? 'Automation on' : 'Automation off'; ?>
            </span>
            <span class="nyx-chip <?php echo $yoast ? 'is-on' : 'is-bad'; ?>">
                <?php echo $yoast ? 'Yoast connected' : 'Yoast missing'; ?>
            </span>
            <span class="nyx-chip is-neutral">
                Log: <?php echo esc_html($log_stats['level']); ?>
                &middot; <?php echo esc_html(number_format_i18n($log_stats['rows'])); ?> rows
            </span>
        </div>
    </header>

    <?php if (!$this->is_yoast_active()) : ?>
        <div class="nyx-alert is-bad">
            <strong>Yoast SEO is not active.</strong>
            This plugin writes into Yoast's title, description and focus-keyword fields, so
            nothing will be generated until Yoast is enabled.
        </div>
    <?php endif; ?>

    <?php if ($settings_updated) : ?>
        <div class="nyx-alert is-good">Settings saved.</div>
    <?php endif; ?>

    <?php if ($db_notice) : ?>
        <div class="nyx-alert is-good"><?php echo esc_html($db_notice); ?></div>
    <?php endif; ?>

    <nav class="nyx-tabs" role="tablist" aria-label="Optimization sections">
        <?php foreach ($tabs as $slug => $meta) : ?>
            <?php $is_on = $current_tab === $slug; ?>
            <a class="nyx-tab <?php echo $is_on ? 'is-active' : ''; ?>"
               id="nyx-tab-<?php echo esc_attr($slug); ?>"
               data-nyx-tab="<?php echo esc_attr($slug); ?>"
               role="tab"
               aria-controls="nyx-panel-<?php echo esc_attr($slug); ?>"
               aria-selected="<?php echo $is_on ? 'true' : 'false'; ?>"
               href="<?php echo esc_url(AutoSEOManager::admin_url_for($slug)); ?>">
                <span class="dashicons dashicons-<?php echo esc_attr($meta[1]); ?>"></span>
                <?php echo esc_html($meta[0]); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php // ---------------------------------------------------------- SETTINGS ?>
    <div class="nyx-panel" id="nyx-panel-settings" role="tabpanel" aria-labelledby="nyx-tab-settings"
         <?php echo 'settings' === $current_tab ? '' : 'hidden'; ?>>
    <form method="post" action="<?php echo esc_url(AutoSEOManager::admin_url_for('settings')); ?>">
        <?php wp_nonce_field('save_auto_seo_settings', 'auto_seo_nonce'); ?>
        <input type="hidden" name="tab" value="settings">

        <div class="nyx-grid">

            <section class="nyx-card nyx-span-2">
                <h2>Automation</h2>
                <p class="nyx-card-sub">What the plugin generates when a post is missing SEO data.</p>
                <?php
                auto_seo_switch('auto_seo_enabled', get_option('auto_seo_enabled', 1),
                    'Enable automatic SEO generation',
                    'Master switch. Turning this off stops scheduled and manual generation.');
                auto_seo_switch('auto_meta_description', get_option('auto_seo_auto_meta_description'),
                    'Generate meta descriptions',
                    'Built from the excerpt, or from extracted content when no excerpt exists.');
                auto_seo_switch('auto_focus_keywords', get_option('auto_seo_auto_focus_keywords'),
                    'Generate focus keywords');
                auto_seo_switch('auto_generate_keywords', get_option('auto_seo_auto_generate_keywords'),
                    'Extract keywords from content');
                ?>
                <div class="nyx-field nyx-field-narrow">
                    <label for="max_description_length">Maximum description length</label>
                    <input type="number" id="max_description_length" name="max_description_length"
                           min="80" max="320"
                           value="<?php echo esc_attr(get_option('auto_seo_max_description_length', 155)); ?>">
                    <p class="nyx-help">Characters. Google truncates around 155–160.</p>
                </div>
            </section>

            <section class="nyx-card">
                <h2>Post types</h2>
                <p class="nyx-card-sub">Which content types are processed.</p>
                <div class="nyx-checks">
                    <?php foreach ($post_type_objects as $pt) : ?>
                        <label class="nyx-check">
                            <input type="checkbox" name="post_types[]"
                                   value="<?php echo esc_attr($pt->name); ?>"
                                   <?php checked(in_array($pt->name, $current_post_types, true)); ?>>
                            <span><?php echo esc_html($pt->labels->name); ?>
                                <code><?php echo esc_html($pt->name); ?></code></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="nyx-card">
                <h2>Scheduling</h2>
                <p class="nyx-card-sub">Background runs. Each toggle adds or removes its cron event.</p>
                <?php
                auto_seo_switch('daily_updates_enabled', get_option('auto_seo_daily_updates_enabled', 1),
                    'Daily SEO update');
                auto_seo_switch('weekly_audit_enabled', get_option('auto_seo_weekly_audit_enabled', 1),
                    'Weekly SEO audit');
                auto_seo_switch('audit_email_enabled', get_option('auto_seo_audit_email_enabled', 1),
                    'Email the audit report');
                ?>
                <div class="nyx-field">
                    <label for="audit_email">Audit report recipient</label>
                    <input type="email" id="audit_email" name="audit_email"
                           value="<?php echo esc_attr(get_option('auto_seo_audit_email')); ?>">
                </div>
                <?php
                $next = array(
                    'Daily update' => wp_next_scheduled('daily_seo_update'),
                    'Weekly audit' => wp_next_scheduled('weekly_seo_audit'),
                    'Log prune'    => wp_next_scheduled('auto_seo_prune_log'),
                );
                ?>
                <ul class="nyx-runlist">
                    <?php foreach ($next as $label => $ts) : ?>
                        <li>
                            <span><?php echo esc_html($label); ?></span>
                            <span class="nyx-mono"><?php
                                echo $ts
                                    ? esc_html(wp_date('j M Y, H:i', $ts))
                                    : '<span class="nyx-dim">not scheduled</span>';
                            ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="nyx-card nyx-span-2">
                <h2>Title templates</h2>
                <p class="nyx-card-sub">
                    Placeholders: <code>%%title%%</code> <code>%%sitename%%</code> <code>%%currentdate%%</code>.
                    With WP Travel active, trip templates also accept
                    <code>%%trip_duration%%</code> <code>%%trip_price%%</code>
                    <code>%%trip_destination%%</code> <code>%%trip_activity%%</code>.
                </p>
                <div class="nyx-templates">
                    <?php foreach ($post_type_objects as $pt) :
                        if (!in_array($pt->name, $current_post_types, true)) {
                            continue;
                        }
                        $tpl = isset($title_templates[$pt->name]) ? $title_templates[$pt->name] : '%%title%% | %%sitename%%';
                        ?>
                        <div class="nyx-field">
                            <label for="tpl-<?php echo esc_attr($pt->name); ?>">
                                <?php echo esc_html($pt->labels->name); ?>
                            </label>
                            <input type="text" class="nyx-mono"
                                   id="tpl-<?php echo esc_attr($pt->name); ?>"
                                   name="title_templates[<?php echo esc_attr($pt->name); ?>]"
                                   value="<?php echo esc_attr($tpl); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="nyx-card nyx-span-2">
                <h2>Meta output</h2>
                <p class="nyx-card-sub">Tags written into <code>wp_head</code> on single posts.</p>
                <?php
                auto_seo_switch('auto_additional_meta', get_option('auto_seo_auto_additional_meta'),
                    'Additional meta tags', 'Author, robots and generic tags.');
                auto_seo_switch('auto_og_tags', get_option('auto_seo_auto_og_tags'),
                    'Open Graph tags');
                auto_seo_switch('auto_twitter_cards', get_option('auto_seo_auto_twitter_cards'),
                    'Twitter Card tags');
                ?>
                <div class="nyx-two">
                    <div class="nyx-field">
                        <label for="site_author">Site author</label>
                        <input type="text" id="site_author" name="site_author"
                               value="<?php echo esc_attr(get_option('auto_seo_site_author')); ?>">
                    </div>
                    <div class="nyx-field">
                        <label for="robots_default">Default robots directive</label>
                        <input type="text" id="robots_default" name="robots_default" class="nyx-mono"
                               value="<?php echo esc_attr(get_option('auto_seo_robots_default', 'index, follow')); ?>">
                    </div>
                    <div class="nyx-field">
                        <label for="twitter_username">Twitter username</label>
                        <input type="text" id="twitter_username" name="twitter_username"
                               placeholder="iconic_exped"
                               value="<?php echo esc_attr(get_option('auto_seo_twitter_username')); ?>">
                    </div>
                    <div class="nyx-field">
                        <label for="google_site_verification">Google site verification</label>
                        <input type="text" id="google_site_verification" name="google_site_verification" class="nyx-mono"
                               value="<?php echo esc_attr(get_option('auto_seo_google_site_verification')); ?>">
                    </div>
                    <div class="nyx-field nyx-span-full">
                        <label for="default_og_image">Default Open Graph image</label>
                        <input type="url" id="default_og_image" name="default_og_image"
                               value="<?php echo esc_attr(get_option('auto_seo_default_og_image')); ?>">
                        <p class="nyx-help">Used when a post has no featured image.</p>
                    </div>
                </div>
            </section>

        </div>

        <div class="nyx-actions">
            <button type="submit" name="submit" value="1" class="nyx-btn is-primary">Save settings</button>
        </div>
    </form>
    </div><?php // /settings ?>

    <?php // ------------------------------------------------------ INTEGRATIONS ?>
    <div class="nyx-panel" id="nyx-panel-integrations" role="tabpanel" aria-labelledby="nyx-tab-integrations"
         <?php echo 'integrations' === $current_tab ? '' : 'hidden'; ?>>
    <form method="post" action="<?php echo esc_url(AutoSEOManager::admin_url_for('integrations')); ?>">
        <?php wp_nonce_field('save_auto_seo_settings', 'auto_seo_nonce'); ?>
        <input type="hidden" name="tab" value="integrations">

        <p class="nyx-lede">
            Integrations only run when the partner plugin is active. Turning one off leaves
            its filters unregistered, which is also the fastest way to rule one out when
            debugging generated output.
        </p>

        <div class="nyx-cards">
            <?php foreach ($integrations as $key => $integration) :
                $available = $this->is_integration_available($key);
                $enabled   = $this->is_integration_enabled($key);
                $required  = !empty($integration['required']);
                ?>
                <section class="nyx-int <?php echo $available ? 'is-available' : 'is-missing'; ?>">
                    <div class="nyx-int-head">
                        <div>
                            <h3><?php echo esc_html($integration['name']); ?></h3>
                            <span class="nyx-tag <?php echo $available ? 'is-on' : 'is-off'; ?>">
                                <?php echo $available ? 'Detected' : 'Not installed'; ?>
                            </span>
                            <?php if ($required) : ?>
                                <span class="nyx-tag is-req">Required</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($required) : ?>
                            <span class="dashicons dashicons-lock nyx-lock" title="Cannot be disabled"></span>
                        <?php else : ?>
                            <label class="nyx-switch is-bare">
                                <input type="checkbox" name="integration_<?php echo esc_attr($key); ?>"
                                       value="1" <?php checked($enabled); ?>
                                       <?php disabled(!$available); ?>>
                                <span class="nyx-track" aria-hidden="true"><span class="nyx-thumb"></span></span>
                                <span class="screen-reader-text">Enable <?php echo esc_html($integration['name']); ?></span>
                            </label>
                        <?php endif; ?>
                    </div>
                    <p class="nyx-int-desc"><?php echo esc_html($integration['description']); ?></p>
                    <?php if (!empty($integration['features'])) : ?>
                        <ul class="nyx-int-features">
                            <?php foreach ($integration['features'] as $feature) : ?>
                                <li><?php echo esc_html($feature); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="nyx-actions">
            <button type="submit" name="submit" value="1" class="nyx-btn is-primary">Save integrations</button>
        </div>
    </form>
    </div><?php // /integrations ?>

    <?php // -------------------------------------------------------------- LOGS ?>
    <div class="nyx-panel" id="nyx-panel-logs" role="tabpanel" aria-labelledby="nyx-tab-logs"
         <?php echo 'logs' === $current_tab ? '' : 'hidden'; ?>>

    <div class="nyx-stats">
        <div class="nyx-stat">
            <span class="nyx-stat-label">Entries</span>
            <span class="nyx-stat-value"><?php echo esc_html(number_format_i18n($log_stats['rows'])); ?></span>
        </div>
        <div class="nyx-stat">
            <span class="nyx-stat-label">Table size</span>
            <span class="nyx-stat-value"><?php echo esc_html($log_stats['size_mb']); ?> <small>MB</small></span>
        </div>
        <div class="nyx-stat">
            <span class="nyx-stat-label">Oldest entry</span>
            <span class="nyx-stat-value nyx-stat-sm">
                <?php echo $log_stats['oldest'] ? esc_html($log_stats['oldest']) : '—'; ?>
            </span>
        </div>
        <div class="nyx-stat">
            <span class="nyx-stat-label">Level</span>
            <span class="nyx-stat-value nyx-stat-sm"><?php echo esc_html($log_stats['level']); ?></span>
        </div>
    </div>

    <form method="post" action="<?php echo esc_url(AutoSEOManager::admin_url_for('logs')); ?>">
        <?php wp_nonce_field('save_auto_seo_settings', 'auto_seo_nonce'); ?>
        <input type="hidden" name="tab" value="logs">

        <section class="nyx-card">
            <h2>Logging</h2>
            <p class="nyx-card-sub">
                Verbosity controls how much is written. <strong>Verbose</strong> records
                integration start-up, which fires on every request — useful for a few minutes
                of debugging, expensive if left on.
            </p>

            <div class="nyx-levels">
                <?php
                $level  = get_option('auto_seo_log_level', 'actions');
                $levels = array(
                    'off'     => 'Nothing is written.',
                    'errors'  => 'Only failures.',
                    'actions' => 'Failures plus real SEO changes. Recommended.',
                    'verbose' => 'Everything, including per-request integration start-up.',
                );
                foreach ($levels as $value => $desc) : ?>
                    <label class="nyx-level <?php echo $level === $value ? 'is-active' : ''; ?>">
                        <input type="radio" name="log_level" value="<?php echo esc_attr($value); ?>"
                               <?php checked($level, $value); ?>>
                        <span class="nyx-level-name"><?php echo esc_html(ucfirst($value)); ?></span>
                        <span class="nyx-level-desc"><?php echo esc_html($desc); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="nyx-two">
                <div class="nyx-field">
                    <label for="log_retention_days">Keep entries for</label>
                    <input type="number" id="log_retention_days" name="log_retention_days" min="0" max="3650"
                           value="<?php echo esc_attr(get_option('auto_seo_log_retention_days', 30)); ?>">
                    <p class="nyx-help">Days. 0 keeps them indefinitely.</p>
                </div>
                <div class="nyx-field">
                    <label for="log_max_rows">Hard row cap</label>
                    <input type="number" id="log_max_rows" name="log_max_rows" min="0" step="1000"
                           value="<?php echo esc_attr(get_option('auto_seo_log_max_rows', 20000)); ?>">
                    <p class="nyx-help">Oldest rows are dropped beyond this. 0 disables the cap.</p>
                </div>
            </div>

            <?php auto_seo_switch('log_pruning_enabled', get_option('auto_seo_log_pruning_enabled', 1),
                'Prune automatically each day', 'Applies both limits above on a daily cron.'); ?>

            <div class="nyx-actions is-inline">
                <button type="submit" name="submit" value="1" class="nyx-btn is-primary">Save logging</button>
                <button type="submit" name="prune_log_now" value="1" class="nyx-btn">Prune now</button>
                <button type="submit" name="purge_log_now" value="1" class="nyx-btn is-danger"
                        onclick="return confirm('Delete every log entry? This cannot be undone.');">
                    Purge all entries
                </button>
            </div>
            <input type="hidden" name="submit" value="1">
        </section>
    </form>

    <section class="nyx-card">
        <h2>Recent activity</h2>
        <?php if (empty($recent_logs)) : ?>
            <p class="nyx-empty">
                Nothing logged yet. Run a manual update from Tools, or lower the log level
                if you expected entries here.
            </p>
        <?php else : ?>
            <div class="nyx-tablewrap">
                <table class="nyx-table">
                    <thead>
                        <tr>
                            <th>Post</th><th>Action</th><th>Status</th><th>Details</th><th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logs as $log) : ?>
                            <tr>
                                <td>
                                    <?php if ($log->post_id > 0) : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($log->post_id)); ?>">
                                            #<?php echo esc_html($log->post_id); ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="nyx-dim">system</span>
                                    <?php endif; ?>
                                </td>
                                <td class="nyx-mono"><?php echo esc_html($log->action); ?></td>
                                <td>
                                    <span class="nyx-status is-<?php echo esc_attr(sanitize_html_class($log->status)); ?>">
                                        <?php echo esc_html($log->status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->details); ?></td>
                                <td class="nyx-mono nyx-dim"><?php echo esc_html($log->timestamp); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    </div><?php // /logs ?>

    <?php // ------------------------------------------------------------- TOOLS ?>
    <div class="nyx-panel" id="nyx-panel-tools" role="tabpanel" aria-labelledby="nyx-tab-tools"
         <?php echo 'tools' === $current_tab ? '' : 'hidden'; ?>>
    <div class="nyx-grid">

        <section class="nyx-card">
            <h2>Run now</h2>
            <p class="nyx-card-sub">
                Processes every published post in the selected post types that is missing
                SEO data. Large sites may take a while.
            </p>
            <button type="button" id="nyx-run" class="nyx-btn is-primary">Run SEO update</button>
            <span id="nyx-run-status" class="nyx-run-status" role="status" aria-live="polite"></span>
        </section>

        <section class="nyx-card">
            <h2>External cron</h2>
            <p class="nyx-card-sub">
                Hit this URL from a real scheduler if you would rather not rely on WP-Cron.
            </p>
            <div class="nyx-copy">
                <code><?php
                    echo esc_html(home_url('/auto-seo-cron/' . get_option('auto_seo_cron_secret_key') . '/'));
                ?></code>
            </div>
            <p class="nyx-help">Treat this URL as a secret — it triggers a full run.</p>
        </section>

        <section class="nyx-card nyx-span-2">
            <h2>REST API</h2>
            <p class="nyx-card-sub">
                For MCP clients and other automation. All routes require an authenticated
                user with <code>manage_options</code>.
            </p>
            <div class="nyx-tablewrap">
                <table class="nyx-table nyx-table-api">
                    <thead><tr><th>Method</th><th>Route</th><th>Purpose</th></tr></thead>
                    <tbody>
                        <tr><td class="nyx-verb">GET</td><td class="nyx-mono">/wp-json/auto-seo/v1/status</td><td>Version, toggles, log stats, integration state, next cron runs</td></tr>
                        <tr><td class="nyx-verb">GET</td><td class="nyx-mono">/wp-json/auto-seo/v1/settings</td><td>Read every writable setting</td></tr>
                        <tr><td class="nyx-verb">POST</td><td class="nyx-mono">/wp-json/auto-seo/v1/settings</td><td>Update settings; unknown keys are reported as ignored</td></tr>
                        <tr><td class="nyx-verb">GET</td><td class="nyx-mono">/wp-json/auto-seo/v1/logs</td><td>Recent entries plus stats (<code>?limit=</code>, max 500)</td></tr>
                        <tr><td class="nyx-verb">DELETE</td><td class="nyx-mono">/wp-json/auto-seo/v1/logs</td><td>Purge the log</td></tr>
                        <tr><td class="nyx-verb">POST</td><td class="nyx-mono">/wp-json/auto-seo/v1/run</td><td>Run for the whole site, or one trip with <code>{"post_id":123}</code></td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="nyx-card nyx-span-2">
            <h2>System</h2>
            <div class="nyx-tablewrap">
                <table class="nyx-table">
                    <tbody>
                        <tr><th>Plugin version</th><td class="nyx-mono"><?php echo esc_html(AUTO_SEO_VERSION); ?></td></tr>
                        <tr><th>Schema version</th><td class="nyx-mono"><?php echo esc_html(get_option('auto_seo_db_version', 1)); ?></td></tr>
                        <tr><th>PHP</th><td class="nyx-mono"><?php echo esc_html(PHP_VERSION); ?></td></tr>
                        <tr><th>WordPress</th><td class="nyx-mono"><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
                        <tr><th>Yoast SEO</th><td class="nyx-mono"><?php echo $this->is_yoast_active() ? 'active' : 'not active'; ?></td></tr>
                        <tr><th>Log table</th><td class="nyx-mono"><?php echo esc_html(number_format_i18n($log_stats['rows'])); ?> rows, <?php echo esc_html($log_stats['size_mb']); ?> MB</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

    </div>

    <script>
    (function () {
        var btn = document.getElementById('nyx-run');
        if (!btn) { return; }
        var out = document.getElementById('nyx-run-status');
        btn.addEventListener('click', function () {
            btn.disabled = true;
            out.textContent = 'Running…';
            out.className = 'nyx-run-status is-busy';
            jQuery.post(ajaxurl, {
                action: 'manual_seo_update',
                nonce: '<?php echo esc_js(wp_create_nonce('auto_seo_nonce')); ?>'
            }).done(function (r) {
                out.textContent = (r && r.success) ? 'Finished.' : 'Failed: ' + (r && r.data ? r.data : 'unknown error');
                out.className = 'nyx-run-status ' + ((r && r.success) ? 'is-good' : 'is-bad');
            }).fail(function () {
                out.textContent = 'Request failed.';
                out.className = 'nyx-run-status is-bad';
            }).always(function () {
                btn.disabled = false;
            });
        });
    })();
    </script>
    </div><?php // /tools ?>

    <?php // ---------------------------------------------------------- DATABASE ?>
    <?php if (isset($tabs['database'])) :
        $db        = new AutoSEODatabase();
        $tables    = $db->table_report();
        $orphans   = $db->orphan_report();
        $autoload  = $db->autoload_report(12);
    ?>
    <div class="nyx-panel" id="nyx-panel-database" role="tabpanel" aria-labelledby="nyx-tab-database"
         <?php echo 'database' === $current_tab ? '' : 'hidden'; ?>>

        <div class="nyx-stats">
            <div class="nyx-stat">
                <span class="nyx-stat-n"><?php echo esc_html(number_format((float) ($tables['total_size_mb'] ?? 0), 1)); ?> MB</span>
                <span class="nyx-stat-l">Database size</span>
            </div>
            <div class="nyx-stat">
                <span class="nyx-stat-n"><?php echo esc_html(number_format((float) ($tables['overhead_mb'] ?? 0), 1)); ?> MB</span>
                <span class="nyx-stat-l">Reclaimable overhead</span>
            </div>
            <div class="nyx-stat">
                <span class="nyx-stat-n"><?php echo esc_html(number_format((float) ($autoload['autoloaded_kb'] ?? 0), 1)); ?> KB</span>
                <span class="nyx-stat-l">Autoloaded, every request</span>
            </div>
            <div class="nyx-stat">
                <span class="nyx-stat-n"><?php echo esc_html(number_format((int) ($orphans['total_rows'] ?? 0))); ?></span>
                <span class="nyx-stat-l">Rows nothing reads</span>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url(AutoSEOManager::admin_url_for('database')); ?>">
            <?php wp_nonce_field('save_auto_seo_settings', 'auto_seo_nonce'); ?>
            <input type="hidden" name="tab" value="database">

            <div class="nyx-card nyx-span-2">
                <h3 class="nyx-card-h">Rows nothing reads any more</h3>
                <p class="nyx-card-sub">Tick what to remove. Deletion is capped per run, so a large backlog needs the button pressing more than once - the count updates each time.</p>
                <div class="nyx-checks">
                    <?php foreach (($orphans['targets'] ?? array()) as $key => $t) :
                        $n = (int) $t['rows']; ?>
                        <label class="nyx-check">
                            <input type="checkbox" name="db_targets[]" value="<?php echo esc_attr($key); ?>"
                                   <?php disabled(0 === $n); ?>>
                            <span><?php echo esc_html($t['label']); ?>
                                <code><?php echo esc_html(number_format($n)); ?></code></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p style="margin-top:14px">
                    <button type="submit" name="submit" value="1" class="nyx-btn is-danger">Delete selected rows</button>
                    <button type="submit" name="db_optimize" value="1" class="nyx-btn">Reclaim table overhead</button>
                </p>
                <p class="nyx-card-sub">Reclaiming overhead rebuilds each table, which locks it while it runs. Do it when the site is quiet.</p>
            </div>

            <div class="nyx-grid" style="margin-top:18px">
                <div class="nyx-card">
                    <h3 class="nyx-card-h">Largest autoloaded options</h3>
                    <p class="nyx-card-sub">Read on every request, including REST and admin-ajax. Usually the most valuable thing on this screen.</p>
                    <div class="nyx-tablewrap">
                        <table class="nyx-table">
                            <thead><tr><th>Option</th><th>Size</th></tr></thead>
                            <tbody>
                            <?php foreach (($autoload['largest'] ?? array()) as $o) : ?>
                                <tr><td><code><?php echo esc_html($o['option']); ?></code></td>
                                    <td><?php echo esc_html($o['kb']); ?> KB</td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="nyx-card">
                    <h3 class="nyx-card-h">Largest tables</h3>
                    <p class="nyx-card-sub">Row counts are InnoDB estimates, not exact.</p>
                    <div class="nyx-tablewrap">
                        <table class="nyx-table">
                            <thead><tr><th>Table</th><th>Size</th><th>Overhead</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice(($tables['tables'] ?? array()), 0, 12) as $t) : ?>
                                <tr><td><code><?php echo esc_html($t['table']); ?></code></td>
                                    <td><?php echo esc_html($t['size_mb']); ?> MB</td>
                                    <td><?php echo esc_html($t['overhead_mb']); ?> MB</td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div><?php // /database ?>
    <?php endif; ?>

</div>

<style>
/* Nyuchi WordPress Optimization — Mzizi/Bundu brand system, scoped to .nyx */
.nyx {
    --nyx-primary:   #4B0082;  /* tanzanite  */
    --nyx-info:      #0047AB;  /* cobalt     */
    --nyx-success:   #004D40;  /* malachite  */
    --nyx-warning:   #7A5C00;
    --nyx-danger:    #B3261E;
    --nyx-gold:      #5D4037;  /* nyuchi mineral */
    --nyx-ink:       #1A1917;
    --nyx-muted:     #55514B;
    --nyx-faint:     #86817A;
    --nyx-border:    #E7E5E0;  /* warm stone, not cool grey */
    --nyx-surface:   #FFFFFF;
    --nyx-sunken:    #FAF9F5;
    --nyx-base:      #F3F3F1;
    --nyx-r-card:    14px;
    --nyx-r-sm:      7px;
    --nyx-r-tab:     999px;

    max-width: 1180px;
    color: var(--nyx-ink);
    font-size: 14px;
    line-height: 1.55;
}
.nyx *, .nyx *::before, .nyx *::after { box-sizing: border-box; }
.nyx h1, .nyx h2, .nyx h3 {
    font-family: "Noto Serif", Georgia, "Times New Roman", serif;
    letter-spacing: -0.01em;
    color: var(--nyx-ink);
}

/* Header */
.nyx-head {
    display: flex; flex-wrap: wrap; gap: 14px 20px;
    align-items: center; justify-content: space-between;
    padding: 20px 0 18px; margin-bottom: 4px;
    border-bottom: 1px solid var(--nyx-border);
}
.nyx-head-id { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.nyx-wordmark {
    font-weight: 700; font-size: 13px; letter-spacing: 0.14em;
    text-transform: lowercase; color: var(--nyx-gold);
    border: 1px solid var(--nyx-border); border-radius: 999px;
    padding: 3px 11px; background: var(--nyx-sunken);
}
.nyx-title { font-size: 25px; margin: 0; font-weight: 600; }
.nyx-version { font-size: 12px; color: var(--nyx-faint); font-variant-numeric: tabular-nums; }
.nyx-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.nyx-chip {
    font-size: 12px; padding: 4px 12px; border-radius: 999px;
    border: 1px solid var(--nyx-border); background: var(--nyx-sunken);
    color: var(--nyx-muted); white-space: nowrap;
}
.nyx-chip.is-on   { color: var(--nyx-success); border-color: #B7D9D1; background: #E0F2F1; }
.nyx-chip.is-off  { color: var(--nyx-warning); border-color: #E3D19A; background: #FFF8E1; }
.nyx-chip.is-bad  { color: var(--nyx-danger);  border-color: #E7BDBA; background: #FDEDED; }
.nyx-chip.is-neutral { font-variant-numeric: tabular-nums; }

/* Alerts */
.nyx-alert {
    border: 1px solid var(--nyx-border); border-left-width: 4px;
    border-radius: var(--nyx-r-sm); padding: 12px 16px; margin: 16px 0;
    background: var(--nyx-surface);
}
.nyx-alert.is-good { border-left-color: var(--nyx-success); background: #E0F2F1; }
.nyx-alert.is-bad  { border-left-color: var(--nyx-danger);  background: #FDEDED; }

/* Tabs */
.nyx-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 18px 0 22px; }
.nyx-tab {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 17px; border-radius: var(--nyx-r-tab);
    border: 1px solid var(--nyx-border); background: var(--nyx-surface);
    color: var(--nyx-muted); text-decoration: none; font-weight: 500;
}
.nyx-tab:hover { color: var(--nyx-primary); border-color: #CFC3DC; background: #F3E5F5; }
.nyx-tab.is-active {
    background: var(--nyx-primary); border-color: var(--nyx-primary);
    color: #fff; font-weight: 600;
    /* Stated again rather than inherited. An active tab is also the focused
       one, and anything that reaches it with equal specificity would otherwise
       win on source order and square off the only tab that is filled. */
    border-radius: var(--nyx-r-tab);
}
.nyx-tab .dashicons { font-size: 17px; width: 17px; height: 17px; }
/*
 * Focus ring drawn with box-shadow rather than outline.
 *
 * outline has only followed border-radius since Safari 16.4, and not at all in
 * some older engines - so on a pill it renders as a rectangle, which on the
 * active tab reads as though that one tab lost its rounding. box-shadow has
 * always followed the radius.
 *
 * The transparent outline is kept deliberately: in Windows High Contrast Mode
 * box-shadow is dropped entirely, and a transparent outline is forced to a
 * visible colour. Without it, focus would be invisible to the people who most
 * need to see it.
 */
.nyx-tab:focus-visible, .nyx a:focus-visible, .nyx button:focus-visible,
.nyx input:focus-visible, .nyx label:focus-within {
    outline: 2px solid transparent;
    outline-offset: 2px;
    box-shadow: 0 0 0 2px var(--nyx-base), 0 0 0 4px var(--nyx-info);
}

/* Layout */
.nyx-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
.nyx-span-2 { grid-column: 1 / -1; }
@media (max-width: 900px) { .nyx-grid { grid-template-columns: minmax(0, 1fr); } }

.nyx-card {
    background: var(--nyx-surface);
    border: 1px solid var(--nyx-border);
    border-radius: var(--nyx-r-card);
    padding: 20px;
    min-width: 0;
    margin-bottom: 18px;
}
.nyx-grid .nyx-card { margin-bottom: 0; }
.nyx-card h2 { font-size: 17px; margin: 0 0 4px; }
.nyx-card-sub { color: var(--nyx-muted); margin: 0 0 16px; max-width: 68ch; }
.nyx-lede { color: var(--nyx-muted); max-width: 72ch; margin: 0 0 18px; }

/* Switches */
.nyx-switch-row { padding: 9px 0; border-top: 1px solid var(--nyx-border); }
.nyx-switch-row:first-of-type { border-top: 0; padding-top: 0; }
.nyx-switch { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; }
.nyx-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
.nyx-track {
    flex: 0 0 auto; width: 42px; height: 24px; border-radius: 999px;
    background: #D6D5D1; border: 1px solid var(--nyx-border);
    position: relative; transition: background .15s ease; margin-top: 1px;
}
.nyx-thumb {
    position: absolute; top: 2px; left: 2px; width: 18px; height: 18px;
    border-radius: 50%; background: #fff; transition: transform .15s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,.25);
}
.nyx-switch input:checked + .nyx-track { background: var(--nyx-primary); border-color: var(--nyx-primary); }
.nyx-switch input:checked + .nyx-track .nyx-thumb { transform: translateX(18px); }
.nyx-switch input:disabled + .nyx-track { opacity: .45; }
.nyx-switch input:focus-visible + .nyx-track { outline: 2px solid var(--nyx-info); outline-offset: 2px; }
.nyx-switch-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.nyx-switch-label { font-weight: 500; }
.nyx-switch-desc { color: var(--nyx-faint); font-size: 13px; }
.nyx-switch.is-bare { gap: 0; }

/* Fields */
.nyx-field { margin: 14px 0 0; min-width: 0; }
.nyx-field label { display: block; font-weight: 500; margin-bottom: 5px; }
.nyx-field input {
    width: 100%; max-width: 100%;
    padding: 9px 14px; border-radius: 999px;
    border: 1px solid var(--nyx-border); background: var(--nyx-surface);
    color: var(--nyx-ink); font-size: 14px;
}
.nyx-field input:focus { border-color: var(--nyx-info); }
.nyx-field-narrow input { max-width: 190px; }
.nyx-help { color: var(--nyx-faint); font-size: 12.5px; margin: 5px 0 0; }
.nyx-two { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 18px; }
.nyx-two .nyx-span-full { grid-column: 1 / -1; }
@media (max-width: 782px) { .nyx-two { grid-template-columns: minmax(0, 1fr); } }
.nyx-templates { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 18px; }
@media (max-width: 782px) { .nyx-templates { grid-template-columns: minmax(0, 1fr); } }

/* Checkbox list */
.nyx-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px; }
.nyx-check { display: flex; align-items: center; gap: 9px; min-width: 0; }
.nyx-check input { width: 18px; height: 18px; border-radius: var(--nyx-r-sm); margin: 0; flex: 0 0 auto; }
.nyx-check span { min-width: 0; overflow-wrap: anywhere; }
.nyx-check code { font-size: 11px; color: var(--nyx-faint); background: none; padding: 0; }

/* Run list */
.nyx-runlist { list-style: none; margin: 16px 0 0; padding: 0; border-top: 1px solid var(--nyx-border); }
.nyx-runlist li {
    display: flex; justify-content: space-between; gap: 12px;
    padding: 7px 0; border-bottom: 1px solid var(--nyx-border);
    font-size: 13px; color: var(--nyx-muted);
}

/* Integration cards */
.nyx-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 16px; }
.nyx-int {
    background: var(--nyx-surface); border: 1px solid var(--nyx-border);
    border-left: 4px solid var(--nyx-border);
    border-radius: var(--nyx-r-card); padding: 17px; min-width: 0;
}
.nyx-int.is-available { border-left-color: var(--nyx-success); }
.nyx-int.is-missing { background: var(--nyx-sunken); }
.nyx-int-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.nyx-int-head h3 { font-size: 15.5px; margin: 0 0 6px; }
.nyx-int-desc { color: var(--nyx-muted); margin: 11px 0 0; }
.nyx-int-features { margin: 11px 0 0; padding-left: 17px; color: var(--nyx-faint); font-size: 13px; }
.nyx-int-features li { margin-bottom: 3px; overflow-wrap: anywhere; }
.nyx-tag {
    display: inline-block; font-size: 11px; letter-spacing: .03em;
    padding: 2px 9px; border-radius: 999px; margin-right: 5px;
    border: 1px solid var(--nyx-border); background: var(--nyx-sunken); color: var(--nyx-muted);
}
.nyx-tag.is-on { color: var(--nyx-success); border-color: #B7D9D1; background: #E0F2F1; }
.nyx-tag.is-off { color: var(--nyx-faint); }
.nyx-tag.is-req { color: var(--nyx-primary); border-color: #CFC3DC; background: #F3E5F5; }
.nyx-lock { color: var(--nyx-faint); }

/* Stats */
.nyx-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 18px; }
.nyx-stat {
    background: var(--nyx-surface); border: 1px solid var(--nyx-border);
    border-radius: var(--nyx-r-card); padding: 15px 17px;
    display: flex; flex-direction: column; gap: 3px; min-width: 0;
}
.nyx-stat-label { font-size: 11.5px; letter-spacing: .07em; text-transform: uppercase; color: var(--nyx-faint); }
.nyx-stat-value { font-size: 25px; font-weight: 600; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.nyx-stat-value small { font-size: 14px; color: var(--nyx-faint); font-weight: 400; }
.nyx-stat-sm { font-size: 14.5px; font-weight: 500; }

/* Log levels */
.nyx-levels { display: grid; grid-template-columns: repeat(auto-fit, minmax(215px, 1fr)); gap: 10px; margin-bottom: 6px; }
.nyx-level {
    border: 1px solid var(--nyx-border); border-radius: var(--nyx-r-card);
    padding: 13px 15px; cursor: pointer; display: flex; flex-direction: column; gap: 3px;
    background: var(--nyx-surface); min-width: 0;
}
.nyx-level:hover { border-color: #CFC3DC; }
.nyx-level.is-active { border-color: var(--nyx-primary); background: #F3E5F5; box-shadow: inset 0 0 0 1px var(--nyx-primary); }
.nyx-level input { position: absolute; opacity: 0; width: 0; height: 0; }
.nyx-level-name { font-weight: 600; }
.nyx-level-desc { color: var(--nyx-muted); font-size: 12.5px; }

/* Tables */
.nyx-tablewrap { overflow-x: auto; border: 1px solid var(--nyx-border); border-radius: var(--nyx-r-card); }
.nyx-table { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 620px; }
.nyx-table th, .nyx-table td { text-align: left; padding: 10px 14px; border-top: 1px solid var(--nyx-border); vertical-align: top; }
.nyx-table thead th {
    border-top: 0; background: var(--nyx-sunken); color: var(--nyx-muted);
    font-size: 11.5px; letter-spacing: .06em; text-transform: uppercase; font-weight: 600;
}
.nyx-table tbody th { background: var(--nyx-sunken); color: var(--nyx-muted); font-weight: 500; width: 190px; }
.nyx-table-api td:first-child { width: 76px; }
.nyx-verb { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11.5px; font-weight: 700; color: var(--nyx-info); }
.nyx-status {
    display: inline-block; font-size: 11.5px; padding: 2px 9px; border-radius: 999px;
    border: 1px solid var(--nyx-border); background: var(--nyx-sunken); color: var(--nyx-muted);
}
.nyx-status.is-success, .nyx-status.is-completed { color: var(--nyx-success); border-color: #B7D9D1; background: #E0F2F1; }
.nyx-status.is-error, .nyx-status.is-failed { color: var(--nyx-danger); border-color: #E7BDBA; background: #FDEDED; }

/* Buttons — always pill, per brand */
.nyx-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
.nyx-actions.is-inline { margin-top: 18px; }
.nyx-btn {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 10px 22px; border-radius: 999px; cursor: pointer;
    border: 1px solid var(--nyx-border); background: var(--nyx-surface);
    color: var(--nyx-ink); font-size: 14px; font-weight: 500; line-height: 1.2;
}
.nyx-btn:hover { border-color: #CFC3DC; background: #F3E5F5; color: var(--nyx-primary); }
.nyx-btn.is-primary { background: var(--nyx-primary); border-color: var(--nyx-primary); color: #fff; }
.nyx-btn.is-primary:hover { background: #3B0068; color: #fff; }
.nyx-btn.is-danger { color: var(--nyx-danger); border-color: #E7BDBA; }
.nyx-btn.is-danger:hover { background: #FDEDED; color: var(--nyx-danger); border-color: var(--nyx-danger); }
.nyx-btn:disabled { opacity: .55; cursor: default; }
.nyx-tab:active, .nyx-btn:not(:disabled):active { transform: translateY(1px); }
.nyx-tab, .nyx-btn { transition: background .12s ease, border-color .12s ease, color .12s ease, transform .06s ease; }
@media (prefers-reduced-motion: reduce) { .nyx-tab, .nyx-btn { transition: none; } .nyx-tab:active, .nyx-btn:active { transform: none; } }
.nyx-panel[hidden] { display: none; }

.nyx-run-status { margin-left: 12px; font-size: 13px; color: var(--nyx-muted); }
.nyx-run-status.is-good { color: var(--nyx-success); }
.nyx-run-status.is-bad { color: var(--nyx-danger); }

.nyx-copy { background: var(--nyx-sunken); border: 1px solid var(--nyx-border); border-radius: var(--nyx-r-sm); padding: 11px 14px; overflow-x: auto; }
.nyx-copy code { background: none; padding: 0; white-space: nowrap; font-size: 12.5px; }
.nyx-mono, .nyx .nyx-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
.nyx-dim { color: var(--nyx-faint); }
.nyx-empty { color: var(--nyx-faint); margin: 0; }
.nyx code { background: var(--nyx-sunken); border-radius: 4px; padding: 1px 5px; font-size: 12px; }
</style>

<script>
/**
 * Client-side tab switching.
 *
 * Every panel is rendered server-side, so switching is a class toggle rather
 * than a page load. The links keep their real href: without JavaScript they
 * still navigate, and the server still honours ?tab=, so the no-JS path and
 * the bookmarked-URL path both keep working.
 */
(function () {
    var nav = document.querySelector('.nyx-tabs');
    if (!nav) { return; }

    var tabs   = Array.prototype.slice.call(nav.querySelectorAll('[data-nyx-tab]'));
    var panels = {};
    tabs.forEach(function (t) {
        var slug = t.getAttribute('data-nyx-tab');
        panels[slug] = document.getElementById('nyx-panel-' + slug);
    });

    function show(slug, push) {
        if (!panels[slug]) { return false; }

        tabs.forEach(function (t) {
            var on = t.getAttribute('data-nyx-tab') === slug;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            // A tab is reachable with the arrow keys; only the selected one
            // stays in the Tab order, which is what a tablist should do.
            t.setAttribute('tabindex', on ? '0' : '-1');
        });

        Object.keys(panels).forEach(function (k) {
            if (panels[k]) { panels[k].hidden = (k !== slug); }
        });

        if (push) {
            var t = tabs.filter(function (x) { return x.getAttribute('data-nyx-tab') === slug; })[0];
            if (t && window.history && history.pushState) {
                history.pushState({ nyxTab: slug }, '', t.getAttribute('href'));
            }
        }
        return true;
    }

    nav.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('[data-nyx-tab]') : null;
        if (!link) { return; }
        // Let modified clicks open a new tab the way any link would.
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) { return; }
        if (show(link.getAttribute('data-nyx-tab'), true)) { e.preventDefault(); }
    });

    nav.addEventListener('keydown', function (e) {
        var i = tabs.indexOf(document.activeElement);
        if (i === -1) { return; }
        var next = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { next = tabs[(i + 1) % tabs.length]; }
        if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   { next = tabs[(i - 1 + tabs.length) % tabs.length]; }
        if (e.key === 'Home') { next = tabs[0]; }
        if (e.key === 'End')  { next = tabs[tabs.length - 1]; }
        if (!next) { return; }
        e.preventDefault();
        next.focus();
        show(next.getAttribute('data-nyx-tab'), true);
    });

    window.addEventListener('popstate', function (e) {
        var slug = (e.state && e.state.nyxTab) || null;
        if (!slug) {
            var m = window.location.search.match(/[?&]tab=([a-z_]+)/);
            slug = m ? m[1] : 'settings';
        }
        show(slug, false);
    });

    tabs.forEach(function (t) {
        t.setAttribute('tabindex', t.classList.contains('is-active') ? '0' : '-1');
    });
}());
</script>
