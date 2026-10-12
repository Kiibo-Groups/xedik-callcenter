@extends('layouts.app')

@section('title', 'Add User')
@section('page-title', 'Add User')

@section('header-actions')
    <a href="{{ route('users.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Users
    </a>
@endsection

@section('content')
    @include('users._form_tabs', ['action' => route('users.store'), 'user' => null])
@endsection
