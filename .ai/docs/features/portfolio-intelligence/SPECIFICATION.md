# Portfolio Valuation and Time-Weighted Return

As-built documentation extracted from the code on `feat/portfolio-valuation`. The pre-implementation capability audit and delivery plan this work follows is [`../../specifications/portfolio-intelligence/capability-audit.md`](../../specifications/portfolio-intelligence/capability-audit.md). Supporting files: [architecture.md](architecture.md), [permissions.md](permissions.md), [tests.md](tests.md). The cashflow and TWR classification rules are also stated in [`../../assets/transactions/transaction-types.md`](../../assets/transactions/transaction-types.md) ("Portfolio Analytics Classification Contract").

### Feature Name

Portfolio valuation

### Feature Summary

A report that values a chosen set of accounts — cash plus the securities held in them — at each month end over the last year, in the user's base currency, together with a time-weighted return for each month and cumulatively. Every value shows which price and exchange rate it used, and any month that cannot be valued or returned reliably is reported as incomplete with the reasons, instead of showing a misleading total or 0%.

### Target User

- Primary: an intermediate-to-advanced self-hosting user who tracks several investment and cash accounts (possibly in more than one currency) and reviews performance periodically (monthly/quarterly), and who wants to know how the whole portfolio performed independently of when money was added or withdrawn.
- Secondary: the same user debugging their data — the missing-input list tells them which price, exchange rate or transaction is preventing a complete valuation.

### User Problem

- Existing investment views value one security at a time; there was no figure for "what were these accounts worth together on a given date".
- Adding money to a portfolio makes its value rise without any investment gain; a plain before/after comparison overstates performance.
- Missing prices or exchange rates previously disappeared silently (a missing price could look like a zero value), so a total could look complete when it was not.
- Existing reports use monthly-average exchange rates that can include rates from after the valuation date.

### User Value / Benefit

#### Functional Benefits

- One page answers "what was my portfolio worth at each month end in the past year" for any selection of accounts.
- Monthly and cumulative time-weighted return strip out the effect of contributions and withdrawals, so performance is comparable across periods with different deposit patterns.
- A missing price, exchange rate or unclassifiable share movement is named (account, investment, kind), so the user knows exactly what to record to complete the figures.
- Investment pages now show a zero-quantity holding as worth 0 even without a price, and an unpriced non-zero holding as "Not set" instead of 0.
- Investment results ignore scheduled (projected) transactions and cannot end in the future; projected rows in the investment's transaction history carry a "Projected" badge.

#### Conceptual Benefits

- Separates "known so far" (the known subtotal) from "the total" — a total is only claimed when every input is known.
- Gives the user a portfolio-level view of performance (how the money was invested) distinct from single-security results (ROI of one holding).
- Makes the evidence behind each number inspectable (which stored price or trade, which dated rate), supporting trust in long-term planning figures.

### Technical Description

- The report page requests valuation for 12 month-end dates ending today, for the accounts the user ticks, in the user's base currency.
- For each date, the valuation service replays recorded (non-scheduled) transactions up to that date: opening balance plus standard transactions give each account's cash; investment transactions give cash movements and the quantity held per account and security.
- Each non-zero holding is priced with the latest price on or before the date (stored price history or a recorded trade price, the newer one winning, a trade winning a same-day tie, the later record winning among same-day trades).
- Every amount is converted to the output currency using only exchange rates dated on or before the valuation date (direct, inverse, or via the base currency).
- The cashflow service classifies external contributions and withdrawals relative to the selected accounts; the TWR service uses those flows and the valuations to produce linked monthly returns.
- All amounts and returns are exact decimal strings; nothing is persisted.

### Inputs

- Selected accounts (explicit list; empty list allowed and means an empty portfolio).
- Output currency (the user's base currency on the report page; any owned currency via the API).
- Valuation dates (12 generated month ends on the page; 1–31 explicit dates via the API).
- Existing data: account opening balances, recorded standard and investment transactions, stored investment prices, recorded trade prices, currency rates.

### Outputs

- Per date: `known_value`, `total_value` (null unless complete), `status` (`complete`/`incomplete`), `missing_inputs`, and per-account cash and per-security components with price source and FX evidence.
- Per date: time-weighted return — `period_return`, `cumulative_return` (fractions, e.g. `0.05` = 5%), `status` (`baseline`/`complete`/`incomplete`), and its own missing inputs.
- Report page: latest total/subtotal cards, a value chart, a table of every point, and an incomplete-data warning. No database writes.

### Domain Concepts Used

- Account, Investment, Investment price, Currency / Currency rate, Transaction (standard and investment), Transaction type — as defined in `.ai/docs/assets/`.

### Domain Concepts

- "Portfolio": an explicit set of the user's accounts; its value is the cash in those accounts plus the securities held in them. Investment groups are classification only and do not define a portfolio.
- "Known subtotal": the sum of every component that could be valued. "Total value": the known subtotal, claimed only when nothing is missing.
- "Missing input": a specific reason a component could not be valued — `price`, `fx`, or `cashflow` (an investment transaction whose cash effect could not be determined).
- "External cashflow": money entering (contribution) or leaving (withdrawal) the selected accounts — deposits, withdrawals, and the selected leg of a transfer to or from an account outside the selection. Transfers between two selected accounts and all buys, sells, income and fees are internal.
- "Unclassified share adjustment": an add-shares or remove-shares transaction. The data does not say whether it was a bonus, split, in-kind transfer or correction, so it is reported, never guessed.
- "Time-weighted return (TWR)": return with the effect of external cashflows removed, sub-periods linked geometrically.

### Core Logic / Rules

- Only recorded, dated, non-scheduled transactions on or before the valuation date are counted; scheduled instances never affect values or returns.
- All accounts, currencies and investments touched must belong to the authenticated user; anything else is a not-found error.
- A zero holding is worth zero even with no price. A non-zero holding without a price, or any component without a usable exchange rate, makes the point incomplete.
- A zero or negative exchange rate is treated as unavailable, not as a rate.
- External cashflows are treated as end-of-day events: on a flow date the portfolio is valued after that day's activity and the day's net external flow is removed before computing that sub-period's return.
- A return period is unavailable (null, `incomplete`, with reasons) when: either boundary valuation is incomplete; a cashflow has no exchange rate on its date; an unclassified share adjustment falls inside the period; the opening value is not positive; or the value before a flow is negative. Once a period is unavailable, later cumulative returns are also unavailable.
- The first requested date is the `baseline` (cumulative return 0, no period return).
- Amounts are rounded to 4 decimal places for output; returns to 10.

### User Flow

1. Open Reports → Portfolio valuation.
2. All active accounts are ticked by default; untick accounts (or use Select all / Select none) to change the portfolio. The page reloads the valuation on each change.
3. Read the latest total (or known subtotal if incomplete), the 12-month value chart and the table of monthly values, period TWR and cumulative TWR.
4. If the warning appears, check the "Missing inputs" column to see which price, exchange rate or transaction to fix.

### Edge Cases / Constraints

- No base currency set: the page shows an error and does not request data.
- No accounts selected: a complete valuation of zero.
- Holdings in a security priced in a different currency than its account are not possible for new transactions (validation requires matching currencies); legacy mismatches surface as a `cashflow` missing input.
- The page always uses 12 month ends and the base currency; there is no date-range or currency selector in the UI.
- Date-only data: intraday ordering of flows is not modelled.

### Dependencies

- Models: AccountEntity, Account, Investment, InvestmentPrice, Transaction, TransactionDetailStandard, TransactionDetailInvestment, Currency, CurrencyRate, User.
- Services: PortfolioValuationService, PortfolioCashflowService, PortfolioTimeWeightedReturnService, InvestmentService (price evidence), TransactionService (investment cashflow).
- External systems: none (uses already-stored prices and rates).

### Frontend Interaction

- `resources/views/reports/portfolio-valuation.blade.php` + `resources/js/reports/portfolio-valuation.js` (account picker, cards, amCharts line chart, table) and `portfolioValuationData.mjs` (date and query building).
- Investment page changes: `InvestmentDetailsCard.vue` (owned value via `getInvestmentValue`), `ResultsCard.vue` (end date capped at today), `TransactionHistoryCard.vue` ("Projected" badge), holdings list and timeline (unpriced shown as "Not set").

### Completeness

- Partial. Delivery steps 1–4 of the capability audit are implemented, and step 5 is implemented for TWR only. Not implemented: XIRR, typed corporate actions, income/allocation views, benchmarks, unified wealth view, cost basis/tax/wrappers. Reasoning: the audit's delivery order lists these as later steps; no code for them exists.

### Confidence Level

- High for the backend rules (read from code and covered by tests); Medium for the report page behaviour (read from code, not yet verified in a browser at mobile widths).

### Assumptions

- The report page's 12-month window and base-currency output are product choices for a first version, not technical limits (the API accepts other currencies and up to 31 dates).
- Documentation referred to: the capability audit and transaction-types contract. They align with the code, except that the audit describes quantity as `decimal(14,4)`; the code (via the precision work) now stores `decimal(20,10)`.
