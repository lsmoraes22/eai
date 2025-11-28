<?php

namespace App\Filament\Resources;

use App\Filament\Resources\XsdFileResource\Pages;
use App\Models\XsdFile;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class XsdFileResource extends Resource
{
    protected static ?string $model = XsdFile::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Integrações';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
		Forms\Components\Select::make('client_id')
    		    ->label('Cliente')
		    ->required()
		    ->options(Client::pluck('name', 'id'))
		    ->searchable()
		    ->preload(),

                Forms\Components\TextInput::make('name')
                    ->maxLength(255),

                Forms\Components\TextInput::make('filename')
                    ->maxLength(255),

                Forms\Components\Textarea::make('description'),

                Forms\Components\FileUpload::make('temp_file')
                    ->label('Arquivo XSD')
                    ->directory('temp/xsd')
                    ->preserveFilenames()
                    ->maxSize(4096)
                    ->acceptedFileTypes(['application/xml', 'text/xml','.xsd'])
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
		Tables\Columns\TextColumn::make('client.name')
    		    ->label('Cliente')
		    ->searchable()
		    ->sortable(),

                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('filename')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('path')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListXsdFiles::route('/'),
            'create' => Pages\CreateXsdFile::route('/create'),
            'edit' => Pages\EditXsdFile::route('/{record}/edit'),
        ];
    }

}
