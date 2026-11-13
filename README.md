# Editorial Advisor

Plugin para **OMP 3.5 / PHP 8.2**, iniciado a partir de `pluginTemplate` (commit `4f6d1d4`).
Referência: `documentacao-e-tarefas/scielo#974`.

## Configuração

Instale o pacote como plugin genérico e ative-o na editora. Em **Configurações → Usuários e papéis → Papéis**,
crie ou edite qualquer papel de nível **Assistant**. O nome do papel é livre.

- **Apenas recomendar uma decisão:** habilita recomendações ao editor nas etapas de avaliação interna e
  externa, usando os controles, a página e o fluxo nativos do OMP (mensagem aos editores,
  discussão interna e registro da recomendação). Não há formulário nem endpoint próprios de recomendação. Impede decisões finais, gestão de avaliações/avaliadores,
  alteração de anexos do parecerista e edição, substituição ou exclusão de arquivos existentes.
  Nas listas de arquivos, mantém apenas a ação **Mais informações**, além do download.
  Envio e seleção de arquivos ficam indisponíveis, inclusive por chamadas diretas ao servidor.
  A discussão da recomendação inclui somente
  o conselheiro e os editores decisores elegíveis.
- **Impedir comunicação com autores e pareceristas:** mantém suas identidades visíveis, mas impede
  notificações diretas, seleção como destinatários de discussões e respostas em discussões que já
  os incluam. Também bloqueia operações de avaliação que enviariam mensagens automaticamente.
  A comunicação interna continua disponível pelas discussões com a equipe editorial.

As opções são independentes. Ative ambas para o perfil de conselho solicitado. As restrições são
aplicadas no servidor e na interface, por editora, submissão e etapa atribuída. Uma atribuição restrita
prevalece sobre outros papéis do mesmo usuário **na mesma etapa**. O plugin não concede acesso a
submissões não atribuídas, nem capacidades editoriais a um autor/parecerista da própria submissão.

As recomendações exigem uma submissão em avaliação, a rodada atual e ao menos um editor decisor
atribuído. Se algum editor decisor também for autor ou parecerista, a recomendação é recusada
para impedir contato pelo fluxo nativo. CC/BCC e anexos não estão disponíveis ao conselheiro. Não alteram a etapa nem o status editorial. Na submissão
inicial, edição e produção não existem recomendações nativas disponibilizadas por este plugin.

Nenhum arquivo do núcleo é modificado. Desativar o plugin remove suas restrições adicionais; dados,
opções dos papéis e recomendações registradas são preservados. As restrições não abrangem comunicação
fora do OMP nem substituem a administração dos acessos privilegiados da instalação.

## Desenvolvimento e testes

O JavaScript usa Vue e os pontos de extensão já fornecidos pelo OMP, sem dependências npm adicionais.
O build copia o código distribuível para `public/build/editorialAdvisor.js`.

```sh
npm run build
npm test
```

Com o plugin em `plugins/generic/editorialAdvisor` de uma instalação OMP 3.5 de testes com dataset
inicializado, execute a partir da raiz do OMP:

```sh
php lib/pkp/lib/vendor/bin/phpunit -c lib/pkp/tests/phpunit.xml --no-coverage \
  plugins/generic/editorialAdvisor/tests
```

Os testes de integração criam fixtures dentro de transações e fazem rollback; não restauram nem
apagam a base. Use o PHP 8.2 do ambiente. Consulte [VALIDATION.md](VALIDATION.md) para a cobertura
real da implementação.
