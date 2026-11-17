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
        data={'office_revision':None,'documents':[],'bridge_version':'0.4.1','schema_version':'tkd-document-operation-snapshot-v1-candidate','order_id':12,'revision':'a'*64,'operation':'reservation.extend','native_edit_post':True,'editor_locked':False,'preview_is_acceptance':False}
        with patch.object(op,'_request',return_value={'result':'received','data':data}) as request:
            result=op.document_snapshot('TKD-SYNTHETIC','reservation.extend',{'hours':48})
            self.assertEqual(result['result'],'document_snapshot_verified')
            self.assertEqual(request.call_args.args[0],'GET')
            self.assertNotIn('Authorization',str(request.call_args))
            data['extra']='SYNTHETIC_PII'
            self.assertEqual(op.document_snapshot('TKD-SYNTHETIC','reservation.extend',{'hours':48})['result'],'schema_mismatch')

    def test_private_projection_and_save_scope(self):
        payload={'delivery_id':30,'items':[{'source_index':0,'quantity':'1.000','serial_numbers':'111111111111111111'}]}
        self.assertTrue(op._intent_ok(self.intent('delivery.save',payload)))
        doc={'id':20,'type':'tkd_invoice','order_id':12,'invoice_id':20,'number':'SYNTH-INVOICE','date':'2026-11-17','status':'issued','seller_key':'fop_pm','bank_key':'privat','items':[{'source_index':0,'sku':'SYNTH','name':'SYNTHETIC_PRIVATE_ITEM','unit':'pcs','quantity':'1.000','unit_price':'100.00','serial_numbers':'111111111111111111'}]}
        d={'bridge_version':'0.4.0','schema_version':'tkd-document-operation-snapshot-v1-candidate','order_id':12,'revision':'a'*64,'operation':'delivery.save','native_edit_post':True,'editor_locked':False,'preview_is_acceptance':False,'office_revision':'b'*64,'documents':[doc]}
        with patch.object(op,'_request',return_value={'result':'received','data':d}) as request:
            summary=op.document_snapshot('TKD-SYNTHETIC','delivery.save',payload)
            self.assertEqual(summary['result'],'document_snapshot_verified')
            self.assertNotIn('SYNTHETIC_PRIVATE_ITEM',str(summary))
            self.assertNotIn('111111111111111111',str(summary))
            self.assertNotIn('111111111111111111',request.call_args.args[1])
            self.assertIn('payload_digest=',request.call_args.args[1])
            private=op.document_snapshot('TKD-SYNTHETIC','delivery.save',payload,include_data=True)
            self.assertEqual(private['data']['documents'][0]['number'],'SYNTH-INVOICE')
        doc['private_token']='SYNTHETIC_REJECT'
        self.assertFalse(op.document_projection_ok([doc]))

    def test_persisted_facts_and_invoice_reserve_semantics(self):
        self.assertTrue(op.facts_ok('reservation.extend',{'reservation':{'deadline':2000000000,'total_hours':48}}))
        self.assertFalse(op.facts_ok('reservation.extend',{'reservation':{'deadline':2000000000,'total_hours':4}}))
        self.assertTrue(op.facts_ok('shipment.issue',{'shipment':{'posted':True,'prepared':True,'dispatch_state':'awaiting_handover','physical_confirmation':False}}))
        intent=self.intent('invoice.issue',{'invoice_id':20,'number':'SYNTH','date':'2026-11-17'})
        outcome={'operation_id':intent['operation_id'],'intent_sha256':'a'*64,'operation':'invoice.issue','state':'complete','reason':'document_verified','payment_saved':None,'reservation_sync_verified':False,'needs_review':False,'retry_native':False}
        self.assertFalse(op._outcome(outcome,intent['operation_id']))
        outcome['reservation_sync_verified']=True
        self.assertTrue(op._outcome(outcome,intent['operation_id']))

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

    def test_patch_capabilities_returning_drift_is_redacted_and_validated(self):
        supported = {'status.change', 'payment.add_manual'} | op.DOCUMENT_OPERATIONS
        unsupported = {'deferred_payment','items.edit','invoice.create','reservation.change','stock.change','payment.attest_bank','payment.attribute_legacy','payment.tov'}
        operations = {name: {'implemented': True, 'actor_capable': True, 'contracts': {'verified':True,'reason':'matched'}} for name in supported}
        operations.update({name: {'implemented':False,'reason':'unsupported'} for name in unsupported})
        report = {'verified':False,'reason':'native_drift','method':'TKD_Returning_Customer_20261114::accept','expected_sha256':'a'*64,'actual_sha256':'b'*64}
        operations['invoice.draft']['contracts'] = report
        data = {'bridge_version':'0.4.1','operations':operations,'manual_channels':['pumb','privatbank','raiffeisen','bank_emeo'],'preview_is_acceptance':False}
        with patch('scripts.operations._request',return_value={'result':'received','data':data}):
            result = op.capabilities()
            self.assertEqual(result['result'],'capabilities_verified')
            self.assertEqual(result['operations']['invoice.draft']['contracts'],report)
            report['method']='TKD_Returning_Arbitrary::accept'
            self.assertEqual(op.capabilities()['result'],'schema_mismatch')
