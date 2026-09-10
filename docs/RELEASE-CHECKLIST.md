# Woo Sales Dashboard — Release Checklist

Last updated: 2026-09-10

This checklist is mandatory before producing or publishing a final installable ZIP. Do not mark a release complete based only on unit tests or a successful archive command.

## 1. Establish release source of truth

- Work from a committed Git branch/commit, never from an untracked ZIP-only hotfix.
- Confirm all intended production fixes exist as readable source files in GitHub.
- Confirm plugin header version and `WSD_VERSION` match the intended release.
- Confirm `readme.txt` Stable tag and changelog match the release.
- Confirm README/handoff documents do not claim features that are absent from source.
- Review diff from the last released version and identify changes to business rules, REST/report contracts, cache payload shape, snapshots/options/meta, permissions, and frontend assets.

## 2. Cache and upgrade safety

- If aggregate payload shape/meaning changed, explicitly invalidate old cache through a schema/prefix revision or compatible migration.
- Test a cold-cache load.
- Test a warm-cache upgrade using a payload produced by the previous release; the new release must not fatal, misclassify, or silently display structurally stale data.
- Verify Bundle `classification_revision` is stored inside the single monthly cache payload, not embedded into proliferating transient keys.
- Verify Marketing/Other Costs/report-email changes do not trigger full order re-aggregation.

## 3. Automated verification

Run every repository test, not only the test touched by the last fix. Required coverage includes commission formulas, VIP priority, Bundle classification, snapshots/fallback, refunds, shipping exclusion, costs/net earnings, cache revision behavior, dashboard aggregation, report payload/rendering, and settings.

Run PHP syntax validation recursively over every shipped PHP file and JavaScript syntax validation over shipped JS. Any failure blocks release.

Search/review source for forbidden regressions: direct `wp_posts`/`wp_postmeta` analytics, external/CDN/telemetry dependencies, unintended storefront assets, background polling/cron reporting, duplicated commission formulas in JavaScript, and unsafe output/injection patterns.

## 4. WordPress/WooCommerce integration smoke test

On a representative WordPress + WooCommerce installation, with the intended release candidate installed:

- Activate without PHP fatal/warning and verify graceful WooCommerce dependency behavior.
- Open Sales Dashboard as a user with `view_woocommerce_reports`; verify an unauthorized user cannot use protected actions.
- Load current and historical months; verify future month rejection.
- Verify Sales KPIs, Top Products, selected/previous SVG series, tooltip and Refresh.
- Verify Commission totals and daily series against at least one known order sample.
- Verify mixed Standard + Bundle order and VIP order containing a Bundle SKU are not double-counted.
- Verify Marketing/Other Costs save and only change Net Earnings.
- Add/edit/remove a Bundle SKU and verify historical snapshots stay stable.
- Preview report, send test email, send report to a controlled address, and verify send audit.
- Verify print/Save-as-PDF workflow on the actual target environment. Exercise the exact report route/fallback used in production; a route returning 404 blocks release.
- Test mobile widths around 320, 375 and 430 px plus desktop. No KPI/card text clipping, horizontal overflow, inaccessible controls, or broken modal/report layout.

## 5. Regression checks from V2 production incidents

These checks are permanent even after the original bug is fixed:

- Report preview/print path must not 404 on the target WordPress environment; verify any REST/admin-ajax fallback end-to-end.
- KPI labels/values must not clip at narrow mobile widths.
- Cached aggregate data must preserve required order identifiers/data shape after upgrades. Verify both fresh cache and stale previous-release cache behavior.

## 6. Performance checks

- One month dashboard load must not create request-per-card behavior.
- Commission must reuse the monthly order/line traversal rather than performing a second full scan.
- CSS/JS enqueue only on Sales Dashboard admin page.
- No storefront page should execute dashboard aggregation or enqueue dashboard assets.
- No automatic report generation/email and no polling.
- Confirm current-month TTL remains short and historical cache remains targeted/long-lived.

## 7. Build the ZIP only after verification

Build from the verified committed source. The archive must contain exactly one top-level `woo-sales-dashboard/` directory. Exclude tests, docs, VCS metadata, previous dist files, temporary/debug files, editor files, and build junk. Include all required runtime PHP/CSS/JS/readme files.

Use a versioned artifact such as `dist/woo-sales-dashboard-vX.Y.Z.zip`. If maintaining `dist/woo-sales-dashboard.zip` as a convenience alias, it must be byte/content-equivalent to the same release source.

## 8. Inspect the artifact, do not trust the build command

- List ZIP contents and confirm one plugin root.
- Extract ZIP to a clean temporary directory.
- Re-run PHP/JS syntax checks on the extracted runtime files.
- Read the extracted plugin header and verify version.
- Compare extracted runtime files against the verified Git source; no unexplained differences are allowed.
- Confirm no tests/docs/secrets/logs/temp files slipped into the archive.
- Record a checksum for the final versioned ZIP when practical.

## 9. GitHub/release parity gate

Before handing the ZIP to the user, confirm the GitHub commit contains the exact source used to build it. Commit documentation/changelog/version changes before or together with the release artifact. Never publish a ZIP whose fixes are absent from source control.

If `main` is the production branch, merge/review the feature/release branch first and verify the resulting main commit before calling it final. If a release is intentionally built from another branch, document the exact branch and commit SHA.

## 10. Post-install production smoke test

After installing the final ZIP on the real site, repeat a short smoke test: plugin activates; Sales loads; Commission loads; known month totals are plausible; costs save; Settings opens; report preview works; test email works; print/PDF works; mobile layout is intact. Clear/rebuild only plugin-owned caches when required by the release design, not WooCommerce data.

If production reveals a bug, fix source first (or immediately reconcile the exact hotfix back into source), add a regression test, bump patch version, rerun this entire checklist, and build a new ZIP. Do not overwrite history and call a changed archive the same verified release.