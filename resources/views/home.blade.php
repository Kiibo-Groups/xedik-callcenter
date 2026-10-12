@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Overview')
@section('page-subtitle', 'Welcome to XEDIK Contact Suite')
@section('content')
<div style="display:flex;flex-direction:column;gap:24px;">
    <div class="card" style="padding:28px 32px;background:linear-gradient(135deg, rgba(37,99,235,0.08) 0%, rgba(15,23,42,0.02) 100%);border:1px solid rgba(37,99,235,0.15);">
        <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
            <div style="width:54px;height:54px;border-radius:14px;background:linear-gradient(135deg,#2563eb,#1d4ed8);display:flex;align-items:center;justify-content:center;color:white;font-size:24px;box-shadow:0 8px 18px rgba(37,99,235,0.3);">
                <i class="bi bi-shield-check"></i>
            </div>
            <div>
                <h2 style="font-size:20px;font-weight:700;color:var(--text-strong);letter-spacing:-0.02em;">Welcome back, {{ Auth::user()->name }}</h2>
                <p style="font-size:13px;color:var(--text-muted);margin-top:2px;">XEDIK Enterprise Communications &amp; Contact Center Suite</p>
            </div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-headset"></i></div>
            <div>
                <div class="stat-label">System Mode</div>
                <div class="stat-value" style="font-size:18px;">Operational</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="stat-label">Role</div>
                <div class="stat-value" style="font-size:18px;text-transform:capitalize;">{{ Auth::user()->role?->name ?? 'User' }}</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-building"></i></div>
            <div>
                <div class="stat-label">Organization</div>
                <div class="stat-value" style="font-size:18px;">{{ Auth::user()->tenant?->name ?? 'System' }}</div>
            </div>
        </div>
    </div>
</div>
@endsection