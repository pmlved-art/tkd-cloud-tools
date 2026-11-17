#!/usr/bin/env python3
"""Explicit native operations. One POST at most; durable local/server replay checks."""
from datetime import datetime
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
import sys
import uuid
from urllib.parse import urlencode
from urllib.error import HTTPError, URLError
from urllib.request import Request, build_opener, ProxyHandler, HTTPSHandler, getproxies, proxy_bypass
from scripts.preflight import NoRedirect

BASE = 'https://tkd-ukraine.com.ua:443/wp-json/tkd-cloud/v1/'
UUID = re.compile(r'[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}')
NUMBER = re.compile(r'TKD-[A-Za-z0-9-]{1,60}')
DOCUMENT_OPERATIONS = {'delivery.save', 'delivery.draft', 'invoice.draft', 'invoice.issue', 'reservation.extend', 'shipment.issue'}
STATES = {'complete', 'failed', 'unknown', 'recorded_needs_review'}
DOCUMENT_ERRORS = {'closed_order','document_bound','document_identity','document_access','document_schema','rest_context_required','installment_unsupported','invoice_identity_or_state','delivery_identity_or_state','delivery_items_unverified','state_unverified','closed_or_paid','payment_or_confirmation','protected_stock','no_active_reserve','reserve_expired','total_deadline_expired_or_missing_start'}
SAFE_ERRORS = {'tkd_application_password_required', 'tkd_forbidden', 'tkd_https_required', 'rest_not_logged_in'} | {'tkd_operation_' + x for x in ('forbidden', 'payload_schema', 'order_identity', 'ambiguous_state', 'state_bound', 'invoice_schema', 'baseline_schema', 'invalid_id', 'not_found', 'split_invoice_review_required', 'tov_context_review_required', 'bank_attestation_required', 'seller_channel', 'emeo_reference', 'money_schema', 'total_required', 'ledger_schema', 'possible_duplicate', 'legacy_payment_ambiguous', 'overpayment', 'busy', 'editor_locked', 'edit_lock_schema', 'edit_lock_unavailable', 'lock_unavailable', 'idempotency_mismatch', 'stale_revision', 'claim_failed', 'state_unavailable', 'native_drift', 'native_missing', 'native_source_unavailable', 'native_visibility_mismatch')}
SAFE_ERRORS |= {'tkd_operation_' + x for x in DOCUMENT_ERRORS}
REASONS = {'interrupted_or_in_progress_no_retry', 'native_status_verified', 'native_effect_unverified', 'native_not_accepted', 'payment_recorded_sync_unverified', 'payment_recorded_native_interrupted', 'native_interrupted', 'journal_persistence_failed', 'mobile_access', 'mobile_status', 'mobile_busy', 'mobile_stale', 'mobile_document', 'mobile_protected', 'mobile_paid', 'mobile_money', 'mobile_invoice', 'mobile_link', 'document_verified', 'document_needs_review'}


def json_unique(pairs):
    d = {}
    for k, v in pairs:
        if k in d:
            raise ValueError('duplicate JSON key')
        d[k] = v
    return d


def _request(method, path, payload=None):
    permitted = (method == 'POST' and path == 'operations') or (method == 'GET' and (path == 'capabilities' or re.fullmatch(r'orders/TKD-[A-Za-z0-9-]{1,60}/operation-snapshot', path) or re.fullmatch(r'orders/TKD-[A-Za-z0-9-]{1,60}/document-snapshot\?operation=[A-Za-z.]+&scope=[A-Za-z0-9%+_.~\-]+&payload_digest=[a-f0-9]{64}', path) or path.startswith('operations/') and UUID.fullmatch(path[11:])))
    if not permitted:
        return {'result': 'invalid_route'}
    auth = os.environ.get('TKD_CLOUD_AUTHORIZATION', '')
    if not auth or len(auth) > 8192 or any(ord(c) < 32 or ord(c) > 126 for c in auth):
        return {'result': 'secret_missing_or_invalid'}
    if not getproxies().get('https') or proxy_bypass('tkd-ukraine.com.ua:443'):
        return {'result': 'managed_proxy_required'}
    try:
        opener = build_opener(ProxyHandler(), NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
        headers = {'Accept': 'application/json', 'Authorization': auth, 'User-Agent': 'TKD-Cloud-Operations/0.4.2'}
        body = None
        if method == 'POST':
            body = json.dumps(payload, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode()
            if len(body) > 8192:
                return {'result': 'payload_bound'}
            headers['Content-Type'] = 'application/json'
        request = Request(BASE + path, data=body, method=method, headers=headers)
        try:
            response = opener.open(request, timeout=30)
        except HTTPError as error:
            response = error
        with response:
            limit = 262144 if '/document-snapshot?' in path else 65536
            raw = response.read(limit + 1)
            if len(raw) > limit:
                return {'result': 'response_bound', 'http_status': response.code}
            if 300 <= response.code < 400:
                return {'result': 'redirect_not_followed', 'http_status': response.code}
            d = json.loads(raw, object_pairs_hook=json_unique)
            if response.code != 200:
                return {'result': 'http_error', 'http_status': response.code, 'code': d.get('code') if isinstance(d, dict) and d.get('code') in SAFE_ERRORS else 'unclassified_error'}
            if 'no-store' not in response.headers.get('Cache-Control', '').lower():
                return {'result': 'cache_policy_mismatch'}
            if not isinstance(d, dict):
                return {'result': 'schema_mismatch'}
            return {'result': 'received', 'http_status': 200, 'data': d}
    except (URLError, OSError, TimeoutError):
        return {'result': 'connection_failed'}
    except (ValueError, TypeError, UnicodeError):
        return {'result': 'schema_mismatch'}


def facts_ok(operation,facts):
    if not isinstance(facts,dict):
        return False
    if operation=='reservation.extend':
        v=facts.get('reservation')
        return set(facts)=={'reservation'} and isinstance(v,dict) and set(v)=={'deadline','total_hours'} and type(v['deadline']) is int and 0<v['deadline']<10**12 and type(v['total_hours']) is int and v['total_hours'] in (36,48,72)
    if operation=='shipment.issue':
        v=facts.get('shipment')
        return set(facts)=={'shipment'} and isinstance(v,dict) and set(v)=={'posted','prepared','dispatch_state','physical_confirmation'} and (v['posted'] is None or type(v['posted']) is bool) and (v['prepared'] is None or type(v['prepared']) is bool) and v['dispatch_state'] in (None,'preparing','awaiting_handover','handed_over') and v['physical_confirmation'] is False
    return False


def _outcome(d, operation_id):
    keys = {'operation_id', 'intent_sha256', 'operation', 'state', 'reason', 'payment_saved', 'reservation_sync_verified', 'needs_review', 'retry_native'}
    if isinstance(d,dict) and 'document_id' in d:
        if type(d['document_id']) is not int or d['document_id']<1 or not isinstance(d.get('operation'),str) or d.get('operation') not in DOCUMENT_OPERATIONS:
            return False
        keys.add('document_id')
    if isinstance(d,dict) and 'facts' in d:
        if not facts_ok(d['operation'],d['facts']):
            return False
        keys.add('facts')
    shape = isinstance(d, dict) and set(d) == keys and d['operation_id'] == operation_id and isinstance(d['intent_sha256'], str) and re.fullmatch('[a-f0-9]{64}', d['intent_sha256']) and isinstance(d['operation'], str) and d['operation'] in ({'status.change', 'payment.add_manual'} | DOCUMENT_OPERATIONS) and isinstance(d['state'], str) and d['state'] in STATES and isinstance(d['reason'], str) and d['reason'] in REASONS and (d['payment_saved'] is None or type(d['payment_saved']) is bool) and type(d['reservation_sync_verified']) is bool and (not d['reservation_sync_verified'] or d['operation'] == 'invoice.issue') and type(d['needs_review']) is bool and d['needs_review'] == (d['state'] in {'unknown', 'recorded_needs_review'}) and d['retry_native'] is False
    if not shape:
        return False
    if d['operation'] in DOCUMENT_OPERATIONS:
        if d['operation']=='invoice.issue' and d['state']=='complete' and d['reservation_sync_verified'] is not True:
            return False
        return d['payment_saved'] is None and (d['state'] != 'complete' or d['reason'] == 'document_verified') and (d['state'] != 'recorded_needs_review' or d['reason'] == 'document_needs_review')
    if d['operation'] == 'status.change':
        return d['payment_saved'] is None and d['state'] != 'recorded_needs_review' and (d['state'] != 'complete' or d['reason'] == 'native_status_verified')
    if d['state'] == 'complete':
        return False  # No generic payment downstream success contract exists.
    if d['state'] == 'recorded_needs_review':
        return d['payment_saved'] is True and d['reason'] in {'payment_recorded_sync_unverified', 'payment_recorded_native_interrupted'}
    return d['payment_saved'] is (False if d['state'] == 'failed' else None)



def operation_status(operation_id):
    if not isinstance(operation_id, str) or not UUID.fullmatch(operation_id):
        return {'result': 'invalid_operation_id'}
    r = _request('GET', 'operations/' + operation_id)
    if r.get('result') != 'received':
        return r
    if not _outcome(r['data'], operation_id):
        return {'result': 'schema_mismatch'}
    return {'result': 'journal_verified', 'http_status': 200, **r['data']}


def capabilities():
    r = _request('GET', 'capabilities')
    if r.get('result') != 'received':
        return r
    d = r['data']
    if set(d) != {'bridge_version', 'operations', 'manual_channels', 'preview_is_acceptance'} or d['bridge_version'] not in ('0.3.0', '0.3.1', '0.4.0', '0.4.1', '0.4.2') or d['preview_is_acceptance'] is not False or not isinstance(d['operations'], dict):
        return {'result': 'schema_mismatch'}
    supported = ({'status.change', 'payment.add_manual'} | DOCUMENT_OPERATIONS)
    if d['bridge_version'] not in ('0.4.0', '0.4.1', '0.4.2'):
        supported -= DOCUMENT_OPERATIONS
    unsupported = {'deferred_payment', 'items.edit', 'invoice.create', 'reservation.change', 'shipment.issue', 'stock.change', 'payment.attest_bank', 'payment.attribute_legacy', 'payment.tov'}
    unsupported -= supported
    if set(d['operations']) != supported | unsupported or d['manual_channels'] != ['pumb', 'privatbank', 'raiffeisen', 'bank_emeo']:
        return {'result': 'schema_mismatch'}
    result = {}
    allowed_reasons = {'matched', 'native_missing', 'native_source_unavailable', 'native_drift', 'native_visibility_mismatch'}
    for name, v in d['operations'].items():
        if not isinstance(v, dict):
            return {'result': 'schema_mismatch'}
        if name in unsupported:
            if v != {'implemented': False, 'reason': 'unsupported'}:
                return {'result': 'schema_mismatch'}
            result[name] = v
            continue
        if set(v) != {'implemented', 'actor_capable', 'contracts'} or v['implemented'] is not True or type(v['actor_capable']) is not bool:
            return {'result': 'schema_mismatch'}
        c = v['contracts']
        if not isinstance(c, dict) or not {'verified', 'reason'} <= set(c) or not set(c) <= {'verified', 'reason', 'method', 'expected_sha256', 'actual_sha256'} or type(c['verified']) is not bool or not isinstance(c['reason'], str) or c['reason'] not in allowed_reasons:
            return {'result': 'schema_mismatch'}
        if 'method' in c and (not isinstance(c['method'], str) or not re.fullmatch(r'TKD_(?:(?:FO_|Sales_|Warehouse_|NP_)[A-Za-z0-9_]{1,80}|Returning_Customer_20261114)(?:::[A-Za-z0-9_]{1,100})?', c['method'])):
            return {'result': 'schema_mismatch'}
        if any(not isinstance(c[k], str) or not re.fullmatch('[a-f0-9]{64}', c[k]) for k in ('expected_sha256', 'actual_sha256') if k in c):
            return {'result': 'schema_mismatch'}
        result[name] = v
    return {'result': 'capabilities_verified', 'http_status': 200, 'operations': result, 'preview_is_acceptance': False}


def snapshot(number):
    if not isinstance(number, str) or not NUMBER.fullmatch(number):
        return {'result': 'invalid_number'}
    r = _request('GET', 'orders/' + number + '/operation-snapshot')
    if r.get('result') != 'received':
        return r
    d = r['data']
    expected = {'bridge_version', 'schema_version', 'order_id', 'number', 'status', 'revision', 'revision_scope', 'native_edit_post', 'editor_locked', 'operations', 'preview_is_acceptance'}
    if set(d) != expected or d['bridge_version'] not in ('0.3.0', '0.3.1', '0.4.0', '0.4.1', '0.4.2') or d['schema_version'] != 'tkd-operation-snapshot-v1-candidate' or d['number'] != number or type(d['order_id']) is not int or d['order_id'] < 1 or not isinstance(d['revision'], str) or not re.fullmatch('[a-f0-9]{64}', d['revision']) or d['revision_scope'] != 'native-baseline-and-fixed-stored-state' or type(d['editor_locked']) is not bool or type(d['native_edit_post']) is not bool or d['preview_is_acceptance'] is not False:
        return {'result': 'schema_mismatch'}
    return {'result': 'snapshot_verified', 'http_status': 200, 'order_id': d['order_id'], 'revision': d['revision'], 'native_edit_post': d['native_edit_post'], 'editor_locked': d['editor_locked'], 'preview_is_acceptance': False}


def document_payload_ok(operation, v):
    if not isinstance(v, dict):
        return False
    if operation == 'invoice.draft':
        return not v
    if operation == 'reservation.extend':
        return set(v) == {'hours'} and type(v['hours']) is int and v['hours'] in (36, 48, 72)
    if operation == 'delivery.save':
        if set(v) != {'delivery_id','items'} or type(v['delivery_id']) is not int or not 0 < v['delivery_id'] < 10**18 or not isinstance(v['items'],list) or not 1 <= len(v['items']) <= 100:
            return False
        seen=set()
        for row in v['items']:
            if not isinstance(row,dict) or set(row) != {'source_index','quantity','serial_numbers'} or type(row['source_index']) is not int or row['source_index']<0 or row['source_index'] in seen or not isinstance(row['quantity'],str) or not re.fullmatch(r'[0-9]{1,12}(?:\.[0-9]{1,3})?',row['quantity']) or not any(c in '123456789' for c in row['quantity']) or not isinstance(row['serial_numbers'],str) or len(row['serial_numbers'].encode())>16000 or re.search(r'[^A-Za-z0-9\r\n,; -]',row['serial_numbers']):
                return False
            seen.add(row['source_index'])
        return True
    if operation == 'delivery.draft':
        return set(v) == {'invoice_id'} and type(v['invoice_id']) is int and 0 < v['invoice_id'] < 10**18
    if operation == 'shipment.issue':
        return set(v) == {'delivery_id'} and type(v['delivery_id']) is int and 0 < v['delivery_id'] < 10**18
    if operation == 'invoice.issue':
        if set(v) != {'invoice_id', 'number', 'date'} or type(v['invoice_id']) is not int or not 0 < v['invoice_id'] < 10**18 or not isinstance(v['number'], str) or not re.fullmatch(r'[A-Za-z0-9А-Яа-яІіЇїЄє._/-]{1,64}', v['number']) or not isinstance(v['date'], str):
            return False
        try:
            return datetime.strptime(v['date'], '%Y-%m-%d').strftime('%Y-%m-%d') == v['date']
        except ValueError:
            return False
    return False


def document_snapshot(number, operation, payload, include_data=False):
    if not isinstance(number, str) or not NUMBER.fullmatch(number) or not isinstance(operation,str) or operation not in DOCUMENT_OPERATIONS or not document_payload_ok(operation, payload):
        return {'result': 'invalid_intent'}
    scope = {} if operation=='invoice.draft' else {'hours':payload['hours']} if operation=='reservation.extend' else {'invoice_id':payload['invoice_id']} if operation in {'invoice.issue','delivery.draft'} else {'delivery_id':payload['delivery_id']}
    digest = hashlib.sha256(json.dumps(payload,sort_keys=True,ensure_ascii=True,separators=(',',':')).encode()).hexdigest()
    query = urlencode({'operation':operation,'scope':json.dumps(scope,separators=(',',':')),'payload_digest':digest})
    r = _request('GET', 'orders/' + number + '/document-snapshot?' + query)
    if r.get('result') != 'received':
        return r
    d = r['data']
    keys = {'office_revision','documents','bridge_version','schema_version','order_id','revision','operation','native_edit_post','editor_locked','preview_is_acceptance'}
    if set(d) != keys or d['bridge_version'] not in ('0.4.0', '0.4.1', '0.4.2') or d['schema_version'] != 'tkd-document-operation-snapshot-v1-candidate' or d['operation'] != operation or type(d['order_id']) is not int or d['order_id'] < 1 or not isinstance(d['revision'], str) or not re.fullmatch('[a-f0-9]{64}', d['revision']) or type(d['native_edit_post']) is not bool or type(d['editor_locked']) is not bool or d['preview_is_acceptance'] is not False:
        return {'result': 'schema_mismatch'}
    office=d['office_revision']
    if office is not None and (not isinstance(office,str) or not re.fullmatch('[a-f0-9]{64}',office)) or operation=='delivery.save' and office is None:
        return {'result':'schema_mismatch'}
    if not document_projection_ok(d['documents'],d['order_id']):
        return {'result':'schema_mismatch'}
    result={'result':'document_snapshot_verified','http_status':200,**d,'documents':[{'id':x['id'],'type':x['type']} for x in d['documents']]}
    if include_data:
        result['data']=d
    return result


def document_projection_ok(docs, order_id=None):
    if not isinstance(docs,list) or len(docs)>50:
        return False
    ids=set()
    for d in docs:
        if not isinstance(d,dict) or set(d)!={'id','type','order_id','invoice_id','number','date','status','seller_key','bank_key','items'} or any(type(d[k]) is not int or d[k]<1 for k in ('id','order_id','invoice_id')) or d['id'] in ids or d['type'] not in ('tkd_invoice','tkd_delivery') or d['status'] not in ('draft','issued','paid','cancelled') or any(not isinstance(d[k],str) or len(d[k].encode())>128 or re.search(r'[\x00-\x1f\x7f]',d[k]) for k in ('number','date','seller_key','bank_key')) or not isinstance(d['items'],list) or len(d['items'])>100:
            return False
        if order_id is not None and d['order_id']!=order_id or d['type']=='tkd_invoice' and d['invoice_id']!=d['id'] or d['type']=='tkd_delivery' and d['status']=='paid':
            return False
        ids.add(d['id'])
        if d['date'] and not re.fullmatch(r'\d{4}-\d{2}-\d{2}',d['date']):
            return False
        if d['date']:
            try:
                if datetime.strptime(d['date'],'%Y-%m-%d').strftime('%Y-%m-%d')!=d['date']:
                    return False
            except ValueError:
                return False
        indices=set()
        for row in d['items']:
            if not isinstance(row,dict) or set(row)!={'source_index','sku','name','unit','quantity','unit_price','serial_numbers'} or type(row['source_index']) is not int or row['source_index']<0 or row['source_index'] in indices or any(not isinstance(row[k],str) or len(row[k].encode())>512 or re.search(r'[\x00-\x1f\x7f]',row[k]) for k in ('sku','name','unit')) or not isinstance(row['quantity'],str) or not re.fullmatch(r'[0-9]{1,12}\.[0-9]{3}',row['quantity']) or not isinstance(row['unit_price'],str) or not re.fullmatch(r'[0-9]{1,12}(?:\.[0-9]{1,2})?',row['unit_price']) or not isinstance(row['serial_numbers'],str) or len(row['serial_numbers'].encode())>16000 or re.search(r'[^A-Za-z0-9\r\n,; -]',row['serial_numbers']):
                return False
            if not any(c in '123456789' for c in row['quantity']):
                return False
            indices.add(row['source_index'])
    return True



def _intent_ok(p):
    valid = isinstance(p, dict) and set(p) == {'operation_id', 'operation', 'order_id', 'number', 'revision', 'payload'} and isinstance(p['operation_id'], str) and UUID.fullmatch(p['operation_id']) and isinstance(p['operation'], str) and p['operation'] in ({'status.change', 'payment.add_manual'} | DOCUMENT_OPERATIONS) and type(p['order_id']) is int and p['order_id'] > 0 and isinstance(p['number'], str) and NUMBER.fullmatch(p['number']) and isinstance(p['revision'], str) and re.fullmatch('[a-f0-9]{64}', p['revision']) and isinstance(p['payload'], dict)
    if not valid:
        return False
    v = p['payload']
    if p['operation'] in DOCUMENT_OPERATIONS:
        return document_payload_ok(p['operation'], v)
    if p['operation'] == 'status.change':
        return set(v) == {'choice'} and isinstance(v['choice'], str) and v['choice'] in {'availability_pending', 'availability_callback', 'discount_inquiry', 'client_thinking', 'client_bought', 'unavailable', 'cancelled_repeat', 'cancelled_irrelevant', 'payment_pending', 'cod_pending', 'invoice_pending', 'prepayment_invoice_sent', 'partially_paid', 'balance_pending', 'paid'}
    fields = {'amount', 'payer_name', 'recipient_key', 'channel_key', 'received_at', 'reference', 'confirm_additional'}
    if set(v) != fields or not isinstance(v['amount'], str) or not re.fullmatch(r'[0-9]{1,12}(?:\.[0-9]{1,2})?', v['amount']) or not any(c in '123456789' for c in v['amount']) or type(v['confirm_additional']) is not bool:
        return False
    if not isinstance(v['recipient_key'], str) or not isinstance(v['channel_key'], str) or v['recipient_key'] not in {'fop_iy', 'fop_pm', 'fop_rv', 'fop_tp', 'fop_md', 'fop_eo'} or v['channel_key'] not in {'pumb', 'privatbank', 'raiffeisen', 'bank_emeo'}:
        return False
    for key, bound in (('payer_name', 256), ('reference', 128)):
        if not isinstance(v[key], str) or not 1 <= len(v[key].encode('utf-8')) <= bound or v[key].strip() != v[key] or re.search(r'[\x00-\x1f\x7f]', v[key]):
            return False
    if not isinstance(v['received_at'], str) or not re.fullmatch(r'[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}', v['received_at']):
        return False
    from datetime import datetime
    try:
        datetime.strptime(v['received_at'], '%Y-%m-%d %H:%M:%S')
    except ValueError:
        return False
    return True



def journal_directory():
    return Path(os.environ.get('TKD_CLOUD_JOURNAL_DIR', '/workspace/shared/tkd-cloud-operation-journal' if Path('/workspace').is_dir() else str(Path.home() / '.local/state/tkd-cloud-operation-journal')))


def execute(intent, confirmed=False, directory=None):
    # Only an explicit user money/status instruction should result in confirmed=True.
    if not confirmed:
        return {'result': 'explicit_confirmation_required'}
    if not _intent_ok(intent):
        return {'result': 'invalid_intent'}
    try:
        encoded = json.dumps(intent, sort_keys=True, ensure_ascii=True, separators=(',', ':'), allow_nan=False).encode()
        if len(encoded) > 8192:
            return {'result': 'payload_bound'}
        digest = hashlib.sha256(encoded).hexdigest()
        root = Path(directory) if directory is not None else journal_directory()
        root.mkdir(mode=0o700, parents=True, exist_ok=True)
        if root.is_symlink() or root.stat().st_uid != os.getuid() or root.stat().st_mode & 0o077:
            return {'result': 'insecure_local_journal'}
        path = root / (intent['operation_id'] + '.json')
        fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'r+', encoding='utf-8') as f:
            if os.fstat(f.fileno()).st_uid != os.getuid() or os.fstat(f.fileno()).st_mode & 0o077:
                return {'result': 'insecure_local_journal'}
            fcntl.flock(f, fcntl.LOCK_EX | fcntl.LOCK_NB)
            def save(state):
                # Fixed record <=1KiB, no payload, payer/reference/credentials.
                value = {'operation_id': intent['operation_id'], 'digest': digest, 'state': state}
                f.seek(0, os.SEEK_END); f.write(json.dumps(value, sort_keys=True) + '\n'); f.flush(); os.fsync(f.fileno())
                folder = os.open(root, os.O_RDONLY)
                try:
                    os.fsync(folder)
                finally:
                    os.close(folder)
            saved = f.read(65537)
            if saved:
                if len(saved) > 65536 or not saved.endswith('\n'):
                    return {'result': 'local_journal_invalid_no_retry'}
                records = [json.loads(line, object_pairs_hook=json_unique) for line in saved.splitlines()]
                if any(not isinstance(r, dict) or set(r) != {'operation_id', 'digest', 'state'} or r['operation_id'] != intent['operation_id'] or r['digest'] != digest or r['state'] not in {'prepared', 'submitting'} | STATES for r in records):
                    return {'result': 'local_idempotency_mismatch'}
                record = records[-1]
                if set(record) != {'operation_id', 'digest', 'state'} or record['operation_id'] != intent['operation_id'] or record['digest'] != digest:
                    return {'result': 'local_idempotency_mismatch'}
                can_submit = record['state'] == 'prepared'
            else:
                save('prepared'); can_submit = True
            # Always consult the actor-scoped durable server journal before considering POST.
            prior = operation_status(intent['operation_id'])
            if prior.get('result') == 'journal_verified':
                if prior['operation'] != intent['operation'] or prior['intent_sha256'] != digest:
                    return {'result': 'server_operation_mismatch'}
                save(prior['state']); return prior
            not_found = prior.get('http_status') == 404 and prior.get('code') == 'tkd_operation_not_found'
            if not can_submit:
                return {'result': 'local_outcome_unknown_no_retry', 'operation_id': intent['operation_id']}
            if not not_found:
                return prior
            save('submitting')  # Durable before network; interruption never authorizes repost.
            response = _request('POST', 'operations', intent)  # Exactly one POST. No retry loop.
            if response.get('result') == 'received' and _outcome(response['data'], intent['operation_id']) and response['data']['operation'] == intent['operation'] and response['data']['intent_sha256'] == digest:
                outcome = response['data']; save(outcome['state'])
                return {'result': 'operation_verified', 'http_status': 200, **outcome}
            save('unknown')
            return {'result': 'submission_unverified_no_retry', 'operation_id': intent['operation_id'], 'http_status': response.get('http_status'), 'code': response.get('code', 'outcome_unknown')}
    except BlockingIOError:
        return {'result': 'local_journal_busy_no_retry'}
    except (OSError, ValueError, TypeError, UnicodeError):
        return {'result': 'local_journal_failure_no_retry'}


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('command', choices=('capabilities', 'snapshot', 'document-snapshot', 'journal', 'execute', 'new-id'))
    p.add_argument('--number'); p.add_argument('--operation-id'); p.add_argument('--confirm-execute', action='store_true')
    args = p.parse_args()
    if args.command == 'new-id':
        result = {'operation_id': str(uuid.uuid4()), 'result': 'operation_id_generated_save_for_reuse'}
    elif args.command == 'capabilities':
        result = capabilities()
    elif args.command == 'snapshot':
        result = snapshot(args.number)
    elif args.command == 'document-snapshot':
        try:
            raw = sys.stdin.buffer.read(8193)
            spec = json.loads(raw, object_pairs_hook=json_unique) if len(raw) <= 8192 else {}
            result = document_snapshot(args.number, spec.get('operation'), spec.get('payload')) if isinstance(spec, dict) and set(spec) == {'operation','payload'} else {'result':'invalid_intent'}
        except (ValueError, UnicodeError, TypeError):
            result = {'result':'invalid_intent'}
    elif args.command == 'journal':
        result = operation_status(args.operation_id)
    else:
        try:
            raw = sys.stdin.buffer.read(8193)
            intent = json.loads(raw, object_pairs_hook=json_unique) if len(raw) <= 8192 else None
        except (ValueError, UnicodeError):
            intent = None
        result = execute(intent, args.confirm_execute)
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result.get('result') in {'document_snapshot_verified', 'capabilities_verified', 'snapshot_verified', 'journal_verified', 'operation_verified', 'operation_id_generated_save_for_reuse'} else 1


if __name__ == '__main__':
    raise SystemExit(main())
