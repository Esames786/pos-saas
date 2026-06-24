@extends('layouts.app')

@section('title', 'Manufacturing Posting Settings')

@section('content')
@php
    $required = array_filter($fields, fn ($m) => $m['required']);
    $optional = array_filter($fields, fn ($m) => ! $m['required']);
@endphp

        <div class="page-header">
            <div class="page-title">
                <h4>Manufacturing Posting Settings</h4>
                <h6>Account mapping &amp; inventory policy for future manufacturing accounting</h6>
            </div>
            <div class="page-btn">
                @can('tenant.manufacturing.posting-settings.edit')
                    <a href="{{ url('/manufacturing/posting-settings/edit') }}" class="btn btn-primary">
                        <i class="ti ti-edit me-1"></i>Edit Settings
                    </a>
                @endcan
            </div>
        </div>

        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="ti ti-alert-triangle fs-18 mt-1"></i>
            <div>
                <strong>Phase A — configuration only.</strong>
                These settings are <strong>stored</strong> so a future posting layer can read them, but
                <strong>no manufacturing posting code exists yet</strong>. Saving or enabling these settings does
                <strong>not</strong> create any journal entry, stock movement, or COGS — and does not change any
                production, WIP, consumption, scrap or finished-goods record.
            </div>
        </div>

        <div class="row g-3 mb-1">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center gap-3">
                    <span class="rounded p-3 {{ $setting->is_enabled ? 'bg-success' : 'bg-secondary' }} bg-opacity-10">
                        <i class="ti ti-power fs-22 {{ $setting->is_enabled ? 'text-success' : 'text-secondary' }}"></i>
                    </span>
                    <div>
                        <div class="text-muted small">Settings status</div>
                        <div class="fw-bold fs-5">{{ $setting->is_enabled ? 'Enabled' : 'Disabled' }}</div>
                    </div>
                </div></div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center gap-3">
                    <span class="rounded p-3 {{ $setting->canPost() ? 'bg-success' : 'bg-warning' }} bg-opacity-10">
                        <i class="ti ti-checklist fs-22 {{ $setting->canPost() ? 'text-success' : 'text-warning' }}"></i>
                    </span>
                    <div>
                        <div class="text-muted small">Posting readiness</div>
                        <div class="fw-bold fs-5">{{ $setting->canPost() ? 'Ready' : 'Not Ready' }}</div>
                    </div>
                </div></div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-5">
                @include('tenant.manufacturing.posting-settings.partials.readiness-checklist', ['setting' => $setting, 'fields' => $fields])
            </div>
            <div class="col-lg-7">
                @include('tenant.manufacturing.posting-settings.partials.account-mapping-table', ['fields' => $required, 'setting' => $setting, 'title' => 'Required account mappings'])
                <div class="mt-3">
                    @include('tenant.manufacturing.posting-settings.partials.account-mapping-table', ['fields' => $optional, 'setting' => $setting, 'title' => 'Optional account mappings (Phase A)'])
                </div>
                <div class="card mt-3">
                    <div class="card-header"><h6 class="mb-0">Inventory policy</h6></div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr><td>Negative stock policy</td><td>{{ str_replace('_', ' ', $setting->negative_stock_policy) }}</td></tr>
                                <tr><td>Costing method</td><td>{{ str_replace('_', ' ', $setting->costing_method) }}</td></tr>
                                <tr><td>Finished-goods cost source</td><td>{{ str_replace('_', ' ', $setting->fg_cost_source) }}</td></tr>
                                <tr><td>Labour absorption</td><td>{{ $setting->labour_absorption_enabled ? 'Enabled' : 'Disabled' }}</td></tr>
                                <tr><td>Overhead absorption</td><td>{{ $setting->overhead_absorption_enabled ? 'Enabled' : 'Disabled' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($setting->notes)
                    <div class="card mt-3"><div class="card-body"><div class="text-muted small mb-1">Notes</div>{{ $setting->notes }}</div></div>
                @endif
            </div>
        </div>
@endsection
