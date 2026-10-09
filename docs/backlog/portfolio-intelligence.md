# Portfolio Intelligence Enhancement Backlog

Status: P0 source audit completed; implementation pending. Base: `develop`. Scope: enhance YAFFA's native investment analytics and financial overview.

## Principles
- Retain YAFFA's existing Laravel architecture, account, transaction, investment, investment-group and price-provider models.
- Audit implementation and tests before classifying any feature as missing.
- Evaluate third-party licensing and attribution before adopting any external implementation; prefer independently developed functionality.
- Use feature branches and PRs targeting develop; follow engineering standards, responsive/mobile accessibility, and production readiness.
- Preserve multi-currency precision, price provenance, and reconciliation with existing transactions.

## P0 — Discovery and specification

[Capability audit, data contracts, findings and UX layout specification](../../.ai/docs/features/portfolio-intelligence/SPECIFICATION.md). Audit snapshot: `743d5333397fbf74146c18d91a99cd45745f5445`, reviewed 9 October 2026. Completion below means source/test inspection and specification, not executed application tests or rendered UX validation.
- [x] Inventory existing investment UI, models, services, routes, reporting, price providers and tests; document verified coverage.
- [x] Build a YAFFA capability matrix: present, partial, missing, out of scope, with repository evidence links.
- [x] Document data contracts for holdings, investment transactions, cashflows, dividends, fees, transfers, corporate actions, splits, FX, historical prices and benchmarks.
- [x] Audit existing calculations for correctness: realised/unrealised gain, cost basis, currency conversion, income, fees, and missing-price behaviour.
- [x] Define UX wireframes and acceptance criteria for desktop and mobile, including accessible charts and tabular alternatives.
- [x] Document licensing and attribution decisions; prefer independently implemented functionality.

## P1 — Portfolio performance
- [ ] Historical portfolio valuation and return series with deterministic price/FX snapshots.
- [ ] Time-weighted return (TWR) and money-weighted return (XIRR), with explicit treatment of external cashflows and fees.
- [ ] Benchmark comparison with aligned dates, currencies and dividends where available.
- [ ] Income dashboard: dividends, coupons, interest, yield and projected income.
- [ ] Reconcile all metrics to transaction and investment records; add fixtures and regression tests.

## P2 — Allocation and risk
- [ ] Asset allocation by class, investment group, currency, geography and sector where metadata exists.
- [ ] Concentration, diversification and currency exposure metrics, with transparent assumptions.
- [ ] Drill-down from aggregate charts to holdings and underlying transactions.
- [ ] Handle unclassified assets without misleading allocation percentages.

## P3 — Unified wealth overview
- [ ] Consolidated net-worth history across cash, savings and investments without double counting transfers.
- [ ] Overlay existing recurring transactions, budgets and forecasts with separately labelled projected values.
- [ ] Responsive dashboards with accessible chart legends, filters, empty states and export.

## P4 — UK-specific extensions (separate design review)
- [ ] Evaluate ISA/SIPP account wrappers and allowance tracking.
- [ ] Evaluate UK CGT and dividend-income reporting, with tax-year settings and clear non-advice disclaimers.
- [ ] Assess bond coupon/accrual, maturity and amortisation requirements before implementation.

## Delivery and definition of done
- One bounded capability per PR; no direct commits to develop/main.
- Tests for cashflows, multi-currency conversions, missing data, and edge cases.
- Mobile-friendly, keyboard accessible, touch-scrollable, no overflow; no production console noise.
- API/service boundaries, migrations, and backfills documented before approval.
- Prioritise discovery and calculations before dashboards; defer tax automation until model verified.

## Audit PR
**Portfolio feature audit and delivery specification**: capability matrix, evidence links, calculation findings, data contracts and desktop/mobile layout requirements. Documentation only; no schema changes.

## Prioritised implementation tasks

GitHub Issues is disabled for this repository, so these entries are the implementation queue. Each task is one bounded feature PR targeting `develop`. Evidence IDs and finding IDs refer to the linked audit.

### PI-001 — P0: align as-of price selection

- [ ] Make frontend and backend select the newest qualifying stored or recorded trade price; transaction wins a same-date source tie (F1; E1/E2/E13).
- [ ] Cover older stored/newer trade, newer stored, same-date tie, stored-only, trade-only, missing price and known zero. No future prices.
- [ ] Define deterministic ordering for multiple trades on the same date and verify summary/single/batch parity.
- Scope: price resolver and relevant regression tests; preserve decimal arithmetic, no migration. First implementation task.

### PI-002 — P0: show unpriced holdings as unknown

- [ ] In holdings list and investment timeline, non-zero quantity with null price displays unknown/unpriced, not zero (F2; E15/E16).
- [ ] Handle known zero and zero positions distinctly; preserve known-value sorting and expose unknown chart labels/tooltips.
- [ ] Verify keyboard operation, narrow mobile layout and touch-scrollable tables; rebuild assets before UI verification.
- Scope: bounded presentation fix with relevant regression coverage. Monthly summary completeness is PI-004.

### PI-003 — P0: separate recorded returns from schedules

- [ ] Historical quantity, gain, ROI and default date bounds use recorded activity only (F3; E2/E4/E5).
- [ ] Due/overdue/future generated schedules stay excluded even within the selected range; recording an occurrence includes it once.
- [ ] Initial detail page and refreshed API data agree. Retain schedules in forecast/quantity views with explicit projection labels.
- [ ] Cover helper/component/API paths with focused regression tests. Restrict historical future selection or separately label a future calculation as projected.
- Scope: historical result inputs and tests; no migration.

### PI-004 — P1: strict portfolio valuation series

Depends on PI-001 and PI-002. First define the service/API contract and any snapshot migration/backfill for review (F2/F4/F5).

- [ ] Value cash plus securities once across an explicit owner-scoped account set; quantities keyed by account/investment, date-bounded and recorded-only.
- [ ] Include inactive/closed historical holdings, negative cash and future-dated-record edge cases.
- [ ] Use exact money/decimal arithmetic and decimal-string outputs. Only base-to-base FX defaults to 1.
- [ ] Expose price/FX dates and source references, known subtotal, nullable complete total, missing inputs and status. Unpriced positions or missing foreign FX prevent a complete total.
- [ ] Exclude future price/FX observations; preserve existing legacy report behaviour unless separately reviewed.
- [ ] Reconcile account contributions and internal transfers; preserve read-token ability enforcement and owner isolation.
- [ ] Test multi-currency/accounts, missing and future FX, missing prices, zero holdings and negative balances. Define source-row correction/snapshot reproducibility before calling values immutable.
- Scope: valuation foundation; TWR, XIRR and dashboards are subsequent bounded PRs.

### Following PI-004

- [ ] Define external flows relative to selected accounts, plus typed in-kind movements and corporate actions before returns (F7).
- [ ] Specify and implement TWR and XIRR separately, with cashflow timing, validity and convergence rules; do not sum position ROIs.
- [ ] Implement income/allocation, then benchmark comparison and unified wealth views using reconciled valuation.
- [ ] Review UK wrappers, tax/cost-basis and advanced bond requirements separately.
