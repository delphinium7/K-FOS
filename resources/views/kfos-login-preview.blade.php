
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>K-FOS | Temporary Employee Login</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
        }

        body {
            font-family: Inter, -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
            color: #18212d;
        }

        /* Soft gradient background */
        .login-background {
            min-height: 100vh;
            min-height: 100dvh;
            background:
                radial-gradient(
                    ellipse at 12% 10%,
                    rgba(255, 241, 223, 0.95),
                    transparent 60%
                ),
                radial-gradient(
                    ellipse at 90% 90%,
                    rgba(196, 65, 115, 0.27),
                    transparent 65%
                ),
                linear-gradient(
                    135deg,
                    #fff9f5 0%,
                    #fbe4e7 52%,
                    #efbbcf 100%
                );
        }

        /* Centered desktop layout */
        .login-page {
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 20px;
        }

        /* K-FOS branding */
        .login-brand {
            width: 100%;
            text-align: center;
            margin-bottom: 32px;
        }

        .brand-title {
            margin: 0;
            font-size: 42px;
            font-weight: 650;
            letter-spacing: -1.5px;
            line-height: 1.2;
        }

        .brand-subtitle {
            margin: 10px 0 0;
            font-size: 15px;
            color: #667085;
        }

        /* Frosted glass card */
        .glass-panel {
            width: 100%;
            max-width: 580px;
            padding: 42px;
            border-radius: 30px;

            background: rgba(255, 255, 255, 0.38);

            border: 1px solid rgba(255, 255, 255, 0.78);

            box-shadow:
                0 18px 50px rgba(106, 49, 77, 0.10),
                0 2px 8px rgba(106, 49, 77, 0.03);

            -webkit-backdrop-filter: blur(22px);
            backdrop-filter: blur(22px);
        }

        /* Heading */
        .login-heading {
            margin: 0;
            font-size: 27px;
            font-weight: 650;
            letter-spacing: -0.6px;
            line-height: 1.3;
        }

        /* Form spacing */
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 23px;
            margin-top: 30px;
        }

        .login-field-group {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .login-label {
            font-size: 14px;
            font-weight: 550;
            color: #505a68;
        }

        /* Glass input fields */
        .glass-input {
            display: block;
            width: 100%;
            min-height: 52px;
            padding: 13px 19px;
            border-radius: 999px;

            background: rgba(255, 255, 255, 0.48);
            border: 1px solid rgba(255, 255, 255, 0.85);

            box-shadow:
                0 2px 8px rgba(90, 50, 70, 0.04);

            color: #18212d;
            font-family: inherit;
            font-size: 15px;

            transition:
                background 0.2s ease,
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }

        .glass-input::placeholder {
            color: #92949e;
        }

        .glass-input:focus {
            outline: 2px solid rgba(173, 64, 116, 0.42);
            outline-offset: 2px;
            background: rgba(255, 255, 255, 0.72);
        }

        /* Remember me */
        .remember-row {
            display: flex;
            align-items: center;
            gap: 11px;
            cursor: pointer;
            font-size: 14px;
            color: #343b45;
        }

        .remember-checkbox {
            width: 19px;
            height: 19px;
            margin: 0;
            accent-color: #bd477f;
            cursor: pointer;
        }

        /* Sign In button */
        .login-button {
            width: 100%;
            min-height: 52px;
            padding: 13px 20px;

            border: none;
            border-radius: 999px;

            background: linear-gradient(
                100deg,
                #f39c87 0%,
                #e16b90 55%,
                #b83f78 100%
            );

            color: white;
            font-family: inherit;
            font-size: 16px;
            font-weight: 650;

            box-shadow: 0 5px 15px rgba(174, 59, 108, 0.12);
        }

        .login-button:disabled {
            cursor: not-allowed;
            opacity: 0.82;
        }

        /* Temporary preview notice */
        .preview-note {
            margin: 28px 0 0;
            text-align: center;
            font-size: 12px;
            line-height: 1.6;
            color: #6b6470;
        }

        /* Tablet / mobile */
        @media (max-width: 640px) {
            .login-page {
                padding: 30px 16px;
            }

            .login-brand {
                margin-bottom: 25px;
            }

            .brand-title {
                font-size: 36px;
            }

            .brand-subtitle {
                font-size: 14px;
            }

            .glass-panel {
                padding: 30px 23px;
                border-radius: 25px;
            }

            .login-heading {
                font-size: 24px;
            }

            .login-form {
                gap: 21px;
                margin-top: 25px;
            }
        }
    </style>
</head>

<body class="login-background">

    <main class="login-page">

        <!-- K-FOS branding -->
        <header class="login-brand">
            <h1 class="brand-title">K-FOS</h1>

            <p class="brand-subtitle">
                Factory Operating System
            </p>
        </header>

        <!-- Temporary login preview -->
        <section
            class="glass-panel"
            aria-labelledby="login-heading"
        >

            <h2 id="login-heading" class="login-heading">
                Employee Login
            </h2>

            <div class="login-form">

                <!-- Employee ID -->
                <div class="login-field-group">
                    <label
                        for="employee-id"
                        class="login-label"
                    >
                        Employee ID
                    </label>

                    <input
                        id="employee-id"
                        class="glass-input"
                        type="text"
                        placeholder="Enter Employee ID"
                        autocomplete="off"
                    >
                </div>

                <!-- Password -->
                <div class="login-field-group">
                    <label
                        for="password"
                        class="login-label"
                    >
                        Password
                    </label>

                    <input
                        id="password"
                        class="glass-input"
                        type="password"
                        placeholder="Enter password"
                        autocomplete="off"
                    >
                </div>

                <!-- Remember me -->
                <label class="remember-row">
                    <input
                        class="remember-checkbox"
                        type="checkbox"
                    >

                    <span>Remember me</span>
                </label>

                <!-- Disabled until authentication is implemented -->
                <button
                    type="button"
                    class="login-button"
                    disabled
                    title="Authentication is not implemented"
                >
                    Sign In
                </button>

            </div>

            <p class="preview-note">
                Temporary preview only — final approved login
                page pending.
            </p>

        </section>

    </main>

</body>
</html>
