<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Schedule::call(function () {
    Artisan::call('app:fetch-endpoints');
})
->name('eai:fetch-endpoints')
->everyMinute()
// A rodada processa todos os endpoints sequencialmente; 60 minutos evita
// sobreposição do scheduler sem depender do TTL menor usado por endpoint.
->withoutOverlapping(60)
; //evita "atropelar" o processo.

/*
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
/**/
