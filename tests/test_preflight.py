import io
import json
import unittest
from urllib.error import HTTPError, URLError
from scripts.preflight import MAX_BODY, NoRedirect, inspect_site, validate_url


class Response(io.BytesIO):
    status = 200


class Opener:
    def __init__(self, body=None, error=None):
        self.body, self.error = body, error

    def open(self, request, timeout):
        if request.get_method() != "GET" or request.data is not None:
            raise AssertionError("Probe must be read-only")
        if request.has_header("Authorization") or request.has_header("Cookie"):
            raise AssertionError("Probe must be anonymous")
        if self.error:
            raise self.error
        return Response(self.body)


class PreflightTests(unittest.TestCase):
    def test_only_exact_https_destinations(self):
        bad = ["http://tkd-ukraine.com.ua/wp-json/",
               "https://tkd-ukraine.com.ua.evil.test/wp-json/",
               "https://user:secret@tkd-ukraine.com.ua/wp-json/",
               "https://tkd-ukraine.com.ua:444/wp-json/",
               "https://tkd-ukraine.com.ua/wp-admin/",
               "https://tkd-ukraine.com.ua/wp-json/?token=secret"]
        for url in bad:
            with self.subTest(url=url), self.assertRaises(ValueError):
                validate_url(url)

    def test_public_index_not_private_access(self):
        body = json.dumps({"namespaces": ["wp/v2"], "authentication": {
            "application-passwords": {}}, "secret_extra": "NOT_FOR_OUTPUT"}).encode()
        result = inspect_site("tkd", Opener(body))
        self.assertEqual(result["result"], "public_index_reachable")
        self.assertFalse(result["private_access_tested"])
        self.assertTrue(result["application_passwords_advertised"])
        self.assertNotIn("NOT_FOR_OUTPUT", json.dumps(result))

    def test_html_is_not_api_success(self):
        result = inspect_site("tkd", Opener(b"<html>login</html>"))
        self.assertEqual(result["result"], "not_json")

    def test_unrelated_json_is_not_api_success(self):
        for body in [b"[]", b' {"ok":true}', b'{"namespaces":[123]}']:
            self.assertEqual(inspect_site("tkd", Opener(body))["result"], "not_wordpress_index")

    def test_redirects_not_followed(self):
        self.assertIsNone(NoRedirect().redirect_request(None, None, 302, "", {}, "https://other.test"))
        error = HTTPError("https://tkd-ukraine.com.ua/wp-json/", 302, "", {}, None)
        self.assertEqual(inspect_site("tkd", Opener(error=error))["result"], "redirect_not_followed")

    def test_denied_is_not_api_success(self):
        error = HTTPError("https://tkd-ukraine.com.ua/wp-json/", 403, "", {}, None)
        result = inspect_site("tkd", Opener(error=error))
        self.assertEqual(result["http_status"], 403)
        self.assertEqual(result["result"], "http_error")

    def test_large_response_bounded(self):
        result = inspect_site("tkd", Opener(b"x" * (MAX_BODY + 1)))
        self.assertEqual(result["result"], "response_too_large")

    def test_error_does_not_expose_details(self):
        result = inspect_site("tkd", Opener(error=URLError("SECRET")))
        self.assertEqual(result["result"], "connection_failed")
        self.assertNotIn("SECRET", json.dumps(result))


if __name__ == "__main__":
    unittest.main()
