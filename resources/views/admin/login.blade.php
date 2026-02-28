<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Google Calendar</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 400px; margin: 4rem auto; padding: 2rem; }
        input { width: 100%; padding: 0.5rem; margin: 0.5rem 0; }
        button { padding: 0.5rem 1rem; cursor: pointer; }
        .error { color: #b91c1c; font-size: 0.875rem; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <h1>Acceso admin</h1>
    <form method="POST" action="{{ route('admin.login.store') }}">
        @csrf
        <label for="admin_secret">Contrasena admin</label>
        <input type="password" name="admin_secret" id="admin_secret" required autofocus>
        @error('admin_secret')
            <p class="error">{{ $message }}</p>
        @enderror
        <button type="submit">Entrar</button>
    </form>
</body>
</html>
