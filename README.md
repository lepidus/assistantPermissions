# Editorial Advisor

Plugin para OMP 3.5 / PHP 8.2, iniciado a partir de `pluginTemplate` (commit `4f6d1d4`).
Referência: `documentacao-e-tarefas/scielo#974`.

Permite configurar qualquer papel de nível Assistant, sem depender do nome do papel:

- apenas recomendar decisões, consultar originais e pareceres e conversar com a equipe editorial;
- impedir comunicação com autores e pareceristas, preservando suas identidades visíveis;
- impedir gerenciamento de avaliações/avaliadores, exclusão de arquivos e decisão final no perfil de recomendação.

As restrições valem para a submissão e etapa atribuídas. Quando o usuário acumula papéis,
a atribuição restrita prevalece naquela etapa, inclusive sobre permissões editoriais adicionais.
O plugin não modifica o núcleo e não restringe comunicação fora do OMP.
