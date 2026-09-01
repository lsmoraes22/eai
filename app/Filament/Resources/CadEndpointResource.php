<?php

namespace App\Filament\Resources;

use App\Models\CadEndpoint;
use App\Models\Client;
use App\Models\CadProcesso;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use App\Filament\Resources\CadEndpointResource\Pages;

class CadEndpointResource extends Resource
{
    protected static ?string $model = CadEndpoint::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';
    protected static ?string $navigationGroup = 'Cadastros';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informações da Interface')
                    ->description('Dados principais do endpoint.')
                    ->schema([

                Select::make('nome')
                    ->label('Nome')
                    ->required()
                    ->options(CadProcesso::pluck('name', 'name'))
                    ->searchable()
                    ->preload(),
			Select::make('direcao')
			    ->label('Direção')
			    ->searchable()
			    ->required()
			    ->live()
			    ->options([
				'entrada' => 'entrada',
				'auth' => 'auth',
				'saida' => 'saida'
			    ]),
                        Select::make('tipo')
                            ->label('Tipo de Integração')
                            ->options([
                                'REST' => 'REST',
                                'SOAP' => 'SOAP',
                                'IDOC' => 'IDOC',
                            ])
                            ->default('REST')
                            ->required(),

                        Select::make('metodo')
                            ->label('Método HTTP')
                            ->options([
                                'GET' => 'GET',
                                'POST' => 'POST',
                                'PUT' => 'PUT',
				'PATCH' => 'PATCH'
                            ])
                            ->default('POST')
                            ->required(),
			Select::make('client_id')
	                    ->label('Cliente')
	                    ->options(Client::all()->pluck('name', 'id')) // lista todos os clientes
	                    ->searchable() // permite pesquisar
	                    ->required(),
                        Textarea::make('url')
                            ->label('URL')
                            ->rows(2)
                            ->required(),
                        Select::make('extensao')
                            ->label('Extensão')
                            ->options([
                                'XML' => 'XML',
                                'JSON' => 'JSON',
                                'TXT' => 'TXT',
				'CSV' => 'CSV'
                            ])
                            ->default('XML')
                            ->required(),
			Toggle::make('namespace'),
                    ])
                    ->columns(2),

                Section::make('Headers da Requisição')
                    ->description('Headers HTTP adicionais (JSON).')
                    ->schema([
                        Forms\Components\KeyValue::make('headers')
                            ->label('Headers (JSON)')
                            ->addButtonLabel('Adicionar Header')
                            ->keyLabel('Header')
                            ->valueLabel('Valor')
                            ->columnSpanFull()
                            ->nullable(),
                    ]),

                Section::make('Autenticação')
                    ->description('Configurações de autenticação.')
                    ->schema([
                        Select::make('autenticacao')
                            ->label('Tipo de Autenticação')
                            ->options([
                                'nenhum'  => 'Nenhum',
                                'basic'   => 'Basic Auth',
                                'bearer'  => 'Bearer Token',
                                'api_key' => 'API Key',
				'oauth2'  => 'OAuth2',
                            ])
                            ->default('nenhum')
			    ->live(),
			Select::make('type_storage_token')
			    ->label('Local de armazenagem do token')
			    ->options([
			        'file' => 'Arquivo (Storage)',
			        'client_token' => 'Token do Cliente (OAuth)',
			        'fixed' => 'Fixo (Campo Auth Token)',
			    ])
			    ->default('fixed')
			    ->required(),
                        TextInput::make('auth_user')
                            ->label('Usuário (Basic)')
                            ->visible(fn ($get) => $get('autenticacao') === 'basic')
                            ->maxLength(100),

                        TextInput::make('auth_pass')
                            ->label('Senha (Basic)')
                            ->password()
                            ->maxLength(255)
                            ->visible(fn ($get) => $get('autenticacao') === 'basic'),

                        Textarea::make('auth_token')
                            ->label('Token (Bearer / API Key)')
                            ->rows(2)
                            ->visible(fn ($get) =>
                                in_array($get('autenticacao'), ['bearer', 'api_key'])
                            ),
                    ])
                    ->columns(2),

                Section::make('Configurações Avançadas')
                    ->schema([
                        Toggle::make('ativo')
                            ->label('Ativo?')
                            ->default(true),

                        TextInput::make('timeout')
                            ->label('Timeout (s)')
                            ->numeric()
                            ->default(30),

                        TextInput::make('tentativas')
                            ->label('Tentativas Máximas')
                            ->numeric()
                            ->default(3),

                        TextInput::make('descricao')
                            ->label('Descrição')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('type_storage_payload')
                            ->label('Tipo de origem do payload')
                            ->options([
				'local' => 'local',
				'database' => 'database',
				'fixed' => 'fixed',
				's3' => 's3',
				'cloud' => 'cloud'
                            ])
                            ->default('local')
                            ->required(),
                        TextInput::make('payload')
                            ->label('Payload')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('timer')
                            ->label('Timer')
                            ->options([
                                '0' => 'desligado',
                                '1'    => 'a cada 1 minuto',
                                '5'    => 'a cada 5 minutos',
                                '10'   => 'a cada 10 minutos',
				'15'   => 'a cada 15 minutos',
				'30'   => 'a cada 30 minutos',
				'60'   => 'a cada 1 hora',
				'180'  => 'a cada 3 horas',
				'360'  => 'a cada 6 horas',
				'720'  => 'a cada 12 horas',
				'1440' => 'a cada dia'
                            ])
                            ->default('0'),
                    ])
                    ->columns(2),

                Section::make('Paginação')
                    ->description('Configuração genérica para APIs paginadas por página.')
                    ->visible(fn ($get) => $get('direcao') !== 'auth')
                    ->schema([
                        Select::make('pagination.type')
                            ->label('Tipo de paginação')
                            ->options([
                                'none' => 'Nenhuma',
                                'page' => 'Página',
                            ])
                            ->default('none')
                            ->live(),
                        TextInput::make('pagination.page_param')
                            ->label('Parâmetro da página')
                            ->default('page')
                            ->required(fn ($get) => $get('pagination.type') === 'page')
                            ->visible(fn ($get) => $get('pagination.type') === 'page'),
                        TextInput::make('pagination.page_start')
                            ->label('Página inicial')
                            ->numeric()
                            ->default(1)
                            ->required(fn ($get) => $get('pagination.type') === 'page')
                            ->visible(fn ($get) => $get('pagination.type') === 'page'),
                        TextInput::make('pagination.page_size_param')
                            ->label('Parâmetro do tamanho')
                            ->default('size')
                            ->required(fn ($get) => $get('pagination.type') === 'page')
                            ->visible(fn ($get) => $get('pagination.type') === 'page'),
                        TextInput::make('pagination.page_size')
                            ->label('Tamanho da página')
                            ->numeric()
                            ->default(100)
                            ->required(fn ($get) => $get('pagination.type') === 'page')
                            ->visible(fn ($get) => $get('pagination.type') === 'page'),
                        TextInput::make('pagination.current_page_path')
                            ->label('Caminho da página atual')
                            ->required(fn ($get) => $get('pagination.type') === 'page')
                            ->visible(fn ($get) => $get('pagination.type') === 'page'),
                        TextInput::make('pagination.total_pages_path')
                            ->label('Caminho do total de páginas')
                            ->required(fn ($get) => $get('pagination.type') === 'page')
                            ->visible(fn ($get) => $get('pagination.type') === 'page'),
                        TextInput::make('pagination.max_pages')
                            ->label('Máximo de páginas')
                            ->numeric()
                            ->default(100)
                            ->required(fn ($get) => $get('pagination.type') === 'page')
                            ->visible(fn ($get) => $get('pagination.type') === 'page'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('nome')->searchable(),
                Tables\Columns\TextColumn::make('tipo'),
                Tables\Columns\TextColumn::make('metodo'),
		Tables\Columns\TextColumn::make('direcao')->label('Direção')->searchable(),
                Tables\Columns\TextColumn::make('extensao')->label('Extensão')->searchable(),
                Tables\Columns\TextColumn::make('autenticacao')->toggleable(isToggledHiddenByDefault: true),
		Tables\Columns\TextColumn::make('client_id')->label('Cliente')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('url')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('auth_user')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('auth_pass')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('ativo')->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('timeout')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tentativas')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('descricao')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCadEndpoints::route('/'),
            'create' => Pages\CreateCadEndpoint::route('/create'),
            'edit'   => Pages\EditCadEndpoint::route('/{record}/edit'),
        ];
    }
}
