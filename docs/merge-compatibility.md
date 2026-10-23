# Integração de main com wip/eai-before-algar-auth-fix

As duas branches usavam contratos diferentes para armazenamento e OAuth. A
resolução mantém `integrations` como disco privado canónico e o estado OAuth
vinculado ao utilizador e à sessão da `main`.

- Novas respostas raw continuam byte a byte em `integrations`, como exige o
  pipeline e a captura de recursos vinculados. Não são duplicadas no disco
  `local` nem publicadas no disco `public`.
- Tokens antigos criptografados em `local` continuam legíveis como fallback.
  Tokens em `integrations` têm prioridade. Um token corrompido não provoca
  fallback para credenciais públicas.
- Quando a pasta raw privada está vazia, validação JSON/XML importa os raws
  locais criptografados; envio importa saídas antigas locais ou públicas.
  A cópia privada alimenta o pipeline, mantendo o original para recuperação.
  Divergências não são sobrescritas. Arquivos já validados, recusados ou
  processados não são reintroduzidos. Para migração em massa e pastas mistas,
  utilize uma operação de migração revisada; a compatibilidade automática é
  deliberadamente limitada ao esvaziamento da fila privada.
- Validação JSON continua a exigir schema. Nenhuma resolução volta a gravar
  resultados no disco público ou a ignorar schemas ausentes.
- `auth_api_way` é preservado no cadastro e model: `header` é o padrão; outro
  valor identifica o campo JSON de autenticação no body. O payload arquivado
  não recebe o token. Paginação, locks, agendamento após resultado e captura
  de recursos vinculados permanecem ativos.
- A deduplicação por minuto da branch WIP só marca uma coleta concluída quando
  todas as páginas e capturas terminaram. Falhas parciais continuam elegíveis
  para nova tentativa; raws mantêm estado pendente de validação. Falhas
  repetidas conservam o estado 5 conforme o limite de tentativas existente.
- URLs OAuth precisam de HTTPS. O início da autorização passa pelo controller
  autenticado; o callback usa estado de sessão de uso único e não expõe erros
  do provedor. Autorizações pendentes emitidas pelo antigo mecanismo de cache
  precisam de ser iniciadas novamente. Falha do provedor também requer nova
  autorização, em vez de reutilizar o mesmo estado/código.
- Diagnóstico de envio conserva status HTTP e identificador de correlação
  limitado, sem incluir bodies externos ou URLs assinadas nas mensagens.

Os testes antigos que esperavam publicação em `public`, raw criptografado em
`local` ou callback OAuth sem sessão foram adaptados ao contrato combinado.
Casos adicionais verificam token no body, recuperação de paginação parcial e
ausência de reenvio de saídas legadas importadas.
