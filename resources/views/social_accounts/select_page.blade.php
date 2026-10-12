@extends('layouts.app')

@section('title', 'Select Page')
@section('page-title', 'Select Facebook Page')
@section('page-subtitle', 'Choose which page to connect to the agent console')

@push('styles')
<style>
.sp-list   { display: flex; flex-direction: column; gap: 10px; max-width: 560px; }
.sp-item   { display: flex; align-items: center; gap: 12px; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); cursor: pointer; transition: border-color .15s, background .15s; }
.sp-item:hover { border-color: var(--primary); background: var(--bg-th); }
.sp-item input { accent-color: var(--primary); }
.sp-icon   { width: 36px; height: 36px; border-radius: 8px; background: #1877F2; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.sp-name   { font-weight: 600; color: var(--text-strong); font-size: 14px; }
.sp-id     { font-size: 12px; color: var(--text-muted); }
.sp-actions { margin-top: 18px; display: flex; gap: 10px; }
</style>
@endpush

@section('content')

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-facebook icon-primary"></i> Your Facebook Pages</span>
    </div>
    <div style="padding: 20px;">
        @if(count($pages))
        <form method="POST" action="{{ route('social-accounts.selectPage') }}">
            @csrf
            <div class="sp-list">
                @foreach($pages as $i => $page)
                <label class="sp-item">
                    <input type="radio" name="page_id" value="{{ $page['id'] }}" {{ $i === 0 ? 'checked' : '' }} required>
                    <span class="sp-icon"><i class="bi bi-facebook"></i></span>
                    <span>
                        <span class="sp-name">{{ $page['name'] }}</span><br>
                        <span class="sp-id">ID: {{ $page['id'] }}</span>
                    </span>
                </label>
                @endforeach
            </div>
            <div class="sp-actions">
                <a href="{{ route('social-accounts.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg"></i> Connect Page</button>
            </div>
        </form>
        @else
        <div class="empty-state">
            <i class="bi bi-facebook"></i>
            <h3>No pages found</h3>
            <p>Your Facebook account has no pages available, or the required permissions were not granted.</p>
            <a href="{{ route('social-accounts.index') }}" class="btn btn-secondary btn-sm">Back</a>
        </div>
        @endif
    </div>
</div>

@endsection
