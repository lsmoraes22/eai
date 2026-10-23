<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use App\Models\CadInterfaceStatus;
class FileExplorer extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-folder-open';
    protected static ?string $navigationGroup = 'Armazenamento';
    protected static string $view = 'filament.pages.file-explorer';

    public string $currentPath = '';
    public string $previewContent = '';
    public ?string $previewType = null; // 'image', 'text'


    public function getItems()
    {
        $path = "{$this->currentPath}";
        return [
            'directories' => array_map(function ($dir) {
		return basename($dir);
            }, Storage::disk('integrations')->directories($path)),

            'files' => array_map(function ($file) {
                return basename($file);
            }, Storage::disk('integrations')->files($path)),
        ];
    }

    public function enterDirectory($dir)
    {
        $this->currentPath .= '/' . $dir;
    }

    public function goBack()
    {
        if ($this->currentPath === 'clients') return;

        $chunks = explode('/', $this->currentPath);
        array_pop($chunks);
        $this->currentPath = implode('/', $chunks);
    }

    public function deleteFile($file)
    {
    	$path = "public/{$this->currentPath}/{$file}";
        if (Storage::disk('integrations')->exists("{$this->currentPath}/{$file}")) {
            Storage::disk('integrations')->delete("{$this->currentPath}/{$file}");
            $this->dispatch('notify', message: 'Arquivo deletado com sucesso!');
	    CadInterfaceStatus::where('int_arquivo',$file)->update(['int_status' => 4]);
        }
    }

    public function previewFile($file)
    {
    	$path = "{$this->currentPath}/{$file}";

        if (!Storage::disk('integrations')->exists($path)) {
    	    $this->previewContent = 'Arquivo não encontrado!';
    	    return;
   	}

    	$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    	if (in_array($ext, ['png','jpg','jpeg','gif','webp'])) {
   	     // Preview de imagem
   	     $this->previewType = 'image';
         $this->previewContent = 'data:image/' . ($ext === 'jpg' ? 'jpeg' : $ext) . ';base64,'
                 . base64_encode(Storage::disk('integrations')->get($path));
   	     return;
   	}

    	// Preview de texto
    	if (in_array($ext, ['txt','json','xml','log','csv','xsd'])) {
    	    $this->previewType = 'text';
            $this->previewContent = Storage::disk('integrations')->get($path);
    	    return;
    	}

    	// Sem preview
    	$this->previewType = 'none';
    	$this->previewContent = "Preview não disponível para .$ext";
    }

}
