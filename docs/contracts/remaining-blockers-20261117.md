# Remaining blockers after reservation evidence b3a7fd2

Source slice SHA256 93fca0fbf94ed59e01b56e2015f61f655f3cfca65d19eba2ed869ff80ad4722e verified locally. Runtime match has NOT been checked. No private invocation, UI redirect trap or live write.

Reservation: total helper computes start + selected36/48/72, never click-now;
handler persists extended stage and until, then bounded native log and claim.
Prepared pure reservation-contracts.php enforces exact hours, active/nonexpired
reserve, unpaid/unconfirmed/unprotected state, editor lock and native deadline
future. It does not calculate a replacement deadline. 24 synthetic assertions.
Not loaded by plugin or installer; native write service and gateway wiring pending.

Concrete remaining source/pin blockers:
1. Orders claim_responsibility_locked and Lifecycle owner_lock/prepare_link/unlock
   plus ownership persistence guards. Public claim can silently return empty on
   lock failure, so deadline+log alone cannot establish complete success.
2. Invoice buyer_fields and seller/bank normalization/selectable choices plus
   save hooks. Must preserve EVERY stored buyer field under exact allowlist,
   ensure exact linked invoice/order/seller, nonce and scoped wp_die restoration.
   Fullfile candidate Invoices/Orders differs from saved live: method pins and
   dependent private candidate matching needed, not replacement of live file pins.
3. Shipment Sales_Fiscal_Gate::status, Multi_Seller::with_delivery,
   scanner manager_release_plan/prepare_saved_for_release and dispatch planning,
   enroll/assigned, status/preflight/prepare dependencies. Their GET purity,
   lock/claim behavior, existing fiscal receipt rules and provider I/O must be
   checked. Keep full public execute_inline(...,'prepare_saved') only.

Implementation integration still outstanding (not an external access blocker):
- fixed operation schemas/caps, document/reservation state snapshot and revisions;
- native-source guard for reconstructed private reservation method, helpers,
  ownership and new shipment classes; fail closed on mismatch;
- claim/journal integration before any first native/domain write, terminal
  unknown on ANY incomplete readback, including log/claim failure;
- exact native-format bounded50 reservation log with last_action/at/user readback,
  no unbounded growth or arbitrary meta API; no Reflection private invocation;
- invoice full-save POST restoration/catch-die tests; downstream evidence;
- document editor lock and linked document/scanner/fiscal/NP state coverage;
- CLI operation schemas/results/journal compatibility; native fixture tests.

Existing status/payment journal remains unchanged and working; new preflight and
projection modules are source candidates only. No new routes/rights, installer,
production POST, payment, stock or reservation mutations in this step.
