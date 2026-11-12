<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>K-FOS | Employee Login — Local V2</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style>
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#202d3e}
body{background:linear-gradient(65deg,#fff7ea 15.315%,#fbeceb 60.36%,#e7a3bb 105.41%)}
.v2-scene{position:relative;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:74px 20px 54px;isolation:isolate;overflow:hidden}
.v2-spheres{position:absolute;z-index:-1;left:50%;top:50%;width:max(100%,1440px);height:max(100%,900px);transform:translate(-50%,-50%);pointer-events:none}
.sphere-piece{position:absolute;display:block;object-fit:fill;max-width:none}
.v2-brand{text-align:center;margin-bottom:35px}
.v2-brand h1{font-size:36px;line-height:1.25;font-weight:600;margin:0;color:#202d3e;letter-spacing:-1px}
.v2-brand p{font-size:13px;font-weight:500;color:#747988;margin:12px 0 0}
.v2-card{width:min(450px,100%);min-height:539px;padding:31px 33px 32px;background:rgba(255,255,255,.54);border:1px solid rgba(255,255,255,.9);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-radius:25px;box-shadow:0 18px 45px rgba(157,87,121,.16)}
.v2-card h2{font-size:24px;line-height:1.4;margin:0 0 24px;font-weight:600}
.v2-field{margin:0 0 15px}
.v2-field label{display:block;font-size:13px;color:#5e6371;font-weight:600;margin:0 0 10px}
.v2-field input{width:100%;height:52px;border-radius:26px;background:rgba(255,255,255,.66);border:1.2px solid #f8e8ee;outline:none;padding:0 17px;font-size:14px;color:#202d3e;font-family:inherit}
.v2-field input::placeholder{color:#657184;opacity:1}
.v2-field input:focus-visible{border:1.7px solid #dfa2b7;box-shadow:0 0 0 2px rgba(223,162,183,.15)}
.v2-password{position:relative}.v2-password input{padding-right:75px;border:1.7px solid #dfa2b7}
.v2-show{position:absolute;right:13px;top:0;bottom:0;border:0;background:none;color:#ad4166;font:600 13px Inter,system-ui,sans-serif;cursor:pointer;padding:0 4px}
.v2-meta{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:21px}
.v2-remember{display:flex;align-items:center;gap:9px;font-size:13px;color:#5e6371;cursor:pointer}
.v2-remember input{appearance:none;width:18px;height:18px;border:1px solid #eedce1;border-radius:5px;background:#fff;margin:0;cursor:pointer;flex:none}
.v2-remember input:checked{background:#c43f80;border-color:#c43f80}
.v2-remember input:checked:after{content:'✓';color:white;display:block;text-align:center;line-height:16px;font-size:13px}
.v2-forgot{color:#ae3f6d;font-size:12px;font-weight:600;text-decoration:none}
.v2-forgot[aria-disabled="true"]{cursor:default}
.v2-helper{font-size:10px;color:#747988;margin:9px 0 31px}
.v2-submit{width:100%;height:52px;border:0;border-radius:26px;background:linear-gradient(90deg,#ff9c8b,#e36a91 50%,#c43f80);color:#fff;font:600 15px Inter,system-ui,sans-serif;box-shadow:0 7px 13px rgba(196,63,128,.18);cursor:pointer}
.v2-submit:disabled{opacity:.72;cursor:wait}
.v2-footer{font-size:10px;color:#747988;text-align:center;margin:22px 0 0}
.v2-error{font-size:12px;background:#fff2f4;border:1px solid #e7abbc;color:#963654;padding:9px 11px;border-radius:9px;margin:-6px 0 15px}
.v2-error[hidden]{display:none}
@media(max-width:520px){.v2-scene{padding:45px 20px 42px;justify-content:flex-start}.v2-brand{margin-bottom:28px}.v2-brand h1{font-size:32px}.v2-card{min-height:520px;padding:28px 23px;border-radius:24px}.v2-spheres{width:900px;height:900px;left:50%;top:40%}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}
</style>
</head>
<body>
<main class="v2-scene">
  <div class="v2-spheres" aria-hidden="true">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/far-halo.svg') }}" alt="" aria-hidden="true" style="left:79.375000%;top:73.555556%;width:13.472222%;height:21.555556%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/far-body.svg') }}" alt="" aria-hidden="true" style="left:80.763889%;top:75.777778%;width:10.694444%;height:17.111111%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/far-highlight.svg') }}" alt="" aria-hidden="true" style="left:82.261111%;top:77.831111%;width:5.881944%;height:9.240000%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/bottom-halo.svg') }}" alt="" aria-hidden="true" style="left:44.027778%;top:75.222222%;width:19.722222%;height:31.555556%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/bottom-body.svg') }}" alt="" aria-hidden="true" style="left:45.416667%;top:77.444444%;width:16.944444%;height:27.111111%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/bottom-highlight.svg') }}" alt="" aria-hidden="true" style="left:47.788889%;top:80.697778%;width:9.319444%;height:14.640000%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/lower-halo.svg') }}" alt="" aria-hidden="true" style="left:9.930556%;top:60.222222%;width:17.013889%;height:27.222222%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/lower-body.svg') }}" alt="" aria-hidden="true" style="left:11.319444%;top:62.444444%;width:14.236111%;height:22.777778%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/lower-highlight.svg') }}" alt="" aria-hidden="true" style="left:12.743056%;top:64.153333%;width:9.253472%;height:14.350000%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/lower-specular.svg') }}" alt="" aria-hidden="true" style="left:14.379861%;top:66.772222%;width:3.274306%;height:4.327778%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/right-halo.svg') }}" alt="" aria-hidden="true" style="left:71.875000%;top:37.000000%;width:22.430556%;height:35.888889%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/right-body.svg') }}" alt="" aria-hidden="true" style="left:73.263889%;top:39.222222%;width:19.652778%;height:31.444444%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/right-highlight.svg') }}" alt="" aria-hidden="true" style="left:75.229167%;top:41.581111%;width:12.774306%;height:19.810000%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/right-specular.svg') }}" alt="" aria-hidden="true" style="left:77.488889%;top:45.196667%;width:4.520139%;height:5.974444%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/top-halo.svg') }}" alt="" aria-hidden="true" style="left:59.375000%;top:22.333333%;width:14.861111%;height:23.777778%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/top-body.svg') }}" alt="" aria-hidden="true" style="left:60.763889%;top:24.555556%;width:12.083333%;height:19.333333%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/top-highlight.svg') }}" alt="" aria-hidden="true" style="left:62.455556%;top:26.875556%;width:6.645833%;height:10.440000%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/left-halo.svg') }}" alt="" aria-hidden="true" style="left:15.138889%;top:12.888889%;width:22.222222%;height:35.555556%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/left-body.svg') }}" alt="" aria-hidden="true" style="left:16.527778%;top:15.111111%;width:19.444444%;height:31.111111%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/left-highlight.svg') }}" alt="" aria-hidden="true" style="left:18.472222%;top:17.444444%;width:12.638889%;height:19.600000%;">
    <img class="sphere-piece" src="{{ asset('assets/kfos-auth-v2/left-specular.svg') }}" alt="" aria-hidden="true" style="left:20.708333%;top:21.022222%;width:4.472222%;height:5.911111%;">
  </div>
  <header class="v2-brand"><h1>K-FOS</h1><p>Factory Operating System</p></header>
  <section class="v2-card" aria-labelledby="v2-title">
    <h2 id="v2-title">Employee Login</h2>
    <p id="v2-error" class="v2-error" role="alert" aria-live="polite" hidden></p>
    <form id="v2-login" method="POST" action="{{ route('kfos.auth.stage.login') }}">
      @csrf
      <div class="v2-field"><label for="v2-employee">Employee ID</label>
      <input id="v2-employee" type="text" name="employee_id" placeholder="Enter your Employee ID" autocomplete="username" maxlength="100" required></div>
      <div class="v2-field"><label for="v2-password">Password</label>
      <div class="v2-password"><input id="v2-password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
      <button class="v2-show" type="button" id="v2-show" aria-pressed="false" aria-label="Show password">Show</button></div></div>
      <div class="v2-meta"><label class="v2-remember"><input type="checkbox" name="remember_me" value="1"><span>Remember me</span></label>
      <span class="v2-forgot" aria-disabled="true" title="Password recovery workflow is not enabled in this local preview">Forgot Password?</span></div>
      <p class="v2-helper">Optional · maximum 90 days on this device</p>
      <button class="v2-submit" type="submit">Sign In</button>
    </form>
    <p class="v2-footer">Local authentication prototype — not for production use.</p>
  </section>
</main>
<script>
(() => {
  const form=document.getElementById('v2-login'),error=document.getElementById('v2-error'),show=document.getElementById('v2-show'),password=document.getElementById('v2-password'),submit=form.querySelector('button[type=submit]');
  show.addEventListener('click',()=>{const visible=password.type==='text';password.type=visible?'password':'text';show.textContent=visible?'Show':'Hide';show.setAttribute('aria-pressed',String(!visible));show.setAttribute('aria-label',visible?'Show password':'Hide password')});
  form.addEventListener('submit',async e=>{
    e.preventDefault();error.hidden=true;error.textContent='';submit.disabled=true;submit.textContent='Signing in…';
    try {
      const response=await fetch(form.action,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':form.querySelector('[name=_token]').value},body:new FormData(form)});
      if(!response.ok){error.textContent=response.status===419?'Session expired. Refresh the page and try again.':'Invalid Employee ID or password.';error.hidden=false;return}
      const data=await response.json();if(data.authenticated===true){window.location.assign(@json(route('kfos.auth.stage.ui.home')));return}
      error.textContent='Unable to sign in. Please try again.';error.hidden=false;
    } catch(_) {error.textContent='Unable to connect. Please try again.';error.hidden=false}
    finally {submit.disabled=false;submit.textContent='Sign In'}
  });
})();
</script>
</body>
</html>
