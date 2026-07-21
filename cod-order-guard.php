<?php
/**
 * Plugin Name: COD Order Guard
 * Plugin URI:  https://github.com/ferdousnahid95/cod-order-guard
 * Description: Incomplete order recovery, SteadFast fraud/return-ratio checking, and Meta Conversions API (Purchase) tracking for WooCommerce COD stores — all in one plugin.
 * Version:     3.0.0
 * Author:      Omninode
 * Author URI:  https://omninode.tech
 * Text Domain: cod-order-guard
 */

if (!defined('ABSPATH')) exit;

define('OFM_VERSION', '3.0.0');
define('OFM_PATH', plugin_dir_path(__FILE__));
define('OFM_URL', plugin_dir_url(__FILE__));

require_once OFM_PATH . 'modules/incomplete-orders.php';
require_once OFM_PATH . 'modules/fraud-checker.php';
require_once OFM_PATH . 'modules/meta-capi.php';

// ─── DB Setup ────────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, 'ofm_create_tables');
function ofm_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'ofm_incomplete_orders';
    $sql     = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        email VARCHAR(100) DEFAULT '',
        address TEXT DEFAULT '',
        product_names TEXT DEFAULT '',
        cart_total DECIMAL(10,2) DEFAULT 0,
        ip_address VARCHAR(45) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY phone (phone),
        KEY created_at (created_at)
    ) $charset;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    add_option('ofm_db_version', '3.0');
}

// ─── Admin Menu ──────────────────────────────────────────────────────────────
add_action('admin_menu', 'ofm_admin_menu');
function ofm_admin_menu() {
    add_menu_page('COD Order Guard', 'COD Order Guard', 'manage_woocommerce',
        'order-flow-manager', 'ofm_dashboard_page', 'dashicons-shield', 56);
    add_submenu_page('order-flow-manager', 'Dashboard',         'Dashboard',          'manage_woocommerce', 'order-flow-manager',  'ofm_dashboard_page');
    add_submenu_page('order-flow-manager', 'Incomplete Orders', 'Incomplete Orders',  'manage_woocommerce', 'ofm-incomplete',      'ofm_incomplete_page');
    add_submenu_page('order-flow-manager', 'Fraud Checker',     'Fraud Checker',      'manage_woocommerce', 'ofm-fraud',           'ofm_fraud_page');
    add_submenu_page('order-flow-manager', 'Meta CAPI Log',     'Meta CAPI Log',      'manage_woocommerce', 'ofm-capi',            'ofm_capi_log_page');
    add_submenu_page('order-flow-manager', 'Settings',          'Settings',           'manage_woocommerce', 'ofm-settings',        'ofm_settings_page');
}

// ─── Admin Assets ────────────────────────────────────────────────────────────
add_action('admin_enqueue_scripts', 'ofm_admin_assets');
function ofm_admin_assets($hook) {
    $is_ofm   = strpos($hook, 'order-flow') !== false
             || strpos($hook, 'ofm-') !== false;
    $is_order = ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order')
             || $hook === 'woocommerce_page_wc-orders'
             || $hook === 'post.php';
    if (!$is_ofm && !$is_order) return;
    wp_enqueue_style('ofm-admin', OFM_URL . 'assets/admin.css', [], OFM_VERSION);
}

// ─── Frontend JS — all pages (landing page support) ──────────────────────────
add_action('wp_enqueue_scripts', 'ofm_enqueue_scripts');
function ofm_enqueue_scripts() {
    wp_enqueue_script('ofm-checkout', OFM_URL . 'assets/checkout.js', ['jquery'], OFM_VERSION, true);
    wp_localize_script('ofm-checkout', 'ofm_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ofm_save_nonce'),
    ]);
}

// ─── Dashboard ───────────────────────────────────────────────────────────────
function ofm_dashboard_page() {
    global $wpdb;
    $incomplete = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ofm_incomplete_orders");
    $capi_sent  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_ofm_capi_sent' AND meta_value='1'");
    $capi_skip  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_ofm_capi_skipped' AND meta_value='1'");
    $pixel_ok   = get_option('ofm_meta_pixel_id', '') ? true : false;
    $sf_ok      = get_option('ofm_steadfast_api_key', '') ? true : false;
    $threshold  = (int)get_option('ofm_sf_ratio_threshold', 70);
    ?>
    <div class="wrap ofm-wrap">
        <div class="ofm-header">
            <div>
                <h1><span class="dashicons dashicons-shield"></span> COD Order Guard</h1>
                <p>Incomplete Orders &middot; SteadFast Fraud Checker &middot; Meta CAPI — all in one place</p>
            </div>
            <div class="ofm-header-status">
                <span class="ofm-status-dot <?= $sf_ok ? 'green' : 'red' ?>"></span> SteadFast
                <span class="ofm-status-dot <?= $pixel_ok ? 'green' : 'red' ?>" style="margin-left:12px"></span> Meta CAPI
            </div>
        </div>

        <?php if (!$pixel_ok || !$sf_ok): ?>
        <div class="notice notice-warning" style="border-radius:6px;margin-bottom:16px">
            <p><span class="dashicons dashicons-warning"></span> Go to <a href="?page=ofm-settings"><strong>Settings</strong></a> and add your
            <?= !$sf_ok ? 'SteadFast API Key' : '' ?>
            <?= (!$sf_ok && !$pixel_ok) ? ' and ' : '' ?>
            <?= !$pixel_ok ? 'Meta Pixel ID + Access Token' : '' ?>.</p>
        </div>
        <?php endif; ?>

        <div class="ofm-cards">
            <a href="?page=ofm-incomplete" class="ofm-card">
                <div class="ofm-card-icon"><span class="dashicons dashicons-clipboard"></span></div>
                <div class="ofm-card-info">
                    <div class="ofm-card-count"><?= $incomplete ?></div>
                    <h3>Incomplete Orders</h3>
                    <p>Filled the form, didn't complete the order</p>
                </div>
            </a>
            <a href="?page=ofm-fraud" class="ofm-card">
                <div class="ofm-card-icon"><span class="dashicons dashicons-search"></span></div>
                <div class="ofm-card-info">
                    <div class="ofm-card-count" style="font-size:22px"><?= $sf_ok ? '<span class="dashicons dashicons-yes-alt" style="color:#27ae60"></span>' : '<span class="dashicons dashicons-warning" style="color:#f39c12"></span>' ?></div>
                    <h3>Fraud Checker</h3>
                    <p>SteadFast courier history</p>
                </div>
            </a>
            <a href="?page=ofm-capi" class="ofm-card">
                <div class="ofm-card-icon"><span class="dashicons dashicons-share"></span></div>
                <div class="ofm-card-info">
                    <div class="ofm-card-count" style="color:#27ae60"><?= $capi_sent ?></div>
                    <h3>CAPI Sent</h3>
                    <p>Events delivered to Meta</p>
                </div>
            </a>
            <a href="?page=ofm-capi" class="ofm-card">
                <div class="ofm-card-icon"><span class="dashicons dashicons-dismiss"></span></div>
                <div class="ofm-card-info">
                    <div class="ofm-card-count" style="color:#e74c3c"><?= $capi_skip ?></div>
                    <h3>CAPI Skipped</h3>
                    <p>Ratio was below <?= $threshold ?>%</p>
                </div>
            </a>
        </div>

        <div class="ofm-flow-box">
            <h3><span class="dashicons dashicons-randomize"></span> How it works</h3>
            <div class="ofm-flow">
                <div class="ofm-flow-step"><span class="dashicons dashicons-cart"></span><br><small>Customer Order</small></div>
                <div class="ofm-flow-arrow">&rarr;</div>
                <div class="ofm-flow-step"><span class="dashicons dashicons-controls-pause"></span><br><small>On-Hold<br>(GTM block)</small></div>
                <div class="ofm-flow-arrow">&rarr;</div>
                <div class="ofm-flow-step"><span class="dashicons dashicons-admin-users"></span><br><small>Admin Confirm</small></div>
                <div class="ofm-flow-arrow">&rarr;</div>
                <div class="ofm-flow-step"><span class="dashicons dashicons-chart-bar"></span><br><small>SteadFast<br>Ratio Check</small></div>
                <div class="ofm-flow-arrow">&rarr;</div>
                <div class="ofm-flow-step" style="border-color:#27ae60"><span class="dashicons dashicons-share" style="color:#27ae60"></span><br><small>Meta CAPI<br>Purchase</small></div>
            </div>
        </div>

        <p class="ofm-credit">Built by <a href="https://omninode.tech" target="_blank" rel="noopener">Omninode</a></p>
    </div>
    <?php
}

// ─── Settings ────────────────────────────────────────────────────────────────
function ofm_settings_page() {
    if (isset($_POST['ofm_save_settings']) && check_admin_referer('ofm_settings')) {
        update_option('ofm_steadfast_api_key',    sanitize_text_field($_POST['ofm_steadfast_api_key'] ?? ''));
        update_option('ofm_steadfast_secret_key', sanitize_text_field($_POST['ofm_steadfast_secret_key'] ?? ''));
        update_option('ofm_sf_ratio_threshold',   intval($_POST['ofm_sf_ratio_threshold'] ?? 70));
        update_option('ofm_meta_pixel_id',        sanitize_text_field($_POST['ofm_meta_pixel_id'] ?? ''));
        update_option('ofm_meta_access_token',    sanitize_text_field($_POST['ofm_meta_access_token'] ?? ''));
        update_option('ofm_meta_test_code',       sanitize_text_field($_POST['ofm_meta_test_code'] ?? ''));
        echo '<div class="notice notice-success" style="border-radius:6px"><p><span class="dashicons dashicons-yes-alt" style="color:#27ae60"></span> Settings saved.</p></div>';
    }
    $sf_key    = get_option('ofm_steadfast_api_key', '');
    $sf_secret = get_option('ofm_steadfast_secret_key', '');
    $sf_ratio  = get_option('ofm_sf_ratio_threshold', 70);
    $pixel_id  = get_option('ofm_meta_pixel_id', '');
    $token     = get_option('ofm_meta_access_token', '');
    $test_code = get_option('ofm_meta_test_code', '');
    ?>
    <div class="wrap ofm-wrap">
        <h1><span class="dashicons dashicons-admin-generic"></span> Settings</h1>
        <form method="post">
            <?php wp_nonce_field('ofm_settings'); ?>

            <div class="ofm-settings-box">
                <div class="ofm-settings-header">
                    <span class="ofm-settings-icon dashicons dashicons-archive"></span>
                    <div>
                        <h2>SteadFast API</h2>
                        <p>Get credentials from SteadFast &rarr; Account &rarr; API.</p>
                    </div>
                    <span class="ofm-settings-status <?= $sf_key ? 'connected' : 'disconnected' ?>">
                        <?= $sf_key ? '<span class="dashicons dashicons-yes-alt"></span> Connected' : '<span class="dashicons dashicons-warning"></span> Not Connected' ?>
                    </span>
                </div>
                <table class="form-table">
                    <tr>
                        <th>API Key</th>
                        <td><input type="text" name="ofm_steadfast_api_key" value="<?= esc_attr($sf_key) ?>" class="regular-text" placeholder="SteadFast API Key"></td>
                    </tr>
                    <tr>
                        <th>Secret Key</th>
                        <td><input type="password" name="ofm_steadfast_secret_key" value="<?= esc_attr($sf_secret) ?>" class="regular-text" placeholder="SteadFast Secret Key"></td>
                    </tr>
                    <tr>
                        <th>Minimum Ratio</th>
                        <td>
                            <input type="number" name="ofm_sf_ratio_threshold" value="<?= esc_attr($sf_ratio) ?>" min="0" max="100" style="width:80px"> %
                            <p class="description">Below this ratio, no event is sent to Meta. Default: 70%</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="ofm-settings-box" style="margin-top:20px">
                <div class="ofm-settings-header">
                    <span class="ofm-settings-icon dashicons dashicons-share"></span>
                    <div>
                        <h2>Meta CAPI</h2>
                        <p>Events Manager &rarr; Settings &rarr; Conversions API &rarr; Access Token</p>
                    </div>
                    <span class="ofm-settings-status <?= $pixel_id ? 'connected' : 'disconnected' ?>">
                        <?= $pixel_id ? '<span class="dashicons dashicons-yes-alt"></span> Connected' : '<span class="dashicons dashicons-warning"></span> Not Connected' ?>
                    </span>
                </div>
                <table class="form-table">
                    <tr>
                        <th>Pixel ID</th>
                        <td><input type="text" name="ofm_meta_pixel_id" value="<?= esc_attr($pixel_id) ?>" class="regular-text" placeholder="Facebook Pixel ID"></td>
                    </tr>
                    <tr>
                        <th>Access Token</th>
                        <td><input type="password" name="ofm_meta_access_token" value="<?= esc_attr($token) ?>" class="large-text" placeholder="Conversions API Access Token"></td>
                    </tr>
                    <tr>
                        <th>Test Event Code</th>
                        <td>
                            <input type="text" name="ofm_meta_test_code" value="<?= esc_attr($test_code) ?>" class="regular-text" placeholder="TEST12345 (use while testing)">
                            <p class="description">Get the code from Meta Events Manager &rarr; Test Events. Leave empty in production.</p>
                        </td>
                    </tr>
                </table>

                <div class="ofm-info-checklist">
                    <strong><span class="dashicons dashicons-info"></span> To improve Event Match Quality (EMQ), make sure:</strong>
                    <ul>
                        <li><span class="dashicons dashicons-yes"></span> The landing page has the Facebook Pixel (fbq)</li>
                        <li><span class="dashicons dashicons-yes"></span> The plugin automatically captures the _fbp and _fbc cookies</li>
                        <li><span class="dashicons dashicons-yes"></span> CAPI fires once, on COD &rarr; on-hold &rarr; confirm (no duplicates)</li>
                        <li><span class="dashicons dashicons-yes"></span> Events send when SteadFast ratio is <?= $sf_ratio ?>%+ or the customer is new</li>
                        <li><span class="dashicons dashicons-yes"></span> Phone, email, name, and city are hashed before sending</li>
                        <li><span class="dashicons dashicons-yes"></span> event_id = order_id (correct deduplication)</li>
                    </ul>
                </div>
            </div>

            <p style="margin-top:20px">
                <button name="ofm_save_settings" type="submit" class="button button-primary button-large"><span class="dashicons dashicons-saved"></span> Save Settings</button>
            </p>
        </form>

        <p class="ofm-credit">Built by <a href="https://omninode.tech" target="_blank" rel="noopener">Omninode</a></p>
    </div>
    <?php
}
