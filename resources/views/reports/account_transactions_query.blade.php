@extends('template.layouts.page')

@section('content')
<div class="container">
    <h1>Account Transactions Query</h1>

    <form method="post" action="{{ route('reports.account_transactions.run') }}">
        @csrf
        <div class="row mb-3">
            <div class="col-md-4">
                <label>Account</label>
                <select name="account_id" class="form-control">
                    @foreach($accounts as $a)
                        <option value="{{ $a->id }}" {{ (isset($selected) && $selected==$a->id) ? 'selected' : '' }}>{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label>Start</label>
                <input name="start" type="date" value="{{ $start ?? old('start') }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>End</label>
                <input name="end" type="date" value="{{ $end ?? old('end') }}" class="form-control" required>
            </div>
            <div class="col-md-2" style="padding-top:24px">
                <button class="btn btn-primary">Run</button>
            </div>
        </div>
    </form>

    @if(isset($rows))
        <div class="mb-3">
            <a href="{{ route('reports.account_transactions.run') }}?download=1&account_id={{ $selected }}&start={{ $start }}&end={{ $end }}" class="btn btn-secondary">Download CSV</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        @foreach(array_keys((array)($rows[0] ?? ['id'=>''])) as $h)
                            <th>{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        <tr>
                            @foreach((array)$r as $c)
                                <td>{{ $c }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
