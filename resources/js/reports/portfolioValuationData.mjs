export function buildMonthlyValuationDates(anchorDate = new Date()) {
  if (!(anchorDate instanceof Date) || Number.isNaN(anchorDate.getTime())) {
    throw new TypeError('anchorDate must be a valid Date');
  }

  const today = new Date(
    anchorDate.getFullYear(),
    anchorDate.getMonth(),
    anchorDate.getDate(),
  );
  const dates = [];

  for (let monthOffset = 11; monthOffset >= 1; monthOffset--) {
    const monthEnd = new Date(
      today.getFullYear(),
      today.getMonth() - monthOffset + 1,
      0,
    );
    dates.push(formatLocalDate(monthEnd));
  }

  dates.push(formatLocalDate(today));

  return dates;
}

export function buildPortfolioValuationQuery(accountIds, currencyId, dates) {
  const params = new URLSearchParams();

  if (accountIds.length === 0) {
    params.set('account_ids', '');
  } else {
    accountIds.forEach((accountId) => {
      params.append('account_ids[]', String(accountId));
    });
  }

  params.set('currency_id', String(currencyId));
  dates.forEach((date) => params.append('dates[]', date));

  return params.toString();
}

function formatLocalDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}
