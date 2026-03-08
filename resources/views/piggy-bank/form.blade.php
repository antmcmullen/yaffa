@extends('template.layouts.page')

@section('title_postfix', __('Piggy Banks'))

@section('content_container_classes', 'container-lg')

@section('content_header', __('Piggy Banks'))

@section('content')
@if(isset($piggyBank))
<form
    accept-charset="UTF-8"
    action="{{ route('piggy-bank.update', $piggyBank) }}"
    autocomplete="off"
    dusk="form-piggy-bank"
    method="POST"
>
@method('PATCH')
@else
<form
    accept-charset="UTF-8"
    action="{{ route('piggy-bank.store') }}"
    autocomplete="off"
    dusk="form-piggy-bank"
    method="POST"
>
@endif
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                @if(isset($piggyBank->id))
                    {{ __('Modify piggy bank') }}
                @else
                    {{ __('Add piggy bank') }}
                @endif
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <label for="name" class="col-form-label col-sm-3">
                    {{ __('Name') }}
                </label>
                <div class="col-sm-9">
                    <input
                        class="form-control"
                        dusk="form-piggy-bank-field-name"
                        id="name"
                        name="name"
                        type="text"
                        value="{{ old('name', $piggyBank->name ?? '') }}"
                    >
                </div>
            </div>

            <div class="row mb-3">
                <label for="target_amount" class="col-form-label col-sm-3">
                    {{ __('Target amount') }}
                </label>
                <div class="col-sm-9">
                    <input
                        class="form-control"
                        dusk="form-piggy-bank-field-target-amount"
                        id="target_amount"
                        min="0"
                        name="target_amount"
                        step="0.01"
                        type="number"
                        value="{{ old('target_amount', isset($piggyBank) ? $piggyBank->target_amount : '') }}"
                    >
                </div>
            </div>

            <div class="row mb-3">
                <label for="current_amount" class="col-form-label col-sm-3">
                    {{ __('Current amount') }}
                </label>
                <div class="col-sm-9">
                    <input
                        class="form-control"
                        dusk="form-piggy-bank-field-current-amount"
                        id="current_amount"
                        min="0"
                        name="current_amount"
                        step="0.01"
                        type="number"
                        value="{{ old('current_amount', isset($piggyBank) ? $piggyBank->current_amount : '0') }}"
                    >
                </div>
            </div>

            <div class="row mb-3">
                <label for="start_date" class="col-form-label col-sm-3">
                    {{ __('Start date') }}
                </label>
                <div class="col-sm-9">
                    <input
                        class="form-control"
                        dusk="form-piggy-bank-field-start-date"
                        id="start_date"
                        name="start_date"
                        type="date"
                        value="{{ old('start_date', isset($piggyBank) ? $piggyBank->start_date?->format('Y-m-d') : '') }}"
                    >
                </div>
            </div>

            <div class="row mb-3">
                <label for="target_date" class="col-form-label col-sm-3">
                    {{ __('Target date') }}
                </label>
                <div class="col-sm-9">
                    <input
                        class="form-control"
                        dusk="form-piggy-bank-field-target-date"
                        id="target_date"
                        name="target_date"
                        type="date"
                        value="{{ old('target_date', isset($piggyBank) ? $piggyBank->target_date?->format('Y-m-d') : '') }}"
                    >
                </div>
            </div>

            <div class="row mb-3">
                <label for="notes" class="col-form-label col-sm-3">
                    {{ __('Notes') }}
                </label>
                <div class="col-sm-9">
                    <textarea
                        class="form-control"
                        dusk="form-piggy-bank-field-notes"
                        id="notes"
                        name="notes"
                        rows="3"
                    >{{ old('notes', $piggyBank->notes ?? '') }}</textarea>
                </div>
            </div>

            <div class="row mb-3">
                <label for="active" class="col-form-label col-sm-3">
                    {{ __('Active') }}
                </label>
                <div class="col-sm-9">
                    <input
                        id="active"
                        class="form-check-input"
                        name="active"
                        type="checkbox"
                        value="1"
                        @if (old())
                            @if (old('active') == '1')
                                checked="checked"
                            @endif
                        @elseif(isset($piggyBank))
                            @if ($piggyBank->active)
                                checked="checked"
                            @endif
                        @else
                            checked="checked"
                        @endif
                    >
                </div>
            </div>
        </div>
        <div class="card-footer">
            @csrf

            <input class="btn btn-primary" type="submit" value="{{ __('Save') }}">
            <a href="{{ route('piggy-bank.index') }}" class="btn btn-secondary cancel confirm-needed">{{ __('Cancel') }}</a>
        </div>
    </div>
</form>
@stop
