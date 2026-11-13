# Portfolio valuation — tests

## Backend (Pest/PHPUnit, MySQL)

| File | What it protects |
| --- | --- |
| `tests/Feature/API/V1/PortfolioValuationApiV1Test.php` | Decimal-string output for explicit accounts and dates; authentication required; empty scope is a complete zero; account scope required; foreign currencies/accounts rejected; date format and 31-point limit; duplicate dates rejected; list-shaped inputs. |
| `tests/Feature/Services/PortfolioValuationServiceTest.php` | Cash + holdings from recorded activity with trade price evidence; payee-linked standard transactions; unpriced non-zero holding is incomplete while a zero holding is fine; only FX on/before the date; zero FX rate is not valid; foreign accounts rejected. |
| `tests/Feature/Services/PortfolioCashflowServiceTest.php` | Deposit/withdrawal/boundary-transfer classification; share adjustments returned unclassified and investment activity excluded from external flows; scheduled transactions excluded; ownership validation. |
| `tests/Feature/Services/PortfolioTimeWeightedReturnServiceTest.php` | Contributions neutralised at end of day; foreign cashflows converted at the flow-date rate; growth linked across flow boundaries; no return across an unclassified share adjustment; missing dated FX reported instead of estimated. |
| `tests/Unit/Services/InvestmentServicePriceTest.php` | Combined price uses the latest eligible source and no future prices; same-day: latest trade id wins and a trade beats a stored price. |
| `tests/Feature/InvestmentSummaryTest.php` | Summary/single/batch price agreement on same-day precedence; known zero prices preserved. |
| `tests/Feature/API/InvestmentApiControllerTest.php` | Timeline keeps missing and zero prices distinct; display data includes schedules flagged as projected. |
| `tests/Feature/API/ApiAbilityEnforcementTest.php` | `read` ability gate on the new endpoint. |

## Frontend (`node --test`)

| File | What it protects |
| --- | --- |
| `resources/js/investments/lib/investmentReturn.test.js` | `priceAsOf` parity with the backend (no look-ahead, newer source wins, highest-id trade on a shared date, scheduled instances ignored, known-zero vs missing); recorded-only filtering and default date bounds; returns exclude scheduled occurrences. |
| `resources/js/investments/lib/valuation.test.js` | Unknown vs known-zero holding values. |
| `resources/js/reports/portfolioValuationData.test.mjs` | 12 ordered month ends ending today (incl. leap day); query encoding of an explicit empty scope and repeated list parameters. Note: `npm run test:js` only globs `*.test.js`; run this file directly with `node --test`. |

## Not covered

- No browser test for the report page; layout at phone widths, keyboard use and dark mode have not been verified automatically.
- No performance test for large transaction histories.
