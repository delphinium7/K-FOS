<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>K-FOS · Add User — SEC-UI-02 Preview</title>
  <style>
    :root {color-scheme:light;--ivory:#fbf7f3;--berry:#673149;--coral:#e8786c;--border:#e6dcd9;}
    * {box-sizing:border-box}body{margin:0;min-height:100vh;color:#312a2f;font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
    header h1{font-size:27px;margin:4px 0}main{margin:20px auto;max-width:1160px}
    .small{font-size:13px;color:#665960}.panel{background:white;border:1px solid var(--border);border-radius:15px;padding:24px;margin-top:16px}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    label{display:block;font-weight:650;color:var(--berry);margin-bottom:5px}input,select{width:100%;padding:11px;border:1px solid #bdaeb5;border-radius:8px;font:inherit;background:#fff;color:#312a2f}
    input:focus,select:focus{outline:2px solid var(--coral);outline-offset:1px}
    button{border:0;border-radius:8px;background:#ad4166;color:#fff;font:inherit;font-weight:700;padding:11px 18px;cursor:pointer}
    button:disabled{opacity:.5;cursor:not-allowed}.feedback{min-height:22px;margin-top:12px;color:#673149}.feedback.error{color:#9b2931}
    @media(max-width:700px){.grid{grid-template-columns:1fr}.panel{padding:17px}}
    @include('auth.partials.kfos-stage-security-css')
  </style>
</head>
<body>
<div class="kfos-layout">
@include('auth.partials.kfos-stage-security-sidebar')
<div class="kfos-content">
<header>
  <div class="small">SECURITY / USER MANAGEMENT / ADD USER</div>
  <h1>Add User</h1>
  <div class="small">Create an employee login account from a verified existing Employee Master record</div>
  <a href="{{ route('kfos.security.users.ui.index') }}">Back to User Management</a>
</header>
<main>
  <section class="panel" aria-labelledby="add-user-heading">
    <h2 id="add-user-heading">Create Employee Login</h2>
    <p class="small">Only a genuine System Administrator may create accounts. An employee must already exist in the verified K-FOS Employee Master.</p>
    <form id="kfos-add-user" method="post" action="{{ route('kfos.security.users.store') }}" autocomplete="off">
      @csrf
      <div class="grid">
        <div><label for="employee_id">Official Employee ID</label><input id="employee_id" name="employee_id" required maxlength="100"></div>
        <div><label for="employee_name">Employee name</label><input id="employee_name" name="name" required maxlength="255"></div>
        <div><label for="employee_position">Position</label><input id="employee_position" name="position" required maxlength="255"></div>
        <div><label for="role_id">Operational role</label><select id="role_id" name="role_id" required>
          <option value="">Choose an active role</option>
          @foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach
        </select></div>
        <div><label for="new_password">Initial password</label><input id="new_password" name="password" type="password" required minlength="8" autocomplete="new-password"></div>
        <div><label for="password_confirmation">Confirm initial password</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password"></div>
      </div>
      <p class="small">Minimum eight characters with uppercase and lowercase letters, a digit and a symbol. Credentials must be handed over manually through the approved secure channel after successful Save. No passwords are saved in this page.</p>
      <button type="submit">Save User</button>
      <p id="create-feedback" class="feedback" role="status" aria-live="polite"></p>
    </form>
  </section>
</main>
</div>
</div>
<script>
(() => {
  'use strict';
  const form=document.getElementById('kfos-add-user');
  const feedback=document.getElementById('create-feedback');
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  const messages={401:'Please sign in again.',403:'You are not authorized.',404:'Local preview unavailable.',419:'Session expired; refresh and retry.',422:'Validation failed. Check the details, role and password requirements.'};
  form.addEventListener('submit',async(event)=>{
    event.preventDefault();const button=form.querySelector('button[type="submit"]');button.disabled=true;feedback.textContent='';feedback.className='feedback';
    try{
      const response=await fetch(form.action,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},body:new FormData(form)});
      if(response.status===201){form.reset();feedback.textContent='Account created. Share credentials manually via the approved secure channel.';}
      else {feedback.className='feedback error';feedback.textContent=messages[response.status]||'Unable to confirm account creation. Check status before retrying.';}
    }catch(_){feedback.className='feedback error';feedback.textContent='Network error. Verify account status before retrying.';}
    finally{button.disabled=false;}
  });
})();
</script>
</body>
</html>
