<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH',__DIR__);
$actor='ui';$locks=array();$orders=array();$events=array();$hooks=array();$pins=true;$cap=true;$nonce=true;$link=12;$change_link=false;
class TKD_Cloud_Native_Contracts {public static function report($op){return array('verified'=>$GLOBALS['pins']);}}
class TKD_FO_Orders {
 public static function acquire_reservation_mutex($id){global $actor,$orders,$events;$events[]='order_acquire';if(isset($orders[$id])&&$orders[$id]!==$actor)return false;$orders[$id]=$actor;return true;}
 public static function release_reservation_mutex($id){global $actor,$orders,$events;$events[]='order_release';if(($orders[$id]??null)===$actor)unset($orders[$id]);}
}
class LockDB {
 public $prefix='wp_';public function prepare($sql,$key){return array($sql,$key);}
 public function get_var($query){global $locks,$actor,$events,$change_link,$link;list($sql,$key)=$query;
  if(strpos($sql,'GET_LOCK')!==false){$events[]='number_acquire';if(isset($locks[$key])&&$locks[$key]!==$actor)return '0';$locks[$key]=$actor;if($change_link)$link=99;return '1';}
  $events[]='number_release';if(($locks[$key]??null)===$actor){unset($locks[$key]);return '1';}return '0';
 }
}
$wpdb=new LockDB();
function add_action($name,$fn,$priority=10){$GLOBALS['hooks'][$name]=array($fn,$priority);}
function current_user_can($name){return $GLOBALS['cap'];}
function check_admin_referer($action){if(!$GLOBALS['nonce'])throw new RuntimeException('nonce');}
function wp_die(...$args){throw new RuntimeException('native_ui_refused');}
function get_post_type($id){return $id===20?'tkd_invoice':'tkd_order';}
function get_post_meta($id,$key,$single=true){return $key==='_tkd_fo_order_id'?$GLOBALS['link']:'fop_pm';}
function wp_date($format){return '2026';}
function sanitize_key($s){return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));}
function wp_cache_delete($key,$group){$GLOBALS['deleted'][]=array($key,$group);unset($GLOBALS['option_cache'][$key]);}
require __DIR__.'/../../wordpress/tkd-cloud-reader/delivery-creation-lock.php';
$checks=0;function lock_check($ok){global $checks;if(!$ok)throw new RuntimeException('FAIL lock '.($checks+1));$checks++;}
TKD_Cloud_Delivery_Creation_Lock::boot();lock_check(count($hooks)===2&&$hooks['admin_post_tkd_fo_create_delivery'][1]===0);
$_GET=array('invoice_id'=>'20');
TKD_Cloud_Delivery_Creation_Lock::before_native();lock_check($events[0]==='order_acquire'&&$events[1]==='number_acquire');lock_check(count($locks)===1&&$orders[12]==='ui');
$actor='cloud';$failed=false;try{TKD_Cloud_Delivery_Creation_Lock::acquire();}catch(RuntimeException $e){$failed=true;}lock_check($failed&&count($locks)===1);
lock_check(TKD_FO_Orders::acquire_reservation_mutex(12)===false);
$actor='ui';TKD_Cloud_Delivery_Creation_Lock::release_native();lock_check(!$locks&&!$orders);$n=count($events);TKD_Cloud_Delivery_Creation_Lock::release_native();lock_check(count($events)===$n);
$actor='cloud';$key=TKD_Cloud_Delivery_Creation_Lock::acquire();$actor='ui';$failed=false;try{TKD_Cloud_Delivery_Creation_Lock::before_native();}catch(RuntimeException $e){$failed=true;}lock_check($failed&&!$orders&&$locks[$key]==='cloud');
$actor='cloud';TKD_Cloud_Delivery_Creation_Lock::release($key);lock_check(!$locks);
$actor='ui';$change_link=true;$failed=false;try{TKD_Cloud_Delivery_Creation_Lock::before_native();}catch(RuntimeException $e){$failed=true;}lock_check($failed&&!$locks&&!$orders);$change_link=false;$link=12;
$nonce=false;$n=count($events);try{TKD_Cloud_Delivery_Creation_Lock::before_native();}catch(RuntimeException $e){}lock_check(count($events)===$n&&!$locks);$nonce=true;
$cap=false;try{TKD_Cloud_Delivery_Creation_Lock::before_native();}catch(RuntimeException $e){}lock_check(count($events)===$n);$cap=true;
$pins=false;TKD_Cloud_Delivery_Creation_Lock::before_native();lock_check(count($events)===$n);$pins=true;
$option_cache=array('alloptions'=>array('stale'=>true),'notoptions'=>array('stale'=>true),'tkd_fo_delivery_sequence_fop_pm_2026'=>5);$database_sequence=6;
TKD_Cloud_Delivery_Creation_Lock::before_native();$native_next=($option_cache['tkd_fo_delivery_sequence_fop_pm_2026']??$database_sequence)+1;lock_check($native_next===7&&!$option_cache);
TKD_Cloud_Delivery_Creation_Lock::release_native();$actor='cloud';$key=TKD_Cloud_Delivery_Creation_Lock::acquire();$database_sequence=7;$option_cache['tkd_fo_delivery_sequence_fop_pm_2026']=5;TKD_Cloud_Delivery_Creation_Lock::fresh_sequence('fop_pm');$cloud_next=($option_cache['tkd_fo_delivery_sequence_fop_pm_2026']??$database_sequence)+1;lock_check($cloud_next===8);TKD_Cloud_Delivery_Creation_Lock::release($key);
lock_check(!$locks&&!$orders);
echo 'PASS '.$checks.' shared creation-lock interleaving/cache/cleanup assertions; synthetic lock service, no live writes'.PHP_EOL;
