<?php
if(PHP_SAPI!=='cli'){exit;}define('ABSPATH',__DIR__);
$count=0;function release_check($v){global $count;if(!$v)throw new RuntimeException('release assertion '.($count+1));$count++;}
class WP_Error{public $code;public function __construct($code,$message){$this->code=$code;}public function get_error_message(){return 'SYNTHETIC';}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_verify_nonce($v,$action){return $v==='SYNTHETIC_NONCE';}function wp_unslash($v){return $v;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['seller'];}
trait Release_Fixture_Helpers{
 private static function allowed($id,$type){return $GLOBALS['allowed'];}
 private static function snapshot(...$args){return $GLOBALS['snapshot'];}
}
trait Receipt_Fixture_Helpers{public static function ensure($order,$preflight){$GLOBALS['receipts'][]=get_called_class();release_check($preflight()===true);return new WP_Error('synthetic_stop','stop before any stock action');}}
class TKD_MD_Cashalot{use Receipt_Fixture_Helpers;}class TKD_RV_Cashalot{use Receipt_Fixture_Helpers;}class TKD_TP_Cashalot{use Receipt_Fixture_Helpers;}class PML_Release_Receipt{use Receipt_Fixture_Helpers;}
require __DIR__.'/fixtures/release-native.php';
$e=json_decode(file_get_contents(__DIR__.'/../../evidence/release-dependencies-20261117.json'),true);
foreach($e['methods']as$m){list($class,$method)=explode('::',$m['method']);$r=new ReflectionMethod($class,$method);$lines=file($r->getFileName());release_check(hash('sha256',implode('',array_slice($lines,$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1)))===$m['source_sha256']);}
$serial=new ReflectionMethod('TKD_Warehouse_Scanner','serials');$lines=file($serial->getFileName());$pins=require __DIR__.'/../../wordpress/tkd-cloud-reader/document-pins.php';release_check(hash('sha256',implode('',array_slice($lines,$serial->getStartLine()-1,$serial->getEndLine()-$serial->getStartLine()+1)))===$pins['TKD_Warehouse_Scanner::serials'][1]);
foreach(array(1,2,'3','2.000')as$q){release_check(TKD_Warehouse_Scanner::quantity_only_items(array(array('sku'=>'WM100UG1','quantity'=>$q,'serial_numbers'=>''))));}
release_check(TKD_Warehouse_Scanner::quantity_only_items(array(array('sku'=>' wm100ug1 ','quantity'=>1),array('sku'=>'WM100UG1','quantity'=>2,'serial_numbers'=>" \n,; "))));
foreach(array(0,-1,1.1,'0','-1','1.001',null,true,false,'NaN','Infinity',INF,NAN,'invalid',array())as$q){release_check(!TKD_Warehouse_Scanner::quantity_only_items(array(array('sku'=>'WM100UG1','quantity'=>$q))));}
foreach(array('SPI4862V100-500P','SYNTHETIC-INVERTER','SYNTHETIC-BATTERY','WM100UG10','WM100UG1-X','AWG3-CU-35-1M-180C','')as$sku){release_check(!TKD_Warehouse_Scanner::quantity_only_items(array(array('sku'=>$sku,'quantity'=>1))));}
foreach(array('123456789012345678','SYNTHETIC'," \n SN1;SN2")as$sn){release_check(!TKD_Warehouse_Scanner::quantity_only_items(array(array('sku'=>'WM100UG1','quantity'=>1,'serial_numbers'=>$sn))));}
foreach(array(null,false,array(),array('bad'),array(array('sku'=>'WM100UG1','quantity'=>1),array('sku'=>'SYNTHETIC-BATTERY','quantity'=>1)))as$items){release_check(!TKD_Warehouse_Scanner::quantity_only_items($items));}
// Exact native execution preserves fiscal preflight and provider selection; fixtures stop before stock.
$snapshot=array('revision'=>str_repeat('a',64),'mode'=>'pickup','invoice'=>20,'order'=>12,'blockers'=>array(),'fiscal'=>array('state'=>'ready'),'operation_basis'=>'SYNTHETIC');$allowed=true;$_SERVER['REQUEST_METHOD']='POST';$_POST=array('tkd_release_nonce'=>'SYNTHETIC_NONCE','_wpnonce'=>'SYNTHETIC_NONCE');$receipts=array();
foreach(array('fop_md'=>'TKD_MD_Cashalot','fop_rv'=>'TKD_RV_Cashalot','fop_tp'=>'TKD_TP_Cashalot','fop_pm'=>'PML_Release_Receipt')as$seller=>$provider){$receipts=array();$r=TKD_Sales_Release::run_test(30,str_repeat('a',64),true);release_check(is_wp_error($r)&&$r->code==='sales_release_fiscal'&&$receipts===array($provider));}
$seller='fop_md';$receipts=array();$r=TKD_Sales_Release::run_test(30,str_repeat('b',64),true);release_check(is_wp_error($r)&&!$receipts);
$allowed=false;$r=TKD_Sales_Release::run_test(30,str_repeat('a',64),true);release_check(is_wp_error($r)&&!$receipts);$allowed=true;
$_POST['tkd_release_nonce']='BAD';$r=TKD_Sales_Release::run_test(30,str_repeat('a',64),true);release_check(is_wp_error($r)&&!$receipts);$_POST['tkd_release_nonce']='SYNTHETIC_NONCE';
$snapshot['blockers']=array('SYNTHETIC_BLOCKER');$r=TKD_Sales_Release::run_test(30,str_repeat('a',64),true);release_check(is_wp_error($r)&&!$receipts);
class TKD_Cloud_Native_Contracts{public static function report($op){return array('verified'=>true,'reason'=>'matched');}}
require __DIR__.'/../../wordpress/tkd-cloud-reader/document-operations.php';
$subset=array();foreach($e['methods']as$m){release_check($pins[$m['method']]===array($m['visibility'],$m['source_sha256']));$subset[$m['method']]=$pins[$m['method']];}
foreach(array('profile'=>$subset,'field_profile'=>array())as$name=>$v){$p=new ReflectionProperty('TKD_Cloud_Document_Operations',$name);$p->setAccessible(true);$p->setValue(null,$v);}
foreach(array('delivery.draft','delivery.save','shipment.issue')as$op)release_check(TKD_Cloud_Document_Operations::pins($op)['verified']);
foreach($subset as$method=>$v){$bad=$subset;$bad[$method][1]=str_repeat('0',64);$p=new ReflectionProperty('TKD_Cloud_Document_Operations','profile');$p->setAccessible(true);$p->setValue(null,$bad);foreach(array('delivery.draft','delivery.save','shipment.issue')as$op){$r=TKD_Cloud_Document_Operations::pins($op);release_check(!$r['verified']&&$r['method']===$method&&$r['actual_sha256']===$v[1]);}}
// State keys retain whole scanner/dispatch/intents; native fingerprints retain financial evidence.
$keys=require __DIR__.'/../../wordpress/tkd-cloud-reader/document-state-keys.php';foreach(array('_tkd_ws_task','_tkd_np_dispatch_v1','_tkd_fo_dispatch_v1','_tkd_fo_delivery_handover_post_v1')as$key)release_check(in_array($key,$keys,true));
echo "PASS $count release/scanner native dependency assertions; synthetic providers, no live writes\n";
