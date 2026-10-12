@extends('layouts.app')
@section('title', 'Add Webchat')
@section('page-title', 'Add Webchat Configuration')
@section('page-subtitle', 'Webchat')

@section('header-actions')
    <a href="{{ route('admin.webchat-configs.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back
    </a>
@endsection

@section('content')

@if($errors->any())
    <div class="alert alert-danger" style="margin-bottom:16px;">
        <ul style="margin:0;padding-left:16px;">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<form action="{{ route('admin.webchat-configs.store') }}" method="POST">
    @csrf
    <div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start;max-width:100%;overflow:hidden;">

        {{-- Columna izquierda: tabs + campos --}}
        <div class="card">
            <div class="card-body">
                @include('webchat_configs._form')
                <div style="margin-top:24px;display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Create Configuration
                    </button>
                    <a href="{{ route('admin.webchat-configs.index') }}" class="btn btn-secondary">Cancel</a>
                </div>
            </div>
        </div>

        {{-- Columna derecha: preview sticky --}}
        @include('webchat_configs._preview')

    </div>
</form>

@push('scripts')
<script>window.WebchatFormConfig = {
    isEdit: false,
    initialColor: '{{ old('primary_color', '#534AB7') }}',
    initialIcon: '{{ old('icon', 'bi-chat-dots-fill') }}',
    initialWelcome: '',
};</script>
<script src="{{ asset('js/webchat-config-form.js') }}"></script>
@endpush
@endsection
