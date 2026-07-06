<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('company-snapshots:refresh-stale --limit=10')
    ->hourly()
    ->withoutOverlapping();
