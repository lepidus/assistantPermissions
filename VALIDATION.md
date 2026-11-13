# Validação da implementação

Alvo: OMP `stable-3_5_0`, core `0baac195989f0847d914d260f73c93787e7792bc`, PHP 8.2.33 e MySQL.
Data: 13/11/2026. Referência: `documentacao-e-tarefas/scielo#974`.

- PHPUnit: 18 testes, 52 asserções; fixtures transacionais com rollback. Cobrem persistência das opções,
  criação e renderização de papéis Assistant, atribuição/etapa/contexto, vínculo expirado, resolução
  independente do ID da discussão, bloqueio de decisão final, destinatário autor, atribuições futuras,
  anexos de parecer somente para leitura, transição de arquivo para anexo de parecer, parâmetro extra
  tentando mascarar a etapa e discussão existente com autor sem resposta nem exclusão de mensagens.
- JavaScript: 7 testes. Cobrem filtros de ações, leitura de parecer concluído, separação por etapa,
  ausência do formulário em rodada antiga e remoção de contato/gestão nos participantes.
- Validações estáticas: sintaxe PHP/JavaScript, XML, traduções, PHP-CS-Fixer e `git diff --check`.
- Chromium real: login como conselheiro, visualização de autores/pareceristas, acesso ao parecer
  concluído, registro de recomendação pelo formulário e criação de discussão interna com o editor.
  Verificação no banco confirmou decisão de tipo recomendação, etapa/status preservados e ausência
  de autores/pareceristas entre os destinatários dessas discussões.
- HTTP autenticado: exclusão de arquivo, decisão final, alteração de avaliação e recomendação em
  submissão não atribuída rejeitadas com HTTP 401; rodada inválida rejeitada com HTTP 422. O status
  401 segue o autorizador nativo do OMP. Destinatário autor forjado no formulário não foi persistido.

- Verificações adicionais: com papel de gerente acumulado na mesma etapa, os bloqueios continuaram
  ativos; a recomendação fora da atribuição recebeu HTTP 403. Sem CSRF, a recomendação recebeu
  HTTP 403. Com apenas bloqueio de contato ativo, cancelamento, reativação e edição de avaliação
  retornaram JSON `status: false`. Uma resposta em discussão com autor também foi negada.

O navegador foi exercitado em cópia isolada com a base `omp_editorial_advisor_i974`, restaurada do
arquivo `omp/stable-3_5_0/mysql/database.sql` do repositório local de datasets PKP e seus arquivos.
O transporte de e-mail dessa cópia foi configurado como `log`; não houve envio externo. A base
original da aplicação não foi restaurada para esses testes.

Não foram executados Cypress, PostgreSQL, outras versões/aplicações PKP ou uma pipeline remota.
As revisões independentes de segurança e de arquitetura foram realizadas sobre o código e os
fluxos afetados. Os achados foram corrigidos e submetidos a nova conferência; não ficaram achados
concretos pendentes no recorte revisado. As regressões verificadas estão incluídas nos testes acima.

## Correção das ações de arquivos — issue 974

- Extensão dos seis stores `fileManager_<namespace>` do OMP 3.5, filtrando o menu
  para `fileSeeNotes` (Mais informações); perfis e etapas sem restrição preservam as ações nativas.
- Edição de metadados, substituição por `revisedFileId`, PUT/PATCH e exclusão de arquivos
  existentes são recusados no servidor. Nenhum arquivo do núcleo foi alterado.
- Envio, seleção e cópia de arquivos são recusados no servidor e os botões de upload/seleção
  são removidos dos seis FileManagers. A antiga exceção de metadados iniciais foi removida
  integralmente (classe, hooks e permissão especial), sem alterações no núcleo ou no esquema.
- Navegador local: conselheiro974, submissão 2, etapa de avaliação externa. Menu do chapter4.pdf
  apresentou zero ações Edit/Delete e uma More Information. Chamadas diretas de edição de
  metadados e PUT foram recusadas. Na validação anterior, o upload sintético ainda era permitido;
  essa permissão foi retirada pelo ajuste que restringe arquivos à consulta.
- Limitação observada separadamente: a janela nativa Mais informações apresentou erro de acesso
  no histórico e erro de classe `Note` não encontrada na aba de notas. A ação é mantida pelo plugin;
  o funcionamento dessas abas não foi corrigido nesta etapa.

Validação do modo de consulta: 21 testes PHP (84 asserções) e 11 testes JavaScript aprovados.
No navegador, não aparecem Upload, Upload/Select Files, Edit ou Delete; More Information permanece.
Download de chapter4.pdf: HTTP 200, application/pdf, 14572 bytes. Chamadas diretas ao wizard,
uploadFile, selectFiles, updateReviewFiles, POST de arquivo e PUT de cópia foram recusadas pelo plugin.
A checagem da janela Mais informações continua limitada pelos erros nativos descritos acima.
