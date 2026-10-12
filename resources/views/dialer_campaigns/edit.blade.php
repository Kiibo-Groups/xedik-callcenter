@extends('layouts.app')

@section('title', 'Edit Dialer Campaign')
@section('page-title', $dialerCampaign->name ?: ($dialerCampaign->campaign?->name ?? 'Dialer Campaign'))
@section('page-subtitle', strtoupper($dialerCampaign->dialer_type) . ' — ' . ucfirst($dialerCampaign->status))

@section('header-actions')
    <a href="{{ route('dialer-campaigns.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back
    </a>
@endsection

@section('content')
    @include('dialer_campaigns._edit_tabs')
@endsection
