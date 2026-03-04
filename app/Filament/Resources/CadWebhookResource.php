<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CadWebhookResource\Pages;
use App\Filament\Resources\CadWebhookResource\RelationManagers;
use App\Models\CadWebhook;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CadWebhookResource extends Resource
{
    protected static ?string $model = CadWebhook::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static ?string $navigationGroup = 'Cadastros';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('client_id')
                ->relationship('client', 'name')
                ->required(),
            Forms\Components\TextInput::make('nome')
                ->required()
                ->placeholder('ex: bling-pedidos'),
//                ->description('Este nome será usado na URL do webhook'),
            Forms\Components\Toggle::make('ativo')
                ->default(true),

            Forms\Components\Section::make('Segurança (HMAC)')
                ->schema([
                    Forms\Components\Toggle::make('webhook_verify')
                        ->label('Verificar Assinatura?')
                        ->reactive(),
                    Forms\Components\TextInput::make('webhook_header')
                        ->label('Cabeçalho da Assinatura')
                        ->default('X-Bling-Signature-256')
                        ->visible(fn ($get) => $get('webhook_verify')),
                    Forms\Components\TextInput::make('webhook_algo')
                        ->label('Algoritmo')
                        ->default('sha256')
                        ->visible(fn ($get) => $get('webhook_verify')),
                ])->columns(2),

            Forms\Components\TextInput::make('descricao')
                ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Id')
		    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('webhook_verify')
                    ->label('Assinatura?')
                    ->boolean(),
                Tables\Columns\TextColumn::make('webhook_header')
                    ->label('Cabeçalho da Assinatura')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('webhook_algo')
                    ->label('Algoritmo')
                    ->copyable()
                    ->searchable(),
		Tables\Columns\TextColumn::make('client.name')
		    ->label('Client')
		    ->toggleable()
		    ->searchable()
		    ->sortable(),
		Tables\Columns\TextColumn::make('descricao')
		    ->label('Client')
		    ->toggleable(isToggledHiddenByDefault: true)
		    ->searchable()
		    ->sortable(),
                Tables\Columns\IconColumn::make('ativo')
                    ->label('Ativo')
                    ->boolean(),

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
            'index' => Pages\ListCadWebhooks::route('/'),
            'create' => Pages\CreateCadWebhook::route('/create'),
            'edit' => Pages\EditCadWebhook::route('/{record}/edit'),
        ];
    }
}
