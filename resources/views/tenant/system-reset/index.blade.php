@extends('layouts.app')

@section('title', 'System Reset')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h1 class="mb-3"><i class="ti ti-alert-triangle text-danger me-2"></i>System Reset</h1>

        @if(session('status'))
            <div class="alert alert-success" style="white-space: pre-line">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif

        <div class="card border-danger mb-3">
            <div class="card-body">
                <p class="fw-bold text-danger mb-2">This permanently deletes ALL transactions. It cannot be undone.</p>
                <div class="row">
                    <div class="col-md-6">
                        <h2 class="h6 text-danger">Deleted forever</h2>
                        <ul class="small mb-0">
                            <li>All sales, payments, returns, KOTs and cancellations</li>
                            <li>All shifts and daily closings</li>
                            <li>All accounting journals and cash/bank movements</li>
                            <li>Customer / supplier ledgers and payments, expenses</li>
                            <li>All purchasing (POs, GRNs, bills, returns)</li>
                            <li>All stock movement, balances, counts and transfers</li>
                            <li>Kitchen / manufacturing transactions, print jobs</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h2 class="h6 text-success">Kept safe</h2>
                        <ul class="small mb-0">
                            <li>Menu: categories, products, prices, combos, modifiers, recipes</li>
                            <li>Customers with their address book, suppliers</li>
                            <li>Users, roles and permissions</li>
                            <li>Branches, terminals, floors, tables, waiters</li>
                            <li>Printers, kitchen routing, receipt layouts, paired agents</li>
                            <li>Payment methods, delivery channels/riders, chart of accounts, settings</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ url('/system-reset') }}" class="card">
            @csrf
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label for="confirm_code" class="form-label required">Type your business code to confirm: <code>{{ $tenantCode }}</code></label>
                    <input id="confirm_code" name="confirm_code" class="form-control" autocomplete="off" placeholder="{{ $tenantCode }}">
                </div>
                <div class="col-md-6">
                    <label for="password" class="form-label required">Your password</label>
                    <input id="password" name="password" type="password" class="form-control" autocomplete="current-password">
                </div>
                <div class="col-12 form-check ms-2">
                    <input class="form-check-input" type="checkbox" id="backup_ack" name="backup_ack" value="1">
                    <label class="form-check-label" for="backup_ack">I confirm a database backup was taken and I accept that all transactions will be permanently deleted.</label>
                </div>
                <div class="col-12">
                    <button class="btn btn-danger" onclick="return confirm('FINAL WARNING: every transaction will be permanently deleted. Continue?')">
                        <i class="ti ti-trash me-1"></i>Reset System Now
                    </button>
                    <a href="{{ url('/dashboard') }}" class="btn btn-light">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
