@extends('layouts.app')
@section('title', 'Edit Table')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Edit Table — {{ $restaurantTable->table_no }}</h3></div>
                    <div class="col-auto"><a href="{{ url('/restaurant/tables') }}" class="btn btn-outline-secondary">Back</a></div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ url('/restaurant/tables/'.$restaurantTable->id) }}">
                        @csrf @method('PUT')
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Branch <span class="text-danger">*</span></label>
                                <select name="branch_id" class="form-select" required>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected(old('branch_id', $restaurantTable->branch_id) == $branch->id)>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Floor <span class="text-danger">*</span></label>
                                <select name="restaurant_floor_id" class="form-select" required>
                                    @foreach($floors as $floor)
                                        <option value="{{ $floor->id }}" @selected(old('restaurant_floor_id', $restaurantTable->restaurant_floor_id) == $floor->id)>
                                            {{ $floor->branch->name ?? '' }} — {{ $floor->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Table No <span class="text-danger">*</span></label>
                                <input type="text" name="table_no" class="form-control"
                                       value="{{ old('table_no', $restaurantTable->table_no) }}" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Capacity</label>
                                <input type="number" name="capacity" class="form-control"
                                       value="{{ old('capacity', $restaurantTable->capacity) }}" min="1">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    @foreach(['available','occupied','reserved','dirty'] as $s)
                                        <option value="{{ $s }}" @selected(old('status', $restaurantTable->status) === $s)>{{ ucfirst($s) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                           id="isActive" @checked(old('is_active', $restaurantTable->is_active))>
                                    <label class="form-check-label" for="isActive">Active</label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">Update Table</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
