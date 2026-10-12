import assert from 'node:assert/strict';
import test from 'node:test';
import { evaluateDecimalExpression } from './evaluateDecimalExpression.js';

test('preserves ten decimal places even at the maximum quantity magnitude', () => {
  assert.equal(
    evaluateDecimalExpression('9999999999.9999999999'),
    '9999999999.9999999999',
  );
  assert.equal(evaluateDecimalExpression('0.0000000001'), '0.0000000001');
});

test('evaluates quantity expressions without native float rounding', () => {
  assert.equal(evaluateDecimalExpression('0.1 + 0.2'), '0.3');
  assert.equal(
    evaluateDecimalExpression('1.1234567891 + 0.0000000001'),
    '1.1234567892',
  );
});

test('leaves excess precision for server validation instead of silently rounding', () => {
  assert.equal(evaluateDecimalExpression('1.12345678901'), '1.12345678901');
});

test('supports clearing the field and rejects non-finite or nonnumeric expressions', () => {
  assert.equal(evaluateDecimalExpression(''), null);
  for (const input of ['1 / 0', '[1,2]', 'sqrt(-1)', 'not-a-number']) {
    assert.throws(() => evaluateDecimalExpression(input));
  }
});
