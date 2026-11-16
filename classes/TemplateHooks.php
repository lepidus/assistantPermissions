<?php

namespace APP\plugins\generic\assistantPermissions\classes;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\plugins\GenericPlugin;
use PKP\security\Role;

/** Template substitutions, native recommendation presentation and backend assets. */
class TemplateHooks
{
    private array $readOnlyQueries = [];

    public function __construct(private GenericPlugin $plugin, private Access $access)
    {
    }

    public function prepareDisplay(string $name, array $args): bool
    {
        $request = Application::get()->getRequest();

        if ($args[1] === 'decision/record.tpl' && $request->getRouter()->getHandler() instanceof AssistantPermissionsDecisionHandler) {
            $this->prepareRecommendationAttachments($args[0], $request);

            $args[0]->addStyleSheet('assistantPermissionsRecommendation', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath()
                . '/public/styles/nativeRecommendation.css', ['contexts' => ['backend']]);
        }

        $args[0]->addStyleSheet('assistantPermissionsParticipants', $request->getBaseUrl() . '/' . $this->plugin->getPluginPath()
            . '/public/styles/participants.css', ['contexts' => ['backend']]);
        $args[0]->addJavaScript(
            'assistantPermissions',
            $request->getBaseUrl() . '/' . $this->plugin->getPluginPath() . '/public/build/assistantPermissions.js',
            ['contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
        );

        return false;
    }

    private function prepareRecommendationAttachments(TemplateManager $manager, \PKP\core\PKPRequest $request): void
    {
        $steps = $manager->getState('steps') ?? [];

        foreach ($steps as $step) {
            if ($step->type === 'email' && (new Access())->restrictions(
                $request->getContext()->getId(),
                $request->getUser()->getId(),
                (int) ($request->getRouter()->getRequestedArgs($request)[0] ?? 0)
            )->restrictFiles) {
                $step->attachers = [];
            }
        }

        $manager->setState(['steps' => $steps]);
    }

    public function prepareTemplate(string $name, array $args): bool
    {
        [$manager, $template] = $args;

        if ($template === 'controllers/grid/settings/roles/form/userGroupForm.tpl') {
            $manager->assign('assistantPermissionsAssistantRoleId', Role::ROLE_ID_ASSISTANT);
            $args[4] = $manager->fetch($this->plugin->getTemplateResource('userGroupForm.tpl'));
            return true;
        }

        if ($template === 'controllers/grid/users/reviewer/readReview.tpl') {
            $request = Application::get()->getRequest();
            $review = $manager->getTemplateVars('reviewAssignment');
            if ($review && $request->getContext() && $request->getUser()
                && (new Access())->restrictions($request->getContext()->getId(), $request->getUser()->getId(), $review->getSubmissionId(), $review->getStageId())->recommendOnly) {
                $recommendation = (int) $review->getRecommendation();
                $manager->assign('assistantPermissionsReviewerRecommendation', $recommendation >= 1 && $recommendation <= 6
                    ? __('plugins.generic.assistantPermissions.reviewerRecommendation.' . $recommendation) : '');
                $args[4] = $manager->fetch($this->plugin->getTemplateResource('readReview.tpl'));
                return true;
            }
        }

        if ($template === 'controllers/grid/gridRow.tpl') {
            $row = $manager->getTemplateVars('row');
            if ($row instanceof \PKP\controllers\grid\queries\QueryNotesGridRow && $this->isReadOnlyQuery($row->getQuery())) {
                $row->_actions = [];
            }
        }

        if ($template === 'controllers/grid/queries/readQuery.tpl') {
            $query = $manager->getTemplateVars('query');
            if ($query && $this->isReadOnlyQuery($query)) {
                $participants = \PKP\query\QueryParticipant::withQueryId($query->id)->get();
                $manager->assign('assistantPermissionsQueryParticipants', $participants->pluck('user')->filter()->all());
                $args[4] = $manager->fetch($this->plugin->getTemplateResource('readQuery.tpl'));
                return true;
            }
        }

        if ($template === 'controllers/grid/queries/form/queryForm.tpl') {
            $request = Application::get()->getRequest();
            $submissionId = (int) $manager->getTemplateVars('assocId');
            if ($request->getContext() && $request->getUser()
                && (new Access())->restrictions($request->getContext()->getId(), $request->getUser()->getId(), $submissionId, (int) $manager->getTemplateVars('stageId'))->blockContact) {
                $forbidden = (new Access())->forbiddenParticipants($submissionId);
                $participants = $manager->getTemplateVars('allParticipants') ?? [];
                $manager->assign('allParticipants', array_diff_key($participants, array_flip($forbidden)));
            }
        }

        return false;
    }

    private function isReadOnlyQuery(\PKP\query\Query $query): bool
    {
        if (array_key_exists($query->id, $this->readOnlyQueries)) {
            return $this->readOnlyQueries[$query->id];
        }

        $request = Application::get()->getRequest();
        $access = $this->access;
        return $this->readOnlyQueries[$query->id] = $request->getContext() && $request->getUser()
            && $access->restrictions($request->getContext()->getId(), $request->getUser()->getId(), (int) $query->assocId, (int) $query->stageId)->blockContact
            && (bool) array_intersect(
                $access->forbiddenParticipants((int) $query->assocId),
                \PKP\query\QueryParticipant::withQueryId($query->id)->pluck('user_id')->all()
            );
    }

}
