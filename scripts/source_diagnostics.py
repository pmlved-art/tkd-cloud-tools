"""Fixed authenticated GET source diagnostics; metadata only, never source/PII."""
import json
import re
from scripts.operations import _request


def validate(data):
    keys = {'bridge_version', 'schema_version', 'verified', 'complete', 'truncated', 'bound_reason', 'inventory_count', 'checked_count', 'mismatch_count', 'mismatches'}
    if not isinstance(data, dict) or set(data) != keys or data['bridge_version'] != '0.4.4' or data['schema_version'] != 'tkd-source-diagnostics-v1':
        return False
    if any(type(data[k]) is not bool for k in ('verified', 'complete', 'truncated')) or any(type(data[k]) is not int for k in ('inventory_count', 'checked_count', 'mismatch_count')):
        return False
    if not 0 <= data['checked_count'] <= min(1024, data['inventory_count']) or not 0 <= data['inventory_count'] <= 4096 or not 0 <= data['mismatch_count'] <= min(512, data['checked_count']):
        return False
    if data['truncated'] != (not data['complete']) or data['verified'] != (data['complete'] and data['mismatch_count'] == 0):
        return False
    if data['complete']:
        if data['bound_reason'] is not None or data['checked_count'] != data['inventory_count']:
            return False
    elif data['bound_reason'] not in ('inventory_bound', 'mismatch_bound', 'source_bound'):
        return False
    if not isinstance(data['mismatches'], list) or len(data['mismatches']) != data['mismatch_count']:
        return False
    for row in data['mismatches']:
        if not isinstance(row, dict) or row.get('kind') not in ('file', 'method', 'field'):
            return False
        expected = {'kind', 'target', 'reason', 'expected_sha256', 'actual_sha256'} | ({'field_index'} if row['kind'] == 'field' else set())
        if set(row) != expected or row['reason'] not in ('native_missing', 'native_source_unavailable', 'native_visibility_mismatch', 'native_drift', 'native_field_drift'):
            return False
        target = row['target']
        if not isinstance(target, str) or not re.fullmatch(r'TKD_[A-Za-z0-9_]{1,100}' + (r'::[A-Za-z0-9_]{1,100}' if row['kind'] == 'method' else ''), target):
            return False
        if row['kind'] == 'field' and (type(row['field_index']) is not int or not 0 <= row['field_index'] < 256):
            return False
        if not isinstance(row['expected_sha256'], str) or not re.fullmatch('[a-f0-9]{64}', row['expected_sha256']):
            return False
        if row['actual_sha256'] is not None and (not isinstance(row['actual_sha256'], str) or not re.fullmatch('[a-f0-9]{64}', row['actual_sha256'])):
            return False
    return True


def diagnose():
    response = _request('GET', 'source-diagnostics')
    if response.get('result') != 'received':
        return response
    data = response['data']
    if not validate(data):
        return {'result': 'schema_mismatch'}
    return {'result': 'source_diagnostics_verified_shape', 'http_status': 200, **data}


def main():
    result = diagnose()
    print(json.dumps(result))
    return 0 if result.get('verified') is True else 1


if __name__ == '__main__':
    raise SystemExit(main())
