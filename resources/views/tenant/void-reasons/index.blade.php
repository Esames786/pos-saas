@extends('layouts.app')

@section('title', 'Void Reasons')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Void Reasons</h4>
                <h6>Manage reasons for voiding or removing items</h6>
            </div>
            @can('tenant.void-reasons.create')
            <div class="page-btn">
                <a href="{{ url('/void-reasons/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Add Reason
                </a>
            </div>
            @endcan
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Void reasons list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Type</th>
                                <th scope="col">Requires Manager Approval</th>
                                <th scope="col">Active</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($reasons as $reason)
                            <tr>
                                <td>{{ $reason->name }}</td>
                                <td><span class="badge bg-secondary">{{ ucfirst($reason->reason_type) }}</span></td>
                                <td>
                                    @if($reason->requires_manager_approval)
                                        <span class="badge bg-warning text-dark">Yes — PIN Required</span>
                                    @else
                                        <span class="text-muted">No</span>
                                    @endif
                                </td>
                                <td><span class="badge bg-{{ $reason->is_active ? 'success' : 'secondary' }}">{{ $reason->is_active ? 'Active' : 'Inactive' }}</span></td>
                                <td>
                                    @can('tenant.void-reasons.edit')
                                    <a href="{{ url('/void-reasons/' . $reason->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1">
                                        <i class="ti ti-pencil"></i>
                                    </a>
                                    @endcan
                                    @can('tenant.void-reasons.destroy')
                                    <form method="POST" action="{{ url('/void-reasons/' . $reason->id) }}" class="d-inline" onsubmit="return confirm('Delete this void reason?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No void reasons found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection
