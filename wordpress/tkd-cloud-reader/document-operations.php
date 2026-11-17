<?php
if (!defined('ABSPATH')) { exit; }
/** Fixed document domain operations, invoked only inside gateway durable journal. */
final class TKD_Cloud_Document_Operations {
    private static $profile = null;
    private static $field_profile = null;
    private static $source_cache = array();
    private static $reflection_cache = array();
    const CAPS = array('invoice.draft'=>'tkd_cloud_invoice','invoice.issue'=>'tkd_cloud_invoice','reservation.extend'=>'tkd_cloud_extend_reservation','delivery.draft'=>'tkd_cloud_delivery_draft','shipment.issue'=>'tkd_cloud_issue_delivery');
    const BUYER = array('buyer_name','buyer_tax_id','buyer_vat_id','buyer_address','buyer_phone','buyer_email','receiver','payer','contract','application');
    public static function supports($op) { return is_string($op) && isset(self::CAPS[$op]); }
    public static function validate($op,$p) {
        if (!is_array($p)) { return false; }
        if ($op==='invoice.draft') { return $p===array(); }
        if ($op==='reservation.extend') { return count($p)===1 && isset($p['hours']) && is_int($p['hours']) && in_array($p['hours'],array(36,48,72),true); }
        if ($op==='delivery.draft') { return count($p)===1 && isset($p['invoice_id']) && is_int($p['invoice_id']) && $p['invoice_id']>0; }
        if ($op==='shipment.issue') { return count($p)===1 && isset($p['delivery_id']) && is_int($p['delivery_id']) && $p['delivery_id']>0; }
        if ($op==='invoice.issue') {
            return count($p)===3 && !array_diff(array_keys($p),array('invoice_id','number','date')) && is_int($p['invoice_id']) && $p['invoice_id']>0 && is_string($p['number']) && preg_match('/\A[A-Za-z0-9А-Яа-яІіЇїЄє._\/-]{1,64}\z/uD',$p['number']) && is_string($p['date']) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$p['date']) && ($d=DateTimeImmutable::createFromFormat('!Y-m-d',$p['date'])) && $d->format('Y-m-d')===$p['date'];
        }
        return false;
    }
    private static function fail($r) { return new WP_Error('tkd_operation_'.$r,'Document operation refused.',array('status'=>503)); }
    public static function pins($op) {
        if (!self::supports($op)) { return array('verified'=>false,'reason'=>'unsupported_operation'); }
        $pins=self::$profile ?? require __DIR__.'/document-pins.php'; $fields=self::$field_profile ?? require __DIR__.'/document-field-pins.php'; $cache=array(); $checked=array();
        foreach ($pins as $key=>$spec) {
            list($class,$name)=explode('::',$key);
            if ($op==='reservation.extend' && !in_array($class,array('TKD_FO_Orders','TKD_FO_Order_Lifecycle'),true)) { continue; }
            if ($op!=='reservation.extend' && $name==='handle_reservation_action_locked') { continue; }
            if (strpos($op,'invoice.')===0 && !in_array($class,array('TKD_FO_Invoices','TKD_FO_Orders','TKD_FO_Order_Lifecycle','TKD_FO_Multi_Seller'),true)) { continue; }
            if (!class_exists($class,false)) { return array('verified'=>false,'reason'=>'native_missing','method'=>$key); }
            $ref=self::$reflection_cache[$class]??(self::$reflection_cache[$class]=new ReflectionClass($class)); if (!$ref->hasMethod($name)) { return array('verified'=>false,'reason'=>'native_missing','method'=>$key); }
            $m=$ref->getMethod($name); $file=$m->getFileName();
            if (!$m->isStatic() || ($spec[0]==='public'?!$m->isPublic():($spec[0]==='protected'?!$m->isProtected():!$m->isPrivate())) || $m->getDeclaringClass()->getName()!==$class) { return array('verified'=>false,'reason'=>'native_visibility_mismatch','method'=>$key); }
            if (!is_string($file)||!is_file($file)||filesize($file)>2097152) { return array('verified'=>false,'reason'=>'native_source_unavailable','method'=>$key); }
            if (!isset($cache[$file])) {
                $file_hash=hash_file('sha256',$file);if(!is_string($file_hash)){return array('verified'=>false,'reason'=>'native_source_unavailable','method'=>$key);}
                if(!isset(self::$source_cache[$file])||self::$source_cache[$file]['hash']!==$file_hash){self::$source_cache[$file]=array('hash'=>$file_hash,'lines'=>file($file));}
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
    public static function augment($state,$op,$payload) {
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
        wp_cache_delete('tkd_fo_order_responsibility_'.$order,'options');
        $owner=get_option('tkd_fo_order_responsibility_'.$order,false);if(!TKD_Cloud_Operations::bounded_value($owner,$nodes,$bytes)){return self::fail('state_bound');}
        $stock=self::stock_state($data);if($stock instanceof WP_Error){return $stock;}
        $state['documents']=$data;$state['document_owner']=$owner;$state['document_editor_locked']=$locked;
        $state['revision']=hash_hmac('sha256',serialize(array($state['revision'],$data,$owner,$stock,$op,$payload)),wp_salt('nonce'));
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
    private static function value($state,$id,$key) { return $state['documents'][$id][$key][0]??''; }
    private static function ownership($p,$before) {
        wp_cache_delete('tkd_fo_order_responsibility_'.$p['order_id'],'options');
        foreach(array('_tkd_fo_reservation_manager_id','_tkd_fo_reservation_manager_source') as $key){if(get_post_meta($p['order_id'],$key,false)!==($before['documents'][$p['order_id']][$key]??array())){return false;}}
        return get_option('tkd_fo_order_responsibility_'.$p['order_id'],false)===$before['document_owner'];
    }
    public static function preflight($p,$s) {
        if (!defined('REST_REQUEST')||!REST_REQUEST) { return 'rest_context_required'; }
        if($s['document_editor_locked']){return 'editor_locked';}
        $id=$p['order_id'];
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
        if($p['operation']==='shipment.issue'){
            $delivery=$p['payload']['delivery_id'];$invoice=(int)self::value($s,$delivery,'_tkd_fo_delivery_invoice_id');
            if(($s['documents'][$delivery]['type']??'')!=='tkd_delivery'||(int)self::value($s,$delivery,'_tkd_fo_delivery_order_id')!==$id||($s['documents'][$invoice]['type']??'')!=='tkd_invoice'||(int)self::value($s,$invoice,'_tkd_fo_order_id')!==$id||self::value($s,$delivery,'_tkd_fo_delivery_status')!=='draft'){return 'delivery_identity_or_state';}
        }
        return true;
    }
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
    private static function log_reservation($id,$label,$until){
        $log=get_post_meta($id,'_tkd_fo_reservation_log',true);if($log===''){$log=array();}
        if(!is_array($log)||count($log)>50){throw new RuntimeException('log_schema');}
        $entry=array('time'=>time(),'user_id'=>get_current_user_id(),'action'=>sanitize_text_field($label),'note'=>'','until'=>$until);$log[]=$entry;$log=array_slice($log,-50);
        update_post_meta($id,'_tkd_fo_reservation_log',$log);
        foreach(array('action'=>'','at'=>'time','user'=>'user_id') as $suffix=>$field){update_post_meta($id,'_tkd_fo_reservation_last_action'.($suffix==='action'?'':'_'.$suffix),$entry[$field===''?'action':$field]);}
        return $log===get_post_meta($id,'_tkd_fo_reservation_log',true)&&$entry['action']===(string)get_post_meta($id,'_tkd_fo_reservation_last_action',true)&&$entry['time']===(int)get_post_meta($id,'_tkd_fo_reservation_last_action_at',true)&&$entry['user_id']===(int)get_post_meta($id,'_tkd_fo_reservation_last_action_user',true);
    }
    private static function form($posted,$callback){
        $old=$_POST;$die=static function(){return static function(){throw new RuntimeException('native_die');};};
        add_filter('wp_die_handler',$die,PHP_INT_MAX);add_filter('wp_die_ajax_handler',$die,PHP_INT_MAX);add_filter('wp_die_json_handler',$die,PHP_INT_MAX);
        try{$_POST=wp_slash($posted);return $callback();}
        finally{$_POST=$old;remove_filter('wp_die_handler',$die,PHP_INT_MAX);remove_filter('wp_die_ajax_handler',$die,PHP_INT_MAX);remove_filter('wp_die_json_handler',$die,PHP_INT_MAX);}
    }
    public static function invoke($p,$s){
        $id=$p['order_id'];$op=$p['operation'];
        if($op==='delivery.draft'){return self::create_delivery($p,$s);}
        if($op==='reservation.extend'){
            $until=TKD_FO_Orders::reservation_total_deadline($id,$p['payload']['hours']);if(!is_int($until)||$until<=time()){return array('unknown','native_effect_unverified');}
            $label='Загальний строк броні: '.$p['payload']['hours'].' год від початку. До '.wp_date('d.m.Y H:i',$until).'.';
            update_post_meta($id,'_tkd_fo_reservation_stage','extended');update_post_meta($id,'_tkd_fo_reservation_until',$until);
            if(get_post_meta($id,'_tkd_fo_reservation_stage',true)!=='extended'||(int)get_post_meta($id,'_tkd_fo_reservation_until',true)!==$until||!self::log_reservation($id,$label,$until)){return array('unknown','native_effect_unverified');}
            // Native REST context intentionally leaves current ownership unchanged.
            TKD_FO_Orders::claim_responsibility($id,(string)get_post_meta($id,'_tkd_fo_reservation_last_action',true));
            $ok=get_post_meta($id,'_tkd_fo_reservation_stage',true)==='extended'&&(int)get_post_meta($id,'_tkd_fo_reservation_until',true)===$until&&(string)get_post_meta($id,'_tkd_fo_reservation_last_action',true)===$label;
            return $ok&&self::ownership($p,$s)?array('complete','document_verified'):array('unknown','native_effect_unverified');
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
            foreach(self::BUYER as $field){$good=$good&&get_post_meta($invoice,'_tkd_fo_'.$field,true)===self::value($s,$invoice,'_tkd_fo_'.$field);}
            return $good?array('complete','document_verified',$invoice):array('unknown','native_effect_unverified',$invoice);
        }
        $delivery=$p['payload']['delivery_id'];$native=TKD_Sales_Release::manager_snapshot($delivery);
        $invoice=(int)self::value($s,$delivery,'_tkd_fo_delivery_invoice_id');$projection=TKD_Cloud_Document_Contracts::release_snapshot($native,$id,$invoice,$delivery);
        if(!$projection||$projection['blocked']){return array('unknown','native_effect_unverified');}
        $posted=array('command'=>'prepare_saved','tkd_release_nonce'=>wp_create_nonce('tkd_sales_release_'.$delivery),'_wpnonce'=>wp_create_nonce(($projection['mode']==='pickup'?'tkd_fo_dispatch_':'tkd_np_dispatch_').$delivery));
        $result=self::form($posted,static function()use($delivery,$native){return TKD_Sales_Release::execute_inline($delivery,$native['revision'],'prepare_saved');});
        // Preserve posted proof but do not infer physical carrier acceptance or complete downstream verification.
        $out=TKD_Cloud_Document_Contracts::release_outcome($result,false);
        return self::ownership($p,$s)&&$out['posted']===true?array('recorded_needs_review','document_needs_review',$delivery):array('unknown','native_effect_unverified',$delivery);
    }
}
