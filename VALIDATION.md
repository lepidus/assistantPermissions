# Validação da implementação

As seções iniciais registram as validações históricas; a seção final descreve o fluxo atual.

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

## Reutilização do fluxo nativo de recomendação — issue 974

- Removidos `EditorialAdvisorRecommendation`, `RecommendationController` e o endpoint
  `plugins/editorial-advisor/{id}/recommendations`, além de suas traduções e metadados exclusivos.
- A interface mantém `WorkflowRecommendOnlyControls` nativo. A página herda `DecisionHandler::record()`;
  o POST mantém a URL nativa `submissions/{id}/decisions` e herda `SubmissionController::addDecision()`.
  As subclasses acrescentam autorização explícita só para o Assistant restrito, sem mutar políticas
  nem middleware existentes. As demais requisições seguem os controllers originais.
- Mantidas as verificações globais do OMP e as políticas nativas de contexto, submissão, tipo e etapa.
  A política específica exige vínculo Assistant ativo, grupo e atribuição recommendOnly, submissão
  concluída/em avaliação e rodada atual. Nega decisão final, proponente autor/parecerista e qualquer
  editor decisor que também seja autor/parecerista. O POST exige uma discussão, sem CC/BCC ou anexos.
- Navegador local: conselheiro974, submissão 2, avaliação externa/rodada 2. Botões nativos abrem
  `decision/record/2`. Uma recomendação sintética completou a confirmação nativa, atualizou
  `currentUserRecommendation` para Accept Submission e preservou etapa 3/status 1. O Mailpit local
  capturou um e-mail para um editor. Não houve envio externo.
- HTTP autenticado: rodada inválida, decisão final, CC/BCC, anexo, ação adicional e submissão
  conflitante recusados com HTTP 401; envio sem CSRF recebeu HTTP 403. Página com ID conflitante
  não renderizou o formulário. O endpoint antigo retornou 404. O upload continuou indisponível. A interface não oferece anexação nem CC/BCC.
- A autorização é reavaliada no POST. Não foram exercitadas alterações concorrentes de papéis,
  atribuições ou rodada durante o envio, nem autenticação por token API; o fluxo validado usa sessão.
  A integração mantém o comportamento transacional nativo, sem garantias adicionais de concorrência.
- Erros nativos de Mais informações permanecem fora deste ajuste. Não foram alterados arquivos do núcleo.

Resultado final desta etapa: 26 testes PHP/102 asserções e 11 testes JavaScript aprovados,
além de sintaxe, estilo, traduções e diff sem erros. Validação HTTP/browser com sessão local
na avaliação externa; a avaliação interna não foi exercitada pelo navegador nesta etapa.

## Comunicação por participante e menus vazios — issue 974

- O bloqueio deixou de abranger todas as notificações: `viewNotify` e `sendNotification` verificam
  o destinatário real no servidor. Somente a equipe editorial atribuída à etapa é elegível, excluindo
  autores e pareceristas da submissão mesmo quando acumulam papel editorial.
- O schema publica a lista elegível por etapa para o filtro da interface. Editar, remover e entrar
  como outro usuário continuam bloqueados pelo perfil recommendOnly, independentemente do destinatário.
- `getItemInfoItems` marca a linha quando `getItemActions` retorna vazio; CSS restrito ao painel de
  participantes oculta o botão nativo dessa linha. Sem cópia do componente ou alteração do núcleo.
- Navegador local: menu do autor oculto, menu do editor com Notificar e formulário nativo disponível.
  Notificação sintética ao editor concluída no OMP local; chamadas diretas de abertura e envio ao
  autor retornaram status false. Os testes transacionais cobrem também editor/autor e editor/parecerista.
- Cobertura final: 28 testes PHP/110 asserções e 15 testes JavaScript, incluindo listas distintas por
  etapa, preservação das identidades e independência entre restrição de contato e de gestão.

## Opção independente de restrição de arquivos — issue 974

- Nova opção Assistant `editorialAdvisorRestrictFiles`: Restringir arquivos a download e Mais informações.
  É independente de recommendOnly e blockContact; ausente/desmarcada significa falso. Não há migração
  automática: papéis existentes precisam marcar a opção para manter o perfil de arquivos somente leitura.
- Formulário e schema persistem a opção por papel, inclusive sem etapas selecionadas. As restrições
  são agregadas por editora/submissão/etapa e associação Assistant ativa, sem depender do nome do papel.
- A opção controla componentes legacy, API de arquivos, capacidades do grid de anexos de pareceres,
  os seis FileManagers e anexos da recomendação nativa. recommendOnly mantém apenas suas restrições
  editoriais e de gestão; sem restrictFiles, operações de arquivos permanecem sujeitas ao core.
- Testes cobrem as oito combinações das três opções, persistência/desativação, renderização do campo,
  criação/edição sem etapas e desacoplamento dos anexos da recomendação.
- O servidor local de navegador usado nas etapas anteriores não estava ativo nesta execução.
  Foram executados o formulário real e a integração SQL com rollback; não foi repetido o fluxo em navegador.

Resultado desta etapa: 32 testes PHP/172 asserções e 16 testes JavaScript aprovados;
sintaxe, estilo, traduções e diff aprovados. Sem alterações no núcleo ou no esquema do banco.
