<?php
if (!defined('ABSPATH')) { exit; }

/** Fixed read adapters only. No TKD business methods are ever invoked. */
final class TKD_Cloud_Read_Extensions {
    const MAX_DETAILS = 131072;
    const MAX_MANIFEST = 524288;
    const MAX_ROWS = 100;
    const CLASSES = array('TKD_FO_Orders', 'TKD_FO_Payments', 'TKD_FO_DB', 'TKD_FO_Invoices', 'TKD_FO_Deliveries', 'TKD_FO_Inventory_Admin', 'TKD_FO_Mobile_Cabinet_20261011', 'TKD_FO_Order_Editor', 'TKD_FO_Customers', 'TKD_FO_Phone_Orders', 'TKD_FO_Order_Comments');
    const META = array('_tkd_order_number', '_tkd_status', '_tkd_customer_name', '_tkd_customer_phone', '_tkd_customer_email', '_tkd_client_total', '_tkd_confirmed_amount', '_tkd_prepayment_received', '_tkd_paid_at', '_tkd_fo_order_items', '_tkd_fo_payments', '_tkd_fo_invoice_id', '_tkd_fo_stock_state', '_tkd_fo_reservation_stage', '_tkd_fo_reservation_until');
    const ITEM_FIELDS = array('sku', 'name', 'quantity', 'unit_price', 'item_id', 'unit');
    const PAYMENT_FIELDS = array('payer_name', 'reference', 'bank_transaction_id', 'id', 'amount', 'fee', 'net_amount', 'recipient_key', 'channel_key', 'received_at', 'source', 'status', 'created_at', 'settled_at', 'settlement_date_precision', 'settlement_date_source');
    const STOCK_FIELDS = array('id', 'sku', 'name', 'unit', 'on_hand', 'reserved', 'reserved_supply', 'incoming', 'active', 'updated_at');
    const STOCK_KNOWN = array('id', 'sku', 'name', 'unit', 'price', 'exw_price_eur', 'cost_price_uah', 'on_hand', 'reserved', 'reserved_supply', 'incoming', 'page_id', 'active', 'created_at', 'updated_at');

    public static function routes() {
        foreach (array('/integration-manifest' => 'manifest', '/orders' => 'lookup', '/orders/(?P<number>[A-Za-z0-9-]{1,64})/details' => 'details', '/stock' => 'stock') as $route => $method) {
            register_rest_route('tkd-cloud/v1', $route, array('methods' => 'GET', 'callback' => array(__CLASS__, $method), 'permission_callback' => array('TKD_Cloud_Reader', 'permission')));
        }
    }

    private static function error($code = 'tkd_schema_unverified', $status = 503) {
        return new WP_Error($code, 'Read adapter refused ambiguous, unsupported or oversized data.', array('status' => $status));
    }

    private static function bounded($data, $limit) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > $limit) { return self::error('tkd_response_bound_exceeded'); }
        return new WP_REST_Response($data);
    }

    private static function text($value, $max = 256) {
        return is_string($value) && strlen($value) <= $max && preg_match('//u', $value) === 1 && !preg_match('/[\x00-\x1f\x7f]/', $value);
    }

    // No coercion of booleans/null/arrays, no exponent/comma/rounding fallback.
    private static function decimal($value, $scale = 2) {
        if (is_float($value)) {
            if (!is_finite($value)) { return null; }
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_int($value)) { $value = (string) $value; }
        if (!is_string($value) || !preg_match('/\A-?[0-9]{1,12}(?:\.[0-9]{1,' . $scale . '})?\z/D', $value)) { return null; }
        return $value;
    }

    // Classification only: never normalize or accept a rejected monetary value.
    private static function decimal_reason($value) {
        if (!is_string($value)) { return 'invalid_decimal'; }
        if ($value === '') { return 'decimal_empty'; }
        if (preg_match('/\A\s+\z/uD', $value)) { return 'decimal_whitespace'; }
        if (preg_match('/\A[+-]?[0-9]+(?:\.[0-9]+)?[eE][+-]?[0-9]+\z/D', $value)) { return 'decimal_exponent'; }
        if (preg_match('/\A-?[0-9]{1,3}(?:[ ,\x{00a0}\x{202f}][0-9]{3})+(?:[.,][0-9]+)?\z/uD', $value)) { return 'decimal_grouped'; }
        if (preg_match('/\A-?[0-9]+,[0-9]+\z/D', $value)) { return 'decimal_localized'; }
        return 'invalid_decimal';
    }

    // Only fixed field paths/reasons and native type enums; no values or row indices.
    private static function details_error($field, $reason, $value, $present = true, $code = 'tkd_schema_unverified', $status = 503) {
        $allowed = array_merge(self::META, array('snapshot', 'response'));
        foreach (self::ITEM_FIELDS as $key) { $allowed[] = '_tkd_fo_order_items.' . $key; }
        foreach (self::PAYMENT_FIELDS as $key) { $allowed[] = '_tkd_fo_payments.' . $key; }
        $reasons = array('expected_list', 'row_bound', 'expected_row', 'empty_projection', 'invalid_decimal', 'decimal_empty', 'decimal_whitespace', 'decimal_exponent', 'decimal_grouped', 'decimal_localized', 'invalid_identifier', 'invalid_text', 'duplicate_id', 'duplicate_meta', 'input_bound_or_unsupported_type', 'snapshot_changed', 'response_bound');
        $type = gettype($value);
        if (!in_array($field, $allowed, true) || !in_array($reason, $reasons, true)) { return self::error(); }
        if (!in_array($type, array('NULL', 'string', 'integer', 'double', 'boolean', 'array', 'object', 'resource', 'resource (closed)'), true)) { $type = 'unknown'; }
        return new WP_Error($code, 'Stored schema did not match the read contract.', array('status' => $status,
            'schema_diagnostic' => array('field' => $field, 'reason' => $reason, 'type' => $type, 'present' => (bool) $present)));
    }

    private static function rows($rows, $payments) {
        $field = $payments ? '_tkd_fo_payments' : '_tkd_fo_order_items';
        if (!is_array($rows) || ($rows && array_keys($rows) !== range(0, count($rows) - 1))) { return self::details_error($field, 'expected_list', $rows); }
        if (count($rows) > self::MAX_ROWS) { return self::details_error($field, 'row_bound', $rows); }
        $out = array(); $ids = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { return self::details_error($field, 'expected_row', $row); }
            if (count($row) > 64) { return self::details_error($field, 'row_bound', $row); }
            $projected = array();
            foreach ($payments ? self::PAYMENT_FIELDS : self::ITEM_FIELDS as $key) {
                if (!array_key_exists($key, $row)) { continue; }
                $value = $row[$key];
                if ($value === null) { $projected[$key] = null; continue; }
                if (in_array($key, array('amount', 'fee', 'net_amount', 'unit_price', 'quantity'), true)) {
                    $value = self::decimal($value, $key === 'quantity' ? 3 : 2);
                    if ($value === null) { return self::details_error($field . '.' . $key, 'invalid_decimal', $row[$key]); }
                } elseif (in_array($key, array('id', 'item_id'), true)) {
                    if (is_int($value) && $value >= 0) { $value = (string) $value; }
                    if (!self::text($value, 64)) { return self::details_error($field . '.' . $key, 'invalid_identifier', $row[$key]); }
                } elseif (!self::text($value, in_array($key, array('name', 'payer_name'), true) ? 512 : 128)) { return self::details_error($field . '.' . $key, 'invalid_text', $row[$key]); }
                $projected[$key] = $value;
            }
            if (!$projected) { return self::details_error($field, 'empty_projection', $row); }
            if ($payments && isset($projected['id']) && $projected['id'] !== '') {
                if (isset($ids[$projected['id']])) { return self::details_error($field . '.id', 'duplicate_id', $row['id']); }
                $ids[$projected['id']] = true;
            }
            $out[] = $projected;
        }
        return $out;
    }

    private static function input_bound($value, &$nodes, &$bytes, $depth = 0) {
        $nodes++;
        if ($nodes > 4096 || $depth > 8 || $bytes > self::MAX_DETAILS) { return false; }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $bytes += strlen((string) $key);
                if (!self::input_bound($child, $nodes, $bytes, $depth + 1)) { return false; }
            }
        } elseif (is_string($value)) { $bytes += strlen($value); }
        elseif (!is_null($value) && !is_int($value) && !is_float($value) && !is_bool($value)) { return false; }
        return $bytes <= self::MAX_DETAILS;
    }

    public static function details($request) {
        $params = $request->get_url_params();
        $minimal = TKD_Cloud_Reader::read_number(isset($params['number']) ? $params['number'] : null);
        if ($minimal instanceof WP_Error) { return $minimal; }
        $base = $minimal->get_data(); $meta = array(); $bytes = 0; $nodes = 0;
        foreach (self::META as $key) {
            $values = get_post_meta($base['id'], $key, false);
            if (count($values) > 1) { return self::details_error($key, 'duplicate_meta', $values, true, 'tkd_ambiguous_metadata', 409); }
            if (!$values) { $meta[$key] = array('present' => false, 'value' => null); continue; }
            $value = $values[0];
            // Bound stored nested input as well as projected output, before traversal.
            if (!self::input_bound($value, $nodes, $bytes)) { return self::details_error($key, 'input_bound_or_unsupported_type', $value, true, 'tkd_response_bound_exceeded'); }
            if ($value !== null) {
                if ($key === '_tkd_fo_order_items' || $key === '_tkd_fo_payments') {
                    $value = self::rows($value, $key === '_tkd_fo_payments');
                    if ($value instanceof WP_Error) { return $value; }
                } elseif (in_array($key, array('_tkd_client_total', '_tkd_confirmed_amount', '_tkd_prepayment_received'), true)) {
                    // Native aggregate_value legitimately stores exact empty legacy aggregate.
                    $value = ($key === '_tkd_prepayment_received' && $value === '') ? '' : self::decimal($value);
                    if ($value === null) { return self::details_error($key, self::decimal_reason($values[0]), $values[0]); }
                } elseif ($key === '_tkd_fo_invoice_id') {
                    if (is_int($value) && $value >= 0) { $value = (string) $value; }
                    if (!is_string($value) || !preg_match('/\A[0-9]{0,18}\z/D', $value)) { return self::details_error($key, 'invalid_identifier', $values[0]); }
                } elseif (!self::text($value, 512)) { return self::details_error($key, 'invalid_text', $values[0]); }
            }
            $meta[$key] = array('present' => true, 'value' => $value);
        }
        if ($meta['_tkd_order_number']['value'] !== $base['number'] || $meta['_tkd_status']['value'] !== $base['status']) { return self::details_error('snapshot', 'snapshot_changed', $meta); }
        // A non-atomic read snapshot, not a write CAS or lock token.
        $base['schema_version'] = 'tkd-order-details-v1-candidate';
        $base['metadata'] = $meta;
        $base['revision'] = hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $base['revision_scope'] = 'informational-read-snapshot';
        $response = self::bounded($base, self::MAX_DETAILS);
        if ($response instanceof WP_Error) { return self::details_error('response', 'response_bound', $base, true, 'tkd_response_bound_exceeded'); }
        $response->header('ETag', '"' . $base['revision'] . '"');
        return $response;
    }

    public static function lookup($request) {
        if (!post_type_exists('tkd_order')) { return self::error(); }
        $q = $request->get_query_params();
        if (array_diff(array_keys($q), array('number'))) { return self::error('tkd_invalid_query', 400); }
        $number = isset($q['number']) ? $q['number'] : null;
        if (is_string($number) && preg_match('/\ATKD-[A-Za-z0-9-]{1,60}\z/D', $number)) { return TKD_Cloud_Reader::read_number($number); }
        if (!is_string($number) || !preg_match('/\A[0-9]{1,18}\z/D', $number)) { return self::error('tkd_invalid_number', 400); }
        global $wpdb;
        // Fixed WordPress tables; RIGHT equality avoids MySQL 8 binary-REGEXP incompatibility.
        foreach (array('posts', 'postmeta') as $table_key) {
            if (!isset($wpdb->$table_key) || !is_string($wpdb->$table_key) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $wpdb->$table_key)) { return self::error(); }
        }
        $sql = $wpdb->prepare('SELECT DISTINCT p.ID AS id FROM `' . $wpdb->posts . '` p INNER JOIN `' . $wpdb->postmeta . '` m ON p.ID = m.post_id WHERE p.post_type = %s AND p.post_status NOT IN (%s,%s) AND m.meta_key = %s AND LEFT(m.meta_value,4) = BINARY %s AND RIGHT(m.meta_value,%d) = BINARY %s AND CHAR_LENGTH(m.meta_value) BETWEEN 5 AND 64 ORDER BY p.ID ASC LIMIT 2',
            'tkd_order', 'trash', 'auto-draft', '_tkd_order_number', 'TKD-', strlen($number) + 1, '-' . $number);
        $matches = self::select($sql);
        if ($matches === null || count($matches) > 2) { return self::error(); }
        $ids = array();
        foreach ($matches as $match) {
            if (!isset($match['id']) || !self::cursor($match['id']) || (int) $match['id'] < 1) { return self::error(); }
            $ids[] = (int) $match['id'];
        }
        if (count($ids) > 1) { return self::error('tkd_duplicate_number', 409); }
        if (!$ids) { return self::error('tkd_not_found', 404); }
        $values = get_post_meta((int) $ids[0], '_tkd_order_number', false);
        if (count($values) !== 1 || !is_string($values[0]) || !preg_match('/\ATKD-(?:[A-Za-z0-9-]+-)?' . $number . '\z/D', $values[0])) { return self::error(); }
        return TKD_Cloud_Reader::read_number($values[0]);
    }

    private static function table() {
        global $wpdb;
        if (!isset($wpdb->prefix) || !is_string($wpdb->prefix) || !preg_match('/\A[A-Za-z0-9_]{1,48}\z/D', $wpdb->prefix)) { return null; }
        return $wpdb->prefix . 'tkd_fo_stock';
    }

    private static function select($sql) {
        global $wpdb;
        $old = $wpdb->suppress_errors(true);
        try {
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if ($wpdb->last_error || !is_array($rows)) { return null; }
            return $rows;
        } finally { $wpdb->suppress_errors($old); }
    }

    private static function stock_schema() {
        $table = self::table();
        if ($table === null) { return null; }
        $rows = self::select('SHOW COLUMNS FROM `' . $table . '`');
        if ($rows === null || count($rows) > 64) { return null; }
        $columns = array();
        foreach ($rows as $row) {
            if (!isset($row['Field'], $row['Type']) || !is_string($row['Field']) || !is_string($row['Type'])) { return null; }
            if (!in_array($row['Field'], self::STOCK_KNOWN, true)) { continue; }
            if (isset($columns[$row['Field']]) || !preg_match('/\A([a-z]+)(?:\(([0-9]+)(?:,([0-9]+))?\))?/i', $row['Type'], $m)) { return null; }
            $columns[$row['Field']] = array('name' => $row['Field'], 'type' => strtolower($m[1]), 'precision' => isset($m[2]) ? (int) $m[2] : null, 'scale' => isset($m[3]) ? (int) $m[3] : null);
        }
        return array_values($columns);
    }

    private static function stock_guard($columns) {
        if (!is_array($columns) || PHP_INT_SIZE < 8) { return false; }
        $map = array(); foreach ($columns as $column) { $map[$column['name']] = $column; }
        foreach (self::STOCK_FIELDS as $field) { if (!isset($map[$field])) { return false; } }
        foreach (array('id', 'active') as $field) { if (!in_array($map[$field]['type'], array('tinyint', 'smallint', 'mediumint', 'int', 'bigint'), true)) { return false; } }
        foreach (array('sku', 'name', 'unit') as $field) { if (!in_array($map[$field]['type'], array('char', 'varchar', 'text', 'tinytext', 'mediumtext'), true)) { return false; } }
        foreach (array('on_hand', 'reserved', 'reserved_supply', 'incoming') as $field) {
            if (!in_array($map[$field]['type'], array('decimal', 'tinyint', 'smallint', 'mediumint', 'int', 'bigint'), true) || (isset($map[$field]['scale']) && $map[$field]['scale'] > 6)) { return false; }
        }
        return in_array($map['updated_at']['type'], array('datetime', 'timestamp'), true);
    }

    private static function cursor($value, $max_digits = 18) {
        return (is_int($value) || is_string($value)) && preg_match('/\A[0-9]{1,' . $max_digits . '}\z/D', (string) $value) === 1;
    }

    // All operands validated as <=12 whole /6 fractional digits on 64-bit PHP.
    private static function decimal_int($s) {
        $negative = substr($s, 0, 1) === '-'; $s = ltrim($s, '-');
        $parts = explode('.', $s, 2);
        $v = (int) $parts[0] * 1000000 + (int) str_pad(isset($parts[1]) ? $parts[1] : '', 6, '0');
        return $negative ? -$v : $v;
    }

    private static function decimal_string($v) {
        return ($v < 0 ? '-' : '') . intdiv(abs($v), 1000000) . '.' . str_pad((string) (abs($v) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function stock($request) {
        global $wpdb;
        $q = $request->get_query_params();
        if (array_diff(array_keys($q), array('limit', 'cursor', 'sku'))) { return self::error('tkd_invalid_query', 400); }
        $limit = isset($q['limit']) ? $q['limit'] : '25'; $cursor = isset($q['cursor']) ? $q['cursor'] : '0';
        if (!self::cursor($limit, 2) || (int) $limit < 1 || (int) $limit > 50 || !self::cursor($cursor)) { return self::error('tkd_invalid_query', 400); }
        $sku = isset($q['sku']) ? $q['sku'] : null;
        if ($sku !== null && (!self::text($sku, 128) || $sku === '' || array_key_exists('cursor', $q))) { return self::error('tkd_invalid_query', 400); }
        $columns = self::stock_schema();
        if (!self::stock_guard($columns)) { return self::error(); }
        $table = self::table();
        $fields = '`' . implode('`,`', self::STOCK_FIELDS) . '`';
        if ($sku !== null) {
            $sql = $wpdb->prepare('SELECT ' . $fields . ' FROM `' . $table . '` WHERE BINARY `sku` = BINARY %s ORDER BY `id` ASC LIMIT 2', $sku);
        } else {
            $sql = $wpdb->prepare('SELECT ' . $fields . ' FROM `' . $table . '` WHERE `id` > %d ORDER BY `id` ASC LIMIT %d', (int) $cursor, (int) $limit + 1);
        }
        $rows = self::select($sql);
        if ($rows === null || count($rows) > ($sku !== null ? 2 : (int) $limit + 1)) { return self::error(); }
        if ($sku !== null && count($rows) > 1) { return self::error('tkd_duplicate_sku', 409); }
        if ($sku !== null && !$rows) { return self::error('tkd_not_found', 404); }
        $has_more = $sku === null && count($rows) > (int) $limit;
        if ($has_more) { array_pop($rows); }
        $out = array(); $last = (int) $cursor;
        foreach ($rows as $row) {
            if (array_diff(self::STOCK_FIELDS, array_keys($row))) { return self::error(); }
            $projected = array();
            if (!self::cursor($row['id']) || (int) $row['id'] <= $last || !in_array((string) $row['active'], array('0', '1'), true)) { return self::error(); }
            foreach (self::STOCK_FIELDS as $key) {
                $value = $row[$key];
                if (in_array($key, array('on_hand', 'reserved', 'reserved_supply', 'incoming'), true)) {
                    $value = self::decimal($value, 6); if ($value === null) { return self::error(); }
                } elseif (in_array($key, array('id', 'active'), true)) {
                    $value = (string) $value;
                    if (!self::cursor($value)) { return self::error(); }
                } elseif (!self::text($value, in_array($key, array('name', 'payer_name'), true) ? 512 : 128)) { return self::error(); }
                $projected[$key] = $value;
            }
            if ($sku !== null && $projected['sku'] !== $sku) { return self::error(); }
            $on_hand = self::decimal_int($projected['on_hand']);
            $reserved = self::decimal_int($projected['reserved']);
            $supply = max(0, self::decimal_int($projected['reserved_supply']));
            $incoming = self::decimal_int($projected['incoming']);
            $physical_reserved = max(0, $reserved - $supply);
            $free_physical = $on_hand - $physical_reserved;
            $projected['available_on_hand_minus_reserved'] = self::decimal_string($on_hand - $reserved);
            $projected['physical_reserved'] = self::decimal_string($physical_reserved);
            $projected['free_physical'] = self::decimal_string($free_physical);
            $projected['free_after_supply'] = self::decimal_string(max(0, $free_physical + max(0, $incoming - $supply)));
            $out[] = $projected; $last = (int) $projected['id'];
        }
        return self::bounded(array('bridge_version' => TKD_Cloud_Reader::VERSION, 'schema_version' => 'tkd-stock-read-v2-candidate', 'rows' => $out, 'next_cursor' => $has_more ? (string) $last : null), self::MAX_DETAILS);
    }

    public static function manifest($request) {
        if ($request->get_query_params()) { return self::error('tkd_invalid_query', 400); }
        $classes = array(); $files = array(); $total = 0; $source_bytes = 0;
        foreach (self::CLASSES as $name) {
            $item = array('name' => $name, 'present' => class_exists($name, false), 'file_sha256' => null, 'versions' => array(), 'methods' => array());
            if ($item['present']) {
                $reflection = new ReflectionClass($name);
                foreach (array('VERSION', 'DB_VERSION', 'SCHEMA_VERSION') as $constant) {
                    $c = $reflection->getReflectionConstant($constant);
                    if ($c && $c->isPublic()) {
                        $value = $c->getValue();
                        if ((is_string($value) || is_int($value)) && preg_match('/\A[A-Za-z0-9._-]{1,32}\z/D', (string) $value)) { $item['versions'][$constant] = (string) $value; }
                    }
                }
                $file = $reflection->getFileName();
                if (!is_string($file)) { return self::error('tkd_manifest_source_unavailable'); }
                $sources = array($file);
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
                foreach ($methods as $method) { if ($method->isStatic()) { $sources[] = $method->getFileName(); } }
                foreach (array_unique($sources) as $source) {
                    if (!is_string($source) || !is_file($source) || !is_readable($source)) { return self::error('tkd_manifest_source_unavailable'); }
                    if (!isset($files[$source])) {
                        $size = @filesize($source);
                        if ($size === false || $size > 2097152 || count($files) >= 64) { return self::error('tkd_manifest_bound_exceeded'); }
                        // Read once per request; no path or source content is returned.
                        $body = @file_get_contents($source, false, null, 0, 2097153);
                        if ($body === false || strlen($body) > 2097152) { return self::error('tkd_manifest_bound_exceeded'); }
                        $source_bytes += strlen($body);
                        if ($source_bytes > 8388608 || substr_count($body, "\n") > 50000) { return self::error('tkd_manifest_bound_exceeded'); }
                        $files[$source] = array('sha256' => hash('sha256', $body), 'lines' => explode("\n", $body));
                    }
                }
                $item['file_sha256'] = $files[$file]['sha256'];
                foreach ($methods as $method) {
                    if (!$method->isStatic()) { continue; }
                    $total++;
                    if ($total > 500) { return self::error('tkd_manifest_bound_exceeded'); }
                    $start = $method->getStartLine(); $end = $method->getEndLine(); $source = $method->getFileName();
                    if (!is_int($start) || !is_int($end) || $start < 1 || $end < $start || $end > count($files[$source]['lines'])) { return self::error('tkd_manifest_source_unavailable'); }
                    // Exact inclusive line slice; each line retains its original LF/CRLF.
                    $lines = $files[$source]['lines']; $slice = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
                    if ($end < count($lines)) { $slice .= "\n"; }
                    $item['methods'][] = array('name' => $method->getName(), 'required_args' => $method->getNumberOfRequiredParameters(), 'total_args' => $method->getNumberOfParameters(), 'source_sha256' => hash('sha256', $slice));
                }
                usort($item['methods'], function($a, $b) { return strcmp($a['name'], $b['name']); });
            }
            $classes[] = $item;
        }
        $versions = array();
        if (defined('TKD_FO_VERSION') && (is_string(constant('TKD_FO_VERSION')) || is_int(constant('TKD_FO_VERSION'))) && preg_match('/\A[A-Za-z0-9._-]{1,32}\z/D', (string) constant('TKD_FO_VERSION'))) { $versions['TKD_FO_VERSION'] = (string) constant('TKD_FO_VERSION'); }
        $columns = self::stock_schema();
        return self::bounded(array('bridge_version' => TKD_Cloud_Reader::VERSION, 'schema_version' => 'tkd-integration-manifest-v1-candidate', 'versions' => $versions, 'classes' => $classes, 'total_methods' => $total,
            'stock_schema' => array('table_suffix' => 'tkd_fo_stock', 'state' => $columns === null ? 'unavailable' : (self::stock_guard($columns) ? 'read-compatible' : 'incompatible'), 'columns' => $columns === null ? array() : $columns)), self::MAX_MANIFEST);
    }
}
