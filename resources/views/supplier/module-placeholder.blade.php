@extends('layouts.supplier')

@section('title', $moduleTitle)

@section('content')
<div class="card border-0 rounded-4 shadow-sm">
    <div class="card-body p-4">
        <h1 class="fs-4 fw-bold mb-2">{{ $moduleTitle }}</h1>

        <p class="text-secondary mb-4">
            This module is coming soon.
        </p>

        <a href="{{ route('supplier.dashboard') }}"
           class="btn btn-success">
            <i class="bi bi-arrow-left me-2" aria-hidden="true"></i>
            Back to Dashboard
        </a>
    </div>
</div>
@endsection