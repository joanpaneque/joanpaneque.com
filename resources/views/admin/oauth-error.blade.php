<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error OAuth</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 500px; margin: 4rem auto; padding: 2rem; }
        .error { color: #b91c1c; background: #fef2f2; padding: 1rem; border-radius: 4px; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <h1>Error de conexion</h1>
    <div class="error">{{ $message }}</div>
    <p><a href="{{ route('admin.google-calendar.connect') }}">Volver a intentar</a></p>
</body>
</html>
