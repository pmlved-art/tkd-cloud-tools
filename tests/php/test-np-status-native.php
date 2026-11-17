<?php
if(PHP_SAPI!=='cli'){exit;} define('ABSPATH',__DIR__);
$count=0;function np_check($v){global $count;if(!$v)throw new RuntimeException('NP assertion '.($count+1));$count++;}
class WP_Error{public function get_error_message(){return 'SYNTHETIC_BLOCKER';}}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_post_meta($id,$key,$single=true){return $key==='_tkd_fo_delivery_status'?$GLOBALS['s']['document_status']:$GLOBALS['s']['intent'];}
function get_option($key,$default=null){return $GLOBALS['s']['claim']??$default;}
trait NP_Status_Fixture_Helpers{
 private static function id($id){return is_int($id)&&$id>0;}
 private static function fresh($id){$GLOBALS['events'][]='fresh';$GLOBALS['fresh_calls']++;$GLOBALS['cached']=$GLOBALS['s']['record'];}
 private static function can($id){$GLOBALS['events'][]='can';return self::id($id)&&$GLOBALS['s']['can'];}
 private static function url($id){return 'SYNTHETIC';}
 private static function record($id){return $GLOBALS['cached'];}
 private static function context($id,$ready){return $GLOBALS['s'][$ready?'ready':'context'];}
 private static function revision(...$a){return str_repeat('a',64);}
 private static function reconciliation_record_valid(...$a){return $GLOBALS['s']['reconciliation_valid'];}
 private static function task($id){return array();}
 private static function unique_order($c){return $GLOBALS['s']['unique'];}
 private static function prepost_claim_guard(...$a){return $GLOBALS['s']['admission'];}
 private static function evidence($v,...$a){return is_array($v)?$v:new WP_Error();}
 private static function fingerprint($c){return 'SYNTHETIC_FP';}
 private static function owns_claim($c){return $GLOBALS['s']['owns'];}
 private static function utc($v){return (int)$v;}
 private static function profile_enabled($b){return $GLOBALS['s']['profile'];}
 private static function claim_key($v){return 'SYNTHETIC';}
 private static function option_fresh($v){}
}
require __DIR__.'/fixtures/np-status-native.php';
$e=json_decode(file_get_contents(__DIR__.'/../../evidence/np-status-upload-20261117.json'),true);
$old=json_decode(file_get_contents(__DIR__.'/../../evidence/document-dependencies-20261117.json'),true);foreach($old['methods']as$m){if($m['method']==='TKD_NP_Dispatch::status')$prior=$m;}
foreach(array_merge($e['methods'],array(array_merge($prior,array('method'=>'Synthetic_NP_Status_Old::status'))))as$m){list($c,$name)=explode('::',$m['method']);$r=new ReflectionMethod($c,$name);$lines=file($r->getFileName());np_check(hash('sha256',implode('',array_slice($lines,$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1)))===$m['source_sha256']);}
function np_base(){return array('can'=>true,'record'=>array('version'=>1,'mode'=>'np','state'=>'preparing','order'=>12),'context'=>array('order'=>12,'binding'=>array('ttn'=>'SYNTHETIC')),'ready'=>array(),'document_status'=>'issued','intent'=>array('state'=>'committed','notification'=>'completed'),'reconciliation_valid'=>true,'unique'=>true,'admission'=>true,'owns'=>true,'profile'=>true);}
$cases=array('preparing'=>array(np_base(),false),'release'=>array(np_base(),true),'invalid_id'=>array(np_base(),false,0));
foreach(array('permission','missing','corrupt','context','profile','admission','probe','rejected')as$name){$s=np_base();if($name==='permission')$s['can']=false;if($name==='missing')$s['record']=array();if($name==='corrupt')$s['record']['version']=2;if($name==='context')$s['context']=new WP_Error();if($name==='profile')$s['profile']=false;if($name==='admission')$s['admission']=new WP_Error();if($name==='probe'){$s['record']['probe_pending']=true;$s['record']['probe_until']=time()+3600;}if($name==='rejected')$s['record']['initial_rejected']=true;$cases[$name]=array($s,true);}
foreach(array('awaiting_handover','handed_over')as$state){$s=np_base();$s['record']+=array('fingerprint'=>'SYNTHETIC_FP','baseline'=>array('state_code'=>'1','accepted'=>false,'checked_at'=>1),'carrier'=>array('accepted'=>true,'checked_at'=>2));$s['record']['state']=$state;$cases[$state]=array($s,false);if($state==='awaiting_handover'){$s['record']['last_error']='carrier_unverified';$cases['warning']=array($s,false);}else{$s['record']['carrier']['accepted']=false;$cases['missing_proof']=array($s,false);}}
foreach(array('preparing','handed_over')as$state){$s=np_base();$s['record']+=array('reconciliation'=>array('phase'=>$state==='preparing'?'admitted':'completed','proof'=>array('checked_at'=>1)),'fingerprint'=>'SYNTHETIC_FP','carrier'=>array('accepted'=>true,'checked_at'=>2));$s['record']['state']=$state;$cases['reconcile_'.$state]=array($s,true);$s['reconciliation_valid']=false;$cases['reconcile_bad_'.$state]=array($s,true);}
foreach($cases as$name=>$case){$s=$case[0];$id=$case[2]??30;$outcomes=array();foreach(array('Synthetic_NP_Status_Old','TKD_NP_Dispatch')as$class){$fresh_calls=0;$events=array();$cached=array('version'=>999);$outcomes[]=$class::status($id,$case[1]);np_check($fresh_calls===($id>0?1:0));np_check($id===0||$events===array('fresh','can'));}np_check($outcomes[0]===$outcomes[1]);$out=$outcomes[1];
 if(in_array($name,array('preparing','release'),true))np_check($out['enabled']&&!$out['blockers']);
 if($name==='permission')np_check(!$out['enabled']&&count($out['blockers'])===1);
 if($name==='awaiting_handover')np_check($out['preparation_valid']);
 if($name==='handed_over')np_check(isset($out['evidence'])&&!$out['blockers']);
 if($name==='warning')np_check($out['preparation_valid']&&$out['warning']==='carrier_unverified');
 if($name==='missing_proof')np_check($out['state']==='awaiting_handover'&&count($out['blockers'])>0);
 if(strpos($name,'reconcile_bad_')===0)np_check(!$out['enabled']&&$out['state']==='preparing');
 if($name==='reconcile_handed_over')np_check(($out['reconciled']??false)===true);
}
class TKD_Cloud_Native_Contracts{public static function report($op){return array('verified'=>true,'reason'=>'matched');}}
require __DIR__.'/../../wordpress/tkd-cloud-reader/document-operations.php';
$pins=require __DIR__.'/../../wordpress/tkd-cloud-reader/document-pins.php';$subset=array();foreach($e['methods']as$m){np_check($pins[$m['method']]===array($m['visibility'],$m['source_sha256']));$subset[$m['method']]=$pins[$m['method']];}
foreach(array('profile'=>$subset,'field_profile'=>array())as$name=>$v){$p=new ReflectionProperty('TKD_Cloud_Document_Operations',$name);$p->setAccessible(true);$p->setValue(null,$v);}
foreach(array('delivery.draft','delivery.save','shipment.issue')as$op)np_check(TKD_Cloud_Document_Operations::pins($op)['verified']);
$subset['TKD_NP_Dispatch::status_read'][1]=str_repeat('0',64);$p=new ReflectionProperty('TKD_Cloud_Document_Operations','profile');$p->setAccessible(true);$p->setValue(null,$subset);
foreach(array('delivery.draft','delivery.save','shipment.issue')as$op){$r=TKD_Cloud_Document_Operations::pins($op);np_check(!$r['verified']&&$r['method']==='TKD_NP_Dispatch::status_read'&&$r['actual_sha256']===$e['methods'][1]['source_sha256']);}
echo "PASS $count exact-native NP status/fresh/private drift assertions across ".count($cases)." scenarios; synthetic helpers only\n";
