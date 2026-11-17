<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH',__DIR__);define('REST_REQUEST',true);
function get_post_meta($id,$key,$single=true){$exists=array_key_exists($key,$GLOBALS['m'][$id]??array());$value=$GLOBALS['m'][$id][$key]??'';return $single?$value:($exists?array($value):array());}
require __DIR__.'/fixtures/native-invoice-regression-proof.php';
class TKD_FO_Invoices extends NativeInvoiceEvidence {}
function get_post_type($id){return $id===12?'tkd_order':($id===20?'tkd_invoice':false);}
function get_post($id){return (object)array('ID'=>$id,'post_type'=>get_post_type($id),'post_status'=>'publish');}
function get_option($key,$default=false){return $default;}function wp_cache_delete(...$args){}
function add_filter(...$args){}function remove_filter(...$args){}function wp_slash($value){return $value;}
function wp_create_nonce($action){return 'synthetic';}
require __DIR__.'/../../wordpress/tkd-cloud-reader/document-operations.php';
$count=0;function native_bridge_check($ok,$name){global $count;if(!$ok)throw new RuntimeException('FAIL '.$name);$count++;}
foreach(array('save_invoice'=>'07cbd3aaf3f009215446d90ac2ea12cbba79eeb93217c206e64f84c29db043fa','sync_order'=>'247c5e25f0e4b4b25c955b72d28eb22c68800f2f5dee7e27b2f6eb05d16dae75') as $method=>$hash){$r=new ReflectionMethod('NativeInvoiceEvidence',$method);$lines=file($r->getFileName());native_bridge_check(hash('sha256',implode('',array_slice($lines,$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1)))===$hash,'actual native source hash');}
function bridge_invoice_state($status){reset_invoice($status);$GLOBALS['m'][20]['_tkd_fo_order_id']='12';$GLOBALS['m'][20]['_tkd_fo_buyer_type']='person';foreach(TKD_Cloud_Document_Operations::BUYER as $field){$GLOBALS['m'][20]['_tkd_fo_'.$field]='SYNTHETIC';}$GLOBALS['m'][12]['_tkd_fo_invoice_id']='20';$data=array();foreach($GLOBALS['m'] as $id=>$row){$data[$id]=array('type'=>$id===12?'tkd_order':'tkd_invoice');foreach($row as $key=>$value){$data[$id][$key]=array($value);}}return array('base'=>array('id'=>12,'status'=>$status),'documents'=>$data,'document_owner'=>false,'document_editor_locked'=>false);}
$p=array('operation'=>'invoice.issue','order_id'=>12,'payload'=>array('invoice_id'=>20,'number'=>'SYNTH-2','date'=>'2026-11-17'));
foreach(array('cancelled','unavailable','returned','refunded') as $status){$s=bridge_invoice_state($status);$before=$m;native_bridge_check(TKD_Cloud_Document_Operations::preflight($p,$s)==='closed_order','closed native order preflight');native_bridge_check($m===$before&&$reserve_calls===0,'closed refusal no actual native invocation');}
$s=bridge_invoice_state('invoice_pending');native_bridge_check(TKD_Cloud_Document_Operations::preflight($p,$s)===true,'open order preflight');$out=TKD_Cloud_Document_Operations::invoke($p,$s);native_bridge_check($out[0]==='recorded_needs_review'&&$out[3]===false,'actual native reserve WP_Error cannot yield complete');native_bridge_check(get_post_meta(20,'_tkd_fo_invoice_status',true)==='issued'&&$reserve_calls===1,'native saved document with unverified reserve preserved');
$s=bridge_invoice_state('invoice_pending');$start=time()-3600;$until=$start+72*3600;
foreach(array('_tkd_fo_stock_state'=>'reserved','_tkd_fo_reservation_stage'=>'extended','_tkd_fo_reservation_started_at'=>(string)$start,'_tkd_fo_reservation_until'=>(string)$until,'_tkd_fo_reserved_items'=>array(array('item_id'=>1,'quantity'=>1)))as $key=>$value){$m[12][$key]=$value;$s['documents'][12][$key]=array($value);}
$out=TKD_Cloud_Document_Operations::invoke($p,$s);native_bridge_check(get_post_meta(12,'_tkd_fo_reservation_until',true)===(string)$until&&get_post_meta(12,'_tkd_fo_reservation_stage',true)==='extended','existing72 not reset by adapter');
$s=bridge_invoice_state('invoice_pending');$m[20]['_tkd_fo_buyer_type']='company';$s['documents'][20]['_tkd_fo_buyer_type']=array('company');$out=TKD_Cloud_Document_Operations::invoke($p,$s);native_bridge_check(get_post_meta(20,'_tkd_fo_buyer_type',true)==='company'&&$out[0]==='recorded_needs_review','actual native buyer_type company preserved');
echo "PASS $count actual-native bridge regression assertions; isolated synthetic WP only\n";
