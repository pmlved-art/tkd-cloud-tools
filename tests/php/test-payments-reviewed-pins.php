<?php
if(PHP_SAPI!=='cli'){exit;}define('ABSPATH',__DIR__);
$count=0;function payment_pin_check($v){global $count;if(!$v)throw new RuntimeException('Payments review assertion '.($count+1));$count++;}
function wp_timezone(){return new DateTimeZone('UTC');}function current_time($kind){return '2026-11-17 23:59:59';}function sanitize_text_field($v){return $v;}
require __DIR__.'/../../wordpress/tkd-cloud-reader/native-contracts.php';
require __DIR__.'/../../wordpress/tkd-cloud-reader/document-operations.php';
require __DIR__.'/../../wordpress/tkd-cloud-reader/operations.php';
require __DIR__.'/../../wordpress/tkd-cloud-reader/operator-role.php';
$e=json_decode(file_get_contents(__DIR__.'/../../evidence/payments-source-review-20261117.json'),true);
$method=new ReflectionMethod('TKD_Cloud_Native_Contracts','profile');$method->setAccessible(true);$profile=$method->invoke(null);$payment=$profile['classes']['TKD_FO_Payments'];
payment_pin_check($payment['file']===$e['after_sha256']&&$payment['file']!==$e['before_sha256']);
$expected=array();foreach($e['pinned_methods_unchanged']as$m){$expected[$m['name']]=$m['sha256'];}
$actual=$payment['methods']+$payment['private_methods'];ksort($actual);ksort($expected);payment_pin_check(count($expected)===17&&$actual===$expected);
foreach($e['pinned_methods_unchanged']as$m){payment_pin_check(($actual[$m['name']]??null)===$m['sha256']);}
payment_pin_check(!isset($actual['reconcile_svitloe_refund']));
$validate=new ReflectionMethod('TKD_Cloud_Operations','validate');$validate->setAccessible(true);
$payloads=array('status.change'=>array('choice'=>'availability_callback'),'payment.add_manual'=>array('amount'=>'1.00','payer_name'=>'SYNTHETIC','recipient_key'=>'fop_pm','channel_key'=>'privatbank','received_at'=>'2026-11-17 10:00:00','reference'=>'SYNTHETIC','confirm_additional'=>false),'invoice.draft'=>array(),'invoice.issue'=>array('invoice_id'=>20,'number'=>'SYNTHETIC','date'=>'2026-11-17'),'reservation.extend'=>array('hours'=>48),'delivery.draft'=>array('invoice_id'=>20),'delivery.save'=>array('delivery_id'=>30,'items'=>array(array('source_index'=>0,'quantity'=>'1.000','serial_numbers'=>'SYNTHETIC'))),'shipment.issue'=>array('delivery_id'=>30));
$p=array('operation_id'=>'11111111-1111-4111-8111-111111111111','operation'=>'','order_id'=>12,'number'=>'TKD-SYNTHETIC','revision'=>str_repeat('a',64),'payload'=>array());
foreach($payloads as$operation=>$payload){$p['operation']=$operation;$p['payload']=$payload;payment_pin_check($validate->invoke(null,$p)===true);}
foreach(array('reconcile_svitloe_refund','payment.refund','payment.reconcile_svitloe_refund','TKD_FO_Payments::reconcile_svitloe_refund')as$operation){$p['operation']=$operation;$p['payload']=array();payment_pin_check($validate->invoke(null,$p)===false);payment_pin_check(!TKD_Cloud_Document_Operations::supports($operation));}
$expected_caps=array('read','tkd_cloud_read_orders','tkd_cloud_change_status','tkd_cloud_add_manual_payment','manage_tkd_felicity','tkd_cloud_delivery_draft','tkd_cloud_invoice','tkd_cloud_extend_reservation','tkd_cloud_issue_delivery');$caps=array_keys(TKD_Cloud_Operator_Role::caps());sort($caps);sort($expected_caps);payment_pin_check($caps===$expected_caps);payment_pin_check(!isset(TKD_Cloud_Operator_Role::caps()['manage_options']));
echo "PASS $count Payments evidence/pin/command/role assertions; no native execution or live writes\n";
