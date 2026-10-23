<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JsonSchemaResource\Pages;
use App\Filament\Resources\JsonSchemaResource\RelationManagers;
//use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use App\Models\JsonSchema;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class JsonSchemaResource extends Resource //implements HasShieldPermissions
{
    protected static ?string $model = JsonSchema::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $slug = 'json-schemas';
    protected static ?string $navigationGroup = 'Integrações';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Configurações do Schema')
                    ->schema([
                        Forms\Components\Select::make('client_id')
                            ->label('Cliente')
                            ->required()
                            ->options(Client::pluck('name', 'id'))
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('name')
                            ->label('Nome Amigável')
                            ->placeholder('Ex: Validação de Pedido Bling')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('description')
                            ->label('Descrição')
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('temp_file')
                            ->disk('integrations')
                            ->visibility('private')
                            ->label('Arquivo JSON Schema')
                            ->directory('temp/json_schema')
                            ->preserveFilenames()
                            ->maxSize(4096)
                            ->acceptedFileTypes(['application/json', 'text/plain'])
                            ->required()
                            // Dica: Adicione o helper para lembrar o usuário do formato
                            ->helperText('O arquivo deve seguir a especificação JSON Schema Draft 6.'),
                    ])->columns(2),
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

                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),

                Tables\Columns\TextColumn::make('filename')
                    ->label('Arquivo')
                    ->icon('heroicon-m-document-text'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client_id')
                    ->label('Filtrar por Cliente')
                    ->relationship('client', 'name')
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
		Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListJsonSchemas::route('/'),
            'create' => Pages\CreateJsonSchema::route('/create'),
            'edit' => Pages\EditJsonSchema::route('/{record}/edit'),
        ];
    }
}
