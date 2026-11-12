<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>K-FOS · User Management — SEC-UI-01 Preview</title>
    <style>
        :root { color-scheme: light; --ivory:#fbf7f3; --coral:#e8786c; --rose:#f8e3e0; --berry:#673149; --ink:#312a2f; --muted:#665960; --border:#e6dcd9; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:var(--ivory); color:var(--ink); font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; }
        header { padding:20px max(22px,calc((100vw - 1160px)/2)); background:var(--berry); color:white; }
        header a { color:white; text-decoration:underline; }
        h1 { font-size:24px; margin:4px 0 0; }
        h2 { color:var(--berry); font-size:19px; margin:0 0 12px; }
        main { width:min(1160px,calc(100% - 32px)); margin:24px auto 60px; }
        .eyebrow { font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.83; }
        .note { background:var(--rose); border-radius:10px; padding:14px 16px; margin-bottom:20px; }
        .panel { border:1px solid var(--border); border-radius:15px; background:white; padding:24px; margin:18px 0; box-shadow:0 3px 18px #6731490d; }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .filter-grid { display:grid; grid-template-columns:minmax(230px,2fr) minmax(180px,1fr) auto; align-items:end; gap:12px; margin:14px 0 18px; }
        .filter-grid label { font-size:13px; }
        .filter-grid button { min-height:44px; }
        @media (max-width:760px) { .filter-grid { grid-template-columns:1fr; } }
        label { display:block; color:var(--berry); font-weight:650; margin-bottom:5px; }
        input,select { width:100%; padding:11px; border-radius:8px; border:1px solid #bdaeb5; font:inherit; color:var(--ink); background:#fff; }
        input:focus,select:focus { outline:2px solid var(--coral); outline-offset:1px; }
        button { cursor:pointer; border:0; border-radius:8px; background:var(--berry); color:white; font:inherit; font-weight:650; padding:11px 15px; }
        button:hover { background:#8b4966; }
        button:disabled { opacity:.53; cursor:not-allowed; }
        .submit { margin-top:16px; }
        .scroll { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; min-width:700px; }
        th,td { padding:11px 10px; text-align:left; border-bottom:1px solid var(--border); vertical-align:middle; }
        th { color:var(--berry); font-size:13px; }
        .small { color:var(--muted); font-size:13px; }
        .action { padding:8px 11px; font-size:13px; }
        .status { font-weight:700; }
        .feedback { margin-top:12px; min-height:21px; color:var(--berry); }
        .feedback.error { color:#9b2931; }
        @media (max-width:700px) { .grid { grid-template-columns:1fr; } .panel { padding:17px; } }
        @include('auth.partials.kfos-stage-security-css')
    </style>
</head>
<body>
<div class="kfos-layout">
@include('auth.partials.kfos-stage-security-sidebar')
<div class="kfos-content">
<header>
    <div class="eyebrow">K-FOS · Local development preview</div>
    <h1>User Management</h1>
    <div class="small" style="color:#f4e8ed">SECURITY / USER MANAGEMENT · Local design integration preview</div>
    <div><a href="{{ url('/kfos-auth-stage/ui/home') }}">Return to K-FOS home</a></div>
</header>
<main>
    <div class="note" role="note">
        This is a restricted development screen. Account creation requires a real K-FOS System Administrator;
        status changes require the separately protected authority. The shared local KFOS_ADMIN identity does not receive either authority.
        No user records are created simply by opening this page.
    </div>

    @if ($isAdministrator)
    <div class="kfos-page-actions"><a class="kfos-button-link" href="{{ route('kfos.security.users.ui.create') }}">Create Employee Login</a></div>
    @endif

    <section class="panel" aria-labelledby="accounts-heading">
        <h2 id="accounts-heading">Employee Login Accounts</h2>
        <p class="small">Showing up to 50 matching accounts for this restricted preview. Universal administrator accounts are hidden from delegated account-status editors.</p>
        <form id="kfos-account-filter" class="filter-grid" method="get" action="{{ url('/kfos/security/ui/users') }}" role="search">
            <div><label for="account_search">Search employee ID, name or position</label><input id="account_search" name="q" value="{{ $search }}" maxlength="100" autocomplete="off" placeholder="Search employee accounts"></div>
            <div><label for="account_status_filter">Account status</label><select id="account_status_filter" name="status">
                <option value="all" @selected($statusFilter === 'all')>All statuses</option>
                <option value="active" @selected($statusFilter === 'active')>Active</option>
                <option value="inactive" @selected($statusFilter === 'inactive')>Inactive</option>
            </select></div>
            <button type="submit">Apply filter</button>
        </form>
        <div class="scroll"><table>
            <thead><tr><th>Employee ID</th><th>Name</th><th>Position</th><th>Role</th><th>Login status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->login_id }}</td><td>{{ $user->name }}</td><td>{{ $user->position }}</td>
                    <td>{{ $user->universal_admin_id ? 'System Administrator' : ($user->role_name ?: 'Not assigned') }}</td>
                    <td class="status">{{ $user->is_active ? 'Active' : 'Inactive' }}</td>
                    <td>
                        @if ((int)$user->id === $actorId)
                            <span class="small">Own account — no self-deactivation</span>
                        @else
                            <form class="kfos-status-form" method="post" action="{{ route('kfos.security.users.status.update', ['user' => $user->id]) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $user->is_active ? 'inactive' : 'active' }}">
                                <button type="submit" class="action">{{ $user->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No eligible accounts found.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <p id="status-feedback" class="feedback" role="status" aria-live="polite"></p>
    </section>
</main>
</div>
</div>
<script>
(() => {
    'use strict';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const post = async (form) => {
        const response = await fetch(form.action, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},
            body: new FormData(form)
        });
        let data = {};
        try { data = await response.json(); } catch (_) { /* Never render HTML errors. */ }
        return {response, data};
    };
    const messageFor = (status) => ({
        401:'Please sign in again.', 403:'You are not authorized for this action.',
        404:'This local preview is unavailable.', 419:'Session expired. Refresh and retry.',
        422:'Validation failed. Check the employee details, password and selected role.'
    }[status] || 'Request failed. No success has been confirmed.');
    document.querySelectorAll('.kfos-status-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!window.confirm('Confirm this employee login account status change?')) return;
            const button = form.querySelector('button[type="submit"]');
            const feedback = document.getElementById('status-feedback');
            button.disabled = true;
            feedback.textContent = '';
            try {
                const {response} = await post(form);
                if (response.ok) window.location.reload();
                else { feedback.className = 'feedback error'; feedback.textContent = messageFor(response.status); }
            } catch (_) {
                feedback.className = 'feedback error'; feedback.textContent = 'Network error. Refresh to verify status before retrying.';
            } finally { button.disabled = false; }
        });
    });
})();
</script>
</body>
</html>
