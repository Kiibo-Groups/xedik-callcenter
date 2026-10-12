<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lead Detail{{ $phone ? ' — ' . $phone : '' }} · xedik CRM</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
            color: #111827;
            min-height: 100vh;
            padding: 0;
        }

        .header {
            background: #1e293b;
            color: #fff;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header img { width: 36px; height: auto; object-fit: contain; border-radius: 4px; filter: drop-shadow(0 1px 0 white) drop-shadow(0 -1px 0 white) drop-shadow(1px 0 0 white) drop-shadow(-1px 0 0 white); }
        .header-title { font-size: 14px; font-weight: 700; letter-spacing: .3px; }
        .header-sub { font-size: 11px; color: #94a3b8; }

        .container { max-width: 560px; margin: 0 auto; padding: 24px 16px 40px; }

        .alert-success {
            background: #dcfce7; border: 1px solid #86efac; color: #166534;
            border-radius: 8px; padding: 10px 14px; font-size: 13px;
            margin-bottom: 20px; display: flex; align-items: center; gap: 8px;
        }

        .alert-error {
            background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b;
            border-radius: 8px; padding: 10px 14px; font-size: 13px;
            margin-bottom: 20px;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 8px rgba(0,0,0,.08);
            overflow: hidden;
        }

        .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .card-body { padding: 20px; }

        .field-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .field-row:last-child { border-bottom: none; }

        .field-label {
            min-width: 140px;
            flex-shrink: 0;
            font-size: 12px;
            font-weight: 500;
            color: #6b7280;
        }

        .field-value {
            font-size: 14px;
            font-family: monospace;
            color: #111827;
            font-weight: 600;
        }

        .form-input {
            flex: 1;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 7px 10px;
            font-size: 14px;
            color: #111827;
            background: #fff;
            transition: border-color .15s;
            width: 100%;
        }
        .form-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }

        .empty-state {
            padding: 32px 20px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }
        .empty-state .big-icon { font-size: 40px; margin-bottom: 8px; }

        .actions {
            display: flex;
            justify-content: flex-end;
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .btn-save {
            background: #4f46e5;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 28px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-save:hover { background: #4338ca; }
        .btn-save:active { background: #3730a3; }

        .list-name {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 6px;
        }
    </style>
</head>
<body>

<div class="header">
    <img src="{{ asset('images/xedik-logo.svg') }}" alt="XEDIK" width="32" height="32">
    <div>
        <div class="header-title">XEDIK</div>
        <div class="header-sub">Lead Detail</div>
    </div>
</div>

<div class="container">

    @if($saved)
    <div class="alert-success">
        ✓ Changes saved successfully.
    </div>
    @endif

    @if($error)
    <div class="alert-error">
        {{ $error }}
    </div>
    @endif

    @if($lead === null && !$error)
    <div class="card">
        <div class="empty-state">
            <div class="big-icon">🔍</div>
            <div>Lead not found for <strong>{{ $phone }}</strong> in this list.</div>
        </div>
    </div>

    @elseif($lead !== null)

    <form method="POST" action="{{ route('dialer.lead-view.save') }}">
        @csrf
        <input type="hidden" name="phone"        value="{{ $phone }}">
        <input type="hidden" name="lead_list_id" value="{{ $leadList->id }}">
        <input type="hidden" name="token"        value="{{ $token }}">

        <div class="card">
            <div class="card-header">
                Lead Information
                @if($leadList)
                <span class="list-name">· {{ $leadList->name }}</span>
                @endif
            </div>
            <div class="card-body">

                {{-- Phone (read-only) --}}
                <div class="field-row">
                    <span class="field-label">Phone</span>
                    <span class="field-value">{{ $phone }}</span>
                </div>

                {{-- Dynamic custom fields --}}
                @forelse($customFields as $key => $value)
                @php
                    $label = ucwords(str_replace(['_', '-'], ' ', $key));
                    $inputId = 'cf_' . $key;
                @endphp
                <div class="field-row">
                    <label class="field-label" for="{{ $inputId }}">{{ $label }}</label>
                    <input id="{{ $inputId }}" type="text" class="form-input"
                           name="custom_fields[{{ $key }}]"
                           value="{{ old('custom_fields.' . $key, $value) }}">
                </div>
                @empty
                <div style="padding: 16px 0; text-align:center; color:#9ca3af; font-size:13px;">
                    No additional fields available.
                </div>
                @endforelse

            </div>
            <div class="actions">
                <button type="submit" class="btn-save">Save Changes</button>
            </div>
        </div>

    </form>

    @endif

</div>

</body>
</html>
