# Ticketeamer — GLPI → Microsoft Teams

Plugin corporativo para **GLPI 11.0.x** que envia automaticamente novos chamados para os técnicos do **grupo técnico associado à categoria ITIL**, usando **conversas privadas 1:1 do Microsoft Teams**.

> Exemplo: um chamado criado com a categoria `Infraestrutura > Computador` usa o grupo técnico configurado na categoria `Infraestrutura > Computador`. Todos os usuários ativos desse grupo que possuem e-mail cadastrado recebem uma mensagem privada no Teams.

## 1. Objetivo

O Ticketeamer resolve o seguinte fluxo operacional:

```text
Novo chamado GLPI
      │
      ▼
Categoria ITIL
      │
      ▼
Grupo técnico da categoria
      │
      ▼
Usuários ativos do grupo
      │
      ▼
Fila de notificações
      │
      ▼
Cron do GLPI
      │
      ▼
Microsoft Graph
      │
      ▼
Chat privado 1:1 no Microsoft Teams
```

A integração é assíncrona: a criação do chamado apenas coloca os destinatários em uma fila local. O envio para o Microsoft Graph é executado pelo mecanismo de tarefas automáticas do GLPI.

Isso evita que uma indisponibilidade do Microsoft Graph deixe o formulário de abertura do chamado lento ou bloqueado.

## 2. Recursos

- Compatibilidade alvo: **GLPI 11.0.x**.
- Compatível com PHP 8.2+.
- Usa o hook `item_add` de `Ticket`.
- Obtém `itilcategories_id` do chamado.
- Lê o `groups_id` configurado na categoria ITIL.
- Obtém usuários ativos do grupo técnico através da API interna do GLPI.
- Usa o e-mail padrão cadastrado no GLPI para localizar o usuário no Microsoft Entra ID.
- Cria/reutiliza uma conversa privada 1:1 no Teams.
- Envia a mensagem através do Microsoft Graph v1.0.
- Fila persistente com tentativas, status e erro.
- Reprocessamento automático de falhas temporárias.
- Refresh token armazenado criptografado com libsodium.
- Client Secret e chave de criptografia ficam fora do banco, via variáveis de ambiente.
- Tela de configuração dentro do GLPI.
- OAuth 2.0 Authorization Code com `offline_access`.
- Log de falhas no sistema de logs do GLPI.

## 3. Ponto importante sobre o Teams

O envio para uma conversa privada é feito via **Microsoft Graph**, não via webhook de canal.

A API de envio de `chatMessage` exige permissão delegada `ChatMessage.Send`; a criação da conversa 1:1 usa `Chat.Create`. A API de criação de chat retorna a conversa existente quando já existe uma conversa 1:1 entre os mesmos membros. Portanto, o plugin pode executar o fluxo sem criar uma nova conversa a cada chamado.

Referências oficiais:

- Microsoft Graph — enviar mensagem em chat: https://learn.microsoft.com/en-us/graph/api/chat-post-messages?view=graph-rest-1.0
- Microsoft Graph — criar chat: https://learn.microsoft.com/en-us/graph/api/chat-post?view=graph-rest-1.0
- Microsoft Graph — permissões: https://learn.microsoft.com/en-us/graph/permissions-reference

### Restrição importante

O fluxo foi desenhado para **autenticação delegada**. O token representa uma conta Microsoft que será o remetente das mensagens no Teams.

Não foi usado um token application-only para enviar mensagens comuns, porque a documentação atual do Microsoft Graph limita o envio de `chatMessage` com permissões de aplicação a cenários de migração.

## 4. Como o roteamento funciona

A categoria ITIL possui um campo de **grupo técnico** (`groups_id`). O GLPI documenta que uma categoria pode ter uma pessoa e/ou grupo técnico associado, usado para notificações e atribuição automática.

O plugin não cria uma nova estrutura paralela de categorias/grupos.

Exemplo:

```text
Categoria:
Infraestrutura
└── Computador

Grupo técnico da categoria:
Infraestrutura

Membros:
- João Silva
- Maria Souza
```

Se um chamado for criado com `Infraestrutura > Computador`, o plugin encontra o grupo técnico da categoria e coloca João e Maria na fila de notificações.

### Observação

O plugin considera o `groups_id` da **categoria selecionada no chamado**. Isso é intencional: permite que categorias filhas tenham grupos técnicos diferentes.

## 5. Estrutura do projeto

```text
ticketeamer/
├── setup.php
├── hook.php
├── composer.json
├── myplugin.xml
├── README.md
├── CHANGELOG.md
├── LICENSE
├── .gitignore
├── front/
│   └── config.form.php
├── src/
│   ├── Config.php
│   ├── Crypto.php
│   ├── GraphClient.php
│   ├── MessageBuilder.php
│   ├── Queue.php
│   ├── QueueTask.php
│   ├── Controller/
│   │   ├── ConfigController.php
│   │   └── OAuthCallbackController.php
│   └── Hook/
│       └── TicketHook.php
└── templates/
    └── config.html.twig
```

## 6. Instalação

### 6.1 Copiar o plugin

Extraia a pasta `ticketeamer` para:

```text
<GLPI>/plugins/ticketeamer
```

O nome da pasta **não deve ser alterado**.

Depois, no GLPI:

```text
Configuração → Plugins
```

Localize **Ticketeamer**, clique em **Instalar** e depois em **Ativar**.

## 7. Variáveis de ambiente

O plugin exige duas variáveis de ambiente.

### Client Secret

```text
GLPI_TEAMS_BRIDGE_CLIENT_SECRET
```

É o secret criado no App Registration do Microsoft Entra ID.

### Chave de criptografia

```text
GLPI_TEAMS_BRIDGE_ENCRYPTION_KEY
```

Use uma string aleatória forte, por exemplo gerada com:

```bash
openssl rand -hex 32
```

Exemplo Linux/systemd/Apache:

```text
GLPI_TEAMS_BRIDGE_CLIENT_SECRET=SEU_CLIENT_SECRET
GLPI_TEAMS_BRIDGE_ENCRYPTION_KEY=UMA_CHAVE_LONGA_E_ALEATORIA
```

**Não coloque essas duas informações no Git.**

Depois de alterar variáveis de ambiente, reinicie o PHP-FPM/Apache conforme o seu ambiente.

## 8. Criar o App Registration no Microsoft Entra ID

No portal do Microsoft Entra ID:

```text
Microsoft Entra ID
  → App registrations
  → New registration
```

Crie uma aplicação, por exemplo:

```text
GLPI Ticketeamer
```

### 8.1 Redirect URI

Adicione uma plataforma Web com a URL configurada no plugin:

```text
https://SEU-GLPI/plugins/ticketeamer/oauth/callback
```

A URL deve ser exatamente igual no GLPI e no Entra ID.

### 8.2 Client Secret

Em:

```text
Certificates & secrets
→ New client secret
```

Copie o valor do secret e coloque na variável:

```text
GLPI_TEAMS_BRIDGE_CLIENT_SECRET
```

Não use o Secret ID no lugar do Secret Value.

### 8.3 Permissões Microsoft Graph

Em:

```text
API permissions
→ Microsoft Graph
→ Delegated permissions
```

Adicione:

```text
User.Read
User.ReadBasic.All
Chat.Create
ChatMessage.Send
offline_access
openid
profile
```

As permissões principais para o fluxo são:

| Permissão | Finalidade |
|---|---|
| `User.Read` | Identificar a conta que autorizou a integração |
| `User.ReadBasic.All` | Localizar o técnico pelo e-mail e obter o ID Entra |
| `Chat.Create` | Criar/reutilizar conversa 1:1 |
| `ChatMessage.Send` | Enviar mensagem no chat |
| `offline_access` | Permitir renovação do token |
| `openid` / `profile` | Fluxo de autenticação |

Dependendo das políticas do tenant, o administrador poderá precisar conceder consentimento para a organização.

## 9. Configurar o plugin

No GLPI, abra a configuração do plugin e preencha:

```text
Tenant ID
Client ID
Redirect URI
```

Exemplo:

```text
Tenant ID:
xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx

Client ID:
yyyyyyyy-yyyy-yyyy-yyyy-yyyyyyyyyyyy

Redirect URI:
https://glpi.empresa.local/plugins/ticketeamer/oauth/callback
```

Salve.

Depois clique em:

```text
Autorizar Microsoft Teams
```

Entre com a conta Microsoft que será utilizada como remetente das mensagens.

Após o consentimento, o plugin armazena somente o refresh token criptografado.

## 10. Conta Microsoft usada pelo plugin

Recomenda-se criar uma conta técnica dedicada, por exemplo:

```text
GLPI Notifications
```

ou:

```text
glpi.notifications@empresa.com
```

Essa conta será a identidade que aparecerá como remetente das mensagens privadas.

Recomendação corporativa:

- Não usar a conta pessoal de um administrador.
- Aplicar MFA conforme política da empresa.
- Definir política de acesso adequada.
- Monitorar expiração/revogação do consentimento.
- Controlar o Client Secret no secret manager da empresa.
- Rotacionar o Client Secret periodicamente.

## 11. Configurar as categorias do GLPI

No GLPI:

```text
Configuração
→ Dropdowns
→ Categorias ITIL
```

Para cada categoria que deve disparar Teams, configure o **Grupo em cargo / Grupo técnico**.

Exemplo:

```text
Infraestrutura
└── Computador
    Grupo técnico: Infraestrutura
```

Depois confirme os membros em:

```text
Administração
→ Grupos
→ Infraestrutura
```

Os técnicos precisam:

1. Estar no grupo.
2. Estar ativos no GLPI.
3. Ter e-mail padrão cadastrado.
4. Ter uma conta correspondente no Microsoft Entra ID.

## 12. Configurar o cron do GLPI

O plugin registra uma tarefa automática chamada:

```text
Ticketeamer → Process
```

O cron do GLPI precisa estar funcionando.

Exemplo de execução externa:

```bash
php <GLPI>/front/cron.php
```

Consulte a configuração oficial do cron do seu ambiente GLPI.

Para um ambiente corporativo, recomenda-se execução externa pelo scheduler do sistema, em vez de depender somente do tráfego de usuários no GLPI.

## 13. Exemplo completo

Imagine:

```text
Grupo técnico:
Infraestrutura

Membros:
- Carlos → carlos@empresa.com
- Fernanda → fernanda@empresa.com
```

Categoria:

```text
Infraestrutura > Computador
```

Chamado:

```text
Título: Computador não liga
```

O GLPI cria o chamado.

O hook executa:

```text
Ticket #1234
  ↓
itilcategories_id = Infraestrutura > Computador
  ↓
groups_id = Infraestrutura
  ↓
Group_User::getGroupUsers()
  ↓
Carlos + Fernanda
  ↓
Fila
```

O cron processa a fila:

```text
Ticket #1234
  ↓
Carlos
  ↓
Chat 1:1
  ↓
Mensagem
```

e depois:

```text
Ticket #1234
  ↓
Fernanda
  ↓
Chat 1:1
  ↓
Mensagem
```

Cada técnico recebe a notificação em uma conversa privada.

## 14. Mensagem enviada

O formato padrão é aproximadamente:

```text
Novo chamado GLPI

Chamado: #1234
Título: Computador não liga
Categoria: Infraestrutura > Computador
Status: Novo
Prioridade: 3

Abrir chamado no GLPI
```

O link aponta diretamente para o chamado.

## 15. Fila e tolerância a falhas

A tabela criada pelo plugin é:

```text
glpi_plugin_ticketeamer_queue
```

Ela possui, entre outros:

| Campo | Finalidade |
|---|---|
| `tickets_id` | Chamado relacionado |
| `users_id` | Técnico GLPI |
| `recipient_email` | Destinatário Microsoft |
| `status` | Estado da entrega |
| `attempts` | Quantidade de tentativas |
| `last_error` | Último erro |
| `sent_at` | Data/hora de envio |
| `date_creation` | Criação da fila |
| `date_mod` | Última alteração |

Estados:

```text
pending
processing
sent
failed
```

Se o Microsoft Graph estiver indisponível, o item retorna para `pending` até atingir o limite configurado.

Se o processo morrer enquanto um item estiver em `processing`, itens antigos são recuperados pelo próximo ciclo do cron.

## 16. Segurança

### Segredos

O Client Secret não é armazenado pelo plugin.

Ele deve estar em:

```text
GLPI_TEAMS_BRIDGE_CLIENT_SECRET
```

O refresh token é armazenado criptografado usando:

```text
libsodium / secretbox
```

A chave fica em:

```text
GLPI_TEAMS_BRIDGE_ENCRYPTION_KEY
```

### Nunca versionar

Não faça commit de:

```text
Client Secret
Refresh Token
Encryption Key
Tokens de acesso
Dump do banco contendo o refresh token
```

## 17. Logs

Falhas da fila são registradas no log do GLPI usando o canal/arquivo:

```text
ticketeamer
```

Procure mensagens como:

```text
Queue #15 failed: Microsoft Graph error: ...
```

## 18. Diagnóstico

### O chamado foi aberto mas nada foi enviado

Verifique:

1. Plugin ativo.
2. Plugin habilitado na configuração.
3. Categoria possui grupo técnico.
4. Grupo possui usuários ativos.
5. Usuários possuem e-mail padrão.
6. E-mails correspondem às contas Microsoft.
7. OAuth foi autorizado.
8. `GLPI_TEAMS_BRIDGE_CLIENT_SECRET` está disponível para o processo PHP/cron.
9. `GLPI_TEAMS_BRIDGE_ENCRYPTION_KEY` está disponível para o processo PHP/cron.
10. Cron do GLPI está executando.
11. Tarefa `Ticketeamer → Process` está ativa.
12. Logs do GLPI.

### Erro de usuário Microsoft não encontrado

O plugin usa o e-mail padrão do usuário GLPI para localizar:

```http
GET /users/{userPrincipalName}
```

Confirme se o e-mail cadastrado no GLPI corresponde ao `userPrincipalName` ou a uma identidade aceita pelo tenant.

### Erro de permissão

Revise as permissões delegadas:

```text
User.Read
User.ReadBasic.All
Chat.Create
ChatMessage.Send
```

Se a política do tenant exigir, aplique o consentimento administrativo.

### Token expirado/revogado

Na tela de configuração, execute novamente:

```text
Autorizar Microsoft Teams
```

## 19. Desenvolvimento

Requisitos:

- PHP 8.2+
- GLPI 11.0.x
- Composer opcional
- PHPUnit opcional

O projeto utiliza PSR-4 em `src/` e inclui um autoloader mínimo para funcionar imediatamente após a instalação.

Se quiser utilizar Composer:

```bash
composer install
```

## 20. Testes estáticos

Validar sintaxe PHP:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Validar estilo com o coding standard do GLPI:

```bash
composer require --dev glpi-project/coding-standard
vendor/bin/phpcs -p --ignore=vendor --standard=vendor/glpi-project/coding-standard/GlpiStandard/ .
```

## 21. Arquitetura

### Hook

`TicketHook` é responsável somente por detectar o novo chamado, identificar o grupo técnico e alimentar a fila.

### Queue

`Queue` encapsula persistência e transições da fila.

### QueueTask

`QueueTask` integra a fila ao scheduler do GLPI.

### GraphClient

`GraphClient` concentra:

- OAuth token exchange.
- Refresh token.
- Consulta de usuário.
- Criação/reutilização de chat.
- Envio de mensagem.

### MessageBuilder

`MessageBuilder` transforma os dados do chamado em HTML compatível com o Teams.

### Crypto

`Crypto` protege o refresh token em repouso.

### Controllers

Os controllers seguem o mecanismo de controllers do GLPI 11 para configuração e callback OAuth.

## 22. Decisões de arquitetura

### Por que não enviar diretamente dentro do hook?

Porque uma requisição para o Microsoft Graph pode falhar ou ficar lenta.

O hook deve ser rápido:

```text
GLPI → grava fila → termina criação do chamado
```

O cron faz:

```text
fila → Graph → Teams
```

### Por que usar o grupo da categoria?

Porque o GLPI já possui o conceito de grupo técnico associado à categoria ITIL. O plugin reutiliza a configuração nativa em vez de criar uma segunda regra de roteamento.

### Por que usar e-mail?

É o identificador mais simples para correlacionar o usuário GLPI com o usuário Microsoft Entra sem exigir uma coluna adicional no usuário do GLPI.

### Por que usar conta técnica?

Evita que o funcionamento dependa da conta pessoal de um administrador ou técnico.

## 23. Limitações conhecidas

- O remetente do Teams é a conta Microsoft que autorizou o OAuth.
- O usuário GLPI precisa ter e-mail compatível com a identidade do Entra ID.
- O fluxo atual considera o grupo técnico da categoria selecionada no chamado.
- O plugin não altera automaticamente a atribuição do chamado; ele apenas notifica.
- O plugin depende do cron do GLPI para entrega assíncrona.
- A política/licenciamento do Microsoft Teams da organização pode afetar a capacidade da conta técnica de enviar chats.

## 24. Compatibilidade GLPI 11

O projeto foi estruturado para a linha GLPI 11 e usa os mecanismos documentados para:

- hooks de itens;
- controllers;
- Twig;
- tarefas automáticas;
- API interna de grupos/usuários;
- configuração do plugin.

A faixa declarada pelo plugin é:

```text
>= 11.0.0
< 12.0.0
```

Recomenda-se validar o plugin no patch release exato do GLPI utilizado antes de colocar em produção.

## 25. Referências oficiais

### GLPI

- Plugin development: https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/index.html
- Plugin requirements: https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/requirements.html
- Hooks: https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/hooks.html
- Controllers: https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/controllers.html
- Automatic actions: https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/crontasks.html
- GLPI 11 upgrade notes: https://glpi-developer-documentation.readthedocs.io/en/latest/upgradeguides/glpi-11.0.html
- ITIL categories: https://help.glpi-project.org/documentation/modules/configuration/dropdowns/categories

### Microsoft

- Create chat: https://learn.microsoft.com/en-us/graph/api/chat-post?view=graph-rest-1.0
- Send message in chat: https://learn.microsoft.com/en-us/graph/api/chat-post-messages?view=graph-rest-1.0
- Get user: https://learn.microsoft.com/en-us/graph/api/user-get?view=graph-rest-1.0
- Microsoft Graph permissions: https://learn.microsoft.com/en-us/graph/permissions-reference

## 26. Licença

GPL-3.0-or-later.
