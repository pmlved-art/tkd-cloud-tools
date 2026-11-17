<?php
// Exact native evidence; CLI synthetic helpers supplied by test.
if (PHP_SAPI !== 'cli') { exit; }
class Synthetic_NP_Status_Old {
 use NP_Status_Fixture_Helpers;
	public static function status( $id, $for_release_prepare = false ) {
		$out = array( 'enabled' => false, 'mode' => 'np', 'state' => 'preparing', 'blockers' => array(), 'url' => '', 'preparation_valid' => false, 'warning' => '' );
		if ( self::id( $id ) ) { self::fresh( $id ); }
		if ( ! self::can( $id ) ) { $out['blockers'][] = 'Немає доступу до цієї видаткової.'; return $out; }
		$out['url'] = self::url( $id ); $s = self::record( $id );
		if ( ! $s ) { $out['blockers'][] = 'Ця видаткова не включена в новий контроль НП. Історичні документи автоматично не додаються.'; return $out; }
		$c = self::context( $id, false );
		if ( ( $s['version'] ?? null ) !== 1 || ( $s['mode'] ?? '' ) !== 'np' || ! in_array( $s['state'] ?? '', array( 'preparing', 'awaiting_handover', 'handed_over' ), true ) || is_wp_error( $c ) || ( $s['order'] ?? null ) !== ( $c['order'] ?? null ) ) { $out['blockers'][] = is_wp_error( $c ) ? $c->get_error_message() : 'Запис контролю НП пошкоджений.'; return $out; }
		$out['enabled'] = true; $out['state'] = $s['state']; $out['revision'] = self::revision( $id, $s, $c );
		if ( isset( $s['reconciliation'] ) ) {
			$valid = self::reconciliation_record_valid( $s, $c, self::task( $id ) ) && self::unique_order( $c ) && ! is_wp_error( self::prepost_claim_guard( $c, get_post_meta( $id, '_tkd_fo_delivery_status', true ) === 'draft' ) );
			if ( $s['state'] === 'handed_over' ) {
				$ready = self::context( $id, true ); $intent = get_post_meta( $id, '_tkd_fo_delivery_handover_post_v1', true );
				$proof = self::evidence( $s['carrier'] ?? null, $c['binding'], false );
				$valid = $valid && ! is_wp_error( $ready ) && ( $s['reconciliation']['phase'] ?? '' ) === 'completed' && ( $s['fingerprint'] ?? '' ) === self::fingerprint( $c ) && self::owns_claim( $c )
					&& is_array( $intent ) && ( $intent['state'] ?? '' ) === 'committed' && ( $intent['notification'] ?? '' ) === 'completed'
					&& ! is_wp_error( $proof ) && $proof['accepted'] === true && self::utc( $proof['checked_at'] ) >= self::utc( $s['reconciliation']['proof']['checked_at'] ?? '' );
				if ( $valid ) { $out['evidence'] = $proof; $out['reconciled'] = true; }
			} else { $valid = $valid && $s['state'] === 'preparing' && ( $s['reconciliation']['phase'] ?? '' ) === 'admitted' && self::profile_enabled( $c['binding'] ); }
			if ( ! $valid ) { $out['enabled'] = false; $out['state'] = 'preparing'; $out['blockers'][] = 'Незмінний запис звірки вже відправленої ЕН потребує перевірки. Повторне списання заборонене.'; }
			elseif ( $s['state'] !== 'handed_over' && $for_release_prepare ) { $out['blockers'][] = 'Завершіть окрему дію «Вже передано НП»; звичайна підготовка не застосовується.'; }
			return $out;
		}
		$retry = $for_release_prepare === true && $s['state'] === 'preparing' && empty( $s['initial_rejected'] );
		if ( $for_release_prepare === true && ! self::profile_enabled( $c['binding'] ) ) { $out['blockers'][] = 'Профіль відправника не ввімкнено для контролю передачі.'; }
		if ( $for_release_prepare === true ) {
			$draft = get_post_meta( $id, '_tkd_fo_delivery_status', true ) === 'draft';
			$admission = self::prepost_claim_guard( $c, $draft ); if ( is_wp_error( $admission ) ) { $out['blockers'][] = $admission->get_error_message(); }
			if ( $draft ) { $key = self::claim_key( $c['binding']['ttn'] ); self::option_fresh( $key ); if ( get_option( $key, null ) !== null ) { $out['blockers'][] = 'ЕН уже закріплена проведеною видатковою. Нове списання заблоковане.'; } }
		}
		if ( ! empty( $s['probe_pending'] ) && ( ! $retry || ( $s['probe_until'] ?? 0 ) > time() ) ) { $out['blockers'][] = ( $s['probe_until'] ?? 0 ) > time() ? 'Перша перевірка ЕН виконується; зачекайте до хвилини.' : 'Перша перевірка перервана. Менеджер може безпечно повторити читання стану тієї самої ЕН.'; }
		if ( ! empty( $s['initial_rejected'] ) ) { $out['blockers'][] = 'Перша перевірка виявила вже прийняту ЕН. Історичне відправлення не можна включити повторно.'; }
		if ( isset( $s['fingerprint'] ) && ( ! is_string( $s['fingerprint'] ) || ! hash_equals( $s['fingerprint'], self::fingerprint( $c ) ) || ! self::owns_claim( $c ) || ! self::unique_order( $c ) ) ) { $out['blockers'][] = 'Склад документів або незмінний зв’язок ЕН змінився. Потрібна звірка менеджера.'; }
		if ( $s['state'] !== 'preparing' ) {
			$ready = self::context( $id, true ); if ( is_wp_error( $ready ) ) { $out['blockers'][] = $ready->get_error_message(); }
			if ( empty( $s['fingerprint'] ) || ! isset( $s['baseline'] ) || is_wp_error( self::evidence( $s['baseline'], $c['binding'], false ) ) || ( $s['baseline']['state_code'] ?? '' ) !== '1' || ( $s['baseline']['accepted'] ?? null ) !== false ) { $out['blockers'][] = 'Немає первинного підтвердження нової ЕН (стан 1).' ; }
		}
		if ( $s['state'] === 'handed_over' ) {
			$proof = self::evidence( $s['carrier'] ?? null, $c['binding'], false );
			if ( is_wp_error( $proof ) || ( $proof['accepted'] ?? null ) !== true || self::utc( $proof['checked_at'] ?? null ) < self::utc( $s['baseline']['checked_at'] ?? null ) ) { $out['blockers'][] = 'Немає незмінного підтвердження фактичного приймання НП.'; }
			elseif ( ! $out['blockers'] ) { $out['evidence'] = $proof; }
		}
		if ( $s['state'] === 'awaiting_handover' && ! $out['blockers'] ) {
			$admission = self::prepost_claim_guard( $c );
			if ( is_wp_error( $admission ) ) { $out['blockers'][] = $admission->get_error_message(); }
			if ( ! self::profile_enabled( $c['binding'] ) ) { $out['blockers'][] = 'Профіль контролю НП вимкнено або його реквізити змінилися. Потрібна звірка.'; }
			// A transient polling failure is not evidence that a prepared parcel left the warehouse.
			if ( ! $out['blockers'] && ( empty( $s['last_error'] ) || $s['last_error'] === 'carrier_unverified' ) ) {
				$out['preparation_valid'] = true;
				if ( ( $s['last_error'] ?? '' ) === 'carrier_unverified' ) { $out['warning'] = 'carrier_unverified'; }
			}
		}
		if ( ! empty( $s['last_error'] ) && ! ( $retry && in_array( $s['last_error'], array( 'baseline_not_new', 'release_changed' ), true ) ) ) { $out['blockers'][] = 'Останню перевірку НП не підтверджено. Очікується наступна перевірка або звірка менеджера.'; }
		if ( $out['blockers'] && $out['state'] === 'handed_over' ) { $out['state'] = 'awaiting_handover'; }
		return $out;
	}
}
class TKD_NP_Dispatch {
 use NP_Status_Fixture_Helpers;
	public static function status( $id, $for_release_prepare = false ) {
		if ( self::id( $id ) ) { self::fresh( $id ); }
		return self::status_read( $id, $for_release_prepare );
	}
	private static function status_read( $id, $for_release_prepare ) {
		$out = array( 'enabled' => false, 'mode' => 'np', 'state' => 'preparing', 'blockers' => array(), 'url' => '', 'preparation_valid' => false, 'warning' => '' );
		if ( ! self::can( $id ) ) { $out['blockers'][] = 'Немає доступу до цієї видаткової.'; return $out; }
		$out['url'] = self::url( $id ); $s = self::record( $id );
		if ( ! $s ) { $out['blockers'][] = 'Ця видаткова не включена в новий контроль НП. Історичні документи автоматично не додаються.'; return $out; }
		$c = self::context( $id, false );
		if ( ( $s['version'] ?? null ) !== 1 || ( $s['mode'] ?? '' ) !== 'np' || ! in_array( $s['state'] ?? '', array( 'preparing', 'awaiting_handover', 'handed_over' ), true ) || is_wp_error( $c ) || ( $s['order'] ?? null ) !== ( $c['order'] ?? null ) ) { $out['blockers'][] = is_wp_error( $c ) ? $c->get_error_message() : 'Запис контролю НП пошкоджений.'; return $out; }
		$out['enabled'] = true; $out['state'] = $s['state']; $out['revision'] = self::revision( $id, $s, $c );
		if ( isset( $s['reconciliation'] ) ) {
			$valid = self::reconciliation_record_valid( $s, $c, self::task( $id ) ) && self::unique_order( $c ) && ! is_wp_error( self::prepost_claim_guard( $c, get_post_meta( $id, '_tkd_fo_delivery_status', true ) === 'draft' ) );
			if ( $s['state'] === 'handed_over' ) {
				$ready = self::context( $id, true ); $intent = get_post_meta( $id, '_tkd_fo_delivery_handover_post_v1', true );
				$proof = self::evidence( $s['carrier'] ?? null, $c['binding'], false );
				$valid = $valid && ! is_wp_error( $ready ) && ( $s['reconciliation']['phase'] ?? '' ) === 'completed' && ( $s['fingerprint'] ?? '' ) === self::fingerprint( $c ) && self::owns_claim( $c )
					&& is_array( $intent ) && ( $intent['state'] ?? '' ) === 'committed' && ( $intent['notification'] ?? '' ) === 'completed'
					&& ! is_wp_error( $proof ) && $proof['accepted'] === true && self::utc( $proof['checked_at'] ) >= self::utc( $s['reconciliation']['proof']['checked_at'] ?? '' );
				if ( $valid ) { $out['evidence'] = $proof; $out['reconciled'] = true; }
			} else { $valid = $valid && $s['state'] === 'preparing' && ( $s['reconciliation']['phase'] ?? '' ) === 'admitted' && self::profile_enabled( $c['binding'] ); }
			if ( ! $valid ) { $out['enabled'] = false; $out['state'] = 'preparing'; $out['blockers'][] = 'Незмінний запис звірки вже відправленої ЕН потребує перевірки. Повторне списання заборонене.'; }
			elseif ( $s['state'] !== 'handed_over' && $for_release_prepare ) { $out['blockers'][] = 'Завершіть окрему дію «Вже передано НП»; звичайна підготовка не застосовується.'; }
			return $out;
		}
		$retry = $for_release_prepare === true && $s['state'] === 'preparing' && empty( $s['initial_rejected'] );
		if ( $for_release_prepare === true && ! self::profile_enabled( $c['binding'] ) ) { $out['blockers'][] = 'Профіль відправника не ввімкнено для контролю передачі.'; }
		if ( $for_release_prepare === true ) {
			$draft = get_post_meta( $id, '_tkd_fo_delivery_status', true ) === 'draft';
			$admission = self::prepost_claim_guard( $c, $draft ); if ( is_wp_error( $admission ) ) { $out['blockers'][] = $admission->get_error_message(); }
			if ( $draft ) { $key = self::claim_key( $c['binding']['ttn'] ); self::option_fresh( $key ); if ( get_option( $key, null ) !== null ) { $out['blockers'][] = 'ЕН уже закріплена проведеною видатковою. Нове списання заблоковане.'; } }
		}
		if ( ! empty( $s['probe_pending'] ) && ( ! $retry || ( $s['probe_until'] ?? 0 ) > time() ) ) { $out['blockers'][] = ( $s['probe_until'] ?? 0 ) > time() ? 'Перша перевірка ЕН виконується; зачекайте до хвилини.' : 'Перша перевірка перервана. Менеджер може безпечно повторити читання стану тієї самої ЕН.'; }
		if ( ! empty( $s['initial_rejected'] ) ) { $out['blockers'][] = 'Перша перевірка виявила вже прийняту ЕН. Історичне відправлення не можна включити повторно.'; }
		if ( isset( $s['fingerprint'] ) && ( ! is_string( $s['fingerprint'] ) || ! hash_equals( $s['fingerprint'], self::fingerprint( $c ) ) || ! self::owns_claim( $c ) || ! self::unique_order( $c ) ) ) { $out['blockers'][] = 'Склад документів або незмінний зв’язок ЕН змінився. Потрібна звірка менеджера.'; }
		if ( $s['state'] !== 'preparing' ) {
			$ready = self::context( $id, true ); if ( is_wp_error( $ready ) ) { $out['blockers'][] = $ready->get_error_message(); }
			if ( empty( $s['fingerprint'] ) || ! isset( $s['baseline'] ) || is_wp_error( self::evidence( $s['baseline'], $c['binding'], false ) ) || ( $s['baseline']['state_code'] ?? '' ) !== '1' || ( $s['baseline']['accepted'] ?? null ) !== false ) { $out['blockers'][] = 'Немає первинного підтвердження нової ЕН (стан 1).' ; }
		}
		if ( $s['state'] === 'handed_over' ) {
			$proof = self::evidence( $s['carrier'] ?? null, $c['binding'], false );
			if ( is_wp_error( $proof ) || ( $proof['accepted'] ?? null ) !== true || self::utc( $proof['checked_at'] ?? null ) < self::utc( $s['baseline']['checked_at'] ?? null ) ) { $out['blockers'][] = 'Немає незмінного підтвердження фактичного приймання НП.'; }
			elseif ( ! $out['blockers'] ) { $out['evidence'] = $proof; }
		}
		if ( $s['state'] === 'awaiting_handover' && ! $out['blockers'] ) {
			$admission = self::prepost_claim_guard( $c );
			if ( is_wp_error( $admission ) ) { $out['blockers'][] = $admission->get_error_message(); }
			if ( ! self::profile_enabled( $c['binding'] ) ) { $out['blockers'][] = 'Профіль контролю НП вимкнено або його реквізити змінилися. Потрібна звірка.'; }
			// A transient polling failure is not evidence that a prepared parcel left the warehouse.
			if ( ! $out['blockers'] && ( empty( $s['last_error'] ) || $s['last_error'] === 'carrier_unverified' ) ) {
				$out['preparation_valid'] = true;
				if ( ( $s['last_error'] ?? '' ) === 'carrier_unverified' ) { $out['warning'] = 'carrier_unverified'; }
			}
		}
		if ( ! empty( $s['last_error'] ) && ! ( $retry && in_array( $s['last_error'], array( 'baseline_not_new', 'release_changed' ), true ) ) ) { $out['blockers'][] = 'Останню перевірку НП не підтверджено. Очікується наступна перевірка або звірка менеджера.'; }
		if ( $out['blockers'] && $out['state'] === 'handed_over' ) { $out['state'] = 'awaiting_handover'; }
		return $out;
	}
}
