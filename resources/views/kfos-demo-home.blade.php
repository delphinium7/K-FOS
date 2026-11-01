<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>K-FOS | Demo Homepage</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px;
            font-family: Inter, -apple-system,
                BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #18212d;
            background: linear-gradient(
                135deg,
                #fff9f5 0%,
                #fbe4e7 52%,
                #efbbcf 100%
            );
        }

        .home-card {
            width: 100%;
            max-width: 560px;
            padding: 44px 32px;
            border-radius: 30px;
            text-align: center;
            background: rgba(255, 255, 255, 0.42);
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 18px 50px rgba(106, 49, 77, 0.1);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
        }

        h1 {
            margin: 0 0 16px;
            font-size: 30px;
        }

        p {
            color: #626a76;
            line-height: 1.6;
        }

        .logout-button {
            margin-top: 22px;
            padding: 14px 48px;
            border: none;
            border-radius: 999px;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(
                100deg,
                #f39c87,
                #e16b90,
                #b83f78
            );
        }
    </style>
</head>

<body>
    <main class="home-card">
        <h1>Welcome to K-FOS</h1>

        <p>
            Login successful for
            <strong>{{ $employeeId }}</strong>.
        </p>

        <p>
            This is a local development homepage.
            Full employee authentication and permissions
            have not been implemented.
        </p>

        <form
            method="POST"
            action="{{ route('kfos.demo.logout') }}"
        >
            @csrf

            <button type="submit" class="logout-button">
                Logout
            </button>
        </form>
    </main>
</body>
</html>
