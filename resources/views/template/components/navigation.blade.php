<ul class="sidebar-nav" data-coreui="navigation" data-simplebar>
    <li class="nav-item">
        <a class="nav-link" href="{{ route('home') }}">
            <i class="nav-icon fa fa-dashboard"></i>
            {{ __('Dashboard') }}
        </a>
    </li>

    <li class="nav-item">
        @if(Auth::user()->enable_investments)
            <a class="nav-link" href="{{ route('investment.index') }}">
                <i class="nav-icon fa-solid fa-chart-line"></i>
                {{ __('Investments') }}
            </a>
        @endif
    </li>

    <li class="nav-group">
        <a class="nav-link nav-group-toggle" href="#">
            <i class="nav-icon fa-solid fa-database"></i>
            {{ __('Assets') }}
        </a>
        <ul class="nav-group-items">
            <x-nav-link
                href="{{ route('currency.index') }}"
                iconClasses="fa-solid fa-money-bill"
                text="{{ __('Currencies') }}"
            />
            <x-nav-link
                href="{{ route('account-group.index') }}"
                iconClasses="fa-solid fa-layer-group"
                text="{{ __('Account groups') }}"
            />
            <x-nav-link
                href="{{ route('account-entity.index', ['type' => 'account']) }}"
                iconClasses="fa-solid fa-building-columns"
                text="{{ __('Accounts') }}"
            />
            <x-nav-link
                href="{{ route('account-entity.index', ['type' => 'payee']) }}"
                iconClasses="fa-solid fa-briefcase"
                text="{{ __('Payees') }}"
            />
            @if(Auth::user()->enable_investments)
                <x-nav-link
                    href="{{ route('investment-group.index') }}"
                    iconClasses="fa-solid fa-layer-group"
                    text="{{ __('Investment groups') }}"
                />
            @endif
            <x-nav-link
                href="{{ route('categories.index') }}"
                iconClasses="fa-solid fa-folder-open"
                text="{{ __('Categories') }}"
            />
            <x-nav-link
                href="{{ route('tag.index') }}"
                iconClasses="fa-solid fa-tags"
                text="{{ __('Tags') }}"
            />
        </ul>
    </li>

    <li class="nav-group">
        <a class="nav-link nav-group-toggle" href="#">
            <i class="nav-icon fa-solid fa-table-list"></i>
            {{ __('Reports') }}
        </a>
        <ul class="nav-group-items">
            <x-nav-link
                href="{{ route('reports.transactions') }}"
                iconClasses="fa-solid fa-magnifying-glass"
                text="{{ __('Find transactions') }}"
            />
            <x-nav-link
                href="{{ route('reports.cashflow') }}"
                iconClasses="fa-solid fa-chart-line"
                text="{{ __('Cash flow') }}"
            />
            <x-nav-link
                href="{{ route('reports.budgetchart') }}"
                iconClasses="fa-solid fa-chart-line"
                text="{{ __('Budget chart') }}"
            />
            <x-nav-link
                href="{{ route('report.schedules') }}"
                iconClasses="fa-solid fa-list"
                text="{{ __('Schedules and budgets') }}"
            />
            <x-nav-link
                href="{{ route('reports.investment_timeline') }}"
                iconClasses="fa-solid fa-chart-gantt"
                text="{{ __('Investment timeline') }}"
            />
        </ul>
    </li>

    <li class="nav-group">
        <a class="nav-link nav-group-toggle" href="#">
            <i class="nav-icon fa-solid fa-microchip"></i>
            {{ __('Automation') }}
        </a>
        <ul class="nav-group-items">
            <x-nav-link
                href="{{ route('received-mail.index') }}"
                iconClasses="fa-solid fa-envelope"
                text="{{ __('Received emails')  }}"

            />
            <x-nav-link
                    href="{{ route('import.csv') }}"
                    iconClasses="fa-solid fa-upload"
                    text="{{ __('Import transactions') }}"
            />
        </ul>
    </li>

    <li class="nav-group">
        <a class="nav-link nav-group-toggle" href="#">
            <i class="nav-icon fa fa-bolt"></i>
            {{ __('Quick actions') }}
        </a>
        <ul class="nav-group-items">
            <x-nav-link
                href="{{ route('transaction.create', ['type' => 'standard']) }}"
                iconClasses="fa-solid fa-cart-plus"
                text="{{ __('New transaction') }}"
            />
            @if(Auth::user()->enable_investments)
                <x-nav-link
                    href="{{ route('transaction.create', ['type' => 'investment']) }}"
                    iconClasses="fa-solid fa-chart-line"
                    text="{{ __('New investment transaction') }}"
                />
            @endif
        </ul>
    </li>
</ul>
