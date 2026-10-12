@extends('layouts.app')

@section('title', 'Edit Campaign')
@section('page-title', 'Edit Campaign')
@section('page-subtitle', $campaign->name)

@section('header-actions')
    <a href="{{ route('campaigns.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Campaigns
    </a>
@endsection

@section('content')
    @include('campaigns._form_tabs', ['action' => route('campaigns.update', $campaign), 'campaign' => $campaign])
@endsection
