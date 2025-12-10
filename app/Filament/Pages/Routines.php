<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;

class Routines extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.routines';

    public $rotinas = [
	'app:fetch-endpoints',
	'app:validate-xml',
	'app:convert-xml-json'
    ];

    public function execute($cmd){
	Artisan::call($cmd);
	// Captura a saída do comando, se houver
    	$output = Artisan::output();

    	// Exibe notificação no Filament
    	Notification::make()
    	    ->title("Rotina executada: {$cmd}")
    	    ->success()
    	    ->body(nl2br($output)) // opcional
    	    ->send();
    }

}
