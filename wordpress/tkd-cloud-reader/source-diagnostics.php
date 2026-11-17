<?php
if (!defined('ABSPATH')) { exit; }
/** Metadata-only fixed inventory audit; never invokes native methods or autoload. */
final class TKD_Cloud_Source_Diagnostics {
    const MAX_CHECKS = 1024;
    const MAX_MISMATCHES = 512;
    const MAX_FILE_BYTES = 2097152;
    const MAX_TOTAL_BYTES = 16777216;
    const MAX_FILES = 64;
    public static function routes() {
        register_rest_route('tkd-cloud/v1','/source-diagnostics',array('methods'=>'GET','callback'=>array(__CLASS__,'read'),'permission_callback'=>array('TKD_Cloud_Reader','permission')));
    }
    public static function read($request) {
        $auth=TKD_Cloud_Reader::permission($request);if($auth!==true){return $auth;}
        if($request->get_query_params()){return new WP_Error('tkd_diagnostics_parameters','Fixed inventory only.',array('status'=>400));}
        return new WP_REST_Response(self::inspect());
    }
    private static function inventory() {
        $out=array();
        foreach(TKD_Cloud_Native_Contracts::source_inventory() as $class=>$spec){
            if($spec['file']!==null){$out[]=array('kind'=>'file','target'=>$class,'expected_sha256'=>$spec['file']);}
            foreach(array('methods'=>'public','private_methods'=>'private') as $group=>$visibility){foreach($spec[$group]??array() as $name=>$sha){$out[]=array('kind'=>'method','target'=>$class.'::'.$name,'expected_sha256'=>$sha,'visibility'=>$visibility);}}
        }
        foreach(require __DIR__.'/document-pins.php' as $target=>$spec){$out[]=array('kind'=>'method','target'=>$target,'expected_sha256'=>$spec[1],'visibility'=>$spec[0]);}
        foreach(require __DIR__.'/document-field-pins.php' as $class=>$fields){foreach($fields as $index=>$declaration){$out[]=array('kind'=>'field','target'=>$class,'field_index'=>$index,'expected_sha256'=>hash('sha256',$declaration),'declaration'=>$declaration);}}
        return $out;
    }
    // No inventory argument: callers cannot choose class, path, method or pin.
    public static function inspect() {
        $inventory=self::inventory();$files=array();$refs=array();$bytes=0;$checked=0;$mismatches=array();$complete=true;$bound=null;
        foreach($inventory as $pin){
            if($checked>=self::MAX_CHECKS||count($mismatches)>=self::MAX_MISMATCHES){$complete=false;$bound=$checked>=self::MAX_CHECKS?'inventory_bound':'mismatch_bound';break;}
            $reason=null;$actual=null;$class=explode('::',$pin['target'])[0];
            if(!class_exists($class,false)){$reason='native_missing';}
            else{
                try{
                    $ref=$refs[$class]??($refs[$class]=new ReflectionClass($class));$file=$ref->getFileName();
                    if(!is_string($file)||!is_file($file)||!is_readable($file)){$reason='native_source_unavailable';}
                    elseif(!isset($files[$file])){
                        $size=@filesize($file);
                        if($size===false){$reason='native_source_unavailable';}
                        elseif($size>self::MAX_FILE_BYTES||count($files)>=self::MAX_FILES||$bytes+$size>self::MAX_TOTAL_BYTES){$complete=false;$bound='source_bound';break;}
                        else{
                            $body=@file_get_contents($file,false,null,0,self::MAX_FILE_BYTES+1);
                            if(!is_string($body)){$reason='native_source_unavailable';}
                            elseif(strlen($body)>self::MAX_FILE_BYTES||$bytes+strlen($body)>self::MAX_TOTAL_BYTES||substr_count($body,"\n")>50000){$complete=false;$bound='source_bound';break;}
                            else{$bytes+=strlen($body);$files[$file]=array('body'=>$body,'sha'=>hash('sha256',$body),'lines'=>explode("\n",$body));}
                        }
                    }
                    if($reason===null){
                        $source=$files[$file];
                        if($pin['kind']==='file'){$actual=$source['sha'];if(!hash_equals($pin['expected_sha256'],$actual)){$reason='native_drift';}}
                        elseif($pin['kind']==='field'){
                            // A missing/duplicated exact declaration has no unambiguous actual slice.
                            if(substr_count($source['body'],$pin['declaration'])!==1){$reason='native_field_drift';}else{$actual=$pin['expected_sha256'];}
                        }else{
                            $name=explode('::',$pin['target'])[1];
                            if(!$ref->hasMethod($name)){$reason='native_missing';}
                            else{
                                $m=$ref->getMethod($name);$visibility=$m->isPublic()?'public':($m->isProtected()?'protected':'private');
                                if(!$m->isStatic()||$visibility!==$pin['visibility']||$m->getDeclaringClass()->getName()!==$class||$m->getFileName()!==$file){$reason='native_visibility_mismatch';}
                                else{
                                    $start=$m->getStartLine();$end=$m->getEndLine();$lines=$source['lines'];
                                    if(!is_int($start)||!is_int($end)||$start<1||$end<$start||$end>count($lines)){$reason='native_source_unavailable';}
                                    else{$slice=implode("\n",array_slice($lines,$start-1,$end-$start+1));if($end<count($lines)){$slice.="\n";}$actual=hash('sha256',$slice);if(!hash_equals($pin['expected_sha256'],$actual)){$reason='native_drift';}}
                                }
                            }
                        }
                    }
                }catch(Throwable $ignored){$reason='native_source_unavailable';}
            }
            $checked++;
            if($reason!==null){$row=array('kind'=>$pin['kind'],'target'=>$pin['target'],'reason'=>$reason,'expected_sha256'=>$pin['expected_sha256'],'actual_sha256'=>$actual);if(isset($pin['field_index'])){$row['field_index']=$pin['field_index'];}$mismatches[]=$row;}
        }
        $out=array('bridge_version'=>TKD_Cloud_Reader::VERSION,'schema_version'=>'tkd-source-diagnostics-v1','verified'=>$complete&&!$mismatches,'complete'=>$complete,'truncated'=>!$complete,'bound_reason'=>$bound,'inventory_count'=>count($inventory),'checked_count'=>$checked,'mismatch_count'=>count($mismatches),'mismatches'=>$mismatches);
        // This fixed shape is below 256 KiB even at MAX_MISMATCHES.
        return $out;
    }
}
