<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>K-FOS | Authentication Integration Test</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="min-height:100vh;display:grid;place-items:center;background:linear-gradient(135deg,#fff9f5,#fbe4e7 52%,#efbbcf);font-family:Inter,Arial,sans-serif;color:#18212d">
<main style="width:min(90vw,540px);background:rgba(255,255,255,.78);border:1px solid #fff;border-radius:26px;padding:36px;box-shadow:0 15px 45px #58263d22">
    <h1>K-FOS</h1>
    <h2>Local Authentication Test</h2>
    <p>Authenticated session established.</p>
    <p>Employee: <strong>{{ auth('web')->user()->login_id }}</strong></p>
    <p style="font-size:13px;color:#6a5962">This page verifies authentication only. It is not the production dashboard.</p>
    <form id="kfos-stage-logout" action="{{ route('kfos.auth.stage.logout') }}" method="POST">
        @csrf
        <button type="submit" style="border:0;border-radius:12px;background:#a63861;color:white;padding:12px 20px;cursor:pointer">Sign out</button>
    </form>
    <p id="kfos-stage-logout-error" role="alert" hidden></p>
</main>
<script>
(() => {
    const form = document.getElementById('kfos-stage-logout');
    const error = document.getElementById('kfos-stage-logout-error');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const response = await fetch(form.action, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept':'application/json', 'X-CSRF-TOKEN':form.querySelector('[name="_token"]').value},
            });
            if (!response.ok) throw new Error('logout failed');
            window.location.assign(@json(route('kfos.auth.stage.ui.login')));
        } catch (_) {
            error.hidden = false;
            error.textContent = 'Unable to sign out. Please try again.';
        }
    });
})();
</script>
</body>
</html>
