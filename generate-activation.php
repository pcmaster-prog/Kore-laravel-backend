<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\UserActivationToken;
use Illuminate\Contracts\Console\Kernel;

$users = User::whereIn('email', ['adancuellarh@gmail.com', 'akecuellarherbandez@gmail.com'])->get();
foreach ($users as $u) {
    $token = UserActivationToken::createForUser($u);
    $u->update(['is_active' => false]);
    echo $u->email.' : '.config('app.frontend_url').'/set-password?token='.$token->token."\n";
}
