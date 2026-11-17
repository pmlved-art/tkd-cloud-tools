import copy
import unittest
from unittest.mock import patch
from scripts import source_diagnostics as diag


class SourceDiagnosticsTests(unittest.TestCase):
    def clean(self):
        return dict(bridge_version='0.4.4',schema_version='tkd-source-diagnostics-v1',verified=True,complete=True,truncated=False,bound_reason=None,inventory_count=3,checked_count=3,mismatch_count=0,mismatches=[])

    def test_clean_and_multiple_redacted_drifts(self):
        data=self.clean()
        self.assertTrue(diag.validate(data))
        data.update(verified=False,mismatch_count=2,mismatches=[dict(kind='file',target='TKD_FO_Payments',reason='native_drift',expected_sha256='a'*64,actual_sha256='b'*64),dict(kind='method',target='TKD_Sales_Fiscal_Gate::status_unscoped',reason='native_missing',expected_sha256='c'*64,actual_sha256=None)])
        self.assertTrue(diag.validate(data))
        with patch('scripts.source_diagnostics._request',return_value={'result':'received','data':data}) as request:
            result=diag.diagnose()
            request.assert_called_once_with('GET','source-diagnostics')
            self.assertFalse(result['verified'])
            self.assertEqual(result['mismatches'],data['mismatches'])

    def test_incomplete_never_verified_and_shape_bounds(self):
        d=self.clean();d.update(verified=False,complete=False,truncated=True,checked_count=1,bound_reason='source_bound')
        self.assertTrue(diag.validate(d))
        for change in ({'verified':True},{'truncated':False},{'bound_reason':None},{'checked_count':1025},{'inventory_count':True},{'mismatch_count':1},{'path':'PRIVATE_SOURCE_PATH'}):
            bad=copy.deepcopy(d);bad.update(change);self.assertFalse(diag.validate(bad))
        d=self.clean();d['checked_count']=2;self.assertFalse(diag.validate(d))

    def test_no_source_paths_or_extra_fields(self):
        data=self.clean();data.update(verified=False,mismatch_count=1,mismatches=[dict(kind='field',target='TKD_FO_Orders',field_index=0,reason='native_field_drift',expected_sha256='a'*64,actual_sha256=None)])
        self.assertTrue(diag.validate(data))
        for change in ({'source':'PRIVATE'},{'target':'/private/server.php'},{'actual_sha256':'raw-source'},{'field_index':-1},{'reason':'stack trace'}):
            bad=copy.deepcopy(data);bad['mismatches'][0].update(change)
            with patch('scripts.source_diagnostics._request',return_value={'result':'received','data':bad}):self.assertEqual(diag.diagnose(),{'result':'schema_mismatch'})
