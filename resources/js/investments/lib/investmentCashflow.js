import Decimal from 'decimal.js';

const ExactDecimal = Decimal.clone({ precision: 40 });

export function investmentCashflow(config, type) {
  return new ExactDecimal(config.quantity ?? 0)
    .times(config.price ?? 0)
    .times(type.amount_multiplier ?? 0)
    .plus(
      new ExactDecimal(config.dividend ?? 0).times(
        type.dividend_multiplier ?? 1,
      ),
    )
    .plus(
      new ExactDecimal(config.commission ?? 0).times(
        type.commission_multiplier ?? -1,
      ),
    )
    .plus(new ExactDecimal(config.tax ?? 0).times(type.tax_multiplier ?? -1));
}

export function priceFromCashflow(config, type, cashflow) {
  const quantity = new ExactDecimal(config.quantity ?? 0);
  const multiplier = type.amount_multiplier ?? 0;
  if (!quantity.isFinite() || quantity.lte(0) || multiplier === 0) {
    throw new Error('Enter quantity first to calculate the price');
  }

  const amount = new ExactDecimal(cashflow);
  if (!amount.isFinite()) {
    throw new Error('Please enter a valid cashflow value');
  }

  const charges = investmentCashflow({ ...config, price: 0 }, type);
  const price = amount.minus(charges).div(quantity.times(multiplier));
  const rounded = price.toDecimalPlaces(10, ExactDecimal.ROUND_HALF_UP);
  if (!rounded.isFinite() || rounded.lte(0)) {
    throw new Error('Calculated price must be greater than zero');
  }

  return rounded.toFixed(10);
}
