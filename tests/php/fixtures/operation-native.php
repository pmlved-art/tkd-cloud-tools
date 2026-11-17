<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Isolated synthetic services, never connected to WordPress or production.
class TKD_FO_Orders {
    public static function acquire_reservation_mutex($id) {
        $GLOBALS['lock_calls']++;
        if ($GLOBALS['busy']) return false;
        $GLOBALS['depth'][$id]=($GLOBALS['depth'][$id]??0)+1; return true;
    }
    public static function release_reservation_mutex($id) { $GLOBALS['depth'][$id]--; }
    public static function reservation_total_deadline($id,$hours){return 2000000000+$hours*3600;}
    public static function claim_responsibility($id,$reason){if(!empty($GLOBALS['simulate_owner_change'])){$GLOBALS['options']['tkd_fo_order_responsibility_'.$id]=array('user_id'=>999);}return array();}
    public static function get_responsibility($id) { return array(); }
    private static function reservation_manager_is_manual($id,$manager) { return true; }
}
class TKD_FO_Mobile_Cabinet_20261011 {
    public static function status_baseline($id) { return hash_hmac('sha256',serialize($GLOBALS['meta'][$id]),wp_salt('nonce')); }
    public static function change_status($id,$choice,$baseline) {
        $GLOBALS['native_status_calls']++;
        if (count(array_filter($GLOBALS['options'], function($r) use($id) { return ($r['state']??'')==='pending' && ($r['order_id']??0)===$id; }))!==1) throw new Exception('Native before durable pending');
        if (($GLOBALS['depth'][$id]??0)!==1) throw new Exception('Missing outer shared lock');
        if (!TKD_FO_Orders::acquire_reservation_mutex($id)) return new WP_Error('mobile_busy','synthetic');
        try {
            if ($GLOBALS['status_mode']==='error') return new WP_Error('mobile_invoice','SENSITIVE_NATIVE_MESSAGE');
            if ($GLOBALS['status_mode']==='throw') throw new Exception('SENSITIVE_STACK_DETAIL');
            if (!hash_equals(self::status_baseline($id),$baseline)) return new WP_Error('mobile_stale','synthetic');
            $GLOBALS['business_writes']++;
            $GLOBALS['meta'][$id]['_tkd_status']=array(strpos($choice,'cancelled_')===0?'cancelled':$choice);
            $GLOBALS['meta'][$id]['_tkd_fo_cancel_reason']=array(strpos($choice,'cancelled_')===0?substr($choice,10):'');
            if ($GLOBALS['status_mode']==='partial') return new WP_Error('mobile_stock','SENSITIVE_NATIVE_MESSAGE');
            return 'SENSITIVE_NATIVE_MESSAGE';
        } finally { TKD_FO_Orders::release_reservation_mutex($id); }
    }
}
class TKD_FO_Payments {
    public static function save_new_payment($id,$post,$update) {
        $GLOBALS['native_payment_calls']++;
        if (count(array_filter($GLOBALS['options'], function($r) use($id) { return ($r['state']??'')==='pending' && ($r['order_id']??0)===$id; }))!==1) throw new Exception('Native before durable pending');
        if (($GLOBALS['depth'][$id]??0)!==1 || !$update || !wp_verify_nonce($_POST['tkd_fo_payment_nonce']??'', 'tkd_fo_save_payment_'.$id)) throw new Exception('Invalid native invocation');
        if (isset($_POST['source']) || isset($_POST['unrelated']) || isset($_POST['tkd_fo_payment_pumb_iban_confirm'])) throw new Exception('POST escaped allowlist');
        if ($GLOBALS['payment_mode']==='reject') return;
        if ($GLOBALS['payment_mode']==='throw') throw new Exception('SENSITIVE_NATIVE_MESSAGE');
        $v=wp_unslash($_POST);$amount=$v['tkd_fo_payment_amount'];
        $rows=$GLOBALS['meta'][$id]['_tkd_fo_payments'][0]??array();
        $row=array('id'=>$v['tkd_fo_payment_token'],'amount'=>$amount,'payer_name'=>$v['tkd_fo_payment_payer_name'],'recipient_key'=>$v['tkd_fo_payment_recipient'],'channel_key'=>$v['tkd_fo_payment_channel'],'received_at'=>$v['tkd_fo_payment_date'],'reference'=>$v['tkd_fo_payment_reference'],'source'=>'manual','status'=>'active','user_id'=>get_current_user_id(),'fee'=>'0.00','net_amount'=>$v['tkd_fo_payment_channel']==='bank_emeo'?$amount:'0.00');
        if ($GLOBALS['payment_mode']==='wrong') $row['source']='wayforpay';
        $rows[]=$row;$GLOBALS['meta'][$id]['_tkd_fo_payments']=array($rows);$GLOBALS['business_writes']++;
        if ($GLOBALS['payment_mode']==='throw_after') throw new Exception('SENSITIVE_NATIVE_MESSAGE');
    }
    public static function get_payments($id) { return $GLOBALS['meta'][$id]['_tkd_fo_payments'][0]??array(); }
    public static function order_total($id) { return 100; }
    public static function paid_total($id) { return 0; }
    private static function sync_order_after_payment($id) {}
}
class TKD_FO_Order_Lifecycle { public static function installment($id){return $GLOBALS['synthetic_installment']??false;} public static function unpaid($id){return true;} public static function protected_stock($id){return false;} public static function confirmed($id){return false;} }
class TKD_FO_DB { const DB_VERSION='4'; }
class TKD_FO_Invoices {
    public static function ensure_manager_draft($id){return 20;}
    public static function save_invoice($id,$post,$update){
        $GLOBALS['invoice_native_calls']=($GLOBALS['invoice_native_calls']??0)+1;
        if(empty($GLOBALS['options'])||!$update||!wp_verify_nonce(wp_unslash($_POST['tkd_fo_invoice_nonce']??''),'tkd_fo_save_invoice'))throw new Exception('missing pending or nonce');
        if(isset($_POST['unrelated']))throw new Exception('unexpected POST');
        if(!empty($GLOBALS['invoice_native_die'])){($GLOBALS['test_filters']['wp_die_handler'])()();}
        update_post_meta($id,'_tkd_fo_invoice_date',wp_unslash($_POST['tkd_fo_invoice_date']));update_post_meta($id,'_tkd_fo_invoice_status','issued');update_post_meta($id,'_tkd_fo_invoice_number',wp_unslash($_POST['tkd_fo_invoice_number']));
    }
}
class TKD_Sales_Release {
    public static function manager_snapshot($id){$GLOBALS['sales_snapshots']=($GLOBALS['sales_snapshots']??0)+1;return array('id'=>$id,'order'=>12,'invoice'=>20,'mode'=>'np','status'=>'draft','revision'=>str_repeat('a',64),'document_basis'=>str_repeat('b',64),'office_revision'=>str_repeat('c',64),'blockers'=>array(),'fiscal'=>array('state'=>'not_required'));}
    public static function execute_inline($id,$revision,$command){$GLOBALS['sales_calls']=($GLOBALS['sales_calls']??0)+1;return array('posted'=>true,'prepared'=>true,'message'=>'DO_NOT_PRINT');}
}
class TKD_FO_Deliveries {}
