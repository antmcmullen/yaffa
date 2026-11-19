# Portfolio valuation — permissions

- **Web page** `reports.portfolio-valuation`: `auth` + `verified` middleware. The page itself holds no data; everything is loaded through the API below.
- **API** `api.v1.reports.portfolio-valuation`: `auth:sanctum`, `verified`, and `abilities:read`. A bearer token needs the `read` ability (paired mobile tokens have it); `settings` is not required. Covered by `tests/Feature/API/ApiAbilityEnforcementTest.php` (`reports.portfolio-valuation` row).
- **Ownership**: the request rule restricts `currency_id` to the user's currencies. The service additionally refuses (not found) any account, account currency, investment or investment currency that is not the user's, so a foreign ID can never contribute to a valuation.
- **No writes**: the feature is read-only; there is no create/update/delete surface and no policy class beyond ownership scoping.
