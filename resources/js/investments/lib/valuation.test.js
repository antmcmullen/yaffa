import { test } from 'node:test';
import assert from 'node:assert/strict';
import { getInvestmentValue } from './valuation.js';

test('an unpriced non-zero holding has unknown value', () => {
  assert.equal(getInvestmentValue('12.5', null), null);
});

test('a zero quantity has zero value even when its price is unknown', () => {
  assert.equal(getInvestmentValue('0.0000000000', null), 0);
  assert.equal(getInvestmentValue(0, null), 0);
});

test('a known zero price is a known zero value for a non-zero holding', () => {
  assert.equal(getInvestmentValue('12.5', 0), 0);
});

test('a priced holding retains its calculated value', () => {
  assert.equal(getInvestmentValue('12.5', 4), 50);
});

test('a missing quantity remains unknown', () => {
  assert.equal(getInvestmentValue(null, 4), null);
});
