-- Migração da versão anterior para a versão funcional.
-- Execute SOMENTE se o banco senai_ppa já existir e você não quiser recriá-lo.
USE senai_ppa;

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

CREATE INDEX idx_solicitacoes_status_psi ON solicitacoes_atendimento(psicologo_id, status, criado_em);
CREATE INDEX idx_bloqueios_data_psi ON agenda_bloqueios(psicologo_id, data, hora_inicio, hora_fim);
CREATE INDEX idx_atendimentos_data_psi ON atendimentos(psicologo_id, data_hora, status);
