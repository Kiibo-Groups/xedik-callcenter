@extends('layouts.app')

@section('title', 'Create Campaign')
@section('page-title', 'Create Campaign')

@section('header-actions')
    <a href="{{ route('campaigns.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Campaigns
    </a>
@endsection

@section('content')
    @include('campaigns._form_tabs', ['action' => route('campaigns.store'), 'campaign' => null])
@endsection
