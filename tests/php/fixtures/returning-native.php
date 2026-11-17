<?php
// Exact evidence method bodies, synthetic CLI harness only.
if (PHP_SAPI !== 'cli') { exit; }
class TKD_Returning_Customer_20261114 {
    public static function pending($id) {
        $r = TKD_FO_Orders::get_responsibility($id);
        return ($r['source'] ?? '') === 'returning_customer_phone' && empty($r['accepted_at']);
    }
    public static function may_accept($id) {
        $r = TKD_FO_Orders::get_responsibility($id);
        return self::pending($id) && current_user_can('manage_tkd_felicity') && current_user_can('edit_post', $id)
            && ((int)($r['user_id'] ?? 0) === get_current_user_id() || current_user_can('manage_options'));
    }
    public static function clear_option_cache($key) {
        foreach (array($key, 'notoptions', 'alloptions') as $name) { wp_cache_delete($name, 'options'); }
    }
    public static function accept($id) {
        global $wpdb;
        if (!self::may_accept($id)) { return false; }
        $key = 'tkd_fo_order_responsibility_' . absint($id);
        $old = get_option($key, false);
        if (!is_array($old) || ($old['source'] ?? '') !== 'returning_customer_phone' || !empty($old['accepted_at'])) { return false; }
        $next = $old; $next['accepted_at'] = time(); $next['accepted_by'] = get_current_user_id();
        $ok = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", maybe_serialize($next), $key, maybe_serialize($old)));
        self::clear_option_cache($key);
        return $ok === 1;
    }
}
class TKD_FO_Orders {
	private static function responsibility_context_is_manual( $order_id ) {
		foreach ( array( 'DOING_CRON', 'DOING_AUTOSAVE', 'REST_REQUEST', 'WP_CLI' ) as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				return false;
			}
		}
		return $order_id && is_admin() && is_user_logged_in() && 'tkd_order' === get_post_type( $order_id )
			&& ! wp_is_post_revision( $order_id ) && ! wp_is_post_autosave( $order_id )
			&& current_user_can( 'manage_tkd_felicity' ) && current_user_can( 'edit_post', $order_id );
	}
	private static function claim_responsibility_locked( $order_id, $reason ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! self::responsibility_context_is_manual( $order_id ) ) {
			return array();
		}
		if (class_exists('TKD_Returning_Customer_20261114')) { TKD_Returning_Customer_20261114::accept($order_id); }
		$option = 'tkd_fo_order_responsibility_' . $order_id;
		$stored = get_option( $option, false );
		// Imported inquiries deliberately start with an empty ownership sentinel.
		// It means unassigned, not an existing owner. Preserve every non-empty record.
		if ( false !== $stored && array() !== $stored ) {
			return self::get_responsibility( $order_id );
		}
		$record = self::get_responsibility( $order_id );
		if ( ! $record ) {
			$user = get_userdata( get_current_user_id() );
			if ( ! $user ) {
				return array();
			}
			$record = array(
				'user_id'   => (int) $user->ID,
				'user_name' => sanitize_text_field( $user->display_name ? $user->display_name : $user->user_login ),
				'time'      => time(),
				'reason'    => sanitize_text_field( $reason ),
				'source'    => 'manual_action',
			);
		}
		// Both paths are atomic: concurrent managers cannot replace the first owner.
		$inserted = array() === $stored
			? $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $record ), $option, maybe_serialize( array() )
			) )
			: $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option, maybe_serialize( $record )
			) );
		if ( false === $inserted ) {
			return array();
		}
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return self::get_responsibility( $order_id );
	}
 public static function get_responsibility($id){return get_option('tkd_fo_order_responsibility_'.$id,array());}
 public static function claim_test($id){return self::claim_responsibility_locked($id,'SYNTHETIC');}
}
