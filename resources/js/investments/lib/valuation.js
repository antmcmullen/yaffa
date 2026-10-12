export function getInvestmentValue(quantity, price) {
  if (quantity == null) {
    return null;
  }

  if (Number(quantity) === 0) {
    return 0;
  }

  if (price == null) {
    return null;
  }

  return quantity * price;
}
