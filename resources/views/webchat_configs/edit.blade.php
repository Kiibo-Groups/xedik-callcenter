@extends('layouts.app')
@section('title', 'Edit Webchat')
@section('page-title', 'Edit: ' . $config->window_title)
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

<form action="{{ route('admin.webchat-configs.update', $config) }}" method="POST">
    @csrf @method('PUT')
    <div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start;max-width:100%;overflow:hidden;">

        {{-- Columna izquierda --}}
        <div class="card">
            <div class="card-body">
                @include('webchat_configs._form')
                <div style="margin-top:24px;display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Changes
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
    isEdit: true,
    initialColor: '{{ old('primary_color', $config->primary_color) }}',
    initialIcon: '{{ old('icon', $config->icon) }}',
    initialWelcome: @json(old('welcome_message', $config->welcome_message ?? '')),
    regenerateTokenUrl: '{{ route('admin.webchat-configs.regenerate-token', $config) }}',
    csrfToken: '{{ csrf_token() }}',
};</script>
<script src="{{ asset('js/webchat-config-form.js') }}"></script>
@endpush
@endsection
