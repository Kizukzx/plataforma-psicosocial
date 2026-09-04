-- =====================================================================
-- PLATAFORMA DE ATENDIMENTO PSICOSSOCIAL DA APRENDIZAGEM - SENAI/PE
-- Banco de dados MySQL
--
-- Estrutura dividida em 4 grandes grupos, conforme os 4 perfis de acesso
-- da plataforma:
--   1) ALUNOS (aprendizes)
--   2) PSICOLOGAS (profissionais do PPA)
--   3) PEDAGOGICO (equipe pedagógica / interlocutoras de aprendizagem)
--   4) DIRETORIA (Diretoria de Educação SENAI/PE)
--
-- + tabelas operacionais compartilhadas (atendimentos, documentos,
--   agenda, alertas, materiais, relatórios, logs) que conectam os 4
--   perfis entre si, mantendo rastreabilidade e conformidade com a LGPD.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS senai_ppa
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE senai_ppa;

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 0. ESTRUTURA BASE (unidades)
-- =====================================================================

CREATE TABLE unidades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100) NOT NULL UNIQUE,
  modalidade ENUM('REMOTO','PRESENCIAL','REMOTO_PRESENCIAL') NOT NULL DEFAULT 'REMOTO',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO unidades (nome, modalidade) VALUES
 ('Areias', 'REMOTO_PRESENCIAL'),
 ('Cabo do Santo Agostinho', 'REMOTO_PRESENCIAL'),
 ('Goiana', 'REMOTO_PRESENCIAL'),
 ('Araripina', 'REMOTO'),
 ('Caruaru', 'REMOTO'),
 ('Paulista', 'PRESENCIAL'),
 ('Santo Amaro', 'REMOTO_PRESENCIAL'),
 ('Belo Jardim', 'REMOTO'),
 ('Ipojuca', 'REMOTO'),
 ('Petrolina', 'REMOTO');

-- =====================================================================
-- 1. GRUPO ALUNOS (aprendizes)
-- =====================================================================

CREATE TABLE alunos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ra VARCHAR(20) NOT NULL UNIQUE,           -- usuário de login do aluno
  nome VARCHAR(150) NOT NULL,
  unidade_id INT NOT NULL,
  curso VARCHAR(150) NOT NULL,
  status_matricula ENUM('ativo','inativo','desligado') NOT NULL DEFAULT 'ativo',
  senha_hash VARCHAR(255) NOT NULL,          -- senha padrão inicial: senha@123
  senha_trocada TINYINT(1) NOT NULL DEFAULT 0, -- troca obrigatória no 1º acesso
  genero VARCHAR(40) NULL,
  raca_cor VARCHAR(40) NULL,
  escolaridade VARCHAR(60) NULL,
  data_nascimento DATE NULL,
  importado_em DATETIME NULL,                -- última importação via planilha
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (unidade_id) REFERENCES unidades(id)
) ENGINE=InnoDB;

CREATE INDEX idx_alunos_unidade ON alunos(unidade_id);
CREATE INDEX idx_alunos_status ON alunos(status_matricula);

-- =====================================================================
-- 2. GRUPO PSICÓLOGAS (profissionais do PPA)
-- =====================================================================

CREATE TABLE psicologos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,        -- login (ex.: yasminn.araujo@sistemafiepe.org.br)
  senha_hash VARCHAR(255) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Relação N:N -> uma psicóloga pode responder por mais de uma unidade
-- (ex.: Raysa Silva responde por Goiana e Paulista)
CREATE TABLE psicologo_unidades (
  psicologo_id INT NOT NULL,
  unidade_id INT NOT NULL,
  PRIMARY KEY (psicologo_id, unidade_id),
  FOREIGN KEY (psicologo_id) REFERENCES psicologos(id) ON DELETE CASCADE,
  FOREIGN KEY (unidade_id) REFERENCES unidades(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Bloqueios/desbloqueios de horário feitos pela psicóloga na própria agenda
-- (janela padrão de atendimento: 09h-17h, sessões de 30 min)
CREATE TABLE agenda_bloqueios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  psicologo_id INT NOT NULL,
  data DATE NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fim TIME NOT NULL,
  motivo VARCHAR(255) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (psicologo_id) REFERENCES psicologos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- 3. GRUPO PEDAGÓGICO (equipe pedagógica / interlocutoras de aprendizagem)
-- =====================================================================

CREATE TABLE pedagogos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  unidade_id INT NOT NULL,                   -- unidade da qual é interlocutora
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (unidade_id) REFERENCES unidades(id)
) ENGINE=InnoDB;

-- Histórico de importações de planilha (RA, nome, unidade, curso, status)
CREATE TABLE importacoes_planilha (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedagogo_id INT NOT NULL,
  unidade_id INT NOT NULL,
  nome_arquivo VARCHAR(255) NOT NULL,
  total_linhas INT NOT NULL DEFAULT 0,
  total_sucesso INT NOT NULL DEFAULT 0,
  total_erros INT NOT NULL DEFAULT 0,
  erros_json TEXT NULL,                      -- detalhe de RA duplicado / curso inválido / status inconsistente
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pedagogo_id) REFERENCES pedagogos(id),
  FOREIGN KEY (unidade_id) REFERENCES unidades(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 4. GRUPO DIRETORIA (Diretoria de Educação SENAI/PE)
-- =====================================================================

CREATE TABLE diretoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
-- 5. MODELOS DE DOCUMENTOS PSICOSSOCIAIS (padronizados, imutáveis)
--    Único formato disponível para preenchimento pelas psicólogas.
-- =====================================================================

CREATE TABLE modelos_documentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,                -- ex.: "Ficha de Acolhimento", "Relatório de Sessão"
  descricao VARCHAR(255) NULL,
  estrutura_json TEXT NOT NULL,               -- schema dos campos do formulário
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
-- 6. ATENDIMENTOS (presencial, remoto/EaD e individual)
-- =====================================================================

CREATE TABLE atendimentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aluno_id INT NOT NULL,
  psicologo_id INT NOT NULL,
  modalidade ENUM('presencial','remoto') NOT NULL,
  data_hora DATETIME NOT NULL,
  duracao_min INT NOT NULL DEFAULT 30,
  status ENUM('confirmado','pendente','cancelado','ausencia','finalizado') NOT NULL DEFAULT 'pendente',
  -- sinalização visual conforme item 3.2.h do documento:
  -- verde=confirmado | amarelo=pendente/reagendamento | vermelho=ausência/urgência
  sinalizacao ENUM('verde','amarelo','vermelho') NOT NULL DEFAULT 'amarelo',
  prioritario TINYINT(1) NOT NULL DEFAULT 0,   -- caso urgente/prioritário
  justificativa_cancelamento VARCHAR(255) NULL,
  observacoes TEXT NULL,
  reentrada TINYINT(1) NOT NULL DEFAULT 0,     -- retomada após cancelamento/ausência prolongada
  atendimento_anterior_id INT NULL,            -- vínculo ao histórico anterior (reentrada)
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (psicologo_id) REFERENCES psicologos(id),
  FOREIGN KEY (atendimento_anterior_id) REFERENCES atendimentos(id)
) ENGINE=InnoDB;

CREATE INDEX idx_atend_aluno ON atendimentos(aluno_id);
CREATE INDEX idx_atend_psic_data ON atendimentos(psicologo_id, data_hora);
CREATE INDEX idx_atend_status ON atendimentos(status);

-- =====================================================================
-- 7. ATENDIMENTOS EM GRUPO
-- =====================================================================

CREATE TABLE atendimentos_grupo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  psicologo_id INT NOT NULL,
  unidade_id INT NOT NULL,
  tema VARCHAR(150) NULL,
  data_hora DATETIME NOT NULL,
  duracao_min INT NOT NULL DEFAULT 60,
  resumo_atividades TEXT NULL,
  status ENUM('agendado','realizado','cancelado') NOT NULL DEFAULT 'agendado',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (psicologo_id) REFERENCES psicologos(id),
  FOREIGN KEY (unidade_id) REFERENCES unidades(id)
) ENGINE=InnoDB;

CREATE TABLE grupo_participantes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  atendimento_grupo_id INT NOT NULL,
  aluno_id INT NOT NULL,
  presente TINYINT(1) NULL,                  -- NULL até o registro de presença
  justificativa_falta VARCHAR(255) NULL,
  observacoes VARCHAR(255) NULL,
  FOREIGN KEY (atendimento_grupo_id) REFERENCES atendimentos_grupo(id) ON DELETE CASCADE,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  UNIQUE KEY uniq_participante (atendimento_grupo_id, aluno_id)
) ENGINE=InnoDB;

-- =====================================================================
-- 8. DOCUMENTOS PSICOSSOCIAIS (repositório pessoal e seguro / LGPD)
-- =====================================================================

CREATE TABLE documentos_psicossociais (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aluno_id INT NOT NULL,
  psicologo_id INT NOT NULL,
  atendimento_id INT NULL,
  atendimento_grupo_id INT NULL,
  modelo_id INT NOT NULL,
  conteudo_json TEXT NOT NULL,                -- dados preenchidos conforme o modelo padrão
  arquivo_pdf_path VARCHAR(255) NULL,          -- gerado ao exportar/baixar em PDF
  assinado_digitalmente TINYINT(1) NOT NULL DEFAULT 0,
  assinado_em DATETIME NULL,
  editavel TINYINT(1) NOT NULL DEFAULT 0,      -- só true após solicitação justificada aprovada
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (psicologo_id) REFERENCES psicologos(id),
  FOREIGN KEY (atendimento_id) REFERENCES atendimentos(id),
  FOREIGN KEY (atendimento_grupo_id) REFERENCES atendimentos_grupo(id),
  FOREIGN KEY (modelo_id) REFERENCES modelos_documentos(id)
) ENGINE=InnoDB;

CREATE INDEX idx_doc_aluno ON documentos_psicossociais(aluno_id);

-- Solicitação justificada de edição/exclusão de documento já salvo
-- (encaminhada automaticamente a atendimentopsicossocial@sistemafiepe.org.br)
CREATE TABLE solicitacoes_documento (
  id INT AUTO_INCREMENT PRIMARY KEY,
  documento_id INT NOT NULL,
  psicologo_id INT NOT NULL,
  tipo ENUM('edicao','exclusao') NOT NULL,
  motivo TEXT NOT NULL,
  status ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
  respondido_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (documento_id) REFERENCES documentos_psicossociais(id),
  FOREIGN KEY (psicologo_id) REFERENCES psicologos(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 9. SOLICITAÇÕES DE ATENDIMENTO (feitas pelo aluno)
-- =====================================================================

CREATE TABLE solicitacoes_atendimento (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aluno_id INT NOT NULL,
  psicologo_id INT NULL,                      -- definido automaticamente pela unidade do aluno
  motivo TEXT NULL,
  urgente TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pendente','aprovada','recusada') NOT NULL DEFAULT 'pendente',
  atendimento_id INT NULL,                    -- preenchido quando aprovada e agendada
  respondido_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (psicologo_id) REFERENCES psicologos(id),
  FOREIGN KEY (atendimento_id) REFERENCES atendimentos(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 10. ALERTAS AUTOMÁTICOS
-- =====================================================================

CREATE TABLE alertas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('duas_faltas','caso_prioritario','atraso','entrada_sala','reagendamento','solicitacao_pendente') NOT NULL,
  aluno_id INT NULL,
  psicologo_id INT NULL,
  unidade_id INT NULL,
  mensagem VARCHAR(255) NOT NULL,
  lido TINYINT(1) NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id),
  FOREIGN KEY (psicologo_id) REFERENCES psicologos(id),
  FOREIGN KEY (unidade_id) REFERENCES unidades(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 11. CANAL DE CONTEÚDOS / MATERIAIS EDUCATIVOS
-- =====================================================================

CREATE TABLE conteudos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(150) NOT NULL,
  tipo ENUM('cartilha','video','atividade','comunicado') NOT NULL,
  categoria VARCHAR(100) NULL,
  unidade_id INT NULL,                        -- NULL = todas as unidades
  descricao TEXT NULL,
  arquivo_path VARCHAR(255) NULL,
  url_externa VARCHAR(255) NULL,
  psicologo_id INT NOT NULL,                  -- autor(a)
  status ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
  aprovado_por VARCHAR(150) NULL,              -- e-mail institucional que aprovou
  aprovado_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (unidade_id) REFERENCES unidades(id),
  FOREIGN KEY (psicologo_id) REFERENCES psicologos(id)
) ENGINE=InnoDB;

CREATE TABLE conteudo_engajamento (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conteudo_id INT NOT NULL,
  aluno_id INT NOT NULL,
  visualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  download TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (conteudo_id) REFERENCES conteudos(id) ON DELETE CASCADE,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 12. LOGS DE ACESSO E AUDITORIA (rastreabilidade / LGPD)
-- =====================================================================

CREATE TABLE logs_acesso (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_tipo ENUM('aluno','psicologo','pedagogo','diretoria') NOT NULL,
  usuario_id INT NOT NULL,
  acao VARCHAR(100) NOT NULL,
  detalhes VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_logs_usuario ON logs_acesso(usuario_tipo, usuario_id);


-- =====================================================================
-- 13. RECUPERAÇÃO DE SENHA
-- =====================================================================
CREATE TABLE password_resets (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  perfil ENUM('psicologo','pedagogo','diretoria') NOT NULL,
  usuario_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expira_em DATETIME NOT NULL,
  usado_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_password_reset_usuario (perfil, usuario_id),
  INDEX idx_password_reset_expira (expira_em)
) ENGINE=InnoDB;

-- =====================================================================
-- 14. FEED SOCIAL / MATERIAIS
--     A página de materiais funciona como um feed social.
--     Psicólogas: publicação entra como pendente até aprovação institucional.
--     Pedagogia, diretoria e aluno: publicam diretamente, conforme pedido do projeto.
-- =====================================================================
CREATE TABLE feed_posts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  autor_tipo ENUM('aluno','psicologo','pedagogo','diretoria') NOT NULL,
  autor_id INT NOT NULL,
  titulo VARCHAR(180) NULL,
  texto TEXT NOT NULL,
  categoria VARCHAR(100) NULL,
  unidade_id INT NULL,
  arquivo_path VARCHAR(255) NULL,
  link_externo VARCHAR(500) NULL,
  status ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'aprovado',
  aprovado_por VARCHAR(180) NULL,
  aprovado_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_feed_status_data (status, criado_em),
  INDEX idx_feed_autor (autor_tipo, autor_id),
  INDEX idx_feed_unidade (unidade_id),
  FOREIGN KEY (unidade_id) REFERENCES unidades(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE feed_reactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT NOT NULL,
  autor_tipo ENUM('aluno','psicologo','pedagogo','diretoria') NOT NULL,
  autor_id INT NOT NULL,
  tipo ENUM('curtir','apoio','parabens','util') NOT NULL DEFAULT 'curtir',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reacao_usuario_post (post_id, autor_tipo, autor_id),
  INDEX idx_reacao_post (post_id),
  FOREIGN KEY (post_id) REFERENCES feed_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE feed_comments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT NOT NULL,
  autor_tipo ENUM('aluno','psicologo','pedagogo','diretoria') NOT NULL,
  autor_id INT NOT NULL,
  texto VARCHAR(1000) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_comentario_post (post_id, criado_em),
  FOREIGN KEY (post_id) REFERENCES feed_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- DADOS INICIAIS (seed) — senha padrão para todos: senha@123
-- Hash bcrypt de "senha@123" usado somente para homologação local.
-- Gere novos hashes em produção com password_hash().
-- =====================================================================

INSERT INTO psicologos (nome, email, senha_hash) VALUES
 ('Yasminn Araújo', 'yasminn.araujo@sistemafiepe.org.br', '$2y$12$ukfWmea5gWJNL2V45dq1aOViMrT8okOkGOOiZhbk62qhnaBMkMMJ6'),
 ('Rutiely Moura', 'rutiely.moura@sistemafiepe.org.br', '$2y$12$ukfWmea5gWJNL2V45dq1aOViMrT8okOkGOOiZhbk62qhnaBMkMMJ6'),
 ('Raysa Silva', 'raysa.silva@sistemafiepe.org.br', '$2y$12$ukfWmea5gWJNL2V45dq1aOViMrT8okOkGOOiZhbk62qhnaBMkMMJ6');

INSERT INTO psicologo_unidades (psicologo_id, unidade_id)
 SELECT p.id, u.id FROM psicologos p, unidades u
 WHERE (p.email='yasminn.araujo@sistemafiepe.org.br' AND u.nome='Cabo do Santo Agostinho')
    OR (p.email='rutiely.moura@sistemafiepe.org.br' AND u.nome='Santo Amaro')
    OR (p.email='raysa.silva@sistemafiepe.org.br' AND u.nome IN ('Goiana','Paulista'));

INSERT INTO pedagogos (nome, email, senha_hash, unidade_id) VALUES
 ('Cristiane Oliveira', 'cristiane.oliveira@sistemafiepe.org.br', '$2y$12$ukfWmea5gWJNL2V45dq1aOViMrT8okOkGOOiZhbk62qhnaBMkMMJ6', (SELECT id FROM unidades WHERE nome='Areias')),
 ('Flavia Almeida', 'flavia.almeida@sistemafiepe.org.br', '$2y$12$ukfWmea5gWJNL2V45dq1aOViMrT8okOkGOOiZhbk62qhnaBMkMMJ6', (SELECT id FROM unidades WHERE nome='Cabo do Santo Agostinho'));

INSERT INTO diretoria (nome, email, senha_hash) VALUES
 ('Diretoria de Educação SENAI/PE', 'diretoria@sistemafiepe.org.br', '$2y$12$ukfWmea5gWJNL2V45dq1aOViMrT8okOkGOOiZhbk62qhnaBMkMMJ6');

INSERT INTO alunos (ra, nome, unidade_id, curso, status_matricula, senha_hash) VALUES
 ('20240015', 'João Matheus', (SELECT id FROM unidades WHERE nome='Cabo do Santo Agostinho'), 'Eletromecânica', 'ativo', '$2y$12$ukfWmea5gWJNL2V45dq1aOViMrT8okOkGOOiZhbk62qhnaBMkMMJ6');

INSERT INTO modelos_documentos (nome, descricao, estrutura_json) VALUES
 ('Ficha de Acolhimento', 'Preenchida no primeiro atendimento do aprendiz',
  '{"campos":[{"nome":"queixa_principal","tipo":"textarea","label":"Queixa principal"},{"nome":"historico","tipo":"textarea","label":"Histórico relevante"},{"nome":"encaminhamentos","tipo":"textarea","label":"Encaminhamentos"}]}'),
 ('Relatório de Sessão', 'Preenchido ao final de cada atendimento individual',
  '{"campos":[{"nome":"resumo_sessao","tipo":"textarea","label":"Resumo da sessão"},{"nome":"evolucao","tipo":"textarea","label":"Evolução observada"},{"nome":"proximos_passos","tipo":"textarea","label":"Próximos passos"}]}'),
 ('Relatório de Atendimento em Grupo', 'Preenchido para sessões coletivas',
  '{"campos":[{"nome":"tema","tipo":"text","label":"Tema da sessão"},{"nome":"atividades","tipo":"textarea","label":"Atividades realizadas"},{"nome":"observacoes_gerais","tipo":"textarea","label":"Observações gerais"}]}');


-- =====================================================================
-- SEED DO FEED (apenas para homologação local)
-- =====================================================================
INSERT INTO feed_posts (autor_tipo, autor_id, titulo, texto, categoria, unidade_id, status, aprovado_por, aprovado_em)
SELECT 'psicologo', p.id, 'Comece pela respiração', 'Uma pausa de dois minutos pode ajudar a organizar a atenção. Inspire lentamente, segure por alguns segundos e solte o ar de forma confortável.', 'Bem-estar', u.id, 'aprovado', 'atendimentopsicossocial@sistemafiepe.org.br', NOW()
FROM psicologos p JOIN unidades u ON u.nome='Cabo do Santo Agostinho'
WHERE p.email='yasminn.araujo@sistemafiepe.org.br'
LIMIT 1;

INSERT INTO feed_posts (autor_tipo, autor_id, titulo, texto, categoria, unidade_id, status, aprovado_por, aprovado_em)
SELECT 'pedagogo', p.id, 'Semana de acolhimento', 'Use este espaço para compartilhar materiais, campanhas e avisos relacionados ao bem-estar dos aprendizes.', 'Comunicado', p.unidade_id, 'aprovado', p.email, NOW()
FROM pedagogos p WHERE p.email='cristiane.oliveira@sistemafiepe.org.br' LIMIT 1;

-- ALTERAÇÕES DE COMPATIBILIDADE DA VERSÃO FUNCIONAL
ALTER TABLE password_resets MODIFY perfil ENUM('aluno','psicologo','pedagogo','diretoria') NOT NULL;

CREATE TABLE IF NOT EXISTS bemestar_registros (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  aluno_id INT NOT NULL,
  nivel ENUM('muito_bem','bem','mais_ou_menos','ansioso','muito_mal') NOT NULL,
  texto TEXT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
  INDEX idx_bemestar_aluno_data (aluno_id, criado_em)
) ENGINE=InnoDB;
