<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/test-reader.php';
require __DIR__ . '/fixtures/tkd-classes.php';
define('ARRAY_A', 'ARRAY_A');
define('TKD_FO_VERSION', '0.1.223');
class SyntheticDatabase {
    public $prefix='wp_synthetic_'; public $posts='wp_synthetic_posts'; public $postmeta='wp_synthetic_postmeta'; public $last_error=''; public $sql=array(); public $columns=array(); public $rows=array(); public $fail=false; public $suppressed=false;
    function suppress_errors($s) { $old=$this->suppressed; $this->suppressed=$s; return $old; }
    function prepare($sql,...$args) {
        foreach ($args as $arg) { $sql=preg_replace_callback('/%[sd]/',function($m)use($arg){ return $m[0]==='%d' ? (string)(int)$arg : "'".str_replace("'","''",(string)$arg)."'"; },$sql,1); }
        return $sql;
    }
    function get_results($sql,$format) {
        if (!preg_match('/\A(?:SHOW COLUMNS FROM|SELECT )/',$sql)) throw new Exception('MUTATION SQL');
        if (strpos($sql,'SELECT DISTINCT p.ID AS id')===0) {
            if (strpos($sql,"LEFT(m.meta_value,4) = BINARY 'TKD-'")===false || strpos($sql,"RIGHT(m.meta_value,4) = BINARY '-100'")===false) throw new Exception('SUFFIX QUERY NOT EXACT');
            $this->sql[]=$sql; return array_map(function($id){ return array('id'=>(string)$id); }, $GLOBALS['ids']);
        }
        if (strpos($sql,'`wp_synthetic_tkd_fo_stock`')===false) throw new Exception('UNEXPECTED TABLE');
        $this->sql[]=$sql; $this->last_error=$this->fail ? 'synthetic-error' : '';
        return strpos($sql,'SHOW COLUMNS')===0 ? $this->columns : $this->rows;
    }
}
$wpdb=new SyntheticDatabase();
foreach(array('id'=>'bigint(20) unsigned','sku'=>'varchar(128)','name'=>'varchar(255)','unit'=>'varchar(32)','on_hand'=>'decimal(12,3)','reserved'=>'decimal(12,3)','incoming'=>'decimal(12,3)','active'=>'tinyint(1)','updated_at'=>'datetime','cost_price_uah'=>'decimal(12,2)') as $name=>$type) $wpdb->columns[]=array('Field'=>$name,'Type'=>$type,'Default'=>'NEVER_RETURN_DEFAULT');
$post->post_modified_gmt='2026-11-17 10:00:00'; $post->post_type='tkd_order'; $post->post_status='publish'; $numbers=array('TKD-100'); $statuses=array('new'); $ids=array(12);
$r->number='TKD-100'; $r->query_params=array();
$extra_meta=array('_tkd_client_total'=>array('0.00'),'_tkd_fo_order_items'=>array(array(array('sku'=>'SYNTHETIC','name'=>'Synthetic','unit'=>'pcs','quantity'=>1.125,'unit_price'=>100.25,'secret'=>'HIDDEN','opaque'=>array('token'=>'HIDDEN')))), '_tkd_fo_payments'=>array(array(array('id'=>'synthetic-payment','amount'=>'0.00','status'=>'void','payer_name'=>'HIDDEN','reference'=>'HIDDEN','bank_transaction_id'=>'HIDDEN','opaque'=>array('secret'=>'HIDDEN')))));
$d=TKD_Cloud_Read_Extensions::details($r);
check($d instanceof WP_REST_Response,'details succeeds');
check(strpos(json_encode($d->data),'HIDDEN')===false,'unknown nested/payment private fields filtered');
check($d->data['metadata']['_tkd_client_total']===array('present'=>true,'value'=>'0.00') && $d->data['metadata']['_tkd_paid_at']===array('present'=>false,'value'=>null),'missing and zero distinct');
check($d->data['metadata']['_tkd_fo_order_items']['value'][0]['quantity']==='1.125' && $d->data['metadata']['_tkd_fo_order_items']['value'][0]['unit_price']==='100.25','item quantity and unit_price number conversion');
check($d->data['metadata']['_tkd_fo_payments']['value'][0]['status']==='void' && !isset($d->data['payment_total']),'stored void rows not counted as received');
check($d->headers['ETag']==='"'.$d->data['revision'].'"' && strlen($d->data['revision'])===64,'ETag informational snapshot');
$old=$d->data['revision']; $extra_meta['_tkd_client_total']=array('1.00');
check(TKD_Cloud_Read_Extensions::details($r)->data['revision']!==$old,'relevant snapshot changes revision');
$extra_meta['_tkd_client_total']=array('1.00','1.00');check(error_status(TKD_Cloud_Read_Extensions::details($r),409),'duplicate meta fails closed');unset($extra_meta['_tkd_client_total']);
$original=$extra_meta['_tkd_fo_order_items'];
foreach(array(array('not-a-list'=>array()),array(array('quantity'=>'1.0001')),array(array('unit_price'=>'10.001')),array(array('sku'=>array('nested'))),array_fill(0,101,array('sku'=>'S'))) as $bad) {
 $extra_meta['_tkd_fo_order_items']=array($bad);check(error_status(TKD_Cloud_Read_Extensions::details($r),503),'malformed/bounded items rejected');
}
$extra_meta['_tkd_fo_order_items']=array(array(array('name'=>str_repeat('x',131073))));check(error_status(TKD_Cloud_Read_Extensions::details($r),503),'input bytes bounded');
$extra_meta['_tkd_fo_order_items']=$original;
$extra_meta['_tkd_fo_payments']=array(array(array('id'=>'same'),array('id'=>'same')));check(error_status(TKD_Cloud_Read_Extensions::details($r),503),'duplicate payment ids fail closed');
$extra_meta['_tkd_fo_payments']=array(array(array('id'=>'s','amount'=>'1e10')));check(error_status(TKD_Cloud_Read_Extensions::details($r),503),'payment exponent refused');
$extra_meta['_tkd_fo_payments']=array(array());
$r->query_params=array('number'=>'100');$res=TKD_Cloud_Read_Extensions::lookup($r);
check($res instanceof WP_REST_Response && $res->data['number']==='TKD-100','unique numeric suffix resolves exact full number');
$ids=array(12,13);check(error_status(TKD_Cloud_Read_Extensions::lookup($r),409),'duplicate suffix refused');$ids=array();check(error_status(TKD_Cloud_Read_Extensions::lookup($r),404),'missing suffix 404');$ids=array(12);
$r->query_params=array('number'=>'100 OR 1');check(error_status(TKD_Cloud_Read_Extensions::lookup($r),400),'suffix SQL/pattern injection refused');
$r->query_params=array('number'=>'100','limit'=>'25');check(error_status(TKD_Cloud_Read_Extensions::lookup($r),400),'no list endpoint');
$r->query_params=array();
$m=TKD_Cloud_Read_Extensions::manifest($r);
check($m instanceof WP_REST_Response && count($m->data['classes'])===11 && $m->data['total_methods']===3,'fixed classes public static signatures only');
$mc=$m->data['classes'][0];
check($mc['methods'][0]['required_args']===1 && $mc['methods'][0]['total_args']===2,'method counts');
$ref=new ReflectionMethod('TKD_FO_Orders','get_order_items');$lines=file($ref->getFileName());$slice=implode('',array_slice($lines,$ref->getStartLine()-1,$ref->getEndLine()-$ref->getStartLine()+1));
check($mc['file_sha256']===hash_file('sha256',$ref->getFileName()) && $mc['methods'][0]['source_sha256']===hash('sha256',$slice),'file and exact method-line hashes');
check(strpos(json_encode($m->data),__DIR__)===false && strpos(json_encode($m->data),'MUTATING')===false && strpos(json_encode($m->data),'NEVER_RETURN_DEFAULT')===false,'no source/path/default value leaks');
check($m->data['stock_schema']['state']==='read-compatible','manifest stock guard');
$row=array('id'=>'1','sku'=>'SKU-SYNTHETIC','name'=>'Synthetic','unit'=>'pcs','on_hand'=>'10.125','reserved'=>'3.001','incoming'=>'99.000','active'=>'1','updated_at'=>'2026-11-17 10:00:00','cost_price_uah'=>'HIDDEN');
$wpdb->rows=array($row);$wpdb->sql=array();$r->query_params=array('limit'=>'1');
$s=TKD_Cloud_Read_Extensions::stock($r);
check($s instanceof WP_REST_Response && $s->data['rows'][0]['available_on_hand_minus_reserved']==='7.124000','stock exact decimal subtraction excludes incoming');
check(!isset($s->data['rows'][0]['cost_price_uah']) && strpos($wpdb->sql[1],'cost_price')===false && strpos($wpdb->sql[0],'SHOW COLUMNS')===0,'schema precedes projected SELECT no cost');
$wpdb->rows=array($row,array_merge($row,array('id'=>'2')));$s=TKD_Cloud_Read_Extensions::stock($r);check(count($s->data['rows'])===1 && $s->data['next_cursor']==='1','stock cursor limit plus lookahead');
$r->query_params=array('sku'=>'SKU-SYNTHETIC');check(error_status(TKD_Cloud_Read_Extensions::stock($r),409),'duplicate exact SKU refused');
$wpdb->rows=array($row);$s=TKD_Cloud_Read_Extensions::stock($r);check($s instanceof WP_REST_Response && strpos(end($wpdb->sql),'BINARY `sku` = BINARY')!==false,'binary SKU equality');
$wpdb->rows=array();check(error_status(TKD_Cloud_Read_Extensions::stock($r),404),'missing SKU 404');
$r->query_params=array('limit'=>'51');check(error_status(TKD_Cloud_Read_Extensions::stock($r),400),'stock limit max50');
$r->query_params=array('cursor'=>'1','sku'=>'SKU-SYNTHETIC');check(error_status(TKD_Cloud_Read_Extensions::stock($r),400),'SKU cursor ambiguity refused');
$r->query_params=array();$backup=$wpdb->columns;$wpdb->columns=array();$wpdb->sql=array();
check(error_status(TKD_Cloud_Read_Extensions::stock($r),503) && count($wpdb->sql)===1,'missing schema blocks SELECT');
$wpdb->columns=$backup;$wpdb->columns[4]['Type']='float';check(error_status(TKD_Cloud_Read_Extensions::stock($r),503),'floating stock column type rejected');$wpdb->columns=$backup;
$wpdb->fail=true;check(error_status(TKD_Cloud_Read_Extensions::stock($r),503) && $wpdb->suppressed===false,'DB error sanitized and suppression restored');$wpdb->fail=false;
$wpdb->rows=array(array_merge($row,array('on_hand'=>'0.1','reserved'=>'0.3','incoming'=>'1')));$s=TKD_Cloud_Read_Extensions::stock($r);check($s->data['rows'][0]['available_on_hand_minus_reserved']==='-0.200000','negative exact availability');
foreach(array('/tkd-cloud/v1/integration-manifest','/tkd-cloud/v1/orders','/tkd-cloud/v1/orders/TKD-100/details','/tkd-cloud/v1/stock') as $route) {
 $r->route=$route;$uid=8;check(error_status(TKD_Cloud_Reader::permission($r),401),'new route rejects cookie/mismatched native user');$uid=7;
 $explicit_cap=false;check(error_status(TKD_Cloud_Reader::permission($r),403),'new route requires same explicit cap');$explicit_cap=true;
 $response=TKD_Cloud_Reader::no_store(new WP_REST_Response(),null,$r);check(strpos($response->headers['Cache-Control'],'no-store')!==false,'new route no-store');
}
echo "PASS $count total synthetic assertions including read extensions; no live WordPress integration\n";
