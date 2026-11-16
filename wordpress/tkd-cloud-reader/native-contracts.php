<?php
if (!defined('ABSPATH')) { exit; }
/** Reviewed source pins. Missing contracts deliberately disable execution. */
final class TKD_Cloud_Native_Contracts {
    private static $profile = null;

    private static function profile() {
        if (self::$profile !== null) { return self::$profile; }
        self::$profile = array(
            'classes' => array(
                'TKD_FO_Orders' => array('file' => 'a847a5d1720451bf82026f04460e863982b32b3ba87dfe4c35818a3fa6bac4d1', 'methods' => array(
                    'acquire_reservation_mutex' => '6b6b447cf0ea5b32b9daf51f1dc4b276b3a4ab310660eccf3b34bac37d7c6491',
                    'release_reservation_mutex' => '7a8e5e95e05dbda737161070d7967564b378b4ae36faee93e2ec815ca8d014a7',
                    'get_responsibility' => 'a0dc49a89d63100ccdc7c390bf46fbe54acd55b3126a65599a76818dea01d318',
                ), 'private_methods' => array('reservation_manager_is_manual' => 'e8a8ac3bee5e3a6991dcc07016c9308256660ef05d1c6e4df44f341fe8543a64'
                )),
                'TKD_FO_Mobile_Cabinet_20261011' => array('file' => '7e58d37ef2a7d9b6c0a630da9e3cbf6d8715da03cdb7735cdd55363a551473d3', 'methods' => array(
                    'status_baseline' => '83725341863332f694e5196f5d25e76bf18ddaeb02d2c997b3ea54fa81b83383',
                    'change_status' => 'd7d0303c7c6dfac0f9a6ff4ba4348a15ed32cff5fc50182fb0eacfe9e7da112b',
                )),
                'TKD_FO_Payments' => array('file' => '474393a1adf826d194d396b875a9166926af8d18d93bdb5818ef0d89c61da382', 'methods' => array(
                    'save_new_payment' => 'c6c7e9466f378bb2880afc60695068edd18f85341b17a0e135fd78a41d78a75f',
                    'record_payment' => '1bac5450fc4845510e4dbb1278830a7727d0c009ef51f823d7ceda28d9a2978b',
                    'get_payments' => '74f8228539c79861fb6303a865c7336740e452ebcbb4bdbc12188b0637e9fdfe',
                    'order_total' => '0e55df74ad02e55c1118d3827878bcc66581d64b2db85b0859089adb9b5c9e50',
                    'paid_total' => '5634774a2ff44728119b0e035a6cb6b53490d7439b35cd370f9bdc19786aaf89',
                    'recipient_labels' => '33270a0db032f93a1ae9206ea3aef732f405d6d8ac59d900507a9c7245d85fbb',
                    'channel_labels' => 'e547d5f49f2372df8382921ca50ac474d04a71f48cfbfb46c7d49788df37fd07',
                    'commission_channels' => '2575035e77d9f20bec42b1a3028aa5e04f516f05af77ce2e8d943a1a06b7e42f',
                    'tov_payment_context' => '812dea19b6b2c19dea1161f622847d0f3e0664afe94f69e6a7adce22376a9da4',
                    'tov_confirmed_payment' => 'fcc95794456f92d8e645375bb6d612c0255ebe79afbc1a622f9aa7eb447390f9',
                ), 'private_methods' => array(
                    'valid_payment_datetime' => '50502fc9beadd93c6c261dc36bf98cde36ebdf3a3a19751bf1039643fb7d9e71',
                    'emeo_received_at' => 'c774d12b165dd43b0eff0ba0ee249f7cbcbb2ef42edb08ae7c8ee1295a78af23',
                    'emeo_package' => '253eba3ebbde7df2efba9f5148a2689da7c664459fc71425307e186048c74223',
                    'emeo_allowed' => '12abddcd11cf2da4a1f2fcf8824a173c61b17b2847b3a0d0aae6162dd32903a2',
                    'emeo_order' => '9661463b354653b8cef9edcfccf1e263bff2cfe11de0f22c10cb0c006185babb',
                    'sync_order_after_payment' => '3dec965be70eeced979be2babe3685b7b647ff17edf256e1605b1101f9175999',
                    'sync_split_invoice_statuses' => '92be5a0adbc6446c61f8c26a0e4b13251c76532e3d9b64fea7398166cc33d232',
                )),
                'TKD_FO_Order_Lifecycle' => array('file' => null, 'methods' => array(
                    'unpaid' => '685a404927bb4af1d411ae4c93692572190ce980368e1072d69b9a29d04327ee',
                    'protected_stock' => '891626ca7566bcf6a38e7faf3d274115f2af3f33075fb80a2be02845a9d7ab18',
                    'confirmed' => '28c016309fa1342aa03e656ce9a7a3ce9ff9b84351bf7787acce64f45b3d304b',
                )),
                'TKD_FO_DB' => array('file' => '6ed4e63c6b8e4b53024e80d1d75c70d2632670cd420069fae92c4d77dcef2935', 'methods' => array()),
                'TKD_FO_Invoices' => array('file' => 'b4cd1b9e573f2c18b7a637aac46f8ed92fe36e5a0c8560aae2b0b197fb424db7', 'methods' => array()),
                'TKD_FO_Deliveries' => array('file' => '545dd9fb07186c16dc768dbb2b4bd018fe5959bc986023ae6dfe416d96256519', 'methods' => array()),
            ),
            'manual_channels' => array('pumb', 'privatbank', 'raiffeisen', 'bank_emeo'),
        );
        return self::$profile;
    }

    public static function verify($operation) {
        $report = self::report($operation);
        return $report['verified'] ? true : $report['reason'];
    }

    public static function report($operation) {
        if (!in_array($operation, array('status.change', 'payment.add_manual'), true)) { return array('verified' => false, 'reason' => 'unsupported_operation'); }
        $profile = self::profile();
        $classes = array('TKD_FO_Orders', 'TKD_FO_Mobile_Cabinet_20261011', 'TKD_FO_Payments', 'TKD_FO_Order_Lifecycle', 'TKD_FO_DB', 'TKD_FO_Invoices', 'TKD_FO_Deliveries');
        foreach ($classes as $class) {
            if (!class_exists($class, false) || !isset($profile['classes'][$class])) { return array('verified' => false, 'reason' => 'native_missing', 'method' => $class); }
            $pin = $profile['classes'][$class]; $ref = new ReflectionClass($class); $file = $ref->getFileName();
            if (!is_string($file) || !is_file($file) || @filesize($file) > 2097152) { return array('verified' => false, 'reason' => 'native_source_unavailable', 'method' => $class); }
            $bytes = @file_get_contents($file, false, null, 0, 2097153);
            if (!is_string($bytes) || strlen($bytes) > 2097152 || substr_count($bytes, "\n") > 50000) { return array('verified' => false, 'reason' => 'native_source_unavailable', 'method' => $class); }
            $actual = hash('sha256', $bytes);
            if ($pin['file'] !== null && !hash_equals($pin['file'], $actual)) { return array('verified' => false, 'reason' => 'native_drift', 'method' => $class, 'expected_sha256' => $pin['file'], 'actual_sha256' => $actual); }
            $lines = explode("\n", $bytes);
            foreach (array('methods' => 'public', 'private_methods' => 'private') as $group => $visibility) {
                foreach (isset($pin[$group]) ? $pin[$group] : array() as $name => $sha) {
                    if (!$ref->hasMethod($name)) { return array('verified' => false, 'reason' => 'native_missing', 'method' => $class . '::' . $name); }
                    $method = $ref->getMethod($name);
                    if (($visibility === 'public' ? !$method->isPublic() : !$method->isPrivate()) || !$method->isStatic() || $method->getFileName() !== $file) { return array('verified' => false, 'reason' => 'native_visibility_mismatch', 'method' => $class . '::' . $name); }
                    $start = $method->getStartLine(); $end = $method->getEndLine();
                    if (!is_int($start) || !is_int($end) || $start < 1 || $end < $start || $end > count($lines)) { return array('verified' => false, 'reason' => 'native_source_unavailable', 'method' => $class . '::' . $name); }
                    $slice = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
                    if ($end < count($lines)) { $slice .= "\n"; }
                    $actual = hash('sha256', $slice);
                    if (!hash_equals($sha, $actual)) { return array('verified' => false, 'reason' => 'native_drift', 'method' => $class . '::' . $name, 'expected_sha256' => $sha, 'actual_sha256' => $actual); }
                }
            }
        }
        return array('verified' => true, 'reason' => 'matched');
    }

    public static function channels() {
        return self::profile()['manual_channels'];
    }
}
