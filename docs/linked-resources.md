# Captura de recursos vinculados — incremento 1

Este incremento preserva arquivos referenciados por respostas JSON. Não interpreta
CSV, não aplica De/Para e não envia dados ao ERP. A configuração padrão é vazia;
endpoints sem opt-in mantêm o fluxo anterior, sem acessar a nova tabela.

## Configuração

Após revisar e aplicar a migration no ambiente escolhido, declare a política em
`config/linked_resources.php`. Exemplo genérico (substitua IDs e caminhos):

```php
return [
    'endpoints' => [
        123 => [
            'enabled' => true,
            'collection_path' => 'data',
            'id_path' => 'id',
            'revision_path' => 'version', // opcional: omitir quando não existir
            'url_path' => 'attachment.url',
            'allowed_hosts' => ['downloads.example.com'],
            'max_bytes' => 10 * 1024 * 1024,
            'timeout' => 15,
            'connect_timeout' => 5,
            'max_references' => 20,
        ],
    ],
];
```

A política só aceita endpoints JSON de entrada. Os caminhos usam a notação de
`data_get`: `collection_path` deve resultar em uma lista; os demais são relativos
a cada item. Caminhos com wildcard podem resultar em listas, mas identidade e URL
precisam resultar em escalares individuais. Coleção vazia é válida; coleção
ausente/nula, referência incompleta ou limite excedido falham explicitamente.

Limites rígidos por referência: até 100 MiB, timeout total de até 30 segundos,
conexão/DNS de até 10 segundos; até 20 referências por resposta/página. Valores
fora dos limites são rejeitados. Há uma tentativa de download por execução;
nenhum retry HTTP implícito. Muitos arquivos/páginas prolongam a coleta sequencial.

## Identidade e persistência

`linked_resources` tem unicidade por cliente, endpoint e SHA-256 da representação
JSON de `[identificador externo, revisão]`. Identificadores inteiros são
normalizados para strings; ausência de revisão é diferente de uma revisão vazia
(esta é inválida quando configurada). A URL assinada não faz parte da identidade.

Estados: `pending` → `capturing` → `captured`; falhas operacionais/de segurança
ficam em `failed`, com código interno sanitizado. Uma nova consulta pode atualizar
a URL de um recurso ainda não capturado. Recursos capturados são imutáveis:
redescoberta verifica os bytes locais e não baixa novamente. Sem revisão externa,
a primeira captura é a versão preservada; alterações posteriores na origem não
são detectadas. Escolha uma identidade/revisão apropriada antes de ativar o fluxo.

Arquivos ficam em `integrations`, sob
`linked-resources/{client_id}/{endpoint_id}/{resource_id}/`. A transferência grava
`staging/content.part` com permissão 0600. Tamanho e SHA-256 são registrados antes
do rename para `captured/content`; somente depois o estado vira `captured`.
Publicação interrompida é reconciliada usando esses metadados. Não se sobrescreve
um arquivo final divergente. A conclusão atesta transferência íntegra e hash
local; não autentica semanticamente o conteúdo nem compara um checksum da origem.

O disco deve ser **local**. Locks `flock` por identidade, sem TTL, protegem captura
e recuperação e são liberados pelo sistema quando o processo termina. Os arquivos
de lock permanecem: não os apague com capturas em execução. A restrição UNIQUE
complementa o lock; banco e filesystem continuam sem transação conjunta.

## Segurança

- HTTPS, porta 443, allowlist exata de hosts ASCII em minúsculas; sem credenciais
  embutidas, fragmentos, caracteres de controle ou endereços IP literais.
- Todas as respostas DNS A/AAAA devem ser públicas. IPv6 é restrito a unicast
  global, excluindo endereços especiais. Um IP validado é fixado na conexão cURL,
  mantendo hostname/SNI e verificação TLS, evitando nova resolução na conexão.
- A resolução DNS roda em subprocesso PHP com timeout, via Symfony Process já
  instalado pelo Laravel. Requer PHP CLI, `proc_open` e extensão cURL. Falhas
  fecham a captura; não há fallback menos restritivo.
- Novo handle cURL por download: sem headers, tokens, Basic Auth ou cookies do
  endpoint pai; proxies de ambiente e netrc desativados. Redirects não são seguidos.
- Somente HTTP 200 é aceito; 206 é rejeitado. Streaming limita bytes realmente
  gravados e headers; Content-Length, quando presente, é conferido. Conteúdo
  comprimido é rejeitado; não há descompressão automática.
- URL armazenada com cast criptografado do Laravel e omitida de serializações.
  Preserve `APP_KEY` para recuperar URLs. Mensagens não incluem URL, body remoto
  ou erro textual do transporte. O raw original ainda contém a URL: restrinja
  acesso ao explorador, backups e logs operacionais; não exponha o disco privado.

## Recuperação

```sh
php artisan app:recover-linked-resources --endpoint=123
php artisan app:recover-linked-resources --resource=456
```

Sem `--resource`, o comando redescobre referências nos documentos preservados em
`raw`, `validated`, `processed` e `refused` do endpoint, em ordem dos nomes com
timestamp gerados pelo FetchEndpoints. Assim, documentos mais recentes atualizam
por último URLs pendentes. Depois recupera registros, inclusive capturas
interrompidas antes/depois do rename. `--resource` retoma somente o registro,
sem varrer documentos. Somente endpoints ativos com política habilitada participam.

O comando retorna 1 quando alguma referência/captura continua pendente, falha ou
está ocupada. Captura incompleta é descartada e refeita; staging já verificado é
publicado sem nova chamada. URL expirada exige uma consulta nova à origem; repetir
recuperação não renova assinaturas. Não há agendamento novo automático.

Na coleta, falha de captura preserva o raw e usa o retry existente do endpoint
(cinco minutos). A paginação para na página com falha; arquivos já capturados
permanecem. A próxima coleta deduplica recursos, mas pode gerar novos raws, como
já ocorre nas falhas de paginação existentes.

## Limitações operacionais

Contrato inicial: uma instância, storage local e escritores cooperativos. Não há
suporte anunciado a volumes remotos, múltiplos nós ou manipulação direta de
arquivos durante execução. O lock do recurso não expira; o lock legado de coleta
por endpoint continua com TTL de 900 segundos. Dimensione quantidade de páginas
e arquivos: rodadas longas podem exceder esse TTL, embora as capturas individuais
continuem protegidas pelo lock local e pela identidade no banco.

Recuperação pressupõe retenção dos raws ou dos registros/arquivos necessários.
Mover/excluir manualmente documentos, alterar identidade/caminhos ou restaurar
somente o banco pode impedir reconciliação. Faça backup conjunto de banco,
arquivos e chave de criptografia. Hash divergente exige investigação; não há
substituição automática. Renomear arquivos em disco não oferece uma transação
durável conjunta com o banco em caso de falha física do host.

## Verificação

`vendor/bin/pest tests/Unit/LinkedResourceCaptureTest.php` usa SQLite em memória,
storage falso e transporte/DNS simulados: nenhuma API externa é chamada. Testa
identidade, criptografia, paginação, políticas, SSRF, limites, concorrência,
falhas de publicação/confirmação e recuperação. Os demais testes Unit verificam
regressões. Feature exige o banco dedicado configurado pelo projeto, cujo nome
termina em `_testing`; não execute migrations no banco da aplicação para testar.
