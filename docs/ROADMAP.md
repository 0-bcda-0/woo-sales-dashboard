# Woo Sales Dashboard — Future Development Protocol

Last updated: 2026-09-10

## Important: no approved future feature roadmap

There are currently **no user-approved future features** waiting to be implemented.

A future AI/developer must not treat ideas, examples, inferred improvements, or technically attractive additions as requested work. New product behavior must first be explicitly requested or approved by Jan.

The purpose of this document is only to explain **how to prepare and implement a future feature safely**.

## Before implementing any new feature

1. Read `docs/PROJECT-HANDOFF.md` completely.
2. Inspect the current source, tests, README/changelog, and latest release/version metadata.
3. Confirm the requested behavior with the user. Do not silently expand scope.
4. Write a short design/spec for material behavior changes, including user-visible behavior and edge cases.
5. Identify effects on commission classification, snapshots, cache schema/revision, REST/admin-ajax contracts, report payloads, email/print output, permissions, and performance.
6. Add a failing regression/feature test before implementation where practical.
7. Implement the smallest compatible change.
8. Run focused tests and the full verification suite.
9. Review the diff for performance regressions and source/ZIP drift.
10. Update handoff/changelog/docs only for behavior that actually exists.
11. Run `docs/RELEASE-CHECKLIST.md` before producing a release ZIP.

## Architectural constraints for future work

Preserve the existing single monthly WooCommerce aggregation pass. Do not introduce a second order scan for Commission or reporting. Use WooCommerce CRUD/query APIs for HPOS compatibility; do not add direct order-table SQL merely for convenience.

Keep normal operation admin-only and lightweight: no storefront assets, telemetry, cron/background reporting, external chart libraries, or remote runtime dependencies unless a future approved design explicitly changes those constraints.

Business formulas and classification rules belong server-side. JavaScript renders returned data and performs UI interactions; it must not become a second independent commission engine.

Preview, email, and print/Save-as-PDF must consume the same normalized report payload so their values cannot drift.

When a response shape stored in aggregate cache changes, bump the cache schema/version and add a **warm-cache upgrade regression test**. Do not rely only on TTL expiry. The V2.0.2 production incident demonstrated why this is required.

When adding fields used by frontend write actions, verify both cold-cache and pre-upgrade cached responses contain the identifiers required by the request contract.

## Classification changes

Current classification precedence and formulas are locked behavior unless the user explicitly changes them:

`manual Count as Standard override -> otherwise VIP -> otherwise Bundle -> otherwise Standard`

VIP is order-level. Bundle is line-item-level. Historical snapshot semantics must remain stable. Any future classification category requires an explicit precedence decision, snapshot/migration strategy, cache impact analysis, report/audit representation, and regression tests before implementation.

## API and report changes

All privileged actions must remain capability/nonce protected. If changing report actions, test both REST and the existing WordPress admin-ajax fallback behavior. Never expose report/customer data through unauthenticated routes.

Any report field added or changed must be verified in dashboard data, preview, HTML email, and print/PDF workflow from the shared payload.

## Definition of ready for release

A feature is not release-ready because it works in a development source tree. It is ready only when tests pass, version/cache migrations are handled, docs describe actual behavior, the final ZIP is built from the reviewed canonical source, ZIP contents are inspected, and production smoke testing succeeds.

Never use a ZIP as the only copy of a source change or hotfix.