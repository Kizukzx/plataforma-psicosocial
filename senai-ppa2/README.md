# PPA SENAI/PE — Plataforma Psicossocial

Sistema web em **PHP puro + MySQL**, sem framework, com front-end integrado ao back-end.

## O que foi corrigido

1. **Login real:** a tela não libera mais a plataforma por JavaScript. O perfil escolhido, usuário/RA e senha são validados no MySQL com `password_verify()`.
2. **Cadastro:** existe uma aba de cadastro/ativação do aprendiz. Ela só funciona para um RA que já tenha sido importado e validado pela equipe pedagógica; isso segue o fluxo do documento, que define o RA como credencial do aprendiz e a importação como origem do cadastro.
3. **Esqueci minha senha:** fluxo por token para contas institucionais. Em `APP_ENV=development`, o endpoint também devolve um token/link de teste para homologação local; em produção o mesmo token deve ser enviado por e-mail institucional.
4. **Sessão e autorização:** cookie de sessão protegido, regeneração de sessão no login, separação entre os quatro perfis e verificações de proprietário/unidade nos endpoints alterados.
5. **Materiais como feed:** nova área social com publicações, arquivo opcional, categorias, curtidas, comentários e exclusão da própria publicação. Publicações de psicólogas entram como `pendente`, conforme o documento; as demais são publicadas diretamente.
6. **Agenda:** validação dos limites 09h–17h, blocos de 30 minutos, conflitos e bloqueios sobrepostos.
7. **Atendimentos:** validação de unidade, modalidade disponível e conflito de horário antes de agendar.
8. **Auditoria:** login, logout, troca/redefinição de senha e ações do feed são registradas.

## Estrutura

```text
senai-ppa/
├── api/
│   ├── feed.php
│   ├── notificacoes.php
│   ├── aluno/
│   ├── psicologa/
│   ├── pedagogica/
│   └── diretoria/
├── auth/
│   ├── login.php
│   ├── logout.php
│   ├── me.php
│   ├── cadastro.php
│   ├── solicitar_recuperacao.php
│   ├── redefinir_senha.php
│   └── trocar_senha.php
├── config/
│   └── database.php
├── database/
│   └── schema.sql
├── includes/
│   ├── auth.php
│   ├── functions.php
│   └── response.php
├── uploads/
│   ├── documentos/
│   ├── materiais/
│   ├── planilhas/
│   └── feed/
├── public/
│   ├── index.html
│   └── api.js
└── .htaccess
```

## Banco separado em 4 perfis

- `alunos`: login por RA e vínculo à unidade/curso/status.
- `psicologos`: login por e-mail institucional, com N:N para unidades em `psicologo_unidades`.
- `pedagogos`: login por e-mail institucional e vínculo a uma unidade.
- `diretoria`: login por e-mail institucional, acesso a indicadores agregados.

As tabelas operacionais conectam esses quatro grupos sem misturar credenciais.

## Requisitos do documento atendidos

O schema preserva os itens centrais do PDF: cadastro/importação de aprendizes, troca obrigatória de senha no primeiro acesso, modalidades por unidade, agenda 09h–17h em blocos de 30 minutos, atendimentos presenciais/remotos, atendimento em grupo, documentos padronizados, repositório individual, alertas de duas faltas, solicitações de atendimento, indicadores agregados e canal de conteúdos.

O PDF também define que a equipe pedagógica e a diretoria devem visualizar apenas informações agregadas, e que documentos individuais ficam restritos à psicóloga responsável. Essas regras continuam refletidas nos endpoints existentes.

## Instalação local

### 1. Criar o banco

```bash
mysql -u root -p < database/schema.sql
```

### 2. Configurar conexão

Edite `config/database.php` ou use:

```text
DB_HOST=127.0.0.1
DB_NAME=senai_ppa
DB_USER=root
DB_PASS=sua_senha
APP_ENV=development
```

### 3. Servir o projeto

Na pasta `senai-ppa`:

```bash
php -S localhost:8000
```

Abra:

```text
http://localhost:8000/public/index.html
```

### 4. Credenciais de demonstração

O `schema.sql` inclui contas iniciais com a senha padrão `senha@123` apenas para homologação local. Em produção, substitua/rotacione essas senhas.

### 5. Teste dos quatro logins

- Psicóloga: `yasminn.araujo@sistemafiepe.org.br`
- Pedagógica: `cristiane.oliveira@sistemafiepe.org.br`
- Aprendiz: RA `20240015`
- Diretoria: `diretoria@sistemafiepe.org.br`

Senha de homologação: `senha@123`.

O login incorreto para o perfil selecionado retorna erro 401. O usuário não entra mais apenas por preencher caracteres aleatórios.

## Feed / materiais

O feed usa:

- `feed_posts`: publicações e materiais anexados;
- `feed_reactions`: uma reação por usuário/publicação;
- `feed_comments`: comentários;
- `feed.php`: GET, POST, PUT e DELETE;
- `pedagogica/feed.php`: aprovação institucional das publicações pendentes.

Psicólogas podem publicar materiais, mas o post fica `pendente`, conforme o fluxo institucional descrito no documento.

## Observação importante sobre LGPD

Este projeto implementa controles de acesso, minimização nas consultas dos perfis agregadores, logs e hashes de senha, mas não deve ser considerado uma certificação LGPD. Em produção ainda devem ser definidos política de retenção, rotina real de backup criptografado, monitoramento, gestão de segredos, HTTPS obrigatório, revisão de infraestrutura e fluxo formal de atendimento de solicitações.

## Versão funcional — fluxos de operação

### Agendamento do aprendiz
A aba **Minha agenda** consulta os horários reais da psicóloga responsável pela unidade. O aprendiz só consegue solicitar um slot livre, compatível com a modalidade da unidade. A solicitação nasce como `pendente` e não ocupa a agenda até a aprovação da psicóloga.

### Aprovação pela psicóloga
Na agenda da psicóloga, as solicitações pendentes são carregadas do MySQL. A profissional escolhe data, hora e modalidade para confirmar a reserva, ou recusa a solicitação. O servidor valida matrícula ativa, unidade, modalidade, bloqueios e conflitos antes de inserir o atendimento.

### Bloqueio de agenda
A psicóloga consegue bloquear/desbloquear intervalos diretamente na agenda. O servidor impede intervalos fora de 09:00–17:00, fora dos blocos de 30 minutos, sobreposição de bloqueios e bloqueio de horário já ocupado por atendimento.

### Importação pedagógica
O perfil pedagógico possui a área **Aprendizes / Importação**. São aceitos CSV e XLSX. A validação verifica cabeçalho, RA duplicado, unidade, curso, status e vínculo de unidade do usuário pedagógico. A importação atualiza registros existentes ou cria novos alunos e grava o histórico.

### SOS e bem-estar
O aluno consegue registrar o estado emocional, escrever no diário e disparar uma solicitação urgente de atendimento. Os registros ficam vinculados ao próprio aluno.

### Migração
Para quem já possui o banco da versão anterior, use `database/migration_v2.sql` em vez de executar novamente o `schema.sql`.
