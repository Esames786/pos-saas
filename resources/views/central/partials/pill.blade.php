{{-- MASTER-UNIVERSE-1: a tenant status as a coloured pill, in plain words. --}}
@php
    $pillWords = ['active' => 'Working', 'pending' => 'Waiting to start', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled'];
@endphp
<span class="mu-pill is-{{ in_array($status, ['active', 'pending', 'suspended', 'cancelled'], true) ? $status : 'cancelled' }}" title="Status: {{ $status }}">{{ $pillWords[$status] ?? ucfirst((string) $status) }}</span>
