<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;

class Routines extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static string $view = 'filament.pages.routines';

    public array $rotinas = [
        'app:fetch-endpoints',
        'app:validate-xml',
	'app:validate-json',
        'app:convert-xml-json',
	'app:convert-json-json',
	'app:convert-json-xml',
	'app:convert-xml-xml',
    ];

    /**
     * Definição dos parâmetros por comando
     */
    public array $parametros = [
        'app:fetch-endpoints' => ['id'],
        'app:validate-xml' => [],
	'app:validate-json' => [],
        'app:convert-xml-json' => [],
	'app:convert-json-json' => [],
	'app:convert-json-xml' => [],
	'app:convert-xml-xml' => [],
    ];

    /**
     * Valores preenchidos nos inputs
     * Ex: params['app:fetch-endpoints']['id'] = 10
     */
    public array $params = [];

    public function execute(string $cmd): void
    {
        $args = [];

        foreach ($this->parametros[$cmd] as $param) {
            if (!empty($this->params[$cmd][$param])) {
                $args["--{$param}"] = $this->params[$cmd][$param];
            }
        }

        Artisan::call($cmd, $args);
        $output = Artisan::output();

        Notification::make()
            ->title("Rotina executada")
            ->body("Comando: {$cmd}\n\n{$output}")
            ->success()
            ->send();
    }
}


/*

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

    public $parametros = [
	'app:fetch-endpoints' => ['id'] ,
	'app:validate-xml' => [],
	'app:convert-xml-json' => [],
    ];

    public function execute($cmd,$param){
	Artisan::call($cmd);
	// Captura a saída do comando, se houver
    	$output = Artisan::output();
	$ps = '';
	foreach($this->parametros[$cmd] as $p){
	    $ps .= " --{$p}=$param";
	}
    	// Exibe notificação no Filament
    	Notification::make()
    	    ->title("Rotina executada: {$cmd} " .  )
    	    ->success()
    	    ->body(nl2br($output)) // opcional
    	    ->send();
    }

}
/**/
