<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CadInterfaceStatusResource\Pages;
use App\Filament\Resources\CadInterfaceStatusResource\RelationManagers;
use App\Models\CadInterfaceStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CadInterfaceStatusResource extends Resource
{
    protected static ?string $model = CadInterfaceStatus::class;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Interface Status';
    protected static ?string $pluralLabel = 'Status das Interfaces';
    protected static ?string $navigationGroup = 'Integrações';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('int_id')
                    ->label('ID')
                    ->sortable(),
		Tables\Columns\TextColumn::make('int_direcao')
		    ->label('Sentido')
		    ->sortable(),
                Tables\Columns\TextColumn::make('int_interface')
                    ->label('Interface')
                    ->searchable(),
                Tables\Columns\TextColumn::make('int_idoc')
                    ->label('IDoc')
		    ->searchable(),

                Tables\Columns\TextColumn::make('int_arquivo')
                    ->label('Arquivo')
                    ->searchable()
		    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('int_status')
                    ->label('Status')
                    ->badge()
                    ->colors([
                        'gray' => fn($state) => $state == 0,
                        'success' => fn($state) => $state == 1,
                        'danger' => fn($state) => $state == 2,
			'info' => fn($state) => $state == 3,
			'warning' => fn($state) => $state == 4
		    ])
		    ->formatStateUsing(function ($state) {
                        return match ($state) {
                            0 => 'Recebido',
                            1 => 'Validado',
                            2 => 'Recusado',
			    3 => 'Processado',
			    4 => 'Excluído',
                            default => 'Desconhecido',
                        };
                    })
		    ->searchable(),
		    Tables\Columns\TextColumn::make('int_mensagem')
			->label('Mensagem')
			->toggleable(isToggledHiddenByDefault: true),
		    Tables\Columns\TextColumn::make('int_data_envio')
                    	->label('Processado em')
                    	->dateTime('d/m/Y H:i:s')
			->toggleable(isToggledHiddenByDefault: true),

            ])
	    ->defaultSort('int_id', 'desc')
            ->paginated([25, 50, 100])
            ->filters([])
	    ->headerActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCadInterfaceStatuses::route('/'),
        ];
    }
}
