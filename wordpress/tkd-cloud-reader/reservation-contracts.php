<?php
if (!defined('ABSPATH')) { exit; }
/** Pure preflight for the future journal-owned fixed total-hour adapter. No writes. */
final class TKD_Cloud_Reservation_Contracts {
    // native_deadline must be supplied only by the pinned public total_deadline
    // service under the order mutex. Never accept it from a REST request.
    public static function preflight($hours, $state, $native_deadline, $now) {
        if (!is_int($hours) || !in_array($hours, array(36, 48, 72), true) || !is_array($state) || !is_int($now) || $now < 1) { return 'payload_schema'; }
        $keys = array('stock_state', 'status', 'post_status', 'unpaid_verified', 'confirmed', 'protected_stock', 'editor_locked', 'current_until');
        if (count($state) !== count($keys) || array_diff($keys, array_keys($state))) { return 'state_unverified'; }
        foreach (array('unpaid_verified', 'confirmed', 'protected_stock', 'editor_locked') as $key) {
            if (!is_bool($state[$key])) { return 'state_unverified'; }
        }
        if (!is_string($state['status']) || !is_string($state['post_status']) || !is_string($state['stock_state']) || !is_int($state['current_until'])) { return 'state_unverified'; }
        if (!in_array($state['post_status'], array('publish', 'private'), true) || in_array($state['status'], array('paid', 'partially_paid', 'balance_pending', 'shipped', 'cancelled', 'returned', 'refunded', 'unavailable', 'deferred_payment'), true)) { return 'closed_or_paid'; }
        if (!$state['unpaid_verified'] || $state['confirmed']) { return 'payment_or_confirmation'; }
        if ($state['protected_stock']) { return 'protected_stock'; }
        if ($state['editor_locked']) { return 'editor_locked'; }
        if ($state['stock_state'] !== 'reserved') { return 'no_active_reserve'; }
        if ($state['current_until'] <= $now) { return 'reserve_expired'; }
        if (!is_int($native_deadline) || $native_deadline <= $now) { return 'total_deadline_expired_or_missing_start'; }
        // Native total duration may be shorter than an earlier extension; do not
        // silently turn the operation into relative-to-now or preserve-max math.
        return array('hours' => $hours, 'stage' => 'extended', 'until' => $native_deadline);
    }
}
