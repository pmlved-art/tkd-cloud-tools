<?php
if (!defined('ABSPATH')) { exit; }
/** Shared by the unchanged native admin factory and the fixed cloud factory. */
final class TKD_Cloud_Delivery_Creation_Lock {
    private static $ui_number_lock = null;
    private static $ui_order = 0;
    public static function boot() {
        add_action('admin_post_tkd_fo_create_delivery', array(__CLASS__, 'before_native'), 0);
        add_action('shutdown', array(__CLASS__, 'release_native'), PHP_INT_MAX);
    }
    public static function acquire() {
        global $wpdb;
        if (!is_string($wpdb->prefix) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $wpdb->prefix)) { throw new RuntimeException('number_lock_schema'); }
        // A single short factory lock also covers year rollover and different sellers.
        $key = 'tkd_cloud_delivery_' . substr(hash('sha256', $wpdb->prefix . '|native-factory-v1'), 0, 40);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $key)) !== '1') { throw new RuntimeException('number_lock_busy'); }
        return $key;
    }
    public static function release($key) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    }
    public static function fresh_sequence($seller) {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        $year = (int)wp_date('Y');
        $seller = sanitize_key($seller ? $seller : 'no_vat');
        foreach (array($year - 1, $year, $year + 1) as $y) {
            wp_cache_delete('tkd_fo_delivery_sequence_' . $seller . '_' . $y, 'options');
        }
    }
    public static function before_native() {
        // If a future native update disables cloud contracts, leave its UI alone.
        $contract = TKD_Cloud_Native_Contracts::report('status.change');
        if (empty($contract['verified'])) { return; }
        if (!current_user_can('manage_tkd_felicity')) { wp_die('Недостатньо прав.'); }
        $raw = $_GET['invoice_id'] ?? null;
        if (!is_scalar($raw) || !preg_match('/\A[1-9][0-9]{0,17}\z/D', (string)$raw)) { wp_die('Рахунок не знайдено.'); }
        $invoice = (int)$raw;
        check_admin_referer('tkd_fo_create_delivery_' . $invoice);
        if (get_post_type($invoice) !== 'tkd_invoice') { wp_die('Рахунок не знайдено.'); }
        try {
            $order = (int)get_post_meta($invoice, '_tkd_fo_order_id', true);
            if ($order > 0) {
                $locked = TKD_FO_Orders::acquire_reservation_mutex($order);
                if ($locked !== true) { throw new RuntimeException('order_busy'); }
                self::$ui_order = $order;
            }
            self::$ui_number_lock = self::acquire();
            foreach (array_unique(array($invoice, $order)) as $id) {
                if ($id > 0) { wp_cache_delete($id, 'post_meta'); wp_cache_delete($id, 'posts'); }
            }
            // Link changes while waiting must not move the operation to another order.
            if ((int)get_post_meta($invoice, '_tkd_fo_order_id', true) !== $order) { throw new RuntimeException('order_changed'); }
            self::fresh_sequence(get_post_meta($invoice, '_tkd_fo_seller_key', true));
        } catch (Throwable $ignored) {
            self::release_native();
            wp_die('Створення видаткової зараз зайняте або дані змінилися. Оновіть сторінку та повторіть після завершення іншої операції.', '', array('response' => 409));
        }
        // Native callback continues unchanged and exits after its redirect.
    }
    public static function release_native() {
        $key = self::$ui_number_lock; self::$ui_number_lock = null;
        $order = self::$ui_order; self::$ui_order = 0;
        try { if ($key !== null) { self::release($key); } }
        finally { if ($order > 0) { TKD_FO_Orders::release_reservation_mutex($order); } }
    }
}
