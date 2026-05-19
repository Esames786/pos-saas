@php
    $class = match($status) {
        'active'    => 'bg-success',
        'pending'   => 'bg-warning text-dark',
        'suspended' => 'bg-danger',
        'cancelled' => 'bg-secondary',
        default     => 'bg-light text-dark',
    };
@endphp

<span class="badge {{ $class }}">{{ ucfirst($status) }}</span>
