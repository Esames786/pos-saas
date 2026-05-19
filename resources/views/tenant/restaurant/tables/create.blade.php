@extends('layouts.app')
@section('title', 'Add Table')
@section('content')
<div class="content-wrapper">
    <div class="content">
        <div class="container-fluid">
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col"><h3 class="page-title">Add Table</h3></div>
                    <div class="col-auto"><a href="{{ url('/restaurant/tables') }}" class="btn btn-outline-secondary">Back</a></div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ url('/restaurant/tables') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Branch <span class="text-danger">*</span></label>
                                <select name="branch_id" class="form-select @error('branch_id') is-invalid @enderror" required>
                                    <option value="">Select Branch</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                                @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Floor <span class="text-danger">*</span></label>
                                <select name="restaurant_floor_id" class="form-select @error('restaurant_floor_id') is-invalid @enderror" required>
                                    <option value="">Select Floor</option>
                                    @foreach($floors as $floor)
                                        <option value="{{ $floor->id }}" @selected(old('restaurant_floor_id') == $floor->id)>
                                            {{ $floor->branch->name ?? '' }} — {{ $floor->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('restaurant_floor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Table No <span class="text-danger">*</span></label>
                                <input type="text" name="table_no" class="form-control @error('table_no') is-invalid @enderror"
                                       value="{{ old('table_no') }}" required>
                                @error('table_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Capacity</label>
                                <input type="number" name="capacity" class="form-control" value="{{ old('capacity', 4) }}" min="1">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                           id="isActive" @checked(old('is_active', true))>
                                    <label class="form-check-label" for="isActive">Active</label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">Save Table</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
