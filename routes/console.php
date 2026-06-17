<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Summarize idle signed-in chats (guests excluded). Requires a scheduler
// process (schedule:work / cron) — deployment wiring is a later step.
Schedule::command('chat:summarize-idle')
    ->everyTenMinutes()
    ->withoutOverlapping();
