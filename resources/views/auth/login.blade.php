<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — XEDIK Contact Suite</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #080c14;
            background-image: 
                radial-gradient(circle at 50% 15%, rgba(37, 99, 235, 0.18) 0%, rgba(8, 12, 20, 0) 60%),
                radial-gradient(circle at 10% 90%, rgba(30, 64, 175, 0.08) 0%, transparent 40%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #f8fafc;
            -webkit-font-smoothing: antialiased;
        }

        /* Subtle ambient grid pattern */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image: 
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        .login-wrap {
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 10;
        }

        /* Brand Header */
        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }
        .login-logo-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 58px;
            height: 58px;
            border-radius: 16px;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.35);
            margin-bottom: 16px;
            backdrop-filter: blur(12px);
        }
        .login-logo-badge img {
            width: 38px;
            height: 38px;
            object-fit: contain;
        }
        .brand-title-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .login-header h1 {
            color: #ffffff;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .login-header .tag-pro {
            background: rgba(37, 99, 235, 0.25);
            color: #60a5fa;
            border: 1px solid rgba(96, 165, 250, 0.3);
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            letter-spacing: 0.05em;
        }
        .login-header p {
            color: #94a3b8;
            font-size: 13.5px;
            margin-top: 6px;
            font-weight: 400;
        }

        /* Executive Glass Card */
        .login-card {
            background: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 18px;
            padding: 34px 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.03);
            position: relative;
            overflow: hidden;
        }

        /* Top Accent Hairline */
        .login-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, #38bdf8 0%, #2563eb 50%, #1d4ed8 100%);
        }

        .login-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #f8fafc;
            margin-bottom: 4px;
            letter-spacing: -0.01em;
        }
        .login-card-subtitle {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 18px;
        }
        .form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 7px;
            letter-spacing: -0.01em;
        }
        .input-wrap {
            position: relative;
        }
        .input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 16px;
            transition: color .15s;
        }
        .form-control {
            width: 100%;
            padding: 11px 14px 11px 42px;
            background: #162035;
            border: 1px solid #23314d;
            border-radius: 10px;
            font-size: 13.5px;
            color: #f8fafc;
            outline: none;
            transition: all .15s ease;
            font-family: inherit;
        }
        .form-control:focus {
            border-color: #3b82f6;
            background: #19253d;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.22);
        }
        .form-control:focus + i,
        .input-wrap:focus-within i {
            color: #60a5fa;
        }
        .form-control::placeholder {
            color: #475569;
        }

        .remember-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            font-size: 12.5px;
        }
        .checkbox-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: #94a3b8;
            user-select: none;
        }
        .checkbox-wrap input {
            accent-color: #2563eb;
            width: 16px;
            height: 16px;
            border-radius: 4px;
            cursor: pointer;
        }
        .checkbox-wrap:hover span {
            color: #cbd5e1;
        }

        .btn-login {
            width: 100%;
            padding: 12px 18px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: inherit;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
            transform: translateY(-1px);
        }
        .btn-login:active {
            transform: translateY(0);
        }

        .error-banner {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.25);
            border-radius: 10px;
            padding: 11px 14px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #fca5a5;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .login-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 11.5px;
            color: #475569;
            letter-spacing: 0.02em;
        }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-header">
        <div class="login-logo-badge">
            <img src="{{ asset('images/xedik-logo.svg') }}" alt="XEDIK">
        </div>
        <div class="brand-title-wrap">
            <h1>XEDIK</h1>
            <span class="tag-pro">SUITE</span>
        </div>
        <p>Enterprise Contact Center &amp; AI Communications</p>
    </div>

    <div class="login-card">
        <div class="login-card-title">Sign in to workspace</div>
        <div class="login-card-subtitle">Enter your credentials to access the console</div>

        @if(isset($errors) && $errors->any())
            <div class="error-banner">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('login.post') }}">
            @csrf
            <div class="form-group">
                <label class="form-label">Email address</label>
                <div class="input-wrap">
                    <i class="bi bi-envelope"></i>
                    <input type="email" name="email" class="form-control"
                           value="{{ old('email') }}" placeholder="agent@xedik.com" required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="input-wrap">
                    <i class="bi bi-lock"></i>
                    <input type="password" name="password" class="form-control"
                           placeholder="••••••••••••" required>
                </div>
            </div>

            <div class="remember-row">
                <label class="checkbox-wrap">
                    <input type="checkbox" name="remember" value="1">
                    <span>Remember this session</span>
                </label>
            </div>

            <button type="submit" class="btn-login">
                <span>Continue to Console</span>
                <i class="bi bi-arrow-right"></i>
            </button>
        </form>

        <div class="login-footer">
            &copy; {{ date('Y') }} XEDIK &middot; Enterprise Cloud Platform
        </div>
    </div>
</div>
</body>
</html>
