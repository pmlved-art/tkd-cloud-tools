<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Independent regression evidence: exact native source, synthetic WP memory only.
class WP_Error {}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_is_post_revision($id){return false;} function wp_is_post_autosave($id){return false;}
function wp_verify_nonce($n,$a){return true;} function current_user_can($cap){return true;}
function sanitize_key($v){return $v;} function sanitize_text_field($v){return $v;} function wp_unslash($v){return $v;}
function wp_date($fmt){return '2026-11-17';} function get_post_status($id){return 'publish';}
function proof_raw_post_meta($id,$key,$single=true){return $GLOBALS['m'][$id][$key]??'';}
function update_post_meta($id,$key,$v){$GLOBALS['m'][$id][$key]=$v;return true;}
function delete_post_meta($id,$key){unset($GLOBALS['m'][$id][$key]);}
function wp_update_post($p){return $p['ID'];} function wp_die($s){throw new Exception('unexpected die');}
class TKD_FO_Inventory_Admin {static function set_notice($type,$message){}}
class TKD_FO_Orders {static function reconcile_reserved_order($id){$GLOBALS['reserve_calls']++;return new WP_Error();} static function sync_order_status($id){throw new Exception('Must not sync after reserve failure');}}
class NativeInvoiceEvidence {
private static $saving=false;
private static function normalize_seller_key($v){return $v;}
private static function normalize_bank_key($s,$v){return $v;}
private static function linked_order_id($id){return 12;}
private static function register_order_invoice($order,$invoice){}
private static function number_exists($seller,$number,$id){return false;}
private static function status_labels(){return array('draft'=>'draft','issued'=>'issued','paid'=>'paid','cancelled'=>'cancelled');}
	public static function save_invoice( $post_id, $post, $update ) {
		if ( self::$saving || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['tkd_fo_invoice_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tkd_fo_invoice_nonce'] ) ), 'tkd_fo_save_invoice' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_tkd_felicity' ) ) {
			return;
		}

		$seller      = self::normalize_seller_key( isset( $_POST['tkd_fo_seller_key'] ) ? wp_unslash( $_POST['tkd_fo_seller_key'] ) : 'no_vat' );
		$submitted_seller = isset( $_POST['tkd_fo_seller_key'] ) && is_string( $_POST['tkd_fo_seller_key'] ) ? sanitize_key( wp_unslash( $_POST['tkd_fo_seller_key'] ) ) : '';
		$existing_seller = (string) get_post_meta( $post_id, '_tkd_fo_seller_key', true );
		if ( ! in_array( $submitted_seller, array( 'no_vat', 'fop_pm', 'fop_rv', 'fop_tp', 'fop_md', 'fop_eo', 'vat' ), true ) ) { wp_die( 'Оберіть продавця рахунку.' ); }
		$linked_order = self::linked_order_id( $post_id );
		if ( $linked_order && $submitted_seller !== (string) get_post_meta( $linked_order, '_tkd_fo_checkout_seller_key', true ) ) { wp_die( 'Спочатку погодьте продавця в замовленні у блоці «Продавець, оплата й доставка». Рахунок не змінено.' ); }
		if ( $linked_order && $submitted_seller !== $existing_seller ) { wp_die( 'Змінюйте продавця пов’язаного рахунку через погодження умов у замовленні. Рахунок не змінено.' ); }
		$bank_key = isset( $_POST['tkd_fo_bank_key'] ) && is_string( $_POST['tkd_fo_bank_key'] ) ? sanitize_key( wp_unslash( $_POST['tkd_fo_bank_key'] ) ) : get_post_meta( $post_id, '_tkd_fo_bank_key', true );
		if ( 'fop_md' === $seller ) {
			$old_md_bank = 'fop_md' === $existing_seller ? get_post_meta( $post_id, '_tkd_fo_bank_key', true ) : null;
			if ( null === $old_md_bank && '' === $bank_key ) { $bank_key = 'md_privat'; }
			$allowed_banks = self::selectable_bank_choices( 'fop_md', $old_md_bank );
			if ( ! isset( $allowed_banks[self::normalize_bank_key( 'fop_md', $bank_key )] ) ) { wp_die( 'Для нового рахунку Дані доступний ПриватБанк. Райффайзен приховано; історичні рахунки збережено.' ); }
		}
		$bank_key = self::normalize_bank_key( $seller, $bank_key );
		$old_seller  = get_post_meta( $post_id, '_tkd_fo_seller_key', true );
		$old_number  = (string) get_post_meta( $post_id, '_tkd_fo_invoice_number', true );
		$number      = isset( $_POST['tkd_fo_invoice_number'] ) ? sanitize_text_field( wp_unslash( $_POST['tkd_fo_invoice_number'] ) ) : '';
		$date        = isset( $_POST['tkd_fo_invoice_date'] ) ? sanitize_text_field( wp_unslash( $_POST['tkd_fo_invoice_date'] ) ) : wp_date( 'Y-m-d' );
		$status      = isset( $_POST['tkd_fo_invoice_status'] ) ? sanitize_key( wp_unslash( $_POST['tkd_fo_invoice_status'] ) ) : 'draft';
		$status      = array_key_exists( $status, self::status_labels() ) ? $status : 'draft';
		if ( $linked_order ) {
			$archived = get_post_meta( $post_id, '_tkd_fo_invoice_status', true ) === 'cancelled' || get_post_status( $post_id ) === 'trash';
			if ( $archived && $status !== 'cancelled' ) {
				wp_die( 'Цей рахунок в архіві. Новий чинний рахунок формується в замовленні; історичний документ не відновлено.' );
			}
			if ( $status === 'cancelled' && ! $archived ) {
				$allowed = self::manager_archive_guard( $post_id );
				if ( is_wp_error( $allowed ) ) { wp_die( $allowed->get_error_message() ); }
			}
		}

		if ( $seller !== $old_seller && $number && $number === $old_number ) {
			$number = 'vat' === $seller ? '' : self::next_no_vat_number( $date, $seller );
		}
		if ( 'vat' !== $seller && ! $number ) {
			$number = self::next_no_vat_number( $date, $seller );
		}

		$error = '';
		if ( 'vat' === $seller && ! $number ) {
			$error  = 'Для продавця з ПДВ введіть актуальний номер рахунку від бухгалтера.';
			$status = 'draft';
		} elseif ( $number && self::number_exists( $seller, $number, $post_id ) ) {
			$error  = 'Рахунок із таким номером уже існує для цього продавця.';
			$number = '';
			$status = 'draft';
		}

		if ( $linked_order ) {
			// Never trust stale/tampered invoice-editor fields for order-managed pricing.
			// Preserve the invoice snapshot, including gift markers and historic totals.
			$items = get_post_meta( $post_id, '_tkd_fo_invoice_items', true );
			if ( ! is_array( $items ) ) { wp_die( 'Не вдалося прочитати товари рахунку. Перевірте пов’язане замовлення; рахунок не змінено.' ); }
			$totals = array();
			foreach ( array( 'items_subtotal', 'discount', 'subtotal', 'vat', 'total' ) as $key ) {
				$totals[ $key ] = (float) get_post_meta( $post_id, '_tkd_fo_invoice_' . $key, true );
			}
		} else {
			$items    = self::sanitize_items( isset( $_POST['tkd_fo_items'] ) ? wp_unslash( $_POST['tkd_fo_items'] ) : array() );
			$discount = max( 0, TKD_FO_DB::decimal( isset( $_POST['tkd_fo_invoice_discount'] ) ? wp_unslash( $_POST['tkd_fo_invoice_discount'] ) : 0, 2 ) );
			$totals = self::calculate_totals( $items, $seller, $discount );
		}
		if ( ! $items && 'draft' !== $status ) {
			$error  = $error ? $error : 'Додайте хоча б одну позицію номенклатури.';
			$status = 'draft';
		}

		update_post_meta( $post_id, '_tkd_fo_seller_key', $seller );
		update_post_meta( $post_id, '_tkd_fo_bank_key', $bank_key );
		update_post_meta( $post_id, '_tkd_fo_invoice_number', $number );
		update_post_meta( $post_id, '_tkd_fo_invoice_date', $date );
		update_post_meta( $post_id, '_tkd_fo_invoice_status', $status );
		if ( ! $linked_order ) {
			update_post_meta( $post_id, '_tkd_fo_invoice_items', $items );
			update_post_meta( $post_id, '_tkd_fo_invoice_items_subtotal', number_format( $totals['items_subtotal'], 2, '.', '' ) );
			update_post_meta( $post_id, '_tkd_fo_invoice_discount', number_format( $totals['discount'], 2, '.', '' ) );
			update_post_meta( $post_id, '_tkd_fo_invoice_subtotal', number_format( $totals['subtotal'], 2, '.', '' ) );
			update_post_meta( $post_id, '_tkd_fo_invoice_vat', number_format( $totals['vat'], 2, '.', '' ) );
			update_post_meta( $post_id, '_tkd_fo_invoice_total', number_format( $totals['total'], 2, '.', '' ) );
			update_post_meta( $post_id, '_tkd_fo_manual_pricing', '1' );
		}

		$buyer_type = isset( $_POST['tkd_fo_buyer_type'] ) && 'company' === $_POST['tkd_fo_buyer_type'] ? 'company' : 'person';
		update_post_meta( $post_id, '_tkd_fo_buyer_type', $buyer_type );
		foreach ( self::buyer_fields() as $key => $label ) {
			$value = isset( $_POST[ 'tkd_fo_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'tkd_fo_' . $key ] ) ) : '';
			update_post_meta( $post_id, '_tkd_fo_' . $key, $value );
		}

		if ( $error ) {
			TKD_FO_Inventory_Admin::set_notice( 'error', $error );
		} else {
			delete_post_meta( $post_id, '_tkd_fo_invoice_error' );
		}
		self::sync_order( $post_id, $status, $items, $totals, (bool) $linked_order );

		self::$saving = true;
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $number ? 'Рахунок ' . $number : 'Чернетка рахунку #' . $post_id,
				'post_status'=> 'publish',
			)
		);
		self::$saving = false;
		if ( ! $error && isset( $_POST['post_ID'] ) && absint( $_POST['post_ID'] ) === (int) $post_id ) {
			TKD_FO_Orders::claim_responsibility( self::linked_order_id( $post_id ), 'Збережено рахунок № ' . $number );
		}
	}

	private static function sync_order( $invoice_id, $status, $items, $totals, $preserve_order_pricing = false ) {
		$order_id = self::linked_order_id( $invoice_id );
		// Cancelling a document does not cancel its order or release its reservation.
		// Keep the original backlink, number, lines and payment references for history.
		if ( $order_id && ( $status === 'cancelled' || get_post_status( $invoice_id ) === 'trash' ) ) { return; }
		$invoice_seller = self::normalize_seller_key( get_post_meta( $invoice_id, '_tkd_fo_seller_key', true ) );
		if ( ! $order_id && in_array( $status, array( 'issued', 'paid' ), true ) ) {
			$order_id = wp_insert_post( array( 'post_type' => 'tkd_order', 'post_status' => 'publish', 'post_title' => 'Ручне замовлення — рахунок #' . $invoice_id ) );
			if ( is_wp_error( $order_id ) || ! $order_id ) {
				TKD_FO_Inventory_Admin::set_notice( 'error', 'Не вдалося створити пов’язане замовлення.' );
				return;
			}
			$order_number = 'TKD-' . wp_date( 'Ymd' ) . '-' . str_pad( (string) $order_id, 5, '0', STR_PAD_LEFT );
			wp_update_post( array( 'ID' => $order_id, 'post_title' => $order_number . ' — ' . get_post_meta( $invoice_id, '_tkd_fo_buyer_name', true ) ) );
			update_post_meta( $invoice_id, '_tkd_fo_order_id', $order_id );
			update_post_meta( $order_id, '_tkd_order_number', $order_number );
			update_post_meta( $order_id, '_tkd_customer_name', get_post_meta( $invoice_id, '_tkd_fo_buyer_name', true ) );
			update_post_meta( $order_id, '_tkd_customer_phone', get_post_meta( $invoice_id, '_tkd_fo_buyer_phone', true ) );
			update_post_meta( $order_id, '_tkd_customer_email', get_post_meta( $invoice_id, '_tkd_fo_buyer_email', true ) );
			update_post_meta( $order_id, '_tkd_payment_method', 'Безготівкова оплата за рахунком (IBAN)' );
			update_post_meta( $order_id, '_tkd_created_at', current_time( 'mysql' ) );
		}

		if ( ! $order_id ) {
			return;
		}
		$current_order_seller = sanitize_key( (string) get_post_meta( $order_id, '_tkd_fo_checkout_seller_key', true ) );
		if ( '' === $current_order_seller && '' !== $invoice_seller ) {
			update_post_meta( $order_id, '_tkd_fo_checkout_seller_key', $invoice_seller );
		}
		self::register_order_invoice( $order_id, $invoice_id );
		// A seller-specific invoice is only one document package inside the order.
		// Editing it must not replace the order's complete historical lines or total.
		if ( '1' === (string) get_post_meta( $invoice_id, '_tkd_fo_split_invoice', true ) ) {
			return;
		}
		// An unpaid invoice that the customer replaced with LiqPay stays in the
		// document history, but must no longer rewrite the live order or its status.
		if ( 'liqpay' === (string) get_post_meta( $order_id, '_tkd_fo_manager_payment_override', true ) ) {
			return;
		}
		if ( ! $preserve_order_pricing ) {
			update_post_meta( $order_id, '_tkd_fo_invoice_id', $invoice_id );
			update_post_meta( $order_id, '_tkd_fo_order_items', $items );
			update_post_meta( $order_id, '_tkd_fo_order_discount', number_format( isset( $totals['discount'] ) ? $totals['discount'] : 0, 2, '.', '' ) );
			update_post_meta( $order_id, '_tkd_confirmed_amount', number_format( $totals['total'], 2, '.', '' ) );
			update_post_meta( $order_id, '_tkd_client_total', number_format( $totals['total'], 2, '.', '' ) );
			update_post_meta( $order_id, '_tkd_fo_invoice_pricing_locked', '1' );
			$bundle_name = trim( (string) get_post_meta( $order_id, '_tkd_fo_bundle_name', true ) );
			if ( $items && ! $bundle_name ) {
				update_post_meta( $order_id, '_tkd_product_name', $items[0]['name'] );
				update_post_meta( $order_id, '_tkd_product_qty', $items[0]['quantity'] );
				if ( isset( $items[1] ) ) {
					update_post_meta( $order_id, '_tkd_product_extra', $items[1]['name'] );
					update_post_meta( $order_id, '_tkd_product_extra_qty', $items[1]['quantity'] );
				} else {
					delete_post_meta( $order_id, '_tkd_product_extra' );
					delete_post_meta( $order_id, '_tkd_product_extra_qty' );
				}
			} elseif ( $bundle_name && isset( $items[2] ) ) {
				update_post_meta( $order_id, '_tkd_product_extra', $items[2]['name'] );
				update_post_meta( $order_id, '_tkd_product_extra_qty', $items[2]['quantity'] );
			} elseif ( $bundle_name ) {
				delete_post_meta( $order_id, '_tkd_product_extra' );
				delete_post_meta( $order_id, '_tkd_product_extra_qty' );
			}
		}

		$current = get_post_meta( $order_id, '_tkd_status', true );
		if ( 'issued' === $status && ! in_array( $current, array( 'paid', 'shipped' ), true ) ) {
			update_post_meta( $order_id, '_tkd_status', 'invoice_pending' );
		} elseif ( 'paid' === $status && 'shipped' !== $current ) {
			update_post_meta( $order_id, '_tkd_status', 'paid' );
		}
		$reservation = TKD_FO_Orders::reconcile_reserved_order( $order_id );
		if ( ! is_wp_error( $reservation ) ) {
			TKD_FO_Orders::sync_order_status( $order_id );
		}
	}

	private static function buyer_fields() {
		return array(
			'buyer_name'    => 'ПІБ або назва компанії',
			'buyer_tax_id'  => 'ЄДРПОУ / ІПН покупця',
			'buyer_vat_id'  => 'ІПН платника ПДВ',
			'buyer_address' => 'Адреса покупця',
			'buyer_phone'   => 'Телефон',
			'buyer_email'   => 'E-mail',
			'receiver'      => 'Одержувач',
			'payer'         => 'Платник',
			'contract'      => 'Договір',
			'application'   => 'Заявка / номер замовлення',
		);
	}

}
function reset_invoice($status){
$GLOBALS['reserve_calls']=0;
$GLOBALS['m']=array(12=>array('_tkd_status'=>$status,'_tkd_fo_checkout_seller_key'=>'fop_pm'),20=>array('_tkd_fo_seller_key'=>'fop_pm','_tkd_fo_bank_key'=>'privat','_tkd_fo_invoice_status'=>'draft','_tkd_fo_invoice_number'=>'SYNTH-1','_tkd_fo_invoice_items'=>array(array('sku'=>'SYNTHETIC','quantity'=>1,'unit_price'=>100))));
foreach(array('items_subtotal','discount','subtotal','vat','total') as $key){$GLOBALS['m'][20]['_tkd_fo_invoice_'.$key]='100.00';}
$_POST=array('tkd_fo_invoice_nonce'=>'synthetic','tkd_fo_seller_key'=>'fop_pm','tkd_fo_bank_key'=>'privat','tkd_fo_invoice_number'=>'SYNTH-1','tkd_fo_invoice_date'=>'2026-11-17','tkd_fo_invoice_status'=>'issued');
}
reset_invoice('cancelled');NativeInvoiceEvidence::save_invoice(20,(object)array('ID'=>20),true);
if(get_post_meta(12,'_tkd_status',true)!=='invoice_pending'||get_post_meta(20,'_tkd_fo_invoice_status',true)!=='issued')throw new Exception('Native behavior differs');
echo "PASS reproduced: native save revives cancelled order; adapter needs preflight guard\n";
reset_invoice('invoice_pending');NativeInvoiceEvidence::save_invoice(20,(object)array('ID'=>20),true);
if(get_post_meta(20,'_tkd_fo_invoice_status',true)!=='issued'||$GLOBALS['reserve_calls']!==1||get_post_meta(12,'_tkd_fo_reservation_until',true)!=='')throw new Exception('Native behavior differs');
echo "PASS reproduced: issued invoice persists despite reserve error; adapter needs reserve readback\n";
