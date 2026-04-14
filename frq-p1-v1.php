<?php
/**
 * Plugin Name: FRQ-p1-v1
 * Description: Phase 1 Free Route Quiz family tree capture with shortcode support and guest submissions.
 * Version: 0.4.0
 * Author: MoveUp
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: frq-p1-v1
 */

if (! defined('ABSPATH')) {
    exit;
}

define('FRQ_P1_V1_VERSION', '0.4.0');
define('FRQ_P1_V1_FILE', __FILE__);
define('FRQ_P1_V1_PATH', plugin_dir_path(__FILE__));
define('FRQ_P1_V1_URL', plugin_dir_url(__FILE__));

require_once FRQ_P1_V1_PATH . 'includes/class-frq-p1-v1-db.php';
require_once FRQ_P1_V1_PATH . 'includes/class-frq-p1-v1-rest.php';
require_once FRQ_P1_V1_PATH . 'includes/class-frq-p1-v1-admin.php';

register_activation_hook(FRQ_P1_V1_FILE, ['FRQ_P1_V1_DB', 'activate']);

add_action('init', function () {
    add_shortcode('frq_p1_v1', 'frq_p1_v1_render_shortcode');
    add_shortcode('frq_p1_v1_share_view', 'frq_p1_v1_render_share_view_shortcode');
});

add_action('rest_api_init', function () {
    FRQ_P1_V1_REST::register_routes();
});

add_action('plugins_loaded', function () {
    FRQ_P1_V1_DB::maybe_migrate();
});

add_action('admin_menu', function () {
    FRQ_P1_V1_Admin::register_menu();
});

add_action('admin_init', function () {
    FRQ_P1_V1_Admin::register_settings();
});

add_action('wp_enqueue_scripts', function () {
    wp_register_style(
        'frq-p1-v1-style',
        FRQ_P1_V1_URL . 'assets/css/frq-p1-v1.css',
        [],
        FRQ_P1_V1_VERSION
    );

    wp_register_script(
        'frq-p1-v1-script',
        FRQ_P1_V1_URL . 'assets/js/frq-p1-v1.js',
        [],
        FRQ_P1_V1_VERSION,
        true
    );

    wp_localize_script('frq-p1-v1-script', 'frqP1V1Config', [
        'restUrl'       => esc_url_raw(rest_url('frq-p1-v1/v1')),
        'submitNonce'   => wp_create_nonce('frq_p1_v1_submit'),
        'privacyPolicy' => esc_url_raw(get_privacy_policy_url()),
        'shareViewPath' => esc_url_raw(home_url('/frq-share/')),
        'startPath' => esc_url_raw(home_url('/frq-start/')),

    ]);
});

function frq_p1_v1_render_shortcode($atts = []): string
{
    wp_enqueue_style('frq-p1-v1-style');
    wp_enqueue_script('frq-p1-v1-script');

    ob_start();
    ?>
    <div id="frq-p1-v1-app" class="frq-p1-v1">
        <h2>FRQ Phase 1: Family Knowledge Tree</h2>
        <p>Start with three generations. Then open the tree up by adding generations above or below (including minors).</p>

        <div class="frq-p1-v1__quest" aria-live="polite">
            <div class="frq-p1-v1__quest-top">
                <strong id="frq-quest-title">Family Quest Progress</strong>
                <span id="frq-quest-percent" class="frq-p1-v1__quest-percent">0%</span>
            </div>
            <div class="frq-p1-v1__quest-bar" role="progressbar" aria-labelledby="frq-quest-title" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span id="frq-quest-fill" class="frq-p1-v1__quest-fill"></span>
            </div>
            <p id="frq-quest-copy" class="frq-help">Fill in names, dates, and places to unlock your family tree.</p>
            <div id="frq-milestones" class="frq-p1-v1__milestones"></div>
            <button type="button" id="frq-score-card-btn" class="frq-score-card-btn">Score Card: <span id="frq-score-value">0</span> pts</button>
            <p id="frq-score-tip" class="frq-help">Earn more points by adding details and sharing your tree with family/friends.</p>
        </div>

        <div id="frq-generation-notice" class="frq-p1-v1__notice" aria-live="polite"></div>

        <div class="frq-p1-v1__layout">
            <div class="frq-p1-v1__tree-pane">
                <h3>Family Tree</h3>
                <div id="frq-tree-canvas" class="frq-tree-canvas"></div>
            </div>
            <div class="frq-p1-v1__editor-pane">
                <h3>Selected Family Member</h3>
                <div id="frq-editor-pane"></div>
            </div>
        </div>

        <div class="frq-p1-v1__section">
            <label for="frq-contact-name">Contact Name</label>
            <input id="frq-contact-name" type="text" placeholder="Your full name" />
        </div>

        <div class="frq-p1-v1__section">
            <label for="frq-guest-email">Your Email</label>
            <input id="frq-guest-email" type="email" placeholder="you@example.com" />
            <p class="frq-help">Name and email are required to save progress. A copy is emailed to you and the FRQ team.</p>
        </div>

        <div class="frq-p1-v1__section frq-p1-v1__consent-wrap">
            <label class="frq-checkbox" for="frq-consent">
                <input id="frq-consent" type="checkbox" />
                I consent to submitting this family information for FRQ Phase 1 processing.
            </label>
            <?php if (get_privacy_policy_url()) : ?>
                <p class="frq-help">Privacy Policy: <a href="<?php echo esc_url(get_privacy_policy_url()); ?>" target="_blank" rel="noopener">View policy</a></p>
            <?php endif; ?>
        </div>

        <input id="frq-company" type="text" autocomplete="off" tabindex="-1" class="frq-honeypot" aria-hidden="true" />

        <div class="frq-p1-v1__actions">
            <button type="button" id="frq-save-draft">Save My Progress</button>
            <button type="button" id="frq-submit">Submit Phase 1</button>
        </div>

        <p id="frq-status" class="frq-p1-v1__status" aria-live="polite"></p>

        <div class="frq-p1-v1__section">
            <label for="frq-share-emails">Share with family/friends (comma-separated emails)</label>
            <input id="frq-share-emails" type="text" placeholder="aunt@example.com, cousin@example.com" />
            <button type="button" id="frq-share">Share Your Tree</button>
            <p class="frq-help">Share links are read-only and expire after 30 days by default.</p>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}
