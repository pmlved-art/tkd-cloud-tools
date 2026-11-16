#!/usr/bin/env python3
"""Fixed-host GET reader. CLI emits redacted verification summaries only."""
import argparse
import json
import os
import re
import ssl
from urllib.error import HTTPError, URLError
from urllib.request import (HTTPSHandler, ProxyHandler, Request, build_opener,
                            getproxies, proxy_bypass)
from scripts.preflight import NoRedirect

BASE = 'https://tkd-ukraine.com.ua:443/wp-json/tkd-cloud/v1/'
SECRET = 'TKD_CLOUD_AUTHORIZATION'
VERSION = '0.1.1'
KNOWN_VERSIONS = ('0.1.0', '0.1.1')
SCHEMA = 'tkd-order-minimal-v1-candidate'
MAX_BODY = 16384


def read_bridge(number=None):
    if number is not None and (not isinstance(number, str) or not re.fullmatch(r'TKD-[A-Za-z0-9-]{1,60}', number)):
        return {'result': 'invalid_number'}
    authorization = os.environ.get(SECRET, '')
    # May be a literal proxy placeholder for the COMPLETE Authorization value.
    # Do not encode it, prepend Basic, print it, or try to resolve it locally.
    if not authorization or len(authorization) > 8192 or any(ord(c) < 32 or ord(c) > 126 for c in authorization):
        return {'result': 'secret_missing_or_invalid'}
    if not getproxies().get('https') or proxy_bypass('tkd-ukraine.com.ua:443'):
        return {'result': 'managed_proxy_required'}
    opener = build_opener(ProxyHandler(), NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
    request = Request(BASE + ('health' if number is None else 'orders/' + number),
                      headers={'Accept': 'application/json', 'Authorization': authorization,
                               'User-Agent': 'TKD-Cloud-Reader/0.1.0'})
    try:
        with opener.open(request, timeout=20) as response:
            body = response.read(MAX_BODY + 1)
            if len(body) > MAX_BODY:
                return {'result': 'response_too_large'}
            if response.status != 200:
                return {'result': 'http_error', 'http_status': response.status}
            if 'no-store' not in response.headers.get('Cache-Control', '').lower():
                return {'result': 'cache_policy_mismatch'}
            data = json.loads(body)
            if not isinstance(data, dict):
                return {'result': 'schema_mismatch'}
            expected = {'bridge_version', 'schema_version'}
            expected |= {'post_type_exists'} if number is None else {'id', 'number', 'status', 'post_modified_gmt'}
            valid = set(data) == expected and data.get('bridge_version') in KNOWN_VERSIONS and data.get('schema_version') == SCHEMA
            if number is None:
                valid = valid and data.get('post_type_exists') is True
            else:
                from datetime import datetime
                valid = valid and type(data.get('id')) is int and data['id'] > 0 and data.get('number') == number
                valid = valid and isinstance(data.get('status'), str) and re.fullmatch(r'[A-Za-z0-9_-]{1,64}', data['status']) is not None
                modified = data.get('post_modified_gmt')
                valid = valid and isinstance(modified, str) and re.fullmatch(r'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}', modified) is not None
                if valid:
                    datetime.strptime(modified, '%Y-%m-%d %H:%M:%S')
            if not valid:
                return {'result': 'schema_mismatch'}
            # Never return/log response body, order number, id, status or headers.
            return {'result': 'health_verified' if number is None else 'order_schema_verified',
                    'http_status': 200, 'bridge_version': data['bridge_version'], 'schema_version': SCHEMA}
    except HTTPError as error:
        code = error.code
        error.close()
        return {'result': 'redirect_not_followed' if 300 <= code < 400 else 'http_error', 'http_status': code}
    except (URLError, TimeoutError, OSError):
        return {'result': 'connection_failed'}
    except (ValueError, UnicodeError, TypeError):
        return {'result': 'schema_mismatch'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--number', help='One full TKD number; omit for health. Avoid shared shell history.')
    args = parser.parse_args()
    result = read_bridge(args.number)
    print(json.dumps(result))
    return 0 if result['result'] in ('health_verified', 'order_schema_verified') else 1


if __name__ == '__main__':
    raise SystemExit(main())
