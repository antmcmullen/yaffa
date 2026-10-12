import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  buildMonthlyValuationDates,
  buildPortfolioValuationQuery,
} from './portfolioValuationData.mjs';

test('builds 12 ordered monthly points ending at today', () => {
  const dates = buildMonthlyValuationDates(new Date(2026, 2, 15));

  assert.equal(dates.length, 12);
  assert.equal(dates[0], '2025-04-30');
  assert.equal(dates[dates.length - 1], '2026-03-15');
  assert.deepEqual(dates, [...dates].sort());
});

test('keeps leap-day month ends in the requested date range', () => {
  const dates = buildMonthlyValuationDates(new Date(2024, 2, 15));

  assert.ok(dates.includes('2024-02-29'));
});

test('encodes an explicit empty scope and repeated list query parameters', () => {
  const emptyScope = new URLSearchParams(
    buildPortfolioValuationQuery([], 7, ['2026-03-15']),
  );
  assert.equal(emptyScope.get('account_ids'), '');
  assert.deepEqual(emptyScope.getAll('dates[]'), ['2026-03-15']);

  const selectedScope = new URLSearchParams(
    buildPortfolioValuationQuery([13, 21], 7, [
      '2026-02-28',
      '2026-03-15',
    ]),
  );
  assert.deepEqual(selectedScope.getAll('account_ids[]'), ['13', '21']);
  assert.deepEqual(selectedScope.getAll('dates[]'), [
    '2026-02-28',
    '2026-03-15',
  ]);
});
