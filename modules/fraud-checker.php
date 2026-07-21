<?php
if (!defined('ABSPATH')) exit;

// AJAX — check single phone via SteadFast
add_action('wp_ajax_ofm_check_fraud', 'ofm_check_fraud');
function ofm_check_fraud() {
    check_ajax_referer('ofm_fraud_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

    $phone      = sanitize_text_field($_POST['phone'] ?? '');
    $api_key    = get_option('ofm_steadfast_api_key', '');
    $secret_key = get_option('ofm_steadfast_secret_key', '');

    if (!$phone) wp_send_json_error('Please enter a phone number');
    if (!$api_key || !$secret_key) wp_send_json_error('Set your SteadFast API key in Settings');

    $response = wp_remote_get('https://portal.packzy.com/api/v1/fraud-check/' . urlencode($phone), [
        'timeout' => 15,
        'headers' => [
            'Api-Key'    => $api_key,
            'Secret-Key' => $secret_key,
            'Content-Type' => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('API Error: ' . $response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    wp_send_json_success($body);
}

// Show fraud info in WooCommerce order list (extra column)
add_filter('manage_edit-shop_order_columns', 'ofm_add_fraud_column');
function ofm_add_fraud_column($columns) {
    $new = [];
    foreach ($columns as $key => $val) {
        $new[$key] = $val;
        if ($key === 'order_status') {
            $new['ofm_fraud'] = 'Fraud Check';
        }
    }
    return $new;
}

add_action('manage_shop_order_posts_custom_column', 'ofm_fraud_column_content', 10, 2);
function ofm_fraud_column_content($column, $post_id) {
    if ($column !== 'ofm_fraud') return;
    $order = wc_get_order($post_id);
    if (!$order) return;
    $phone = $order->get_billing_phone();
    if (!$phone) { echo '<span style="color:#999">—</span>'; return; }
    $phone_clean = preg_replace('/[^0-9]/', '', $phone);
    ?>
    <button class="button button-small ofm-check-btn"
            data-phone="<?= esc_attr($phone_clean) ?>"
            data-order="<?= $post_id ?>">
        Check
    </button>
    <div class="ofm-fraud-result" id="ofm-result-<?= $post_id ?>"></div>
    <?php
}

// HPOS support
add_filter('manage_woocommerce_page_wc-orders_columns', 'ofm_add_fraud_column');
add_action('manage_woocommerce_page_wc-orders_custom_column', 'ofm_fraud_column_content', 10, 2);

// Enqueue admin JS for order list
add_action('admin_enqueue_scripts', 'ofm_fraud_admin_scripts');
function ofm_fraud_admin_scripts($hook) {
    $is_order_page = ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order')
                  || ($hook === 'woocommerce_page_wc-orders');
    if (!$is_order_page) return;

    wp_enqueue_style('ofm-admin', OFM_URL . 'assets/admin.css', [], OFM_VERSION);
    wp_register_script('ofm-fraud', false, ['jquery'], OFM_VERSION, true);
    wp_enqueue_script('ofm-fraud');
    wp_localize_script('ofm-fraud', 'ofmFraud', [
        'ajax'  => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ofm_fraud_nonce'),
    ]);
    wp_add_inline_script('ofm-fraud', ofm_fraud_inline_js());
}

function ofm_fraud_inline_js() {
    return 'jQuery(function($){
    $(document).on("click", ".ofm-check-btn", function(){
        var btn    = $(this);
        var phone  = btn.data("phone");
        var order  = btn.data("order");
        var result = $("#ofm-result-" + order);

        btn.prop("disabled", true).text("...");
        result.html("");

        $.post(ofmFraud.ajax, {
            action: "ofm_check_fraud",
            nonce: ofmFraud.nonce,
            phone: phone
        }, function(res) {
            btn.prop("disabled", false).text("Check");
            if (!res.success) {
                result.html("<span style=\"color:red;font-size:11px\">" + res.data + "</span>");
                return;
            }
            var d = res.data;
            if (d.status === 0) {
                result.html("<span style=\"color:#999;font-size:11px\">No history found</span>");
                return;
            }
            var total    = d.total_order || 0;
            var received = d.total_received || 0;
            var cancel   = d.total_cancel || 0;
            var ratio    = total > 0 ? Math.round((received / total) * 100) : 0;
            var color    = ratio >= 70 ? "#27ae60" : ratio >= 40 ? "#f39c12" : "#e74c3c";
            var label    = ratio >= 70 ? "Good" : ratio >= 40 ? "Caution" : "Risky";
            result.html(
                "<div style=\"font-size:11px;margin-top:4px;line-height:1.6\">" +
                "<strong style=\"color:" + color + "\">" + label + "</strong><br>" +
                "Total: " + total + " | Received: " + received + " | Cancelled: " + cancel + "<br>" +
                "<strong style=\"color:" + color + "\">Ratio: " + ratio + "%</strong>" +
                "</div>"
            );
        });
    });
});';
}

// Fraud checker manual search page
function ofm_fraud_page() {
    $api_key = get_option('ofm_steadfast_api_key', '');
    ?>
    <div class="wrap ofm-wrap">
        <h1><span class="dashicons dashicons-search"></span> Fraud Checker</h1>

        <?php if (!$api_key): ?>
            <div class="notice notice-warning">
                <p><span class="dashicons dashicons-warning"></span> Set your SteadFast API key. <a href="?page=ofm-settings">Go to Settings</a></p>
            </div>
        <?php endif; ?>

        <div class="ofm-fraud-search-box">
            <h2>Check by phone number</h2>
            <div style="display:flex;gap:10px;align-items:center;margin-top:12px;">
                <input type="text" id="ofm-manual-phone" placeholder="01XXXXXXXXX" class="regular-text" maxlength="15">
                <button id="ofm-manual-check" class="button button-primary"><span class="dashicons dashicons-search"></span> Check</button>
            </div>
            <div id="ofm-manual-result" style="margin-top:16px;"></div>
        </div>

        <div class="ofm-info-box" style="margin-top:24px;">
            <h3><span class="dashicons dashicons-info"></span> Fraud Check in the Order List</h3>
            <p>Go to WooCommerce &rarr; Orders and every order will show a <strong>"Fraud Check"</strong> column. Click <strong>Check</strong> and the customer's SteadFast delivery history appears instantly.</p>
            <p><strong>What the ratio means:</strong></p>
            <ul>
                <li><span class="dashicons dashicons-yes-alt" style="color:#27ae60"></span> <strong style="color:#27ae60">70%+</strong> — Good customer, safe to deliver</li>
                <li><span class="dashicons dashicons-warning" style="color:#f39c12"></span> <strong style="color:#f39c12">40–69%</strong> — Use caution, call to confirm</li>
                <li><span class="dashicons dashicons-dismiss" style="color:#e74c3c"></span> <strong style="color:#e74c3c">Below 40%</strong> — Risky, take advance payment</li>
            </ul>
        </div>
    </div>

    <script>
    jQuery(function($){
        var nonce = '<?= wp_create_nonce('ofm_fraud_nonce') ?>';

        $('#ofm-manual-check').on('click', function(){
            var phone  = $('#ofm-manual-phone').val().trim().replace(/[^0-9]/g, '');
            var result = $('#ofm-manual-result');
            if (!phone || phone.length < 10) {
                result.html('<div class="ofm-alert ofm-alert-error">Please enter a valid phone number</div>');
                return;
            }
            $(this).prop('disabled', true).text('Checking...');
            result.html('<div class="ofm-loading">Searching SteadFast...</div>');

            $.post(ajaxurl, {
                action: 'ofm_check_fraud',
                nonce: nonce,
                phone: phone
            }, function(res){
                $('#ofm-manual-check').prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Check');
                if (!res.success) {
                    result.html('<div class="ofm-alert ofm-alert-error">' + res.data + '</div>');
                    return;
                }
                var d = res.data;
                if (d.status === 0 || !d.total_order) {
                    result.html('<div class="ofm-alert ofm-alert-warning">No SteadFast history found for this number.</div>');
                    return;
                }
                var total    = d.total_order || 0;
                var received = d.total_received || 0;
                var cancel   = d.total_cancel || 0;
                var inhand   = total - received - cancel;
                var ratio    = total > 0 ? Math.round((received / total) * 100) : 0;
                var color    = ratio >= 70 ? '#27ae60' : ratio >= 40 ? '#f39c12' : '#e74c3c';
                var label    = ratio >= 70 ? 'Good customer' : ratio >= 40 ? 'Use caution' : 'Risky';

                result.html(
                    '<div class="ofm-fraud-card" style="border-left:4px solid '+color+'">' +
                    '<h3 style="color:'+color+';margin-top:0">'+label+'</h3>' +
                    '<table class="ofm-fraud-table">' +
                    '<tr><td>Total Orders</td><td><strong>'+total+'</strong></td></tr>' +
                    '<tr><td>Received</td><td><strong style="color:#27ae60">'+received+'</strong></td></tr>' +
                    '<tr><td>Cancelled</td><td><strong style="color:#e74c3c">'+cancel+'</strong></td></tr>' +
                    '<tr><td>In Transit</td><td><strong style="color:#3498db">'+(inhand > 0 ? inhand : 0)+'</strong></td></tr>' +
                    '<tr><td>Success Ratio</td><td><strong style="color:'+color+';font-size:18px">'+ratio+'%</strong></td></tr>' +
                    '</table></div>'
                );
            });
        });

        $('#ofm-manual-phone').on('keypress', function(e){
            if (e.which === 13) $('#ofm-manual-check').click();
        });
    });
    </script>
    <?php
}
