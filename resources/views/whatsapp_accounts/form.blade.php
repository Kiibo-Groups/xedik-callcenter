@extends('layouts.app')

@section('title', isset($account) ? 'Edit WhatsApp Account' : 'New WhatsApp Account')
@section('page-title', isset($account) ? 'Edit WhatsApp Account' : 'New WhatsApp Account')
@section('page-subtitle', 'Configure WhatsApp Business API credentials')

@section('content')

@if($errors->any())
    <div class="alert alert-danger">
        <ul style="margin:0;padding-left:16px;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@php $isEdit = isset($account) && $account->exists; @endphp

<form method="POST"
      action="{{ $isEdit ? route('whatsapp-accounts.update', $account) : route('whatsapp-accounts.store') }}">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="card" style="padding:24px;margin-bottom:20px;">

        {{-- Display Name --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
                <label class="form-label">Display Name <span class="required-star">*</span></label>
                <input type="text" name="display_name" class="form-control"
                       value="{{ old('display_name', $account->display_name ?? '') }}"
                       placeholder="e.g. Sales WhatsApp">
                @error('display_name') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="form-label">Phone Number <span class="required-star">*</span></label>
                <input type="text" name="phone_number" class="form-control"
                       value="{{ old('phone_number', $account->phone_number ?? '') }}"
                       placeholder="+52 55 1234 5678">
                @error('phone_number') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
            </div>
        </div>

        {{-- Meta API Credentials --}}
        <div style="border-top:1px solid var(--border);margin-bottom:20px;padding-top:20px;">
            <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px;">
                Meta API Credentials
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <label class="form-label">Phone Number ID <span class="required-star">*</span></label>
                    <input type="text" name="phone_number_id" class="form-control"
                           value="{{ old('phone_number_id', $account->phone_number_id ?? '') }}"
                           placeholder="Meta phone_number_id">
                    @error('phone_number_id') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label">WABA ID <span class="required-star">*</span></label>
                    <input type="text" name="waba_id" class="form-control"
                           value="{{ old('waba_id', $account->waba_id ?? '') }}"
                           placeholder="WhatsApp Business Account ID">
                    @error('waba_id') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                <div>
                    <label class="form-label">
                        Access Token <span class="required-star">*</span>
                        @if($isEdit)
                            <span style="color:var(--text-muted);font-size:11px;">(leave blank to keep current)</span>
                        @endif
                    </label>
                    <input type="password" name="access_token" class="form-control"
                           placeholder="{{ $isEdit ? '••••••••••••••••' : 'Meta permanent access token' }}"
                           autocomplete="new-password">
                    @error('access_token') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label">
                        App Secret
                        @if(!$isEdit) <span class="required-star">*</span>
                        @else <span style="color:var(--text-muted);font-size:11px;">(leave blank to keep current)</span>
                        @endif
                    </label>
                    <input type="password" name="app_secret" class="form-control"
                           placeholder="{{ $isEdit ? '••••••••••••••••' : 'Required — Meta App Secret for signature verification' }}"
                           autocomplete="new-password">
                    @if(!$isEdit)
                        <div style="font-size:11px;color:#d97706;margin-top:4px;">
                            ⚠️ Required. Without this, the webhook will reject all incoming messages.
                            Find it in Meta Dashboard → App → Settings → Basic → App Secret.
                        </div>
                    @endif
                    @error('app_secret') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">Verify Token <span class="required-star">*</span></label>
                    <input type="text" name="verify_token" class="form-control"
                           value="{{ old('verify_token', $account->verify_token ?? '') }}"
                           placeholder="Token used to verify the webhook URL">
                    @error('verify_token') <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="form-label">Queue</label>
                    <select name="omni_queue_id" class="form-control">
                        <option value="">— No queue —</option>
                        @foreach($queues as $queue)
                            <option value="{{ $queue->id }}"
                                {{ old('omni_queue_id', $account->omni_queue_id ?? '') == $queue->id ? 'selected' : '' }}>
                                {{ $queue->name }} ({{ ucfirst($queue->type) }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Status --}}
        <div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="is_active" value="1"
                       {{ old('is_active', $account->is_active ?? true) ? 'checked' : '' }}>
                Active
            </label>
        </div>

    </div>

    {{-- Actions --}}
    <div style="display:flex;align-items:center;gap:12px;">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Save
        </button>
        <a href="{{ route('whatsapp-accounts.index') }}" class="btn btn-secondary">Back</a>
    </div>

</form>

@endsection
