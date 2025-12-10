<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CadProcessosDeparaResource\Pages;
use App\Filament\Resources\CadProcessosDeparaResource\RelationManagers;
use App\Models\CadProcessosDepara;
use App\Models\CadProcesso;
use Filament\Forms;
use Filament\Forms\Components as Comp;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CadProcessosDeparaResource extends Resource
{
    protected static ?string $model = CadProcessosDepara::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Cadastros';
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Comp\Select::make('processo_id')
                    ->label('Processo')
                    ->required()
                    ->options(CadProcesso::pluck('name', 'id'))
                    ->searchable()
                    ->preload(),
                Comp\Textarea::make('input_path')
                    ->label('Input Path')
                    ->maxLength(255)
                    ->required(),
                Comp\Textarea::make('output_path')
                    ->label('Output Path')
                    ->maxLength(255)
                    ->required(),
                Comp\Textarea::make('data_type')
                    ->label('Tipo de dados')
		    ->default('string')
                    ->maxLength(255),
		Comp\Textarea::make('default_value')
                    ->label('Valor Padrão')
                    ->maxLength(255),
		Comp\TextInput::make('order')
		    ->label('Ordem')
		    ->numeric(),
                Comp\Toggle::make('active')
                    ->label('Ativo?')
                    ->default(true)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('processo.name')->label('Processo')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('input_path'),
                Tables\Columns\TextColumn::make('output_path'),
                Tables\Columns\TextColumn::make('data_type'),
                Tables\Columns\TextColumn::make('defaut_value'),
		Tables\Columns\TextColumn::make('order'),
                Tables\Columns\IconColumn::make('active')->boolean(),
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
            'index' => Pages\ListCadProcessosDeparas::route('/'),
            'create' => Pages\CreateCadProcessosDepara::route('/create'),
            'edit' => Pages\EditCadProcessosDepara::route('/{record}/edit'),
        ];
    }
}
