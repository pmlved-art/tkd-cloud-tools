<?php
if (!defined('ABSPATH')) { exit; }
/** Authenticated exact-document read transport; no cookie/admin URL handoff. */
final class TKD_Cloud_Document_PDF {
    public static function routes() {
        register_rest_route('tkd-cloud/v1','/orders/(?P<number>[A-Za-z0-9-]{1,64})/documents/(?P<document_id>[1-9][0-9]{0,17})/pdf',array('methods'=>'GET','callback'=>array(__CLASS__,'read'),'permission_callback'=>array('TKD_Cloud_Reader','permission')));
    }
    private static function error($reason,$status=409){return new WP_Error('tkd_operation_'.$reason,'PDF read refused.',array('status'=>$status));}
    public static function read($request){
        $auth=TKD_Cloud_Reader::permission($request);if($auth!==true){return $auth;}
        if($request->get_query_params()){return self::error('payload_schema',400);}
        $u=$request->get_url_params();$number=$u['number']??null;$raw=$u['document_id']??null;
        if(!is_string($raw)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',$raw)){return self::error('document_identity');}$id=(int)$raw;
        $minimal=TKD_Cloud_Reader::read_number($number);if($minimal instanceof WP_Error){return $minimal;}$order=$minimal->get_data()['id'];
        $post=get_post($id);if(!$post||!in_array($post->post_type,array('tkd_invoice','tkd_delivery'),true)||in_array($post->post_status,array('trash','auto-draft'),true)||!current_user_can('edit_post',$id)||!current_user_can('edit_post',$order)||!current_user_can('manage_tkd_felicity')){return self::error('document_access',403);}
        $kind=$post->post_type==='tkd_invoice'?'invoice':'delivery';$link=$kind==='invoice'?'_tkd_fo_order_id':'_tkd_fo_delivery_order_id';
        $values=get_post_meta($id,$link,false);if(count($values)!==1||(!is_string($values[0])&&!is_int($values[0]))||!preg_match('/\A[1-9][0-9]{0,17}\z/D',(string)$values[0])||(int)$values[0]!==$order){return self::error('document_identity');}
        $status=get_post_meta($id,$kind==='invoice'?'_tkd_fo_invoice_status':'_tkd_fo_delivery_status',false);
        if(count($status)!==1||!in_array($status[0],$kind==='invoice'?array('issued','paid'):array('issued'),true)){return self::error('pdf_not_ready');}
        if($kind==='delivery'){
            $v=get_post_meta($id,'_tkd_fo_delivery_invoice_id',false);if(count($v)!==1||!is_scalar($v[0])||!preg_match('/\A[1-9][0-9]{0,17}\z/D',(string)$v[0])){return self::error('document_identity');}$invoice=(int)$v[0];
            $p=get_post($invoice);$linked=get_post_meta($invoice,'_tkd_fo_order_id',false);if(!$p||$p->post_type!=='tkd_invoice'||in_array($p->post_status,array('trash','auto-draft'),true)||count($linked)!==1||(!is_string($linked[0])&&!is_int($linked[0]))||(string)$linked[0]!== (string)$order||!current_user_can('edit_post',$invoice)){return self::error('document_identity');}
        }
        $nodes=0;$bytes=0;foreach(array_unique(array($id,$kind==='delivery'?$invoice:$id)) as $document){foreach(require __DIR__.'/document-state-keys.php' as $key){$values=get_post_meta($document,$key,false);if(count($values)>1||!TKD_Cloud_Operations::bounded_value($values,$nodes,$bytes)){return self::error('document_schema',503);}}}
        $pins=TKD_Cloud_Document_Operations::pins($kind.'.pdf');if(!$pins['verified']){return self::error($pins['reason'],503);}
        $get=$_GET;$req=$_REQUEST;$buffer_level=ob_get_level();
        $block=static function(){throw new RuntimeException('pdf_write_refused');};
        $die=static function(){return static function(){throw new RuntimeException('pdf_native_refused');};};
        $cache=static function($headers){$headers['Cache-Control']='private, no-store, no-cache, must-revalidate, max-age=0';$headers['Vary']='Authorization, Cookie';return $headers;};
        foreach(array('add_post_metadata','update_post_metadata','delete_post_metadata') as $hook){add_filter($hook,$block,PHP_INT_MAX);}
        foreach(array('wp_die_handler','wp_die_ajax_handler','wp_die_json_handler') as $hook){add_filter($hook,$die,PHP_INT_MAX);}
        add_filter('nocache_headers',$cache,PHP_INT_MAX);
        try{
            $_GET=array($kind.'_id'=>(string)$id,'_wpnonce'=>wp_create_nonce('tkd_fo_download_'.$kind.'_pdf_'.$id));$_REQUEST=$_GET;
            if(!headers_sent()){header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');header('Vary: Authorization, Cookie');header('X-Content-Type-Options: nosniff');}
            if($kind==='invoice'){TKD_FO_Invoices::download_invoice_pdf();}else{TKD_FO_Deliveries::download_pdf();}
            return self::error('pdf_stream_unconfirmed',503);
        }catch(Throwable $ignored){while(ob_get_level()>$buffer_level){ob_end_clean();}return self::error('pdf_unavailable',503);}
        finally{
            $_GET=$get;$_REQUEST=$req;
            foreach(array('add_post_metadata','update_post_metadata','delete_post_metadata') as $hook){remove_filter($hook,$block,PHP_INT_MAX);}
            foreach(array('wp_die_handler','wp_die_ajax_handler','wp_die_json_handler') as $hook){remove_filter($hook,$die,PHP_INT_MAX);}
            remove_filter('nocache_headers',$cache,PHP_INT_MAX);
        }
        // Native output_pdf exits only after streaming a no-store PDF; request ends.
    }
}
