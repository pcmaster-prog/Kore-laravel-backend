<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use App\Mail\InterviewScheduledMail;
use Illuminate\Support\Facades\Mail;

Mail::fake();
Mail::to('test@example.com')->send(new InterviewScheduledMail('Juan', 'Cajero', '2026-06-24 10:00', 'video', null, 'https://meet.example.com'));
echo "ok\n";
