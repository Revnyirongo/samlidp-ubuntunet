<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{if isset($pagetitle)}{$pagetitle|escape} — {/if}{$tenant_name|default:'eduID.africa'}</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  body { font-family: 'Inter', sans-serif; }
  {if isset($custom_css)}{$custom_css|escape:html}{/if}
</style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-900 to-slate-800 flex items-center justify-center p-4">
<div class="w-full max-w-md">

  {# Organization branding #}
  <div class="text-center mb-8">
    {if isset($logo_url) && $logo_url}
      <img src="{$logo_url|escape}" alt="{$tenant_name|default:'Institution'|escape}" class="h-16 mx-auto mb-4 object-contain">
    {else}
      <div class="inline-flex items-center justify-center w-16 h-16 bg-orange-500 rounded-2xl mb-4 shadow-lg text-white text-3xl font-bold">
        {$tenant_name|default:'U'|truncate:1:'':true}
      </div>
    {/if}
    <h1 class="text-2xl font-bold text-white">{$tenant_name|default:'eduID.africa'|escape}</h1>
    <p class="text-slate-400 text-sm mt-1">Sign in with your institutional credentials</p>
  </div>

  {# Login card #}
  <div class="bg-white rounded-2xl shadow-2xl p-8">

    {# SP info (which service is requesting auth) #}
    {if isset($sp_name)}
    <div class="mb-6 flex items-center gap-3 bg-blue-50 border border-blue-100 rounded-xl px-4 py-3">
      <svg class="w-5 h-5 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <div class="text-sm">
        <span class="text-blue-700 font-medium">Signing in to: </span>
        <span class="text-blue-800 font-semibold">{$sp_name|escape}</span>
      </div>
    </div>
    {/if}

    {# Error messages #}
    {if isset($errors) && !empty($errors)}
    <div class="mb-5 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
      {foreach $errors as $error}
        <p class="text-sm text-red-700 flex items-center gap-2">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          {$error|escape}
        </p>
      {/foreach}
    </div>
    {/if}

    {# Login form #}
    <form method="post" action="{$formTarget|escape}" autocomplete="on" class="space-y-4">
      {if isset($stateParams)}
        {foreach $stateParams as $name => $value}
          <input type="hidden" name="{$name|escape}" value="{$value|escape}">
        {/foreach}
      {/if}

      <div>
        <label for="username" class="block text-sm font-medium text-gray-700 mb-1.5">
          {$usernameLabel|default:'Username'|escape}
        </label>
        <input type="text"
               id="username"
               name="username"
               value="{if isset($username)}{$username|escape}{/if}"
               autocomplete="username"
               required
               autofocus
               class="w-full px-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none transition"
               placeholder="{$usernamePlaceholder|default:'username or email'|escape}">
      </div>

      <div>
        <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">
          {t}Password{/t}
        </label>
        <input type="password"
               id="password"
               name="password"
               autocomplete="current-password"
               required
               class="w-full px-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-orange-500 focus:border-transparent outline-none transition"
               placeholder="••••••••">
      </div>

      {if isset($rememberMeEnabled) && $rememberMeEnabled}
      <div class="flex items-center gap-2">
        <input type="checkbox" id="remember" name="remember"
               class="rounded border-gray-300 text-orange-500">
        <label for="remember" class="text-sm text-gray-600">{t}Remember me for 8 hours{/t}</label>
      </div>
      {/if}

      <button type="submit"
              class="w-full py-2.5 bg-orange-500 hover:bg-orange-600 active:bg-orange-700 text-white font-semibold rounded-xl text-sm transition shadow-sm mt-2">
        {t}Sign in{/t}
      </button>
    </form>

    {# Help links #}
    {if isset($helpUrl) && $helpUrl}
    <div class="mt-5 text-center">
      <a href="{$helpUrl|escape}" target="_blank" rel="noopener"
         class="text-xs text-gray-400 hover:text-gray-600">
        {t}Need help? Contact your IT helpdesk{/t}
      </a>
    </div>
    {/if}
  </div>

  {# Footer #}
  <p class="text-center text-xs text-slate-500 mt-6">
    Powered by <a href="{$service_home_url|default:'#'|escape}" class="hover:text-slate-300">eduID.africa</a>
    {if isset($privacy_url)}&nbsp;·&nbsp;<a href="{$privacy_url|escape}" class="hover:text-slate-300">{t}Privacy{/t}</a>{/if}
  </p>
</div>

<script>
// Auto-focus first empty field
document.addEventListener('DOMContentLoaded', function() {
    var u = document.getElementById('username');
    var p = document.getElementById('password');
    if (u && !u.value) { u.focus(); }
    else if (p) { p.focus(); }
});
</script>
</body>
</html>
