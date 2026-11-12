<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>K-FOS Development Login</title>
</head>
<body>
    <main>
        <h1>K-FOS Development Login</h1>

        <p>Local testing only — not for production use.</p>

        @if ($errors->any())
            <p role="alert">
                {{ $errors->first() }}
            </p>
        @endif

        <form method="POST"
              action="{{ route('kfos.auth.dev.submit') }}">
            @csrf

            <div>
                <label for="employee_id">Login ID</label>
                <input
                    id="employee_id"
                    name="employee_id"
                    type="text"
                    value="{{ old('employee_id') }}"
                    autocomplete="username"
                    required
                >
            </div>

            <div>
                <label for="password">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit">Log In</button>
        </form>
    </main>
</body>
</html>
