<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CadProcessoResource\Pages;
use App\Filament\Resources\CadProcessoResource\RelationManagers;
use App\Models\CadProcesso;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CadProcessoResource extends Resource
{
    protected static ?string $model = CadProcesso::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Cadastros';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->rules(['regex:/^[A-Za-z0-9_]+$/'])
                    ->helperText('Somente letras, números e underline.')
                    ->maxLength(100),
	 	Select::make('initial_format')
                    ->label('Formato Inicial')
                    ->options([
                        'XML' => 'XML',
                        'JSON' => 'JSON',
                        'TXT' => 'TXT',
                        'CSV' => 'CSV'
                    ])
                    ->default('XML')
                    ->required(),
 		Select::make('final_format')
                    ->label('Formato Final')
                    ->options([
                        'XML' => 'XML',
                        'JSON' => 'JSON',
                        'TXT' => 'TXT',
                        'CSV' => 'CSV'
                    ])
                    ->default('JSON')
                    ->required(),
		Toggle::make('active')
		    ->label('Ativo?')
                    ->default(true)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('initial_format')->label('Formato Inicial'),
                Tables\Columns\TextColumn::make('final_format')->label('Formato Final'),
		Tables\Columns\TextColumn::make('user_create_id')->label('Id do Usuário de criação'),
		Tables\Columns\IconColumn::make('active')->label('Ativo')->boolean(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCadProcessos::route('/'),
            'create' => Pages\CreateCadProcesso::route('/create'),
            'edit' => Pages\EditCadProcesso::route('/{record}/edit'),
        ];
    }
}
