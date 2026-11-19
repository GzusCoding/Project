# Step 4.14E — Final Buy Workflow Specification

## Status and scope

This document records the Buy Offer rules supplied for the original review.
The user later chose the direct purchase flow from the Word document, not Buy
Offer negotiation or counter-offers, and authorized implementation. The current
implemented behavior and migration status are documented in
[`MARKETPLACE_WORKFLOW_IMPLEMENTATION.md`](./MARKETPLACE_WORKFLOW_IMPLEMENTATION.md).
The Buy Offer-specific rules below are retained as historical review context
and do not describe the implemented direct Buy workflow.

## Buy Offer lifecycle

1. **Negotiation:** The Buyer submits a Buy Offer. The Seller may accept, reject,
   or send a Counter Offer. Each Counter Offer is a new revision; the latest
   revision is the active offer and starts a fresh seven-day negotiation period.
   The Buyer alone may cancel before acceptance. If not accepted, the active
   revision expires seven days after it was created.
2. **Accept and reserve:** On acceptance, create the Buy transaction and reserve
   the listing in the same database transaction. A listing may have only one
   active reservation across Buy and Exchange. Keep the reservation through the
   transaction and any open dispute.
3. **Payment and delivery confirmations:** During the transaction, record each
   confirmation below with the confirming user and timestamp. The parties may
   use in-person pickup, Seller delivery, Buyer delivery/courier, or another
   mutually agreed method. Seller must confirm dispatch/handover; Buyer must
   confirm receipt. Tracking is optional and is not verified by the website.
4. **Complete:** No automatic completion. Set `Completed` only when all four
   Buy Confirmations are recorded and no dispute is open. Then transfer
   ownership, close the reservation, and mark the original listing
   `Sold`/unavailable.
5. **Cancel:** Before acceptance, only the Buyer may cancel negotiation. After
   acceptance but before dispatch, either party may cancel; cancellation does
   not itself refund money. After dispatch and before Buyer receipt, normal
   cancellation is unavailable; follow the dispute provisions below.
6. **Dispute:** The reservation remains active during a dispute, and completion
   is blocked while one is open. PJF does not define Buy-transaction dispute
   eligibility, timing, adjudication, outcomes, or reservation release. Do not
   infer those rules or reuse the Exchange dispute workflow. After a dispute,
   reservation disposition requires consideration of its outcome and who holds
   the item; the detailed process remains unspecified by PJF.

## Buy Confirmations — separate from Exchange Handover Confirmations

| # | Buy confirmation | Recorded by |
| --- | --- | --- |
| 1 | Payment Sent — Buyer states payment was sent | Buyer |
| 2 | Payment Received — Seller states payment was received | Seller |
| 3 | Handover/Dispatch Confirmed — item was handed over or dispatched | Seller |
| 4 | Receipt Confirmed — item was received | Buyer |

These are user attestations, not verification by the website. The website does
not collect payment, verify payment slips, or certify that payment occurred.
Payment Evidence and Seller Verification are not completion guards; the
Seller's Payment Received confirmation is distinct from verifying evidence.
Do not substitute the Exchange confirmations:
`creator_handed_over_offered_item`, `recipient_received_offered_item`,
`recipient_handed_over_requested_item`, and `creator_received_requested_item`.

## PJF comparison

- **PJF, p. 19, Fig. 3.7.3** (the nearby caption says Fig. 3.7.2): the Buyer
  searches and selects a product, views details, buys; the system records the
  sale and returns success. It does not describe Buy Offers, revisions, or a
  seven-day deadline.
- **PJF, p. 24, Fig. 3.8.4:** the Buyer orders and selects a payment method; the
  system records the order and notifies the Seller; the Seller delivers and
  confirms “delivered”; the Buyer checks and confirms correct receipt; the
  system marks the transaction successful.
- **PJF, p. 26, Fig. 3.8.6:** the Buyer/offeror selects a payment method and
  uploads a transfer slip; the system records it and notifies the Seller; the
  Seller checks the evidence and confirms correctness. This differs from the
  approved Buy rule: payment and Seller confirmations are user attestations,
  evidence is not verified by the website, and evidence verification does not
  gate completion.
- **PJF, p. 27, Fig. 3.8.8:** Admin reviews reported behavior/listings; on a
  finding, removes a product or suspends an account; otherwise rejects the
  complaint. It does not define a Buy-transaction dispute workflow. PJF does
  not specify Buy reservation, four Buy confirmations, cancellation windows,
  ownership transfer, or the completion guard; these are additional approved
  rules, not claims about the original diagrams.

## Technical readiness only — unresolved

- **Offer/revision schema:** `offers` supports a cash difference on an
  Exchange-style product pair and statuses including `expired`, but has no
  revision identity/history or expiration timestamp. Application expiry and
  Counter Offer revisions are not implemented. See
  [`migrate_offers.php`](../migrations/migrate_offers.php#L53-L68).
- **Currency:** Existing money fields use integer minor units and a fixed
  allowlist (JPY/KRW/VND, selected two-decimal currencies, BHD/KWD); THB is not
  included. The Buy Offer currency, price representation, and whether THB must
  be supported require an explicit technical decision. See
  [`OFFERS_MIGRATION.md`](./OFFERS_MIGRATION.md#L71-L86).
- **Buy schema and reservation:** Current transactions and reservations are
  Exchange-specific. The shared one-reservation-per-product key is present, and
  Exchange acceptance already creates transaction, product reservations, and
  offer acceptance atomically; Buy needs a compatible representation without
  allowing Buy/Exchange double reservation. See
  [`migrate_exchange_transactions.php`](../migrations/migrate_exchange_transactions.php#L302-L353)
  and [`offer_action.php`](../offer_action.php#L52-L57),
  [`offer_action.php`](../offer_action.php#L180-L235).
- **Confirmation and completion guards:** The existing four confirmation types
  reference Exchange handover only; the migration explicitly adds no completion
  guard. Buy requires its own records and atomic four-confirmation/no-open-
  dispute completion behavior. See
  [`migrate_handover_confirmations.php`](../migrations/migrate_handover_confirmations.php#L113-L127),
  [`OFFERS_MIGRATION.md`](./OFFERS_MIGRATION.md#L261-L277).
- **Payment technical conflict:** The current payment schema requires a
  positive-cash completed Exchange transaction to have `verified` payment
  status. This conflicts with the approved Buy rule that evidence/Seller
  verification is not a completion guard. See
  [`migrate_payment_evidence.php`](../migrations/migrate_payment_evidence.php#L472-L479).
- **Migration numbering:** Source migrations run v1–v6, but the audited local
  database records only v1–v5; v6 is not applied. Proposed v7/v8 labels are not
  confirmed deployable versions. Resolve the target schema/ledger before
  assigning any Buy workflow migration number. See
  [`OFFERS_MIGRATION.md`](./OFFERS_MIGRATION.md#L3-L45).

No Dispute rules beyond the PJF admin-report behavior are specified here.
