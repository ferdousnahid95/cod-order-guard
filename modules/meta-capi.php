<?php
if (!defined('ABSPATH')) exit;

// ═══════════════════════════════════════════════════════════════════════════
// MODULE: Meta CAPI Purchase
// Logic: COD order → on-hold (GTM blocked) → admin confirm → on-hold→completed
//        (delivered) → CAPI fires
// Adds: FBP/FBC capture, Settings UI, SteadFast ratio check, order meta status
// Purchase fires ONLY on delivery-confirmed (completed), not processing.
// Cancel event (custom, non-reversing) sent to Meta when an order is cancelled.
// HPOS-compatible: all order meta uses $order->update_meta_data()/get_meta()/save()
//           instead of update_post_meta()/get_post_meta().
// ═══════════════════════════════════════════════════════════════════════════


// ─── 1. Capture FBP + FBC from browser on page load ─────────────────────────
add_action('wp_ajax_ofm_save_fb_cookies', 'ofm_save_fb_cookies');
add_action('wp_ajax_nopriv_ofm_save_fb_cookies', 'ofm_save_fb_cookies');
function ofm_save_fb_cookies() {
    check_ajax_referer('ofm_save_nonce', 'nonce');
    if (!WC()->session) return;
    $fbp = sanitize_text_field($_POST['fbp'] ?? '');
    $fbc = sanitize_text_field($_POST['fbc'] ?? '');
    $ua  = sanitize_text_field($_POST['ua']  ?? '');
    if ($fbp) WC()->session->set('ofm_fbp', $fbp);
    if ($fbc) WC()->session->set('ofm_fbc', $fbc);
    if ($ua)  WC()->session->set('ofm_ua',  $ua);
    wp_send_json_success();
}

// ─── 2. Save FBP/FBC/IP/UA to order meta when order is placed ───────────────
add_action('woocommerce_checkout_order_created', 'ofm_persist_capi_meta');
function ofm_persist_capi_meta($order) {
    if (!WC()->session) return;
    $fbp = WC()->session->get('ofm_fbp', '');
    $fbc = WC()->session->get('ofm_fbc', '');
    $ua  = WC()->session->get('ofm_ua',  $_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip  = WC_Geolocation::get_ip_address() ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($fbp) $order->update_meta_data('_ofm_fbp', $fbp);
    if ($fbc) $order->update_meta_data('_ofm_fbc', $fbc);
    if ($ua)  $order->update_meta_data('_ofm_ua',  $ua);
    if ($ip)  $order->update_meta_data('_ofm_ip',  $ip);
    $order->save();
}

// ─── 3. CAPI Purchase — fires on completed (delivery confirmed) ─────────────
add_action('woocommerce_order_status_changed', 'ofm_meta_capi_purchase', 10, 4);
function ofm_meta_capi_purchase($order_id, $old_status, $new_status, $order) {

    // Gate 1: Must go TO completed only (delivery confirmed) — processing ≠ final conversion
    if ('completed' !== $new_status) return;

    if (!$order) $order = wc_get_order($order_id);
    if (!$order) return;

    // Gate 2: Idempotency — prevent double-fire (HPOS-compatible)
    if ($order->get_meta('_ofm_capi_sent')) return;

    // Gate 3: Skip admin-created orders (not real Facebook conversions)
    if ('admin' === $order->get_meta('_wc_order_attribution_source_type')) {
        $order->update_meta_data('_ofm_capi_skipped', 1);
        $order->save();
        error_log("[OFM CAPI] Skipped — order #{$order_id} created via admin, not a real FB conversion");
        return;
    }

    // Gate 4: Credentials
    $pixel_id     = get_option('ofm_meta_pixel_id', '');
    $access_token = get_option('ofm_meta_access_token', '');
    if (!$pixel_id || !$access_token) {
        error_log("[OFM CAPI] Pixel ID or Access Token missing — order #{$order_id} skipped.");
        return;
    }

    // ── SteadFast Ratio Check ─────────────────────────────────────────────
    $phone      = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
    $api_key    = get_option('ofm_steadfast_api_key', '');
    $secret_key = get_option('ofm_steadfast_secret_key', '');
    $sf_ratio   = null;

    if ($api_key && $secret_key && $phone) {
        $sf_res = wp_remote_get('https://portal.packzy.com/api/v1/fraud-check/' . $phone, [
            'timeout' => 10,
            'headers' => ['Api-Key' => $api_key, 'Secret-Key' => $secret_key],
        ]);
        if (!is_wp_error($sf_res)) {
            $sf_data  = json_decode(wp_remote_retrieve_body($sf_res), true);
            $total    = intval($sf_data['total_order'] ?? 0);
            if ($total > 0) {
                $received = intval($sf_data['total_received'] ?? 0);
                $sf_ratio = (int) round(($received / $total) * 100);
                $threshold = (int) get_option('ofm_sf_ratio_threshold', 70);
                $order->update_meta_data('_ofm_sf_ratio', $sf_ratio);
                $order->save();
                if ($sf_ratio < $threshold) {
                    $order->update_meta_data('_ofm_capi_skipped', 1);
                    $order->save();
                    error_log("[OFM CAPI] Order #{$order_id} skipped — SteadFast ratio {$sf_ratio}% < threshold {$threshold}%");
                    return;
                }
            }
            // No history = new customer → allow
        }
    }

    // ── Normalize BD phone: 01XXXXXXXXX → 8801XXXXXXXXX ─────────────────
    if (11 === strlen($phone) && '0' === substr($phone, 0, 1)) {
        $phone = '880' . substr($phone, 1);
    }

    // ── User data ─────────────────────────────────────────────────────────
    $h = fn($v) => !empty($v) ? hash('sha256', strtolower(trim($v))) : null;

    $fbp = $order->get_meta('_ofm_fbp') ?: '';
    $fbc = $order->get_meta('_ofm_fbc') ?: '';
    $ip  = $order->get_meta('_ofm_ip')  ?: '';
    $ua  = $order->get_meta('_ofm_ua')  ?: '';

    $user_data = array_filter([
        'em'                => $h($order->get_billing_email()),
        'ph'                => $h($phone),
        'fn'                => $h($order->get_billing_first_name()),
        'ln'                => $h($order->get_billing_last_name()),
        'ct'                => $h($order->get_billing_city()),
        'country'           => $h('bd'),
        'client_ip_address' => $ip  ?: null,
        'client_user_agent' => $ua  ?: null,
        'fbp'               => $fbp ?: null,
        'fbc'               => $fbc ?: null,
    ]);

    // ── Custom data ───────────────────────────────────────────────────────
    $items = [];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $items[] = [
            'id'         => $product ? (string)($product->get_sku() ?: $product->get_id()) : '',
            'quantity'   => $item->get_quantity(),
            'item_price' => (float) $order->get_item_subtotal($item, false),
        ];
    }

    $event_id    = (string) $order_id;
    $custom_data = [
        'currency'     => $order->get_currency(),
        'value'        => (float) $order->get_total(),
        'order_id'     => $event_id,
        'content_ids'  => array_column($items, 'id'),
        'content_type' => 'product',
        'contents'     => $items,
        'num_items'    => count($items),
    ];

    // ── Payload ───────────────────────────────────────────────────────────
    $payload = [
        'data' => [[
            'event_name'       => 'Purchase',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => $order->get_checkout_order_received_url(),
            'event_id'         => $event_id,
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
        ]],
    ];

    // Test event code (from Settings)
    $test_code = get_option('ofm_meta_test_code', '');
    if ($test_code) $payload['test_event_code'] = $test_code;

    // ── Send ──────────────────────────────────────────────────────────────
    $url = "https://graph.facebook.com/v19.0/{$pixel_id}/events?access_token={$access_token}";
    $response = wp_remote_post($url, [
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload),
    ]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $order->update_meta_data('_ofm_capi_sent', 1);
        $order->update_meta_data('_ofm_capi_sent_time', current_time('mysql'));
        if ($sf_ratio !== null) $order->update_meta_data('_ofm_sf_ratio', $sf_ratio);
        $order->save();
        error_log("[OFM CAPI] Purchase sent — order #{$order_id} | {$old_status}→{$new_status}" . ($sf_ratio !== null ? " | ratio:{$sf_ratio}%" : ''));
    } else {
        $err = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
        error_log("[OFM CAPI] FAILED order #{$order_id}: {$err}");
        $order->update_meta_data('_ofm_capi_error', $err);
        $order->save();
    }
}

// ─── 3b. Cancel Event — signals Meta the order was NOT delivered ───────────
// NOTE: This is a CUSTOM event, not a Meta standard "Purchase" reversal.
// Meta has no public API to undo/delete a previously sent Purchase event.
// Real fix: Purchase now fires ONLY on completed (Gate 1 above), so a
// cancelled order (on-hold/processing → cancelled) never gets a Purchase sent
// in the first place — nothing to "undo". This event exists for two cases:
//   1. Building a Custom Audience of cancelled orders (exclude from remarketing)
//   2. Rare edge case: order already completed, then later cancelled/refunded
//      (Purchase already counted — this event won't remove it from ROAS, but
//      it's logged here for your own records / audience suppression)
add_action('woocommerce_order_status_changed', 'ofm_meta_capi_cancel', 10, 4);
function ofm_meta_capi_cancel($order_id, $old_status, $new_status, $order) {

    if ('cancelled' !== $new_status) return;

    if (!$order) $order = wc_get_order($order_id);
    if (!$order) return;

    if ($order->get_meta('_ofm_capi_cancelled')) return; // idempotency

    $pixel_id     = get_option('ofm_meta_pixel_id', '');
    $access_token = get_option('ofm_meta_access_token', '');
    if (!$pixel_id || !$access_token) return;

    $was_purchase_sent = (bool) $order->get_meta('_ofm_capi_sent');

    $phone = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
    if (11 === strlen($phone) && '0' === substr($phone, 0, 1)) {
        $phone = '880' . substr($phone, 1);
    }
    $h = fn($v) => !empty($v) ? hash('sha256', strtolower(trim($v))) : null;

    $user_data = array_filter([
        'em'                => $h($order->get_billing_email()),
        'ph'                => $h($phone),
        'fn'                => $h($order->get_billing_first_name()),
        'ln'                => $h($order->get_billing_last_name()),
        'ct'                => $h($order->get_billing_city()),
        'country'           => $h('bd'),
        'client_ip_address' => $order->get_meta('_ofm_ip') ?: null,
        'client_user_agent' => $order->get_meta('_ofm_ua') ?: null,
        'fbp'               => $order->get_meta('_ofm_fbp') ?: null,
        'fbc'               => $order->get_meta('_ofm_fbc') ?: null,
    ]);

    $payload = [
        'data' => [[
            'event_name'    => 'OrderCancelled', // custom event name, not a Meta standard event
            'event_time'    => time(),
            'action_source' => 'website',
            'event_id'      => 'cancel_' . $order_id,
            'user_data'     => $user_data,
            'custom_data'   => [
                'currency'          => $order->get_currency(),
                'value'             => (float) $order->get_total(),
                'order_id'          => (string) $order_id,
                'was_purchase_sent' => $was_purchase_sent ? 'yes' : 'no',
            ],
        ]],
    ];

    $test_code = get_option('ofm_meta_test_code', '');
    if ($test_code) $payload['test_event_code'] = $test_code;

    $url = "https://graph.facebook.com/v19.0/{$pixel_id}/events?access_token={$access_token}";
    $response = wp_remote_post($url, [
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload),
    ]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $order->update_meta_data('_ofm_capi_cancelled', 1);
        $order->update_meta_data('_ofm_capi_cancelled_time', current_time('mysql'));
        $order->save();
        error_log("[OFM CAPI] Cancel event sent — order #{$order_id}" . ($was_purchase_sent ? ' (Purchase was already sent earlier — count not reversible)' : ' (Purchase never sent — clean)'));
    } else {
        $err = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
        error_log("[OFM CAPI] Cancel event FAILED order #{$order_id}: {$err}");
    }
}

// ─── 4. Show CAPI status in WooCommerce order detail page ───────────────────
add_action('woocommerce_admin_order_data_after_billing_address', 'ofm_capi_status_in_order');
function ofm_capi_status_in_order($order) {
    $sent      = $order->get_meta('_ofm_capi_sent');
    $skipped   = $order->get_meta('_ofm_capi_skipped');
    $cancelled = $order->get_meta('_ofm_capi_cancelled');
    $ratio     = $order->get_meta('_ofm_sf_ratio');
    $fbp       = $order->get_meta('_ofm_fbp');
    $fbc       = $order->get_meta('_ofm_fbc');
    $time      = $order->get_meta('_ofm_capi_sent_time');
    $error     = $order->get_meta('_ofm_capi_error');
    $threshold = (int) get_option('ofm_sf_ratio_threshold', 70);
    ?>
    <div style="margin-top:14px;padding:12px 16px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;font-size:13px;line-height:2;">
        <strong style="font-size:13px;"><span class="dashicons dashicons-share"></span> Meta CAPI Status</strong><br>
        <?php if ($sent): ?>
            <span style="color:#27ae60;font-weight:600;"><span class="dashicons dashicons-yes-alt"></span> Purchase event sent</span>
            <?php if ($time): ?><br><span style="color:#888;font-size:12px;"><span class="dashicons dashicons-clock"></span> <?= esc_html($time) ?></span><?php endif; ?>
        <?php elseif ($skipped): ?>
            <span style="color:#e74c3c;font-weight:600;"><span class="dashicons dashicons-dismiss"></span> Skipped</span>
            <br><span style="color:#666;">SteadFast ratio: <strong><?= esc_html($ratio) ?>%</strong> (threshold: <?= $threshold ?>%)</span>
        <?php elseif ($error): ?>
            <span style="color:#e74c3c;font-weight:600;"><span class="dashicons dashicons-warning"></span> Error</span>
            <br><span style="color:#888;font-size:11px;"><?= esc_html(substr($error, 0, 120)) ?></span>
        <?php else: ?>
            <span style="color:#f39c12;font-weight:600;"><span class="dashicons dashicons-clock"></span> Not fired yet</span>
            <br><span style="color:#888;font-size:12px;">Fires when the order moves on-hold → completed (delivery confirmed)</span>
        <?php endif; ?>

        <?php if ($cancelled): ?>
            <br><span style="color:#c0392b;font-weight:600;"><span class="dashicons dashicons-dismiss"></span> Cancel event sent to Meta</span>
        <?php endif; ?>

        <hr style="border:none;border-top:1px solid #dee2e6;margin:8px 0;">

        <span style="color:<?= $fbp ? '#27ae60' : '#e74c3c' ?>">
            <?= $fbp ? '<span class="dashicons dashicons-yes-alt"></span> FBP: ' . esc_html(substr($fbp, 0, 25)) . '...' : '<span class="dashicons dashicons-warning"></span> No FBP (lowers EMQ)' ?>
        </span><br>
        <span style="color:<?= $fbc ? '#27ae60' : '#888' ?>">
            <?= $fbc ? '<span class="dashicons dashicons-yes-alt"></span> FBC: ' . esc_html(substr($fbc, 0, 25)) . '...' : '— No FBC (normal without an ad click)' ?>
        </span>
        <?php if ($ratio !== ''): ?>
            <br><span style="color:#555;"><span class="dashicons dashicons-chart-bar"></span> SteadFast ratio: <strong><?= esc_html($ratio) ?>%</strong></span>
        <?php endif; ?>
    </div>
    <?php
}

// ─── 5. CAPI Log page (last 30 orders) ──────────────────────────────────────
function ofm_capi_log_page() {
    global $wpdb;

    $pixel_id  = get_option('ofm_meta_pixel_id', '');
    $threshold = (int) get_option('ofm_sf_ratio_threshold', 70);

    // Stats — HPOS-compatible: query wp_wc_orders_meta instead of wp_postmeta
    $sent_count      = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key='_ofm_capi_sent' AND meta_value='1'");
    $skipped_count   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key='_ofm_capi_skipped' AND meta_value='1'");
    $error_count     = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key='_ofm_capi_error'");
    $cancelled_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key='_ofm_capi_cancelled' AND meta_value='1'");

    // Last 30 orders with CAPI status — HPOS-compatible
    $order_ids = $wpdb->get_col("
        SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_orders_meta
        WHERE meta_key IN ('_ofm_capi_sent','_ofm_capi_skipped','_ofm_capi_error','_ofm_capi_cancelled')
        ORDER BY order_id DESC LIMIT 30
    ");
    ?>
    <div class="wrap ofm-wrap">
        <h1><span class="dashicons dashicons-share"></span> Meta CAPI</h1>

        <?php if (!$pixel_id): ?>
        <div class="notice notice-error" style="border-radius:6px">
            <p><span class="dashicons dashicons-warning"></span> Pixel ID is not set. <a href="?page=ofm-settings"><strong>Go to Settings</strong></a></p>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="ofm-capi-stats">
            <div class="ofm-stat-card ofm-stat-green">
                <div class="ofm-stat-num"><?= $sent_count ?></div>
                <div class="ofm-stat-label"><span class="dashicons dashicons-yes-alt"></span> Event Sent</div>
            </div>
            <div class="ofm-stat-card ofm-stat-red">
                <div class="ofm-stat-num"><?= $skipped_count ?></div>
                <div class="ofm-stat-label"><span class="dashicons dashicons-dismiss"></span> Skipped (low ratio)</div>
            </div>
            <div class="ofm-stat-card ofm-stat-orange">
                <div class="ofm-stat-num"><?= $error_count ?></div>
                <div class="ofm-stat-label"><span class="dashicons dashicons-warning"></span> Error</div>
            </div>
            <div class="ofm-stat-card ofm-stat-blue">
                <div class="ofm-stat-num"><?= $threshold ?>%</div>
                <div class="ofm-stat-label"><span class="dashicons dashicons-chart-bar"></span> Ratio Threshold</div>
            </div>
            <div class="ofm-stat-card ofm-stat-red">
                <div class="ofm-stat-num"><?= $cancelled_count ?></div>
                <div class="ofm-stat-label"><span class="dashicons dashicons-dismiss"></span> Cancel Event Sent</div>
            </div>
        </div>

        <!-- Info box -->
        <div class="ofm-capi-info">
            <strong><span class="dashicons dashicons-admin-generic"></span> How it works:</strong>
            <ul>
                <li>COD order placed → <strong>on-hold</strong> (GTM purchase block)</li>
                <li>Admin confirms + delivered → <strong>completed</strong> → CAPI Purchase fires</li>
                <li>SteadFast ratio <strong><?= $threshold ?>%+</strong> or a new customer → <span class="dashicons dashicons-yes-alt"></span> event is sent</li>
                <li>SteadFast ratio <strong>below <?= $threshold ?>%</strong> → <span class="dashicons dashicons-dismiss"></span> skipped</li>
                <li>Order <strong>cancelled</strong> → a custom "OrderCancelled" event is sent (for tracking/audience exclusion — doesn't reduce ROAS count)</li>
                <li>FBP capture improves Event Match Quality (EMQ)</li>
            </ul>
        </div>

        <!-- Recent orders table -->
        <?php if (empty($order_ids)): ?>
            <div class="ofm-empty">No CAPI events yet</div>
        <?php else: ?>
        <h2 style="margin-top:24px">Recent Orders</h2>
        <table class="ofm-table wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="80">Order</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>CAPI Status</th>
                    <th>SteadFast</th>
                    <th>FBP</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($order_ids as $oid):
                $order   = wc_get_order($oid);
                if (!$order) continue;
                $sent    = $order->get_meta('_ofm_capi_sent');
                $skipped = $order->get_meta('_ofm_capi_skipped');
                $err     = $order->get_meta('_ofm_capi_error');
                $cancelled = $order->get_meta('_ofm_capi_cancelled');
                $ratio   = $order->get_meta('_ofm_sf_ratio');
                $fbp     = $order->get_meta('_ofm_fbp');
                $sent_t  = $order->get_meta('_ofm_capi_sent_time');

                if ($sent) {
                    $status_html = '<span class="ofm-badge ofm-badge-green"><span class="dashicons dashicons-yes-alt"></span> Sent</span>';
                } elseif ($cancelled) {
                    $status_html = '<span class="ofm-badge ofm-badge-red"><span class="dashicons dashicons-dismiss"></span> Cancelled</span>';
                } elseif ($skipped) {
                    $status_html = '<span class="ofm-badge ofm-badge-red"><span class="dashicons dashicons-dismiss"></span> Skipped</span>';
                } elseif ($err) {
                    $status_html = '<span class="ofm-badge ofm-badge-orange"><span class="dashicons dashicons-warning"></span> Error</span>';
                } else {
                    $status_html = '<span class="ofm-badge ofm-badge-gray"><span class="dashicons dashicons-clock"></span> Pending</span>';
                }

                $ratio_color = '';
                if ($ratio !== '') {
                    $ratio_color = $ratio >= $threshold ? 'color:#27ae60;font-weight:600' : 'color:#e74c3c;font-weight:600';
                }
            ?>
            <tr>
                <td><a href="<?= get_edit_post_link($oid) ?>" target="_blank">#<?= $oid ?></a></td>
                <td>
                    <strong><?= esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?></strong><br>
                    <small><?= esc_html($order->get_billing_phone()) ?></small>
                </td>
                <td><?= esc_html(get_woocommerce_currency_symbol($order->get_currency())) ?><?= number_format($order->get_total(), 0) ?></td>
                <td><?= $status_html ?><?php if ($sent_t): ?><br><small style="color:#888"><?= esc_html(human_time_diff(strtotime($sent_t))) ?> ago</small><?php endif; ?></td>
                <td><?php if ($ratio !== ''): ?><span style="<?= $ratio_color ?>"><?= $ratio ?>%</span><?php else: ?><span style="color:#888">—</span><?php endif; ?></td>
                <td><?= $fbp ? '<span class="dashicons dashicons-yes-alt" style="color:#27ae60"></span>' : '<span class="dashicons dashicons-no" style="color:#e74c3c"></span>' ?></td>
                <td><?= esc_html(human_time_diff(strtotime($order->get_date_created()))) ?> ago</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
