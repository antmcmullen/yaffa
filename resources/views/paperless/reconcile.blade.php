@extends('template.layouts.page')

@section('content')
<div class="container">
    <h1>Paperless Reconcile</h1>

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form action="{{ route('paperless.reconcile.run') }}" method="post">
        @csrf
        <div class="mb-3">
            <label class="form-label">Document id</label>
            <input name="doc_id" value="{{ old('doc_id') }}" required class="form-control">
        </div>
        <div class="mb-3">
            <p class="small text-muted">We will fetch the document and compare it against transactions generated from the selected account and date range. API credentials are used server-side only.</p>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Account</label>
                <select name="account_id" class="form-control" required>
                    <option value="">-- choose account --</option>
                    @foreach($accounts as $a)
                        <option value="{{ $a->id }}" {{ old('account_id') == $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Start</label>
                <input name="start" type="date" value="{{ old('start') }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">End</label>
                <input name="end" type="date" value="{{ old('end') }}" class="form-control" required>
            </div>
        </div>
        <button class="btn btn-primary">Fetch & Reconcile</button>
    </form>

    @if(!empty($suggestions))
        <h2 class="mt-4">Report</h2>

        <h4>Matches</h4>
        <table class="table table-sm table-striped">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>CSV id</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Diff</th>
                    <th>Description / Parts</th>
                </tr>
            </thead>
            <tbody>
                @foreach($suggestions['matches'] as $m)
                    @php $c = $m['csv'] ?? []; @endphp
                    <tr>
                        <td>{{ $m['type'] }}</td>
                        <td>{{ $c['"id"'] ?? $c['id'] ?? '' }}</td>
                        <td>{{ $c['effective_date'] ?? ($m['statement']['date'] ?? ($m['statement_parts'][0]['date'] ?? '')) }}</td>
                        <td>{{ number_format($c['cashflow_value'] ?? ($m['statement']['amount'] ?? 0), 4) }}</td>
                        <td>{{ number_format($m['diff'] ?? 0, 4) }}</td>
                        <td>
                            @if($m['type'] === 'single')
                                {{ $m['statement']['ref'] ?? '' }} — {{ $m['statement']['description'] ?? '' }}
                            @else
                                @foreach($m['statement_parts'] as $p)
                                    <div>{{ $p['ref'] ?? '' }} : {{ number_format($p['amount'] ?? 0, 2) }} — {{ $p['description'] ?? '' }}</div>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h4>Unmatched statement rows</h4>
        <table class="table table-sm table-bordered">
            <thead><tr><th>Date</th><th>Ref</th><th>Amount</th><th>Description</th><th>Action</th></tr></thead>
            <tbody>
                @foreach($suggestions['unmatched_statement'] ?? [] as $u)
                    <tr>
                        <td>{{ $u['date'] ?? '' }}</td>
                        <td>{{ $u['ref'] ?? '' }}</td>
                        <td>{{ number_format($u['amount'] ?? 0, 2) }}</td>
                        <td>{{ $u['description'] ?? '' }}</td>
                        <td>
                            <form method="post" action="{{ route('paperless.reconcile.createDraft') }}">
                                @csrf
                                @php
                                    $draft = [
                                        'ref' => $u['ref'] ?? '',
                                        'date' => $u['date'] ?? now()->toDateString(),
                                        'amount' => $u['amount'] ?? 0,
                                        'description' => $u['description'] ?? '',
                                        'config' => [
                                            'account_from_id' => null,
                                            'account_to_id' => null,
                                        ],
                                    ];
                                    // assign to selected account by default as account_to for positive amounts
                                    if (!empty($selected_account_id)) {
                                        if (($u['amount'] ?? 0) >= 0) {
                                            $draft['config']['account_to_id'] = $selected_account_id;
                                        } else {
                                            $draft['config']['account_from_id'] = $selected_account_id;
                                        }
                                    }
                                @endphp
                                <input type="hidden" name="draft" value="{{ e(json_encode($draft)) }}">
                                <button class="btn btn-sm btn-outline-primary">Create Draft</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if(!empty($suggestions['unmatched_csv']))
            <h4>Unmatched CSV rows</h4>
            <table class="table table-sm">
                <thead><tr><th>CSV id</th><th>Date</th><th>Amount</th><th>Account</th></tr></thead>
                <tbody>
                    @foreach($suggestions['unmatched_csv'] as $c)
                        <tr>
                            <td>{{ $c['id'] ?? $c['"id"'] ?? '' }}</td>
                            <td>{{ $c['date'] ?? '' }}</td>
                            <td>{{ number_format($c['amount'] ?? 0, 4) }}</td>
                            <td>{{ $c['acc'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @elseif(!empty($report))
        <h2 class="mt-4">Report</h2>
        <pre style="white-space:pre-wrap; background:#f8f9fa; padding:12px; border:1px solid #ddd">{{ $report }}</pre>
    @endif

    @if(!empty($md))
        <p>Saved markdown to storage: <code>{{ $md }}</code></p>
    @endif

    @if(!empty($suggestions))
        <h2 class="mt-4">Suggested Actions</h2>
        <div class="card p-3">
            <p><strong>Matched:</strong> {{ $suggestions['summary']['matched'] ?? 0 }} — <strong>Unmatched statement rows:</strong> {{ $suggestions['summary']['unmatched_statement'] ?? 0 }} — <strong>Unmatched CSV rows:</strong> {{ $suggestions['summary']['unmatched_csv'] ?? 0 }}</p>
            @if(!empty($suggestions['unmatched_statement']))
                <h4>Unmatched statement rows</h4>
                <ul>
                    @foreach($suggestions['unmatched_statement'] as $u)
                        <li>{{ $u['date'] ?? 'n/a' }} — {{ $u['ref'] ?? '' }} — {{ number_format($u['amount'] ?? 0, 2) }} — {{ $u['description'] ?? '' }}</li>
                    @endforeach
                </ul>
            @endif

            @if(!empty($suggestions['matches']))
                <h4>Matches (summary)</h4>
                <ul>
                    @foreach($suggestions['matches'] as $m)
                        <li>
                            @if($m['type'] === 'single')
                                {{ $m['statement']['date'] ?? 'n/a' }} — {{ $m['statement']['ref'] ?? '' }} => CSV {{ number_format($m['csv']['cashflow_value'] ?? 0, 4) }}
                            @else
                                Combined ({{ count($m['statement_parts'] ?? []) }}) => CSV {{ number_format($m['csv']['cashflow_value'] ?? 0, 4) }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
@endsection
