@extends('layouts.app')

@section('title', 'Edit User')
@section('page-title', 'Edit User')
@section('page-subtitle', $user->name)

@section('header-actions')
    <a href="{{ route('users.index') }}" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Users
    </a>
@endsection

@section('content')
    @include('users._form_tabs', ['action' => route('users.update', $user), 'user' => $user])
@endsection
