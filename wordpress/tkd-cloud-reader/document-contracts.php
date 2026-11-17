<?php
if (!defined('ABSPATH')) { exit; }
/** Pure native result projections; no routes or native invocations in this class. */
final class TKD_Cloud_Document_Contracts {
    private static function revision($value) {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }
    // Input is an in-memory native manager_snapshot, never an untrusted REST callback.
    // No native getters or arbitrary classes invoked; caller must establish pins/auth first.
    public static function release_snapshot($snapshot, $order, $invoice, $delivery) {
        if (!is_int($order) || !is_int($invoice) || !is_int($delivery) || min($order, $invoice, $delivery) < 1 || !is_array($snapshot)) { return null; }
        foreach (array('order' => $order, 'invoice' => $invoice, 'id' => $delivery) as $key => $expected) {
            if (($snapshot[$key] ?? null) !== $expected) { return null; }
        }
        if (!in_array($snapshot['mode'] ?? null, array('pickup', 'np'), true) || !in_array($snapshot['status'] ?? null, array('draft', 'issued'), true)
            || !self::revision($snapshot['revision'] ?? null) || !self::revision($snapshot['document_basis'] ?? null)
            || !self::revision($snapshot['office_revision'] ?? null) || !is_array($snapshot['blockers'] ?? null)
            || count($snapshot['blockers']) > 100 || !is_array($snapshot['fiscal'] ?? null)
            || !in_array($snapshot['fiscal']['state'] ?? null, array('ok', 'not_required', 'ready', 'blocked'), true)) { return null; }
        foreach ($snapshot['blockers'] as $blocker) { if (!is_string($blocker) || strlen($blocker) > 8192) { return null; } }
        return array('order_id' => $order, 'invoice_id' => $invoice, 'delivery_id' => $delivery,
            'revision' => $snapshot['revision'], 'document_basis' => $snapshot['document_basis'],
            'office_revision' => $snapshot['office_revision'], 'mode' => $snapshot['mode'],
            'document_status' => $snapshot['status'], 'blocked' => !empty($snapshot['blockers']) || $snapshot['fiscal']['state'] === 'blocked',
            'fiscal_state' => $snapshot['fiscal']['state'], 'preview_is_acceptance' => false);
    }
    // execute_inline may have posted even if handover preparation remains incomplete.
    // A WP_Error/exception/invalid shape never proves absence of fiscal or stock effects.
    public static function release_outcome($native, $persisted_verified = false) {
        $unknown = array('state' => 'unknown', 'posted' => null, 'prepared' => null, 'physical_shipment_verified' => false, 'needs_review' => true, 'retry_native' => false);
        if (!is_array($native) || !array_key_exists('posted', $native) || !array_key_exists('prepared', $native)
            || $native['posted'] !== true || !is_bool($native['prepared'])) { return $unknown; }
        return array('state' => $native['prepared'] && $persisted_verified === true ? 'complete' : 'recorded_needs_review',
            'posted' => true, 'prepared' => $native['prepared'], 'physical_shipment_verified' => false,
            'needs_review' => !$native['prepared'] || $persisted_verified !== true, 'retry_native' => false);
    }
}
