import { test } from 'node:test';
import assert from 'node:assert/strict';
import { investmentCashflow, priceFromCashflow } from './investmentCashflow.js';

const buy = {
  amount_multiplier: -1,
  dividend_multiplier: 1,
  commission_multiplier: -1,
  tax_multiplier: -1,
};
const sell = { ...buy, amount_multiplier: 1 };

test('cashflow keeps decimal strings exact and applies special transaction signs', () => {
  assert.equal(
    investmentCashflow(
      { quantity: '10', price: '20', commission: '30', tax: '40' },
      buy,
    ).toString(),
    '-270',
  );
  assert.equal(
    investmentCashflow(
      { dividend: '20.2500', tax: '0.1250' },
      { ...buy, dividend_multiplier: -1 },
    ).toString(),
    '-20.375',
  );
  assert.equal(
    investmentCashflow(
      { tax: '12.5000', commission: '0.1000' },
      { ...sell, tax_multiplier: 1 },
    ).toString(),
    '12.4',
  );
});

test('price calculation reverses signed purchase and sale cashflows', () => {
  const config = { quantity: '10', commission: '30', tax: '40' };
  assert.equal(priceFromCashflow(config, buy, '-270'), '20.0000000000');
  assert.equal(priceFromCashflow(config, sell, '130'), '20.0000000000');
  assert.equal(
    priceFromCashflow({ quantity: '0.004', commission: '0.21' }, sell, '0'),
    '52.5000000000',
  );
});

test('price calculation preserves ten decimal places without a Number conversion', () => {
  assert.equal(priceFromCashflow({ quantity: '3' }, buy, '-1'), '0.3333333333');
  assert.equal(
    priceFromCashflow({ quantity: '1' }, buy, '-1234567890.1234567890'),
    '1234567890.1234567890',
  );
});

test('price calculation rejects invalid quantities, amounts and nonpositive prices', () => {
  for (const quantity of ['0', '-1', 'Infinity']) {
    assert.throws(() => priceFromCashflow({ quantity }, buy, '-100'));
  }
  for (const cashflow of ['NaN', 'Infinity', 'invalid', '100']) {
    assert.throws(() => priceFromCashflow({ quantity: '1' }, buy, cashflow));
  }
  assert.throws(() =>
    priceFromCashflow({ quantity: '1' }, { amount_multiplier: null }, '100'),
  );
});
