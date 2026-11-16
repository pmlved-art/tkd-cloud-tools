#!/usr/bin/env python3
"""Public HTTPS checks only. No credentials, private data, or writes."""
import argparse
import json
import ssl
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, HTTPSHandler, ProxyHandler, Request, build_opener

SITES = {
    "tkd": "https://tkd-ukraine.com.ua",
    "ti": "https://ti-group.com.ua",
    "solar": "https://www.felicity-solar.shop",
    "svitloe": "https://svitloe.online",
}
MAX_BODY = 2 * 1024 * 1024


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def validate_url(url):
    parsed = urlsplit(url)
    allowed = {urlsplit(value).netloc for value in SITES.values()}
    if (parsed.scheme != "https" or parsed.netloc not in allowed
            or parsed.username or parsed.password or parsed.query or parsed.fragment
            or parsed.path not in ("/", "/wp-json/")):
        raise ValueError("Only fixed public HTTPS probes are permitted")
    return url


def inspect_site(site, opener=None):
    url = validate_url(SITES[site] + "/wp-json/")
    # Disable implicit proxies/.netrc credentials. Network access may be provided
    # transparently by the managed environment; never add a secret to this probe.
    opener = opener or build_opener(ProxyHandler({}), NoRedirect(),
                                   HTTPSHandler(context=ssl.create_default_context()))
    request = Request(url, headers={"Accept": "application/json",
                                   "User-Agent": "TKD-Cloud-Public-Preflight/1.0"})
    result = {"site": site, "url": url, "private_access_tested": False}
    try:
        with opener.open(request, timeout=20) as response:
            result["http_status"] = response.status
            body = response.read(MAX_BODY + 1)
            if len(body) > MAX_BODY:
                result["result"] = "response_too_large"
                return result
            try:
                data = json.loads(body)
            except (ValueError, UnicodeError):
                result["result"] = "not_json"
                return result
            namespaces = data.get("namespaces") if isinstance(data, dict) else None
            if not isinstance(namespaces, list) or not all(isinstance(n, str) for n in namespaces):
                result["result"] = "not_wordpress_index"
                return result
            result["result"] = "public_index_reachable"
            result["namespaces"] = namespaces
            auth = data.get("authentication", {})
            result["application_passwords_advertised"] = (
                isinstance(auth, dict) and "application-passwords" in auth)
    except HTTPError as error:
        result.update(http_status=error.code,
                      result="redirect_not_followed" if 300 <= error.code < 400 else "http_error")
        if error.fp is not None:
            error.close()
    except (URLError, TimeoutError, OSError):
        # Avoid logging exception content, URLs supplied by upstream servers,
        # response bodies, cookies, or any accidental request secrets.
        result["result"] = "connection_failed"
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", choices=[*SITES, "all"], default="tkd")
    args = parser.parse_args()
    results = [inspect_site(key) for key in (SITES if args.site == "all" else [args.site])]
    print(json.dumps(results, ensure_ascii=False, indent=2))
    return 0 if all(row["result"] == "public_index_reachable" for row in results) else 1


if __name__ == "__main__":
    sys.exit(main())
