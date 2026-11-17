<?php
if (!defined('ABSPATH')) { exit; }
/** Native-operation adapters; no generic mutation/callback/meta/SQL interface. */
final class TKD_Cloud_Operations {
    const STATUS_CAP = 'tkd_cloud_change_status';
    const PAYMENT_CAP = 'tkd_cloud_add_manual_payment';
    const CHOICES = array('availability_pending', 'availability_callback', 'discount_inquiry', 'client_thinking', 'client_bought', 'unavailable', 'cancelled_repeat', 'cancelled_irrelevant', 'payment_pending', 'cod_pending', 'invoice_pending', 'prepayment_invoice_sent', 'partially_paid', 'balance_pending', 'paid');
    const STATE_KEYS = array('_edit_lock', '_edit_last', '_tkd_order_number', '_tkd_status', '_tkd_fo_cancel_reason', '_tkd_fo_stock_state', '_tkd_fo_reserved_items', '_tkd_fo_payments', '_tkd_prepayment_received', '_tkd_confirmed_amount', '_tkd_client_total', '_tkd_fo_invoice_id', '_tkd_fo_order_items', '_tkd_paid_at', '_tkd_payment_token', '_tkd_payment_expires', '_tkd_liqpay_order_id', '_tkd_fo_checkout_seller_key', '_tkd_fo_manager_payment_override', '_tkd_np_delivery_method', '_tkd_np_payment_type', '_tkd_fo_invoice_split_done', '_tkd_fo_reservation_manager_id', '_tkd_fo_reservation_manager_source', '_tkd_fo_reservation_log', '_tkd_svitloe_wfp_binding_v1', '_tkd_mobile_status_history');

    public static function routes() {
        TKD_Cloud_Document_PDF::routes();
        register_rest_route('tkd-cloud/v1', '/orders/(?P<number>[A-Za-z0-9-]{1,64})/document-snapshot', array('methods'=>'GET','callback'=>array(__CLASS__,'document_snapshot'),'permission_callback'=>array('TKD_Cloud_Reader','permission')));
        register_rest_route('tkd-cloud/v1', '/capabilities', array('methods' => 'GET', 'callback' => array(__CLASS__, 'capabilities'), 'permission_callback' => array('TKD_Cloud_Reader', 'permission')));
        register_rest_route('tkd-cloud/v1', '/orders/(?P<number>[A-Za-z0-9-]{1,64})/operation-snapshot', array('methods' => 'GET', 'callback' => array(__CLASS__, 'snapshot'), 'permission_callback' => array('TKD_Cloud_Reader', 'permission')));
        register_rest_route('tkd-cloud/v1', '/operations/(?P<operation_id>[a-f0-9-]{36})', array('methods' => 'GET', 'callback' => array(__CLASS__, 'journal'), 'permission_callback' => array('TKD_Cloud_Reader', 'permission')));
        register_rest_route('tkd-cloud/v1', '/operations', array('methods' => 'POST', 'callback' => array(__CLASS__, 'execute'), 'permission_callback' => array(__CLASS__, 'permission')));
    }

    private static function error($reason, $status = 409) {
        return new WP_Error('tkd_operation_' . $reason, 'Cloud operation refused; inspect the safe reason enum.', array('status' => $status));
    }

    private static function cap($cap) {
        $user = wp_get_current_user();
        return !empty($user->allcaps[$cap]) && current_user_can($cap);
    }

    public static function permission($request) {
        $auth = TKD_Cloud_Reader::application_permission($request);
        if ($auth !== true) { return $auth; }
        if ($request->get_method() !== 'POST') { return self::error('method', 405); }
        // No nonce creation or native service invocation here.
        return self::cap('manage_tkd_felicity') && (self::cap(self::STATUS_CAP) || self::cap(self::PAYMENT_CAP) || self::cap('tkd_cloud_delivery_draft') || self::cap('tkd_cloud_invoice') || self::cap('tkd_cloud_extend_reservation') || self::cap('tkd_cloud_issue_delivery')) ? true : self::error('forbidden', 403);
    }

    public static function capabilities($request) {
        $result = array();
        foreach (array_merge(array('status.change' => self::STATUS_CAP, 'payment.add_manual' => self::PAYMENT_CAP), TKD_Cloud_Document_Operations::CAPS) as $name => $cap) {
            $report = TKD_Cloud_Document_Operations::supports($name) ? TKD_Cloud_Document_Operations::pins($name) : TKD_Cloud_Native_Contracts::report($name);
            $result[$name] = array('implemented' => true, 'actor_capable' => self::cap($cap) && self::cap('manage_tkd_felicity'), 'contracts' => $report);
        }
        foreach (array('deferred_payment', 'items.edit', 'invoice.create', 'reservation.change', 'stock.change', 'payment.attest_bank', 'payment.attribute_legacy', 'payment.tov') as $name) { $result[$name] = array('implemented' => false, 'reason' => 'unsupported'); }
        return new WP_REST_Response(array('bridge_version' => TKD_Cloud_Reader::VERSION, 'operations' => $result, 'manual_channels' => TKD_Cloud_Native_Contracts::channels(), 'preview_is_acceptance' => false));
    }

    public static function bounded_value($v, &$nodes, &$bytes, $depth = 0) {
        if (++$nodes > 10000 || $depth > 12 || $bytes > 524288) { return false; }
        if (is_array($v)) {
            foreach ($v as $k => $child) { $bytes += strlen((string) $k); if (!self::bounded_value($child, $nodes, $bytes, $depth + 1)) { return false; } }
        } elseif (is_string($v)) { $bytes += strlen($v); }
        elseif (!is_null($v) && !is_bool($v) && !is_int($v) && !(is_float($v) && is_finite($v))) { return false; }
        return $bytes <= 524288;
    }

    private static function state($id, $number, $operation = null, $payload = array()) {
        wp_cache_delete($id, 'post_meta'); wp_cache_delete($id, 'posts');
        $minimal = TKD_Cloud_Reader::read_number($number);
        if ($minimal instanceof WP_Error) { return $minimal; }
        $base = $minimal->get_data();
        if ($base['id'] !== $id) { return self::error('order_identity'); }
        $raw = array(); $nodes = 0; $bytes = 0;
        foreach (self::STATE_KEYS as $key) {
            $values = get_post_meta($id, $key, false);
            if ($key !== '_tkd_mobile_status_history' && count($values) > 1) { return self::error('ambiguous_state', 503); }
            if (!self::bounded_value($values, $nodes, $bytes)) { return self::error('state_bound', 503); }
            $raw[$key] = $values;
        }
        $owner = get_option('tkd_fo_order_responsibility_' . $id, false);
        if (!self::bounded_value($owner, $nodes, $bytes)) { return self::error('state_bound', 503); }
        $invoice = array(); $invoice_id = self::value($raw, '_tkd_fo_invoice_id');
        if ($invoice_id !== '' && $invoice_id !== null && ((!is_string($invoice_id) && !is_int($invoice_id)) || !preg_match('/\A[0-9]+\z/D', (string) $invoice_id) || strlen((string) $invoice_id) > 18)) { return self::error('invoice_schema', 503); }
        if ((int) $invoice_id > 0) {
            $invoice_post = get_post((int) $invoice_id);
            if (!$invoice_post) { return self::error('invoice_schema', 503); }
            wp_cache_delete((int) $invoice_id, 'post_meta');
            foreach (array('_tkd_fo_seller_key', '_tkd_fo_invoice_status', '_tkd_fo_payer', '_tkd_fo_buyer_name', '_tkd_fo_invoice_number', '_tkd_fo_invoice_total') as $key) {
                $values = get_post_meta((int) $invoice_id, $key, false);
                if (count($values) > 1 || !self::bounded_value($values, $nodes, $bytes)) { return self::error('invoice_schema', 503); }
                $invoice[$key] = $values;
            }
            $invoice['modified'] = $invoice_post->post_modified_gmt;
        }
        $baseline = TKD_FO_Mobile_Cabinet_20261011::status_baseline($id);
        if (!is_string($baseline) || !preg_match('/\A[a-f0-9]{64}\z/D', $baseline)) { return self::error('baseline_schema', 503); }
        $revision = hash_hmac('sha256', serialize(array($base, $raw, $owner, $invoice, $baseline)), wp_salt('nonce'));
        $result = array('base' => $base, 'raw' => $raw, 'revision' => $revision, 'baseline' => $baseline);
        return TKD_Cloud_Document_Operations::supports($operation) ? TKD_Cloud_Document_Operations::augment($result,$operation,$payload) : $result;
    }

    private static function value($raw, $key) { return empty($raw[$key]) ? '' : $raw[$key][0]; }

    public static function editor_locked($id) {
        if (!function_exists('wp_check_post_lock')) {
            $core = ABSPATH . 'wp-admin/includes/post.php';
            if (!is_file($core) || !is_readable($core)) { return self::error('edit_lock_unavailable', 503); }
            require_once $core;
        }
        wp_cache_delete($id, 'post_meta');
        $values = get_post_meta($id, '_edit_lock', false);
        if (count($values) > 1) { return self::error('edit_lock_schema', 503); }
        $raw = $values ? $values[0] : '';
        if ($raw !== '' && (!is_string($raw) || !preg_match('/\A([0-9]{1,12}):([0-9]{1,18})\z/D', $raw, $parts))) { return self::error('edit_lock_schema', 503); }
        if (wp_check_post_lock($id)) { return true; }
        // Also protect an editor using the same technical account; core excludes self.
        if ($raw !== '') {
            $window = apply_filters('wp_check_post_lock_window', 150);
            if (!is_int($window) || $window < 1 || $window > 86400) { return self::error('edit_lock_schema', 503); }
            return (int) $parts[1] > time() - $window && (int) $parts[2] > 0;
        }
        return false;
    }

    public static function document_snapshot($request) {
        $auth=TKD_Cloud_Reader::permission($request);if($auth!==true){return $auth;}
        $q=$request->get_query_params();
        if(!is_array($q)||count($q)!==2||!isset($q['operation'],$q['payload'])||!TKD_Cloud_Document_Operations::supports($q['operation'])||!is_string($q['payload'])||strlen($q['payload'])>2048){return self::error('payload_schema',400);}
        $payload=json_decode($q['payload'],true);
        if(!TKD_Cloud_Document_Operations::validate($q['operation'],$payload)){return self::error('payload_schema',400);}
        $pins=TKD_Cloud_Document_Operations::pins($q['operation']);if(!$pins['verified']){return self::error($pins['reason'],503);}
        $number=$request->get_url_params()['number']??null;$minimal=TKD_Cloud_Reader::read_number($number);if($minimal instanceof WP_Error){return $minimal;}
        $id=$minimal->get_data()['id'];$state=self::state($id,$number,$q['operation'],$payload);if($state instanceof WP_Error){return $state;}
        $documents=array();foreach($state['documents'] as $document_id=>$row){$documents[]=array('id'=>$document_id,'type'=>$row['type']);}
        return new WP_REST_Response(array('documents'=>$documents,'bridge_version'=>TKD_Cloud_Reader::VERSION,'schema_version'=>'tkd-document-operation-snapshot-v1-candidate','order_id'=>$id,'revision'=>$state['revision'],'operation'=>$q['operation'],'native_edit_post'=>current_user_can('edit_post',$id),'editor_locked'=>$state['document_editor_locked'],'preview_is_acceptance'=>false));
    }

    public static function snapshot($request) {
        $auth = TKD_Cloud_Reader::permission($request); if ($auth !== true) { return $auth; }
        $pins = TKD_Cloud_Native_Contracts::verify('status.change'); if ($pins !== true) { return self::error($pins, 503); }
        $params = $request->get_url_params(); $number = $params['number'] ?? null;
        $minimal = TKD_Cloud_Reader::read_number($number); if ($minimal instanceof WP_Error) { return $minimal; }
        $base = $minimal->get_data(); $state = self::state($base['id'], $number); if ($state instanceof WP_Error) { return $state; }
        $editor = self::editor_locked($base['id']); if ($editor instanceof WP_Error) { return $editor; }
        $capabilities = self::capabilities($request)->get_data();
        return new WP_REST_Response(array('bridge_version' => TKD_Cloud_Reader::VERSION, 'schema_version' => 'tkd-operation-snapshot-v1-candidate', 'order_id' => $base['id'], 'number' => $number, 'status' => $base['status'], 'revision' => $state['revision'], 'revision_scope' => 'native-baseline-and-fixed-stored-state', 'native_edit_post' => current_user_can('edit_post', $base['id']), 'editor_locked' => $editor, 'operations' => $capabilities['operations'], 'preview_is_acceptance' => false));
    }

    private static function uuid($v) { return is_string($v) && preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $v); }
    private static function key($operation_id) { return 'tkd_cloud_operation_' . $operation_id; }
    private static function fresh_record($key) { wp_cache_delete($key, 'options'); wp_cache_delete('notoptions', 'options'); return get_option($key, false); }
    private static function claim($key, $record) {
        // Atomic INSERT IGNORE rather than add_option's possible upsert race.
        // Only this fixed WordPress journal table/key is writable, never business SQL.
        global $wpdb;
        if (!isset($wpdb->options) || !is_string($wpdb->options) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $wpdb->options)) { return false; }
        $old = $wpdb->suppress_errors(true);
        try {
            $indexes = $wpdb->get_results('SHOW INDEX FROM `' . $wpdb->options . '` WHERE Key_name = \'option_name\'', ARRAY_A);
            if (!is_array($indexes) || count($indexes) !== 1 || (int) ($indexes[0]['Non_unique'] ?? 1) !== 0 || ($indexes[0]['Column_name'] ?? '') !== 'option_name' || (int) ($indexes[0]['Seq_in_index'] ?? 0) !== 1) { return false; }
            $inserted = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO `' . $wpdb->options . '` (`option_name`,`option_value`,`autoload`) VALUES (%s,%s,%s)', $key, maybe_serialize($record), 'no'));
        } finally { $wpdb->suppress_errors($old); }
        return $inserted === 1 && self::fresh_record($key) === $record;
    }

    private static function store($key, $record) {
        update_option($key, $record, false);
        return self::fresh_record($key) === $record;
    }
    private static function valid_record($r) {
        $fields = array('operation_id', 'operation', 'actor', 'order_id', 'digest', 'intent_sha256', 'state', 'reason', 'payment_saved', 'updated_at', 'audit');
        if(is_array($r)&&isset($r['document_id'])){if(!is_int($r['document_id'])||$r['document_id']<1||!TKD_Cloud_Document_Operations::supports($r['operation']??null)){return false;}$fields[]='document_id';}
        $reasons = array('native_not_yet_verified', 'interrupted_or_in_progress_no_retry', 'native_status_verified', 'native_effect_unverified', 'native_not_accepted', 'payment_recorded_sync_unverified', 'payment_recorded_native_interrupted', 'native_interrupted', 'journal_persistence_failed', 'mobile_access', 'mobile_status', 'mobile_busy', 'mobile_stale', 'mobile_document', 'mobile_protected', 'mobile_paid', 'mobile_money', 'mobile_invoice', 'mobile_link', 'document_verified', 'document_needs_review');
        return is_array($r) && count($r) === count($fields) && !array_diff(array_keys($r), $fields) && self::uuid($r['operation_id'])
            && in_array($r['operation'], array('status.change', 'payment.add_manual', 'invoice.draft', 'delivery.draft', 'invoice.issue', 'reservation.extend', 'shipment.issue'), true) && is_int($r['actor']) && $r['actor'] > 0 && is_int($r['order_id']) && $r['order_id'] > 0
            && is_string($r['digest']) && preg_match('/\A[a-f0-9]{64}\z/D', $r['digest']) && is_string($r['intent_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $r['intent_sha256'])
            && in_array($r['state'], array('pending', 'complete', 'failed', 'unknown', 'recorded_needs_review'), true) && in_array($r['reason'], $reasons, true)
            && (is_null($r['payment_saved']) || is_bool($r['payment_saved'])) && is_int($r['updated_at']) && is_array($r['audit']) && count($r['audit']) >= 1 && count($r['audit']) <= 4;
    }

    private static function summary($record) {
        $state = $record['state'] === 'pending' ? 'unknown' : $record['state'];
        $summary = array('operation_id' => $record['operation_id'], 'intent_sha256' => $record['intent_sha256'], 'operation' => $record['operation'], 'state' => $state, 'reason' => $record['state'] === 'pending' ? 'interrupted_or_in_progress_no_retry' : $record['reason'], 'payment_saved' => $record['payment_saved'], 'reservation_sync_verified' => false, 'needs_review' => in_array($state, array('unknown', 'recorded_needs_review'), true), 'retry_native' => false);
        if(isset($record['document_id'])){$summary['document_id']=$record['document_id'];}
        return $summary;
    }

    public static function journal($request) {
        $auth = TKD_Cloud_Reader::permission($request); if ($auth !== true) { return $auth; }
        $id = $request->get_url_params()['operation_id'] ?? null;
        if (!self::uuid($id)) { return self::error('invalid_id', 400); }
        $record = self::fresh_record(self::key($id));
        if (!self::valid_record($record) || (int) $record['actor'] !== (int) get_current_user_id()) { return self::error('not_found', 404); }
        return new WP_REST_Response(self::summary($record));
    }

    private static function clean_text($v, $max = 256) { return is_string($v) && $v !== '' && strlen($v) <= $max && preg_match('//u', $v) && !preg_match('/[\x00-\x1f\x7f]/', $v) && sanitize_text_field($v) === $v && trim($v) === $v; }
    private static function cents($v) {
        if (is_int($v)) { $v = (string) $v; }
        if (is_float($v) && is_finite($v)) { $v = json_encode($v, JSON_PRESERVE_ZERO_FRACTION); }
        if (!is_string($v) || !preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D', $v)) { return null; }
        $p = explode('.', $v, 2); return (int) $p[0] * 100 + (int) str_pad($p[1] ?? '', 2, '0');
    }
    private static function money($v) { return intdiv($v, 100) . '.' . str_pad((string) ($v % 100), 2, '0', STR_PAD_LEFT); }

    private static function validate($p) {
        $keys = array('operation_id', 'operation', 'order_id', 'number', 'revision', 'payload');
        if (!is_array($p) || count($p) !== 6 || array_diff(array_keys($p), $keys) || !self::uuid($p['operation_id']) || !in_array($p['operation'], array('status.change', 'payment.add_manual', 'invoice.draft', 'delivery.draft', 'invoice.issue', 'reservation.extend', 'shipment.issue'), true) || !is_int($p['order_id']) || $p['order_id'] < 1 || !is_string($p['number']) || !preg_match('/\ATKD-[A-Za-z0-9-]{1,60}\z/D', $p['number']) || !is_string($p['revision']) || !preg_match('/\A[a-f0-9]{64}\z/D', $p['revision']) || !is_array($p['payload'])) { return false; }
        $v = $p['payload'];
        if (TKD_Cloud_Document_Operations::supports($p['operation'])) { return TKD_Cloud_Document_Operations::validate($p['operation'],$v); }
        if ($p['operation'] === 'status.change') { return count($v) === 1 && isset($v['choice']) && in_array($v['choice'], self::CHOICES, true); }
        $fields = array('amount', 'payer_name', 'recipient_key', 'channel_key', 'received_at', 'reference', 'confirm_additional');
        if (count($v) !== count($fields) || array_diff(array_keys($v), $fields) || !is_string($v['amount']) || self::cents($v['amount']) === null || self::cents($v['amount']) < 1 || !self::clean_text($v['payer_name']) || !self::clean_text($v['reference'], 128) || !is_bool($v['confirm_additional']) || !is_string($v['recipient_key']) || !in_array($v['recipient_key'], array('fop_iy', 'fop_pm', 'fop_rv', 'fop_tp', 'fop_md', 'fop_eo'), true) || !is_string($v['channel_key']) || !in_array($v['channel_key'], TKD_Cloud_Native_Contracts::channels(), true) || !is_string($v['received_at']) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $v['received_at'])) { return false; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $v['received_at'], wp_timezone());
        return $date && $date->format('Y-m-d H:i:s') === $v['received_at'] && $v['received_at'] <= current_time('mysql');
    }

    private static function canonical($v) { if (is_array($v)) { ksort($v); foreach ($v as &$child) { $child = self::canonical($child); } unset($child); } return $v; }
    private static function digest($p, $actor) { return hash_hmac('sha256', serialize(array($actor, self::canonical($p))), wp_salt('auth')); }

    private static function ledger($raw) {
        $rows = self::value($raw, '_tkd_fo_payments');
        if ($rows === '' || $rows === null) { return array(); }
        if (!is_array($rows) || count($rows) > 500) { return null; }
        $ids = array();
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id']) || $row['id'] === '' || isset($ids[$row['id']])) { return null; }
            $ids[$row['id']] = true;
            if (isset($row['amount']) && self::cents($row['amount']) === null) { return null; }
        }
        return array_values($rows);
    }

    private static function payment_preflight($p, $state) {
        $v = $p['payload']; $raw = $state['raw'];
        if (self::value($raw, '_tkd_fo_invoice_split_done') === '1') { return 'split_invoice_review_required'; }
        if (self::value($raw, '_tkd_fo_checkout_seller_key') === 'vat' || $v['recipient_key'] === 'ti_group') { return 'tov_context_review_required'; }
        if (self::value($raw, '_tkd_svitloe_wfp_binding_v1') !== '') { return 'bank_attestation_required'; }
        if (($v['recipient_key'] === 'fop_eo') !== ($v['channel_key'] === 'bank_emeo')) { return 'seller_channel'; }
        if ($v['channel_key'] === 'bank_emeo' && $v['reference'] !== $p['number']) { return 'emeo_reference'; }
        $confirmed = self::value($raw, '_tkd_confirmed_amount'); $client = self::value($raw, '_tkd_client_total');
        $confirmed = $confirmed === '' || $confirmed === null ? 0 : self::cents($confirmed);
        $client = $client === '' || $client === null ? 0 : self::cents($client);
        if ($confirmed === null || $client === null) { return 'money_schema'; }
        $total = $confirmed > 0 ? $confirmed : $client;
        if ($total <= 0) { return 'total_required'; }
        $rows = self::ledger($raw); if ($rows === null) { return 'ledger_schema'; }
        $paid = 0;
        foreach ($rows as $row) {
            if (!isset($row['amount'])) { return 'ledger_schema'; }
            $amount = self::cents($row['amount']);
            if (($row['status'] ?? '') !== 'void') { $paid += $amount; }
            $same_amount = $amount === self::cents($v['amount']);
            $same_date = ($row['received_at'] ?? '') === $v['received_at'];
            $same_reference = isset($row['reference']) && $row['reference'] !== '' && $row['reference'] === $v['reference'];
            $same_transaction = isset($row['bank_transaction_id']) && is_string($row['bank_transaction_id']) && $row['bank_transaction_id'] !== '' && $row['bank_transaction_id'] === $v['reference'];
            if ($same_transaction || ($same_amount && $same_date) || ($same_reference && ($same_amount || $same_date))) { return 'possible_duplicate'; }
        }
        if (!$rows) {
            $legacy = self::value($raw, '_tkd_prepayment_received'); $paid = $legacy === '' || $legacy === null ? 0 : self::cents($legacy);
            if ($paid === null) { return 'money_schema'; }
            if ($paid > 0 && (!$v['confirm_additional'] || $paid === self::cents($v['amount']))) { return 'legacy_payment_ambiguous'; }
        }
        if ($paid + self::cents($v['amount']) > $total) { return 'overpayment'; }
        return true;
    }

    private static function payment_saved($p, $before, $after) {
        $rows = self::ledger($after['raw']); if ($rows === null) { return false; }
        $token = 'tkd-cloud-' . $p['operation_id']; $matches = array();
        foreach ($rows as $row) { if ($row['id'] === $token) { $matches[] = $row; } }
        if (count($matches) !== 1) { return false; }
        $row = $matches[0]; $v = $p['payload'];
        foreach (array('payer_name', 'recipient_key', 'channel_key', 'received_at', 'reference') as $k) { if (($row[$k] ?? null) !== $v[$k]) { return false; } }
        if (self::cents($row['amount'] ?? null) !== self::cents($v['amount']) || ($row['source'] ?? '') !== 'manual' || (int) ($row['user_id'] ?? 0) !== (int) get_current_user_id() || ($row['status'] ?? '') !== 'active' || self::cents($row['fee'] ?? null) !== 0 || self::cents($row['net_amount'] ?? null) !== ($v['channel_key'] === 'bank_emeo' ? self::cents($v['amount']) : 0)) { return false; }
        foreach (self::ledger($before['raw']) as $previous) {
            $found = array_filter($rows, function($r) use ($previous) { return $r['id'] === $previous['id'] && $r === $previous; });
            if (count($found) !== 1) { return false; }
        }
        return true;
    }

    private static function invoke_payment($p) {
        $v = $p['payload']; $id = $p['order_id'];
        $posted = array('tkd_fo_payment_nonce' => wp_create_nonce('tkd_fo_save_payment_' . $id), 'tkd_fo_payment_amount' => $v['amount'], 'tkd_fo_payment_payer_name' => $v['payer_name'], 'tkd_fo_payment_recipient' => $v['recipient_key'], 'tkd_fo_payment_channel' => $v['channel_key'], 'tkd_fo_payment_date' => $v['received_at'], 'tkd_fo_payment_reference' => $v['reference'], 'tkd_fo_payment_note' => '', 'tkd_fo_payment_token' => 'tkd-cloud-' . $p['operation_id'], 'tkd_fo_payment_operation' => 'new', 'tkd_fo_confirm_additional_payment' => $v['confirm_additional'] ? '1' : '0', 'tkd_fo_payment_fee' => '0.00', 'tkd_fo_payment_net' => '0.00');
        $old = $_POST;
        try { $_POST = wp_slash($posted); TKD_FO_Payments::save_new_payment($id, get_post($id), true); }
        finally { $_POST = $old; }
    }

    private static function finish($key, $record, $state, $reason, $saved = null) {
        $record['state'] = $state; $record['reason'] = $reason; $record['payment_saved'] = $saved; $record['updated_at'] = time();
        $record['audit'][] = array('actor' => $record['actor'], 'order_id' => $record['order_id'], 'at' => $record['updated_at'], 'result' => $state, 'reason' => $reason);
        if (!self::store($key, $record)) { $record['state'] = 'unknown'; $record['reason'] = 'journal_persistence_failed'; $record['payment_saved'] = null; }
        return new WP_REST_Response(self::summary($record));
    }

    public static function execute($request) {
        $auth = self::permission($request); if ($auth !== true) { return $auth; }
        if (strlen($request->get_body()) > 8192 || $request->get_query_params()) { return self::error('payload_schema', 400); }
        $p = $request->get_json_params();
        if (!self::validate($p)) { return self::error('payload_schema', 400); }
        $document = TKD_Cloud_Document_Operations::supports($p['operation']);
        $cap = $document ? TKD_Cloud_Document_Operations::CAPS[$p['operation']] : ($p['operation'] === 'status.change' ? self::STATUS_CAP : self::PAYMENT_CAP);
        if (!self::cap($cap) || !current_user_can('edit_post', $p['order_id'])) { return self::error('forbidden', 403); }
        $pins = $document ? TKD_Cloud_Document_Operations::pins($p['operation']) : TKD_Cloud_Native_Contracts::verify($p['operation']); if ($document) { $pins = $pins['verified'] ? true : $pins['reason']; } if ($pins !== true) { return self::error($pins, 503); }
        try { $locked = TKD_FO_Orders::acquire_reservation_mutex($p['order_id']); } catch (Throwable $error) { return self::error('lock_unavailable', 503); }
        if (!$locked) { return self::error('busy', 423); }
        $key = self::key($p['operation_id']); $actor = (int) get_current_user_id(); $digest = self::digest($p, $actor); $record = null;
        try {
            $existing = self::fresh_record($key);
            if ($existing !== false) {
                if (!self::valid_record($existing) || ($existing['actor'] ?? null) !== $actor || ($existing['order_id'] ?? null) !== $p['order_id'] || !is_string($existing['digest'] ?? null) || !hash_equals($existing['digest'], $digest)) { return self::error('idempotency_mismatch'); }
                if ($existing['state'] === 'pending') { return self::finish($key, $existing, 'unknown', 'interrupted_or_in_progress_no_retry'); }
                return new WP_REST_Response(self::summary($existing));
            }
            $before = self::state($p['order_id'], $p['number'], $p['operation'], $p['payload']); if ($before instanceof WP_Error) { return $before; }
            if (!hash_equals($before['revision'], $p['revision'])) { return self::error('stale_revision'); }
            $editor = self::editor_locked($p['order_id']); if ($editor instanceof WP_Error) { return $editor; }
            if ($editor) { return self::error('editor_locked', 423); }
            if (self::value($before['raw'], '_tkd_fo_invoice_split_done') === '1') { return self::error('split_invoice_review_required'); }
            if ($document) { $preflight = TKD_Cloud_Document_Operations::preflight($p,$before); if($preflight!==true){return self::error($preflight);} }
            if ($p['operation'] === 'payment.add_manual') {
                $preflight = self::payment_preflight($p, $before); if ($preflight !== true) { return self::error($preflight); }
            }
            // Recheck disk fingerprints immediately before durable claim/native invocation.
            $pins = $document ? TKD_Cloud_Document_Operations::pins($p['operation']) : TKD_Cloud_Native_Contracts::verify($p['operation']); if ($document) { $pins = $pins['verified'] ? true : $pins['reason']; } if ($pins !== true) { return self::error($pins, 503); }
            $candidate = array('operation_id' => $p['operation_id'], 'operation' => $p['operation'], 'actor' => $actor, 'order_id' => $p['order_id'], 'digest' => $digest, 'intent_sha256' => hash('sha256', json_encode(self::canonical($p), JSON_UNESCAPED_SLASHES)), 'state' => 'pending', 'reason' => 'native_not_yet_verified', 'payment_saved' => null, 'updated_at' => time(), 'audit' => array(array('actor' => $actor, 'order_id' => $p['order_id'], 'at' => time(), 'result' => 'pending', 'reason' => 'claimed_before_native')));
            if (!self::claim($key, $candidate)) { return self::error('claim_failed'); }
            $record = $candidate;
            try {
                if ($document) { $out = TKD_Cloud_Document_Operations::invoke($p,$before); if(isset($out[2])&&is_int($out[2])&&$out[2]>0){$record['document_id']=$out[2];} return self::finish($key,$record,$out[0],$out[1]); }
                if ($p['operation'] === 'status.change') {
                    $result = TKD_FO_Mobile_Cabinet_20261011::change_status($p['order_id'], $p['payload']['choice'], $before['baseline']);
                    $after = self::state($p['order_id'], $p['number'], $p['operation'], $p['payload']);
                    if ($result instanceof WP_Error) {
                        $code = $result->get_error_code();
                        $safe = in_array($code, array('mobile_access', 'mobile_status', 'mobile_busy', 'mobile_stale', 'mobile_document', 'mobile_protected', 'mobile_paid', 'mobile_money', 'mobile_invoice', 'mobile_link'), true);
                        return self::finish($key, $record, $safe ? 'failed' : 'unknown', $safe ? $code : 'native_effect_unverified');
                    }
                    $choice = $p['payload']['choice']; $expected = strpos($choice, 'cancelled_') === 0 ? 'cancelled' : $choice;
                    $reason = strpos($choice, 'cancelled_') === 0 ? substr($choice, 10) : '';
                    $verified = is_string($result) && !($after instanceof WP_Error) && $after['base']['status'] === $expected && self::value($after['raw'], '_tkd_fo_cancel_reason') === $reason;
                    return self::finish($key, $record, $verified ? 'complete' : 'unknown', $verified ? 'native_status_verified' : 'native_effect_unverified');
                }
                self::invoke_payment($p);
                $after = self::state($p['order_id'], $p['number'], $p['operation'], $p['payload']);
                $saved = !($after instanceof WP_Error) && self::payment_saved($p, $before, $after);
                if ($saved) { return self::finish($key, $record, 'recorded_needs_review', 'payment_recorded_sync_unverified', true); }
                $unchanged = !($after instanceof WP_Error) && hash_equals($before['revision'], $after['revision']);
                return self::finish($key, $record, $unchanged ? 'failed' : 'unknown', $unchanged ? 'native_not_accepted' : 'native_effect_unverified', $unchanged ? false : null);
            } catch (Throwable $error) {
                if ($p['operation'] === 'payment.add_manual') {
                    try {
                        $after = self::state($p['order_id'], $p['number'], $p['operation'], $p['payload']);
                        if (!($after instanceof WP_Error) && self::payment_saved($p, $before, $after)) { return self::finish($key, $record, 'recorded_needs_review', 'payment_recorded_native_interrupted', true); }
                    } catch (Throwable $ignored) { /* Unknown outcome must never retry native. */ }
                }
                return self::finish($key, $record, 'unknown', 'native_interrupted');
            }
        } catch (Throwable $error) {
            return $record === null ? self::error('state_unavailable', 503) : self::finish($key, $record, 'unknown', 'native_interrupted');
        } finally {
            try { TKD_FO_Orders::release_reservation_mutex($p['order_id']); } catch (Throwable $ignored) { /* Connection-scoped lock; no credentials/stack logging. */ }
        }
    }
}
