<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Lead{{ $leadList ? ' — ' . $leadList->name : '' }} · xedik CRM</title>
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
            border-radius: 8px; padding: 12px 16px; font-size: 13px;
            margin-bottom: 20px;
        }
        .alert-success strong { display: block; margin-bottom: 2px; font-size: 14px; }
        .lead-id-badge {
            display: inline-block;
            background: #166534;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: .3px;
            margin-top: 4px;
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
        .field-label .required { color: #ef4444; margin-left: 2px; }

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

        .no-fields-note {
            padding: 16px 0;
            text-align: center;
            color: #9ca3af;
            font-size: 13px;
        }
    </style>
</head>
<body>

<div class="header">
    <img src="{{ asset('images/xedik-logo.svg') }}" alt="XEDIK" width="32" height="32">
    <div>
        <div class="header-title">XEDIK</div>
        <div class="header-sub">Add Lead</div>
    </div>
</div>

<div class="container">

    {{-- Page-level error (bad params, archived list, etc.) --}}
    @if($pageError)
    <div class="alert-error">{{ $pageError }}</div>
    @endif

    {{-- Success notice --}}
    @if($added && $leadId)
    <div class="alert-success">
        <strong>✓ Lead added successfully.</strong>
        Lead ID: <span class="lead-id-badge">#{{ $leadId }}</span>
    </div>
    @endif

    {{-- Field validation error --}}
    @if($addError)
    <div class="alert-error">{{ $addError }}</div>
    @endif

    @if(!$pageError)
    <form method="POST" action="{{ route('dialer.lead-add.store') }}">
        @csrf
        <input type="hidden" name="lead_list_id" value="{{ $leadListId }}">
        <input type="hidden" name="token"         value="{{ $token }}">
        <input type="hidden" name="fields"        value="{{ $fieldsParam }}">

        <div class="card">
            <div class="card-header">
                New Lead
                @if($leadList)
                <span class="list-name">· {{ $leadList->name }}</span>
                @endif
            </div>
            <div class="card-body">

                {{-- Phone (always required) --}}
                <div class="field-row">
                    <label class="field-label" for="phone">
                        Phone <span class="required">*</span>
                    </label>
                    <input id="phone" type="text" class="form-input"
                           name="phone"
                           value="{{ $phoneValue }}"
                           placeholder="e.g. 6025551234"
                           autocomplete="off"
                           autofocus>
                </div>

                {{-- Dynamic custom fields from ?fields= param --}}
                {{-- Numeric indices in name= avoid PHP's space→underscore conversion in array keys. --}}
                {{-- The store() method maps values back to the original key names via parseFields(). --}}
                @foreach($fields as $i => $key)
                @php $label = ucwords(str_replace(['_', '-'], ' ', $key)); @endphp
                <div class="field-row">
                    <label class="field-label" for="cf_{{ $i }}">{{ $label }}</label>
                    <input id="cf_{{ $i }}" type="text" class="form-input"
                           name="custom_fields[{{ $i }}]"
                           value=""
                           autocomplete="off">
                </div>
                @endforeach

                @if(empty($fields))
                <div class="no-fields-note">
                    Only phone will be saved. Add <code>?fields=field1,field2</code> to the URL to include custom fields.
                </div>
                @endif

            </div>
            <div class="actions">
                <button type="submit" class="btn-save">Add Lead</button>
            </div>
        </div>

    </form>
    @endif

</div>

</body>
</html>
