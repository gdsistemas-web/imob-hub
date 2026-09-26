# IMOB Hub — CRM imobiliário VLN Info

Fundação funcional do CRM: SPA React/TypeScript, API REST em PHP 8.3 e MySQL 8. O modelo separa contatos de oportunidades, permitindo vários interesses para a mesma pessoa sem duplicar seu histórico.

## O que funciona

- login/logout com token armazenado somente em hash, perfis `admin`, `manager`, `sdr` e `broker` e autorização na API;
- dashboard com contagens reais, lista/busca/filtro, criação, detalhe e atualização de leads;
- origem, responsáveis, histórico cronológico, follow-ups e movimentação validada do funil (perda exige motivo);
- importação CSV com relatório por linha e webhook local autenticado;
- deduplicação por fonte + identificador externo; contato é reutilizado apenas quando e-mail/telefone encontra uma correspondência inequívoca;
- payload de integração persistido para auditoria, mascarando campos sensíveis comuns;
- conectores cadastrados com estado e requisitos, sem fingir conexão ativa.

## Instalação e execução

Requisitos: PHP 8.3 com `pdo_mysql`, Node 22/npm e MySQL 8 (ou Docker).

```bash
cp .env.example backend/.env
# ajuste DB_PASS para imob_hub_local se usar o compose
docker compose up -d mysql
php backend/database/migrate.php
php backend/database/seed.php
php -S localhost:8080 -t backend/public backend/public/router.php
```

Em outro terminal:

```bash
cd frontend
npm install
npm run dev
```

Acesse `http://localhost:5173` para o site público e `http://localhost:5173/login` (ou **Área do corretor**) para o painel, que fica em `/painel`. Para produção, sirva `frontend/dist` (gerado por `npm run build`) e aponte `/api` para `backend/public` sob HTTPS. O PHP embutido é apenas para desenvolvimento.

## Deploy com Coolify

A stack de produção fica em `compose.coolify.yml`. Ela é uma **opção adicional**: o desenvolvimento local continua igual (`docker compose up -d mysql` + `php -S` + `npm run dev`), e o `docker-compose.yml` da raiz segue sendo só o MySQL de desenvolvimento.

### 1. Requisitos

Servidor Linux com Docker e Coolify v4. O repositório precisa estar acessível pelo Coolify, e o branch é `main`. Você vai precisar de um domínio apontando para o servidor, porque o Coolify emite o certificado HTTPS.

### 2. Arquitetura (`compose.coolify.yml`)

| Serviço | Imagem | Função | Porta |
|---|---|---|---|
| `web` | `docker/nginx/Dockerfile` (Node 22 compila o React; imagem final `nginx-unprivileged`) | Serve o SPA com fallback para `index.html`, repassa `/api/*` ao PHP-FPM e serve `/uploads/*` (só imagens) | 8080, **a única publicada** (via proxy do Coolify) |
| `php` | `docker/php/Dockerfile` (`php:8.3-fpm` + pdo_mysql, gd, exif e opcache) | API existente, pelo front controller `backend/public/index.php` | 9000, só na rede interna |
| `mysql` | `mysql:8.0` | Banco | 3306, só na rede interna |

O Nginx entrega ao PHP apenas o `index.php`; nenhum outro arquivo do backend fica acessível. Os dois containers de aplicação rodam sem root (`www-data` e `nginx`). O backend não tem dependências Composer, então não há `composer install`. `display_errors` fica desligado e os erros vão para o log do container. Os healthchecks são: `mysqladmin ping`; `ping` do PHP-FPM (via `cgi-fcgi`, sem rota pública nova); e resposta HTTP do Nginx. Cada serviço só sobe depois que o anterior está saudável.

### 3. Variáveis de ambiente (cadastrar no Coolify)

| Variável | Obrigatória | Observação |
|---|---|---|
| `APP_URL` | sim | URL pública com `https://`. É usada nos links das fotos e no CORS. |
| `MYSQL_PASSWORD` | sim | Senha do usuário da aplicação. |
| `MYSQL_ROOT_PASSWORD` | sim | Senha do root do MySQL. |
| `APP_ENCRYPTION_KEY` | sim | 32+ caracteres (`openssl rand -hex 32`). Cifra os dados das fichas: **guarde em cofre e nunca troque** depois de haver dados. |
| `WEBHOOK_TOKEN` | sim | Token do webhook de leads (`/api/webhooks/local`). |
| `MYSQL_DATABASE`, `MYSQL_USER` | não | Padrão `imob_hub`. |
| `APP_TIMEZONE` | não | Padrão `America/Sao_Paulo`. |
| `UPLOAD_MAX_BYTES` | não | Padrão 10485760 (10 MB). O Nginx aceita até 14 MB por requisição. |
| `CONNECTOR_INTERNAL_TOKEN` | não | Só se usar o conector de WhatsApp. |
| `DOCUMENSO_URL`, `DOCUMENSO_API_KEY`, `DOCUMENSO_WEBHOOK_SECRET` | não | Integração de assinatura. Sem elas, os contratos seguem pelo fluxo manual. O Documenso é implantado à parte. |

O banco é acessado pelo nome do serviço (`mysql`), montado no `DB_DSN` pelo próprio compose. `PRIVATE_STORAGE_PATH` também é fixado pelo compose. A lista com valores de exemplo está em `docker/coolify.env.example`. Nenhum segredo real fica no repositório.

### 4. Criar o recurso no Coolify

1. **+ New → Resource → Public Repository** (ou *Private Repository (GitHub App)*, se o repositório for privado) e informe `https://github.com/gdsistemas-web/imob-hub`.
2. **Branch:** `main`. **Build Pack:** `Docker Compose`. **Base Directory:** `/`. **Docker Compose Location:** `/compose.coolify.yml`.
3. Na lista de serviços, **apenas em `web`**, preencha o domínio **com a porta do container**, por exemplo `https://imob.seudominio.com.br:8080`. Deixe `php` e `mysql` sem domínio.
4. Em **Environment Variables**, preencha as obrigatórias da tabela acima. O Coolify lista as variáveis que encontra no compose.

### 5. Primeiro deploy

Clique em **Deploy**. O Coolify constrói as imagens `web` e `php`, sobe o MySQL e aguarda os healthchecks. Depois, pelo **Terminal** do Coolify, no container `php`:

```bash
php backend/database/migrate.php      # cria as tabelas e os dados estruturais (ex.: origens de lead)
php backend/bin/create_admin.php      # cria o primeiro administrador (interativo; a senha não aparece na tela)
```

Os demais usuários (gestores, SDRs, corretores e analistas) são criados pelo administrador em **Equipe e distribuição**.

> **Não use `backend/database/seed.php` em produção.** Ele serve só para desenvolvimento e homologação: cria usuários de demonstração com a senha conhecida `Demo@123`, leads e imóveis fictícios, e não é idempotente. O banco de produção não depende dele.

### 6. Migrations (ação manual)

Pelo **Terminal** do Coolify, no container `php` (o diretório de trabalho é `/var/www/html`):

```bash
php backend/database/migrate.php
```

O migrador registra cada arquivo em `schema_migrations` e ignora os já aplicados, então pode ser rodado a cada deploy que traga migrations novas. Faça backup do banco antes. A migration `013_lead_sources.sql` cria as origens de lead (local, site, whatsapp, olx, quintoandar, email, social, partner) apenas quando o código ainda não existe; ela nunca altera origens já gravadas.

`create_admin.php` também pode ser usado depois, para criar outro administrador. Ele aplica a mesma regra da tela Equipe (senha com ao menos 8 caracteres e e-mail único) e recusa e-mails já cadastrados.

**Erros internos:** falhas inesperadas (banco fora do ar, erro de SQL etc.) respondem `500` com mensagem genérica e um `error_id`. O detalhe técnico fica só no log do `php`, na linha `[erro <error_id>]`.

**Notificações de prazo:** em *Scheduled Tasks*, crie uma tarefa no container `php` com o comando `php backend/bin/generate_notifications.php` e a frequência `*/10 * * * *`.

### 7. Volumes persistentes

| Volume | Montado em | Conteúdo |
|---|---|---|
| `mysql-data` | `mysql:/var/lib/mysql` | Banco de dados |
| `uploads` | `php:/var/www/html/backend/public/uploads` (e `web:/srv/uploads`, somente leitura) | Fotos dos imóveis (públicas) |
| `private-storage` | `php:/var/www/private-storage` | Documentos das fichas e contratos (PDF gerado e assinado). Nunca é servido pelo Nginx. |

Os três precisam entrar na rotina de backup. O código da aplicação faz parte da imagem e não é montado como volume.

### 8. Logs

Use a aba **Logs** de cada serviço no Coolify. Erros do PHP e acessos do FPM saem no log do `php`; requisições e erros de proxy, no do `web`. No servidor: `docker logs -f <container>`.

### 9. Redeploy

Com um push no `main`, use **Redeploy** (ou ative o deploy automático por webhook do Git). As imagens são reconstruídas e os volumes preservados. Se o deploy trouxer migrations novas, rode `php backend/database/migrate.php` depois.

### 10. Desenvolvimento local × Coolify

| | Desenvolvimento | Coolify |
|---|---|---|
| Frontend | `npm run dev` (Vite, porta 5173, proxy de `/api`) | Build de produção servido pelo Nginx |
| Backend | `php -S localhost:8080 … router.php` | PHP-FPM atrás do Nginx |
| Banco | `docker compose up -d mysql` (porta 3306 aberta no host) | MySQL interno, sem porta publicada |
| Configuração | `backend/.env` | Variáveis de ambiente do Coolify |
| Arquivos | `backend/public/uploads` e `PRIVATE_STORAGE_PATH` locais | Volumes `uploads` e `private-storage` |

**Testar a stack de produção na sua máquina** (com Docker disponível para o usuário):

```bash
cp docker/coolify.env.example docker/coolify.env   # troque as senhas; o arquivo é ignorado pelo Git
docker compose -f compose.coolify.yml -f docker/compose.local.yml --env-file docker/coolify.env up -d --build
docker compose -f compose.coolify.yml -f docker/compose.local.yml --env-file docker/coolify.env exec php php backend/database/migrate.php
docker compose -f compose.coolify.yml -f docker/compose.local.yml --env-file docker/coolify.env exec php php backend/bin/create_admin.php
# acesse http://localhost:8088
```

O `docker/compose.local.yml` apenas publica o `web` em `127.0.0.1:8088`. Não o use no Coolify.

## Usuários de demonstração

Senha de todos: `Demo@123`.

- `admin@vlninfo.local`
- `gestor@vlninfo.local`
- `sdr@vlninfo.local`
- `corretor@vlninfo.local`
- `analista@vlninfo.local` (analista de crédito)

O seed também cria mais 3 SDRs e 5 corretores (mesma senha) só para dar variedade real aos gráficos por corretor/SDR e ao rodízio; a apresentação em si usa apenas os 4 usuários acima.

## Testes

Os testes usam SQLite em memória para não alterar o MySQL local (requer `pdo_sqlite`):

```bash
php -d zend.assertions=1 -d assert.exception=1 backend/tests/run.php
npm --prefix frontend run build
```

Para validar no MySQL após migration e seed:

```bash
DB_DSN='mysql:host=127.0.0.1;dbname=imob_hub;charset=utf8mb4' DB_USER=imob_hub DB_PASS='sua-senha' \
  php -d zend.assertions=1 -d assert.exception=1 backend/tests/mysql_flow.php
```

Teste do webhook após configurar `WEBHOOK_TOKEN`:

```bash
curl -X POST http://localhost:8080/api/webhooks/local -H 'Content-Type: application/json' -H 'X-Webhook-Token: troque-este-token' -d '{"name":"Lead Webhook","email":"lead@teste.local","external_id":"teste-001","interest_type":"purchase","region":"Centro"}'
```

CSV mínimo: `name,email,phone,source_code,external_id,interest_type,region`; `source_code` pode ser `local`, `site`, `olx`, `quintoandar`, `whatsapp`, `email`, `social` ou `partner`.

## Operação SDR, nutrição e distribuição

Novos leads são atribuídos ao primeiro SDR ativo e recebem prazo inicial de 30 minutos. A caixa de entrada permite registrar tentativa ou contato concluído e concluir a triagem. Nutrição exige motivo e próximo contato; “sem interesse” e “inválido” exigem motivo. A oportunidade pode ser requalificada pela mesma tela.

O SDR pode distribuir oportunidades qualificadas ainda sem corretor. Gestor e administrador podem redistribuir; nesse caso, o motivo é obrigatório. Corretores inativos nunca são elegíveis. O corretor confirma recebimento e início do atendimento em **Minhas oportunidades** e não acessa carteiras alheias.

Em **Configurar rodízio**, gestor/admin pode ativar ou desativar a regra. O rodízio percorre corretores ativos em ordem estável de ID. No MySQL, lê e atualiza o ponteiro singleton com `SELECT ... FOR UPDATE`, na mesma transação que cria o registro de distribuição. Isso serializa atribuições simultâneas e impede corrupção do ponteiro. Desativado, somente distribuição manual funciona.

Indicadores usam apenas dados persistidos: aguardando primeiro contato = triagens pendentes/contatadas sem `first_service_at`; tempo médio = minutos entre criação e primeiro contato concluído; nutrição = `triage_status=nurturing`; vencidos = tarefa pendente anterior ao momento atual; qualificados por SDR = `qualified_at` nos últimos 30 dias; distribuídos por corretor = registros em `distributions`; sem corretor = qualificados com `broker_id` nulo.

Após atualizar uma instalação existente, faça backup e execute `php backend/database/migrate.php`. A tabela `schema_migrations` evita reaplicações; o migrador reconhece automaticamente uma base da primeira versão pela tabela `users` e registra a migration inicial antes de aplicar a segunda.

## Operação comercial e fechamento

A área **Minhas oportunidades** leva o corretor ao detalhe comercial, onde ficam necessidades do SDR, contato, origem, imóveis apresentados, visitas, propostas, negociações, follow-ups e histórico. Gestor e administrador acompanham tudo em **Visão gerencial**, com filtros de etapa e estrutura preparada para período/corretor na API.

O cadastro de imóveis é intencionalmente básico. Visitas conflitantes no intervalo de uma hora antes/depois para o mesmo corretor são recusadas; cancelamentos permanecem registrados e exigem motivo. Propostas validam valor positivo e compatibilidade entre interesse, finalidade do imóvel e tipo da proposta. Cada alteração gera uma versão em `proposal_events`. Transições permitidas: rascunho → enviada/cancelada; enviada → em análise/aceita/recusada/cancelada; em análise → aceita/recusada/cancelada. Estados finais não retornam a estados anteriores.

Ganho exige imóvel, venda/locação, valor final positivo e data. Perda exige motivo e data. O fechamento preserva visitas, propostas, versões, negociações e histórico. Somente gestor/admin reabre, sempre com motivo; a oportunidade retorna para negociação. O campo de arquivo contratual foi preparado, mas upload não foi habilitado sem uma estratégia definida de armazenamento privado, antivírus, autorização de download e limites de arquivo.

Indicadores comerciais são calculados do banco: visitas por status; propostas por status; negociação pela etapa atual; vendas e locações por `closings.business_type`; valores financeiros são separados entre vendas e locações; conversão é ganhos dividido por oportunidades atribuídas a cada corretor; perdas são agrupadas pelo motivo registrado.

### Validação MySQL

Em 23/09/2026, as migrations `001`, `002` e `003`, o seed e o teste integrado foram executados com sucesso em MySQL 8.0.46 inicializado isoladamente. O teste cobriu captação, qualificação, distribuição, visita e conflito de agenda, proposta e versões, negociação, ganho e reabertura. Para repetir localmente, suba o MySQL, configure `DB_DSN`, `DB_USER` e `DB_PASS`, rode `php backend/database/migrate.php`, `php backend/database/seed.php` e o comando `backend/tests/mysql_flow.php` acima.

## Integrações e próxima etapa

## Agenda, notificações, documentos e auditoria

A agenda operacional consulta diretamente tarefas/follow-ups, visitas, validade de propostas e contatos de nutrição. Compromissos comerciais usam `calendar_events` e aparecem pelo filtro **Compromissos**; eles não copiam visitas ou tarefas. Ações de concluir, reagendar e cancelar tarefas registram usuário e data. Consultas são limitadas a 500 itens por período.

Notificações de atribuição, redistribuição, visitas e fechamentos são criadas durante as ações da API. Prazos de tarefas e propostas são verificados pelo comando idempotente abaixo; a chave única por usuário/evento evita duplicidade:

```bash
*/10 * * * * cd /caminho/imob-hub && /usr/bin/php backend/bin/generate_notifications.php >> /var/log/imob-hub-notifications.log 2>&1
```

As notificações são somente internas. Nenhuma mensagem de WhatsApp, SMS ou e-mail é enviada.

### Armazenamento privado

Configure um diretório fora de `backend/public`, gravável exclusivamente pelo usuário do PHP:

```env
PRIVATE_STORAGE_PATH=/srv/imob-hub-private
UPLOAD_MAX_BYTES=10485760
```

O upload aceita PDF, JPEG, PNG e WebP, detecta o MIME real com `finfo`, gera nome aleatório, calcula SHA-256 e nunca publica o caminho físico. Downloads passam por autenticação e autorização da oportunidade; exclusões são lógicas e uploads, downloads e remoções entram na auditoria. O servidor deve impedir execução nesse diretório. Não foi encontrado antivírus no ambiente, portanto os documentos **não foram examinados contra malware**; em produção, integre ClamAV ou serviço equivalente antes de liberar arquivos.

Relatórios aplicam período, origem, SDR, corretor e tipo de negócio na mesma consulta usada pela tela/CSV. Corretores e SDRs recebem escopo automático da própria carteira. A interface é paginável por domínio e o CSV tem limite de 10.000 linhas, separador `;` e BOM UTF-8 para planilhas. A auditoria, exclusiva de gestor/admin, registra distribuição, propostas, visitas, fechamentos, reaberturas e documentos sem tokens, senhas ou conteúdo de credenciais.

Na validação da quarta etapa, `004_operations_audit.sql` foi aplicada incrementalmente sobre as migrations `001`–`003` em MySQL 8.0.46. Foram testados no MySQL: regressão comercial, unicidade de notificações, auditoria sanitizada, upload válido, download autenticado, remoção lógica, rejeição por tamanho e rejeição pelo MIME real. O build e os testes SQLite também passaram.

OLX/Canal Pro e QuintoAndar exigem documentação, credenciais, permissões, formato e autenticação de webhook. WhatsApp exige Meta Business/WABA, número, token e segredo do app. Site exige contrato do formulário e segredo. E-mail exige provedor, OAuth/IMAP e regras. Redes sociais e parceiros exigem contas comerciais, apps, permissões e documentação de cada canal. Segredos ficarão em variáveis de ambiente; não no banco em texto puro.

## Cadastro de imóveis e aprovação de anúncios

Corretor, gestor e administrador cadastram imóveis em **Imóveis → Novo imóvel**, em seis etapas: tipo e valores, endereço (preenchido pelo CEP via ViaCEP), características, proprietário e documentação, fotos, revisão e envio. A tela é mobile-first e será reaproveitada no app do corretor.

**Guia de fotos.** As fotos obrigatórias dependem do tipo e das quantidades informadas: residencial exige fachada, sala, cozinha, um slot por quarto, um por banheiro e área de serviço; comercial exige fachada, área principal e banheiros; terreno exige frente e visão geral. Fotos adicionais (sacada, varanda gourmet, vista, piscina, academia e demais áreas do condomínio) são livres, até 60 por imóvel. No aparelho, a foto é reduzida para 2048 px antes do envio. No servidor, o conteúdo real é validado, a orientação da câmera é corrigida e a imagem é regravada em JPEG, o que remove EXIF e GPS. Fotos menores que 800×600 são recusadas. A foto de fachada vira capa automaticamente, e a capa pode ser trocada depois.

**Moderação.** O fluxo do anúncio é `rascunho → aguardando aprovação → publicado`, ou `reprovado (com motivo obrigatório) → corrigido → reenviado`. O envio só é aceito com o checklist completo: campos obrigatórios, descrição com 60+ caracteres e todas as fotos obrigatórias. Gestor e administrador são notificados a cada envio, e o corretor é notificado da decisão. Em análise, o cadastro fica bloqueado para o corretor. Se o corretor alterar dados ou fotos de um anúncio publicado, ele volta para análise. Gestor e administrador podem editar sem perder a publicação, publicar direto ("Publicar agora") e arquivar. Todo o histórico fica em `property_review_events`, e as ações entram na auditoria.

**Visibilidade.** O corretor vê os próprios cadastros e a vitrine de publicados. O SDR vê só os publicados. Gestão vê tudo, com abas por status. Dados do proprietário, matrícula, IPTU, chaves e comissão só aparecem para quem cadastrou e para a gestão. Apenas anúncios publicados podem ser apresentados a clientes na negociação. O status comercial (disponível/reservado/vendido/alugado) continua separado do status do anúncio.

A migration `009_property_listing.sql` marca os imóveis já existentes como publicados e torna `price` opcional para rascunhos.

## Site público e chat de atendimento

O endereço raiz (`/`) passou a ser o site da imobiliária, e o CRM fica em `/painel`. O botão **Área do corretor** leva ao login. Páginas públicas:

- `/` tem busca por finalidade, tipo, cidade, bairro, preço e quartos. Os filtros ficam na URL, e dá para compartilhar o link já filtrado.
- `/imovel/:id` tem galeria com ampliação, ficha, custo mensal total (aluguel + condomínio + IPTU/12), formulário "Tenho interesse", chat, WhatsApp e imóveis parecidos.
- `/privacidade` é um texto-base LGPD **a ser revisado pelo jurídico**.
- `?chat=1` em qualquer página abre o chat direto, útil em links de campanha.

Só aparecem imóveis **publicados e disponíveis**. A API pública (`/api/public/*`) devolve apenas campos de anúncio: dados do proprietário, matrícula, chaves e comissão nunca saem. O endereço completo só aparece se o cadastro marcar "mostrar endereço completo".

**Formulário de interesse.** Cria um lead de origem "Site e formulários" no funil do SDR, com o imóvel e a mensagem na observação. Exige consentimento de contato e tem um campo-isca contra robôs.

**Chat do site.** O visitante informa nome, telefone ou e-mail e a mensagem. Isso cria o lead no funil e uma conversa `site_chat` que já entra como *Aguardando atendimento* na **Central de Conversas**, com o imóvel de origem. A Central agora é atendida também pelo corretor. Ele vê as conversas livres e as que assumiu, e ao responder assume a conversa automaticamente. Conversa assumida por outro corretor não aparece para ele. Admin, gestor e SDR continuam vendo tudo. O visitante recebe as respostas por polling (4 s com o chat aberto, 20 s fechado, com contador de não lidas). O token da conversa fica só no navegador dele e é guardado no servidor como hash. Conversa encerrada não aceita novas mensagens.

**Proteções.** Há limite por IP: 5 formulários ou 4 chats a cada 10 min, 30 mensagens/min e 120 consultas/min. Em produção atrás de proxy, ajuste `client_ip()` para confiar no `X-Forwarded-For` do proxy.

**Configurações → Empresa e site** (edição só do admin): nome, razão social, CNPJ, CRECI, telefone, WhatsApp (ativa o botão nos imóveis), e-mail, endereço, textos da página inicial, horário, liga/desliga do chat e mensagem automática. Esses dados também vão preencher os contratos.

**Fuso horário.** `APP_TIMEZONE` (padrão `America/Sao_Paulo`) agora vale para o PHP e para a sessão do MySQL. Antes o PHP gravava em UTC e o MySQL no horário local, e prazos/datas gravados pelo PHP ficavam 3 h adiantados. Os registros antigos gravados pelo PHP continuam com essa diferença. As mensagens de conversa passaram a ter precisão de microssegundos, para manter a ordem quando várias chegam no mesmo segundo.

## App do corretor (PWA)

O painel é instalável no celular como app ("Área do Corretor"): tem manifesto, ícones e service worker. O manifesto só é carregado dentro do painel, então o site público não oferece a instalação. O service worker guarda apenas os arquivos do app. A API e os uploads nunca ficam em cache no aparelho. Ele só é registrado no build de produção, e a instalação exige HTTPS (ou `localhost`).

- **Início do corretor** (`/painel`) reúne o que precisa de ação: novas oportunidades (confirmar/iniciar), conversas aguardando, fichas com pendência, fichas aprovadas sem contrato e anúncios reprovados. Também mostra a agenda dos próximos 3 dias, a situação dos anúncios, os números do corretor e atalhos. O painel completo de indicadores ficou em `/indicadores`.
- No celular, uma barra de abas fixa leva às telas do dia a dia de cada perfil. Nas telas de tarefa (cadastro de imóvel, ficha, contrato) a barra some para dar lugar às ações.
- A Central de Conversas no celular abre em lista e depois em tela cheia, com botão de voltar.
- Para publicar nas lojas no futuro, o mesmo app pode ser empacotado como TWA (Android) ou com Capacitor (iOS/Android), sem reescrever as telas.

## Ficha de interesse e análise de crédito

O corretor abre a ficha em **Fichas de interesse → Nova ficha**, escolhendo um cliente da carteira (ou cadastrando um novo, que entra direto na carteira dele), um imóvel publicado e a modalidade: venda à vista, financiada, entrada + financiamento ou locação. Depois preenche, em etapas:

1. Condições (proposta, entrada, FGTS e prazo; ou aluguel, garantia e prazo). O valor financiado é calculado.
2. Proponente.
3. Cônjuge/compositor de renda e fiador.
4. Documentos (PDF ou foto; a foto é reduzida no aparelho).
5. Revisão, com a autorização LGPD do cliente.

Os documentos exigidos variam conforme o caso: identidade, renda e residência sempre; certidão de estado civil para casado(a), em união estável, divorciado(a) ou viúvo(a); FGTS se houver uso; documentos do compositor e do fiador quando existirem.

**Perfil analista de crédito.** Esse perfil só acessa a própria área: qualquer outra rota responde 403. A fila tem as abas *Novas*, *Comigo*, *Aguardando corretor* e *Decididas*. O analista assume a ficha e vê os dados, os documentos (com download registrado na auditoria) e uma calculadora de comprometimento de renda: parcela pela Tabela Price com taxa ajustável ou custo mensal da locação, com referência de 30%. As decisões possíveis são *aprovar*, *aprovar com condições*, *solicitar pendência* (volta ao corretor e depois retorna ao mesmo analista) ou *reprovar* (motivo obrigatório). O corretor é notificado a cada passo, e a oportunidade registra o histórico. Gestor e administrador também podem decidir.

**Dados pessoais.** CPF, RG, renda, endereço e demais dados do proponente, cônjuge e fiador ficam **cifrados no banco** (libsodium com `APP_ENCRYPTION_KEY`). Só o nome e a renda familiar ficam em claro, para busca e listagem. Os documentos vão para o armazenamento privado com permissão `0600`. Trocar a `APP_ENCRYPTION_KEY` torna as fichas existentes ilegíveis, então guarde a chave em cofre e faça backup.

## Contratos e assinatura eletrônica

Com a ficha aprovada, o botão **Emitir contrato** gera o PDF preenchido a partir da ficha, do imóvel, dos dados da empresa e dos padrões em **Configurações → Contratos**. O texto sai com qualificação das partes, valores por extenso, cláusulas numeradas e bloco de assinaturas. São quatro modelos-base: venda à vista, venda financiada, entrada + financiamento e locação (Lei 8.245/91, com a cláusula de garantia montada conforme caução, fiador, seguro-fiança ou capitalização). **Os modelos são um ponto de partida e devem ser revisados pelo jurídico.** O administrador edita o texto de cada modelo com variáveis clicáveis, gera a prévia em PDF e pode restaurar o padrão.

Enquanto o contrato está em rascunho, o corretor ajusta as condições e os signatários (inclusive testemunhas e cônjuge do vendedor), e o PDF é regenerado a cada ajuste. Ao enviar, o contrato vai ao **Documenso** pela API v2 (`envelope/create` + `envelope/distribute`), com um campo de assinatura posicionado para cada parte, e cada uma recebe o link por e-mail. O webhook `DOCUMENT_COMPLETED` (ou o botão "Atualizar status") baixa o PDF assinado para o armazenamento privado, marca o contrato como assinado, **registra o fechamento (ganho) na oportunidade e passa o imóvel para vendido/alugado**. Recusa e cancelamento no Documenso também são refletidos. Sem Documenso configurado, o fluxo manual continua disponível: baixar o PDF, colher as assinaturas e anexar o PDF assinado. A tela guarda o SHA-256 do PDF gerado e do assinado.

### Subir o Documenso localmente

```bash
cd docker/documenso
cp .env.example .env          # preencha as senhas/chaves (openssl rand -hex 32)
./gerar-certificado.sh        # certificado autoassinado, só para desenvolvimento
docker compose up -d          # Documenso em http://localhost:3000 · e-mails em http://localhost:8025
```

No Documenso: crie a conta, gere um token em *Settings → API Tokens* e crie um webhook apontando para `http://host.docker.internal:8080/api/webhooks/documenso` com os eventos de documento e um segredo. No `backend/.env` do IMOB HUB:

```env
DOCUMENSO_URL=http://localhost:3000
DOCUMENSO_API_KEY=api_...
DOCUMENSO_WEBHOOK_SECRET=o-mesmo-segredo-do-webhook
```

O webhook é validado pelo cabeçalho `X-Documenso-Secret`, com comparação em tempo constante. **Produção:** use um certificado ICP-Brasil (e-CNPJ A1) da imobiliária no lugar do autoassinado, um SMTP real, `NEXT_PUBLIC_DISABLE_SIGNUP=true` e HTTPS nas duas pontas. O Documenso é AGPL-3.0 e está sendo usado sem modificações, como serviço separado acessado por API.

Na validação desta etapa não havia permissão para rodar o Docker neste ambiente. A integração foi testada contra um Documenso simulado que implementa os mesmos endpoints da API v2, e pela suíte automatizada com um dublê. Falta o teste com uma instância real do Documenso.

## Builder, simulador e central de integrações

Após migration e seed, acesse:

- `http://localhost:5173/fluxos`: editor visual, versões e publicação;
- `http://localhost:5173/simulador`: prévia isolada ou teste integrado local;
- `http://localhost:5173/conversas`: central de atendimento humano — lista as conversas que a automação transferiu (nó "Atendimento humano"), permite assumir, responder manualmente e encerrar;
- `http://localhost:5173/integracoes`: estado técnico de todos os conectores;
- `http://localhost:5173/sdr`: confirme o lead criado pelo teste integrado.

O seed publica **Interesse em compra**, **Interesse em locação** e **Dúvidas frequentes** (esse último termina em atendimento humano, para popular a central de conversas). No simulador, selecione um fluxo, deixe “Teste integrado local” desligado para prévia sem gravação ou ligue para criar uma oportunidade marcada como `TESTE LOCAL DO SIMULADOR`. Responda nome, telefone, região e faixa de valor. O estado da conversa fica no banco e continua após reiniciar a API. O editor executa somente no backend, rejeita início ausente/duplicado, texto obrigatório ausente e saídas desconectadas. Uma nova versão de rascunho não altera `published_version_id`.

Quando um fluxo chega ao bloco "Atendimento humano", a conversa fica com `status='human'` e aparece em **Conversas** (visível para admin, gestor e SDR) com o ícone de chat na barra superior indicando quantas aguardam atendimento. Um atendente assume a conversa, responde diretamente (fica registrado como mensagem `sent` do atendente, sem qualquer envio externo real) e encerra quando finalizar. Depois de encerrada, a conversa não aceita novas respostas.

Configuração adicional:

```env
APP_ENCRYPTION_KEY=uma-chave-aleatoria-com-32-caracteres-ou-mais
CONNECTOR_INTERNAL_TOKEN=um-token-interno-longo
PAM_SESSION_PATH=/srv/imob-hub-pam-session
```

O simulador não chama WhatsApp. O endpoint interno de conectores exige `X-Connector-Token`, deduplica `provider + external_message_id` e reutiliza o mesmo motor. O serviço PAM está isolado em `connector-pam/` porque a versão 1.x atual exige PHP 8.5; o CRM continua em PHP 8.3. Consulte [connector-pam/README.md](connector-pam/README.md). O ambiente possui Chrome, mas não possui PAM nem PHP 8.5, portanto a inicialização até “aguardando QR” não foi executada aqui. Nenhuma conta ou QR foi usado.

| Integração | Implementado/testado localmente | Falta para ativação real |
|---|---|---|
| WhatsApp simulador | Motor, conversa persistente, prévia e criação de lead; testado em SQLite/MySQL | Nada; não envia mensagens reais |
| PAM WhatsApp Web | Serviço isolado e contrato interno; biblioteca não instalada neste host | PHP 8.5, PAM, autorização administrativa, QR e avaliação de risco do WhatsApp Web não oficial |
| Meta oficial | Contrato/provedor e campos preparados | Meta Business, WABA, número, app, token, segredo, webhook e homologação |
| OLX/Canal Pro | Origem, normalização, rastreabilidade, evento simulado; contrato alinhado à documentação pública de leads | Plano elegível, homologação, OAuth `client_id/client_secret`, conta e URL HTTPS pública |
| QuintoAndar | Contrato genérico e evento simulado | Documentação privada, acordo comercial, credenciais e payload oficial |
| Site/formulários | Webhook autenticado, CSV e evento simulado | Mapeamento do formulário, domínio e segredo compartilhado |
| E-mail | Configuração IMAP/SMTP e normalização simulada | Host, portas/TLS, caixa, senha de app ou OAuth e regras do remetente |
| Redes sociais | Contrato extensível e evento simulado | Plataformas, contas comerciais, apps, permissões e webhooks oficiais |
| VoIP | Contrato extensível e evento simulado | Provedor, documentação, conta SIP/API, webhooks e política de gravação |
| Chat do site | Simulador e transferência para humano | Domínio, widget e identidade visual |
| Power BI | Configuração para Entra/workspace/modelo; sem endpoint inventado | Tenant, app, OAuth, workspace, semantic model e decisão entre importação/REST |

A documentação pública da OLX confirma entrega de leads por endpoint e processo de homologação. A Microsoft documenta autenticação Entra e limites para modelos semânticos push; nenhuma chamada real foi feita sem tenant e workspace. Para QuintoAndar, VoIP e redes sociais, o CRM mantém contrato neutro até a VLN Info escolher o provedor e entregar documentação oficial.

Próxima etapa recomendada: gestão documental segura de contratos, notificações internas, tarefas em calendário, auditoria gerencial exportável, telefonia/chat e Power BI. Antes de produção também devem entrar expiração/rotação de tokens, rate limiting, CSRF conforme topologia, filas, criptografia de credenciais com chave externa, observabilidade, backups e testes HTTP/E2E.
