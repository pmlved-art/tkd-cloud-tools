> Historical inventory; superseded by 0.4.0-candidate.md and current source.

# Next native adapters — source review gate

No new routes, caps, native invocations, role changes or installer in this step.
next-native-adapters.json is a bounded source-review inventory derived from the
saved live 2026-11-17 manifest. It is not a runtime enable switch or proof of
current live code. Full file pins for Invoices/Deliveries already match the
0.3.1 operations guard, but this workspace contains no native implementations
of the candidate methods. Synthetic fixture classes are not native evidence.

## Required source and adapter boundaries

- invoice.draft: ensure_manager_draft(order_id, optional argument),
  manager_invoice_context(order_id), save_invoice(id, post, update), their
  seller/payer/split helpers and save hooks. Determine whether a public method
  ensures existing draft or creates one, parameter semantics, numbering,
  permission/lock checks and exact persisted linkage before using it.
- invoice.create: create_issued_for_order(order_id), create_from_order(), and
  dependency closure. Zero-argument UI handler cannot be assumed to be a REST
  service. Need evidence for return values versus redirect/exit, VAT/FOP seller,
  payer fields, item prices/quantities, split invoice and issue prerequisites.
- reservation.extend: handle_reservation_action() and the two deadline helpers,
  including private implementation it delegates to and metadata write guards.
  Known deadline hashes do not establish extension authorization or writes.
  UI redirect/exit handler will not be invoked as a service; do not invoke its
  private helpers through Reflection. Need a reviewed public service or native
  extraction preserving its complete rules. Modes limited to total36/48/72 only
  after evidence of total-versus-additional hours, timezone, source timestamp,
  payment state, ownership, protected stock and maximum deadline rules.
- delivery.prepare: create_from_invoice(), save_delivery(id,post,update),
  issued_quantities_for_order(order_id), dependent invoice/document helpers.
  Need draft reuse/duplicate rules, partial remaining quantities and linkage.
- shipment.issue: save_delivery, validate_waybill_before_issue(delivery_id),
  post_saved_for_handover(id,post), all stock journal, scanner, serial, fiscal,
  warehouse handover and Nova Poshta hooks. Need exact guards and result
  verification. A valid waybill alone does not authorize physical issue.
  Neither raw stock delta nor setting shipped metadata is an issue adapter.

Source request includes relevant boot hook registrations and any private
helper bodies, constants and service dependencies. Source only, no exports,
credentials, real order/customer records. Compare exact source slices with
recorded method hashes; private helpers require fixed runtime pins as before.

## Execution design to retain

Adapters will use strict fixed operation schemas, explicit cloud operation caps
and actual native edit_post, exact order/document ownership and ID matching.
Under the native shared order mutex: refresh fixed stored state, check editor
lock and revision, validate prerequisites and runtime fingerprints, durably claim
operation ID before calling an explicitly reviewed native public service once.
Retain existing atomic INSERT IGNORE journal unique claim; do not weaken it.
No outer transaction around native own COMMIT. Native may reenter shared mutex.
Revision must include linked document state, seller/payer/items, relevant stock
and scanner/serial/fiscal/NP state, not just order status_baseline. Enumerate
those dependencies from source before implementing a snapshot.

After invocation reread persisted document linkage and domain markers; native
void/error alone cannot establish no effects or successful completion. Partial
or interrupted outcome is unknown/needs_review, durable and never replayed.
No GET calls mutating draft-ensure/getters. CLI: persist operation ID/intent,
one POST only, then GET journal/reread, no blind retry. No PII in summaries/Git.

## Tests to implement with actual contracts

GET/auth/schema refusals cause zero native calls; stale order/document revision;
active editor lock/shared lock contention; UUID payload/actor/order mismatch;
claim race/terminal-save failure; duplicate draft/doc reuse; incompatible seller
or payer/split invoice; extension bounds and total-hour semantics; partial
shipment/serial uniqueness/scanner/fiscal/NP refusals; partial native effects
classified unknown with no repeat. Synthetic tests are not live business writes.

At present none of the five adapters has sufficient native source evidence in
this workspace to safely invoke. Existing status/payment operations and reads
are unchanged. Complete implementation follows source review, not UI guesswork.

## Evidence received at b98b07e (supersedes missing-body inventory above)

58 source slice hashes validated, 16 exact public methods match saved live
manifest. Invoices/Orders candidate fullfile hashes DIFFER from saved
live despite matching public slices: do not replace existing live file pins with
these candidate fullfile hashes or claim private helpers match. Sales Release
and scanner need new fixed runtime class/method pins and dependency evidence.

Prepared document-contracts.php is an isolated pure projection/outcome adapter,
not included in plugin bootstrap/build and not an enabled route. Native snapshots
must not be called until dependency read-only behavior is proved. It filters
facts, blocker text, fiscal messages, manager plans and source PII. Strict
order/invoice/delivery identity and native revisions are retained. WP_Error or
invalid result is unknown; posted-but-not-prepared is recorded_needs_review,
no retry. Full persisted verification is mandatory before complete. NP prepared
never means physical carrier acceptance. 24 synthetic assertions pass.

Confirmed invocation selection for subsequent integration:
- invoice draft: ensure_manager_draft under journal, never on GET. Native itself
  calls mutating get_order_items and creates documents; revision/preflight must
  cover terms/pricing/bank dependencies. Reentrant SQL GET_LOCK behavior under
  shared mutex must be retained and tested.
- invoice issue/save: full save_invoice, preserve every stored buyer field via
  explicit allowlist POST (missing fields clear them), nonce only after claims;
  trap wp_die safely with request-local finally restoration, not generic bypass.
  It is void and may save draft/errors: reread document status/number/linkage.
- shipment issue: execute_inline(id, revision, 'prepare_saved'), full public
  workflow; strict allowlisted command/release+dispatch nonces. Never call
  issue_delivery as a substitute. It may fiscalize before stock, and stock may
  post before dispatch prepare fails: distinguish durable effect and needs_review.
- reservation: old private redirect handler is excluded; await Nov14 total-hour
  candidate. Future fixed native service extraction must preserve authorization,
  claim_responsibility, append_reservation_log, deadline semantics and mutex,
  not Reflection invoke or duplicated independent reservation implementation.

Remaining dependency source was requested explicitly, including buyer_fields,
bank normalization/choices, Multi_Seller with_delivery, fiscal status and scanner/
NP/pickup plan/prepare services. No installer, role/cap change or live POST.
