import * as am4core from '@amcharts/amcharts4/core';
import * as am4charts from '@amcharts/amcharts4/charts';
import am4themes_animated from '@amcharts/amcharts4/themes/animated';
import { __, toFormattedCurrency, toFormattedDate } from '@/shared/lib/i18n';
import { parseIsoDate } from '@/shared/lib/helpers';
import {
  applyAmChartsColorTheme,
  COLOR_MODE_EVENT,
} from '@/shared/lib/ui/amchartsColorTheme';
import { applyAmChartsLocalization } from '@/shared/lib/i18n/amcharts';
import {
  buildMonthlyValuationDates,
  buildPortfolioValuationQuery,
} from './portfolioValuationData.mjs';

am4core.useTheme(am4themes_animated);

const accountOptions = document.getElementById('portfolioAccountOptions');
const accountOptionsLoading = document.getElementById(
  'portfolioAccountsLoading',
);
const accountOptionsEmpty = document.getElementById('portfolioAccountsEmpty');
const errorElement = document.getElementById('portfolioValuationError');
const reloadButton = document.getElementById('portfolioValuationReload');
const loadingElement = document.getElementById('portfolioValuationLoading');
const chartElement = document.getElementById('portfolioValuationChart');
const tableRows = document.getElementById('portfolioValuationRows');
const baseCurrency = window.YAFFA.userSettings.baseCurrency;
const locale = window.YAFFA.userSettings.locale;

let chart;
let accountNames = new Map();
let valuationSeries = [];
let requestVersion = 0;
let activeRequest = null;

document.getElementById('portfolioValuationCurrency').textContent =
  baseCurrency?.iso_code ?? '';

function showError(message) {
  errorElement.textContent = message;
  errorElement.classList.remove('d-none');
}

function clearError() {
  errorElement.textContent = '';
  errorElement.classList.add('d-none');
}

function getErrorMessage(error) {
  const serverMessage = error?.response?.data?.message;

  return typeof serverMessage === 'string'
    ? serverMessage
    : __('Unable to load the portfolio valuation. Please try again.');
}

function formatCurrency(value) {
  return toFormattedCurrency(value, locale, baseCurrency);
}

function formatPercent(value) {
  if (value === null) {
    return __('Unavailable');
  }

  return new Intl.NumberFormat(locale, {
    style: 'percent',
    maximumFractionDigits: 2,
  }).format(Number(value));
}

function initializeChart() {
  if (chart) {
    chart.dispose();
  }

  applyAmChartsColorTheme(am4core);

  chart = am4core.create('portfolioValuationChart', am4charts.XYChart);
  applyAmChartsLocalization(
    chart,
    window.YAFFA.userSettings.locale,
    window.YAFFA.userSettings.language,
  );
  chart.numberFormatter.intlLocales = locale;
  chart.numberFormatter.numberFormat = {
    style: 'currency',
    currency: baseCurrency.iso_code,
    currencyDisplay: 'narrowSymbol',
    minimumFractionDigits: 0,
    maximumFractionDigits: baseCurrency.generic_decimal_precision ?? undefined,
  };
  chart.dateFormatter.intlLocales = locale;
  chart.dateFormatter.inputDateFormat = 'yyyy-MM-dd';

  const dateAxis = chart.xAxes.push(new am4charts.DateAxis());
  dateAxis.baseInterval = { count: 1, timeUnit: 'month' };
  dateAxis.dateFormats.setKey('month', 'MMM yyyy');
  dateAxis.renderer.minGridDistance = 60;
  dateAxis.tooltipDateFormat = 'MMM yyyy';

  const valueAxis = chart.yAxes.push(new am4charts.ValueAxis());
  valueAxis.numberFormatter = chart.numberFormatter;

  const totalSeries = chart.series.push(new am4charts.LineSeries());
  totalSeries.dataFields.dateX = 'date';
  totalSeries.dataFields.valueY = 'totalValue';
  totalSeries.name = __('Complete portfolio value');
  totalSeries.strokeWidth = 3;
  totalSeries.tooltipText = '{dateX}: [bold]{valueY}[/]';

  const knownSeries = chart.series.push(new am4charts.LineSeries());
  knownSeries.dataFields.dateX = 'date';
  knownSeries.dataFields.valueY = 'knownValue';
  knownSeries.name = __('Known subtotal');
  knownSeries.strokeWidth = 2;
  knownSeries.strokeDasharray = '4,3';
  knownSeries.tooltipText = '{dateX}: [bold]{valueY}[/]';

  chart.legend = new am4charts.Legend();
  chart.cursor = new am4charts.XYCursor();
  chart.scrollbarX = new am4core.Scrollbar();
}

function accountLabel(accountId) {
  return (
    accountNames.get(String(accountId)) ?? `${__('Account')} #${accountId}`
  );
}

function missingInputLabel(input) {
  const labels = {
    cashflow: __('Unresolved recorded cash flow'),
    cashflow_fx: __('Missing dated exchange rate for a portfolio cash flow'),
    price: __('Missing investment price'),
    fx: __('Missing exchange rate'),
    negative_pre_flow_value: __('Negative portfolio value before a cash flow'),
    non_positive_opening_value: __(
      'Return cannot be calculated from a non-positive opening value',
    ),
    unclassified_share_adjustment: __('Unclassified share adjustment'),
    valuation: __('Missing portfolio valuation'),
  };
  const detail = labels[input.kind] ?? String(input.kind);
  const account =
    input.account_id === undefined
      ? ''
      : ` — ${accountLabel(input.account_id)}`;
  const investment =
    input.investment_id === undefined
      ? ''
      : `, ${__('Investment')} #${input.investment_id}`;
  const date = input.date === undefined ? '' : ` (${input.date})`;

  return `${detail}${account}${investment}${date}`;
}

function renderMissingInputs(container, inputs) {
  if (inputs.length === 0) {
    container.textContent = __('None');
    return;
  }

  const list = document.createElement('ul');
  list.className = 'list-unstyled mb-0';

  inputs.forEach((input) => {
    const item = document.createElement('li');
    item.textContent = missingInputLabel(input);
    list.appendChild(item);
  });

  container.appendChild(list);
}

function renderTable(points) {
  tableRows.replaceChildren();

  points.forEach((point) => {
    const row = document.createElement('tr');
    const dateCell = document.createElement('td');
    const statusCell = document.createElement('td');
    const knownCell = document.createElement('td');
    const totalCell = document.createElement('td');
    const periodReturnCell = document.createElement('td');
    const cumulativeReturnCell = document.createElement('td');
    const missingCell = document.createElement('td');
    const status = document.createElement('span');

    dateCell.textContent = toFormattedDate(
      point.date,
      locale,
      point.date,
      true,
      { year: 'numeric', month: 'short', day: 'numeric' },
    );

    status.className = `badge ${
      point.status === 'complete' ? 'text-bg-success' : 'text-bg-warning'
    }`;
    status.textContent =
      point.status === 'complete' ? __('Complete') : __('Incomplete');
    statusCell.appendChild(status);

    knownCell.className = 'text-end';
    knownCell.textContent = formatCurrency(point.known_value);

    totalCell.className = 'text-end';
    totalCell.textContent =
      point.total_value === null
        ? __('Unavailable')
        : formatCurrency(point.total_value);

    periodReturnCell.className = 'text-end';
    periodReturnCell.textContent = formatPercent(
      point.time_weighted_return.period_return,
    );

    cumulativeReturnCell.className = 'text-end';
    cumulativeReturnCell.textContent = formatPercent(
      point.time_weighted_return.cumulative_return,
    );

    renderMissingInputs(missingCell, [
      ...point.missing_inputs,
      ...point.time_weighted_return.missing_inputs,
    ]);
    row.append(
      dateCell,
      statusCell,
      knownCell,
      totalCell,
      periodReturnCell,
      cumulativeReturnCell,
      missingCell,
    );
    tableRows.appendChild(row);
  });
}

function renderLatestPoint(point) {
  document.getElementById('portfolioValuationLatestLabel').textContent =
    point.total_value === null
      ? __('Latest known subtotal')
      : __('Latest total portfolio value');
  document.getElementById('portfolioValuationLatestValue').textContent =
    formatCurrency(point.total_value ?? point.known_value);

  const status = document.getElementById('portfolioValuationLatestStatus');
  status.className = `badge ${
    point.status === 'complete' ? 'text-bg-success' : 'text-bg-warning'
  }`;
  status.textContent =
    point.status === 'complete' ? __('Complete') : __('Incomplete');

  document.getElementById('portfolioValuationLatestDate').textContent =
    toFormattedDate(point.date, locale, point.date, true, {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  document.getElementById('portfolioValuationLatestReturn').textContent =
    formatPercent(point.time_weighted_return.cumulative_return);
  document
    .getElementById('portfolioValuationLatest')
    .classList.remove('d-none');
}

function renderSeries(points, dates) {
  if (
    !Array.isArray(points) ||
    points.length !== dates.length ||
    points.some(
      (point) =>
        typeof point.date !== 'string' ||
        typeof point.known_value !== 'string' ||
        !Array.isArray(point.missing_inputs) ||
        !['complete', 'incomplete'].includes(point.status) ||
        (point.total_value !== null && typeof point.total_value !== 'string') ||
        !point.time_weighted_return ||
        point.time_weighted_return.date !== point.date ||
        !['baseline', 'complete', 'incomplete'].includes(
          point.time_weighted_return.status,
        ) ||
        !Array.isArray(point.time_weighted_return.missing_inputs) ||
        (point.time_weighted_return.period_return !== null &&
          (typeof point.time_weighted_return.period_return !== 'string' ||
            !Number.isFinite(
              Number(point.time_weighted_return.period_return),
            ))) ||
        (point.time_weighted_return.cumulative_return !== null &&
          (typeof point.time_weighted_return.cumulative_return !== 'string' ||
            !Number.isFinite(
              Number(point.time_weighted_return.cumulative_return),
            ))),
    )
  ) {
    throw new Error(__('The portfolio valuation response was invalid.'));
  }

  const chartData = points.map((point) => ({
    date: parseIsoDate(point.date),
    totalValue: point.total_value === null ? null : Number(point.total_value),
    knownValue: Number(point.known_value),
  }));
  if (
    chartData.some(
      (point) =>
        !(point.date instanceof Date) ||
        Number.isNaN(point.date.getTime()) ||
        !Number.isFinite(point.knownValue) ||
        (point.totalValue !== null && !Number.isFinite(point.totalValue)),
    )
  ) {
    throw new Error(
      __('The portfolio valuation response contained invalid values.'),
    );
  }

  valuationSeries = points;
  chart.data = chartData;
  chart.invalidateData();
  renderLatestPoint(points[points.length - 1]);
  renderTable(points);

  const hasIncompletePoints = points.some(
    (point) =>
      point.status === 'incomplete' ||
      point.time_weighted_return.status === 'incomplete',
  );
  document
    .getElementById('portfolioValuationIncompleteNote')
    .classList.toggle('d-none', !hasIncompletePoints);
  document
    .getElementById('portfolioValuationNoAccountsNote')
    .classList.toggle(
      'd-none',
      accountNames.size === 0 ||
        document.querySelectorAll(
          '#portfolioAccountOptions input[type="checkbox"]:checked',
        ).length !== 0,
    );

  chartElement.classList.remove('d-none');
}

function selectedAccountIds() {
  return Array.from(
    document.querySelectorAll(
      '#portfolioAccountOptions input[type="checkbox"]:checked',
    ),
  ).map((input) => Number(input.value));
}

async function loadValuation() {
  if (!baseCurrency?.id || !baseCurrency.iso_code) {
    showError(__('Set a base currency before viewing portfolio valuation.'));
    return;
  }

  const dates = buildMonthlyValuationDates();
  const query = buildPortfolioValuationQuery(
    selectedAccountIds(),
    baseCurrency.id,
    dates,
  );
  const url = `${window.route('api.v1.reports.portfolio-valuation')}?${query}`;
  const currentVersion = ++requestVersion;

  activeRequest?.abort();
  activeRequest = new AbortController();
  reloadButton.disabled = true;
  clearError();
  loadingElement.classList.remove('d-none');
  chartElement.classList.add('d-none');
  document.getElementById('portfolioValuationLatest').classList.add('d-none');
  document
    .getElementById('portfolioValuationIncompleteNote')
    .classList.add('d-none');
  document
    .getElementById('portfolioValuationNoAccountsNote')
    .classList.add('d-none');
  tableRows.replaceChildren();
  valuationSeries = [];
  chart.data = [];
  chart.invalidateData();

  try {
    const { data } = await window.axios.get(url, {
      signal: activeRequest.signal,
    });

    if (currentVersion !== requestVersion) {
      return;
    }

    renderSeries(data.series, dates);
  } catch (error) {
    if (window.axios.isCancel(error) || currentVersion !== requestVersion) {
      return;
    }
    console.error('Failed to load portfolio valuation:', error);
    showError(getErrorMessage(error));
  } finally {
    if (currentVersion === requestVersion) {
      loadingElement.classList.add('d-none');
      reloadButton.disabled = false;
    }
  }
}

function renderAccountOptions(accounts) {
  accountOptions.replaceChildren();
  accountNames = new Map();

  accounts.forEach((account) => {
    const wrapper = document.createElement('label');
    wrapper.className = 'list-group-item d-flex align-items-center gap-2';

    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'form-check-input flex-shrink-0';
    checkbox.value = String(account.id);
    checkbox.checked = true;
    checkbox.addEventListener('change', loadValuation);

    const name = document.createElement('span');
    name.className = 'text-break';
    name.textContent = account.name;

    wrapper.append(checkbox, name);
    accountOptions.appendChild(wrapper);
    accountNames.set(String(account.id), account.name);
  });

  accountOptionsLoading.classList.add('d-none');
  accountOptionsEmpty.classList.toggle('d-none', accounts.length !== 0);
  reloadButton.disabled = false;
}

async function loadAccounts() {
  if (!baseCurrency?.id || !baseCurrency.iso_code) {
    accountOptionsLoading.classList.add('d-none');
    showError(__('Set a base currency before viewing portfolio valuation.'));
    return;
  }

  try {
    const { data } = await window.axios.get('/api/v1/accounts', {
      params: { q: '', limit: 0 },
    });

    if (!Array.isArray(data)) {
      throw new Error(__('The account list response was invalid.'));
    }

    renderAccountOptions(data);
    initializeChart();
    reloadButton.addEventListener('click', loadValuation);
    document
      .getElementById('portfolioSelectAll')
      .addEventListener('click', () => {
        accountOptions
          .querySelectorAll('input[type="checkbox"]')
          .forEach((checkbox) => {
            checkbox.checked = true;
          });
        loadValuation();
      });
    document
      .getElementById('portfolioSelectNone')
      .addEventListener('click', () => {
        accountOptions
          .querySelectorAll('input[type="checkbox"]')
          .forEach((checkbox) => {
            checkbox.checked = false;
          });
        loadValuation();
      });

    await loadValuation();
  } catch (error) {
    accountOptionsLoading.classList.add('d-none');
    reloadButton.disabled = true;
    console.error('Failed to load portfolio accounts:', error);
    showError(getErrorMessage(error));
  }
}

document.addEventListener(COLOR_MODE_EVENT, () => {
  if (!chart) {
    return;
  }

  initializeChart();
  if (valuationSeries.length > 0) {
    renderSeries(
      valuationSeries,
      valuationSeries.map((point) => point.date),
    );
  }
});

loadAccounts();
