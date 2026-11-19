# Marketplace workflow implementation

The workflow implementation follows the Word document's direct purchase flow.
New workflow pages use the same warm cream-and-orange visual language as the
marketplace. Existing homepage and account-page designs remain unchanged.

## Shared page styling

The shared stylesheet at `assets/css/marketplace.css` brings order details,
orders and reports, notifications, user profiles, product details, offer
details, exchange transactions, and the admin review queue into a consistent
responsive style. It adds shared typography, cards, form controls, action
buttons, notices, and mobile layouts without changing page behavior.

## Project layout

- Public PHP pages and endpoints remain in the project root so their existing
  URLs continue to work.
- Shared PHP code is in `includes/`.
- Database migrations are in `migrations/`; tests stay in `tests/`.
- Project documentation is in `docs/`, and the one-time Admin CLI utility is
  in `tools/`.
- The SQLite database, uploaded product images, and database backups remain in
  their existing locations because they contain runtime data.

## Buy orders

1. A buyer selects a payment method and submits a purchase.
2. The system records an order with a snapshot of the listing name and price,
   reserves the product so it cannot be purchased or exchanged twice, and
   notifies the seller.
3. For PromptPay or bank transfer, the buyer uploads payment evidence. The
   seller can inspect it, verify it, or reject it with a reason; the buyer can
   submit replacement evidence after a rejection. The seller cannot confirm
   dispatch until the payment evidence is verified. For cash on handover, the
   buyer records the cash payment instead.
4. The seller confirms dispatch, then the buyer confirms receipt.
5. The order completes after both confirmations, the product is marked sold,
   and both parties are notified that they can review the transaction.

The website records and reviews payment evidence; it does not initiate or
process a transfer through a bank or payment provider. Evidence is stored
outside the web root and is available only to the order participants.

This is a direct purchase flow, not a Buy Offer/counter-offer workflow.

## Exchange completion

Exchange keeps its four handover confirmations. After migration v7, the system
can complete an eligible exchange only when all four confirmations are present,
payment requirements are satisfied, there is no open dispute, and both
reservations are consistent. Completion transfers both products to their new
owners, marks the existing product rows inactive, and releases reservations.
Before v7 is applied, the existing handover workflow remains in effect.

## Exchange chat

Accepting an exchange offer creates a private chat room for its two
participants. The chat is available from the marketplace's **แชทของฉัน** menu
and from the exchange transaction page, where participants can also choose a
payment method, submit payment evidence, and review or confirm it. For a cash
difference, the owner of the lower-valued item pays in THB; the server computes
and snapshots both product values when the offer is created. The designated
payer submits payment evidence, and the other participant reviews it. Messages
refresh automatically while the page is open. Completed or cancelled
transactions retain their chat history in read-only mode.

## Buy order chat

Creating a direct purchase creates a private chat room for the buyer and seller
in the same database transaction as the order. The room is available from
**แชทของฉัน** and the order details page. Only the order participants can read
or send messages; after the order is no longer in progress, its history remains
available in read-only mode. Migration v9 backfills rooms for existing orders.

## Chat images

Participants can attach one JPEG, PNG, or WebP image (up to 5 MB) to an
exchange or purchase message; a caption is optional. Images are validated by
content, stored outside the web root with restricted permissions, and served
only after checking the viewer is a participant in that chat. Migration v10
adds image metadata to both message tables without changing existing messages.

## Offer and order tracking

The **My offers & orders** page shows incoming and outgoing exchange offers and
direct purchase/sale orders together by default. It identifies the user's role
in each item and supports direction and status filters. Direct orders link to
their order details; exchange offers retain their separate statuses and
transaction links. Its empty state links to marketplace listings. An exchange
chat room is created only after an offer is accepted; a purchase chat room is
created together with its order.

## Reviews and reports

- Participants may review one another once an order or exchange is completed.
- Both parties receive an in-app reminder to review after a completed order or
  exchange. A review notifies its recipient.
- The product detail page and public user profile show a seller's average score
  and review count; the profile lists received reviews.
- Users can submit listing or behavior reports. Reports enter the admin review
  queue; an admin must record a decision and reason.
- Reporters receive an in-app notification when the admin records a decision
  and can view the outcome under their own report history. Reports and
  notifications are private to their owners.
- Account suspension is enforced by protected workflows after migration v8.
- The admin workspace includes member status management with a reasoned audit
  history, category create/edit/delete (deletion is blocked while products use
  a category), and summary charts for members, active listings, buy orders,
  and completed exchanges.

Notifications are in-app only. No email, SMS, or push-notification service was
present in the existing application.

## Migration and database status

The implementation uses sequential SQLite migrations:

| Version | Script | Scope |
| --- | --- | --- |
| 6 | `migrations/migrate_handover_confirmations.php` | Exchange handover confirmations |
| 7 | `migrations/migrate_buy_orders.php` | Contact fields, category master data, direct Buy orders, payment evidence, in-app notifications, reviews, and Exchange completion guard |
| 8 | `migrations/migrate_reports.php` | Content reports and account suspension |
| 9 | `migrations/migrate_buy_order_chats.php` | Private chat rooms and messages for direct purchase orders |
| 10 | `migrations/migrate_chat_images.php` | Private image attachments for exchange and purchase chats |
| 11 | `migrations/migrate_admin_management.php` | Thai category labels and member-status audit history |
| 12 | `migrations/migrate_exchange_cash_difference.php` | THB value-difference snapshots and designated payer/reviewer rules |

Migration v7 requires v6 to be recorded and its handover/dispute schema to be
present. Migration v8 requires v7. Apply them only in order: **v6 → v7 → v8**.
Migration v9 requires v8. Apply them only in order: **v6 → v7 → v8 → v9**.
Migration v10 requires v9. Apply it only after v9.
Migration v11 requires v10. Apply it only after v10.
Migration v12 requires v11. Apply it only after v11. It preserves existing offer
and transaction records, adds optional price snapshots for new offers, allows
either exchange participant to be the designated payer, adds THB as a supported
cash currency, and updates payment evidence guards so the non-payer reviews.
Each script requires explicit confirmation, creates and verifies a restricted
backup, and checks data integrity. Do not skip a version or apply a migration
to a database whose schema or migration ledger does not pass its preflight.

The workspace database was migrated from v5 through v12 on 2026-11-19. Each
version created a verified backup in `.database-backups/`; the ledger contains
versions 1–12, all four existing purchase orders have a private chat room, and
both chat message tables support image metadata. Migration v11 preserves
existing products and category codes while adding localized category labels and
the member-status history table. Migration v12 adds the cash-difference rules
without changing existing offer or transaction records. `integrity_check`
returns `ok` with no foreign-key violations. Workflow tests use disposable
databases.

Migration v12 is covered by the isolated payment workflow test, including
preservation of existing records, THB constraints, recipient-as-payer evidence
submission, and review by the other participant.

## Verification

Run the focused integration test with:

```sh
php tests/buy_order_workflow_test.php
```

Run the complete PHP suite with:

```sh
for test in tests/*.php; do php "$test" || exit 1; done
```

The isolated Buy integration test exercises payment submission/rejection and
resubmission, participant-only evidence access, notification delivery and
read ownership, Buy and Exchange completion, reviews, public profiles, report
decisions, account suspension, and purchase-chat creation, messaging,
participant privacy, image upload/download authorization, invalid/oversized
image rejection, and read-only history. A live local HTTP smoke run also completed
the browser-facing buyer/seller flow against a disposable copy of the migrated
workspace database. Chromium UI testing verified login, purchase, payment
evidence upload, seller verification and dispatch, buyer receipt confirmation,
and notification display. This testing exposed and fixed form submission
issues caused by named fields shadowing form properties and submit buttons
being omitted from `FormData`.

The exchange chat browser check also exercised registration, product creation,
offer acceptance, chat inbox access, message send/receive, payment-method
selection, evidence upload, and seller verification on a disposable schema-only
database. It caught and fixed a shadowed form-action property in transaction
actions. The mobile transaction view was checked for horizontal overflow and
correctly hidden retry controls.
