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
    <p style="font-size:13px;color:#6a5962">This is the local authenticated integration preview, not the production dashboard.</p>
    @if($canManageUsers || $canManageRoles)
    <section aria-labelledby="kfos-security-links" style="padding:16px;border:1px solid #eed7df;border-radius:14px;background:#fff8fa;margin:18px 0">
        <h3 id="kfos-security-links" style="margin:0 0 8px;color:#673149">Security Management</h3>
        <p style="font-size:13px;margin:0 0 12px;color:#6a5962">Only functions allowed by your current operational role or genuine System Administrator membership are shown.</p>
        @if($canManageUsers)<p><a href="{{ route('kfos.security.users.ui.index') }}" style="color:#a63861;font-weight:650">Open User Management</a></p>@endif
        @if($canAddUser)<p><a href="{{ route('kfos.security.users.ui.create') }}" style="color:#a63861;font-weight:650">Create Employee Login</a></p>@endif
        @if($canManageRoles)<p><a href="{{ route('kfos.security.roles.ui.index') }}" style="color:#a63861;font-weight:650">Open Roles &amp; Permissions</a></p>@endif
    </section>
    @else
    <p id="kfos-security-no-access" style="font-size:13px;color:#6a5962">No Security Management navigation is assigned to this account.</p>
    @endif
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
