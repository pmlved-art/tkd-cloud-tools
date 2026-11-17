<?php
if (!defined('ABSPATH')) { exit; }
/** Fixed document domain operations, invoked only inside gateway durable journal. */
final class TKD_Cloud_Document_Operations {
    private static $profile = null;
    private static $field_profile = null;
    private static $source_cache = array();
    private static $reflection_cache = array();
    const CAPS = array('invoice.draft'=>'tkd_cloud_invoice','invoice.issue'=>'tkd_cloud_invoice','reservation.extend'=>'tkd_cloud_extend_reservation','delivery.save'=>'tkd_cloud_delivery_draft','delivery.draft'=>'tkd_cloud_delivery_draft','shipment.issue'=>'tkd_cloud_issue_delivery');
    const BUYER = array('buyer_name','buyer_tax_id','buyer_vat_id','buyer_address','buyer_phone','buyer_email','receiver','payer','contract','application');
    public static function supports($op) { return is_string($op) && isset(self::CAPS[$op]); }
    public static function validate($op,$p) {
        if (!is_array($p)) { return false; }
        if ($op==='invoice.draft') { return $p===array(); }
        if ($op==='reservation.extend') { return count($p)===1 && isset($p['hours']) && is_int($p['hours']) && in_array($p['hours'],array(36,48,72),true); }
        if ($op==='delivery.save') {
            if(count($p)!==2||array_diff(array_keys($p),array('delivery_id','items'))||!is_int($p['delivery_id'])||$p['delivery_id']<1||!is_array($p['items'])||!$p['items']||count($p['items'])>100||array_keys($p['items'])!==range(0,count($p['items'])-1)){return false;}
            $seen=array();foreach($p['items'] as $row){if(!is_array($row)||count($row)!==3||array_diff(array_keys($row),array('source_index','quantity','serial_numbers'))||!is_int($row['source_index'])||$row['source_index']<0||isset($seen[$row['source_index']])||!is_string($row['quantity'])||self::quantity($row['quantity'])===null||self::quantity($row['quantity'])<1||!is_string($row['serial_numbers'])||strlen($row['serial_numbers'])>16000||preg_match('/[^A-Za-z0-9\r\n,; -]/',$row['serial_numbers'])){return false;}$seen[$row['source_index']]=true;}
            return true;
        }
        if ($op==='delivery.draft') { return count($p)===1 && isset($p['invoice_id']) && is_int($p['invoice_id']) && $p['invoice_id']>0; }
        if ($op==='shipment.issue') { return count($p)===1 && isset($p['delivery_id']) && is_int($p['delivery_id']) && $p['delivery_id']>0; }
        if ($op==='invoice.issue') {
            return count($p)===3 && !array_diff(array_keys($p),array('invoice_id','number','date')) && is_int($p['invoice_id']) && $p['invoice_id']>0 && is_string($p['number']) && preg_match('/\A[A-Za-z0-9А-Яа-яІіЇїЄє._\/-]{1,64}\z/uD',$p['number']) && is_string($p['date']) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$p['date']) && ($d=DateTimeImmutable::createFromFormat('!Y-m-d',$p['date'])) && $d->format('Y-m-d')===$p['date'];
        }
        return false;
    }
    private static function fail($r) { return new WP_Error('tkd_operation_'.$r,'Document operation refused.',array('status'=>503)); }
    public static function pins($op) {
        if (!self::supports($op) && !in_array($op,array('invoice.pdf','delivery.pdf'),true)) { return array('verified'=>false,'reason'=>'unsupported_operation'); }
        $base=TKD_Cloud_Native_Contracts::report('status.change');if(!$base['verified']){return $base;}
        $pins=self::$profile ?? require __DIR__.'/document-pins.php'; $fields=self::$field_profile ?? require __DIR__.'/document-field-pins.php'; $cache=array(); $checked=array();
        foreach ($pins as $key=>$spec) {
            list($class,$name)=explode('::',$key);
            if ($op==='delivery.pdf' && !in_array($class,array('TKD_FO_Invoices','TKD_FO_Deliveries','TKD_FO_Orders','TKD_FO_Multi_Seller','TKD_FO_Order_Lifecycle'),true)) { continue; }
            if ($op==='reservation.extend' && !in_array($class,array('TKD_FO_Orders','TKD_FO_Order_Lifecycle'),true)) { continue; }
            if ($op!=='reservation.extend' && $name==='handle_reservation_action_locked') { continue; }
            if (strpos($op,'invoice.')===0 && !in_array($class,array('TKD_FO_Invoices','TKD_FO_Orders','TKD_FO_Order_Lifecycle','TKD_FO_Multi_Seller'),true)) { continue; }
            if (!class_exists($class,false)) { return array('verified'=>false,'reason'=>'native_missing','method'=>$key); }
            $ref=self::$reflection_cache[$class]??(self::$reflection_cache[$class]=new ReflectionClass($class)); if (!$ref->hasMethod($name)) { return array('verified'=>false,'reason'=>'native_missing','method'=>$key); }
            $m=$ref->getMethod($name); $file=$m->getFileName();
            if (!$m->isStatic() || ($spec[0]==='public'?!$m->isPublic():($spec[0]==='protected'?!$m->isProtected():!$m->isPrivate())) || $m->getDeclaringClass()->getName()!==$class) { return array('verified'=>false,'reason'=>'native_visibility_mismatch','method'=>$key); }
            if (!is_string($file)||!is_file($file)||!is_readable($file)||@filesize($file)>2097152) { return array('verified'=>false,'reason'=>'native_source_unavailable','method'=>$key); }
            if (!isset($cache[$file])) {
                $file_hash=@hash_file('sha256',$file);if(!is_string($file_hash)){return array('verified'=>false,'reason'=>'native_source_unavailable','method'=>$key);}
                if(!isset(self::$source_cache[$file])||self::$source_cache[$file]['hash']!==$file_hash){self::$source_cache[$file]=array('hash'=>$file_hash,'lines'=>@file($file));}
                $cache[$file]=self::$source_cache[$file]['lines'];
            }
            $lines=$cache[$file];
            if(!isset($checked[$class])){foreach($fields[$class]??array() as $declaration){if(substr_count(implode('',$lines),$declaration)!==1){return array('verified'=>false,'reason'=>'native_drift','method'=>$class);}}$checked[$class]=true;}
            $start=$m->getStartLine();$end=$m->getEndLine();
            if (!$lines || $start<1 || $end<$start || $end>count($lines)) { return array('verified'=>false,'reason'=>'native_source_unavailable','method'=>$key); }
            $actual=hash('sha256',implode('',array_slice($lines,$start-1,$end-$start+1)));
            if (!hash_equals($spec[1],$actual)) { return array('verified'=>false,'reason'=>'native_drift','method'=>$key,'expected_sha256'=>$spec[1],'actual_sha256'=>$actual); }
        }
        return array('verified'=>true,'reason'=>'matched');
    }
    private static function canonical($v){if(is_array($v)){ksort($v);foreach($v as &$x){$x=self::canonical($x);}unset($x);}return $v;}
    public static function payload_digest($p){return hash('sha256',json_encode((object)self::canonical($p),JSON_UNESCAPED_SLASHES));}
    public static function scope($op,$p){
        if($op==='invoice.draft'){return array();}
        if($op==='reservation.extend'){return array('hours'=>$p['hours']);}
        if(in_array($op,array('invoice.issue','delivery.draft'),true)){return array('invoice_id'=>$p['invoice_id']);}
        return array('delivery_id'=>$p['delivery_id']);
    }
    public static function valid_scope($op,$p){
        if(!is_array($p)){return false;}if($op==='invoice.draft'){return $p===array();}
        if($op==='reservation.extend'){return self::validate($op,$p);}
        $key=in_array($op,array('invoice.issue','delivery.draft'),true)?'invoice_id':'delivery_id';return count($p)===1&&isset($p[$key])&&is_int($p[$key])&&$p[$key]>0;
    }
    public static function augment($state,$op,$payload) {
        $digest=isset($payload['_snapshot_digest'])?$payload['_snapshot_digest']:self::payload_digest($payload);unset($payload['_snapshot_digest']);$scope=self::scope($op,$payload);
        $order=$state['base']['id'];$keys=require __DIR__.'/document-state-keys.php';$data=array();$nodes=0;$bytes=0;
        // Pure bounded query: include every linked invoice/delivery to detect a concurrent creation.
        $ids=array($order);foreach(array('tkd_invoice'=>'_tkd_fo_order_id','tkd_delivery'=>'_tkd_fo_delivery_order_id') as $type=>$meta) {
            $found=get_posts(array('post_type'=>$type,'post_status'=>'any','posts_per_page'=>26,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','meta_query'=>array(array('key'=>$meta,'value'=>(string)$order,'compare'=>'=')),'no_found_rows'=>true,'suppress_filters'=>true));
            if (!is_array($found)||count($found)>25) { return self::fail('document_bound'); } foreach($found as $id){$ids[]=(int)$id;}
        }
        foreach(array('invoice_id','delivery_id') as $key) { if(isset($payload[$key])&&!in_array($payload[$key],$ids,true)){return self::fail('document_identity');} }
        sort($ids,SORT_NUMERIC);$locked=false;
        foreach($ids as $id){
            wp_cache_delete($id,'post_meta');wp_cache_delete($id,'posts');$post=get_post($id);
            if(!$post||in_array($post->post_status,array('trash','auto-draft'),true)||!current_user_can('edit_post',$id)){return self::fail('document_access');}
            $lock=TKD_Cloud_Operations::editor_locked($id);if($lock instanceof WP_Error){return $lock;}$locked=$locked||$lock;
            $row=array('type'=>$post->post_type,'status'=>$post->post_status,'modified'=>$post->post_modified_gmt,'date_gmt'=>$post->post_date_gmt);
            foreach($keys as $key){$v=get_post_meta($id,$key,false);if(count($v)>1||!TKD_Cloud_Operations::bounded_value($v,$nodes,$bytes)){return self::fail('document_schema');}$row[$key]=$v;}
            $data[$id]=$row;
        }
        wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
        wp_cache_delete('tkd_fo_order_responsibility_'.$order,'options');
        $owner=get_option('tkd_fo_order_responsibility_'.$order,false);if(!TKD_Cloud_Operations::bounded_value($owner,$nodes,$bytes)){return self::fail('state_bound');}
        foreach($data as $document=>$row){if($document===$order){continue;}$invoice=$row['type']==='tkd_invoice';if(!$invoice&&$row['type']!=='tkd_delivery'){return self::fail('document_identity');}$link=$row[$invoice?'_tkd_fo_order_id':'_tkd_fo_delivery_order_id'][0]??null;if((!is_string($link)&&!is_int($link))||!preg_match('/\A[1-9][0-9]{0,17}\z/D',(string)$link)||(int)$link!==$order){return self::fail('document_identity');}if(!$invoice){$parent=$row['_tkd_fo_delivery_invoice_id'][0]??null;if((!is_string($parent)&&!is_int($parent))||!preg_match('/\A[1-9][0-9]{0,17}\z/D',(string)$parent)||($data[(int)$parent]['type']??'')!=='tkd_invoice'){return self::fail('document_identity');}}}
        $stock=self::stock_state($data);if($stock instanceof WP_Error){return $stock;}
        $state['native_office_revision']=null;if($op==='delivery.save'){$office=TKD_Warehouse_Scanner::office_revision($payload['delivery_id']);if(!is_string($office)||!preg_match('/\A[a-f0-9]{64}\z/D',$office)){return self::fail('document_schema');}$state['native_office_revision']=$office;}
        $state['documents']=$data;$state['document_owner']=$owner;$state['document_editor_locked']=$locked;
        $state['revision']=hash_hmac('sha256',serialize(array($state['revision'],$data,$owner,$stock,$op,$scope,$digest,$state['native_office_revision'])),wp_salt('nonce'));
        return $state;
    }
    private static function stock_state($documents) {
        global $wpdb;$skus=array();
        foreach($documents as $row){foreach(array('_tkd_fo_order_items','_tkd_fo_invoice_items','_tkd_fo_delivery_items') as $key){$items=$row[$key][0]??array();if($items===''){continue;}if(!is_array($items)||count($items)>100){return self::fail('document_schema');}foreach($items as $item){if(!is_array($item)||!is_string($item['sku']??null)||strlen($item['sku'])>128){return self::fail('document_schema');}$skus[$item['sku']]=true;}}}
        if(count($skus)>50){return self::fail('document_bound');}if(!$skus){return array();}
        $table=$wpdb->prefix.'tkd_fo_stock';if(!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$table)){return self::fail('document_schema');}
        $columns=$wpdb->get_results('SHOW COLUMNS FROM `'.$table.'`',ARRAY_A);$map=array();if(!is_array($columns)){return self::fail('document_schema');}foreach($columns as $c){$map[$c['Field']??'']=$c['Type']??'';}
        foreach(array('id','sku','on_hand','reserved','reserved_supply','incoming','active','updated_at') as $c){if(!isset($map[$c])){return self::fail('document_schema');}}
        foreach(array('on_hand','reserved','reserved_supply','incoming') as $c){if(!preg_match('/\Adecimal\([0-9]+,[0-9]+\)/i',$map[$c])){return self::fail('document_schema');}}
        $result=array();$list=array_keys($skus);sort($list,SORT_STRING);
        foreach($list as $sku){$rows=$wpdb->get_results($wpdb->prepare('SELECT id,sku,on_hand,reserved,reserved_supply,incoming,active,updated_at FROM `'.$table.'` WHERE BINARY sku=BINARY %s ORDER BY id LIMIT 2',$sku),ARRAY_A);if(!is_array($rows)||count($rows)!==1){return self::fail('document_schema');}$result[]=$rows[0];}
        return $result;
    }
    public static function projection($s){
        $out=array();foreach($s['documents'] as $id=>$row){$type=$row['type'];if($type==='tkd_order'){continue;}$invoice=$type==='tkd_invoice';$prefix=$invoice?'_tkd_fo_invoice_':'_tkd_fo_delivery_';
            $entry=array('id'=>$id,'type'=>$type,'order_id'=>$s['base']['id'],'invoice_id'=>$invoice?$id:(int)self::value($s,$id,'_tkd_fo_delivery_invoice_id'));
            foreach(array('number','date','status') as $field){$v=self::value($s,$id,$prefix.$field);if(!is_string($v)||strlen($v)>128||sanitize_text_field($v)!==$v){return self::fail('document_schema');}$entry[$field]=$v;}
            if(!in_array($entry['status'],array('draft','issued','paid','cancelled'),true)||($entry['date']!==''&&!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$entry['date']))){return self::fail('document_schema');}
            foreach(array('seller_key','bank_key') as $field){$v=self::value($s,$id,$invoice?'_tkd_fo_'.$field:'_tkd_fo_delivery_'.$field);if(!$invoice&&$field==='bank_key'){$v=self::value($s,$entry['invoice_id'],'_tkd_fo_bank_key');}if(!is_string($v)||strlen($v)>128||sanitize_text_field($v)!==$v){return self::fail('document_schema');}$entry[$field]=$v;}
            $items=self::value($s,$id,$prefix.'items');if(!is_array($items)||count($items)>100||($items&&array_keys($items)!==range(0,count($items)-1))){return self::fail('document_schema');}$entry['items']=array();
            foreach($items as $index=>$item){if(!is_array($item)){return self::fail('document_schema');}$projected=array('source_index'=>$invoice?$index:($item['source_index']??null));if(!is_int($projected['source_index'])||$projected['source_index']<0){return self::fail('document_schema');}
                foreach(array('sku','name','unit') as $field){$v=$item[$field]??($field==='unit'?'шт':'');if(!is_string($v)||strlen($v)>512||sanitize_text_field($v)!==$v){return self::fail('document_schema');}$projected[$field]=$v;}
                $q=self::quantity($item['quantity']??null);$price=$item['unit_price']??null;if(is_int($price)){$price=(string)$price;}if(is_float($price)&&is_finite($price)){$price=json_encode($price,JSON_PRESERVE_ZERO_FRACTION);}if($q===null||$q<1||!is_string($price)||!preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D',$price)){return self::fail('document_schema');}
                $projected['quantity']=intdiv($q,1000).'.'.str_pad((string)($q%1000),3,'0',STR_PAD_LEFT);$projected['unit_price']=$price;
                $sn=$item['serial_numbers']??'';if(!is_string($sn)||strlen($sn)>16000||preg_match('/[^A-Za-z0-9\r\n,; -]/',$sn)){return self::fail('document_schema');}$projected['serial_numbers']=$sn;$entry['items'][]=$projected;
            }$out[]=$entry;
        }
        $body=json_encode($out);if(!is_string($body)||strlen($body)>131072){return self::fail('document_bound');}return $out;
    }
    public static function valid_facts($op,$facts){
        if(!is_array($facts)||count($facts)!==1){return false;}
        if($op==='reservation.extend'){$v=$facts['reservation']??null;return is_array($v)&&count($v)===2&&!array_diff(array_keys($v),array('deadline','total_hours'))&&is_int($v['deadline'])&&$v['deadline']>0&&$v['deadline']<1000000000000&&is_int($v['total_hours'])&&in_array($v['total_hours'],array(36,48,72),true);}
        if($op==='shipment.issue'){$v=$facts['shipment']??null;return is_array($v)&&count($v)===4&&!array_diff(array_keys($v),array('posted','prepared','dispatch_state','physical_confirmation'))&&($v['posted']===null||is_bool($v['posted']))&&($v['prepared']===null||is_bool($v['prepared']))&&in_array($v['dispatch_state'],array(null,'preparing','awaiting_handover','handed_over'),true)&&$v['physical_confirmation']===false;}return false;
    }
    private static function value($state,$id,$key) { return $state['documents'][$id][$key][0]??''; }
    private static function ownership($p,$before) {
        wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
        wp_cache_delete('tkd_fo_order_responsibility_'.$p['order_id'],'options');
        foreach(array('_tkd_fo_reservation_manager_id','_tkd_fo_reservation_manager_source') as $key){if(get_post_meta($p['order_id'],$key,false)!==($before['documents'][$p['order_id']][$key]??array())){return false;}}
        return get_option('tkd_fo_order_responsibility_'.$p['order_id'],false)===$before['document_owner'];
    }
    public static function preflight($p,$s) {
        if (!defined('REST_REQUEST')||!REST_REQUEST) { return 'rest_context_required'; }
        if($s['document_editor_locked']){return 'editor_locked';}
        $id=$p['order_id'];
        if(in_array($p['operation'],array('invoice.issue','delivery.draft','delivery.save'),true)&&in_array($s['base']['status'],array('cancelled','cancelled_repeat','cancelled_irrelevant','unavailable','returned','refunded','shipped'),true)){return 'closed_order';}
        if(self::value($s,$id,'_tkd_fo_invoice_split_done')==='1'){return 'split_invoice_review_required';}
        if($p['operation']==='reservation.extend'){
            // Native prepare_link is retained; reject installment state before any write.
            if(TKD_FO_Order_Lifecycle::installment($id)){return 'installment_unsupported';}
            $log=self::value($s,$id,'_tkd_fo_reservation_log');if($log!==''&&(!is_array($log)||count($log)>50)){return 'document_schema';}
            $until=self::value($s,$id,'_tkd_fo_reservation_until');
            if(is_string($until)&&preg_match('/\A[0-9]{1,12}\z/D',$until)){$until=(int)$until;}
            $facts=array('stock_state'=>self::value($s,$id,'_tkd_fo_stock_state'),'status'=>$s['base']['status'],'post_status'=>$s['documents'][$id]['status'],'unpaid_verified'=>TKD_FO_Order_Lifecycle::unpaid($id)===true,'confirmed'=>TKD_FO_Order_Lifecycle::confirmed($id)!==false,'protected_stock'=>TKD_FO_Order_Lifecycle::protected_stock($id)!==false,'editor_locked'=>$s['document_editor_locked'],'current_until'=>$until);
            $r=TKD_Cloud_Reservation_Contracts::preflight($p['payload']['hours'],$facts,TKD_FO_Orders::reservation_total_deadline($id,$p['payload']['hours']),time());
            return is_array($r)?true:$r;
        }
        if($p['operation']==='invoice.issue'){
            $invoice=$p['payload']['invoice_id'];
            if(!in_array(self::value($s,$invoice,'_tkd_fo_buyer_type'),array('person','company'),true)){return 'document_schema';}
            foreach(self::BUYER as $field){$v=self::value($s,$invoice,'_tkd_fo_'.$field);if(!is_string($v)||strlen($v)>4096||sanitize_text_field($v)!==$v){return 'document_schema';}}
            if(($s['documents'][$invoice]['type']??'')!=='tkd_invoice'||(int)self::value($s,$invoice,'_tkd_fo_order_id')!==$id||self::value($s,$invoice,'_tkd_fo_invoice_status')!=='draft'){return 'invoice_identity_or_state';}
            if((int)self::value($s,$id,'_tkd_fo_invoice_id')!==$invoice){return 'invoice_identity_or_state';}
        }
        if($p['operation']==='delivery.draft'){
            $invoice=$p['payload']['invoice_id'];
            if(($s['documents'][$invoice]['type']??'')!=='tkd_invoice'||(int)self::value($s,$invoice,'_tkd_fo_order_id')!==$id||!in_array(self::value($s,$invoice,'_tkd_fo_invoice_status'),array('issued','paid'),true)){return 'invoice_identity_or_state';}
            if((int)self::value($s,$id,'_tkd_fo_invoice_id')!==$invoice||self::value($s,$invoice,'_tkd_fo_seller_key')!==self::value($s,$id,'_tkd_fo_checkout_seller_key')){return 'invoice_identity_or_state';}
            $items=self::remaining_items($s,$invoice);return is_array($items)&&$items?true:'delivery_items_unverified';
        }
        if($p['operation']==='delivery.save'){ $candidate=self::delivery_candidate($s,$p['payload']['delivery_id'],$p['payload']['items']);return is_array($candidate)?true:'delivery_items_unverified'; }
        if($p['operation']==='shipment.issue'){
            $delivery=$p['payload']['delivery_id'];$invoice=(int)self::value($s,$delivery,'_tkd_fo_delivery_invoice_id');
            if(($s['documents'][$delivery]['type']??'')!=='tkd_delivery'||(int)self::value($s,$delivery,'_tkd_fo_delivery_order_id')!==$id||($s['documents'][$invoice]['type']??'')!=='tkd_invoice'||(int)self::value($s,$invoice,'_tkd_fo_order_id')!==$id||self::value($s,$delivery,'_tkd_fo_delivery_status')!=='draft'){return 'delivery_identity_or_state';}
        }
        return true;
    }
    private static function serials($value){$tokens=preg_split('/[\r\n,;]+/',$value);$out=array();foreach($tokens as $token){$token=trim($token);if($token===''){continue;}if(in_array($token,$out,true)){return null;}$out[]=$token;}return implode("\n",$out);}
    private static function price_minor($value){if(is_int($value)){$value=(string)$value;}if(is_float($value)&&is_finite($value)){$value=json_encode($value,JSON_PRESERVE_ZERO_FRACTION);}if(!is_string($value)||!preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D',$value)){return null;}$parts=explode('.',$value,2);return (int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');}
    private static function quantity($v) {
        if(is_float($v)&&is_finite($v)){$v=json_encode($v,JSON_PRESERVE_ZERO_FRACTION);}if(is_int($v)){$v=(string)$v;}
        if(!is_string($v)||!preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,3})?\z/D',$v)){return null;}$p=explode('.',$v,2);return (int)$p[0]*1000+(int)str_pad($p[1]??'',3,'0');
    }
    public static function remaining_items($s,$invoice) {
        $source=self::value($s,$invoice,'_tkd_fo_invoice_items');if(!is_array($source)||!$source||count($source)>100||array_keys($source)!==range(0,count($source)-1)){return null;}$allocated=array();
        foreach($s['documents'] as $doc=>$row){if(($row['type']??'')!=='tkd_delivery'||(int)self::value($s,$doc,'_tkd_fo_delivery_invoice_id')!==$invoice||self::value($s,$doc,'_tkd_fo_delivery_status')==='cancelled'){continue;}
            $items=self::value($s,$doc,'_tkd_fo_delivery_items');if(!is_array($items)||count($items)>100){return null;}
            foreach($items as $item){$index=$item['source_index']??null;$q=self::quantity($item['quantity']??null);if(!is_int($index)||!isset($source[$index])||$q===null||$q<1){return null;}$allocated[$index]=($allocated[$index]??0)+$q;}
        }
        $out=array();foreach($source as $index=>$row){if(!is_array($row)){return null;}$q=self::quantity($row['quantity']??null);if($q===null||$q<1||($allocated[$index]??0)>$q){return null;}$left=$q-($allocated[$index]??0);if(!$left){continue;}
            foreach(array('sku','name','unit') as $key){if(!is_string($row[$key]??null)||strlen($row[$key])>512||sanitize_text_field($row[$key])!==$row[$key]){return null;}}
            $price=$row['unit_price']??null;if(is_float($price)&&is_finite($price)){$price=json_encode($price,JSON_PRESERVE_ZERO_FRACTION);}if(is_int($price)){$price=(string)$price;}if(!is_string($price)||!preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D',$price)){return null;}
            $out[]=array('source_index'=>$index,'sku'=>$row['sku'],'name'=>$row['name'],'unit'=>$row['unit'],'quantity'=>intdiv($left,1000).'.'.str_pad((string)($left%1000),3,'0',STR_PAD_LEFT),'unit_price'=>$price);
        }return $out;
    }
    // Fixed source-pinned extraction of native create_from_invoice without its
    // redirect/exit. Native namespace/sequence retained; no arbitrary fields/SQL.
    private static function create_delivery($p,$s){
        global $wpdb;$invoice=$p['payload']['invoice_id'];$seller=self::value($s,$invoice,'_tkd_fo_seller_key');
        if(!in_array($seller,array('no_vat','fop_pm','fop_rv','fop_tp','fop_md','fop_eo','vat'),true)){throw new RuntimeException('seller');}
        $items=self::remaining_items($s,$invoice);if(!$items){throw new RuntimeException('allocation');}
        $date=wp_date('Y-m-d');$year=wp_date('Y');$key='tkd_fo_delivery_sequence_'.$seller.'_'.$year;
        $lock='tkd_cloud_delivery_'.substr(hash('sha256',$wpdb->prefix.'|'.$key),0,40);
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!=='1'){throw new RuntimeException('number_lock');}
        try{
            $next=get_option($key,0);if((!is_int($next)&&(!is_string($next)||!preg_match('/\A[0-9]{1,9}\z/D',$next)))||(int)$next<0||(int)$next>999999999){throw new RuntimeException('number_schema');}$next=(int)$next+1;
            $number='';for($i=0;$i<100;$i++,$next++){$candidate=$year.'-'.str_pad((string)$next,4,'0',STR_PAD_LEFT);$dupes=get_posts(array('post_type'=>'tkd_delivery','post_status'=>'any','posts_per_page'=>2,'fields'=>'ids','meta_query'=>array(array('key'=>'_tkd_fo_delivery_seller_key','value'=>$seller),array('key'=>'_tkd_fo_delivery_number','value'=>$candidate))));if(!is_array($dupes)){throw new RuntimeException('number_query');}if(!$dupes){$number=$candidate;break;}}
            if($number===''){throw new RuntimeException('number_bound');}update_option($key,$next,false);if((int)get_option($key)!==$next){throw new RuntimeException('sequence_readback');}
            $id=wp_insert_post(array('post_type'=>'tkd_delivery','post_status'=>'publish','post_title'=>'Видаткова накладна '.$number),true);if(is_wp_error($id)||!is_int($id)||$id<1){throw new RuntimeException('insert');}
            $buyer=self::value($s,$invoice,'_tkd_fo_buyer_name');$receiver=self::value($s,$invoice,'_tkd_fo_receiver');$payer=self::value($s,$invoice,'_tkd_fo_payer');$app=self::value($s,$invoice,'_tkd_fo_application');
            $values=array('invoice_id'=>$invoice,'order_id'=>$p['order_id'],'seller_key'=>$seller,'number'=>$number,'date'=>$date,'place'=>'м. Дніпро','status'=>'draft','receiver'=>$receiver?:$buyer,'payer'=>$payer?:$buyer,'contract'=>self::value($s,$invoice,'_tkd_fo_contract'),'application'=>$app?:$p['number'],'items'=>$items);
            foreach($values as $key=>$value){update_post_meta($id,'_tkd_fo_delivery_'.$key,$value);if(is_scalar($value) ? (!is_scalar(get_post_meta($id,'_tkd_fo_delivery_'.$key,true)) || (string)get_post_meta($id,'_tkd_fo_delivery_'.$key,true)!==(string)$value) : get_post_meta($id,'_tkd_fo_delivery_'.$key,true)!==$value){throw new RuntimeException('delivery_readback');}}
            $dupes=get_posts(array('post_type'=>'tkd_delivery','post_status'=>'any','posts_per_page'=>2,'fields'=>'ids','meta_query'=>array(array('key'=>'_tkd_fo_delivery_seller_key','value'=>$seller),array('key'=>'_tkd_fo_delivery_number','value'=>$number))));if(!is_array($dupes)||array_map('intval',$dupes)!==array($id)){throw new RuntimeException('number_ambiguous');}
            TKD_FO_Orders::claim_responsibility($p['order_id'],'Створено видаткову № '.$number);
            return self::ownership($p,$s)?array('complete','document_verified',$id):array('unknown','native_effect_unverified',$id);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private static function delivery_candidate($s,$delivery,$rows){
        if(($s['documents'][$delivery]['type']??'')!=='tkd_delivery'||self::value($s,$delivery,'_tkd_fo_delivery_status')!=='draft'||self::value($s,$delivery,'_tkd_fo_delivery_handover_post_v1')!==''||!in_array(self::value($s,$delivery,'_tkd_fo_delivery_stock_journal'),array('',array()),true)){return null;}
        $invoice=(int)self::value($s,$delivery,'_tkd_fo_delivery_invoice_id');$order=$s['base']['id'];
        if((int)self::value($s,$delivery,'_tkd_fo_delivery_order_id')!==$order||($s['documents'][$invoice]['type']??'')!=='tkd_invoice'||(int)self::value($s,$invoice,'_tkd_fo_order_id')!==$order){return null;}
        foreach(array('number','date','place','receiver','payer','contract','application','seller_key') as $field){$v=self::value($s,$delivery,'_tkd_fo_delivery_'.$field);if(!is_string($v)||strlen($v)>4096||sanitize_text_field($v)!==$v){return null;}}
        $copy=$s;unset($copy['documents'][$delivery]);$remaining=self::remaining_items($copy,$invoice);if(!is_array($remaining)){return null;}$available=array();foreach($remaining as $row){$available[$row['source_index']]=$row;}
        $out=array();foreach($rows as $row){$index=$row['source_index'];if(!isset($available[$index])||self::quantity($row['quantity'])>self::quantity($available[$index]['quantity'])){return null;}$item=$available[$index];$item['quantity']=$row['quantity'];$serials=self::serials($row['serial_numbers']);if($serials===null){return null;}$item['serial_numbers']=$serials;$out[]=$item;}return $out;
    }
    private static function invoice_reserve_verified($id,$before){
        wp_cache_delete($id,'post_meta');$start=get_post_meta($id,'_tkd_fo_reservation_started_at',true);$until=get_post_meta($id,'_tkd_fo_reservation_until',true);$rows=get_post_meta($id,'_tkd_fo_reserved_items',true);
        if((!is_int($start)&&!is_string($start))||(!is_int($until)&&!is_string($until))||!preg_match('/\A[1-9][0-9]{0,11}\z/D',(string)$start)||!preg_match('/\A[1-9][0-9]{0,11}\z/D',(string)$until)){return false;}
        if(!self::reserve_quantities_verified($id,$before,$rows)){return false;}
        $prior_stage=self::value($before,$id,'_tkd_fo_reservation_stage');
        $preserved=in_array($prior_stage,array('extended','retry'),true)&&self::value($before,$id,'_tkd_fo_stock_state')==='reserved';
        $timing=$preserved ? ((string)$start===(string)self::value($before,$id,'_tkd_fo_reservation_started_at')&&(string)$until===(string)self::value($before,$id,'_tkd_fo_reservation_until')&&get_post_meta($id,'_tkd_fo_reservation_stage',true)===$prior_stage) : (is_scalar($start)&&is_scalar($until)&&(int)$until===(int)$start+86400);
        return $timing&&(is_int($start)||is_string($start))&&(is_int($until)||is_string($until))&&preg_match('/\A[1-9][0-9]{0,11}\z/D',(string)$start)&&preg_match('/\A[1-9][0-9]{0,11}\z/D',(string)$until)&&(int)$start<=time()&&(int)$until>time()&&get_post_meta($id,'_tkd_status',true)==='invoice_pending'&&get_post_meta($id,'_tkd_fo_stock_state',true)==='reserved'&&get_post_meta($id,'_tkd_fo_stock_error',true)===''&&is_array($rows)&&count($rows)>0&&count($rows)<=100;
    }
    private static function reserve_quantities_verified($id,$before,$reserved){
        $items=get_post_meta($id,'_tkd_fo_order_items',true);if(!is_array($items)||!$items||count($items)>100||!is_array($reserved)||!$reserved||count($reserved)>100){return false;}$expected=array();
        foreach($items as $item){$sku=$item['sku']??null;$qty=self::quantity($item['quantity']??null);if(!is_string($sku)||$sku===''||strlen($sku)>128||$qty===null||$qty<1){return false;}$expected[$sku]=($expected[$sku]??0)+$qty;}
        $docs=$before['documents'];$docs[$id]['_tkd_fo_order_items']=array($items);$stock=self::stock_state($docs);if($stock instanceof WP_Error){return false;}$map=array();foreach($stock as $row){$map[(string)$row['id']]=$row;}$actual=array();
        foreach($reserved as $entry){if(!is_array($entry)){return false;}$item=$entry['item_id']??null;$qty=self::quantity($entry['quantity']??null);if((!is_int($item)&&!is_string($item))||!preg_match('/\A[1-9][0-9]{0,17}\z/D',(string)$item)||!isset($map[(string)$item])||$qty===null||$qty<1){return false;}$sku=$map[(string)$item]['sku'];$actual[$sku]=($actual[$sku]??0)+$qty;}
        ksort($actual);ksort($expected);if($actual!==$expected){return false;}
        foreach($stock as $row){$q=$expected[$row['sku']]??0;if(!$q){continue;}if($q>999999999999999||!is_string($row['reserved'])||!preg_match('/\A([0-9]{1,12})(?:\.([0-9]{1,6}))?\z/D',$row['reserved'],$m)){return false;}$amount=(int)$m[1]*1000000+(int)str_pad($m[2]??'',6,'0');if($amount<$q*1000){return false;}}return true;
    }
    private static function journal_map($rows){if(!is_array($rows)||!$rows||count($rows)>100){return null;}$out=array();foreach($rows as $row){if(!is_array($row)){return null;}$id=$row['item_id']??null;$q=self::quantity($row['quantity']??null);if((!is_int($id)&&!is_string($id))||!preg_match('/\A[1-9][0-9]{0,17}\z/D',(string)$id)||$q===null||$q<1){return null;}$out[(string)$id]=($out[(string)$id]??0)+$q;}ksort($out,SORT_STRING);return $out;}
    private static function shipment_persisted($id,$order,$invoice,$mode,$before_items){
        wp_cache_delete($id,'post_meta');$intent=get_post_meta($id,'_tkd_fo_delivery_handover_post_v1',true);$journal=get_post_meta($id,'_tkd_fo_delivery_stock_journal',true);$items=get_post_meta($id,'_tkd_fo_delivery_items',true);$at=get_post_meta($id,'_tkd_fo_delivery_stock_processed_at',true);$nodes=0;$bytes=0;
        if(!TKD_Cloud_Operations::bounded_value(array($intent,$journal,$items),$nodes,$bytes)||get_post_meta($id,'_tkd_fo_delivery_status',true)!=='issued'||!is_array($intent)||($intent['version']??null)!==1||($intent['state']??'')!=='committed'||($intent['notification']??'')!=='completed'||!is_array($journal)||!$journal||count($journal)>100||(self::journal_map($journal)===null||self::journal_map($journal)!==self::journal_map($intent['plan']['journal']??null))||!is_array($items)||!$items||$items!==$before_items||$items!==($intent['snapshot']['delivery_fields']['items']??null)||($intent['snapshot']['delivery']??null)!==$id||($intent['snapshot']['order']??null)!==$order||($intent['snapshot']['invoice']??null)!==$invoice||!is_string($at)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$at)||($intent['processed_at']??null)!==$at||get_post_meta($order,'_tkd_fo_stock_error',true)!==''){return false;}
        $record=get_post_meta($id,$mode==='np'?'_tkd_np_dispatch_v1':'_tkd_fo_dispatch_v1',true);
        if(!is_array($record)||($record['version']??null)!==1||($record['order']??null)!==$order||!in_array($record['state']??'',array('awaiting_handover','handed_over'),true)){return false;}
        $status=$mode==='np'?TKD_NP_Dispatch::status($id):TKD_FO_Dispatch::status($id);
        return is_array($status)&&($status['enabled']??false)===true&&($status['state']??null)===$record['state']&&isset($status['blockers'])&&$status['blockers']===array();
    }
    private static function log_reservation($id,$label,$until){
        $log=get_post_meta($id,'_tkd_fo_reservation_log',true);if($log===''){$log=array();}
        if(!is_array($log)||count($log)>50){throw new RuntimeException('log_schema');}
        $entry=array('time'=>time(),'user_id'=>get_current_user_id(),'action'=>sanitize_text_field($label),'note'=>'','until'=>$until);$log[]=$entry;$log=array_slice($log,-50);
        update_post_meta($id,'_tkd_fo_reservation_log',$log);
        foreach(array('action'=>'','at'=>'time','user'=>'user_id') as $suffix=>$field){update_post_meta($id,'_tkd_fo_reservation_last_action'.($suffix==='action'?'':'_'.$suffix),$entry[$field===''?'action':$field]);}
        return $log===get_post_meta($id,'_tkd_fo_reservation_log',true)&&$entry['action']===(string)get_post_meta($id,'_tkd_fo_reservation_last_action',true)&&$entry['time']===(int)get_post_meta($id,'_tkd_fo_reservation_last_action_at',true)&&$entry['user_id']===(int)get_post_meta($id,'_tkd_fo_reservation_last_action_user',true);
    }
    private static function form($posted,$callback){
        $old=$_POST;$request=$_REQUEST;$die=static function(){return static function(){throw new RuntimeException('native_die');};};
        add_filter('wp_die_handler',$die,PHP_INT_MAX);add_filter('wp_die_ajax_handler',$die,PHP_INT_MAX);add_filter('wp_die_json_handler',$die,PHP_INT_MAX);
        try{$_POST=wp_slash($posted);$_REQUEST=$_POST;return $callback();}
        finally{$_POST=$old;$_REQUEST=$request;remove_filter('wp_die_handler',$die,PHP_INT_MAX);remove_filter('wp_die_ajax_handler',$die,PHP_INT_MAX);remove_filter('wp_die_json_handler',$die,PHP_INT_MAX);}
    }
    public static function invoke($p,$s){
        $id=$p['order_id'];$op=$p['operation'];
        if($op==='delivery.save'){
            $delivery=$p['payload']['delivery_id'];$items=self::delivery_candidate($s,$delivery,$p['payload']['items']);if(!$items){return array('unknown','native_effect_unverified');}
            $office=$s['native_office_revision'];if(!is_string($office)||!preg_match('/\A[a-f0-9]{64}\z/D',$office)){return array('unknown','native_effect_unverified');}
            $posted=array('tkd_fo_delivery_nonce'=>wp_create_nonce('tkd_fo_save_delivery'),'tkd_ws_office_revision'=>$office,'tkd_fo_delivery_status'=>'draft','tkd_fo_delivery_items'=>$items);
            foreach(array('number','date','place','receiver','payer','contract','application') as $field){$posted['tkd_fo_delivery_'.$field]=self::value($s,$delivery,'_tkd_fo_delivery_'.$field);}
            self::form($posted,static function()use($delivery){TKD_FO_Deliveries::save_delivery($delivery,get_post($delivery),true);});
            $good=get_post_meta($delivery,'_tkd_fo_delivery_status',true)==='draft'&&self::ownership($p,$s);$actual=get_post_meta($delivery,'_tkd_fo_delivery_items',true);
            if(!is_array($actual)||count($actual)!==count($items)){$good=false;}else{foreach($items as $i=>$expected){$got=$actual[$i];foreach(array('source_index','sku','name','unit','serial_numbers') as $field){$good=$good&&($got[$field]??null)===$expected[$field];}$good=$good&&self::quantity($got['quantity']??null)===self::quantity($expected['quantity'])&&self::price_minor($got['unit_price']??null)!==null&&self::price_minor($got['unit_price'])===self::price_minor($expected['unit_price']);}}
            foreach(array('number','date','place','receiver','payer','contract','application','seller_key') as $field){$good=$good&&get_post_meta($delivery,'_tkd_fo_delivery_'.$field,true)===self::value($s,$delivery,'_tkd_fo_delivery_'.$field);}
            return $good?array('complete','document_verified',$delivery):array('unknown','native_effect_unverified',$delivery);
        }
        if($op==='delivery.draft'){return self::create_delivery($p,$s);}
        if($op==='reservation.extend'){
            $until=TKD_FO_Orders::reservation_total_deadline($id,$p['payload']['hours']);if(!is_int($until)||$until<=time()){return array('unknown','native_effect_unverified');}
            $label='Загальний строк броні: '.$p['payload']['hours'].' год від початку. До '.wp_date('d.m.Y H:i',$until).'.';
            update_post_meta($id,'_tkd_fo_reservation_stage','extended');update_post_meta($id,'_tkd_fo_reservation_until',$until);
            if(get_post_meta($id,'_tkd_fo_reservation_stage',true)!=='extended'||(int)get_post_meta($id,'_tkd_fo_reservation_until',true)!==$until||!self::log_reservation($id,$label,$until)){return array('unknown','native_effect_unverified');}
            // Native REST context intentionally leaves current ownership unchanged.
            TKD_FO_Orders::claim_responsibility($id,(string)get_post_meta($id,'_tkd_fo_reservation_last_action',true));
            $ok=get_post_meta($id,'_tkd_fo_reservation_stage',true)==='extended'&&(int)get_post_meta($id,'_tkd_fo_reservation_until',true)===$until&&(string)get_post_meta($id,'_tkd_fo_reservation_last_action',true)===$label;
            return $ok&&self::ownership($p,$s)?array('complete','document_verified',null,null,array('reservation'=>array('deadline'=>$until,'total_hours'=>$p['payload']['hours']))):array('unknown','native_effect_unverified');
        }
        if($op==='invoice.draft'){
            $invoice=TKD_FO_Invoices::ensure_manager_draft($id);
            if(is_wp_error($invoice)||!is_int($invoice)||$invoice<1){return array('unknown','native_effect_unverified');}
            $good=get_post_type($invoice)==='tkd_invoice'&&(int)get_post_meta($invoice,'_tkd_fo_order_id',true)===$id&&(int)get_post_meta($id,'_tkd_fo_invoice_id',true)===$invoice&&in_array(get_post_meta($invoice,'_tkd_fo_invoice_status',true),array('draft','issued','paid'),true)&&self::ownership($p,$s);
            return $good?array('complete','document_verified',$invoice):array('unknown','native_effect_unverified',$invoice);
        }
        if($op==='invoice.issue'){
            $invoice=$p['payload']['invoice_id'];$posted=array('tkd_fo_invoice_nonce'=>wp_create_nonce('tkd_fo_save_invoice'),'tkd_fo_seller_key'=>self::value($s,$invoice,'_tkd_fo_seller_key'),'tkd_fo_bank_key'=>self::value($s,$invoice,'_tkd_fo_bank_key'),'tkd_fo_invoice_number'=>$p['payload']['number'],'tkd_fo_invoice_date'=>$p['payload']['date'],'tkd_fo_invoice_status'=>'issued','tkd_fo_buyer_type'=>self::value($s,$invoice,'_tkd_fo_buyer_type'));
            foreach(self::BUYER as $field){$v=self::value($s,$invoice,'_tkd_fo_'.$field);if(!is_string($v)||strlen($v)>4096||sanitize_text_field($v)!==$v){throw new RuntimeException('buyer_schema');}$posted['tkd_fo_'.$field]=$v;}
            self::form($posted,static function()use($invoice){TKD_FO_Invoices::save_invoice($invoice,get_post($invoice),true);});
            $good=get_post_type($invoice)==='tkd_invoice'&&get_post_meta($invoice,'_tkd_fo_invoice_date',true)===$p['payload']['date']&&get_post_meta($invoice,'_tkd_fo_invoice_status',true)==='issued'&&get_post_meta($invoice,'_tkd_fo_invoice_number',true)===$p['payload']['number']&&(int)get_post_meta($invoice,'_tkd_fo_order_id',true)===$id&&self::ownership($p,$s);
            foreach(array('_tkd_fo_seller_key','_tkd_fo_bank_key','_tkd_fo_invoice_items','_tkd_fo_invoice_items_subtotal','_tkd_fo_invoice_discount','_tkd_fo_invoice_subtotal','_tkd_fo_invoice_vat','_tkd_fo_invoice_total') as $key){$good=$good&&get_post_meta($invoice,$key,true)===self::value($s,$invoice,$key);}
            $good=$good&&get_post_meta($invoice,'_tkd_fo_buyer_type',true)===self::value($s,$invoice,'_tkd_fo_buyer_type');
            foreach(self::BUYER as $field){$good=$good&&get_post_meta($invoice,'_tkd_fo_'.$field,true)===self::value($s,$invoice,'_tkd_fo_'.$field);}
            $reserved=$good&&self::invoice_reserve_verified($id,$s);
            return !$good?array('unknown','native_effect_unverified',$invoice):($reserved?array('complete','document_verified',$invoice,true):array('recorded_needs_review','document_needs_review',$invoice,false));
        }
        $delivery=$p['payload']['delivery_id'];$native=TKD_Sales_Release::manager_snapshot($delivery);
        $invoice=(int)self::value($s,$delivery,'_tkd_fo_delivery_invoice_id');$projection=TKD_Cloud_Document_Contracts::release_snapshot($native,$id,$invoice,$delivery);
        if(!$projection||$projection['blocked']){return array('unknown','native_effect_unverified');}
        $posted=array('command'=>'prepare_saved','tkd_release_nonce'=>wp_create_nonce('tkd_sales_release_'.$delivery),'_wpnonce'=>wp_create_nonce(($projection['mode']==='pickup'?'tkd_fo_dispatch_':'tkd_np_dispatch_').$delivery));
        $result=self::form($posted,static function()use($delivery,$native){return TKD_Sales_Release::execute_inline($delivery,$native['revision'],'prepare_saved');});
        // Preserve posted proof but do not infer physical carrier acceptance or complete downstream verification.
        $verified=is_array($result)&&($result['posted']??null)===true&&($result['prepared']??null)===true&&self::shipment_persisted($delivery,$id,$invoice,$projection['mode'],self::value($s,$delivery,'_tkd_fo_delivery_items'));
        $out=TKD_Cloud_Document_Contracts::release_outcome($result,$verified);
                $record=get_post_meta($delivery,$projection['mode']==='np'?'_tkd_np_dispatch_v1':'_tkd_fo_dispatch_v1',true);$stage=is_array($record)&&in_array($record['state']??'',array('preparing','awaiting_handover','handed_over'),true)?$record['state']:null;
        $facts=array('shipment'=>array('posted'=>$out['posted'],'prepared'=>$out['prepared'],'dispatch_state'=>$stage,'physical_confirmation'=>false));
        return self::ownership($p,$s)&&$out['posted']===true?($out['state']==='complete'?array('complete','document_verified',$delivery,null,$facts):array('recorded_needs_review','document_needs_review',$delivery,null,$facts)):array('unknown','native_effect_unverified',$delivery,null,$facts);
    }
}
