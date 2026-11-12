<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>K-FOS — Add Test User</title>
</head>
<body>
    <main>
        <h1>Add Development Test User</h1>

        <p>
            Local testing only.
            Do not enter real employee information.
        </p>

        @if (session('success'))
            <p role="status">
                {{ session('success') }}
            </p>
        @endif

        @if ($errors->any())
            <div role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST"
              action="{{ route('kfos.auth.dev.users.store') }}">
            @csrf

            <div>
                <label for="employee_id">Test Employee ID</label>
                <input
                    id="employee_id"
                    name="employee_id"
                    type="text"
                    value="{{ old('employee_id') }}"
                    required
                    maxlength="100"
                    autocomplete="off"
                >
            </div>

            <div>
                <label for="name">Employee Name</label>
                <input
                    id="name"
                    name="name"
                    type="text"
                    value="{{ old('name') }}"
                    required
                >
            </div>

            <div>
                <label for="position">Position</label>
                <input
                    id="position"
                    name="position"
                    type="text"
                    value="{{ old('position') }}"
                    required
                >
            </div>

            <div>
                <label for="password">Initial Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    minlength="16"
                    autocomplete="new-password"
                >
            </div>

            <div>
                <label for="password_confirmation">
                    Confirm Password
                </label>
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    required
                    minlength="16"
                    autocomplete="new-password"
                >
            </div>

            <button type="submit">
                Create Test User
            </button>
        </form>

        <p>
            <a href="{{ route('kfos.auth.dev.home') }}">
                Return to Development Home
            </a>
        </p>
    </main>
</body>
</html>
