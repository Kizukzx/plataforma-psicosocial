# Fluxos funcionais da PPA SENAI/PE

## Aprendiz
1. Conta o RA precisa existir na base importada pelo pedagógico.
2. O primeiro acesso usa a senha inicial `senha@123` e exige troca de senha.
3. Em **Minha agenda**, o aprendiz escolhe data, modalidade e horário livre.
4. A ação cria uma solicitação pendente para a psicóloga responsável pela unidade.
5. Em **SOS & Bem-estar**, o aprendiz registra o estado emocional e pode enviar uma solicitação urgente.

## Psicóloga
1. Em **Agenda**, a psicóloga visualiza slots, atendimentos e bloqueios.
2. Pode bloquear qualquer intervalo alinhado a blocos de 30 minutos entre 09:00 e 17:00.
3. Em **Solicitações pendentes**, pode aceitar escolhendo data, hora e modalidade, ou recusar com justificativa.
4. A aprovação cria o atendimento confirmado e envia alerta ao aprendiz.
5. Em **Registro de Atendimento**, pode criar, finalizar, registrar ausência ou cancelar com justificativa.
6. O histórico e documentos são restritos às unidades atendidas pela psicóloga.

## Pedagógico
1. Em **Aprendizes / Importação**, envia CSV ou XLSX.
2. O sistema valida cabeçalho, RA duplicado, unidade, curso e status.
3. Registros válidos são criados/atualizados e recebem senha inicial padrão.
4. O resultado e os erros da importação são registrados em `importacoes_planilha`.
5. O pedagógico pode moderar publicações do feed que estiverem pendentes.

## Feed / Materiais
- Todos os perfis autenticados podem visualizar o feed.
- Publicações, reações e comentários são persistidos em MySQL.
- Publicações das psicólogas ficam pendentes até aprovação institucional.

## Instalação
- Banco novo: `mysql -u root -p < database/schema.sql`
- Banco existente: `mysql -u root -p < database/migration_v2.sql`
- Para XLSX, o servidor PHP precisa das extensões `zip` e `SimpleXML`.
