<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Google Calendar</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 500px; margin: 4rem auto; padding: 2rem; }
        .success { color: #15803d; background: #f0fdf4; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .btn { display: inline-block; padding: 0.5rem 1rem; background: #2563eb; color: white; border-radius: 4px; margin-top: 1rem; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <h1>Google Calendar</h1>
    @if(session('success'))
        <div class="success">{{ session('success') }}</div>
    @endif
    @if($connected)
        <p>Calendario conectado.</p>
        <a href="{{ route('admin.google-calendar.connect') }}" class="btn">Reconectar (revocar y autorizar de nuevo)</a>
    @else
        <p>No hay calendario conectado.</p>
        <a href="{{ route('admin.google-calendar.connect') }}" class="btn">Conectar con Google</a>
    @endif
    <p style="margin-top: 1.5rem;"><a href="{{ route('admin.google-calendar.debug') }}" style="font-size: 0.875rem; color: #6b7280;">Diagnostico OAuth</a></p>
</body>
</html>
