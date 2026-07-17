<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('company-snapshots:refresh-stale --limit=10')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('company-snapshots:refresh-full-transactions --limit=100')
    ->weeklyOn(7, '2:00')
    ->withoutOverlapping();
