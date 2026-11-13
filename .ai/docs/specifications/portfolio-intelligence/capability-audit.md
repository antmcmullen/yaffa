# Portfolio Intelligence: capability audit and delivery specification

## Feature Summary

YAFFA already records investment activity, holdings, prices, investment groups, cash balances, budgets and forecasts. Portfolio Intelligence extends these foundations with reconciled portfolio valuation, clearly defined returns, allocation and consolidated wealth reporting.

This audit describes the code at commit `743d5333397fbf74146c18d91a99cd45745f5445` on `feature/portfolio-intelligence-backlog`. Evidence was reviewed on 9 October 2026. Findings are from source and test inspection; they are not claims that the application or test suites were run.

## Goals / Non-Goals

- Goals: preserve current workflows; identify reusable capabilities; resolve valuation ambiguity before portfolio metrics; provide bounded implementation tasks with evidence and acceptance criteria.
- Non-goals for this PR: application changes, schema changes, new dependencies, tax calculations, external code adoption, deployment or merging to a protected branch.
- Product boundary: the current product context describes self-hosted personal finance and explicitly excludes strict accounting software and automatic bank synchronisation (E19). Do not assume a double-entry ledger or organisation treasury model.

## Assumptions

- A portfolio is an explicit set of the user's accounts. Its valuation includes cash balances plus securities held in those accounts. User-defined investment groups remain classification rather than account ownership.
- A security may exist in several accounts; holdings must be keyed by account and investment before aggregation.
- Recorded transactions and projections are separate datasets. Inactive investments with recorded holdings remain relevant to historical valuations.
- New analytics use existing Money/BigDecimal and decimal-string conventions. Existing summary/display fields sometimes collapse to floats (E1, E15); do not reuse these as exact calculation inputs.
- Current manifests specify PHP ^8.4, Laravel ^13.34, Vue 3, Vite and decimal.js (E20, E21). Some contributor instructions still describe Laravel 12 and Mix; confirm installed versions in Sail before implementation.
- `.ai/rules` is absent at the audited commit. AGENTS.md, CLAUDE.md and existing domain documentation were reviewed.

## Verified capability matrix

“Present” means code and relevant coverage were located, not runtime certification. “Partial” identifies useful foundations with gaps. “Missing” means no dedicated implementation was located in the scoped source search, not proof that no differently named implementation can exist.

| Capability | Status | Verified behaviour and boundary | Evidence |
| --- | --- | --- | --- |
| Investment activity | Present | Buy/sell, add/remove shares, dividends, interest yield, purchased interest, product fees and tax relief are explicit types. | E6, E7, E17, E23 |
| Current holdings | Present | Quantity across accounts or an account filter; summary excludes schedules and scopes to owner. Current lookup has no as-of date bound. | E1, E13 |
| Investment groups and identifiers | Present | Investment has group, currency, symbol and optional ISIN; reuse existing entities. | E1, E12 |
| Price history and providers | Present | Stored prices and recorded transaction prices; provider selection, fetch state and batch lookup exist. | E1, E12, E14 |
| Single-investment result and ROI | Partial | Economic gain and purchase-weighted ROI; annualisation in the UI. This is not portfolio TWR or XIRR. | E2, E3, E4 |
| Historical investment values | Partial | Exact monthly account-level quantities times as-of prices. Unpriced non-zero positions are skipped. | E8, E9, E24 |
| Cashflow and forecasting | Present | Monthly fact/forecast/budget data and base-currency reports exist. Projections should be reused and labelled. | E9, E10 |
| Strict historical FX | Partial | Daily FX rows exist; report helper uses monthly averages and may fall back to a later rate or 1:1 with warning. | E11, E12, E10 |
| Portfolio valuation series | Partial | Monthly account summaries are useful, but no strict portfolio series with completeness and row-level price/FX provenance was located. | E8, E9, E10, E12 |
| Realised/unrealised gain and cost basis | Missing | Result combines gain and income; no lot or disposal matching engine located. A result total is not a cost-basis report. | E2, E6, E12 |
| TWR and XIRR | Missing | No dedicated implementations or tests located; must define portfolio boundary and external flows first. | E2, E3, E7 |
| Benchmark comparison | Missing | No benchmark series, definition or comparison endpoint located. | E5, E10, E12 |
| Income dashboard | Partial | Income and charges recorded; no dedicated portfolio income/yield dashboard or validated projection engine located. | E6, E7, E4 |
| Allocation and risk | Partial | Group and currency available; no dedicated allocation percentages, concentration engine, sector/geography model located. | E1, E12 |
| Unified net-worth history | Partial | Monthly cash and investment fact values exist; no dedicated reconciled wealth view with strict completeness located. | E8, E9, E10 |
| ISA/SIPP and tax reporting | Missing | Tax relief transactions exist, but no wrapper/allowance or tax-lot model located. | E7, E12 |
| Bond features | Partial | Interest yield, purchased interest and scheduled sales exist; no coupon schedule, accrual, amortisation or maturity metadata engine located. | E7, E12 |
| Corporate actions and splits | Partial | Share adjustments exist; no typed split ratio, matched security transfer or historical adjustment policy located. | E7, E12 |
| Strict accounting/bank sync | Out of scope | Explicitly excluded by current product context. | E19 |

Search scope: app models/services/controllers/requests/jobs, routes, resources/js investment/report components, schema dump and subsequent migrations, investment/currency/summary tests, and existing product/domain documentation. Search terms included TWR, XIRR, benchmark, allocation, realised/realized, cost basis, ISA, SIPP, coupon, maturity and net worth. Existing tests were inspected for relevant assertions.

## Backend Scope (Laravel)

Reuse InvestmentService price resolution, transaction-type multipliers, transaction cashflow calculation, account associations, ownership policies and fact/forecast jobs. Thin API controllers should call bounded services; new APIs require existing read-ability enforcement and owner isolation.

No migrations or backfills are proposed by this audit. Provenance snapshots, typed corporate actions and wrapper metadata need a later schema design and migration/backfill review. Existing monthly summary rows are mutable aggregates, not immutable pricing snapshots.

### Calculation findings

| ID | Finding | Concrete effect | Required decision / regression |
| --- | --- | --- | --- |
| F1 | Frontend `priceAsOf` selects any qualifying stored history before checking transactions; backend combined resolution chooses whichever source is newer, transaction winning a same-date tie (E1, E2, E13). | Stored price 10 on 1 January and trade price 12 on 10 January produce different as-of prices on 11 January. | Unify selection and same-date tie rules; preserve no future-price lookup. |
| F2 | Holdings list and timeline multiply nullable prices as JavaScript numbers (E15, E16); monthly investment summation skips unresolved non-zero positions (E8). | A missing price can render a zero value or leave an apparently complete subtotal. | Distinguish known zero from unknown; expose incomplete valuation and missing holdings. |
| F3 | Detail API and page append generated schedule instances to transactions; ResultsCard and return helper apply date filters without excluding schedules (E5, E4, E2). | Selecting a future end date, or receiving a due schedule in the range, can include unrecorded activity in ROI. | Historical results accept recorded activity only; projections stay separately labelled. |
| F4 | Current quantity and latest-price lookups have no “today” upper bound (E1). | Future-dated recorded transactions may affect “current” positions or prices. | Introduce explicit as-of semantics for new analytics; preserve or deliberately revise legacy behaviour with tests. |
| F5 | Monthly FX averages may include observations after an early-month valuation; pre-history lookup falls back to the oldest later month; missing currency can aggregate at 1:1 with warnings (E11, E10). | Existing report amounts cannot be treated as strict historical portfolio snapshots. | New series uses only FX dated on/before valuation and reports unresolved FX; retain legacy report policy separately until reviewed. |
| F6 | Existing gain is closing minus opening value plus signed investment cashflow; ROI denominator is opening capital plus time-weighted purchase costs (E2). | Sales and income affect numerator, purchases affect denominator; no separate disposal cost or tax lot basis. | Retain a named position-result method; implement TWR/XIRR as separately defined methods, never sum security ROIs. |
| F7 | `add_shares` increases economic result at unchanged price; existing test treats bonus shares as gain (E3). | An in-kind transfer represented this way would look like a gain; a split requires coordinated price adjustment. | Preserve bonus-share semantics; classify transfers and corporate actions before using adjustments in portfolio returns. |

Backend cashflow formula (E6, E7): signed price × quantity plus signed dividend, commission and tax. Fees on share adjustments are included; legacy currency mismatch returns null and logs a warning. New investment transactions require account and security currencies to match (E18), so the immediate multi-currency problem is aggregation between accounts, not silent FX inside a trade.

### Data & API Design

The following separates current storage from proposed analytics contracts.

| Domain | Current contract | Analytics contract / gap |
| --- | --- | --- |
| Holding | Derived from account_id, investment_id and signed quantity; stored detail quantity decimal(14,4). | account_id, investment_id, as_of, quantity as decimal string; recorded only, date bounded; include closed/inactive history. |
| Trade | Transaction owns date/type/schedule and polymorphic investment detail; price scale 10. | Preserve transaction IDs, signs and currencies; do not calculate through float summary responses. |
| Cashflow | Money derived by TransactionService; transfers have two account legs and no scalar cashflow. | Classify each leg relative to selected accounts: internal if both inside, external if one inside. Security trades are internal to a cash-plus-securities portfolio. |
| Income and charges | dividend/commission/tax fields with account currency and enum multipliers; detail casts use scale 4. | Separate gross income, withholding/tax relief, fees and net income; preserve corrections and signed values. |
| Security movement | add_shares/remove_shares change quantity; optional commission/tax affect cash. | Distinguish bonus shares, split, in-kind transfer and corrections; do not infer from sign alone. |
| Prices | One investment/date row, price decimal(20,10); trade price fallback; mutable fetch/refill. | Return chosen date, source kind and source row ID; staleness policy and completeness. Persist reproducible snapshots only after schema review. |
| FX | Pair/date, rate decimal(20,10); existing report averages. | Decimal rate, pair, observation date, source reference; as-of only; base-to-base is 1; foreign missing rate is unknown. |
| Valuation | Monthly account summary amount decimal(14,4), type and fact/forecast labels. | Explicit account set, currency/date, cash value, security value, known subtotal, nullable total, status, missing inputs and reconciliation contributions. |
| Benchmark | No dedicated model. | Later contract: identifier, currency, price/total-return convention, dates, availability and permitted data use. |
| Return | Client-side position gain and purchase-weighted ROI. | Named method and version, account boundary, observation/cashflow timing, validity state and reasons; undefined metrics are nullable. |

Proposed service output for a valuation point (not an implemented endpoint):

```json
{
  "date": "2026-01-11",
  "currency": "GBP",
  "known_value": "1200.0000",
  "total_value": null,
  "status": "incomplete",
  "missing_inputs": [
    {"account_id": 2, "investment_id": 9, "kind": "price"}
  ]
}
```

IDs are scoped to the authenticated owner. A successful empty portfolio can be zero; a non-zero holding with no price cannot be zero. There is no authorised new route or migration in this PR.

## Frontend Scope (Vue + Bootstrap)

### UX wireframe specification

| Surface | Desktop arrangement | Mobile arrangement | States / accessible alternative |
| --- | --- | --- | --- |
| Overview | Account scope and date/currency filters; valuation, change and completeness cards; value chart; holdings table. | One-column filters/cards; chart above holdings; controlled horizontal scrolling inside the table. | Text summary, labelled chart legend and same-data table; separate loading, empty, incomplete and error states. |
| Performance | Method selector and date range; return chart; external cashflow table; methodology details. | Stacked controls, chart, metrics and cashflows. | Show method, period and validity; undefined return has a reason, not 0%. |
| Allocation | Group/currency selector; chart beside holdings breakdown. | Chart followed by labelled allocation table and drill-down links. | Unclassified assets explicit; incomplete valuation prevents apparently complete percentages. |
| Income | Gross/net income and fees; history table; separately labelled future schedule. | Stacked totals and touch-scrollable table. | Recorded and projected totals never mixed; corrections visible. |
| Wealth | Cash plus securities fact history; forecast toggle and separate projection series. | Filters, total/completeness, chart then data table. | Explain transfer treatment and prevent double counting; include negative balances. |

These are layout contracts, not rendered UI or browser validation. Existing Vue islands/Blade navigation remain. Filters must preserve account scope across tabs; no portfolio-wide totals by adding values in different currencies without conversion.

## Test Strategy

- Existing relevant coverage: E3/E13/E14/E23/E24 and price provider tests. Presence was reviewed; no test execution is claimed.
- No application code changed, so PHP, JS, browser and database suites are not needed for this documentation PR. Sail/vendor and Docker are unavailable in this checkout.
- Each behaviour PR must run the narrow affected tests through Sail, use installed package versions, and include meaningful negative cases.
- Valuation fixtures: older stored/newer trade, same-date tie, future prices, unpriced non-zero holdings, zero holdings, closed holdings, negative cash, future-dated records, owner isolation and multiple-account filtering.
- Cashflow fixtures: buy/sell/income/fees/tax relief; internal and boundary-crossing transfers; matched in-kind movement; recorded versus scheduled transactions; legacy currency mismatch.
- FX fixtures: same currency, exact date, prior date, weekend carry, no history, only future history, multi-currency conversion and rounding. No average that consumes future observations.
- Return fixtures: contribution-only zero return, flat market with fees, complete sale, in-kind transfer, split, short reporting period, no external flows, no XIRR root, multiple possible roots and missing valuation boundaries.
- UI: keyboard operation, labels, accessible tables/legends, 320px and 375px widths, desktop, zoom, dark mode, no page overflow, touch scroll and no production console noise.

## Risks / Open Questions

- How should dated recorded transactions after today be presented? New analytics will use explicit as-of dates; any legacy change needs a separate regression review.
- What price staleness threshold should apply by asset/provider? Expose observation dates first; do not invent market calendars.
- Are share adjustments currently used as transfers or splits in real data? Source code cannot answer; avoid destructive reclassification/backfill.
- Snapshot persistence and benchmark data rights require later design. No third-party code, new market-data integration or dependency is adopted here.
- XIRR root selection/convergence and TWR cashflow ordering need explicit specifications before implementation. UK tax and wrapper work stays a separate design review.

## Acceptance Criteria

- Given this source snapshot, when a capability is marked present/partial/missing, then its basis and scope are stated and traceable to evidence.
- Given existing return calculations, when portfolio work is planned, then security ROI is distinguished from TWR/XIRR and cost basis.
- Given missing prices or FX, when new valuation contracts are implemented, then unresolved positions are visible and a complete total is withheld.
- Given schedules, future data or internal transfers, when historical returns are calculated, then they cannot create recorded gain or external contributions.
- Given the first delivery tasks, when implemented, then each stays bounded, includes regression tests, and targets develop without merging this PR automatically.

## Delivery order

1. F1 price-selection parity.
2. F2 missing-price presentation (bounded UI fix; does not redesign stored summaries).
3. F3 recorded-only historical results.
4. Strict portfolio valuation series design and service (F2/F4/F5; date, FX, provenance, completeness and account scope).
5. External cashflow and corporate-action contracts (F7), then separately scoped TWR and XIRR PRs.
6. Income and allocation views; benchmark comparison once aligned data contracts exist.
7. Unified wealth dashboard reusing reconciled fact/forecast foundations.
8. UK wrappers, cost-basis/tax and advanced bond work following separate review.

### Licensing and attribution decision

Repository metadata and LICENSE identify MIT (E20, E22). This PR adopts no external implementation and introduces no dependency or attribution change. Independently develop future analytics against the agreed contracts; review any later external code or data-provider terms before adoption.

## Evidence index

- **E1**: [Holdings and price lookup](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Services/InvestmentService.php).
- **E2**: [Single-investment return arithmetic](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/resources/js/investments/lib/investmentReturn.js).
- **E3**: [Return regression examples](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/resources/js/investments/lib/investmentReturn.test.js).
- **E4**: [Results and annualisation UI](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/resources/js/investments/components/display/ResultsCard.vue).
- **E5**: [Investment API and scheduled instances](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Http/Controllers/API/InvestmentApiController.php).
- **E6**: [Recorded investment cashflow](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Services/TransactionService.php).
- **E7**: [Transaction types and signs](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Enums/TransactionType.php).
- **E8**: [Monthly investment value](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Models/AccountMonthlySummary.php).
- **E9**: [Monthly fact and forecast jobs](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Jobs/CalculateAccountMonthlySummary.php).
- **E10**: [Report aggregation and FX warnings](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Http/Controllers/API/ReportApiController.php).
- **E11**: [Monthly FX resolution](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Http/Traits/CurrencyTrait.php).
- **E12**: [Database fields and precision](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/database/schema/mysql-schema.sql).
- **E13**: [Holdings API regression coverage](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/tests/Feature/InvestmentSummaryTest.php).
- **E14**: [Price service regression coverage](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/tests/Unit/Services/InvestmentServicePriceTest.php).
- **E15**: [Holdings list value rendering](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/resources/js/investments/index.js).
- **E16**: [Timeline value rendering](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/resources/js/reports/investment-timeline.js).
- **E17**: [Investment detail money casts](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Models/TransactionDetailInvestment.php).
- **E18**: [Account/investment currency validation](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/app/Http/Requests/TransactionRequest.php).
- **E19**: [Product intent](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/.ai/docs/product-context.md).
- **E20**: [Runtime constraints](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/composer.json).
- **E21**: [Frontend dependencies and build](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/package.json).
- **E22**: [Project licence](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/LICENSE).
- **E23**: [Backend cashflow regression coverage](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/tests/Unit/Services/TransactionServiceTest.php).
- **E24**: [Summary regression coverage](https://github.com/antmcmullen/yaffa/blob/743d5333397fbf74146c18d91a99cd45745f5445/tests/Unit/Models/AccountMonthlySummaryTest.php).
