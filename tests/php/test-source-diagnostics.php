<?php
if(PHP_SAPI!=='cli'){exit;}define('ABSPATH',__DIR__);
$count=0;function diag_check($v){global $count;if(!$v)throw new RuntimeException('diagnostics assertion '.($count+1));$count++;}
class TKD_Cloud_Reader{const VERSION='0.4.4';}
class TKD_Cloud_Native_Contracts{public static $classes=array();public static function source_inventory(){return self::$classes;}}
$dir=sys_get_temp_dir().'/tkd-diag-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try{
 copy(__DIR__.'/../../wordpress/tkd-cloud-reader/source-diagnostics.php',$dir.'/source-diagnostics.php');
 $fixture="<?php\nclass TKD_Synthetic_Diagnostics {\n private static \$flag=false;\n public static function good(){throw new RuntimeException('NATIVE_INVOCATION_FORBIDDEN');}\n private static function secret(){throw new RuntimeException('SECRET_SOURCE_DO_NOT_EXPORT');}\n}\n";file_put_contents($dir.'/fixture.php',$fixture);require $dir.'/fixture.php';
 $method=function($name)use($dir){$r=new ReflectionMethod('TKD_Synthetic_Diagnostics',$name);$lines=file($r->getFileName());return hash('sha256',implode('',array_slice($lines,$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1)));};
 $class='TKD_Synthetic_Diagnostics';$good=$method('good');$secret=$method('secret');$file=hash_file('sha256',$dir.'/fixture.php');
 $write=function($pins,$fields)use($dir){file_put_contents($dir.'/document-pins.php','<?php return '.var_export($pins,true).';');file_put_contents($dir.'/document-field-pins.php','<?php return '.var_export($fields,true).';');};
 $base=array($class=>array('file'=>$file,'methods'=>array('good'=>$good),'private_methods'=>array('secret'=>$secret)));$fields=array($class=>array(' private static $flag=false;'));$doc=array($class.'::secret'=>array('private',$secret));TKD_Cloud_Native_Contracts::$classes=$base;$write($doc,$fields);
 require $dir.'/source-diagnostics.php';$r=TKD_Cloud_Source_Diagnostics::inspect();diag_check($r['verified']&&$r['complete']&&!$r['truncated']&&$r['checked_count']===5&&$r['mismatches']===array());
 TKD_Cloud_Native_Contracts::$classes[$class]['file']=str_repeat('0',64);TKD_Cloud_Native_Contracts::$classes[$class]['methods']['good']=str_repeat('1',64);
 $doc[$class.'::secret']=array('public',$secret);$doc[$class.'::missing']=array('private',str_repeat('2',64));$doc['TKD_Synthetic_Missing::absent']=array('public',str_repeat('3',64));$write($doc,array($class=>array(' private static $flag=true;')));
 $r=TKD_Cloud_Source_Diagnostics::inspect();diag_check(!$r['verified']&&$r['complete']&&$r['mismatch_count']===6);$reasons=array_column($r['mismatches'],'reason');foreach(array('native_drift','native_missing','native_visibility_mismatch','native_field_drift')as$reason)diag_check(in_array($reason,$reasons,true));
 $out=json_encode($r);foreach(array('SECRET_SOURCE','NATIVE_INVOCATION','fixture.php',$dir,'$flag','declaration','throw new')as$forbidden)diag_check(strpos($out,$forbidden)===false);
 TKD_Cloud_Native_Contracts::$classes=array();$many=array();for($i=0;$i<600;$i++)$many['TKD_Synthetic_Missing::method'.$i]=array('public',str_repeat('4',64));$write($many,array());$r=TKD_Cloud_Source_Diagnostics::inspect();diag_check(!$r['verified']&&!$r['complete']&&$r['truncated']&&$r['bound_reason']==='mismatch_bound'&&$r['mismatch_count']===512);
 TKD_Cloud_Native_Contracts::$classes=$base;$write($doc,array());file_put_contents($dir.'/fixture.php',str_repeat('X',2097153));clearstatcache();$r=TKD_Cloud_Source_Diagnostics::inspect();diag_check(!$r['verified']&&!$r['complete']&&$r['bound_reason']==='source_bound');
 echo "PASS $count complete/all-drift/missing/private/field/bounds/no-invocation diagnostics assertions; synthetic inventory only\n";
}finally{foreach(glob($dir.'/*')as$p)unlink($p);rmdir($dir);}
