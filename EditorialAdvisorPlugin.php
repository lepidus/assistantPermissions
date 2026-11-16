<?php

/**
 * Editorial advisor permissions for OMP.
 * Derived from PKP pluginTemplate (4f6d1d4), distributed under GNU GPL v3.
 */

namespace APP\plugins\generic\editorialAdvisor;

use APP\core\Application;
use APP\plugins\generic\editorialAdvisor\classes\Access;
use APP\plugins\generic\editorialAdvisor\classes\AdvisorDecisionHandler;
use APP\plugins\generic\editorialAdvisor\classes\AdvisorSubmissionController;
use APP\plugins\generic\editorialAdvisor\classes\AdvisorUserGroupGridHandler;
use APP\plugins\generic\editorialAdvisor\classes\DiscussionHooks;
use APP\plugins\generic\editorialAdvisor\classes\RequestPolicy;
use APP\submission\maps\Schema;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\APIRouter;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class EditorialAdvisorPlugin extends GenericPlugin
{
    private ?Access $access = null;
    private array $readOnlyQueries = [];
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }
        Hook::add('Schema::get::userGroup', function ($name, $args) {
            foreach ([Access::BLOCK_CONTACT, Access::RESTRICT_FILES] as $option) {
                $args[0]->properties->{$option} = (object) [
                    'type' => 'boolean', 'origin' => 'setting', 'validation' => ['boolean'],
                ];
            }
            return false;
        });
        \PKP\stageAssignment\StageAssignment::saving(function ($assignment) {
            $group = \PKP\userGroup\UserGroup::findById((int) $assignment->userGroupId);
            if ($group && $group->roleId == Role::ROLE_ID_ASSISTANT && $this->getEnabled($group->contextId)) {
                $assignment->recommendOnly = (bool) $group->recommendOnly;
            }
        });
        Hook::add('LoadHandler', function ($name, $args) {
            if ($args[0] !== 'decision' || $args[1] !== 'record' || $args[3]) {
                return false;
            }
            $request = Application::get()->getRequest();
            $submissionId = (int) ($request->getRouter()->getRequestedArgs($request)[0] ?? 0);
            if ($request->getContext() && $request->getUser()
                && (new Access())->restrictions($request->getContext()->getId(), $request->getUser()->getId(), $submissionId)->recommendOnly) {
                $args[3] = new AdvisorDecisionHandler();
                return true;
            }
            return false;
        });
        Hook::add('LoadComponentHandler', $this->loadComponent(...), Hook::SEQUENCE_LAST);
        Hook::add('APIHandler::endpoints::plugin', function (string $name, APIRouter $router): bool {
            $request = Application::get()->getRequest();
            if (app(\Illuminate\Http\Request::class)->method() === 'POST'
                && preg_match('~/submissions/(\d+)/decisions/?$~', $request->getRequestPath(), $match)
                && $request->getContext() && $request->getUser()
                && (new Access())->restrictions($request->getContext()->getId(), $request->getUser()->getId(), (int) $match[1])->recommendOnly) {
                $router->registerPluginApiControllers([new AdvisorSubmissionController()]);
            }
            Hook::add('APIHandler::endpoints::' . $router->getEntity(), function ($name, $controller, $handler) {
                if (!$controller instanceof AdvisorSubmissionController) {
                    $controller->addPolicy(new RequestPolicy(Application::get()->getRequest(), controller: $controller));
                }
                return false;
            });
            return false;
        });
        Hook::add('TemplateManager::fetch', $this->prepareTemplate(...));
        $discussions = new DiscussionHooks();
        Hook::add('queryform::validate', $discussions->validate(...));
        Hook::add('querynoteform::validate', $discussions->validate(...));
        app('maps')->extend(Schema::class, $this->submissionPermissions(...));
        Hook::add('TemplateManager::display', function ($name, $args) {
            $request = Application::get()->getRequest();
            if ($args[1] === 'decision/record.tpl' && $request->getRouter()->getHandler() instanceof AdvisorDecisionHandler) {
                $steps = $args[0]->getState('steps') ?? [];
                foreach ($steps as $step) {
                    if ($step->type === 'email' && (new Access())->restrictions(
                        $request->getContext()->getId(),
                        $request->getUser()->getId(),
                        (int) ($request->getRouter()->getRequestedArgs($request)[0] ?? 0)
                    )->restrictFiles) {
                        $step->attachers = [];
                    }
                }
                $args[0]->setState(['steps' => $steps]);
                $args[0]->addStyleSheet('advisorRecommendation', $request->getBaseUrl() . '/' . $this->getPluginPath()
                    . '/public/styles/nativeRecommendation.css', ['contexts' => ['backend']]);
            }
            $args[0]->addStyleSheet('advisorParticipants', $request->getBaseUrl() . '/' . $this->getPluginPath()
                . '/public/styles/participants.css', ['contexts' => ['backend']]);
            $args[0]->addJavaScript(
                'editorialAdvisor',
                $request->getBaseUrl() . '/' . $this->getPluginPath() . '/public/build/editorialAdvisor.js',
                ['contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
            );
            return false;
        });
        return $success;
    }

    public function loadComponent(string $name, array $args): bool
    {
        [$component, $operation] = $args;
        if ($component === 'grid.settings.roles.UserGroupGridHandler') {
            $args[2] = new AdvisorUserGroupGridHandler();
            return true;
        }
        // Preserve a handler provided by another plugin. Otherwise load the core handler unchanged.
        $handler = $args[2];
        if (!$handler) {
            foreach (['APP', 'PKP'] as $namespace) {
                $class = $namespace . '\\controllers\\' . str_replace('.', '\\', $component);
                if (class_exists($class)) {
                    $handler = new $class();
                    break;
                }
            }
        }
        if (!$handler || !method_exists($handler, 'addPolicy')) {
            return false;
        }
        $request = Application::get()->getRequest();
        if ($component === 'grid.files.attachment.EditorReviewAttachmentsGridHandler'
            && $request->getContext() && $request->getUser()
            && (new Access())->restrictions(
                $request->getContext()->getId(),
                $request->getUser()->getId(),
                (int) $request->getUserVar('submissionId'),
                (int) $request->getUserVar('stageId')
            )->restrictFiles) {
            $handler->getCapabilities()->setCanAdd(false);
            $handler->getCapabilities()->setCanEdit(false);
            $handler->getCapabilities()->setCanDelete(false);
            $handler->getCapabilities()->setCanManage(false);
        }
        $handler->addPolicy(new RequestPolicy(Application::get()->getRequest(), $component, $operation));
        $args[2] = $handler;
        return true;
    }

    public function prepareTemplate(string $name, array $args): bool
    {
        [$manager, $template] = $args;
        if ($template === 'controllers/grid/settings/roles/form/userGroupForm.tpl') {
            $manager->assign('advisorAssistantRoleId', Role::ROLE_ID_ASSISTANT);
            $args[4] = $manager->fetch($this->getTemplateResource('userGroupForm.tpl'));
            return true;
        }
        if ($template === 'controllers/grid/users/reviewer/readReview.tpl') {
            $request = Application::get()->getRequest();
            $review = $manager->getTemplateVars('reviewAssignment');
            if ($review && $request->getContext() && $request->getUser()
                && (new Access())->restrictions($request->getContext()->getId(), $request->getUser()->getId(), $review->getSubmissionId(), $review->getStageId())->recommendOnly) {
                $recommendation = (int) $review->getRecommendation();
                $manager->assign('advisorReviewerRecommendation', $recommendation >= 1 && $recommendation <= 6
                    ? __('plugins.generic.editorialAdvisor.reviewerRecommendation.' . $recommendation) : '');
                $args[4] = $manager->fetch($this->getTemplateResource('readReview.tpl'));
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
                $manager->assign('advisorQueryParticipants', $participants->pluck('user')->filter()->all());
                $args[4] = $manager->fetch($this->getTemplateResource('readQuery.tpl'));
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
        $access = $this->access ??= new Access();
        return $this->readOnlyQueries[$query->id] = $request->getContext() && $request->getUser()
            && $access->restrictions($request->getContext()->getId(), $request->getUser()->getId(), (int) $query->assocId, (int) $query->stageId)->blockContact
            && (bool) array_intersect(
                $access->forbiddenParticipants((int) $query->assocId),
                \PKP\query\QueryParticipant::withQueryId($query->id)->pluck('user_id')->all()
            );
    }

    public function submissionPermissions(array $output, Submission $submission): array
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$request->getUser()) {
            return $output;
        }
        $access = $this->access ??= new Access();
        $contextId = $request->getContext()->getId();
        $userId = $request->getUser()->getId();
        $stageId = (int) $submission->getData('stageId');
        $rules = $access->restrictions($contextId, $userId, $submission->getId(), $stageId);
        $stages = [];
        foreach ([1, 2, 3, 4, 5] as $id) {
            $stageRules = $access->restrictions($contextId, $userId, $submission->getId(), $id);
            $stages[$id] = [
                'recommendOnly' => $stageRules->recommendOnly, 'blockContact' => $stageRules->blockContact,
                'restrictFiles' => $stageRules->restrictFiles,
                'notifyParticipantIds' => $stageRules->blockContact ? $access->notificationRecipients($submission->getId(), $id) : [],
            ];
        }
        foreach ($output['stages'] ?? [] as $key => $stage) {
            $id = (int) $stage['id'];
            if ($stages[$id]['recommendOnly'] ?? false) {
                $output['stages'][$key]['currentUserCanRecommendOnly'] = $access->canRecommend(
                    $contextId,
                    $userId,
                    $submission->getId(),
                    $id
                );
            }
        }
        $output['editorialAdvisor'] = [
            'stages' => $stages,
            'recommendOnly' => $rules->recommendOnly,
            'blockContact' => $rules->blockContact,
            'restrictFiles' => $rules->restrictFiles,
            'notifyParticipantIds' => $stages[$stageId]['notifyParticipantIds'] ?? [],
        ];
        return $output;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.editorialAdvisor.name');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.editorialAdvisor.description');
    }
}
