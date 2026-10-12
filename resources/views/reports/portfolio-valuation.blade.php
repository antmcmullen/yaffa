@extends('template.layouts.page')

@section('title_postfix', __('Portfolio valuation'))

@section('content_container_classes', 'container-fluid')

@section('content_header', __('Portfolio valuation'))

@section('content')
<div class="row">
    <div class="col-12 col-lg-3" id="portfolioValuationControls">
        <div class="card mb-3">
            <div class="card-header">
                <div class="card-title mb-0">{{ __('Accounts included') }}</div>
            </div>
            <div class="card-body">
                <p class="text-body-secondary small" id="portfolioAccountHelp">
                    {{ __('All active accounts are selected by default. Choose the accounts to include in this portfolio.') }}
                </p>
                <div class="d-flex gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="portfolioSelectAll">
                        {{ __('Select all') }}
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="portfolioSelectNone">
                        {{ __('Select none') }}
                    </button>
                </div>
                <div id="portfolioAccountsLoading" class="placeholder-glow" aria-hidden="true">
                    <span class="placeholder col-12 mb-2"></span>
                    <span class="placeholder col-10 mb-2"></span>
                    <span class="placeholder col-11"></span>
                </div>
                <div
                    id="portfolioAccountOptions"
                    class="list-group overflow-auto"
                    style="max-height: 22rem;"
                    aria-describedby="portfolioAccountHelp"
                ></div>
                <div id="portfolioAccountsEmpty" class="text-body-secondary small d-none">
                    {{ __('No active accounts are available.') }}
                </div>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header">
                <div class="card-title mb-0">{{ __('Valuation settings') }}</div>
            </div>
            <div class="card-body">
                <div class="mb-2">
                    <strong>{{ __('Currency') }}:</strong>
                    <span id="portfolioValuationCurrency"></span>
                </div>
                <div class="text-body-secondary small">
                    {{ __('Showing 12 monthly valuation points in your base currency.') }}
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-9" id="portfolioValuationMainContent">
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="card-title mb-0">{{ __('Portfolio value over time') }}</div>
                <button type="button" class="btn btn-sm btn-primary" id="portfolioValuationReload" disabled>
                    <i class="fa fa-fw fa-refresh" aria-hidden="true"></i>
                    {{ __('Reload') }}
                </button>
            </div>
            <div id="portfolioValuationError" class="alert alert-danger m-3 d-none" role="alert"></div>
            <div class="card-body">
                <div id="portfolioValuationLoading" class="placeholder-glow" aria-label="{{ __('Loading portfolio valuation') }}">
                    <div class="placeholder w-100" style="height: 24rem;"></div>
                </div>
                <div id="portfolioValuationLatest" class="mb-3 d-none" aria-live="polite">
                    <div class="d-flex flex-wrap align-items-baseline gap-2">
                        <span class="text-body-secondary" id="portfolioValuationLatestLabel"></span>
                        <strong class="fs-3" id="portfolioValuationLatestValue"></strong>
                        <span id="portfolioValuationLatestStatus"></span>
                    </div>
                    <div class="small text-body-secondary" id="portfolioValuationLatestDate"></div>
                </div>
                <div id="portfolioValuationIncompleteNote" class="alert alert-warning d-none" role="status">
                    {{ __('Some dates are incomplete. The chart separates the known subtotal from the total, which is left unavailable when prices, exchange rates, or recorded cash-flow inputs are missing.') }}
                </div>
                <div
                    id="portfolioValuationChart"
                    class="d-none"
                    role="img"
                    aria-label="{{ __('Portfolio valuation chart; data is also available in the table below.') }}"
                    style="width: 100%; height: 25rem;"
                ></div>
                <div id="portfolioValuationNoAccountsNote" class="alert alert-info d-none" role="status">
                    {{ __('No accounts are selected. The empty portfolio is valued at zero.') }}
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title mb-0">{{ __('Valuation details') }}</div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0" id="portfolioValuationTable">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Date') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="text-end">{{ __('Known subtotal') }}</th>
                            <th scope="col" class="text-end">{{ __('Total value') }}</th>
                            <th scope="col">{{ __('Missing inputs') }}</th>
                        </tr>
                    </thead>
                    <tbody id="portfolioValuationRows"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@stop
