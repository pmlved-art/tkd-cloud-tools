# Pending details schema diagnostics (not deployed or packaged)

Live 0.2.0 details returned 503 `tkd_schema_unverified` for the owner's permitted
order; minimal lookup, health, stock and manifest succeeded. The current route
does not identify its failing field. No live stored values were retrieved.

Code review identifies possible (NOT proven live) incompatibilities:

- Present empty string for one of the three monetary totals fails decimal
  validation; missing and stored null remain distinct accepted states.
- Present empty string instead of stored items/payments list fails expected_list;
  missing/null/empty list are not converted into an empty string or vice versa.
- quantity number/string with <=3 fractional digits passes; >3, exponent/comma,
  or boolean fails. unit_price permits <=2. No rounding or normalization.
- reservation_until / paid_at currently require stored text or null. An integer
  timestamp or boolean fails; text including empty text is accepted.
- Other causes (nested malformed shapes, duplicate IDs, invalid invoice ID,
  invalid UTF-8/control text) remain possible until server evidence exists.

Prepared change: only refused details responses gain data.schema_diagnostic:
`{field, reason, type, present}`. Field is an explicit fixed metadata/nested
allowlist path, or snapshot/response; never an input-supplied key or row index.
Reason/type are fixed enums. No values, lengths, hashes, customer/order IDs,
number, arbitrary keys, source/path, credentials or body fragments are emitted.
First failure only. Existing codes/status (including duplicate409/bounds503),
permission rules and no-store are preserved. No successful read is relaxed.
No business getter or database write was introduced. The pre-existing generic
minimal-route failures before metadata validation remain generic.

Reason enum: expected_list, row_bound, expected_row, empty_projection,
invalid_decimal, invalid_identifier, invalid_text, duplicate_id, duplicate_meta,
input_bound_or_unsupported_type, snapshot_changed, response_bound.
Type enum: NULL,string,integer,double,boolean,array,object,resource,
resource (closed),unknown. present is a boolean, not the stored value.

Do not install a separate diagnostic release yet. Combine with the next reviewed
update/version/client/package. The current CLI intentionally continues to emit
only a redacted HTTP summary for errors; a future controlled diagnostic request
can select exactly these four enum fields after installation.

Synthetic test: `php tests/php/test-details-diagnostics.php`.
No cause is asserted for the live503 until this diagnostic or a verified server
inspection identifies the stored field/type. No extra order numbers were queried.
