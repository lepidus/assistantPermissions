[English](../README.md) | Português Brasileiro

# Permissões de Assistente

Permite configurar papéis de **Assistente de Adição** para consultar submissões e pareceres,
recomendar decisões e restringir arquivos e comunicação. As identidades dos autores e pareceristas
permanecem visíveis. A decisão final cabe ao editor.

Compatível com **OMP 3.5.0.x e PHP 8.2**.

## Configuração

1. Em **Configurações → Website → Plugins → Enviar novo plugin**, instale o pacote `.tar.gz` e habilite **Permissões de Assistente**.
2. Em **Configurações → Usuários e Papéis → Papéis**, crie ou edite um papel com nível **Assistente de Adição**. O nome do papel é livre.
3. Selecione **Avaliação interna** e **Avaliação externa** e marque as opções desejadas:

| Opção | Efeito |
| --- | --- |
| **Permitir a este papel apenas recomendar uma decisão…** | Habilita recomendações. Impede decisões finais e gestão de avaliadores, rodadas e participantes. |
| **Permitir apenas a consulta de arquivos** | Mantém download e **Mais informações**. Impede enviar, selecionar, editar, substituir e excluir arquivos e anexar arquivos à recomendação. |
| **Impedir comunicação com autores e pareceristas (manter suas identidades visíveis)** | Bloqueia contato com autores e pareceristas. Permite **Notificar** a equipe editorial atribuída à etapa. |

4. Salve o papel e associe o usuário a ele em **Usuários e Papéis**.
5. Abra a submissão e adicione o usuário em **Participantes**, usando esse papel.

Marque **as três opções** para o perfil completo do conselheiro. Elas são independentes:
a opção de recomendação não restringe arquivos. Nos papéis existentes, marque a opção de arquivos
explicitamente. Com as opções desmarcadas, permanecem as permissões nativas do papel.
O plugin não cria papéis automaticamente.

## Créditos e licença

Desenvolvido pela [Lepidus Tecnologia](https://lepidus.com.br/).
Distribuído sob a [GNU General Public License v3.0](../LICENSE).

Copyright (c) 2026 Lepidus Tecnologia

Copyright (c) 2026 SciELO
