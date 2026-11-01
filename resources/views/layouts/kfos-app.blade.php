<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title', 'K-FOS')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-slate-100 text-slate-900">

    <header class="bg-slate-900 text-white">
        <div class="mx-auto flex max-w-7xl items-center
                    justify-between px-6 py-4">
            <div>
                <h1 class="text-2xl font-bold">K-FOS</h1>
                <p class="text-sm text-slate-300">
                    Factory Operating System
                </p>
            </div>

            <span class="rounded bg-amber-200 px-3 py-1
                         text-xs font-medium text-amber-950">
                DEVELOPMENT
            </span>
        </div>
    </header>

    <div class="mx-auto grid max-w-7xl gap-6
                px-6 py-8 lg:grid-cols-[240px_1fr]">

        <aside class="rounded-xl bg-white p-5 shadow-sm">
            <h2 class="mb-4 font-semibold">Modules</h2>

            <nav class="space-y-3 text-sm">
                <div>Dashboard</div>
                <div>Sales Orders</div>
                <div>Material Management</div>
                <div>Production</div>
                <div>Reports</div>
            </nav>

            <p class="mt-6 text-xs text-slate-500">
                Navigation placeholders only.
            </p>
        </aside>

        <main class="rounded-xl bg-white p-6 shadow-sm">
            @yield('content')
        </main>
    </div>

    @livewireScripts
</body>
</html>
