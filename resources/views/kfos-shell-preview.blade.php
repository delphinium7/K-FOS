
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>K-FOS | Dashboard</title>

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --sidebar-top: #ef9882;
            --sidebar-middle: #db7181;
            --sidebar-bottom: #b43d69;

            --page-background: #fdf7f6;
            --text-primary: #253043;
            --text-secondary: #7d8392;

            --rose-primary: #b6406a;
            --border-soft: #eee3e7;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont,
                "Segoe UI", Roboto, Arial, sans-serif;
            color: var(--text-primary);
            background: var(--page-background);
        }

        .app-layout {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr);
            min-height: 100vh;
        }

        /* SIDEBAR */

        .sidebar {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            padding: 24px 14px 16px;
            color: #ffffff;

            background: linear-gradient(
                180deg,
                var(--sidebar-top) 0%,
                var(--sidebar-middle) 48%,
                var(--sidebar-bottom) 100%
            );
        }

        .brand {
            padding: 0 12px 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,.32);
        }

        .brand-title {
            margin: 0;
            font-size: 25px;
            font-weight: 700;
            letter-spacing: .2px;
        }

        .brand-subtitle {
            display: block;
            margin-top: 4px;
            font-size: 9px;
            letter-spacing: .8px;
            text-transform: uppercase;
        }

        .navigation {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nav-link,
        .nav-placeholder {
            display: block;
            width: 100%;
            padding: 11px 12px;
            border: none;
            border-radius: 9px;
            font-family: inherit;
            font-size: 13px;
            line-height: 1.4;
            color: white;
            text-align: left;
            text-decoration: none;
            background: transparent;
        }

        .nav-link {
            cursor: pointer;
            transition: background .2s ease;
        }

        .nav-link:hover {
            background: rgba(255,255,255,.22);
        }

        .nav-link.active {
            background: rgba(255,255,255,.24);
            font-weight: 600;
        }

        .nav-placeholder {
            opacity: .88;
            cursor: default;
        }

        .nav-group {
            margin-top: 1px;
        }

        .nav-submenu {
            margin: 4px 0 7px 15px;
            padding-left: 12px;
            border-left: 1px solid rgba(255,255,255,.35);
        }

        .nav-subitem {
            display: block;
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 12px;
            color: rgba(255,255,255,.95);
            text-decoration: none;
            transition: background .2s;
        }

        .nav-subitem:hover {
            background: rgba(255,255,255,.20);
        }

        /* ACCOUNT */

        .sidebar-spacer {
            flex: 1;
        }

        .account-card {
            margin-top: 24px;
            padding: 12px;
            border-radius: 13px;
            background: rgba(255,255,255,.93);
            color: #6a3852;
        }

        .account-top {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            border-radius: 50%;
            background: #f3d8e3;
            color: #9d466d;
            font-size: 13px;
            font-weight: 700;
        }

        .account-name {
            margin: 0;
            font-size: 12px;
            font-weight: 700;
        }

        .account-role {
            margin: 3px 0 0;
            color: #8f7282;
            font-size: 11px;
            overflow-wrap: anywhere;
        }

        .logout-form {
            margin-top: 12px;
        }

        .logout-button {
            width: 100%;
            padding: 9px;
            border: 1px solid #efd8e1;
            border-radius: 8px;
            background: white;
            color: #9a4567;
            font-family: inherit;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .logout-button:hover {
            background: #fff1f5;
        }

        /* MAIN CONTENT */

        .main-content {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 0;
            padding: 40px;
            background:
                radial-gradient(
                    circle at 80% 18%,
                    rgba(245,204,218,.26),
                    transparent 38%
                ),
                linear-gradient(
                    135deg,
                    #fdf7f6 0%,
                    #f9eef0 56%,
                    #f6edf5 100%
                );
        }

        .welcome-panel {
            width: 100%;
            max-width: 760px;
            padding: 55px 35px;
            border: 1px solid rgba(255,255,255,.85);
            border-radius: 24px;
            background: rgba(255,255,255,.68);
            box-shadow: 0 18px 48px rgba(109,61,87,.08);
            text-align: center;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .welcome-label {
            display: inline-block;
            padding: 9px 16px;
            margin-bottom: 24px;
            border: 1px solid #f0dce4;
            border-radius: 999px;
            background: #fff1f5;
            color: #a0446a;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .4px;
        }

        .welcome-heading {
            margin: 0;
            font-size: clamp(30px, 4vw, 50px);
            font-weight: 800;
            letter-spacing: -1px;
            line-height: 1.15;
        }

        .welcome-heading span {
            background: linear-gradient(
                135deg,
                #e68b7d 0%,
                #ca5b85 55%,
                #ad65b0 100%
            );
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .welcome-subtitle {
            max-width: 520px;
            margin: 20px auto 0;
            font-size: 15px;
            line-height: 1.8;
            color: var(--text-secondary);
        }

        .welcome-footer {
            margin-top: 28px;
            color: #a18d9a;
            font-size: 12px;
        }

        /* RESPONSIVE */

        @media (max-width: 900px) {
            .app-layout {
                grid-template-columns: 210px minmax(0, 1fr);
            }

            .main-content {
                padding: 25px;
            }
        }

        @media (max-width: 650px) {
            .app-layout {
                grid-template-columns: 1fr;
            }

            .sidebar {
                min-height: auto;
            }

            .main-content {
                min-height: 60vh;
                padding: 20px;
            }

            .welcome-panel {
                padding: 38px 22px;
            }
        }
    </style>
</head>

<body>

    <div class="app-layout">

        <!-- LEFT SIDEBAR -->

        <aside class="sidebar">

            <div class="brand">
                <h1 class="brand-title">K-FOS</h1>
                <small class="brand-subtitle">
                    WORKSHOP OPERATIONS
                </small>
            </div>

            <nav class="navigation" aria-label="K-FOS modules">

                <!-- Dashboard -->
                <a
                    href="{{ route('kfos.demo.dashboard') }}"
                    class="nav-link active"
                    aria-current="page"
                >
                    Dashboard
                </a>

                <!-- Sales Order -->
                <div class="nav-group">
                    <a
                        href="{{ route('kfos.demo.so.preview') }}"
                        class="nav-link"
                    >
                        Sales Order
                    </a>

                    <div class="nav-submenu">
                        <a
                            href="{{ route('kfos.demo.so.preview') }}"
                            class="nav-subitem"
                        >
                            SO-01 Create Sales Order
                        </a>
                    </div>
                </div>

                <!-- Other modules: UI only -->
                <span class="nav-placeholder">SO Approval</span>
                <span class="nav-placeholder">Production Planning</span>
                <span class="nav-placeholder">Material Control</span>
                <span class="nav-placeholder">Production</span>
                <span class="nav-placeholder">Quality Control</span>
                <span class="nav-placeholder">Packaging</span>
                <span class="nav-placeholder">Delivery</span>
                <span class="nav-placeholder">Timesheet Management</span>
                <span class="nav-placeholder">Reports</span>

            </nav>

            <div class="sidebar-spacer"></div>

            <!-- DEMO ACCOUNT PANEL -->

            <div class="account-card">

                <div class="account-top">

                    <div class="avatar">U</div>

                    <div>
                        <p class="account-name">My Account</p>

                        <p class="account-role">
                            {{ session('kfos_demo_employee_id', 'Demo User') }}
                        </p>
                    </div>

                </div>

                <form
                    method="POST"
                    action="{{ route('kfos.demo.logout') }}"
                    class="logout-form"
                >
                    @csrf

                    <button
                        type="submit"
                        class="logout-button"
                    >
                        Logout
                    </button>
                </form>

            </div>

        </aside>

        <!-- RIGHT MAIN CONTENT -->

        <main class="main-content">

            <section class="welcome-panel">

                <span class="welcome-label">
                    K-FOS Dashboard
                </span>

                <h2 class="welcome-heading">
                    Welcome to <span>K-FOS</span>
                </h2>

                <p class="welcome-subtitle">
                    Kilang Factory Operating System
                </p>

                <p class="welcome-footer">
                    Local Development Preview
                </p>

            </section>

        </main>

    </div>

</body>
</html>
