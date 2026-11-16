<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/test-read-extensions.php';
$ids=array(12);$numbers=array('TKD-100');$statuses=array('new');
$post->post_type='tkd_order';$post->post_status='publish';$post->post_modified_gmt='2026-11-17 10:00:00';
$r->number='TKD-100';$r->route='/tkd-cloud/v1/orders/TKD-100/details';$r->method='GET';$extra_meta=array();
function expect_diagnostic($field,$reason,$type) {
 global $r;
 $error=TKD_Cloud_Read_Extensions::details($r);
 check($error instanceof WP_Error,'diagnostic preserves refusal');
 $d=$error->data['schema_diagnostic'];
 check($d===array('field'=>$field,'reason'=>$reason,'type'=>$type,'present'=>true),'exact enum-only schema diagnostic');
 check(array_keys($error->data)===array('status','schema_diagnostic'),'no extra error data');
}
foreach(array('_tkd_client_total','_tkd_confirmed_amount','_tkd_prepayment_received') as $key) {
 $extra_meta=array($key=>array(''));expect_diagnostic($key,'invalid_decimal','string');
}
foreach(array('_tkd_fo_order_items','_tkd_fo_payments') as $key) {
 $extra_meta=array($key=>array(''));expect_diagnostic($key,'expected_list','string');
}
$extra_meta=array('_tkd_fo_reservation_until'=>array(1234567890));expect_diagnostic('_tkd_fo_reservation_until','invalid_text','integer');
$extra_meta=array('_tkd_paid_at'=>array(false));expect_diagnostic('_tkd_paid_at','invalid_text','boolean');
$extra_meta=array('_tkd_fo_order_items'=>array(array(array('quantity'=>'1.1234'))));expect_diagnostic('_tkd_fo_order_items.quantity','invalid_decimal','string');
$extra_meta=array('_tkd_fo_order_items'=>array(array(array('unit_price'=>array('sensitive-key'=>'DO_NOT_EMIT')))));expect_diagnostic('_tkd_fo_order_items.unit_price','invalid_decimal','array');
$extra_meta=array('_tkd_fo_payments'=>array(array(array('received_at'=>17))));expect_diagnostic('_tkd_fo_payments.received_at','invalid_text','integer');
$extra_meta=array('_tkd_fo_payments'=>array(array(array('id'=>'DO_NOT_EMIT'),array('id'=>'DO_NOT_EMIT'))));expect_diagnostic('_tkd_fo_payments.id','duplicate_id','string');
$extra_meta=array('_tkd_customer_name'=>array('DO_NOT_EMIT','DO_NOT_EMIT'));
$e=TKD_Cloud_Read_Extensions::details($r);check(error_status($e,409) && strpos(json_encode($e->data),'DO_NOT_EMIT')===false,'duplicate meta diagnostic no values');
$extra_meta=array('_tkd_fo_order_items'=>array(array(array('quantity'=>1.125,'unit_price'=>'1.20'))),'_tkd_client_total'=>array('0.00'),'_tkd_customer_name'=>array(''));
$success=TKD_Cloud_Read_Extensions::details($r);
check($success instanceof WP_REST_Response && $success->data['metadata']['_tkd_client_total']['value']==='0.00','valid fractional quantity/zero unchanged');
check($success->data['metadata']['_tkd_customer_name']['value']==='' && $success->data['metadata']['_tkd_fo_payments']['present']===false,'empty text and missing arrays unchanged');
check(!isset($success->data['schema_diagnostic']),'no diagnostic on success');
$uid=8;check(error_status(TKD_Cloud_Reader::permission($r),401),'diagnostics remain behind unchanged auth');$uid=7;
$extra_meta=array('_tkd_client_total'=>array(''));
$e=TKD_Cloud_Read_Extensions::details($r);$response=TKD_Cloud_Reader::no_store(new WP_REST_Response($e->data),null,$r);
check(strpos($response->headers['Cache-Control'],'no-store')!==false,'diagnostic errors still no-store');
echo "PASS $count total synthetic assertions including schema diagnostics; not live evidence\n";
