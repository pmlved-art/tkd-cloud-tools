<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
class WP_User { public $ID; function __construct($id){$this->ID=$id;} }
class WP_Error { public $code; public $data; function __construct($c,$m='',$d=array()){$this->code=$c;$this->data=$d;} function get_error_code(){return $this->code;} }
class WP_REST_Response { public $data;public $headers=array();function __construct($d=array()){$this->data=$d;} function get_data(){return $this->data;}function header($k,$v){$this->headers[$k]=$v;} }
class OpRequest {
 public $method='POST'; public $json; public $params=array(); public $query=array(); public $route='/tkd-cloud/v1/operations';public $extra_body='';
 function get_method(){return $this->method;} function get_json_params(){return $this->json;}function get_body(){return $this->extra_body?:json_encode($this->json);}function get_url_params(){return $this->params;}function get_query_params(){return $this->query;}function get_route(){return $this->route;}
}
function add_action(...$a){}function add_filter($name,$cb,...$a){$GLOBALS['test_filters'][$name]=$cb;}function remove_filter($name,$cb,...$a){unset($GLOBALS['test_filters'][$name]);}function register_activation_hook(...$a){}function register_rest_route(...$a){}
function rest_ensure_response($r){return $r;}function is_ssl(){return $GLOBALS['ssl'];}function get_current_user_id(){return $GLOBALS['actor'];}
function wp_get_current_user(){return (object)array('allcaps'=>$GLOBALS['caps']);}
function current_user_can($c,...$args){return $c==='edit_post'?$GLOBALS['edit_allowed']:!empty($GLOBALS['caps'][$c]);}
function wp_check_post_lock($id){return $GLOBALS['editor_lock']??false;}function apply_filters($name,$v){return $v;}
function wp_cache_delete(...$a){return true;}function get_post_stati(){return array('publish','private','trash','auto-draft');}
function post_type_exists($t){return $t==='tkd_order';}
function get_posts($q){if(($q['meta_query'][0]['key']??'')==='_tkd_fo_delivery_seller_key'){$result=array();foreach($GLOBALS['document_ids']['tkd_delivery']??array() as $id){if(get_post_meta($id,'_tkd_fo_delivery_seller_key',true)===$q['meta_query'][0]['value']&&get_post_meta($id,'_tkd_fo_delivery_number',true)===$q['meta_query'][1]['value'])$result[]=$id;}return $result;}if(($q['post_type']??'')!=='tkd_order'){return $GLOBALS['document_ids'][$q['post_type']]??array();}$number=$q['meta_query'][0]['value'];return $number==='TKD-SYNTHETIC'?array(12):array();}
function get_post($id){if(isset($GLOBALS['document_posts'][$id])){return $GLOBALS['document_posts'][$id];}return $id===12?(object)array('post_type'=>'tkd_order','post_status'=>'publish','post_date_gmt'=>'2026-11-17 00:00:00','post_modified_gmt'=>'2026-11-17 00:00:00'):null;}
function get_post_meta($id,$key,$single=false){$values=$GLOBALS['meta'][$id][$key]??array();return $single?($values[0]??''):$values;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function add_option($key,$v,$deprecated='',$autoload=false){
 if ($GLOBALS['claim_race']) {$GLOBALS['options'][$key]=array('foreign'=>'DO_NOT_OVERWRITE');return false;}
 if ($GLOBALS['add_fail']||array_key_exists($key,$GLOBALS['options']))return false;
 $GLOBALS['options'][$key]=$v;$GLOBALS['journal_writes']++;return true;
}
function update_option($key,$v,$autoload=false){if($GLOBALS['update_fail'])return false;$GLOBALS['options'][$key]=$v;$GLOBALS['journal_writes']++;return true;}
function maybe_serialize($v){return serialize($v);}
class JournalDatabase {
 public $prefix='wp_synthetic_';public $options='wp_synthetic_options';public $suppressed=false;public $pending=array();
 function suppress_errors($v){$old=$this->suppressed;$this->suppressed=$v;return $old;}
 function get_var($sql){return '1';}
 function get_results($sql,$format){if(strpos($sql,'SHOW COLUMNS')===0)return array_map(function($c){return array('Field'=>$c,'Type'=>in_array($c,array('on_hand','reserved','reserved_supply','incoming'))?'decimal(18,6)':'varchar(128)');},array('id','sku','on_hand','reserved','reserved_supply','incoming','active','updated_at'));if(strpos($sql,'SELECT id,sku')===0)return array(array('id'=>'1','sku'=>$this->pending[0],'on_hand'=>$GLOBALS['stock_amount']??'100.000000','reserved'=>'0.000000','reserved_supply'=>'0.000000','incoming'=>'0.000000','active'=>'1','updated_at'=>'2026-11-17 00:00:00'));if(strpos($sql,'SHOW INDEX FROM `wp_synthetic_options`')!==0)throw new Exception('Unapproved metadata SQL');return $GLOBALS['missing_unique']?array():array(array('Non_unique'=>'0','Column_name'=>'option_name','Seq_in_index'=>'1'));}
 function prepare($sql,...$args){$this->pending=$args;return $sql;}
 function query($sql){
  if(strpos($sql,'INSERT IGNORE INTO `wp_synthetic_options` (`option_name`,`option_value`,`autoload`)')!==0)throw new Exception('Unapproved SQL');
  list($key,$serialized,$autoload)=$this->pending;
  if($GLOBALS['claim_race']){$GLOBALS['options'][$key]=array('foreign'=>'DO_NOT_OVERWRITE');return 0;}
  if($GLOBALS['add_fail']||array_key_exists($key,$GLOBALS['options']))return 0;
  $GLOBALS['options'][$key]=unserialize($serialized);$GLOBALS['journal_writes']++;return 1;
 }
}
$wpdb=new JournalDatabase();
function wp_salt($s){return 'SYNTHETIC_TEST_SALT_'.$s;}function wp_timezone(){return new DateTimeZone('UTC');}function current_time($v){return '2026-11-17 12:00:00';}
function sanitize_text_field($v){return strip_tags((string)$v);}function wp_create_nonce($a){$GLOBALS['nonce_calls']++;return hash('sha256',$a);}
function wp_verify_nonce($v,$a){return hash_equals(hash('sha256',$a),$v);}
function wp_slash($v){return is_array($v)?array_map('wp_slash',$v):addslashes($v);}function wp_unslash($v){return is_array($v)?array_map('wp_unslash',$v):stripslashes($v);}
function get_role($name){return $GLOBALS['roles'][$name]??null;}function add_role($name,$label,$caps){$GLOBALS['roles'][$name]=(object)array('capabilities'=>$caps);}
function wp_die($m){throw new RuntimeException('REDACTED_ADMIN_REFUSAL');}function check_admin_referer($a){if(!$GLOBALS['admin_nonce'])wp_die('nonce');}
function get_users($q){return $GLOBALS['assigned_operators']??array(10);}
function get_user_by($f,$login){return $GLOBALS['technical_user'];}
function wp_safe_redirect($url){throw new LogicException('SYNTHETIC_REDIRECT');}function admin_url($p){return $p;}
function get_post_type($id){$p=get_post($id);return $p?$p->post_type:false;}
function update_post_meta($id,$key,$v){$GLOBALS['business_writes']++;$GLOBALS['meta'][$id][$key]=array($v);return true;}
function wp_date($format,$time=null){return date($format,$time??time());}
class TechnicalUser { public $ID=10; public $roles=array('tkd_cloud_reader'); public $caps=array('tkd_cloud_reader'=>true); public $allcaps=array(); function get_role_caps(){$this->allcaps=array_merge(($GLOBALS['roles'][$this->roles[0]]->capabilities??array()),$this->caps);if(!empty($GLOBALS['extra_effective']))$this->allcaps['manage_options']=true;return $this->allcaps;} function set_role($role){$this->roles=array($role);$this->caps=array($role=>true);$this->get_role_caps();$GLOBALS['role_assignments']++;} }
require __DIR__.'/fixtures/operation-native.php';
require __DIR__.'/../../wordpress/tkd-cloud-reader/tkd-cloud-reader.php';
$count=0;function check($condition,$name){global $count;if(!$condition)throw new Exception('FAIL '.$name);$count++;}
function code_is($r,$c){return $r instanceof WP_Error && $r->code==='tkd_operation_'.$c;}
function reset_state(){
 $GLOBALS['missing_unique']=false;$GLOBALS['editor_lock']=false;$GLOBALS['ssl']=true;$GLOBALS['actor']=10;$GLOBALS['edit_allowed']=true;$GLOBALS['caps']=TKD_Cloud_Operator_Role::caps();
 $GLOBALS['meta']=array(12=>array('_tkd_order_number'=>array('TKD-SYNTHETIC'),'_tkd_status'=>array('availability_pending'),'_tkd_client_total'=>array('100.00'),'_tkd_fo_payments'=>array(array())));
 foreach(array('native_status_calls','native_payment_calls','business_writes','journal_writes','lock_calls','nonce_calls','role_assignments')as $k)$GLOBALS[$k]=0;
 foreach(array('busy','add_fail','update_fail','claim_race')as $k)$GLOBALS[$k]=false;
 $GLOBALS['depth']=array();$GLOBALS['options']=array();$GLOBALS['status_mode']='ok';$GLOBALS['payment_mode']='ok';$GLOBALS['extra_effective']=false;$GLOBALS['roles']=array('tkd_cloud_reader'=>(object)array('capabilities'=>array('read'=>true,'tkd_cloud_read_orders'=>true)));$GLOBALS['technical_user']=new TechnicalUser();$GLOBALS['admin_nonce']=true;$_POST=array('unrelated'=>'MUST_RESTORE');
 TKD_Cloud_Reader::authenticated(new WP_User(10),array());
}
function synthetic_pins(){
 $profile=array('classes'=>array(),'manual_channels'=>array('pumb','privatbank','raiffeisen','bank_emeo'));
 foreach(array('TKD_FO_Orders','TKD_FO_Mobile_Cabinet_20261011','TKD_FO_Payments','TKD_FO_Order_Lifecycle','TKD_FO_DB','TKD_FO_Invoices','TKD_FO_Deliveries')as $class){
  $r=new ReflectionClass($class);$pin=array('file'=>hash_file('sha256',$r->getFileName()),'methods'=>array(),'private_methods'=>array());
  foreach($r->getMethods()as $m){
   $lines=file($m->getFileName());$sha=hash('sha256',implode('',array_slice($lines,$m->getStartLine()-1,$m->getEndLine()-$m->getStartLine()+1)));
   $pin[$m->isPrivate()?'private_methods':'methods'][$m->getName()]=$sha;
  }
  $profile['classes'][$class]=$pin;
 }
 return $profile;
}
function set_pins($p){$ref=new ReflectionProperty('TKD_Cloud_Native_Contracts','profile');$ref->setAccessible(true);$ref->setValue(null,$p);}
function operation($kind='status.change'){
 $r=new OpRequest();$r->method='GET';$r->params=array('number'=>'TKD-SYNTHETIC');$r->route='/tkd-cloud/v1/orders/TKD-SYNTHETIC/operation-snapshot';
 $s=TKD_Cloud_Operations::snapshot($r);if($s instanceof WP_Error)throw new Exception('Snapshot failure '.$s->code);
 $r->method='POST';$r->route='/tkd-cloud/v1/operations';$r->params=array();
 $r->json=array('operation_id'=>'11111111-1111-4111-8111-111111111111','operation'=>$kind,'order_id'=>12,'number'=>'TKD-SYNTHETIC','revision'=>$s->data['revision'],'payload'=>$kind==='status.change'?array('choice'=>'availability_callback'):array('amount'=>'25.00','payer_name'=>'SYNTHETIC_PERSON','recipient_key'=>'fop_pm','channel_key'=>'privatbank','received_at'=>'2026-11-17 10:00:00','reference'=>'SYNTHETIC_REFERENCE','confirm_additional'=>false));
 return $r;
}
reset_state();$profile=synthetic_pins();set_pins($profile);
check(TKD_Cloud_Native_Contracts::verify('status.change')===true,'synthetic fixture fingerprints match');
$r=operation();check($business_writes===0&&$journal_writes===0&&$nonce_calls===0&&$lock_calls===0,'GET snapshot has no writes/nonce/mutex');
$before=$_POST;$result=TKD_Cloud_Operations::execute($r);check($result->data['state']==='complete'&&$native_status_calls===1&&$depth[12]===0,'status native reentrant lock + persisted result');
$again=TKD_Cloud_Operations::execute($r);check($again->data===$result->data&&$native_status_calls===1,'status replay never repeats native');
$r->json['payload']['choice']='client_thinking';check(code_is(TKD_Cloud_Operations::execute($r),'idempotency_mismatch')&&$native_status_calls===1,'digest mismatch rejects');
reset_state();$r=operation();$r->json['revision']=str_repeat('a',64);check(code_is(TKD_Cloud_Operations::execute($r),'stale_revision')&&$journal_writes===0&&$business_writes===0,'stale creates no claim');
reset_state();$r=operation();$r->json['order_id']=13;check(TKD_Cloud_Operations::execute($r) instanceof WP_Error&&$business_writes===0&&$journal_writes===0,'ID/number exact matching');
reset_state();$r=operation();$busy=true;check(code_is(TKD_Cloud_Operations::execute($r),'busy')&&$journal_writes===0,'shared mutex busy refuses');
reset_state();$r=operation();$caps=array('read'=>true,'tkd_cloud_read_orders'=>true);check(code_is(TKD_Cloud_Operations::execute($r),'forbidden')&&$business_writes===0&&$nonce_calls===0,'reader role cannot operate');
reset_state();$r=operation();TKD_Cloud_Reader::authenticated(new WP_User(0),array());check(TKD_Cloud_Operations::execute($r) instanceof WP_Error&&$business_writes===0&&$journal_writes===0,'cookie-only denied');
reset_state();$r=operation();$ssl=false;check(TKD_Cloud_Operations::execute($r) instanceof WP_Error&&$business_writes===0,'HTTPS required');
reset_state();$r=operation();$edit_allowed=false;check(code_is(TKD_Cloud_Operations::execute($r),'forbidden')&&$journal_writes===0,'native edit_post enforced');
foreach(array('shipped','deferred_payment','not_a_status') as $choice){reset_state();$r=operation();$r->json['payload']['choice']=$choice;check(code_is(TKD_Cloud_Operations::execute($r),'payload_schema')&&$native_status_calls===0&&$journal_writes===0,'no status shortcut');}
reset_state();$r=operation();$r->json['payload']['arbitrary_meta']='SYNTHETIC';check(code_is(TKD_Cloud_Operations::execute($r),'payload_schema')&&$journal_writes===0,'strict payload schema');
reset_state();$r=operation();$status_mode='error';$x=TKD_Cloud_Operations::execute($r);check($x->data['state']==='failed'&&$x->data['reason']==='mobile_invoice','redacted native refusal');check(strpos(json_encode($options),'SENSITIVE')===false,'audit no native message or PII');
reset_state();$r=operation();$status_mode='partial';$x=TKD_Cloud_Operations::execute($r);check($x->data['state']==='unknown','partial native effect unknown');TKD_Cloud_Operations::execute($r);check($native_status_calls===1,'unknown never repeats');
reset_state();$r=operation();$add_fail=true;check(code_is(TKD_Cloud_Operations::execute($r),'claim_failed')&&$native_status_calls===0,'claim persistence before native');
reset_state();$r=operation();$claim_race=true;check(code_is(TKD_Cloud_Operations::execute($r),'claim_failed')&&$native_status_calls===0&&reset($options)===array('foreign'=>'DO_NOT_OVERWRITE'),'CAS loser cannot overwrite winner');
reset_state();$r=operation();$x=TKD_Cloud_Operations::execute($r);$key='tkd_cloud_operation_'.$r->json['operation_id'];$options[$key]['state']='pending';$options[$key]['reason']='native_not_yet_verified';$native_status_calls=0;
$x=TKD_Cloud_Operations::execute($r);check($x->data['state']==='unknown'&&$options[$key]['state']==='unknown'&&$native_status_calls===0,'interrupted durable pending becomes unknown without retry');
$j=new OpRequest();$j->method='GET';$j->params=array('operation_id'=>$r->json['operation_id']);$writes=$journal_writes;$x=TKD_Cloud_Operations::journal($j);check($x->data['state']==='unknown'&&$journal_writes===$writes,'journal GET pure');$actor=11;TKD_Cloud_Reader::authenticated(new WP_User(11),array());check(code_is(TKD_Cloud_Operations::journal($j),'not_found'),'journal scoped to actor');
reset_state();$r=operation('payment.add_manual');$old_post=$_POST;$x=TKD_Cloud_Operations::execute($r);check($x->data['state']==='recorded_needs_review'&&$x->data['payment_saved']===true&&!$x->data['reservation_sync_verified'],'payment persisted but sync not claimed');check($_POST===$old_post&&$nonce_calls===1,'POST finally restored and nonce after authorization');$x2=TKD_Cloud_Operations::execute($r);check($x2->data===$x->data&&$native_payment_calls===1,'payment replay cannot double-post');check(strpos(json_encode($options),'SYNTHETIC_PERSON')===false&&strpos(json_encode($options),'SYNTHETIC_REFERENCE')===false,'journal stores no payment payload');
reset_state();$r=operation('payment.add_manual');$r->json['payload']['amount']='101.00';check(code_is(TKD_Cloud_Operations::execute($r),'overpayment')&&$native_payment_calls===0&&$nonce_calls===0,'overage prevented');
reset_state();$meta[12]['_tkd_fo_payments']=array(array(array('id'=>'existing','amount'=>'25.00','status'=>'active','received_at'=>'2026-11-17 10:00:00','reference'=>'OTHER')));$r=operation('payment.add_manual');check(code_is(TKD_Cloud_Operations::execute($r),'possible_duplicate')&&$native_payment_calls===0,'possible duplicate rejected');
foreach(array('wayforpay','svitloe_wayforpay','liqpay','pumb_pml','mono_payparts','payparts','novapay')as $channel){reset_state();$r=operation('payment.add_manual');$r->json['payload']['channel_key']=$channel;check(code_is(TKD_Cloud_Operations::execute($r),'payload_schema')&&$native_payment_calls===0,'attested/commission channel unsupported');}
reset_state();$r=operation('payment.add_manual');$r->json['payload']['source']='wayforpay';check(code_is(TKD_Cloud_Operations::execute($r),'payload_schema')&&$nonce_calls===0,'caller cannot fake source');
reset_state();$meta[12]['_tkd_fo_checkout_seller_key']=array('vat');$r=operation('payment.add_manual');check(code_is(TKD_Cloud_Operations::execute($r),'tov_context_review_required')&&$native_payment_calls===0,'TOV no bypass');
reset_state();$r=operation('payment.add_manual');$r->json['payload']['recipient_key']='fop_eo';check(code_is(TKD_Cloud_Operations::execute($r),'seller_channel')&&$native_payment_calls===0,'EO requires EMEO');
reset_state();$r=operation('payment.add_manual');$payment_mode='reject';$x=TKD_Cloud_Operations::execute($r);check($x->data['state']==='failed'&&$x->data['payment_saved']===false&&$_POST===array('unrelated'=>'MUST_RESTORE'),'void native refusal not success');
reset_state();$r=operation('payment.add_manual');$payment_mode='wrong';$x=TKD_Cloud_Operations::execute($r);check($x->data['state']==='unknown'&&$x->data['payment_saved']===null,'unexpected ledger source unknown');
reset_state();$r=operation('payment.add_manual');$payment_mode='throw_after';$x=TKD_Cloud_Operations::execute($r);check($x->data['state']==='recorded_needs_review'&&$x->data['payment_saved']===true&&$_POST===array('unrelated'=>'MUST_RESTORE'),'interruption after record recognized without claiming sync');TKD_Cloud_Operations::execute($r);check($native_payment_calls===1,'interrupted saved payment not repeated');
reset_state();$r=operation();$update_fail=true;$x=TKD_Cloud_Operations::execute($r);check($x->data['state']==='unknown','completion persistence failure unknown');$update_fail=false;TKD_Cloud_Operations::execute($r);check($native_status_calls===1,'lost completion claim still prevents replay');
reset_state();$r=operation();$bad=$profile;$bad['classes']['TKD_FO_Orders']['private_methods']['reservation_manager_is_manual']=str_repeat('a',64);set_pins($bad);$report=TKD_Cloud_Native_Contracts::report('status.change');check(!$report['verified']&&$report['method']==='TKD_FO_Orders::reservation_manager_is_manual'&&preg_match('/^[a-f0-9]{64}$/',$report['actual_sha256']),'private candidate runtime drift diagnostics');check(code_is(TKD_Cloud_Operations::execute($r),'native_drift')&&$business_writes===0&&$journal_writes===0,'drift fail closed');set_pins($profile);
reset_state();$r=operation();$caps['manage_options']=true;$cap_req=new OpRequest();$cap_req->method='GET';$c=TKD_Cloud_Operations::capabilities($cap_req);check(!$c->data['operations']['deferred_payment']['implemented']&&!$c->data['operations']['stock.change']['implemented'],'unsupported operations explicit');
TKD_Cloud_Reader::activate();check($technical_user->roles===array('tkd_cloud_reader')&&$role_assignments===0,'activation never elevates account');check(array_keys($roles['tkd_cloud_operator']->capabilities)===array('read','tkd_cloud_read_orders','tkd_cloud_change_status','tkd_cloud_add_manual_payment','manage_tkd_felicity','tkd_cloud_delivery_draft','tkd_cloud_invoice','tkd_cloud_extend_reservation','tkd_cloud_issue_delivery'),'operator exact caps no admin/edit/delete');
$roles=array('tkd_cloud_reader'=>(object)array('capabilities'=>array('read'=>true,'tkd_cloud_read_orders'=>true)));$_SERVER['REQUEST_METHOD']='POST';$_POST=array('confirm'=>'assign-tkd-cloud');try{TKD_Cloud_Operator_Role::assign();}catch(LogicException $expected){}check($role_assignments===1&&isset($roles['tkd_cloud_operator'])&&$technical_user->roles===array('tkd_cloud_operator'),'ordinary update action creates role without reactivation');
foreach(array('direct','admin','multi','effective') as $case){
 reset_state();$_SERVER['REQUEST_METHOD']='POST';$_POST=array('confirm'=>'assign-tkd-cloud');$caps['manage_options']=true;
 if($case==='direct')$technical_user->caps['manage_options']=true;
 if($case==='admin'){$technical_user->roles=array('administrator');$technical_user->caps=array('administrator'=>true);}
 if($case==='multi')$technical_user->roles[]='subscriber';
 if($case==='effective')$extra_effective=true;
 try{TKD_Cloud_Operator_Role::assign();}catch(RuntimeException $expected){}
 check($role_assignments===0,'unexpected user rights refused '.$case);
}
reset_state();$_SERVER['REQUEST_METHOD']='POST';$_POST=array('confirm'=>'assign-tkd-cloud');$caps['manage_options']=true;
$role_assignments=0;$admin_nonce=false;try{TKD_Cloud_Operator_Role::assign();}catch(RuntimeException $expected){}check($role_assignments===0,'role action nonce required');$admin_nonce=true;$caps['manage_options']=false;try{TKD_Cloud_Operator_Role::assign();}catch(RuntimeException $expected){}check($role_assignments===0,'role action admin required');
reset_state();$r=operation();$meta[12]['_tkd_confirmed_amount']=array('99.00');check(code_is(TKD_Cloud_Operations::execute($r),'stale_revision')&&$native_status_calls===0,'concurrent relevant state invalidates baseline before claim');
reset_state();$r=operation();TKD_Cloud_Operations::execute($r);$actor=11;TKD_Cloud_Reader::authenticated(new WP_User(11),array());check(code_is(TKD_Cloud_Operations::execute($r),'idempotency_mismatch')&&$native_status_calls===1,'operation ID actor scoped');
reset_state();$meta[12]['_tkd_fo_payments']=array(array(array('id'=>'existing','amount'=>'12.00','received_at'=>'2026-11-10 09:00:00','bank_transaction_id'=>'SYNTHETIC_REFERENCE')));$r=operation('payment.add_manual');check(code_is(TKD_Cloud_Operations::execute($r),'possible_duplicate')&&$nonce_calls===0,'bank transaction/reference possible duplicate');
reset_state();$r=operation();$r->extra_body=str_repeat('x',8193);check(code_is(TKD_Cloud_Operations::execute($r),'payload_schema')&&$journal_writes===0,'bounded operation input');
reset_state();$r=operation('payment.add_manual');$r->json['payload']['received_at']='2026-02-31 00:00:00';check(code_is(TKD_Cloud_Operations::execute($r),'payload_schema')&&$nonce_calls===0,'invalid date denied before native');
reset_state();$r=operation();$status_mode='throw';$x=TKD_Cloud_Operations::execute($r);check($x->data['state']==='unknown'&&$depth[12]===0,'native exception releases common lock');check(strpos(json_encode($x->data),'SENSITIVE')===false,'exception detail not emitted');
reset_state();$r=operation();$editor_lock=7;check(code_is(TKD_Cloud_Operations::execute($r),'editor_locked')&&$native_status_calls===0&&$journal_writes===0,'wp_check_post_lock refusal before claim');
reset_state();$meta[12]['_edit_lock']=array(time().':10');$r=operation('payment.add_manual');check(code_is(TKD_Cloud_Operations::execute($r),'editor_locked')&&$native_payment_calls===0&&$nonce_calls===0,'same-account active edit lock also protected');
reset_state();$meta[12]['_edit_lock']=array((time()-1000).':7');$r=operation();check(TKD_Cloud_Operations::execute($r)->data['state']==='complete','expired editor lock is not active');
reset_state();$r=operation();$meta[12]['_edit_lock']=array('malformed');check(TKD_Cloud_Operations::execute($r) instanceof WP_Error&&$business_writes===0,'malformed lock fails closed');
reset_state();$r=operation();TKD_Cloud_Operations::execute($r);$native_status_calls=0;$r->json['order_id']=13;$r->json['payload']['choice']='client_thinking';check(code_is(TKD_Cloud_Operations::execute($r),'idempotency_mismatch')&&$native_status_calls===0,'same operation ID different order/payload cannot claim again');
reset_state();$r=operation();$missing_unique=true;check(code_is(TKD_Cloud_Operations::execute($r),'claim_failed')&&$native_status_calls===0&&$journal_writes===0,'missing runtime unique index fails closed before native');
echo "PASS $count isolated gateway assertions; synthetic services, no live writes\n";

