
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>K-FOS | Employee Login</title>

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
            font-family:
                Inter,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

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

        /* Apple-style frosted glass card */
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

        /* Login heading */
        .login-heading {
            margin: 0;
            font-size: 27px;
            font-weight: 650;
            letter-spacing: -0.6px;
            line-height: 1.3;
        }

        /* Login form */
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

            font-size: 14px;
            color: #343b45;
        }

        .remember-checkbox {
            width: 19px;
            height: 19px;
            margin: 0;

            accent-color: #bd477f;
        }

        .remember-checkbox:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }

        .remember-text {
            opacity: 0.65;
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

            box-shadow:
                0 5px 15px rgba(174, 59, 108, 0.12);

            cursor: pointer;

            transition:
                opacity 0.2s ease,
                transform 0.2s ease;
        }

        .login-button:hover {
            opacity: 0.92;
        }

        .login-button:active {
            transform: scale(0.99);
        }

        .login-button:focus-visible {
            outline: 2px solid #933e70;
            outline-offset: 3px;
        }

        /* Validation error */
        .login-error {
            margin: 0;
            padding: 12px 16px;

            border-radius: 14px;
            border: 1px solid rgba(190, 60, 90, 0.20);

            background: rgba(255, 236, 240, 0.75);

            color: #9f1239;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Temporary prototype notice */
        .preview-note {
            margin: 28px 0 0;

            text-align: center;
            font-size: 12px;
            line-height: 1.6;
            color: #6b6470;
        }

        /* Mobile responsiveness */
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

            <h1 class="brand-title">
                K-FOS
            </h1>

            <p class="brand-subtitle">
                Factory Operating System
            </p>

        </header>

        <!-- Login glass card -->
        <section
            class="glass-panel"
            aria-labelledby="login-heading"
        >

            <h2
                id="login-heading"
                class="login-heading"
            >
                Employee Login
            </h2>

            <!-- Functional local test login form -->
            <form
                id="kfos-login-form"
                class="login-form"
                method="POST"
                action="{{ !empty($kfosStageMode) ? route('kfos.auth.stage.login') : route('kfos.demo.login.submit') }}"
            >

                @csrf

                <!-- Login validation errors -->
                @if ($errors->any())
                    <p
                        class="login-error"
                        role="alert"
                    >
                        {{ $errors->first() }}
                    </p>
                @endif

                @if (!empty($kfosStageMode))
                    <p class="login-error" id="kfos-stage-error" role="alert" aria-live="polite" hidden></p>
                @endif

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
                        name="employee_id"
                        class="glass-input"
                        type="text"
                        placeholder="Enter Employee ID"
                        value="{{ old('employee_id') }}"
                        autocomplete="username"
                        maxlength="100"
                        required
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
                        name="password"
                        class="glass-input"
                        type="password"
                        placeholder="Enter password"
                        autocomplete="current-password"
                        required
                    >

                </div>

                <!-- Remember me -->
                <label class="remember-row">

                    @if (!empty($kfosStageMode))
                        <input
                            class="remember-checkbox"
                            type="checkbox"
                            name="remember_me"
                            value="1"
                        >
                    @else
                        <input
                            class="remember-checkbox"
                            type="checkbox"
                            disabled
                            title="Remember me is not implemented in this prototype"
                        >
                    @endif

                    <span class="remember-text">
                        Remember me
                    </span>

                </label>

                <!-- Functional Sign In button -->
                <button
                    type="submit"
                    class="login-button"
                >
                    Sign In
                </button>

            </form>

            <!-- Development notice -->
            <p class="preview-note">
                Local authentication prototype —
                not for production use.
            </p>

        </section>

    </main>

    @if (!empty($kfosStageMode))
    <script>
    (() => {
        const form = document.getElementById('kfos-login-form');
        const error = document.getElementById('kfos-stage-error');
        const submit = form?.querySelector('button[type="submit"]');
        if (!form || !error || !submit) return;
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            error.hidden = true;
            error.textContent = '';
            submit.disabled = true;
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                    },
                    body: new FormData(form),
                });
                if (!response.ok) {
                    error.textContent = response.status === 419
                        ? 'Session expired. Refresh the page and try again.'
                        : 'Invalid Employee ID or password.';
                    error.hidden = false;
                    return;
                }
                const result = await response.json();
                if (result.authenticated === true) {
                    window.location.assign(@json(route('kfos.auth.stage.ui.home')));
                    return;
                }
                error.textContent = 'Unable to sign in. Please try again.';
                error.hidden = false;
            } catch (_) {
                error.textContent = 'Unable to connect. Please try again.';
                error.hidden = false;
            } finally {
                submit.disabled = false;
            }
        });
    })();
    </script>
    @endif
</body>
</html>
