# Portfolio valuation — architecture

See [SPECIFICATION.md](SPECIFICATION.md) for the feature description.

## Components

| Layer | Component | Responsibility |
| --- | --- | --- |
| Route (web) | `GET /reports/portfolio-valuation` → `reports.portfolio-valuation` (`Route::view`, `auth` + `verified`) | Renders the report page; breadcrumb Home › Reports › Portfolio valuation; linked from the Reports menu. |
| Route (API) | `GET /api/v1/reports/portfolio-valuation` → `api.v1.reports.portfolio-valuation` | Valuation + TWR series. |
| Controller | `API\PortfolioValuationApiController::series()` | Thin: validates, loads the owned currency, calls `PortfolioTimeWeightedReturnService::valuationsWithReturns()` once, attaches each date's TWR result to its valuation point. Throws `LogicException` if a point has no return result (internal invariant). |
| Request | `API\PortfolioValuationRequest` | `account_ids`: present, list of distinct integers (may be empty). `currency_id`: required, owned by the user. `dates`: required list of 1–31 distinct `Y-m-d` dates. |
| Service | `PortfolioValuationService` | `series(user, accountIds, currency, dates)` — one valuation per date. `convertCashflowAmount()` — FX conversion used by TWR. |
| Service | `PortfolioCashflowService` | `externalCashflows()` and `unclassifiedShareAdjustments()` for the selected accounts and a date window. |
| Service | `PortfolioTimeWeightedReturnService` | `series()` — linked TWR using valuations, flows and adjustments. |
| Service (shared) | `InvestmentService::getLatestPricesEvidenceBatch()` | Batch price lookup returning price, source kind (`stored`/`transaction`), source id and date. Same-day rules match `getLatestPrice()`/`getLatestPricesBatch()`, now ordered by date then id. |
| Frontend | `reports/portfolio-valuation.js`, `reports/portfolioValuationData.mjs`, `views/reports/portfolio-valuation.blade.php` | Page island: loads `/api/v1/accounts`, builds 12 month-end dates, requests the series, renders cards/chart/table. |
| Frontend (shared) | `investments/lib/valuation.js` (`getInvestmentValue`), `investments/lib/investmentReturn.js` (`priceAsOf`, `getRecordedTransactions`, `computeDateBounds`) | Known-zero vs unknown value; recorded-only results; price selection parity with the backend. |

## Valuation algorithm (per date)

1. Load the selected accounts (must all be owned; must be account-type entities). Cash starts at each account's opening balance.
2. Apply every recorded standard transaction dated on or before the date that touches a selected account (withdrawal/deposit/transfer legs).
3. For every recorded investment transaction on or before the date in a selected account: add its cash effect (`TransactionService::getTransactionCashFlow()`) to the account, and accumulate signed quantity per (account, investment) using `TransactionType::quantityMultiplier()`. A cash effect in a currency other than the account's, or a missing cash effect where cash terms exist, adds a `cashflow` missing input.
4. Batch-resolve the latest price on or before the date for every non-zero position.
5. Convert each cash balance and position value to the output currency with `resolveFxRate()`: identity → direct rate → inverse rate → cross via the user's base currency; only rates dated on or before the date; non-positive rates are unavailable.
6. Sum known values; `total_value` is set only when there are no missing inputs.

## TWR algorithm

- Requested dates are sorted; the first is the baseline.
- Between consecutive requested dates, every external cashflow date is an interval boundary. At each boundary the portfolio is valued after that day's activity; the day's net external flow (converted with the rate on the flow date) is removed from the closing value; the sub-period return is `(closing − flow) / opening − 1`.
- Sub-period factors are multiplied to give the period return; period factors are chained for the cumulative return.
- Unavailable conditions: see SPECIFICATION.md "Core Logic / Rules".

## Data and persistence

- Read-only; no tables, migrations, jobs or caches were added. Results are recomputed on every request.
- Relies on the 10-place quantity storage from `feat/investment-quantity-precision`.

## Response shape (abridged)

```json
{
  "series": [
    {
      "date": "2026-10-31",
      "currency": "GBP",
      "known_value": "1200.0000",
      "total_value": null,
      "status": "incomplete",
      "missing_inputs": [{ "account_id": 2, "investment_id": 9, "kind": "price" }],
      "components": [
        { "kind": "cash", "account_id": 2, "amount": "200.0000", "currency": "GBP", "value": "200.0000", "fx": { "rate": "1", "method": "identity", "rates": [] } },
        { "kind": "security", "account_id": 2, "investment_id": 9, "quantity": "10.0000000000", "price": null, "value": null, "price_source": null }
      ],
      "time_weighted_return": {
        "date": "2026-10-31",
        "period_start_date": "2026-09-30",
        "period_return": null,
        "cumulative_return": null,
        "status": "incomplete",
        "missing_inputs": [{ "kind": "valuation", "date": "2026-10-31" }]
      }
    }
  ]
}
```

## Performance notes

- `valuationsWithReturns()` values the requested dates **and** every external-cashflow date in one `PortfolioValuationService::series()` call, so the history is loaded once per request.
- `series()` loads recorded standard and investment transactions once (up to the latest date), replays them in date/id order and snapshots cash, positions and cashflow gaps at each date. Output order matches valuing each date independently (positions and cashflow gaps ordered by first transaction id).
- Prices: one batched lookup for all distinct (investment, date) pairs; `InvestmentService::latestOnOrBefore()` binary-searches the date/id-ordered histories.
- FX: each currency pair's rate history is loaded once per service instance and binary-searched; the base currency and currency-ownership checks are memoised.
- Measured on a 101-account, 17.6k-transaction dataset with 305 cashflow dates in the year (remote MySQL, memory-starved host): the request went from more than 25 minutes (gateway 504) to about 16 s with 59 queries. Remaining cost is hydrating the full history and building ~300 points; nothing is cached or persisted. A persisted daily valuation cache (invalidated by transaction/price/rate changes) is the next step if this is still too slow; the existing monthly summaries are unsuitable (month-end only, no price/FX provenance, unpriced holdings skipped).
