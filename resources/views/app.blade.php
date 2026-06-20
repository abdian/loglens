<!DOCTYPE html>
<html lang="{{ $boot['locale'] }}" dir="{{ $boot['dir'] }}" data-theme="{{ $boot['theme'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>LogLens</title>
    {{-- Boot config as inert JSON (no inline script execution → strict-CSP safe). --}}
    <script type="application/json" id="loglens-boot">@json($boot)</script>
    <link rel="stylesheet" href="{{ $assetBase }}/app.css">
</head>
<body class="loglens-body">
    <div id="loglens-app"></div>
    <noscript>LogLens requires JavaScript.</noscript>
    <script type="module" src="{{ $assetBase }}/app.js"></script>
</body>
</html>
