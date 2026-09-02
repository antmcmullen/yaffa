# Bank Statement Import and Reconciliation

## Objective

Add a bank-statement import workflow to Yaffa that can consume structured or enriched statement content, identify the correct user-owned account, propose ledger actions, and safely reconcile those proposals against existing transactions before anything is committed.

The first extraction sources should be:

1. Paperless-ngx documents already enriched by Docling.
2. Direct uploads using the same canonical import contract.
3. Optional Unstract extraction for statements where layout or semantics are difficult to resolve deterministically.

Yaffa remains the authoritative ledger. Paperless, Docling and Unstract provide evidence and observations only.

## Current Yaffa foundations

The current `develop` branch already supports most of the accounting concepts needed for this feature:

- `AccountEntity` is user-owned and differentiates accounts from payees.
- `Account` carries account group and currency.
- standard transactions support withdrawal, deposit and transfer.
- `TransactionDetailStandard` stores separate `amount_from` and `amount_to` values.
- transactions can link to an `AiDocument`.
- `TransactionItem` supports child/category splits under a transaction.

The statement importer should therefore be an ingestion and reconciliation layer over the existing ledger, not a parallel accounting model.

## Design principles

### Statement data is evidence, not an instruction to create transactions

A statement row may represent:

- a genuinely new withdrawal or deposit;
- a transfer that is already ledgered from the other account;
- a credit-card settlement that was entered manually;
- a regular-saver transfer that already exists;
- a foreign-exchange settlement whose underlying purchase must be represented separately;
- a payment through an intermediary account where the bank-side movement is a transfer and the economic purchase exists on the other side;
- an already reconciled historical transaction.

For that reason every row is imported first as a proposal and only later linked to or converted into ledger transactions.

### Deterministic matching before AI inference

Use deterministic identifiers and ledger facts first. AI-derived classifications should support, not override, reconciliation logic.

### No real financial identities in the public repository

The repository must never contain real:

- institution names;
- account numbers;
- sort codes;
- card suffixes;
- statement text;
- balances;
- user-specific aliases;
- account mappings;
- exported statements;
- credentials or API tokens.

Fixtures, examples and tests must use synthetic identities such as `Example Current Account`, `Example Credit Card`, `Example Wallet` and `Example Marketplace`.

Any runtime account-recognition identifier should be encrypted or represented by a user-scoped HMAC/fingerprint where possible. Source identifiers must never be logged.

## Proposed import domain

### `StatementImport`

A user-owned import envelope containing:

- `id`
- `user_id`
- `account_entity_id` nullable until matched
- `source_type` (`paperless`, `upload`, `api`)
- `source_reference` nullable
- `ai_document_id` nullable
- `statement_start_date`
- `statement_end_date`
- `opening_balance` nullable
- `closing_balance` nullable
- `currency_id`
- `status` (`received`, `parsed`, `account_match_required`, `review`, `ready`, `committed`, `failed`)
- `parser_adapter`
- `parser_version`
- `content_fingerprint`
- timestamps

Raw source documents remain in Paperless or private application storage and are not committed to Git-tracked storage.

### `StatementImportRow`

Each extracted row remains a proposal until reviewed or matched:

- `statement_import_id`
- `row_index`
- `posted_at`
- `value_at` nullable
- `description_raw` runtime only
- `description_normalised`
- `amount`
- `currency_id`
- `running_balance` nullable
- `external_reference` nullable
- `row_fingerprint`
- `classification`
- `confidence`
- `match_status`
- `matched_transaction_id` nullable
- `proposed_action` JSON
- `review_notes` nullable

### Reconciliation links

Do not assume one statement row maps to one transaction.

A transfer may appear on two statements and both rows should reconcile to the same Yaffa transfer. Likewise an FX event may need several linked ledger entries.

Use a dedicated reconciliation link or grouping model so that:

- many statement rows can reference one ledger transaction;
- one statement row can participate in a grouped FX or clearing-account composition;
- provenance remains queryable after import.

## Canonical extraction contract

Create a provider-neutral `StatementExtractionAdapter` contract.

Initial adapters:

- `PaperlessDoclingAdapter`
- `UploadStatementAdapter`
- `UnstractAdapter` optional

Each adapter must return the same canonical Yaffa statement DTO rather than leaking provider-specific payloads throughout the application.

Unstract must never mutate Yaffa directly. It can only return observations and confidence/provenance data.

## Account recognition

Introduce a user-owned account import profile, for example `AccountImportProfile`, containing private matching hints:

- `account_entity_id`
- expected currency
- statement/parser type
- user-defined aliases
- masked or fingerprinted identifiers where needed
- optional encrypted institution/account hints
- account import behaviour
- configurable date and amount matching tolerances

Matching must always be scoped to the import owner (`user_id`).

Do not hard-code institution names or account mappings in source code.

## Account behaviours

Add a user-configurable behaviour such as:

- `normal`
- `clearing`
- `credit`
- `savings`
- `investment`

This describes how the account behaves during reconciliation, not which institution provides it.

## Classification intents

Each row should be classified into one of:

1. `new_withdrawal`
2. `new_deposit`
3. `existing_transaction_match`
4. `own_account_transfer`
5. `clearing_account_transfer`
6. `fx_purchase`
7. `fx_transfer`
8. `fee`
9. `ambiguous_review`
10. `ignored`

## Duplicate prevention and existing-ledger matching

Duplicate prevention is a primary requirement.

Before proposing a new transaction, search existing transactions owned by the user.

### High-confidence matching

Prefer exact or near-exact evidence:

- same account;
- same signed amount;
- same date;
- matching external reference or import fingerprint;
- opposite side is another known user account;
- an existing reconciliation link already exists.

### Strong fuzzy matching

Where exact matching is unavailable:

- amount exact;
- date within a configurable tolerance;
- normalised description/payee similarity;
- compatible transaction direction/type;
- compatible source/destination account.

Persist a match score and human-readable reason list.

### Explicit duplicate scenarios

Tests must cover:

- credit-card settlement already entered manually as a transfer;
- regular-saver/top-up entered before either statement arrives;
- the same transfer appearing independently on both account statements;
- manually entered purchase later found on a statement;
- clearing-account funding already ledgered while the underlying purchase exists separately;
- re-importing the same statement.

A credible existing match must default to review/match, never to creating another ledger entry.

## Own-account transfers

When both sides can be identified as accounts owned by the same user, reconcile to a single Yaffa `transfer`.

If the corresponding row from the opposite account statement arrives later, attach it to the same transfer rather than create another transaction.

## Foreign exchange handling

Do not collapse a foreign-currency purchase into only the base-currency settlement shown by the bank or card provider.

Represent FX as a grouped composition that preserves both observed values.

Synthetic example:

- base settlement: `-82.14 GBP`
- underlying purchase: `-95.00 EUR`
- optional fee: `1.50 GBP`

The importer should propose:

1. a base-settlement leg on the funding account;
2. an underlying purchase leg in the source currency against the merchant/payee/category;
3. a distinct fee leg or item where explicitly present;
4. a derived effective FX rate stored as metadata/evidence rather than replacing observed amounts.

The existing separate `amount_from` and `amount_to` fields should be reused where appropriate, but the implementation must not force a multi-leg FX event into one record if that would lose accounting meaning.

Use an explicit reconciliation or transaction-group identifier to link the composition.

Observed statement values are evidence. Inferred exchange rates, payees and categories remain proposals until deterministic validation or user confirmation.

## Clearing / intermediary accounts

Some user accounts should be treated as clearing accounts.

A bank statement may show a payment to the clearing account, while the economic purchase belongs on the clearing-account side.

Represent this as:

- bank-side movement: transfer from bank or credit account to clearing account;
- clearing-account side: one or more child/linked purchase transactions to the actual merchant/category;
- provider fee: separate item/transaction when present.

If the detailed clearing-side purchase already exists, match it instead of creating a duplicate.

This behaviour must be configured by the user on the account; no provider brand should be hard-coded.

## Paperless / Docling / Unstract flow

1. User selects **Import bank statement**.
2. Source is a Paperless document or direct upload.
3. If Paperless already has Docling-enriched content, use it as the primary extraction input.
4. Apply deterministic parsing where possible.
5. Optionally call a configured Unstract instance when the statement layout or semantics need structured extraction.
6. Canonicalise extracted content into Yaffa's statement DTO.
7. Match or request selection of the target user account.
8. Run duplicate, transfer, FX and clearing-account classification.
9. Present a reconciliation review screen.
10. Commit accepted proposals atomically.
11. Link committed or matched transactions back to statement evidence.

No document should be sent to any third-party extraction service unless the user explicitly configures and enables that provider.

## Review experience

Group rows by:

- Matched existing
- Proposed new
- Transfer detected
- FX composition
- Clearing-account composition
- Needs review
- Ignored

For each row show:

- statement observation;
- proposed or matched Yaffa transaction;
- match/classification reasons;
- confidence;
- alternate matching choices;
- source/destination account controls;
- payee/category controls;
- expandable FX/clearing compositions.

Also show a reconciliation summary:

- statement opening balance;
- imported movement total;
- statement closing balance;
- calculated Yaffa balance at statement end;
- unexplained difference.

A statement should not be marked reconciled while unexplained rows remain unless the user explicitly overrides with a recorded reason.

## Idempotency

Re-importing a statement must be safe.

Use:

- document/content fingerprint;
- per-row fingerprint;
- external references where available;
- existing reconciliation links.

A repeated import should reopen or enrich the previous import rather than recreate transactions.

## Security and public-repository hygiene

Before implementation introduces statement parsing or fixtures:

1. scan the current tree and full Git history for real account identifiers, institution-specific examples, statement exports and credentials;
2. replace any examples in the working tree with synthetic data;
3. if sensitive financial identifiers exist in history, perform a history-rewrite/removal rather than only deleting them in a new commit;
4. rotate any credential discovered;
5. ensure extraction payloads, debug logs and statement artefacts are ignored and never committed;
6. add CI secret/PII-pattern checks suited to this public repository.

Do not copy any discovered sensitive value into issues, commits, test names or PR descriptions.

## Implementation phases

### Phase 1 — import domain and deterministic reconciliation

- migrations/models for statement imports, rows, reconciliation links and account import profiles;
- canonical statement DTO;
- direct upload/manual structured parser route;
- account matching;
- duplicate matching;
- ordinary withdrawal/deposit/own-account-transfer proposals;
- review UI;
- idempotent commit flow;
- fully synthetic tests.

### Phase 2 — Paperless Docling adapter

- private Paperless connection settings;
- select/import a Paperless statement;
- consume Docling-enriched content;
- retain source-document lineage;
- never mirror source documents into Git-tracked storage.

### Phase 3 — FX compositions

- source-currency purchase value;
- settlement value;
- optional fee;
- grouping/provenance;
- dedupe against manually entered FX transactions.

### Phase 4 — clearing accounts

- user-configurable clearing-account behaviour;
- transfer plus child purchase composition;
- matching of existing clearing-side transactions.

### Phase 5 — optional Unstract adapter

- user-configured endpoint and credentials;
- provider-neutral output mapping;
- schema extraction for poorly structured statements;
- confidence and provenance capture;
- no direct ledger mutation.

## Acceptance criteria

- [ ] Import is completely scoped to the current user.
- [ ] Import identifies or asks the user to choose the target account.
- [ ] Re-importing the same statement does not create duplicates.
- [ ] Existing manually ledgered transactions can be matched and reconciled.
- [ ] Opposite sides of own-account transfers resolve to one ledger transaction.
- [ ] FX purchases preserve both source-currency and base-settlement observations.
- [ ] Optional FX fees are represented distinctly.
- [ ] Clearing accounts can represent a bank transfer plus linked underlying purchases.
- [ ] AI/Unstract results remain proposals; deterministic validation or user confirmation controls ledger mutation.
- [ ] Paperless/Docling can be used as an extraction source without committing document content.
- [ ] No real user financial or institution data appears in source, fixtures, docs, tests or PR text.
- [ ] Repository and history privacy audit is completed before statement examples are introduced.
- [ ] All examples and tests use generated synthetic identities and values.
- [ ] Import and reconciliation provenance remains queryable for auditability.
