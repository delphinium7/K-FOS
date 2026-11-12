<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>K-FOS · Roles &amp; Permissions — Local Preview</title>
  <style>
    :root{--berry:#673149;--rose:#f8e3e0;--border:#e6dcd9;--ink:#312a2f;--muted:#665960}
    *{box-sizing:border-box}body{margin:0;color:var(--ink);font:15px/1.5 system-ui,-apple-system,'Segoe UI',sans-serif}
    header h1{font-size:29px;margin:5px 0}header .small{font-size:13px;color:#78808d}
    main{max-width:1160px;margin:16px auto 60px}.panel{background:white;border:1px solid var(--border);border-radius:15px;padding:22px;margin:14px 0}
    .small{font-size:13px;color:var(--muted)}h2{font-size:20px;color:var(--berry);margin:0 0 8px}
    h3{font-size:16px;margin:0 0 8px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
    label{display:block;color:var(--berry);font-size:13px;font-weight:650;margin:6px 0}
    input,select{padding:9px 11px;border:1px solid #bdaeb5;border-radius:8px;font:inherit;background:white;color:var(--ink);max-width:100%}
    input:not([type=checkbox]),select{width:100%}input[type=checkbox]{accent-color:#ad4166;margin-right:8px}
    button{background:#ad4166;color:white;border:0;border-radius:8px;padding:9px 14px;font:inherit;font-weight:650;cursor:pointer}
    button:hover{background:#8f3555}button:disabled{opacity:.55;cursor:not-allowed}
    .row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.role-card{border:1px solid #f1dce1;background:#fffafa;border-radius:12px;padding:17px;margin:14px 0}
    .perm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:3px 12px;margin:12px 0}
    .perm-grid label{font-size:13px;font-weight:500;color:#45333e}.status{font-size:12px;font-weight:700;border-radius:14px;padding:4px 10px;background:#f6e8e9}
    .feedback{min-height:20px;color:#673149}.feedback.error{color:#9b2931}details summary{cursor:pointer;color:var(--berry);font-weight:700;margin:8px 0}
    @media(max-width:800px){.grid,.perm-grid{grid-template-columns:1fr}.panel{padding:14px}}
    @include('auth.partials.kfos-stage-security-css')
  </style>
</head>
<body>
<div class="kfos-layout">
@include('auth.partials.kfos-stage-security-sidebar')
<div class="kfos-content">
<header><div class="small">SECURITY / ROLES &amp; PERMISSIONS</div>
  <h1>Roles &amp; Permissions</h1>
  <div class="small">Protected local UI integration preview · current operational role and centrally managed permissions</div>
  <a href="{{ route('kfos.security.users.ui.index') }}">Back to User Management</a>
</header>
<main>
  <div class="panel" role="note">One operational role per employee. Protected Security Management permissions and assignment <em>into</em> protected roles require a genuine System Administrator. Every committed change is audited and qualifying changes revoke sessions.</div>
  @if($canCreate)
    <section class="panel" aria-labelledby="create-role-heading"><h2 id="create-role-heading">Add Operational Role</h2>
      <form class="kfos-role-action" method="post" action="{{ route('kfos.security.roles.store') }}">
        @csrf <div class="grid"><div><label for="role_name">Role name</label><input id="role_name" name="name" required minlength="2" maxlength="80" placeholder="e.g. Production Planner"></div>
        <div><label for="create_reason">Reason (optional)</label><input id="create_reason" name="reason" maxlength="1000"></div></div>
        <p class="small">New roles start active and unassigned, with no permissions. Assign permissions separately.</p>
        <button type="submit">Add Role</button>
      </form>
    </section>
  @endif
  <section class="panel" aria-labelledby="role-list-heading"><h2 id="role-list-heading">Operational Roles</h2>
    <p class="small">{{ $roles->count() }} roles displayed (maximum 100). The universal System Administrator role cannot be edited here.</p>
    @forelse($roles as $role)
      <article class="role-card" data-role-id="{{ $role->id }}">
        <div class="row"><h3>{{ $role->name }}</h3><span class="status">{{ $role->is_active ? 'Active' : 'Disabled' }}</span></div>
        @if($canEdit)
          <details><summary>Rename and lifecycle</summary>
            <div class="grid">
              <form class="kfos-role-action" method="post" action="{{ route('kfos.security.roles.rename', $role->id) }}">
                @csrf <label>New role name<input name="name" value="{{ $role->name }}" maxlength="80" required></label>
                <label>Reason (optional)<input name="reason" maxlength="1000"></label><button type="submit">Save Name</button>
              </form>
              <form class="kfos-role-action" method="post" action="{{ route('kfos.security.roles.status', $role->id) }}">
                @csrf <input type="hidden" name="is_active" value="{{ $role->is_active ? 0 : 1 }}">
                <label>Reason (optional)<input name="reason" maxlength="1000"></label>
                <button type="submit">{{ $role->is_active ? 'Disable Role' : 'Reactivate Role' }}</button>
                <p class="small">Disable requires zero current employee assignments, including inactive accounts.</p>
              </form>
            </div>
          </details>
          @if($role->is_active)
            <details><summary>Edit role permissions</summary>
              <form class="kfos-role-action" method="post" action="{{ route('kfos.security.roles.permissions', $role->id) }}">
                @csrf <div class="perm-grid">
                  @foreach($permissions as $permission)
                    @php($checked = in_array($permission->code, $selected->get($role->id, []), true))
                    @if(!$permission->is_protected || $isAdministrator)
                      <label><input type="checkbox" name="permission_codes[]" value="{{ $permission->code }}" @checked($checked)>
                        {{ $permission->name }} {{ $permission->is_protected ? '— Protected' : '' }}</label>
                    @else
                      <label><input type="checkbox" @checked($checked) disabled> {{ $permission->name }} — Protected (read only)</label>
                    @endif
                  @endforeach
                </div>
                <label>Reason (optional)<input name="reason" maxlength="1000"></label>
                <button type="submit">Save Permissions</button>
              </form>
            </details>
          @endif
        @endif
      </article>
    @empty <p>No operational roles available.</p>
    @endforelse
  </section>
  @if($canAssign)
    <section class="panel" aria-labelledby="assignment-heading"><h2 id="assignment-heading">Assign Employee Role</h2>
      <p class="small">Showing up to 50 eligible ordinary employee accounts. Protected-role destination choices are System Administrator-only. A changed assignment revokes the affected employee's sessions.</p>
      @forelse($users as $user)
        <form class="kfos-role-action role-card" method="post" action="{{ route('kfos.security.roles.employees.assign', $user->id) }}">
          @csrf <div class="grid"><div><label>{{ $user->login_id }} · {{ $user->name }}
            <select name="role_id"><option value="">No operational role</option>
              @foreach($destinations as $destination)
                <option value="{{ $destination->id }}" @selected((int) $user->role_id === (int) $destination->id)>{{ $destination->name }}</option>
              @endforeach
            </select></label></div>
            <div><label>Reason (optional)<input name="reason" maxlength="1000"></label>
              <button type="submit">Save Role Assignment</button></div></div>
        </form>
      @empty <p>No eligible employee accounts available.</p> @endforelse
    </section>
  @endif
  <p id="role-feedback" class="feedback" role="status" aria-live="polite"></p>
</main>
</div></div>
<script>
(() => {
  'use strict';
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  const feedback=document.getElementById('role-feedback');
  const message=(status)=>({401:'Please sign in again.',403:'You are not authorized for this change.',404:'Local preview unavailable.',419:'Session expired; refresh and retry.',422:'Validation failed or action not permitted. Check the role name, assignments and permissions.'}[status]||'Action not confirmed; review status before retrying.');
  document.querySelectorAll('.kfos-role-action').forEach(form=>form.addEventListener('submit',async(event)=>{
    event.preventDefault();const button=form.querySelector('button[type="submit"]');
    if(!button || button.disabled)return;
    if(/status|assign/.test(form.action) && !window.confirm('Confirm this role management change?'))return;
    button.disabled=true;feedback.textContent='';feedback.className='feedback';
    try{
      const response=await fetch(form.action,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},body:new FormData(form)});
      if(response.ok){let data={};try{data=await response.json()}catch(_){/* no assumptions */}
        if(data.changed===false){feedback.textContent='No changes were necessary.';button.disabled=false}
        else window.location.reload();
      }else{feedback.className='feedback error';feedback.textContent=message(response.status);button.disabled=false;}
    }catch(_){feedback.className='feedback error';feedback.textContent='Network error. Verify saved state before retrying.';button.disabled=false;}
  }));
})();
</script>
</body></html>
