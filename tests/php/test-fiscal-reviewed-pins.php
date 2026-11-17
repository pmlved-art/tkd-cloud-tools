<?php
if(PHP_SAPI!=='cli'){exit;}define('ABSPATH',__DIR__);
$e=json_decode(file_get_contents(__DIR__.'/../../evidence/fiscal-source-review-20261117.json'),true);$pins=require __DIR__.'/../../wordpress/tkd-cloud-reader/document-pins.php';$count=0;$changed=0;
foreach($e['methods']as$m){if(($pins[$m['method']]??null)!==array($m['visibility'],$m['source_sha256'])){throw new RuntimeException('fiscal evidence pin mismatch');}$count++;if($m['changed'])$changed++;}
if($count!==21||$changed!==7){throw new RuntimeException('fiscal evidence inventory mismatch');}
echo "PASS $count fiscal evidence/pin consistency assertions; source review external, no native calls\n";
