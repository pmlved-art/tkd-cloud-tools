<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH',__DIR__); define('REST_REQUEST',true);
$count=0; function check_returning($v){global $count;if(!$v){throw new RuntimeException('returning assertion '.($count+1));}$count++;}
function evidence_methods($name){$v=json_decode(file_get_contents(__DIR__.'/../../evidence/'.$name),true);$out=array();foreach($v['methods'] as $m){$out[$m['method']]=$m;}return $out;}
$live=evidence_methods('live-drift-0.4.0-20261117.json');$deps=evidence_methods('document-dependencies-20261117.json');
foreach($live as $m){check_returning(hash('sha256',$m['source'])===$m['source_sha256']);}
$context=$deps['TKD_FO_Orders::responsibility_context_is_manual'];check_returning(hash('sha256',$context['source'])===$context['source_sha256']);
require __DIR__.'/fixtures/returning-native.php';
foreach(array_merge($live,array($context['method']=>$context)) as $name=>$m){
 if($name==='TKD_FO_Multi_Seller::sellers'){continue;}
 list($class,$method)=explode('::',$name);$ref=new ReflectionMethod($class,$method);$lines=file($ref->getFileName());
 check_returning(hash('sha256',implode('',array_slice($lines,$ref->getStartLine()-1,$ref->getEndLine()-$ref->getStartLine()+1)))===$m['source_sha256']);
}
$actor=10;$admin=false;$rights=true;$records=array();$writes=0;$cache=array();
function absint($v){return abs((int)$v);}function get_current_user_id(){return $GLOBALS['actor'];}
function current_user_can($cap,...$args){return $cap==='manage_options'?$GLOBALS['admin']:$GLOBALS['rights'];}
function get_option($key,$default=false){return $GLOBALS['records'][$key]??$default;}
function maybe_serialize($v){return serialize($v);}function wp_cache_delete($name,$group){$GLOBALS['cache'][]=$name;}
class ReturningDB {public $options='wp_options';public function prepare($sql,...$args){return $args;}public function query($args){global $records,$writes;list($next,$key,$old)=$args;if(serialize($records[$key]??null)!==$old){return 0;}$records[$key]=unserialize($next);$writes++;return 1;}}
$wpdb=new ReturningDB();$key='tkd_fo_order_responsibility_12';
$other=array('user_id'=>42,'source'=>'returning_customer_phone','user_name'=>'SYNTHETIC');$records[$key]=$other;
check_returning(TKD_FO_Orders::claim_test(12)===array());check_returning($records[$key]===$other&&$writes===0);
check_returning(TKD_Returning_Customer_20261114::accept(12)===false);check_returning($records[$key]===$other&&$writes===0);
$records[$key]=array();check_returning(TKD_FO_Orders::claim_test(12)===array());check_returning($records[$key]===array()&&$writes===0);
$mine=$other;$mine['user_id']=10;$records[$key]=$mine;
check_returning(TKD_FO_Orders::claim_test(12)===array());check_returning($records[$key]===$mine&&$writes===0); // REST must not accept even its own pending assignment.
check_returning(TKD_Returning_Customer_20261114::accept(12)===true);check_returning($records[$key]['user_id']===10&&$records[$key]['accepted_by']===10&&$writes===1);
check_returning(TKD_Returning_Customer_20261114::accept(12)===false&&$writes===1);
$records[$key]=$mine;$rights=false;check_returning(TKD_Returning_Customer_20261114::accept(12)===false&&$writes===1);$rights=true;
$records[$key]=array('user_id'=>10,'source'=>'manual_action');check_returning(TKD_Returning_Customer_20261114::accept(12)===false&&$writes===1);
check_returning($admin===false);check_returning($cache===array($key,'notoptions','alloptions'));
// Every applicable operation must actually guard the newly reachable native dependency.
class TKD_Cloud_Native_Contracts {public static function report($op){return array('verified'=>true,'reason'=>'matched');}}
require __DIR__.'/../../wordpress/tkd-cloud-reader/document-operations.php';
$pins=require __DIR__.'/../../wordpress/tkd-cloud-reader/document-pins.php';$subset=array();foreach($live as $name=>$m){check_returning(($pins[$name]??null)===array($m['visibility'],$m['source_sha256']));if(strpos($name,'TKD_Returning_Customer_20261114::')===0){$subset[$name]=$pins[$name];}}
foreach(array('profile'=>$subset,'field_profile'=>array()) as $name=>$value){$p=new ReflectionProperty('TKD_Cloud_Document_Operations',$name);$p->setAccessible(true);$p->setValue(null,$value);}
foreach(array('invoice.draft','invoice.issue','reservation.extend','delivery.draft','delivery.save','shipment.issue','invoice.pdf','delivery.pdf') as $op){check_returning(TKD_Cloud_Document_Operations::pins($op)['verified']===true);}
$subset['TKD_Returning_Customer_20261114::accept'][1]=str_repeat('0',64);$p=new ReflectionProperty('TKD_Cloud_Document_Operations','profile');$p->setAccessible(true);$p->setValue(null,$subset);
foreach(array('invoice.draft','invoice.issue','reservation.extend','delivery.draft','delivery.save','shipment.issue','invoice.pdf','delivery.pdf') as $op){$r=TKD_Cloud_Document_Operations::pins($op);check_returning($r['verified']===false&&$r['method']==='TKD_Returning_Customer_20261114::accept'&&$r['actual_sha256']===$live['TKD_Returning_Customer_20261114::accept']['source_sha256']);}
echo "PASS $count exact-native returning ownership and dependency guard assertions; synthetic only\n";
