<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Synthetic WordPress API stubs: no live WordPress/database/authentication integration.
define('ABSPATH', __DIR__);
class WP_User { public $ID; function __construct($id) { $this->ID=$id; } }
class WP_Error {
    public $code; public $data;
    function __construct($code, $message, $data) { $this->code=$code; $this->data=$data; }
}
class WP_REST_Response {
    public $data; public $headers=array();
    function __construct($data=array()) { $this->data=$data; }
    function get_data() { return $this->data; }
    function header($key,$value) { $this->headers[$key]=$value; }
}
class Request {
    public $number='TKD-100'; public $method='GET'; public $route='/tkd-cloud/v1/orders/TKD-100';
    public $query_params=array();
    function get_query_params() { return $this->query_params; }
    function get_url_params() { return array('number'=>$this->number); }
    function get_method() { return $this->method; }
    function get_route() { return $this->route; }
}
$hooks=array(); $routes=array(); $role=null; $created=array(); $ssl=true; $uid=7; $cap=true; $explicit_cap=true; $exists=true;
$ids=array(12); $numbers=array('TKD-100'); $statuses=array('new');
$post=(object)array('post_type'=>'tkd_order','post_status'=>'publish','post_modified_gmt'=>'2026-11-16 12:00:00');
function add_action($name,$fn,$priority=10,$argc=1) { $GLOBALS['hooks'][$name]=$fn; }
function add_filter($name,$fn,$priority=10,$argc=1) { $GLOBALS['hooks'][$name]=$fn; }
function register_activation_hook($file,$fn) {}
function get_role($name) { return $name==='tkd_cloud_reader' ? $GLOBALS['role'] : null; }
function add_role($name,$label,$caps) { $GLOBALS['created']=array($name,$caps); }
function wp_die($message) { throw new RuntimeException($message); }
function register_rest_route($namespace,$route,$definition) { $GLOBALS['routes'][$namespace.$route]=$definition; }
function is_ssl() { return $GLOBALS['ssl']; }
function get_current_user_id() { return $GLOBALS['uid']; }
function current_user_can($cap) { if ($cap!=='tkd_cloud_read_orders') throw new Exception('Unexpected capability'); return $GLOBALS['cap']; }
function wp_get_current_user() { return (object)array('allcaps'=>array('tkd_cloud_read_orders'=>$GLOBALS['explicit_cap'])); }
function post_type_exists($type) { if ($type!=='tkd_order') throw new Exception('Fallback type'); return $GLOBALS['exists']; }
function get_post_stati() { return array('publish','private','draft','pending','trash','auto-draft'); }
function get_posts($args) { $GLOBALS['query']=$args; return $GLOBALS['ids']; }
function get_post($id) { return $GLOBALS['post']; }
function get_post_meta($id,$key,$single) {
    if ($single!==false) throw new Exception('Must detect duplicate meta');
    if ($key==='_tkd_order_number') return $GLOBALS['numbers'];
    if ($key==='_tkd_status') return $GLOBALS['statuses'];
    if (in_array($key, TKD_Cloud_Read_Extensions::META,true)) return isset($GLOBALS['extra_meta'][$key]) ? $GLOBALS['extra_meta'][$key] : array();
    throw new Exception('Unexpected metadata access');
}
function rest_ensure_response($r) { return $r; }
$plugin=__DIR__.'/../../wordpress/tkd-cloud-reader/tkd-cloud-reader.php';
require is_file($plugin) ? $plugin : __DIR__.'/../tkd-cloud-reader.php';
$count=0;
function check($ok,$name) { global $count; if (!$ok) throw new Exception('FAIL: '.$name); $count++; echo "ok $count - $name\n"; }
function error_status($value,$status) { return $value instanceof WP_Error && $value->data['status']===$status; }
$r=new Request();
check(isset($hooks['application_password_did_authenticate']), 'native authentication hook registered');
TKD_Cloud_Reader::activate();
check($created===array('tkd_cloud_reader',array('read'=>true,'tkd_cloud_read_orders'=>true)), 'only minimal role created');
$role=(object)array('capabilities'=>array('read'=>true,'tkd_cloud_read_orders'=>true,'manage_options'=>true));
try { TKD_Cloud_Reader::activate(); check(false,'conflicting role'); } catch (RuntimeException $e) { check(true,'existing privileged role rejected'); }
TKD_Cloud_Reader::routes();
check(count($routes)===11,'read routes and fixed operation routes registered');
foreach ($routes as $path=>$route) {
 if ($path==='tkd-cloud/v1/operations') check($route['methods']==='POST' && $route['permission_callback']===array('TKD_Cloud_Operations','permission'),'fixed POST operation callback');
 else check($route['methods']==='GET' && $route['permission_callback']===array('TKD_Cloud_Reader','permission'),'GET with permission callback');
}
check(error_status(TKD_Cloud_Reader::permission($r),401),'cookie/current user alone rejected');
$r->route='/tkd-cloud/v1/health';
$_SERVER=array();
$diag=TKD_Cloud_Reader::permission($r)->data['auth_diagnostics'];
check(count($diag)===8 && array_values(array_unique(array_map('gettype',$diag)))===array('boolean'),'diagnostics contain only eight booleans');
check(count(array_filter($diag))===0,'missing inputs are false');
$_SERVER=array('HTTP_AUTHORIZATION'=>'Basic SYNTHETIC', 'REDIRECT_HTTP_AUTHORIZATION'=>'Bearer SYNTHETIC', 'PHP_AUTH_USER'=>'synthetic', 'PHP_AUTH_PW'=>'synthetic');
$wp_rest_application_password_status=new WP_User(7); $wp_rest_application_password_uuid='synthetic-uuid';
$diag=TKD_Cloud_Reader::permission($r)->data['auth_diagnostics'];
check(count(array_filter($diag))===8,'present inputs and matching native core status observed');
check(error_status(TKD_Cloud_Reader::permission($r),401),'core diagnostics do not bypass original hook requirement');
$wp_rest_application_password_status=new WP_User(8);
$diag=TKD_Cloud_Reader::permission($r)->data['auth_diagnostics'];
check($diag['native_app_password_status_is_user'] && !$diag['native_app_password_user_matches_current'],'native user mismatch observed');
$wp_rest_application_password_status=new WP_Error('synthetic','synthetic',array()); $wp_rest_application_password_uuid=null;
$_SERVER=array('HTTP_AUTHORIZATION'=>'Bearer SYNTHETIC','REDIRECT_HTTP_AUTHORIZATION'=>'bAsIc SYNTHETIC');
$diag=TKD_Cloud_Reader::permission($r)->data['auth_diagnostics'];
check($diag['authorization_basic_scheme'] && !$diag['native_app_password_status_is_user'] && !$diag['native_app_password_uuid_present'],'redirect Basic detected without decoding; native error false');
$_SERVER=array('HTTP_AUTHORIZATION'=>array('unexpected'));
$diag=TKD_Cloud_Reader::permission($r)->data['auth_diagnostics'];
check(!$diag['http_authorization_present'] && !$diag['authorization_basic_scheme'],'non-string header rejected safely');
$r->route='/tkd-cloud/v1/orders/TKD-100';
check(!isset(TKD_Cloud_Reader::permission($r)->data['auth_diagnostics']),'order errors contain no diagnostics');
$r->route='/tkd-cloud/v1/health'; $r->method='POST';
check(!isset(TKD_Cloud_Reader::permission($r)->data['auth_diagnostics']),'non-GET contains no diagnostics');
$r->method='GET'; $r->route='/tkd-cloud/v1/orders/TKD-100'; $_SERVER=array();
$wp_rest_application_password_status=null; $wp_rest_application_password_uuid=null;
TKD_Cloud_Reader::authenticated((object)array('ID'=>7),array());
$ssl=false; check(error_status(TKD_Cloud_Reader::permission($r),403),'HTTP rejected'); $ssl=true;
$uid=8; check(error_status(TKD_Cloud_Reader::permission($r),401),'authenticated user mismatch rejected'); $uid=7;
$cap=false; check(error_status(TKD_Cloud_Reader::permission($r),403),'application password without capability rejected including admin'); $cap=true;
$explicit_cap=false; check(error_status(TKD_Cloud_Reader::permission($r),403),'super-admin implicit bypass rejected'); $explicit_cap=true;
check(TKD_Cloud_Reader::permission($r)===true,'application user plus capability accepted');
foreach(array('POST','PUT','DELETE','PATCH','HEAD') as $method) { $r->method=$method; check(error_status(TKD_Cloud_Reader::permission($r),405),'reject '.$method); } $r->method='GET';
$exists=false; check(error_status(TKD_Cloud_Reader::health($r),503) && error_status(TKD_Cloud_Reader::order($r),503),'missing post type fails closed'); $exists=true;
check(TKD_Cloud_Reader::health($r)->data['post_type_exists']===true,'health confirms type');
foreach(array('100','tkd-100','TKD-','TKD-100%','TKD-'.str_repeat('A',61)) as $number) { $r->number=$number; check(error_status(TKD_Cloud_Reader::order($r),400),'invalid full number rejected'); } $r->number='TKD-100';
$response=TKD_Cloud_Reader::order($r);
check(array_keys($response->data)===array('id','number','status','post_modified_gmt','schema_version','bridge_version'),'response contains only allowlisted fields');
check($response->data['number']==='TKD-100' && $response->data['status']==='new','stored number/status preserved');
check($query['post_type']==='tkd_order' && $query['meta_query'][0]===array('key'=>'_tkd_order_number','value'=>'TKD-100','compare'=>'=','type'=>'BINARY'),'exact binary meta query no suffix or type fallback');
check(!in_array('trash',$query['post_status'],true) && !in_array('auto-draft',$query['post_status'],true) && $query['posts_per_page']===2,'excluded statuses and bounded duplicate lookup');
$ids=array(); check(error_status(TKD_Cloud_Reader::order($r),404),'missing order');
$ids=array(12,13); check(error_status(TKD_Cloud_Reader::order($r),409),'duplicate orders'); $ids=array(12);
foreach(array('trash','auto-draft') as $status) { $post->post_status=$status; check(error_status(TKD_Cloud_Reader::order($r),404),'race to excluded status'); } $post->post_status='publish';
$post->post_type='shop_order'; check(error_status(TKD_Cloud_Reader::order($r),503),'wrong type fails closed'); $post->post_type='tkd_order';
foreach(array(array(),array('tkd-100'),array('TKD-100','TKD-100')) as $bad) { $numbers=$bad; check(error_status(TKD_Cloud_Reader::order($r),503),'inconsistent number metadata'); } $numbers=array('TKD-100');
foreach(array(array(),array(''),array('new','paid'),array(array('bad')),array('customer@example.test')) as $bad) { $statuses=$bad; check(error_status(TKD_Cloud_Reader::order($r),503),'inconsistent status metadata'); } $statuses=array('new');
foreach(array('0000-00-00 00:00:00','2026-02-31 12:00:00','invalid') as $bad) { $post->post_modified_gmt=$bad; check(error_status(TKD_Cloud_Reader::order($r),503),'invalid GMT date'); }
foreach(array('/tkd-cloud/v1/health','/tkd-cloud/v1/orders/TKD-100','/tkd-cloud/v1/unknown') as $route) {
 $r->route=$route;
 // WordPress rest_post_dispatch receives normalized success AND error responses.
 $res=TKD_Cloud_Reader::no_store(new WP_REST_Response(array('code'=>'synthetic_error')),null,$r);
 check(strpos($res->headers['Cache-Control'],'no-store')!==false && $res->headers['Vary']==='Authorization, Cookie','no-store including normalized errors/unknown routes');
}
$r->route='/other/v1'; check(TKD_Cloud_Reader::no_store(new WP_REST_Response(),null,$r)->headers===array(),'unrelated routes unchanged');
echo "PASS $count synthetic assertions; not live WordPress integration\n";
