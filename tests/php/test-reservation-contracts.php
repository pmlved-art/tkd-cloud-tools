<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
require __DIR__.'/../../wordpress/tkd-cloud-reader/reservation-contracts.php';
$n=0;function reservation_check($v){global $n;if(!$v)throw new RuntimeException('synthetic reservation contract failed');$n++;}
$s=array('stock_state'=>'reserved','status'=>'invoice_pending','post_status'=>'publish','unpaid_verified'=>true,'confirmed'=>false,'protected_stock'=>false,'editor_locked'=>false,'current_until'=>5000);
foreach(array(36,48,72) as $hours){reservation_check(TKD_Cloud_Reservation_Contracts::preflight($hours,$s,4000,1000)===array('hours'=>$hours,'stage'=>'extended','until'=>4000));}
foreach(array('36',4,24,73,null) as $hours){reservation_check(TKD_Cloud_Reservation_Contracts::preflight($hours,$s,4000,1000)==='payload_schema');}
foreach(array('paid','shipped','cancelled','deferred_payment','returned') as $status){$x=$s;$x['status']=$status;reservation_check(TKD_Cloud_Reservation_Contracts::preflight(36,$x,4000,1000)==='closed_or_paid');}
foreach(array(array('unpaid_verified',false,'payment_or_confirmation'),array('confirmed',true,'payment_or_confirmation'),array('protected_stock',true,'protected_stock'),array('editor_locked',true,'editor_locked'),array('stock_state','none','no_active_reserve'),array('current_until',1000,'reserve_expired'),array('current_until','5000','state_unverified')) as $f){$x=$s;$x[$f[0]]=$f[1];reservation_check(TKD_Cloud_Reservation_Contracts::preflight(36,$x,4000,1000)===$f[2]);}
foreach(array(0,1000,'4000',null) as $deadline){reservation_check(TKD_Cloud_Reservation_Contracts::preflight(36,$s,$deadline,1000)==='total_deadline_expired_or_missing_start');}
echo "PASS $n pure reservation preflight assertions; no native/write invocation\n";
