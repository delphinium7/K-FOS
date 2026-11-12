<aside class="kfos-sidebar" aria-label="K-FOS navigation">
  <div class="kfos-brand">K-FOS<span>WORKSHOP OPERATIONS</span></div>
  <nav class="kfos-nav" aria-label="Modules">
    <span>Dashboard</span><span>Sales Order</span><span>Production Planning</span>
    <span>Material Control</span><span>Production</span><span>Quality Control</span>
    <span>Packaging</span><span>Delivery</span><span>Reports</span>
    <div class="kfos-nav-group"><strong>Security</strong>
      <a class="{{ request()->routeIs('kfos.security.roles.*') ? 'kfos-nav-sub' : 'kfos-nav-active' }}" href="{{ route('kfos.security.users.ui.index') }}">User Management</a>
      <a class="{{ request()->routeIs('kfos.security.roles.*') ? 'kfos-nav-active' : 'kfos-nav-sub' }}" href="{{ url('kfos/security/ui/roles') }}">Roles &amp; Permissions</a>
      <span class="kfos-nav-sub">Security Audit</span>
    </div>
  </nav>
  <div class="kfos-profile"><span class="kfos-avatar" aria-hidden="true">U</span><div><strong>My Account</strong><small>User ID: {{ \Illuminate\Support\Facades\Auth::guard('web')->user()?->login_id ?? '—' }}</small></div></div>
</aside>
