<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>K-FOS — Development Authentication</title>
</head>

<body>
    <main>

        <h1>K-FOS Authentication Test</h1>

        <p>
            This page is for local development testing only.
            It is not the final approved K-FOS dashboard.
        </p>

        <hr>

        <h2>Authenticated Account</h2>

        <p>
            <strong>Login ID:</strong>
            {{ $user->login_id }}
        </p>

        <p>
            <strong>Account Status:</strong>
            {{ $user->is_active ? 'Active' : 'Inactive' }}
        </p>

        <p>
            <strong>System Administrator:</strong>
            {{ $user->is_system_admin ? 'Yes' : 'No' }}
        </p>

        <p>
            <strong>Development Only:</strong>
            {{ $user->is_development_only ? 'Yes' : 'No' }}
        </p>

        <hr>

        <h2>Development User Management</h2>

        @if (
            $user->login_id === 'KFOS_ADMIN' &&
            $user->is_active &&
            $user->is_system_admin &&
            $user->is_development_only
        )

            <p>
                <a href="{{ route('kfos.auth.dev.users.create') }}">
                    Add Test User
                </a>
            </p>

            <p>
                <a href="{{ route('kfos.auth.dev.users.index') }}">
                    Manage Test Users
                </a>
            </p>

        @else

            <p>
                User Management is unavailable for this account.
            </p>

        @endif

        <hr>

        <h2>Session Management</h2>

        <form
            method="POST"
            action="{{ route('kfos.auth.dev.logout') }}"
        >

            @csrf

            <button type="submit">
                Log Out
            </button>

        </form>

    </main>
</body>
</html>
