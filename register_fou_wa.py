import subprocess

php_code = """<?php
require '/var/www/xedik/vendor/autoload.php';
$app = require_once '/var/www/xedik/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$acc = \\App\\Models\\WhatsappAccount::withoutGlobalScopes()->find(2);

// 1. Register phone number 1473439735851393 on Meta Cloud API
$regResp = \\Illuminate\\Support\\Facades\\Http::withToken($acc->access_token)
    ->post("https://graph.facebook.com/v21.0/1473439735851393/register", [
        'messaging_product' => 'whatsapp',
        'pin' => '123456',
    ]);
echo "REGISTER RESPONSE: " . $regResp->body() . "\\n";

// 2. Re-fetch status from Meta
$checkResp = \\Illuminate\\Support\\Facades\\Http::withToken($acc->access_token)
    ->get("https://graph.facebook.com/v21.0/{$acc->waba_id}/phone_numbers");
echo "STATUS AFTER REGISTER: " . $checkResp->body() . "\\n";

// 3. Create or update WhatsappAccount for Tenant 5 (FOU)
$fouWa = \\App\\Models\\WhatsappAccount::withoutGlobalScopes()->updateOrCreate(
    ['phone_number_id' => '1473439735851393'],
    [
        'tenant_id'       => 5,
        'display_name'    => 'FOU Factory Outlet',
        'phone_number'    => '8135470665',
        'waba_id'         => $acc->waba_id,
        'access_token'    => $acc->access_token,
        'app_secret'      => $acc->app_secret,
        'verify_token'    => $acc->verify_token,
        'is_active'       => true,
    ]
);
echo "CRM WHATSAPP ACCOUNT ID: " . $fouWa->id . "\\n";
"""

p = subprocess.Popen(['ssh', 'root@2.24.74.183', 'php'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
out, err = p.communicate(php_code)
print("OUT:", out)
print("ERR:", err)

