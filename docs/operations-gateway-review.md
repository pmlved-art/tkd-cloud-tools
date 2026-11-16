# 0.3.0 candidate — not installed

Scope: status.change and payment.add_manual only. No production POST/native
business mutations were performed. Private/Lifecycle candidate hashes have NOT
been checked live; their exact runtime guards must match before execution.
This is a source-review candidate, not a claim of live write readiness.

## Existing reads and stock

Existing read routes/auth/cap remain. Details schema diagnostics are enum-only
field/reason/type/present. No relaxation/normalization of stored values.
Protected payment detail projection now additionally includes payer_name (512
UTF-8 bytes), reference and bank_transaction_id (128 bytes each). No mass CLI
printing, no live PII in Git. Programmatic read requires explicit include_data.

Stock schema v2 requires reserved_supply. Exact fixed-scale six arithmetic:
supply=max(0,reserved_supply); physical_reserved=max(0,reserved-supply);
free_physical=on_hand-physical_reserved;
free_after_supply=max(0,free_physical+max(0,incoming-supply)).
available_on_hand_minus_reserved is retained as a legacy arithmetic difference,
NOT the actual free physical stock. Numeric inputs <=12 whole/6 fractional
places, 64-bit PHP required; intermediate bounds stay within signed64. No calls
to DB stock getters/install/upgrade. Current live0.2 stockv1 remains readable by
Python; v2 validates all formulas without binary floating-point arithmetic.

## Rights and admin action after an ordinary plugin update

Reader role unchanged. New exact TKD Cloud Operator role:
read, tkd_cloud_read_orders, tkd_cloud_change_status,
tkd_cloud_add_manual_payment, manage_tkd_felicity.
No manage_options/administrator/delete/edit_* capability grants. Native
current_user_can(edit_post,order_id) is still required; never bypassed or mapped
by this plugin. If site's native capability map does not permit it, operations
remain forbidden. Deferred payment shortcuts are unavailable to all gateway
actors; existing owner/Evgen native policy is not altered.

No account promotion at activation or normal update. Інструменти → Хмарний оператор ТКД
is registered on every plugin load. Its POST action checks manage_options,
WordPress nonce, exact confirmation, and login tkd-cloud with ID10. It calls
ensure_role before set_role, so it works when a normal plugin update did NOT
run activation_hook. Tests cover this with the operator role initially absent.
Action replaces roles exactly; existing Application Passwords are not touched.
Deletion/deactivation does not delete users, journal or business records.

## Fixed API (prefix /wp-json/tkd-cloud/v1)

GET /capabilities: implemented/unsupported, actor caps, bounded fingerprint
match/drift diagnostics (enum class/method and SHA256 only), manual channel
allowlist; preview_is_acceptance=false.
GET /orders/{full-number}/operation-snapshot: exact id/number, status, opaque
revision, native_edit_post, editor_locked, capability diagnostics. Pure reads:
no mutex/nonce/journal writes, no mutating TKD getters. Native status_baseline is
called only after matching guards. It invokes the reviewed pure responsibility
getter. Snapshot revisions HMAC cover the fixed order metadata list (including
raw ledger/items, money, seller flags, lock and history), responsibility option,
single linked invoice's relevant stored fields/modified time and native baseline.
No raw tokens, payer/customer fields or native baseline are returned.
Preview is non-atomic and does not promise native acceptance; under-lock
revision recheck and current native validation remain required. Split-invoice
operations are refused until a complete multi-document snapshot adapter exists.
GET /operations/{uuid-v4}: current actor's durable redacted outcome only. No
native invocation or journal update. Pending is displayed as unknown/no-retry.
POST /operations: one fixed operation, payload <=8192 bytes; no query params,
callback, arbitrary meta, SQL or user IDs. App Password + HTTPS + read cap,
explicit operation cap + manage_tkd_felicity + native edit_post are required.

Envelope exactly:
operation_id (lowercase UUIDv4), operation, order_id (positive integer), number
(full TKD-...), revision (64 lowercase hex), payload (fixed object).
status.change payload: {choice}; fixed enum excluding shipped/deferred_payment.
Native change_status(id,choice,baseline) is called, never the redirect/exit UI
handler. Native WP_Error is redacted to safe precondition enums; partial/unclear
native errors become terminal unknown. A returned string plus persisted exact
status/cancel reason establishes complete, not arbitrary text returned by API.
Reservation sync is not separately claimed in the summary.

payment.add_manual payload exactly:
amount (positive decimal string <=12 whole/2 fractional digits), payer_name,
recipient_key, channel_key, received_at (valid site-local Y-m-d H:i:s, not
future), reference, confirm_additional (boolean). Text must round-trip native
sanitization, be nonempty, trimmed, bounded and control-free.
Recipients: fop_iy/fop_pm/fop_rv/fop_tp/fop_md/fop_eo. Channels:
pumb/privatbank/raiffeisen/bank_emeo. EO only bank_emeo; EMEO reference must be
the exact order number. Native seller/EMEO validations remain authoritative.
No bank attestation flags are generated. pumb_pml, WFP, LiqPay, installments,
Novapay, acquiring/commission channels are unsupported. TOV and split invoice
payment are explicitly refused until context/rules have a complete adapter;
the native TOV rule is never bypassed by fabricating a confirmation/fingerprint.

A pure preflight refuses unknown totals/ledger, overage, legacy ambiguity and
possible duplicate amount+date/reference/bank-transaction references (including
void/uncertain rows). No override to force a duplicate. These conservative
guards may reject a legitimate repeated same-amount transfer for manual review.
Full save_new_payment(id,post,true) then performs native validation. A native
nonce is generated only AFTER auth, cap, shared lock, revision, preflight,
fingerprints and durable claim. Adapter swaps $_POST for only documented UI
fields in try/finally, forces new operation, deterministic token tkd-cloud-UUID,
fee0 and no attestation, restores the original $_POST even on exception. It does
not call record_payment directly. Native save enforces source manual/actor.

Result verification re-reads exact stable token, amount/payer/recipient/channel/
date/reference/source/actor/status/fee/net plus unchanged preexisting ledger
rows. A persisted payment is terminal recorded_needs_review, payment_saved=true,
reservation_sync_verified=false, needs_review=true. There is NO universal
native downstream marker; never claim invoice/reservation sync from row presence.
Void native refusal without relevant state change is failed/native_not_accepted.
Partial, exception or ambiguous effect is unknown; if an exception follows a
verified stored row, recorded_needs_review. Neither outcome is automatically
retried. Manual bank instructions must come from the actual user's money task;
unit fixtures are NOT authority to create test payments in production.

## Locks, idempotency and journal durability

Before invocation: native acquire_reservation_mutex(order_id), exact identity,
fresh revision check under the mutex, wp_check_post_lock and raw active
_edit_lock (also protect same-account active editor), preflight, second pin check.
Malformed/ambiguous editor lock fails closed. Native shared mutex is reentrant.
No naive outer transaction wraps native services that may COMMIT themselves.
WordPress editor heartbeat is a separate protocol: the active-lock check is a
preflight, not a claim that all external code takes this mutex. Lock fields are
also in the revision snapshot. Native guards remain the final business authority.

Global operation ID uniqueness is enforced by a fixed prepared INSERT IGNORE
in the WP options table, AFTER checking the actual unique option_name index.
No reliance on add_option's possible ON DUPLICATE KEY UPDATE. Concurrent different
order locks with one operation ID cannot replace the winner. Missing unique index
fails closed. This is journal SQL only, never order/payment/stock SQL writes.
Stored record contains IDs/actor, server HMAC payload digest, canonical intent
SHA256 (random UUID+revision included), state/timestamp and bounded audit events;
no payer/reference/body/headers/credentials. autoload=no. Pending is durable and
verified before native invocation. Complete/failed/recorded_needs_review/unknown
are persisted/read back; save failure reports unknown and retained claim prevents
re-invocation. Interrupted pending becomes durable unknown on an explicit POST
replay under mutex, without calling native. GET journal stays pure.

Replay requires same actor/order/payload digest, otherwise409. Journal recovery
never authorizes a second native attempt. There is intentionally no reset/delete
endpoint or automatic expiry; do not delete the claim to retry an uncertain
financial operation. Native outcome and audit need owner review/reconciliation.

## Pins and limitations

Fixed names only, exact public and private source-line SHA256, static/visibility
and file-size guards; no reflection invoke/source export. Complete known file
pins for Orders/Mobile/Payments/DB/Invoices/Deliveries; Lifecycle candidate pins
are per-method because its full filehash was not provided. All candidate private
hashes must match. Files can be deployed between snapshot/invocation: checks are
repeated under the order mutex just before claim. Pins concern disk source, not
cryptographic proof of bytecode; deployment must ensure OPcache is current.
Mismatch reports only fixed method enum/expected/actual SHA256, never a path.
Schema diagnostics do not silently enable a missing/private/changed service.

## CLI workflow (only after reviewed installation and explicit user task)

python3 -m scripts.operations capabilities
python3 -m scripts.operations snapshot --number TKD-FULL-NUMBER
python3 -m scripts.operations new-id
python3 -m scripts.operations journal --operation-id UUID
python3 -m scripts.operations execute --confirm-execute < /secure/path/intent.json

Use the snapshot's order_id/revision to construct the explicit intent; never
save actual payment inputs in Git or shared shell history. stdin bounded8KiB,
strict JSON and duplicate-key rejection. The client uses the existing full-header
literal binding, inherited managed proxy/CA and exact HTTPS host443, no cookies,
no redirect follow or POST retry. It first GETs the server journal. If an existing
outcome matches operation kind AND intent digest, it reports it without POST;
mismatch is refused. Only an actual journal404 for a locally prepared intent
permits one POST. Local state submitting is fsynced before sending.

Local journal default /workspace/shared/tkd-cloud-operation-journal in Cloud,
~/.local/state/tkd-cloud-operation-journal elsewhere; override with nonsecret
TKD_CLOUD_JOURNAL_DIR. Requires directory0700/files0600, no symlinks, per-file flock,
append-only records with fsync (including directory). No payload/PII/secret is
stored there. Interrupted/torn append, lost result, local mismatch, timeout or
unknown server outcome never trigger another POST. Query journal explicitly.
Mac requires a separately approved managed proxy/binding; don't copy cloud
placeholder/credentials into shell/Git. CLI outputs only IDs/revision/outcome/
booleans/safe fingerprint diagnostics, no mass personal data.

## Evidence for review

Synthetic WP/native fixtures, not live integration or real business mutations.
PHP gateway tests cover auth/cookie/caps, fixed schema, stale revisions, common
and editor locks, atomic claim races/missing unique index, actor/order/digest
mismatch/replay/interruption, terminal-save failure, native refusal/partial error,
payment duplicate/overage/channel/source restrictions, $_POST restoration,
recorded_needs_review semantics, private candidate drift and explicit admin role
assignment after update without activation. Read tests retain details diagnostics,
no-store, bounds and supply-aware exact stock precision. Python tests exercise
one-POST maximum, GET journal recovery, local fsync/replay/digest/torn append,
TLS/literal binding/redirect denial and redaction.

Run php tests/php/test-operations.php;
php tests/php/test-details-diagnostics.php;
python3 -m unittest discover -s tests -v.
No install ZIP until independent review. Future item/invoice/reservation/shipment
adapters remain unsupported and are not implied by gateway source completeness.

## Інструкція оператора

Після звичайного оновлення відкрийте Інструменти → Хмарний оператор ТКД.
Підтвердіть призначення лише tkd-cloud (ID 10). Дозволена лише точна попередня
роль Reader або Operator без персональних прав; зайві прямі/ефективні права
блокують дію. Наявний Application Password не змінюється.

Для конкретного доручення спершу отримайте snapshot/revision, створіть і
збережіть operation ID. Виконуйте лише один POST через CLI з явним
підтвердженням. Після відповіді або мережевої помилки перечитайте замовлення
та GET journal цього operation ID. Не повторюйте POST наосліп і не створюйте
інший ID для повторення невизначеної операції. payment recorded_needs_review
означає збережений платіж без доведеної синхронізації резерву: оплату повторно
не додавати. Повний обсяг інтеграції ще не завершено; товари, рахунки,
резервування й відвантаження через шлюз не підтримуються. Runtime fingerprints
та реальні права мають пройти перевірку після встановлення до операцій запису.
