#!/usr/bin/env python3
"""Fixed-host GET reader. CLI emits redacted verification summaries only."""
import argparse
import json
import os
import re
import ssl
from urllib.parse import urlencode
from scripts.read_contracts import SCHEMAS, validate_details, validate_manifest, validate_stock
from urllib.error import HTTPError, URLError
from urllib.request import (HTTPSHandler, ProxyHandler, Request, build_opener,
                            getproxies, proxy_bypass)
from scripts.preflight import NoRedirect

BASE = 'https://tkd-ukraine.com.ua:443/wp-json/tkd-cloud/v1/'
SECRET = 'TKD_CLOUD_AUTHORIZATION'
VERSION = '0.4.1'
KNOWN_VERSIONS = ('0.1.0', '0.1.1', '0.2.0', '0.3.0', '0.3.1', '0.4.0', '0.4.1')
SCHEMA = 'tkd-order-minimal-v1-candidate'
MAX_BODY = 16384


def read_bridge(number=None, command=None, limit=25, cursor='0', sku=None, include_data=False):
    command = command or ('health' if number is None else 'order')
    if command not in ('health', 'order', 'details', 'manifest', 'lookup', 'stock'):
        return {'result': 'invalid_command'}
    if command in ('order', 'details') and number is None:
        return {'result': 'invalid_number'}
    if command == 'lookup':
        if not isinstance(number, str) or not re.fullmatch(r'(?:[0-9]{1,18}|TKD-[A-Za-z0-9-]{1,60})', number):
            return {'result': 'invalid_number'}
    elif command in ('health', 'manifest', 'stock') and number is not None:
        return {'result': 'invalid_number'}
    if command in ('order', 'details') and (not isinstance(number, str) or not re.fullmatch(r'TKD-[A-Za-z0-9-]{1,60}', number)):
        return {'result': 'invalid_number'}
    if command == 'stock' and (type(limit) is not int or not 1 <= limit <= 50 or not isinstance(cursor, str) or not re.fullmatch(r'[0-9]{1,18}', cursor) or sku is not None and (not isinstance(sku, str) or not 1 <= len(sku.encode('utf-8')) <= 128 or re.search(r'[\x00-\x1f\x7f]', sku) or cursor != '0')):
        return {'result': 'invalid_query'}
    paths = {'health': 'health', 'manifest': 'integration-manifest'}
    if command in ('order', 'details'):
        path = 'orders/' + number + ('/details' if command == 'details' else '')
    elif command == 'lookup':
        path = 'orders?' + urlencode({'number': number})
    elif command == 'stock':
        path = 'stock?' + urlencode({'sku': sku} if sku is not None else {'limit': limit, 'cursor': cursor})
    else:
        path = paths[command]
    body_limit = 524288 if command == 'manifest' else 131072 if command in ('details', 'stock') else MAX_BODY
    authorization = os.environ.get(SECRET, '')
    # May be a literal proxy placeholder for the COMPLETE Authorization value.
    # Do not encode it, prepend Basic, print it, or try to resolve it locally.
    if not authorization or len(authorization) > 8192 or any(ord(c) < 32 or ord(c) > 126 for c in authorization):
        return {'result': 'secret_missing_or_invalid'}
    if not getproxies().get('https') or proxy_bypass('tkd-ukraine.com.ua:443'):
        return {'result': 'managed_proxy_required'}
    opener = build_opener(ProxyHandler(), NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
    request = Request(BASE + path,
                      headers={'Accept': 'application/json', 'Authorization': authorization,
                               'User-Agent': 'TKD-Cloud-Reader/0.4.1'})
    try:
        with opener.open(request, timeout=20) as response:
            body = response.read(body_limit + 1)
            if len(body) > body_limit:
                return {'result': 'response_too_large'}
            if response.status != 200:
                return {'result': 'http_error', 'http_status': response.status}
            if 'no-store' not in response.headers.get('Cache-Control', '').lower():
                return {'result': 'cache_policy_mismatch'}
            data = json.loads(body)
            if not isinstance(data, dict):
                return {'result': 'schema_mismatch'}
            if command in SCHEMAS:
                valid = data.get('bridge_version') in ('0.2.0', '0.3.0', '0.3.1', '0.4.0', '0.4.1') and (data.get('schema_version') == SCHEMAS[command] or command == 'stock' and data.get('schema_version') == 'tkd-stock-read-v1-candidate' and data.get('bridge_version') == '0.2.0')
                if command == 'manifest':
                    valid = valid and validate_manifest(data)
                elif command == 'details':
                    valid = valid and validate_details(data, number, response.headers)
                else:
                    valid = valid and validate_stock(data, limit, sku)
                if not valid:
                    return {'result': 'schema_mismatch'}
                summary = {'result': command + '_verified', 'http_status': 200,
                           'bridge_version': data['bridge_version'], 'schema_version': data['schema_version']}
                if include_data:
                    summary['data'] = data  # Opt-in in-memory API only; CLI never enables this.
                return summary
            if command == 'lookup':
                found = data.get('number')
                if not isinstance(found, str) or not re.fullmatch(r'TKD-[A-Za-z0-9-]{1,60}', found) or not (found == number if number.startswith('TKD-') else re.fullmatch(r'TKD-(?:[A-Za-z0-9-]+-)?' + number, found)):
                    return {'result': 'schema_mismatch'}
                number = found
            expected = {'bridge_version', 'schema_version'}
            expected |= {'post_type_exists'} if number is None else {'id', 'number', 'status', 'post_modified_gmt'}
            valid = set(data) == expected and data.get('bridge_version') in KNOWN_VERSIONS and data.get('schema_version') == SCHEMA
            if command == 'health':
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
            return {'result': 'health_verified' if command == 'health' else 'order_schema_verified',
                    'http_status': 200, 'bridge_version': data['bridge_version'], 'schema_version': SCHEMA}
    except HTTPError as error:
        code = error.code
        error.close()
        return {'result': 'redirect_not_followed' if 300 <= code < 400 else 'http_error', 'http_status': code}
    except (URLError, TimeoutError, OSError):
        return {'result': 'connection_failed'}
    except (ValueError, UnicodeError, TypeError, KeyError, OverflowError):
        return {'result': 'schema_mismatch'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--command', choices=('health', 'order', 'details', 'manifest', 'lookup', 'stock'))
    parser.add_argument('--limit', type=int, default=25)
    parser.add_argument('--cursor', default='0')
    parser.add_argument('--sku')
    parser.add_argument('--number', help='One full TKD number; omit for health. Avoid shared shell history.')
    args = parser.parse_args()
    result = read_bridge(args.number, args.command, args.limit, args.cursor, args.sku)
    print(json.dumps(result))
    return 0 if result['result'] in ('health_verified', 'order_schema_verified', 'details_verified', 'manifest_verified', 'stock_verified') else 1


if __name__ == '__main__':
    raise SystemExit(main())
