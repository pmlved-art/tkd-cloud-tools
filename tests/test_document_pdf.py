import io
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from urllib.error import HTTPError
from scripts import document_pdf as pdf


class Response(io.BytesIO):
    code = 200
    headers = {'Content-Type':'application/pdf','Cache-Control':'private, no-store'}


class PDFTests(unittest.TestCase):
    def call(self, path, body=b'%PDF-1.4\nSYNTHETIC_ONLY', headers=None, error=None):
        class Opener:
            def open(self, req, timeout):
                if req.get_method() != 'GET' or req.data is not None or req.has_header('Cookie'):
                    raise AssertionError('Only GET/no cookies')
                if error:
                    raise error
                response=Response(body)
                response.headers=headers or Response.headers
                return response
        with patch.dict(os.environ, {'TKD_CLOUD_AUTHORIZATION':'SYNTHETIC_PROXY_BINDING'}),patch.object(pdf,'getproxies',return_value={'https':'managed'}),patch.object(pdf,'proxy_bypass',return_value=False),patch.object(pdf,'build_opener',return_value=Opener()):
            return pdf.download_pdf('TKD-SYNTHETIC',20,path)

    def test_download_private_pdf_no_overwrite(self):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'synthetic.pdf'
            result=self.call(path)
            self.assertEqual(result['result'],'pdf_downloaded')
            self.assertEqual(path.stat().st_mode & 0o777,0o600)
            self.assertEqual(self.call(path)['result'],'output_exists_no_overwrite')
            self.assertNotIn('SYNTHETIC_PROXY_BINDING',str(result))

    def test_bad_pdf_cache_or_size_not_saved(self):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'synthetic.pdf'
            for body,headers in [(b'<html>SYNTHETIC_PRIVATE_ERROR</html>',None),(b'%PDF-1.4\n',{'Content-Type':'application/pdf','Cache-Control':'public'}),(b'%PDF-1.4\n'+b'x'*pdf.MAX_PDF,None)]:
                self.assertEqual(self.call(path,body,headers)['result'],'pdf_contract_mismatch')
                self.assertFalse(path.exists())
            self.assertEqual(self.call(path,error=HTTPError('https://tkd-ukraine.com.ua',302,'',{},None))['result'],'redirect_not_followed')
