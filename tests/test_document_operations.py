import copy
import tempfile
import unittest
from unittest.mock import patch
from scripts import operations as op


class DocumentOperationsTests(unittest.TestCase):
    def intent(self, operation, payload):
        return {'operation_id':'33333333-3333-4333-8333-333333333333','operation':operation,'order_id':12,'number':'TKD-SYNTHETIC','revision':'a'*64,'payload':payload}

    def test_fixed_document_payloads(self):
        for operation,payload in [('invoice.draft',{}),('delivery.draft',{'invoice_id':20}),('invoice.issue',{'invoice_id':20,'number':'SYNTH-2','date':'2026-11-17'}),('reservation.extend',{'hours':48}),('shipment.issue',{'delivery_id':30})]:
            self.assertTrue(op._intent_ok(self.intent(operation,payload)))
            bad=copy.deepcopy(payload);bad['callback']='arbitrary'
            self.assertFalse(op._intent_ok(self.intent(operation,bad)))
        for hours in (4,24,73,'48',True):
            self.assertFalse(op._intent_ok(self.intent('reservation.extend',{'hours':hours})))
        self.assertFalse(op._intent_ok(self.intent('invoice.issue',{'invoice_id':20,'number':'SYNTH','date':'2026-02-30'})))

    def test_get_document_snapshot_projection(self):
        data={'documents':[{'id':12,'type':'tkd_order'}],'bridge_version':'0.4.0','schema_version':'tkd-document-operation-snapshot-v1-candidate','order_id':12,'revision':'a'*64,'operation':'reservation.extend','native_edit_post':True,'editor_locked':False,'preview_is_acceptance':False}
        with patch.object(op,'_request',return_value={'result':'received','data':data}) as request:
            result=op.document_snapshot('TKD-SYNTHETIC','reservation.extend',{'hours':48})
            self.assertEqual(result['result'],'document_snapshot_verified')
            self.assertEqual(request.call_args.args[0],'GET')
            self.assertNotIn('Authorization',str(request.call_args))
            data['extra']='SYNTHETIC_PII'
            self.assertEqual(op.document_snapshot('TKD-SYNTHETIC','reservation.extend',{'hours':48})['result'],'schema_mismatch')

    def test_document_outcome_and_no_post_retry(self):
        intent=self.intent('reservation.extend',{'hours':48})
        import hashlib,json
        digest=hashlib.sha256(json.dumps(intent,sort_keys=True,ensure_ascii=True,separators=(',',':')).encode()).hexdigest()
        outcome={'operation_id':intent['operation_id'],'intent_sha256':digest,'operation':'reservation.extend','state':'complete','reason':'document_verified','payment_saved':None,'reservation_sync_verified':False,'needs_review':False,'retry_native':False}
        self.assertTrue(op._outcome(outcome,intent['operation_id']))
        with tempfile.TemporaryDirectory() as folder:
            with patch.object(op,'operation_status',return_value={'result':'http_error','http_status':404,'code':'tkd_operation_not_found'}),patch.object(op,'_request',return_value={'result':'connection_failed'}) as request:
                self.assertEqual(op.execute(intent,True,folder)['result'],'submission_unverified_no_retry')
                self.assertEqual(op.execute(intent,True,folder)['result'],'local_outcome_unknown_no_retry')
                self.assertEqual(request.call_count,1)
        outcome.update(operation='shipment.issue',state='recorded_needs_review',reason='document_needs_review',needs_review=True)
        self.assertTrue(op._outcome(outcome,intent['operation_id']))
        outcome['payment_saved']=True
        self.assertFalse(op._outcome(outcome,intent['operation_id']))
