<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy"
          content="default-src 'self';
                   script-src 'self' 'unsafe-inline' https://js.pusher.com https://cdn.jsdelivr.net;
                   connect-src 'self' wss://*.pusher.com https://*.pusher.com https:;
                   style-src 'self' 'unsafe-inline';
                   img-src 'self' data:;">
    <title>Webchat Preview — {{ $config->window_title }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; margin: 0; padding: 40px; background: #f5f5f5; color: #333; }
        .container { max-width: 720px; margin: 0 auto; }
        .notice { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px 16px; margin-bottom: 28px; font-size: 13px; color: #856404; }
        h1 { font-size: 22px; margin-bottom: 16px; }
        .line { height: 13px; background: #ddd; border-radius: 4px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="notice">
            <strong>Preview mode</strong> — This page simulates how the webchat widget looks on your website.
            Share this URL with your team for testing.
        </div>
        <h1>Welcome to our website</h1>
        <div class="line" style="width:90%"></div>
        <div class="line" style="width:75%"></div>
        <div class="line" style="width:85%"></div>
        <div class="line" style="width:60%"></div>
        <br>
        <div class="line" style="width:95%"></div>
        <div class="line" style="width:80%"></div>
        <div class="line" style="width:70%"></div>
    </div>

    <script src="{{ url('/webchat/widget.js') }}"
            data-config="{{ $publicToken }}"
            data-pusher-key="{{ config('broadcasting.connections.pusher.key') }}"
            data-pusher-cluster="{{ config('broadcasting.connections.pusher.options.cluster') }}"></script>
</body>
</html>
