<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Synthetic class metadata only. These method bodies must never run.
class TKD_FO_Orders {
    const VERSION = 'synthetic-1';
    public static function get_order_items($id, $optional = null) {
        throw new Exception('MUTATING BUSINESS GETTER INVOKED');
    }
    private static function hidden() { throw new Exception('PRIVATE METHOD INVOKED'); }
    public function non_static() { throw new Exception('INSTANCE METHOD INVOKED'); }
}
class TKD_FO_DB {
    const DB_VERSION = '3';
    public static function stock_table() { throw new Exception('DB GETTER INVOKED'); }
    public static function change_stock($id, $amount) { throw new Exception('WRITE INVOKED'); }
}
