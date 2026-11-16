<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/test-read-extensions.php';
$mode=isset($argv[1]) ? $argv[1] : 'methods';
$file=tempnam(sys_get_temp_dir(),'tkd-synthetic-source-');
$body="<?php\nclass TKD_FO_Payments {\n";
if ($mode==='methods') {
 for($i=0;$i<501;$i++) $body.='public static function synthetic_'.$i.'() {}' . "\n";
} else { $body.='/*'.str_repeat('x',2097152).'*/'; }
$body.="\n}\n";
file_put_contents($file,$body);
try {
 require $file;
 $r->query_params=array();
 $res=TKD_Cloud_Read_Extensions::manifest($r);
 check(error_status($res,503) && $res->code==='tkd_manifest_bound_exceeded','explicit manifest '.$mode.' bound failure no truncation');
} finally { unlink($file); }
echo "PASS manifest bound $mode\n";
