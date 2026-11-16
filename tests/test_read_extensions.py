import copy
import io
import json
import os
import ssl
import unittest
from unittest.mock import patch
from urllib.parse import urlsplit, parse_qs
from urllib.request import HTTPSHandler, ProxyHandler
from scripts.preflight import NoRedirect
from scripts.reader import read_bridge
from scripts.read_contracts import CLASSES, META


class Response(io.BytesIO):
    status = 200
    headers = {'Cache-Control': 'private, no-store', 'ETag': '"'+'a'*64+'"'}


class ExtensionClientTests(unittest.TestCase):
    def setUp(self):
        p = patch.dict(os.environ, {'TKD_CLOUD_AUTHORIZATION': 'SYNTHETIC_LITERAL', 'https_proxy': 'http://managed-proxy.test:8080'}, clear=True)
        p.start(); self.addCleanup(p.stop)
        self.manifest = {'bridge_version':'0.2.0', 'schema_version':'tkd-integration-manifest-v1-candidate', 'versions':{'TKD_FO_VERSION':'0.1.223'}, 'classes':[{'name':n, 'present':False,'file_sha256':None,'versions':[],'methods':[]} for n in CLASSES], 'total_methods':0,'stock_schema':{'table_suffix':'tkd_fo_stock','state':'unavailable','columns':[]}}
        self.details = {'bridge_version':'0.2.0','schema_version':'tkd-order-details-v1-candidate','id':12,'number':'TKD-SYNTHETIC','status':'new','post_modified_gmt':'2026-11-17 00:00:00','revision':'a'*64,'revision_scope':'informational-read-snapshot','metadata':{k:{'present':False,'value':None} for k in META}}
        self.details['metadata']['_tkd_order_number']={'present':True,'value':'TKD-SYNTHETIC'}
        self.details['metadata']['_tkd_status']={'present':True,'value':'new'}
        self.details['metadata']['_tkd_customer_name']={'present':True,'value':'DO_NOT_LOG_SYNTHETIC_PERSON'}
        self.details['metadata']['_tkd_fo_order_items']={'present':True,'value':[{'sku':'SYNTHETIC','quantity':'1.125','unit_price':'1.25'}]}
        self.stock={'bridge_version':'0.2.0','schema_version':'tkd-stock-read-v1-candidate','rows':[{'id':'1','sku':'SYNTHETIC-SKU','name':'SYNTHETIC','unit':'pcs','on_hand':'0.1','reserved':'0.3','incoming':'90','active':'1','updated_at':'2026-11-17 00:00:00','available_on_hand_minus_reserved':'-0.200000'}],'next_cursor':None}

    def request(self, data, command, number=None, raw=None, headers=None, include_data=False, **kwargs):
        outer=self
        class Opener:
            def open(self, req, timeout):
                outer.assertEqual(req.get_method(),'GET'); outer.assertIsNone(req.data)
                outer.assertEqual(req.get_header('Authorization'),'SYNTHETIC_LITERAL'); outer.assertFalse(req.has_header('Cookie'))
                u=urlsplit(req.full_url); outer.assertEqual((u.scheme,u.hostname,u.port),('https','tkd-ukraine.com.ua',443))
                paths={'manifest':'integration-manifest','details':'orders/TKD-SYNTHETIC/details','stock':'stock','lookup':'orders'}
                outer.assertEqual(u.path,'/wp-json/tkd-cloud/v1/'+paths[command])
                if command=='lookup': outer.assertEqual(parse_qs(u.query),{'number':['100']})
                r=Response(raw if raw is not None else json.dumps(data).encode())
                if headers is not None:r.headers=headers
                return r
        def make(*handlers):
            tls=next(h for h in handlers if isinstance(h,HTTPSHandler))._context
            outer.assertTrue(tls.check_hostname);outer.assertEqual(tls.verify_mode,ssl.CERT_REQUIRED)
            outer.assertTrue(any(isinstance(h,ProxyHandler) for h in handlers));outer.assertTrue(any(isinstance(h,NoRedirect) for h in handlers))
            return Opener()
        with patch('scripts.reader.build_opener',side_effect=make):
            result=read_bridge(number,command,include_data=include_data,**kwargs)
        output=json.dumps({k:v for k,v in result.items() if k != 'data'})
        for value in ('DO_NOT_LOG_SYNTHETIC_PERSON','SYNTHETIC_LITERAL','TKD-SYNTHETIC','SYNTHETIC-SKU'):
            self.assertNotIn(value,output)
        return result

    def test_details_projection_redaction_and_etag(self):
        self.assertEqual(self.request(self.details,'details','TKD-SYNTHETIC')['result'],'details_verified')
        for key,val in [('quantity','1.0001'),('unit_price','1.001'),('opaque',{'secret':'x'})]:
            d=copy.deepcopy(self.details);d['metadata']['_tkd_fo_order_items']['value'][0][key]=val
            self.assertEqual(self.request(d,'details','TKD-SYNTHETIC')['result'],'schema_mismatch')
        d=copy.deepcopy(self.details);d['metadata']['_tkd_paid_at']={'present':False,'value':'not missing'}
        self.assertEqual(self.request(d,'details','TKD-SYNTHETIC')['result'],'schema_mismatch')
        self.assertEqual(self.request(self.details,'details','TKD-SYNTHETIC',headers={'Cache-Control':'no-store','ETag':'bad'})['result'],'schema_mismatch')

    def test_opt_in_data_is_in_memory_only(self):
        r = self.request(self.details,'details','TKD-SYNTHETIC',include_data=True)
        self.assertEqual(r['data']['metadata']['_tkd_customer_name']['value'],'DO_NOT_LOG_SYNTHETIC_PERSON')
        self.assertNotIn('data', self.request(self.details,'details','TKD-SYNTHETIC'))

    def test_manifest_larger_than_old_cap_and_unknown_fields(self):
        m=copy.deepcopy(self.manifest);c=m['classes'][0];c.update(present=True,file_sha256='b'*64)
        c['methods']=[{'name':'method_'+str(i),'required_args':0,'total_args':1,'source_sha256':'c'*64} for i in range(200)]
        m['total_methods']=200
        self.assertGreater(len(json.dumps(m)),16384)
        self.assertEqual(self.request(m,'manifest')['result'],'manifest_verified')
        for corrupt in ('source','path'):
            bad=copy.deepcopy(m);bad['classes'][0][corrupt]='UNEXPECTED'
            self.assertEqual(self.request(bad,'manifest')['result'],'schema_mismatch')
        m['classes'][0]['methods'] += [{'name':'extra_'+str(i),'required_args':0,'total_args':0,'source_sha256':'c'*64} for i in range(301)]
        m['total_methods']=501
        self.assertEqual(self.request(m,'manifest')['result'],'schema_mismatch')

    def test_endpoint_body_bounds(self):
        self.assertEqual(self.request(self.manifest,'manifest',raw=b'x'*524289)['result'],'response_too_large')
        self.assertEqual(self.request(self.details,'details','TKD-SYNTHETIC',raw=b'x'*131073)['result'],'response_too_large')
        self.assertEqual(self.request(self.stock,'stock',raw=b'x'*131073)['result'],'response_too_large')

    def test_stock_decimal_no_cost_no_incoming_mix(self):
        self.assertEqual(self.request(self.stock,'stock')['result'],'stock_verified')
        for field,value in [('cost_price_uah','1'),('available_on_hand_minus_reserved','89.800000'),('on_hand',0.1),('active','yes')]:
            d=copy.deepcopy(self.stock);d['rows'][0][field]=value
            self.assertEqual(self.request(d,'stock')['result'],'schema_mismatch')
        self.assertEqual(self.request(self.stock,'stock',sku='SYNTHETIC-SKU')['result'],'stock_verified')
        self.assertEqual(self.request(self.stock,'stock',sku='OTHER')['result'],'schema_mismatch')

    def test_lookup_suffix_checks(self):
        d={'id':12,'number':'TKD-20261117-100','status':'new','post_modified_gmt':'2026-11-17 00:00:00','bridge_version':'0.2.0','schema_version':'tkd-order-minimal-v1-candidate'}
        self.assertEqual(self.request(d,'lookup','100')['result'],'order_schema_verified')
        d['number']='TKD-20261117-1100'
        self.assertEqual(self.request(d,'lookup','100')['result'],'schema_mismatch')

    def test_invalid_queries_never_connect(self):
        with patch('scripts.reader.build_opener') as op:
            for args in ({'command':'details'}, {'command':'lookup','number':'10.*'},{'command':'stock','limit':51},{'command':'stock','cursor':'-1'},{'command':'stock','sku':'S','cursor':'1'},{'command':'manifest','number':'TKD-X'}):
                self.assertTrue(read_bridge(**args)['result'].startswith('invalid_'))
            op.assert_not_called()
