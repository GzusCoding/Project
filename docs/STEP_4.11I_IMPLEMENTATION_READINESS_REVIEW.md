# Step 4.11I — Corrected Implementation Readiness Review

## Purpose and scope

This document records the readiness review as it stood before implementation.
Its future-work and authorization statements are historical and have been
superseded by the user's subsequent authorization to implement the direct Buy
workflow. The current implementation, migration order, verification, and
workspace database status are documented in
[`MARKETPLACE_WORKFLOW_IMPLEMENTATION.md`](./MARKETPLACE_WORKFLOW_IMPLEMENTATION.md).

No migration has been applied to the workspace or Production database.

## A. Migration separation and numbering

### Approved scope boundaries

The work is split into two independently authored and applied migrations:

**Migration v7 — Lifecycle and Payment/Dispute Retirement**

- `workflow_version`
- Reservation Design B
- Product lifecycle and ownership transfer
- Completion guards and an atomic completion workflow
- Retirement of active Payment and Dispute write/UI paths
- Preservation of historical payment and dispute records
- Existing disputed transactions remain frozen

**Migration v8 — Report System**

- Participant Reports
- Report Events and follow-up records
- Admin review and appeal workflow
- Access audit and evidence-reference controls

The v7 and v8 schemas, migration scripts, backups, application procedures, and
rollback procedures must remain separate. Do not call them a “v7/report
migration” or otherwise combine their scope. The intended labels v7 and v8 are
proposed sequence labels, not authorization to create or apply either
migration.

### Workspace numbering check and conflict

The repository's migration scripts establish this sequence:

| Version | Existing migration |
| --- | --- |
| 1 | Products |
| 2 | Offers |
| 3 | Exchange transactions, product reservations, and chat rooms |
| 4 | Chat messages |
| 5 | Payment evidence and transaction disputes |
| 6 | Handover confirmations |

The read-only inspection of the local workspace database
`hybrid_marketplace.sqlite` found migration records 1 through 5. The workspace
also contains `migrations/migrate_handover_confirmations.php` for version 6, but version 6
is not recorded in that local database.

Therefore, v7 is **not confirmed as the next applicable migration** for the
workspace database. This is a numbering/state conflict that must be resolved
before implementation. Do not renumber, create, or apply v7/v8 based only on
the proposed labels. Before implementation, a separately authorized
workspace-only preflight must determine the intended database target and
reconcile its schema and migration records with the v6 migration. If any target
database has an unexpected migration record or schema, stop and report the
conflict; do not make a best-effort migration.

No Production Database was accessed. The local version inspection was
read-only and did not reveal or verify any Production Database state.

## B. Workspace-only boundaries

This correction is limited to documentation. It must not:

- Access or modify a Production Database.
- Apply any Production migration.
- Deploy the application.
- Start or restart any server.
- Commit or push changes.
- Modify source files, application behavior, schemas, or migration scripts.

Any later implementation, workspace database changes, server operation,
deployment, or Production activity requires separate authorization. Production
access and Production migration/deployment are outside the scope of this
review.

## C. Approved Report Policy

The following policy is approved and must be preserved:

### Categories

- Unpaid agreed cash difference
- Fraud/scam
- Product not shipped
- Product significantly different from agreement
- Inappropriate behavior
- Other

### Statuses

- `pending`
- `under_review`
- `upheld`
- `dismissed`
- `appealed`

### Policy rules

- Only eligible participants in the transaction may report.
- Self-reporting is prohibited.
- A reporter may submit only one initial report for a given
  reporter/reported-participant/transaction combination.
- Further context and evidence must be added as auditable follow-up events,
  not as duplicate initial reports.
- Description length limits and submission rate limits are required; their
  exact values remain to be specified.
- Submitting a report must not automatically punish or suspend any person.
- A report is an allegation of platform-policy misconduct, not proof of
  non-payment.
- An upheld decision means a platform-policy violation was established; it
  does not mean payment was verified.
- Every admin decision must include a reason.
- Appeals should support independent review where feasible.
- Report event and audit history must be append-only.
- Admin access to transaction context, chat, and evidence must be scoped and
  logged.
- Unrelated private messages must not be exposed.
- Do not implement reputation, credit scores, or automatic punishment based
  on report volume.

## D. Open technical decisions and proposed defaults

The following are not approved implementation details. Proposed defaults are
recommendations for a separate technical specification and authorization.

| Topic | Proposed technical default | Unresolved decision |
| --- | --- | --- |
| Report table and columns | Normalize initial reports separately from append-only report events; use stable identifiers, transaction and participant references, controlled category/status values, timestamps, and decision reason fields. | Exact table names, columns, keys, constraints, indexes, nullability, and allowed transitions must be specified. |
| Evidence | Store evidence outside report rows and persist only access-controlled references and metadata in the report system. | Storage location, accepted file types/size, malware scanning, encryption, access mechanism, deletion policy, retention period, and legal holds. |
| Follow-up events | Represent every submission, context/evidence addition, status change, decision, appeal, reassignment, and administrative access as a distinct append-only event. | Exact event schema, actor attribution, event payload minimization, integrity controls, retention, and audit access. |
| Immutability | Deny ordinary application updates/deletes to historical events; append a correction or superseding event instead. | Database-level versus application-level enforcement, privileged maintenance exceptions, tamper evidence, and audit verification. |
| Appeal reviewer | Prefer a reviewer who did not make the original decision, when staffing permits. | Independence criteria, conflicts-of-interest handling, reviewer assignment, reassignment authority, and behavior when no independent reviewer is available. |
| Admin permissions | Apply least privilege with separate report review, appeal review, and evidence/context access grants; log access to sensitive materials. | Exact roles, grants, authorization checks, emergency access, review cadence, and who can view which fields or evidence. |
| Transaction/chat context | Permit only the minimum context needed to decide the report; exclude unrelated private messages. | Precise message selection/redaction rules and whether contextual access requires additional approval. |
| Limits | Enforce configurable server-side description limits and per-user submission throttling. | Exact character/byte limits, rate-limit window and threshold, trusted proxy handling, and user-facing behavior when limited. |
| Migration dependency | Keep v7 and v8 in separate migration units with independent backup/apply/rollback procedures. | Whether v8 has a hard schema dependency on v7 or can be installed independently after the verified transaction schema. |
| Numbering | Keep proposed names v7 and v8 only if the intended target is verified to have v6 applied and no conflicting records. | Resolve the local workspace's v1–v5 records versus existing v6 migration before assigning deployable version numbers. |
| Rollback and backup | Take a verified, access-restricted backup immediately before each migration; restore only through an approved procedure. Avoid destructive rollback after dependent or user data is written. | Backup retention/location, restore owner, recovery objectives, forward-fix versus restore policy, and handling of data written after migration. |

## E. Corrected implementation stages

These are sequencing requirements for a future, separately authorized
implementation; none are performed here.

1. **Preflight and reconcile:** Confirm the intended workspace-only database,
   inspect its schema and migration records read-only, reconcile the missing
   version-6 record/state, and confirm the exact numbering. Stop on any
   unexpected state.
2. **Specify v7:** Finalize the lifecycle, reservation, completion, payment/
   dispute retirement, preservation, and rollback specifications without
   changing the approved lifecycle decisions below.
3. **Implement and validate v7 only:** Create a distinct migration, application
   changes, and tests for v7. Verify preservation and legacy read-only
   behavior. Do not include report-system schema or code in v7.
4. **Specify v8:** Resolve the open report-system schema, evidence, permissions,
   audit, appeal, retention, and dependency decisions.
5. **Implement and validate v8 only:** Create a distinct migration,
   application changes, and tests for v8. Do not include v7 changes in the v8
   migration or rollback procedure.
6. **Integrated regression review:** Validate both sets of behavior together
   against a disposable test database and confirm no Production or deployment
   operation is part of the authorized scope.
7. **Separate operational authorization:** Any deployment, server operation,
   Production access, or Production migration requires explicit separate
   authorization and its own reviewed operational plan.

## F. Migration dependencies and safety gates

- No v7 or v8 work may start until the v6 numbering/schema conflict is
  reconciled for the intended workspace target.
- The proposed sequence is v7 followed by v8, but that sequence is not yet a
  confirmed technical dependency. Specify whether v8 requires v7 before
  implementation.
- Each migration must have its own script, version record, preflight checks,
  verified backup, atomicity strategy, post-migration integrity checks, and
  documented rollback/forward-recovery procedure.
- Never combine v7/v8 DDL, migration records, application release gates, or
  rollback instructions.
- Recheck migration records immediately before each authorized migration;
  stop if a version is missing, duplicated, unexpected, or inconsistent with
  the target schema.
- Validate only against disposable databases until the relevant implementation
  and operational work is separately authorized.
- No migration may be applied to Production under this review.

## G. Test and regression requirements

Future implementation must test at minimum:

- Migration preconditions, exact version sequencing, rerun behavior,
  transactional failure behavior, backup verification, integrity, and
  foreign-key consistency for each migration independently.
- v7 ownership transfer for both products on successful exchange; both product
  rows become inactive; product rows and transaction history are preserved;
  new owners create new listings rather than reactivating transferred rows.
- Reservation Design B and reservation release/consistency behavior.
- Four real handover confirmations and all completion guards; completion is
  atomic and is not conditioned on payment verification or a payment gateway.
- Existing disputed transactions remain frozen.
- Active Payment and Dispute write/UI paths are retired while historical
  payment/dispute records remain preserved and read-only.
- v8 participant eligibility, self-report prevention, one initial report per
  reporter/reported participant/transaction, category/status validation,
  length/rate limits, and auditable follow-up rather than duplicate reports.
- Decisions require a reason; appeals and independent review/reassignment
  follow the approved specification.
- Events/audit history cannot be silently edited or deleted through ordinary
  application access.
- Admin access is least-privilege, scoped, logged, and does not reveal unrelated
  private messages.
- Reports do not automatically punish or suspend users, establish payment,
  create reputation/credit scores, or trigger punishment based on report
  volume.
- Regression of existing offer, exchange, chat, payment-evidence, dispute, and
  handover behavior that remains in scope.

## H. Risks and rollback considerations

- **Version drift:** The local workspace database is recorded through v5 even
  though a v6 migration script exists. Applying v7 without reconciling this
  state risks skipping schema prerequisites or misrecording migration history.
- **Irreversible data effects:** Product ownership changes, report decisions,
  report events, and evidence references may have dependent writes. A schema
  rollback must not silently erase or rewrite those records.
- **Legacy record exposure:** Retained payment/dispute history and report
  evidence may contain sensitive information; read-only does not by itself
  establish appropriate authorization or privacy.
- **Audit integrity:** Update/delete access, broad admin visibility, or
  unlogged evidence access can undermine trust and legal defensibility.
- **Migration coupling:** Combining v7/v8 or using one rollback could broaden
  blast radius and make recovery ambiguous.
- **Backup restore effects:** Restoring a whole-database backup can discard
  writes made after that backup. Preserve the backup, stop writes as authorized,
  and use a separately reviewed restore or forward-fix procedure.

Do not roll back by ad hoc DDL or by deleting migration records. The rollback
strategy for each migration must be reviewed independently after its schema
and data effects are specified.

## I. Final readiness status

### Approved decisions preserved

- Successful exchange transfers ownership of both products.
- Both transferred product rows become inactive.
- Product rows and transaction history are preserved.
- New owners create new product listings rather than reactivating transferred
  rows.
- Reservation Design B remains approved.
- Four real handover confirmations are required before completion.
- Existing disputed transactions remain frozen.
- No payment verification or payment gateway is required for completion.
- Cash difference remains an agreed transaction term; users settle it
  externally.
- Legacy payment/dispute records are preserved and restricted to read-only
  access.
- The Report Policy in section C is approved.

### Final decision

**The corrected plan is not ready for separate implementation authorization.**
The local workspace database has migration records only through v5 while the
workspace contains a v6 migration script. Reconcile this state and verify the
intended target's exact numbering before confirming v7/v8 as deployable
migration numbers. The technical decisions in section D also require
specification and approval before implementation. This document does not grant
authorization to implement, migrate, deploy, operate servers, or access
Production.
