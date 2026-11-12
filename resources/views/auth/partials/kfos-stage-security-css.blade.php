/* Shared local-only layout aligned to Figma SEC-UI-01 and SEC-UI-02 visual conventions.
   This is not a pixel-fidelity acceptance claim. */
body { background:linear-gradient(64deg,#fff9f6 18%,#fbefF1 64%,#f3e3ed 100%); }
.kfos-layout { display:grid;grid-template-columns:244px minmax(0,1fr);min-height:100vh; }
.kfos-content {min-width:0;}
.kfos-sidebar {min-height:100vh;background:linear-gradient(154deg,#f0a47d 2%,#de6b70 42%,#a93f63 92%);color:#fff;display:flex;flex-direction:column;padding:24px 14px;}
.kfos-brand {font-size:30px;font-weight:750;line-height:1.2;padding:0 10px 20px;border-bottom:1px solid #fde3dfbF;}
.kfos-brand span {display:block;font-size:11px;font-weight:500;letter-spacing:.08em;margin-top:4px;}
.kfos-nav {display:flex;flex-direction:column;gap:2px;margin-top:16px;flex:1;}
.kfos-nav > span,.kfos-nav-group > strong {display:block;padding:10px 15px;font-size:14px;font-weight:500;}
.kfos-nav-group{margin-top:4px;display:flex;flex-direction:column;}
.kfos-nav-group > strong {font-weight:700;}
.kfos-nav-group a,.kfos-nav-sub {display:block;margin:2px 0 2px 12px;padding:9px 12px;border-radius:9px;font-size:13px;color:#fff9f6;text-decoration:none;}
.kfos-nav-group .kfos-nav-active{background:#fff9f8;color:#ad4166;font-weight:700;}
.kfos-nav-sub{opacity:.85;}
.kfos-profile{display:flex;gap:10px;align-items:center;background:#fff9f7;color:#6e304e;border-radius:16px;padding:13px 10px;margin-top:12px;}
.kfos-profile .kfos-avatar{width:38px;height:38px;border-radius:50%;background:#fbe5e9;display:grid;place-items:center;font-weight:700;}
.kfos-profile strong{display:block;font-size:13px;}.kfos-profile small{display:block;font-size:11px;color:#936d7c;overflow-wrap:anywhere;}
.kfos-content header {background:transparent;color:#283449;padding:24px 24px 8px;}
.kfos-content header a {color:#ad4166;}
.kfos-content header .small{color:#78808d!important;}
.kfos-content main {width:min(1160px,calc(100% - 48px));margin:12px auto 60px;}
.kfos-content .panel {box-shadow:0 10px 34px #6731490d;border-color:#f1dce1;}
.kfos-content h2 {color:#8f3555;}
.kfos-page-actions {display:flex;justify-content:flex-end;align-items:center;gap:12px;margin:6px 0 18px;}
.kfos-button-link{display:inline-block;padding:11px 18px;border-radius:9px;background:#ad4166;color:white;text-decoration:none;font-weight:700;}
.kfos-button-link:hover{background:#8f3555;}
@media(max-width:1000px){.kfos-layout{grid-template-columns:195px minmax(0,1fr)}.kfos-sidebar{padding:16px 8px}.kfos-content main{width:calc(100% - 28px)}}
@media(max-width:700px){.kfos-layout{grid-template-columns:minmax(0,1fr)}.kfos-sidebar{min-height:auto;padding:12px}.kfos-brand{font-size:23px;padding-bottom:8px}.kfos-nav{display:none}.kfos-profile{display:none}.kfos-content header{padding:15px}.kfos-content main{width:calc(100% - 20px)}}
