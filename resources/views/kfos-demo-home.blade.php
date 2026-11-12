
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>K-FOS | Demo Homepage</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
            background: linear-gradient(
                135deg,
                #fff5ef 0%,
                #fce8e9 50%,
                #f7efff 100%
            );
            color: #292533;
        }

        .home-card {
            width: 100%;
            max-width: 460px;
            margin: 24px;
            padding: 42px 36px;
            text-align: center;
            background: rgba(255, 255, 255, 0.65);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(80, 56, 72, 0.10);
        }

        .logo {
            width: 64px;
            height: 64px;
            margin: 0 auto 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background: linear-gradient(
                135deg, #eea5a0, #cda9df
            );
            color: white;
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 28px;
            font-weight: 700;
        }

        .subtitle {
            margin: 0 0 28px;
            color: #746d7c;
            line-height: 1.6;
        }

        .employee-box {
            margin-bottom: 28px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.55);
            border: 1px solid rgba(180, 160, 180, 0.2);
            border-radius: 14px;
        }

        .employee-label {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            color: #81778a;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .employee-id {
            font-size: 19px;
            font-weight: 700;
        }

        .dashboard-button {
            display: block;
            width: 100%;
            padding: 15px;
            border-radius: 12px;
            background: linear-gradient(
                135deg, #d98e96, #b589c9
            );
            color: white;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .dashboard-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(185, 137, 201, 0.25);
        }

        .logout-form {
            margin-top: 14px;
        }

        .logout-button {
            display: block;
            width: 100%;
            padding: 14px;
            background: rgba(255, 255, 255, 0.7);
            color: #705c75;
            border: 1px solid rgba(160, 140, 170, 0.3);
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .logout-button:hover {
            background: rgba(255, 255, 255, 0.95);
        }

        .footer {
            margin-top: 28px;
            font-size: 12px;
            color: #938798;
        }

        @media (max-width: 480px) {
            .home-card {
                padding: 32px 24px;
            }

            h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <main class="home-card">

        <div class="logo">KF</div>

        <h1>Welcome to K-FOS</h1>

        <p class="subtitle">
            Your local development login was successful.
        </p>

        <div class="employee-box">
            <span class="employee-label">Employee ID</span>
            <span class="employee-id">{{ $employeeId }}</span>
        </div>

        <!-- Dashboard Navigation -->
        <a
            href="{{ route('kfos.demo.dashboard') }}"
            class="dashboard-button"
        >
            Open K-FOS Dashboard
        </a>

        <!-- Logout -->
        <form
            method="POST"
            action="{{ route('kfos.demo.logout') }}"
            class="logout-form"
        >
            @csrf

            <button type="submit" class="logout-button">
                Logout
            </button>
        </form>

        <p class="footer">
            K-FOS Development Environment · Prototype Only
        </p>

    </main>
</body>
</html>
