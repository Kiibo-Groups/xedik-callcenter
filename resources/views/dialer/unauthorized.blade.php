<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
        .card { background: #fff; border-radius: 12px; padding: 40px 32px; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { font-size: 20px; font-weight: 700; color: #111827; margin-bottom: 8px; }
        p { font-size: 14px; color: #6b7280; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🔒</div>
        <h1>Access Denied</h1>
        <p>{{ $message }}</p>
    </div>
</body>
</html>
