{{-- Shared form partial for Manufacturing Customer create/edit --}}
<form method="POST"
      action="{{ $customer ? url('/manufacturing/customers/' . $customer->id) : url('/manufacturing/customers') }}"
      novalidate>
    @csrf
    @if($customer)
        @method('PUT')
    @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <div class="card-header"><h6 class="mb-0">Identity</h6></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="code" class="form-label required">Code</label>
                <input id="code" name="code" required
                       class="form-control @error('code') is-invalid @enderror"
                       value="{{ old('code', $customer?->code ?? $nextCode) }}"
                       placeholder="{{ $nextCode ?? 'MFG-CUST-0001' }}">
                <div class="form-text">Auto-generated if left blank on create.</div>
                @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-8">
                <label for="name" class="form-label required">Customer / Project Name</label>
                <input id="name" name="name" required
                       class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $customer?->name) }}">
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="company_name" class="form-label">Company Name</label>
                <input id="company_name" name="company_name"
                       class="form-control @error('company_name') is-invalid @enderror"
                       value="{{ old('company_name', $customer?->company_name) }}">
                @error('company_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="contact_person" class="form-label">Contact Person</label>
                <input id="contact_person" name="contact_person"
                       class="form-control @error('contact_person') is-invalid @enderror"
                       value="{{ old('contact_person', $customer?->contact_person) }}">
                @error('contact_person') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><h6 class="mb-0">Contact Details</h6></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label for="phone" class="form-label">Phone</label>
                <input id="phone" name="phone"
                       class="form-control @error('phone') is-invalid @enderror"
                       value="{{ old('phone', $customer?->phone) }}">
                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="mobile" class="form-label">Mobile</label>
                <input id="mobile" name="mobile"
                       class="form-control @error('mobile') is-invalid @enderror"
                       value="{{ old('mobile', $customer?->mobile) }}">
                @error('mobile') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="email" class="form-label">Email</label>
                <input id="email" type="email" name="email"
                       class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email', $customer?->email) }}">
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="city" class="form-label">City</label>
                <input id="city" name="city"
                       class="form-control @error('city') is-invalid @enderror"
                       value="{{ old('city', $customer?->city) }}">
                @error('city') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="country" class="form-label">Country</label>
                <input id="country" name="country"
                       class="form-control @error('country') is-invalid @enderror"
                       value="{{ old('country', $customer?->country ?? 'Pakistan') }}">
                @error('country') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="tax_number" class="form-label">Tax Number / NTN</label>
                <input id="tax_number" name="tax_number"
                       class="form-control @error('tax_number') is-invalid @enderror"
                       value="{{ old('tax_number', $customer?->tax_number) }}">
                @error('tax_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="address" class="form-label">Address</label>
                <textarea id="address" name="address" rows="2"
                          class="form-control @error('address') is-invalid @enderror">{{ old('address', $customer?->address) }}</textarea>
                @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><h6 class="mb-0">Settings</h6></div>
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required
                        class="form-select @error('status') is-invalid @enderror">
                    <option value="active"   @selected(old('status', $customer?->status ?? 'active') === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $customer?->status) === 'inactive')>Inactive</option>
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-9">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2"
                          class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $customer?->notes) }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary">
            {{ $customer ? 'Update Customer' : 'Save Customer' }}
        </button>
        <a href="{{ url('/manufacturing/customers' . ($customer ? '/' . $customer->id : '')) }}"
           class="btn btn-light ms-2">Cancel</a>
    </div>
</form>
