<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">
    <title>K-FOS — Development Users</title>
</head>
<body>
<main>
    <h1>Development User Management</h1>

    <p>
        Local synthetic test accounts only.
        Employee employment status is managed separately.
    </p>

    @if (session('success'))
        <p role="status">{{ session('success') }}</p>
    @endif

    @if ($errors->any())
        <div role="alert">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <p>
        <a href="{{ route('kfos.auth.dev.users.create') }}">
            Add Test User
        </a>
    </p>

    <table border="1" cellpadding="8">
        <thead>
            <tr>
                <th>Login ID</th>
                <th>Name</th>
                <th>Account Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->login_id }}</td>
                    <td>{{ $user->name }}</td>
                    <td>
                        {{ $user->is_active
                            ? 'Active' : 'Inactive' }}
                    </td>
                    <td>
                        @if (
                            $user->login_id === 'KFOS_ADMIN' ||
                            $user->is_system_admin
                        )
                            Protected administrator
                        @else
                            <form method="POST"
                                  action="{{ route(
                                      'kfos.auth.dev.users.status',
                                      $user->id
                                  ) }}">
                                @csrf

                                <input
                                    type="hidden"
                                    name="status"
                                    value="{{ $user->is_active
                                        ? 'inactive' : 'active' }}"
                                >

                                <label>
                                    Reason
                                    <input
                                        type="text"
                                        name="reason"
                                        maxlength="1000"
                                        required
                                    >
                                </label>

                                <button type="submit">
                                    {{ $user->is_active
                                        ? 'Deactivate'
                                        : 'Activate' }}
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">
                        No development users found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <p>
        <a href="{{ route('kfos.auth.dev.home') }}">
            Return to Development Home
        </a>
    </p>
</main>
</body>
</html>
