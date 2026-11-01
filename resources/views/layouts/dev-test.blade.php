<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>K-FOS Development Test</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-slate-100">
    <main class="mx-auto max-w-3xl px-6 py-12">

        <div class="mb-8">
            <h1 class="text-3xl font-bold text-slate-900">
                K-FOS
            </h1>

            <p class="mt-2 text-slate-600">
                Frontend Integration Test
            </p>
        </div>

        {{ $slot }}

    </main>

    @livewireScripts
</body>
</html>
