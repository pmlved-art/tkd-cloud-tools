<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
class WP_Error { public $code; public function __construct($code,...$args){$this->code=$code;} }
class FakeResponse { public function get_data(){return array('id'=>12);} }
class FakeRequest {
    public $query=array(); public $id='20';
    public function get_query_params(){return $this->query;}
    public function get_url_params(){return array('number'=>'TKD-SYNTHETIC','document_id'=>$this->id);}
}
class TKD_Cloud_Reader {
    public static function permission($request){return $GLOBALS['authorized']?true:new WP_Error('unauthorized');}
    public static function read_number($number){return new FakeResponse();}
}
class TKD_Cloud_Document_Operations { public static function pins($op){return array('verified'=>$GLOBALS['pins'],'reason'=>'native_drift');} }
class TKD_Cloud_Operations { public static function bounded_value($v,&$nodes,&$bytes){return true;} }
function current_user_can($cap,...$args){return $GLOBALS['capable'];}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_meta($id,$key,$single=false){$v=$GLOBALS['meta'][$id][$key]??array();return $single?($v[0]??''):$v;}
function wp_create_nonce($action){return 'SYNTHETIC-'.$action;}
function add_filter($hook,$fn,...$args){$GLOBALS['filters'][$hook]=$fn;}
function remove_filter($hook,$fn,...$args){unset($GLOBALS['filters'][$hook]);}
class TKD_FO_Invoices {
    public static function download_invoice_pdf(){
        $GLOBALS['native_calls']++;
        if ($_GET!==$_REQUEST||$_GET['invoice_id']!=='20'||$_GET['_wpnonce']!=='SYNTHETIC-tkd_fo_download_invoice_pdf_20') throw new Exception('invalid scoped nonce');
        $GLOBALS['nonce_verified']=true;
        if($GLOBALS['attempt_write']) { ($GLOBALS['filters']['update_post_metadata'])(); $GLOBALS['business_writes']++; }
        throw new RuntimeException('simulated renderer refusal');
    }
}
class TKD_FO_Deliveries { public static function download_pdf(){ $GLOBALS['delivery_calls']++;throw new RuntimeException('simulated renderer refusal'); } }
require __DIR__.'/../../wordpress/tkd-cloud-reader/document-pdf.php';
function reset_case(){
    $GLOBALS['authorized']=$GLOBALS['capable']=$GLOBALS['pins']=true;
    $GLOBALS['posts']=array(20=>(object)array('post_type'=>'tkd_invoice','post_status'=>'publish'),30=>(object)array('post_type'=>'tkd_delivery','post_status'=>'publish'));
    $GLOBALS['meta']=array(20=>array('_tkd_fo_order_id'=>array('12'),'_tkd_fo_invoice_status'=>array('issued')),30=>array('_tkd_fo_delivery_order_id'=>array('12'),'_tkd_fo_delivery_invoice_id'=>array('20'),'_tkd_fo_delivery_status'=>array('issued')));
    $GLOBALS['filters']=array();$GLOBALS['native_calls']=$GLOBALS['delivery_calls']=$GLOBALS['business_writes']=0;
    $GLOBALS['nonce_verified']=$GLOBALS['attempt_write']=false;$_GET=array('untrusted'=>'get');$_REQUEST=array('untrusted'=>'request');
    return new FakeRequest();
}
$checks=0;
function check_pdf($ok){global $checks;if(!$ok)throw new Exception('FAIL PDF boundary '.($checks+1));$checks++;}
foreach(array('auth','capability','wrong_order','duplicate_link','draft','query_override','pins','invalid_id','trash') as $case){
    $r=reset_case();
    if($case==='auth')$GLOBALS['authorized']=false;
    if($case==='capability')$GLOBALS['capable']=false;
    if($case==='wrong_order')$GLOBALS['meta'][20]['_tkd_fo_order_id']=array('13');
    if($case==='duplicate_link')$GLOBALS['meta'][20]['_tkd_fo_order_id']=array('12','12');
    if($case==='draft')$GLOBALS['meta'][20]['_tkd_fo_invoice_status']=array('draft');
    if($case==='query_override')$r->query=array('view'=>'1');
    if($case==='pins')$GLOBALS['pins']=false;
    if($case==='invalid_id')$r->id='020';
    if($case==='trash')$GLOBALS['posts'][20]->post_status='trash';
    $out=TKD_Cloud_Document_PDF::read($r);
    check_pdf($out instanceof WP_Error && $GLOBALS['native_calls']===0 && $GLOBALS['business_writes']===0);
}
$r=reset_case();$out=TKD_Cloud_Document_PDF::read($r);
check_pdf($out instanceof WP_Error && $out->code==='tkd_operation_pdf_unavailable' && $GLOBALS['native_calls']===1 && $GLOBALS['nonce_verified']);
check_pdf($_GET===array('untrusted'=>'get') && $_REQUEST===array('untrusted'=>'request') && !$GLOBALS['filters']);
$r=reset_case();$GLOBALS['attempt_write']=true;$out=TKD_Cloud_Document_PDF::read($r);
check_pdf($GLOBALS['business_writes']===0 && $out instanceof WP_Error && !$GLOBALS['filters']);
$r=reset_case();$r->id='30';$GLOBALS['meta'][20]['_tkd_fo_order_id']=array('13');TKD_Cloud_Document_PDF::read($r);
check_pdf($GLOBALS['delivery_calls']===0);
$r=reset_case();$r->id='30';TKD_Cloud_Document_PDF::read($r);check_pdf($GLOBALS['delivery_calls']===1 && !$GLOBALS['filters']);
echo 'PASS '.$checks.' independent PDF authorization/linkage/nonce/write-refusal boundaries; synthetic only'.PHP_EOL;
