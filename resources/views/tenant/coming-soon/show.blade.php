@extends('layouts.app')

@section('title', $module['title'] . ' — Coming Soon')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $module['title'] }}
                    <span class="badge bg-warning text-dark ms-2"><i class="ti ti-clock-hour-4 me-1"></i>Coming Soon</span>
                </h4>
                <h6>{{ $module['category'] }} — Planned ERP Extension</h6>
            </div>
            <div class="page-btn">
                <a href="{{ url('/dashboard') }}" class="btn btn-secondary"><i class="ti ti-arrow-left me-1"></i>Back</a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge bg-warning text-dark"><i class="ti ti-tools me-1"></i>Under development / customization</span>
                            <span class="badge bg-light text-dark border">{{ $module['category'] }}</span>
                        </div>

                        <p class="text-muted mb-4">{{ $module['description'] }}</p>

                        <h6 class="fw-bold mb-2">Planned workflow</h6>
                        <ul class="list-unstyled mb-0">
                            @foreach($module['workflow'] as $step)
                                <li class="mb-2"><i class="ti ti-circle-check text-success me-2"></i>{{ $step }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="ti ti-info-circle me-1"></i>
                    This module is planned as an ERP/manufacturing extension. It is shown here for
                    client roadmap and customization discussion — it is <strong>not yet available</strong>.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="icon-wrap mx-auto mb-3" style="width:56px;height:56px;"><i class="ti ti-rocket"></i></div>
                        <h6 class="fw-bold mb-2">Want this for your business?</h6>
                        <p class="text-muted small mb-3">
                            Request customization or talk to your administrator to prioritize this module
                            for your rollout.
                        </p>
                        <a href="{{ url('/dashboard') }}" class="btn btn-outline-primary btn-sm">Back to Dashboard</a>
                    </div>
                </div>
            </div>
        </div>
@endsection
