<?php
if (!defined('ABSPATH')) exit;

// AJAX save
add_action('wp_ajax_ofm_save_incomplete', 'ofm_save_incomplete');
add_action('wp_ajax_nopriv_ofm_save_incomplete', 'ofm_save_incomplete');
function ofm_save_incomplete() {
    check_ajax_referer('ofm_save_nonce', 'nonce');

    $name    = sanitize_text_field($_POST['name'] ?? '');
    $phone   = sanitize_text_field($_POST['phone'] ?? '');
    $email   = sanitize_email($_POST['email'] ?? '');
    $address = sanitize_text_field($_POST['address'] ?? '');

    if (empty($name) || empty($phone)) wp_send_json_error('Missing data');

    global $wpdb;
    $table = $wpdb->prefix . 'ofm_incomplete_orders';

    $cart_total = 0; $product_names = '';
    if (function_exists('WC') && WC()->cart) {
        $cart_total = WC()->cart->get_cart_contents_total();
        $items = [];
        foreach (WC()->cart->get_cart() as $item) $items[] = $item['data']->get_name();
        $product_names = implode(', ', $items);
    }

    $ip       = $_SERVER['REMOTE_ADDR'] ?? '';
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE phone = %s", $phone));

    if ($existing) {
        $wpdb->update($table, [
            'name' => $name, 'email' => $email, 'address' => $address,
            'product_names' => $product_names, 'cart_total' => $cart_total,
            'updated_at' => current_time('mysql'),
        ], ['id' => $existing]);
    } else {
        $wpdb->insert($table, [
            'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address,
            'product_names' => $product_names, 'cart_total' => $cart_total,
            'ip_address' => $ip, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ]);
    }

    wp_send_json_success('Saved');
}

// Remove on order complete
add_action('woocommerce_checkout_order_created', 'ofm_remove_on_order');
function ofm_remove_on_order($order) {
    $phone = $order->get_billing_phone();
    if (!$phone) return;
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'ofm_incomplete_orders', ['phone' => sanitize_text_field($phone)]);
}

// CSV Export
add_action('admin_init', 'ofm_handle_csv_export');
function ofm_handle_csv_export() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'ofm-incomplete') return;
    if (!isset($_GET['ofm_export']) || $_GET['ofm_export'] !== 'csv') return;
    if (!check_admin_referer('ofm_export_csv')) return;
    if (!current_user_can('manage_woocommerce')) return;

    global $wpdb;
    $results  = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ofm_incomplete_orders ORDER BY created_at DESC", ARRAY_A);
    $filename = 'incomplete-orders-' . date('Y-m-d-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['#', 'Name', 'Phone', 'Email', 'Address', 'Products', 'Total (BDT)', 'IP', 'Time']);
    foreach ($results as $row) {
        fputcsv($output, [$row['id'], $row['name'], $row['phone'], $row['email'],
            $row['address'], $row['product_names'], $row['cart_total'], $row['ip_address'], $row['created_at']]);
    }
    fclose($output);
    exit;
}

// Admin page
function ofm_incomplete_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'ofm_incomplete_orders';

    if (isset($_GET['delete_id']) && check_admin_referer('ofm_delete_incomplete')) {
        $wpdb->delete($table, ['id' => intval($_GET['delete_id'])]);
        echo '<div class="notice notice-success"><p>Deleted.</p></div>';
    }

    if (isset($_POST['ofm_cleanup']) && check_admin_referer('ofm_bulk_cleanup')) {
        $days = intval($_POST['cleanup_days'] ?? 7);
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days));
        echo '<div class="notice notice-success"><p>Old records deleted.</p></div>';
    }

    $search = sanitize_text_field($_GET['search'] ?? '');
    $where  = '';
    if ($search) {
        $where = $wpdb->prepare(" WHERE phone LIKE %s OR name LIKE %s OR email LIKE %s",
            '%'.$wpdb->esc_like($search).'%', '%'.$wpdb->esc_like($search).'%', '%'.$wpdb->esc_like($search).'%');
    }

    $total      = $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
    $results    = $wpdb->get_results("SELECT * FROM $table $where ORDER BY created_at DESC LIMIT 200");
    $export_url = wp_nonce_url(admin_url('admin.php?page=ofm-incomplete&ofm_export=csv'), 'ofm_export_csv');
    ?>
    <div class="wrap ofm-wrap">
        <h1><span class="dashicons dashicons-clipboard"></span> Incomplete Orders <span class="ofm-count"><?= $total ?></span></h1>

        <div class="ofm-toolbar">
            <form method="get">
                <input type="hidden" name="page" value="ofm-incomplete">
                <input type="text" name="search" placeholder="Name, phone, or email..." value="<?= esc_attr($search) ?>">
                <button type="submit" class="button">Search</button>
                <?php if ($search): ?><a href="?page=ofm-incomplete" class="button">Clear</a><?php endif; ?>
            </form>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <a href="<?= esc_url($export_url) ?>" class="button ofm-export-btn"><span class="dashicons dashicons-download"></span> CSV Export</a>
                <form method="post" style="display:inline-flex;gap:8px;align-items:center;">
                    <?php wp_nonce_field('ofm_bulk_cleanup'); ?>
                    <label>Delete records older than (days):</label>
                    <input type="number" name="cleanup_days" value="7" min="1" style="width:60px">
                    <button name="ofm_cleanup" type="submit" class="button button-secondary">Cleanup</button>
                </form>
            </div>
        </div>

        <?php if (empty($results)): ?>
            <div class="ofm-empty"><span class="dashicons dashicons-yes-alt" style="color:#27ae60"></span> No incomplete orders</div>
        <?php else: ?>
        <table class="ofm-table wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="40">#</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Address</th>
                    <th>Products</th>
                    <th>Total</th>
                    <th>Time</th>
                    <th width="50"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row):
                    $time_ago  = human_time_diff(strtotime($row->created_at), current_time('timestamp'));
                    $call_link = 'tel:' . preg_replace('/[^0-9+]/', '', $row->phone);
                    $wa_link   = 'https://wa.me/88' . ltrim(preg_replace('/[^0-9]/', '', $row->phone), '0');
                ?>
                <tr>
                    <td><?= $row->id ?></td>
                    <td><strong><?= esc_html($row->name) ?></strong></td>
                    <td>
                        <a href="<?= $call_link ?>" class="ofm-phone"><span class="dashicons dashicons-phone"></span> <?= esc_html($row->phone) ?></a>
                        <a href="<?= $wa_link ?>" target="_blank" class="ofm-wa"><span class="dashicons dashicons-format-chat"></span></a>
                    </td>
                    <td><?= $row->email ? '<a href="mailto:'.esc_attr($row->email).'">'.esc_html($row->email).'</a>' : '—' ?></td>
                    <td><?= esc_html($row->address ?: '—') ?></td>
                    <td><?= esc_html($row->product_names ?: '—') ?></td>
                    <td><?= $row->cart_total ? 'BDT '.number_format($row->cart_total, 0) : '—' ?></td>
                    <td title="<?= esc_attr($row->created_at) ?>"><?= $time_ago ?> ago</td>
                    <td>
                        <a href="<?= wp_nonce_url('?page=ofm-incomplete&delete_id='.$row->id, 'ofm_delete_incomplete') ?>"
                           class="button button-small ofm-delete-btn"
                           onclick="return confirm('Delete this record?')"><span class="dashicons dashicons-trash"></span></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
