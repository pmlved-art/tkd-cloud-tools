<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
require __DIR__.'/../../wordpress/tkd-cloud-reader/document-contracts.php';
$n=0;function verify_document($ok){global $n;if(!$ok)throw new RuntimeException('synthetic document assertion failed');$n++;}
$s=array('id'=>30,'order'=>10,'invoice'=>20,'mode'=>'np','status'=>'draft','revision'=>str_repeat('a',64),'document_basis'=>str_repeat('b',64),'office_revision'=>str_repeat('c',64),'blockers'=>array(),'fiscal'=>array('state'=>'ready','message'=>'SYNTHETIC_PII'),'facts'=>array('SYNTHETIC_PII'),'manager_plan'=>array('token'=>'SYNTHETIC_SECRET'));
$r=TKD_Cloud_Document_Contracts::release_snapshot($s,10,20,30);
verify_document(is_array($r)&&$r['preview_is_acceptance']===false&&!$r['blocked']);
verify_document(strpos(json_encode($r),'SYNTHETIC')===false);
foreach(array('id','order','invoice','revision','document_basis','office_revision','mode','status','blockers','fiscal') as $key){$bad=$s;$bad[$key]=null;verify_document(TKD_Cloud_Document_Contracts::release_snapshot($bad,10,20,30)===null);}
verify_document(TKD_Cloud_Document_Contracts::release_snapshot($s,11,20,30)===null);
verify_document(TKD_Cloud_Document_Contracts::release_snapshot($s,10,20,31)===null);
$s['blockers']=array('SYNTHETIC_PERSONAL_NATIVE_MESSAGE');$r=TKD_Cloud_Document_Contracts::release_snapshot($s,10,20,30);verify_document($r['blocked']&&strpos(json_encode($r),'SYNTHETIC')===false);
foreach(array(null,false,new stdClass(),array(),array('posted'=>true,'prepared'=>'yes'),array('posted'=>false,'prepared'=>false)) as $v){$r=TKD_Cloud_Document_Contracts::release_outcome($v);verify_document($r['state']==='unknown'&&$r['retry_native']===false);}
foreach(array(false,true) as $prepared){$r=TKD_Cloud_Document_Contracts::release_outcome(array('posted'=>true,'prepared'=>$prepared,'message'=>'SYNTHETIC_PII'));verify_document($r['state']==='recorded_needs_review'&&$r['posted']&&$r['retry_native']===false&&strpos(json_encode($r),'SYNTHETIC')===false);}
$r=TKD_Cloud_Document_Contracts::release_outcome(array('posted'=>true,'prepared'=>true),true);verify_document($r['state']==='complete'&&$r['physical_shipment_verified']===false);
echo "PASS $n pure document projection assertions; no routes/native writes\n";
