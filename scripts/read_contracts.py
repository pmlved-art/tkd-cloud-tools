"""Explicit contracts for read-only 0.2.0 projections; no printing or persistence."""
import re

CLASSES = ('TKD_FO_Orders', 'TKD_FO_Payments', 'TKD_FO_DB', 'TKD_FO_Invoices', 'TKD_FO_Deliveries', 'TKD_FO_Inventory_Admin', 'TKD_FO_Mobile_Cabinet_20261011', 'TKD_FO_Order_Editor', 'TKD_FO_Customers', 'TKD_FO_Phone_Orders', 'TKD_FO_Order_Comments')
META = ('_tkd_order_number', '_tkd_status', '_tkd_customer_name', '_tkd_customer_phone', '_tkd_customer_email', '_tkd_client_total', '_tkd_confirmed_amount', '_tkd_prepayment_received', '_tkd_paid_at', '_tkd_fo_order_items', '_tkd_fo_payments', '_tkd_fo_invoice_id', '_tkd_fo_stock_state', '_tkd_fo_reservation_stage', '_tkd_fo_reservation_until')
ITEM = {'sku', 'name', 'quantity', 'unit_price', 'item_id', 'unit'}
PAYMENT = {'id', 'amount', 'fee', 'net_amount', 'recipient_key', 'channel_key', 'received_at', 'source', 'status', 'created_at', 'settled_at', 'settlement_date_precision', 'settlement_date_source'}
STOCK = {'id', 'sku', 'name', 'unit', 'on_hand', 'reserved', 'incoming', 'active', 'updated_at'}
STOCK_KNOWN = STOCK | {'price', 'exw_price_eur', 'cost_price_uah', 'page_id', 'created_at'}
SCHEMAS = {'manifest': 'tkd-integration-manifest-v1-candidate', 'details': 'tkd-order-details-v1-candidate', 'stock': 'tkd-stock-read-v1-candidate'}


def text(v, bound=512):
    return isinstance(v, str) and len(v.encode('utf-8')) <= bound and not re.search(r'[\x00-\x1f\x7f]', v)


def decimal(v, scale=2):
    return isinstance(v, str) and re.fullmatch(r'-?[0-9]{1,12}(?:\.[0-9]{1,' + str(scale) + '})?', v) is not None


def sha(v):
    return isinstance(v, str) and re.fullmatch('[a-f0-9]{64}', v) is not None


def rows(v, payments=False):
    fields = PAYMENT if payments else ITEM
    if not isinstance(v, list) or len(v) > 100:
        return False
    ids = set()
    for row in v:
        if not isinstance(row, dict) or not row or not set(row) <= fields:
            return False
        for k, val in row.items():
            if val is None:
                continue
            if k in {'quantity', 'unit_price', 'amount', 'fee', 'net_amount'}:
                if not decimal(val, 3 if k == 'quantity' else 2):
                    return False
            elif not text(val, 512 if k == 'name' else 64 if k in {'id', 'item_id'} else 128):
                return False
        if payments and row.get('id'):
            if row['id'] in ids:
                return False
            ids.add(row['id'])
    return True


def validate_details(d, number, headers):
    expected = {'id', 'number', 'status', 'post_modified_gmt', 'bridge_version', 'schema_version', 'metadata', 'revision', 'revision_scope'}
    if set(d) != expected or d['number'] != number or type(d['id']) is not int or d['id'] < 1 or not isinstance(d['status'], str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', d['status']):
        return False
    from datetime import datetime
    if not isinstance(d['post_modified_gmt'], str) or not re.fullmatch(r'[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}', d['post_modified_gmt']):
        return False
    datetime.strptime(d['post_modified_gmt'], '%Y-%m-%d %H:%M:%S')
    if not sha(d['revision']) or d['revision_scope'] != 'informational-read-snapshot' or headers.get('ETag') != '"' + d['revision'] + '"':
        return False
    meta = d['metadata']
    if not isinstance(meta, dict) or set(meta) != set(META):
        return False
    for k, entry in meta.items():
        if not isinstance(entry, dict) or set(entry) != {'present', 'value'} or type(entry['present']) is not bool:
            return False
        v = entry['value']
        if not entry['present']:
            if v is not None:
                return False
        elif v is None:
            continue
        elif k in {'_tkd_fo_order_items', '_tkd_fo_payments'}:
            if not rows(v, k == '_tkd_fo_payments'):
                return False
        elif k in {'_tkd_client_total', '_tkd_confirmed_amount', '_tkd_prepayment_received'}:
            if not decimal(v):
                return False
        elif not text(v):
            return False
    return meta['_tkd_order_number'] == {'present': True, 'value': number} and meta['_tkd_status'] == {'present': True, 'value': d['status']}


def version_map(v, keys):
    return v == [] or isinstance(v, dict) and set(v) <= keys and all(isinstance(x, str) and re.fullmatch(r'[A-Za-z0-9._-]{1,32}', x) for x in v.values())


def validate_manifest(d):
    if set(d) != {'bridge_version', 'schema_version', 'versions', 'classes', 'total_methods', 'stock_schema'} or not version_map(d['versions'], {'TKD_FO_VERSION'}):
        return False
    if not isinstance(d['classes'], list) or len(d['classes']) != len(CLASSES):
        return False
    total = 0
    for item, name in zip(d['classes'], CLASSES):
        if not isinstance(item, dict) or set(item) != {'name', 'present', 'file_sha256', 'versions', 'methods'} or item['name'] != name or type(item['present']) is not bool:
            return False
        if not version_map(item['versions'], {'VERSION', 'DB_VERSION', 'SCHEMA_VERSION'}) or not isinstance(item['methods'], list):
            return False
        if item['present']:
            if not sha(item['file_sha256']):
                return False
        elif item['file_sha256'] is not None or item['methods'] or item['versions'] not in ({}, []):
            return False
        names = set()
        for method in item['methods']:
            if not isinstance(method, dict) or set(method) != {'name', 'required_args', 'total_args', 'source_sha256'}:
                return False
            if not isinstance(method['name'], str) or not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,127}', method['name']) or method['name'] in names or not sha(method['source_sha256']):
                return False
            if type(method['required_args']) is not int or type(method['total_args']) is not int or not 0 <= method['required_args'] <= method['total_args'] <= 1000:
                return False
            names.add(method['name'])
            total += 1
    if type(d['total_methods']) is not int or d['total_methods'] != total or total > 500:
        return False
    s = d['stock_schema']
    if not isinstance(s, dict) or set(s) != {'table_suffix', 'state', 'columns'} or s['table_suffix'] != 'tkd_fo_stock' or s['state'] not in {'unavailable', 'incompatible', 'read-compatible'} or not isinstance(s['columns'], list) or len(s['columns']) > 15:
        return False
    names = set()
    for c in s['columns']:
        if not isinstance(c, dict) or set(c) != {'name', 'type', 'precision', 'scale'} or c['name'] not in STOCK_KNOWN or c['name'] in names or not isinstance(c['type'], str) or not re.fullmatch('[a-z]{1,32}', c['type']):
            return False
        if any(c[k] is not None and (type(c[k]) is not int or not 0 <= c[k] <= 65535) for k in ('precision', 'scale')):
            return False
        names.add(c['name'])
    return True


def validate_stock(d, limit, sku):
    if set(d) != {'bridge_version', 'schema_version', 'rows', 'next_cursor'} or not isinstance(d['rows'], list) or len(d['rows']) > (1 if sku is not None else limit):
        return False
    cursor = d['next_cursor']
    if cursor is not None and (not isinstance(cursor, str) or not re.fullmatch(r'[0-9]{1,18}', cursor)):
        return False
    ids = set()
    from decimal import Decimal
    for row in d['rows']:
        if not isinstance(row, dict) or set(row) != STOCK | {'available_on_hand_minus_reserved'} or not all(text(v) for v in row.values()):
            return False
        if not re.fullmatch(r'[0-9]{1,18}', row['id']) or int(row['id']) < 1 or row['id'] in ids or row['active'] not in {'0', '1'}:
            return False
        ids.add(row['id'])
        if sku is not None and row['sku'] != sku:
            return False
        if not all(decimal(row[k], 6) for k in ('on_hand', 'reserved', 'incoming')) or not re.fullmatch(r'-?[0-9]{1,13}\.[0-9]{6}', row['available_on_hand_minus_reserved']):
            return False
        if Decimal(row['available_on_hand_minus_reserved']) != Decimal(row['on_hand']) - Decimal(row['reserved']):
            return False
    return True
