<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Schedule::call(function () {
    Artisan::call('app:fetch-endpoints');
})
//->name('fetch-endpoint-id-4-unique-name')
->everyMinute()
//->withoutOverlapping()
; //evita "atropelar" o processo.

/*
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
/**/

