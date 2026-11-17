<?php
if (!defined('ABSPATH')) { exit; }
return array(
    'TKD_FO_Invoices' => array('	private static $saving = false;'),
    'TKD_FO_Orders' => array('	private static $reservation_mutex_depth = array();','	private static $pending_normalization = array();','	private static $status_before_save = array();','	private static $responsibility_form_order_id = 0;','	private static $agreed_before = array();','	private static $agreed_plans = array();','	private static $agreed_rejected = array();','	private static $agreed_locks = array();','	private static $fulfillment_filter_ids = null;','	private static $np_fulfillment_badges = array();','	private static $np_fulfillment_pending = array();','	private static $pickup_fulfillment_badges = array();','	private static $pickup_fulfillment_pending = array();'),
    'TKD_FO_Order_Lifecycle' => array(' const ENABLED=\'tkd_order_lifecycle_v1\';',' const EXPIRED=\'_tkd_reserve_expired_v1\';',' const LINK=\'_tkd_installment_parent_v1\';',' const CHILD=\'_tkd_installment_child_v1\';',' private static $busy=false;',' private static $form_id=0;'),
    'TKD_FO_Multi_Seller' => array('    const VERSION = 1;','    const HISTORY = \'_tkd_fo_multi_seller_history_v1\';','    const TERMS = \'_tkd_fo_package_terms_v1\';','    private static $route = null;'),
    'TKD_Warehouse_Scanner' => array('	const VERSION = \'0.1.14\';','	const CAPABILITY = \'tkd_scan_warehouse\';','	const TASK_META = \'_tkd_ws_task\';','	private static $lease = null;'),
    'TKD_FO_Dispatch' => array('	const META = \'_tkd_fo_dispatch_v1\';','	private static $initialized = false;','	private static $sms_scope = null;'),
    'TKD_NP_Dispatch' => array('	const META = \'_tkd_np_dispatch_v1\';','	const POLICY = \'tkd_np_dispatch_policy_v1\';','	const PROBES = \'tkd_np_dispatch_probes_v1\';','	const CURSOR = \'tkd_np_dispatch_poll_cursor\';','	const CRON = \'tkd_np_dispatch_poll\';','	const CLAIM = \'tkd_np_dispatch_claim_\';','	private static $initialized = false;','	private static $accepted_codes = array( \'4\', \'41\', \'5\', \'6\', \'7\', \'8\', \'9\', \'10\', \'11\', \'101\', \'106\', \'107\', \'111\' );'),
);
