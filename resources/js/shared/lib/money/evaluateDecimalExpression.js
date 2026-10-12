import { all, create } from 'mathjs';
import Decimal from 'decimal.js';

const math = create(all, { number: 'BigNumber', precision: 40 });

export function evaluateDecimalExpression(input) {
  if (input === '') {
    return null;
  }

  const result = math.evaluate(input);
  if (!math.isBigNumber(result) && typeof result !== 'number') {
    throw new Error('The expression must evaluate to a finite number.');
  }
  const amount = new Decimal(result.toString());
  if (!amount.isFinite()) {
    throw new Error('The expression must evaluate to a finite number.');
  }

  return amount.toFixed();
}
