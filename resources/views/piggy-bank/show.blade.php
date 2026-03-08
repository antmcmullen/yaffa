@extends('template.layouts.page')

@section('title_postfix', __('Piggy Banks'))

@section('content_container_classes', 'container-lg')

@section('content_header', __('Piggy Banks'))

@section('content')
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="card-title mb-0">{{ $piggyBank->name }}</div>
            <div>
                <a href="{{ route('piggy-bank.edit', $piggyBank) }}" class="btn btn-primary btn-sm">
                    <i class="fa fa-fw fa-pencil"></i> {{ __('Edit') }}
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-sm-3 fw-bold">{{ __('Status') }}</div>
                <div class="col-sm-9">
                    @if($piggyBank->active)
                        <span class="badge bg-success">{{ __('Active') }}</span>
                    @else
                        <span class="badge bg-secondary">{{ __('Inactive') }}</span>
                    @endif
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-sm-3 fw-bold">{{ __('Target amount') }}</div>
                <div class="col-sm-9">
                    {{ number_format($piggyBank->target_amount, 2) }}
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-sm-3 fw-bold">{{ __('Current amount') }}</div>
                <div class="col-sm-9">
                    {{ number_format($piggyBank->current_amount, 2) }}
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-sm-3 fw-bold">{{ __('Progress') }}</div>
                <div class="col-sm-9">
                    @php
                        $percentage = $piggyBank->percentage;
                        $barClass = $percentage >= 100 ? 'bg-success' : 'bg-primary';
                    @endphp
                    <div class="progress mb-1" style="height: 1.25rem;">
                        <div
                            class="progress-bar {{ $barClass }}"
                            role="progressbar"
                            style="width: {{ $percentage }}%"
                            aria-valuenow="{{ $percentage }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            {{ $percentage }}%
                        </div>
                    </div>
                    <small class="text-muted">
                        {{ number_format($piggyBank->current_amount, 2) }} / {{ number_format($piggyBank->target_amount, 2) }}
                    </small>
                </div>
            </div>

            @if($piggyBank->start_date)
            <div class="row mb-3">
                <div class="col-sm-3 fw-bold">{{ __('Start date') }}</div>
                <div class="col-sm-9">{{ $piggyBank->start_date->format('Y-m-d') }}</div>
            </div>
            @endif

            @if($piggyBank->target_date)
            <div class="row mb-3">
                <div class="col-sm-3 fw-bold">{{ __('Target date') }}</div>
                <div class="col-sm-9">{{ $piggyBank->target_date->format('Y-m-d') }}</div>
            </div>
            @endif

            @if($piggyBank->notes)
            <div class="row mb-3">
                <div class="col-sm-3 fw-bold">{{ __('Notes') }}</div>
                <div class="col-sm-9">{{ $piggyBank->notes }}</div>
            </div>
            @endif
        </div>
        <div class="card-footer">
            <a href="{{ route('piggy-bank.index') }}" class="btn btn-secondary">
                <i class="fa fa-fw fa-arrow-left"></i> {{ __('Back to list') }}
            </a>
        </div>
    </div>
@stop
