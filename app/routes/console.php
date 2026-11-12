<?php

use App\Services\GoogleSheets\SheetsClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Google entry sheet: lines into Data Entry drafts every 5 minutes; dropdown lists and tab names hourly.
Schedule::command('app:sheets-sync')->everyFiveMinutes()->withoutOverlapping(15)->when(fn () => app(SheetsClient::class)->isConfigured());
Schedule::command('app:sheets-tabs')->hourly()->withoutOverlapping(30)->when(fn () => app(SheetsClient::class)->isConfigured());
