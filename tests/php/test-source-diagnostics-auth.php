<?php
require __DIR__.'/test-reader.php';
$r=new Request();$r->route='/tkd-cloud/v1/source-diagnostics';
check($routes['tkd-cloud/v1/source-diagnostics']['methods']==='GET'&&$routes['tkd-cloud/v1/source-diagnostics']['permission_callback']===array('TKD_Cloud_Reader','permission'),'diagnostics fixed GET common auth');
TKD_Cloud_Reader::authenticated(new WP_User(0),array());check(error_status(TKD_Cloud_Source_Diagnostics::read($r),401),'diagnostics anonymous/cookie-only denied');
TKD_Cloud_Reader::authenticated(new WP_User(7),array());$uid=7;$explicit_cap=false;check(error_status(TKD_Cloud_Source_Diagnostics::read($r),403),'diagnostics explicit read cap required');$explicit_cap=true;
$r->query_params=array('class'=>'arbitrary');check(error_status(TKD_Cloud_Source_Diagnostics::read($r),400),'diagnostics rejects class/path selection');$r->query_params=array();
$r->method='POST';check(error_status(TKD_Cloud_Source_Diagnostics::read($r),405),'diagnostics rejects POST');$r->method='GET';
$res=TKD_Cloud_Reader::no_store(new WP_REST_Response(array()),null,$r);check(strpos($res->headers['Cache-Control'],'no-store')!==false,'diagnostics no-store error/success policy');
$read=TKD_Cloud_Source_Diagnostics::read($r);$data=$read->get_data();
check($data['complete']===true&&$data['verified']===false&&$data['inventory_count']===$data['checked_count']&&strlen(json_encode($data))<=262144,'authorized diagnostics checks entire real fixed inventory within response bound');
echo "PASS $count aggregate diagnostics auth assertions; synthetic WP only\n";
