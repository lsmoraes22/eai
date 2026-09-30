<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office'; // Ícone mais apropriado para Clientes
    protected static ?string $navigationGroup = 'Cadastros';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // SEÇÃO: DADOS CADASTRAIS
                Section::make('Informações Gerais')
                    ->description('Dados básicos de identificação do cliente.')
                    ->schema([
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('Nome do Cliente')
                                ->required()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('code')
                                ->label('Código Interno')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->rules(['regex:/^[A-Za-z0-9_]+$/'])
                                ->helperText('Somente letras, números e underline.')
                                ->maxLength(255),
                        ]),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('cnpj')
                                ->label('CNPJ')
                                ->mask('99.999.999/9999-99')
                                ->required(),
                            Forms\Components\TextInput::make('telefone')
                                ->label('Telefone')
                                ->tel()
                                ->required(),
                        ]),
                        Forms\Components\TextInput::make('endereco')
                            ->label('Endereço Completo')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Toggle::make('active')
                            ->label('Cliente Ativo')
                            ->default(true)
                            ->required(),
                    ]),

                // SEÇÃO: CONFIGURAÇÃO OAUTH2 (Bling, etc)
                Section::make('Configurações de Autenticação (OAuth2)')
                    ->description('Credenciais para integrações que utilizam tokens dinâmicos.')
                    ->collapsible()
                    ->schema([
                        Grid::make(1)->schema([
                            Forms\Components\TextInput::make('auth_url')
                                ->label('URL de Autenticação')
                                ->placeholder('https://www.bling.com.br/Api/v3/oauth/authorization')
                                ->url()
                                ->helperText('Endpoint para solicitação de autenticação.'),
                        ]),
                        Grid::make(1)->schema([
                            Forms\Components\TextInput::make('token_url')
                                ->label('URL de Token')
                                ->placeholder('https://www.bling.com.br/Api/v3/oauth/token')
                                ->url()
                                ->helperText('Endpoint para troca e refresh de tokens.'),
                        ]),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('app_client_id')
                                ->label('App Client ID')
                                ->password() // Oculta o ID por segurança
                                ->revealable(),
                            Forms\Components\TextInput::make('app_client_secret')
                                ->label('App Client Secret')
                                ->password()
                                ->revealable(),
                        ]),

                        // Campos de Token (Geralmente preenchidos via API, mas visíveis para Debug)
                        Grid::make(3)->schema([
                            Forms\Components\Textarea::make('access_token')
                                ->label('Access Token')
                                ->rows(3)
                                ->disabled() // Evita edição manual acidental
                                ->dehydrated(false),
                            Forms\Components\Textarea::make('refresh_token')
                                ->label('Refresh Token')
                                ->rows(3)
                                ->disabled()
                                ->dehydrated(false),
                            Forms\Components\DateTimePicker::make('expires_at')
                                ->label('Expira em')
                                ->disabled(),
                        ]),
                        Forms\Components\TextInput::make('account_id')
                            ->label('ID da Conta Externa')
                            ->disabled(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->copyable() // Facilita copiar o código para testes
                    ->searchable(),
                Tables\Columns\TextColumn::make('cnpj')
                    ->label('CNPJ')
                    ->searchable(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),

                // Status do Token na Tabela
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Token Expira')
                    ->dateTime('d/m/H:i')
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : 'success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Apenas Ativos'),
            ])
	    ->actions([
		Tables\Actions\EditAction::make(),
		Tables\Actions\Action::make('connect_oauth')
		->label('Vincular Conta API')
		->icon('heroicon-o-key')
		->color('info')
		->url(fn (Client $record) => route('oauth.authorize', ['client' => $record]))
                ->openUrlInNewTab()
                // O botão só aparece se os dados mínimos de configuração existirem
                ->visible(fn (Client $record) => $record->auth_url && $record->app_client_id),
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
            // Descomente conforme precisar
            // RelationManagers\EndpointsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
