@extends('layouts.app')

@section('title', 'SMS Numbers')
@section('page-title', 'SMS Numbers')
@section('page-subtitle', 'SMS numbers assigned to your tenant — link each one to an omnichannel queue')

@section('content')

{{--
    Defensivo: nunca confiar en que el controller haya pasado estas variables.
    Si por cualquier motivo (versión desalineada de controller/vista, ruta
    alternativa, etc.) no llegan, la vista se autocompleta con el mismo valor
    que calcularía el controller (auth()->user()->isSuperAdmin()) y con
    colecciones/valores vacíos seguros — nunca "Undefined variable".
--}}
@php
    $isSuperAdmin     = $isSuperAdmin     ?? auth()->user()->isSuperAdmin();
    $tenants          = $tenants          ?? collect();
    $selectedTenantId = $selectedTenantId ?? null;
    $selectedTenant   = $selectedTenant   ?? ($selectedTenantId ? $tenants->firstWhere('id', $selectedTenantId) : null);
    $channels         = $channels         ?? collect();
    $queues           = $queues           ?? collect();
@endphp

@include('sms._nav')

@if($isSuperAdmin)
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="display:flex;align-items:center;gap:10px;">
        <label class="form-label" style="margin:0;white-space:nowrap;">Acting as tenant:</label>
        <form method="GET" action="{{ route('sms-channels.index') }}" style="display:flex;gap:8px;">
            <select name="tenant_id" class="form-control" onchange="this.form.submit()" style="min-width:260px;">
                <option value="">— Select tenant —</option>
                @foreach($tenants as $tenant)
                    <option value="{{ $tenant->id }}" {{ (string) $selectedTenantId === (string) $tenant->id ? 'selected' : '' }}>{{ $tenant->name }}</option>
                @endforeach
            </select>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-chat-dots-fill icon-primary"></i> SMS Numbers</span>
    </div>
    <div class="table-wrapper">
        @if($isSuperAdmin && !$selectedTenantId)
        <div class="empty-state">
            <i class="bi bi-building"></i>
            <h3>Select a tenant</h3>
            <p>Pick a tenant above to view and configure its SMS numbers.</p>
        </div>
        @elseif($channels->count())
        <table>
            <thead>
                <tr>
                    <th>Display Name</th>
                    <th>Number</th>
                    <th>Status</th>
                    <th>Queue</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($channels as $channel)
                <tr>
                    <td class="cell-strong">{{ $channel->display_name }}</td>
                    <td><code class="cell-code">{{ $channel->address }}</code></td>
                    <td>
                        <span class="badge {{ $channel->is_active ? 'badge-success' : 'badge-danger' }}">
                            {{ $channel->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td colspan="2">
                        <form method="POST" action="{{ route('sms-channels.update', $channel) }}"
                              style="display:flex;gap:8px;align-items:center;">
                            @csrf @method('PUT')
                            <select name="omni_queue_id" class="form-control" style="max-width:260px;">
                                <option value="">— No queue —</option>
                                @foreach($queues as $queue)
                                    <option value="{{ $queue->id }}" {{ $channel->omni_queue_id == $queue->id ? 'selected' : '' }}>
                                        {{ $queue->name }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-outline btn-xs"><i class="bi bi-check-lg"></i> Save</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="empty-state">
            <i class="bi bi-chat-dots"></i>
            <h3>No SMS numbers assigned yet</h3>
            <p>Ask your superadmin to provision an SMS number for your tenant.</p>
        </div>
        @endif
    </div>
</div>

@endsection
