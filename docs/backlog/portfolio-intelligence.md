# Portfolio Intelligence Enhancement Backlog

Status: discovery and planning. Base: `develop`. Reference applications: Ghostfolio and Wealthfolio.

## Principles
- Retain YAFFA's existing Laravel architecture, account, transaction, investment, investment-group and price-provider models.
- Audit implementation and tests before classifying any feature as missing.
- Avoid copying AGPL-licensed Ghostfolio implementation into MIT-licensed YAFFA without a deliberate licensing decision.
- Use feature branches and PRs targeting develop; follow engineering standards, responsive/mobile accessibility, and production readiness.
- Preserve multi-currency precision, price provenance, and reconciliation with existing transactions.

## P0 — Discovery and specification
- [ ] Inventory existing investment UI, models, services, routes, reporting, price providers and tests; document verified coverage.
- [ ] Build Ghostfolio/Wealthfolio/Yaffa capability matrix: present, partial, missing, out of scope, with evidence links.
- [ ] Document data contracts for holdings, investment transactions, cashflows, dividends, fees, transfers, corporate actions, splits, FX, historical prices and benchmarks.
- [ ] Audit existing calculations for correctness: realised/unrealised gain, cost basis, currency conversion, income, fees, and missing-price behaviour.
- [ ] Define UX wireframes and acceptance criteria for desktop and mobile, including accessible charts and tabular alternatives.
- [ ] Document licensing and attribution decisions; prefer independently implemented functionality.

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

## First suggested PR
**Portfolio feature audit and comparison matrix**: produce a repository-grounded report and prioritised implementation issues. No schema changes in this PR.
