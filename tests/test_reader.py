import io
import json
import os
import ssl
import unittest
from unittest.mock import patch
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPSHandler, ProxyHandler
from scripts.reader import read_bridge, MAX_BODY, VERSION, SCHEMA
from scripts.preflight import NoRedirect


class Response(io.BytesIO):
    status = 200
    headers = {'Cache-Control': 'private, no-store'}


class ReaderTests(unittest.TestCase):
    def setUp(self):
        self.env = patch.dict(os.environ, {'TKD_CLOUD_AUTHORIZATION': 'LITERAL_TEST_PLACEHOLDER'}, clear=True)
        self.env.start()
        self.addCleanup(self.env.stop)
        for name, value in [('getproxies', {'https': 'http://managed-proxy.test:8080'}), ('proxy_bypass', False)]:
            p = patch('scripts.reader.' + name, return_value=value)
            p.start()
            self.addCleanup(p.stop)
        self.data = {'bridge_version': VERSION, 'schema_version': SCHEMA, 'post_type_exists': True}

    def run_response(self, data=None, number=None, body=None, error=None, cache=True):
        captured = []
        outer = self
        class Opener:
            def open(self, request, timeout):
                u = urlsplit(request.full_url)
                outer.assertEqual((u.scheme, u.hostname, u.port), ('https', 'tkd-ukraine.com.ua', 443))
                outer.assertEqual(request.get_method(), 'GET')
                outer.assertIsNone(request.data)
                outer.assertEqual(request.get_header('Authorization'), 'LITERAL_TEST_PLACEHOLDER')
                outer.assertFalse(request.has_header('Cookie'))
                outer.assertEqual(u.path, '/wp-json/tkd-cloud/v1/' + ('health' if number is None else 'orders/' + number))
                if error: raise error
                r = Response(body if body is not None else json.dumps(data if data is not None else outer.data).encode())
                if not cache: r.headers = {}
                return r
        def opener(*handlers):
            captured.extend(handlers)
            return Opener()
        with patch('scripts.reader.build_opener', side_effect=opener):
            result = read_bridge(number)
        tls = next(h for h in captured if isinstance(h, HTTPSHandler))._context
        self.assertTrue(tls.check_hostname)
        self.assertEqual(tls.verify_mode, ssl.CERT_REQUIRED)
        self.assertTrue(any(isinstance(h, ProxyHandler) for h in captured))
        self.assertTrue(any(isinstance(h, NoRedirect) for h in captured))
        self.assertNotIn('LITERAL_TEST_PLACEHOLDER', json.dumps(result))
        return result

    def test_health_literal_binding_tls_proxy_and_get(self):
        self.assertEqual(self.run_response()['result'], 'health_verified')

    def test_known_versions_only(self):
        for version in ('0.1.0', '0.1.1'):
            r = self.run_response(dict(self.data, bridge_version=version))
            self.assertEqual(r['result'], 'health_verified')
            self.assertEqual(r['bridge_version'], version)
        self.assertEqual(self.run_response(dict(self.data, bridge_version='0.1.2'))['result'], 'schema_mismatch')

    def test_order_redacted(self):
        d = {'bridge_version': VERSION, 'schema_version': SCHEMA, 'id': 12, 'number': 'TKD-SYNTHETIC', 'status': 'new', 'post_modified_gmt': '2026-11-17 00:00:00'}
        r = self.run_response(d, 'TKD-SYNTHETIC')
        self.assertEqual(r['result'], 'order_schema_verified')
        self.assertNotIn('TKD-SYNTHETIC', json.dumps(r))
        for key in ('id', 'number', 'status', 'post_modified_gmt'): self.assertNotIn(key, r)
        for key, value in [('number','TKD-OTHER'),('status', ['new']),('post_modified_gmt','2026-02-31 00:00:00'),('id',True)]:
            bad = dict(d); bad[key] = value
            self.assertEqual(self.run_response(bad, 'TKD-SYNTHETIC')['result'], 'schema_mismatch')

    def test_invalid_number_no_network(self):
        with patch('scripts.reader.build_opener') as op:
            for n in ('100', 'tkd-1', 'TKD-../x', 'TKD-X?token=x', 'TKD-'+'x'*61):
                self.assertEqual(read_bridge(n)['result'], 'invalid_number')
            op.assert_not_called()

    def test_secret_missing_and_header_injection(self):
        for value in ('', 'bad\r\nCookie: x', '\u2603'):
            os.environ['TKD_CLOUD_AUTHORIZATION'] = value
            self.assertEqual(read_bridge()['result'], 'secret_missing_or_invalid')

    def test_no_proxy_or_bypass_refused(self):
        with patch('scripts.reader.getproxies', return_value={}):
            self.assertEqual(read_bridge()['result'], 'managed_proxy_required')
        with patch('scripts.reader.proxy_bypass', return_value=True):
            self.assertEqual(read_bridge()['result'], 'managed_proxy_required')

    def test_redirect_and_errors_never_log_bodies(self):
        for code in (301,302,307,308,401,403,409,503):
            e = HTTPError('https://untrusted.test/SECRET', code, 'SECRET', {}, io.BytesIO(b'CUSTOMER SECRET'))
            r = self.run_response(error=e)
            self.assertEqual(r['http_status'], code)
            self.assertEqual(r['result'], 'redirect_not_followed' if code < 400 else 'http_error')
            self.assertNotIn('SECRET', json.dumps(r))
        self.assertEqual(self.run_response(error=URLError('SECRET'))['result'], 'connection_failed')

    def test_schema_and_bounds(self):
        for d in ([], {}, dict(self.data, email='SYNTHETIC'), dict(self.data, bridge_version='unexpected'),dict(self.data,post_type_exists=False)):
            self.assertEqual(self.run_response(d)['result'], 'schema_mismatch')
        self.assertEqual(self.run_response(body=b'x'*(MAX_BODY+1))['result'], 'response_too_large')
        self.assertEqual(self.run_response(body=b'not-json')['result'], 'schema_mismatch')
        self.assertEqual(self.run_response(cache=False)['result'], 'cache_policy_mismatch')
