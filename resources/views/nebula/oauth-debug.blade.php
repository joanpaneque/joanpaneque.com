<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug OAuth</title>
    <style>
        body { font-family: monospace; max-width: 700px; margin: 2rem auto; padding: 1rem; background: #1a1a1a; color: #eee; }
        h1 { font-size: 1.25rem; }
        .row { margin: 0.5rem 0; padding: 0.5rem; background: #333; border-radius: 4px; }
        .label { color: #888; }
        .value { color: #4ade80; word-break: break-all; }
        .warning { color: #fbbf24; margin-top: 1rem; }
        a { color: #60a5fa; }
    </style>
</head>
<body>
    <h1>Diagnostico OAuth</h1>
    <p>Esta es la redirect URI que la app envia a Google. Debe coincidir EXACTAMENTE con una de las URIs en Google Cloud Console.</p>

    <div class="row">
        <span class="label">GOOGLE_REDIRECT_URI en .env:</span><br>
        <span class="value">{{ $explicit }}</span>
    </div>
    <div class="row">
        <span class="label">URI construida dinamicamente (scheme + host):</span><br>
        <span class="value">{{ $dynamic }}</span>
    </div>
    <div class="row">
        <span class="label">URI que se USA (la que va a Google):</span><br>
        <span class="value">{{ $used }}</span>
    </div>
    <div class="row">
        <span class="label">Scheme detectado:</span> <span class="value">{{ $scheme }}</span>
    </div>
    <div class="row">
        <span class="label">Host detectado:</span> <span class="value">{{ $host }}</span>
    </div>
    <div class="row">
        <span class="label">URL completa actual:</span><br>
        <span class="value">{{ $url }}</span>
    </div>

    <div class="warning">
        <strong>Si scheme es "http" en produccion (HTTPS):</strong> tu servidor esta detras de un proxy. Configura trusted proxies en Laravel o define GOOGLE_REDIRECT_URI explicitamente en .env con https.
    </div>

    <p style="margin-top: 2rem;"><a href="{{ route('nebula.google-calendar.index') }}">Volver</a></p>
</body>
</html>
