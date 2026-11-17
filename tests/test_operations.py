import copy
import hashlib
import io
import json
import os
from pathlib import Path
import ssl
import tempfile
import unittest
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import HTTPSHandler, ProxyHandler
from scripts import operations as ops
from scripts.preflight import NoRedirect


class Response(io.BytesIO):
    code = 200
    headers = {'Cache-Control': 'private, no-store'}


class OperationClientTests(unittest.TestCase):
    def setUp(self):
        self.intent = {'operation_id':'11111111-1111-4111-8111-111111111111','operation':'payment.add_manual','order_id':12,'number':'TKD-SYNTHETIC','revision':'a'*64,'payload':{'amount':'25.00','payer_name':'SYNTHETIC_PERSON','reference':'SYNTHETIC_REF','recipient_key':'fop_pm','channel_key':'privatbank','received_at':'2026-11-17 10:00:00','confirm_additional':False}}
        self.digest=hashlib.sha256(json.dumps(self.intent,sort_keys=True,ensure_ascii=True,separators=(',',':')).encode()).hexdigest()
        self.outcome={'operation_id':self.intent['operation_id'],'intent_sha256':self.digest,'operation':'payment.add_manual','state':'recorded_needs_review','reason':'payment_recorded_sync_unverified','payment_saved':True,'reservation_sync_verified':False,'needs_review':True,'retry_native':False}
        self.notfound={'result':'http_error','http_status':404,'code':'tkd_operation_not_found'}

    def transport(self, method, path, payload=None, response=None, status=200):
        p=patch.dict(os.environ,{'TKD_CLOUD_AUTHORIZATION':'SYNTHETIC_LITERAL','https_proxy':'http://managed-proxy.test:8080'},clear=True)
        outer=self
        class Opener:
            def open(self,req,timeout):
                u=urlsplit(req.full_url);outer.assertEqual((u.scheme,u.hostname,u.port),('https','tkd-ukraine.com.ua',443))
                outer.assertEqual(req.get_method(),method);outer.assertEqual(req.get_header('Authorization'),'SYNTHETIC_LITERAL');outer.assertFalse(req.has_header('Cookie'))
                if method=='POST':outer.assertEqual(json.loads(req.data),payload)
                else:outer.assertIsNone(req.data)
                if status != 200:raise HTTPError(req.full_url,status,'SENSITIVE',{},io.BytesIO(json.dumps(response).encode()))
                return Response(json.dumps(response).encode())
        def make(*handlers):
            tls=next(h for h in handlers if isinstance(h,HTTPSHandler))._context
            outer.assertTrue(tls.check_hostname);outer.assertEqual(tls.verify_mode,ssl.CERT_REQUIRED)
            outer.assertTrue(any(isinstance(h,ProxyHandler) for h in handlers));outer.assertTrue(any(isinstance(h,NoRedirect) for h in handlers))
            return Opener()
        with p,patch('scripts.operations.build_opener',side_effect=make):return ops._request(method,path,payload)

    def test_transport_post_literal_binding_tls_no_cookies(self):
        r=self.transport('POST','operations',self.intent,self.outcome)
        self.assertEqual(r['result'],'received')
        for status in (301,302,307,308):
            r=self.transport('POST','operations',self.intent,{'code':'SENSITIVE'},status)
            self.assertEqual(r,{'result':'redirect_not_followed','http_status':status})
        r=self.transport('POST','operations',self.intent,{'code':'tkd_operation_overpayment','message':'SYNTHETIC_PERSON'},409)
        self.assertEqual(r['code'],'tkd_operation_overpayment');self.assertNotIn('SYNTHETIC_PERSON',json.dumps(r))

    def test_routes_and_confirmation_fail_before_network(self):
        with patch('scripts.operations.build_opener') as op:
            self.assertEqual(ops._request('POST','orders/TKD-X')['result'],'invalid_route')
            self.assertEqual(ops._request('GET','operations/../../evil')['result'],'invalid_route')
            self.assertEqual(ops.execute(self.intent)['result'],'explicit_confirmation_required')
            bad=copy.deepcopy(self.intent);bad['operation']='arbitrary.sql'
            self.assertEqual(ops.execute(bad,True)['result'],'invalid_intent')
            op.assert_not_called()

    def test_one_post_and_durable_local_replay_prevention(self):
        with tempfile.TemporaryDirectory() as root:
            calls=[]
            def request(method,path,payload=None):
                calls.append(method)
                if method=='GET':return self.notfound
                return {'result':'received','data':self.outcome,'http_status':200}
            with patch('scripts.operations._request',side_effect=request):
                r=ops.execute(self.intent,True,root)
                self.assertEqual(r['state'],'recorded_needs_review');self.assertEqual(calls,['GET','POST'])
                r=ops.execute(self.intent,True,root)
                self.assertEqual(r['result'],'local_outcome_unknown_no_retry');self.assertEqual(calls,['GET','POST','GET'])
            record=(Path(root)/(self.intent['operation_id']+'.json')).read_text()
            self.assertNotIn('SYNTHETIC_PERSON',record);self.assertNotIn('SYNTHETIC_REF',record)
            self.assertNotIn('25.00',record)

    def test_network_interruption_then_journal_lookup_no_post_retry(self):
        with tempfile.TemporaryDirectory() as root:
            with patch('scripts.operations._request',side_effect=[self.notfound,{'result':'connection_failed'}]) as send:
                self.assertEqual(ops.execute(self.intent,True,root)['result'],'submission_unverified_no_retry')
                self.assertEqual(send.call_count,2)
            with patch('scripts.operations._request',return_value={'result':'received','data':self.outcome,'http_status':200}) as send:
                r=ops.execute(self.intent,True,root)
                self.assertEqual(r['result'],'journal_verified');self.assertEqual(send.call_count,1);self.assertEqual(send.call_args.args[0],'GET')

    def test_server_and_local_digest_mismatch(self):
        with tempfile.TemporaryDirectory() as root:
            bad=copy.deepcopy(self.outcome);bad['intent_sha256']='b'*64
            with patch('scripts.operations._request',return_value={'result':'received','data':bad,'http_status':200}) as send:
                self.assertEqual(ops.execute(self.intent,True,root)['result'],'server_operation_mismatch');self.assertEqual(send.call_count,1)
            altered=copy.deepcopy(self.intent);altered['payload']['amount']='26.00'
            with patch('scripts.operations._request') as send:
                self.assertEqual(ops.execute(altered,True,root)['result'],'local_idempotency_mismatch');send.assert_not_called()

    def test_torn_append_never_authorizes_repost(self):
        with tempfile.TemporaryDirectory() as root:
            path=Path(root)/(self.intent['operation_id']+'.json');path.write_text('{"state":');path.chmod(0o600)
            with patch('scripts.operations._request') as send:
                self.assertEqual(ops.execute(self.intent,True,root)['result'],'local_journal_invalid_no_retry');send.assert_not_called()

    def test_corrupt_or_pii_outcome_never_printed(self):
        for change in ({'reason':'SYNTHETIC_PERSON'},{'reason':{}},{'state':[]},{'operation':{}},{'payer_name':'SYNTHETIC_PERSON'},{'retry_native':True},{'reservation_sync_verified':True},{'state':'complete','needs_review':False,'payment_saved':True}):
            d=dict(self.outcome,**change)
            if change.get('state')=='complete':continue
            with patch('scripts.operations._request',return_value={'result':'received','data':d}):
                r=ops.operation_status(self.intent['operation_id'])
                self.assertEqual(r['result'],'schema_mismatch');self.assertNotIn('SYNTHETIC_PERSON',json.dumps(r))

    def test_snapshot_safe_revision_only(self):
        data={'bridge_version':'0.4.3','schema_version':'tkd-operation-snapshot-v1-candidate','order_id':12,'number':'TKD-SYNTHETIC','status':'new','revision':'a'*64,'revision_scope':'native-baseline-and-fixed-stored-state','native_edit_post':True,'editor_locked':False,'operations':{},'preview_is_acceptance':False}
        with patch('scripts.operations._request',return_value={'result':'received','data':data}):
            r=ops.snapshot('TKD-SYNTHETIC');self.assertEqual(r['result'],'snapshot_verified');self.assertNotIn('number',r);self.assertNotIn('status',r);self.assertFalse(r['preview_is_acceptance'])

    def test_duplicate_json_keys_rejected(self):
        with self.assertRaises(ValueError):json.loads('{"amount":"1","amount":"2"}',object_pairs_hook=ops.json_unique)
