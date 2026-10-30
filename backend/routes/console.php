<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:process-aging')
    ->dailyAt('08:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

Schedule::command('inventory:weekly-risk')
    ->weeklyOn(1, '08:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
