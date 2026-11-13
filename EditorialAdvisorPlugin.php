<?php

/**
 * Editorial advisor permissions for OMP.
 * Derived from PKP pluginTemplate (4f6d1d4), distributed under GNU GPL v3.
 */

namespace APP\plugins\generic\editorialAdvisor;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\editorialAdvisor\classes\Access;
use APP\plugins\generic\editorialAdvisor\classes\AdvisorUserGroupGridHandler;
use APP\plugins\generic\editorialAdvisor\classes\DiscussionHooks;
use APP\plugins\generic\editorialAdvisor\classes\RecommendationController;
use APP\plugins\generic\editorialAdvisor\classes\RequestPolicy;
use APP\submission\maps\Schema;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\APIRouter;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class EditorialAdvisorPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }
        Hook::add('Schema::get::userGroup', function ($name, $args) {
            $args[0]->properties->{Access::BLOCK_CONTACT} = (object) [
                'type' => 'boolean', 'origin' => 'setting', 'validation' => ['boolean'],
            ];
            return false;
        });
        Hook::add('LoadComponentHandler', $this->loadComponent(...), Hook::SEQUENCE_LAST);
        Hook::add('APIHandler::endpoints::plugin', function (string $name, APIRouter $router): bool {
            $router->registerPluginApiControllers([new RecommendationController()]);
            Hook::add('APIHandler::endpoints::' . $router->getEntity(), function ($name, $controller, $handler) {
                $controller->addPolicy(new RequestPolicy(Application::get()->getRequest(), controller: $controller));
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
                $args[4] = $manager->fetch($this->getTemplateResource('readReview.tpl'));
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

    public function submissionPermissions(array $output, Submission $submission): array
    {
        $request = Application::get()->getRequest();
        if (!$request->getContext() || !$request->getUser()) {
            return $output;
        }
        $access = new Access();
        $contextId = $request->getContext()->getId();
        $userId = $request->getUser()->getId();
        $rules = $access->restrictions($contextId, $userId, $submission->getId());
        $stageId = (int) $submission->getData('stageId');
        $options = [];
        if ($rules->recommendOnly && $access->canRecommend($contextId, $userId, $submission->getId(), $stageId)
            && (int) $submission->getData('status') === Submission::STATUS_QUEUED) {
            foreach (Repo::decision()->getDecisionTypes() as $type) {
                if (Repo::decision()->isRecommendation($type->getDecision()) && $type->getStageId() === $stageId) {
                    $options[] = ['value' => $type->getDecision(), 'label' => $type->getLabel()];
                }
            }
        }
        $stages = [];
        foreach ([1, 2, 3, 4, 5] as $id) {
            $stageRules = $access->restrictions($contextId, $userId, $submission->getId(), $id);
            $stages[$id] = ['recommendOnly' => $stageRules->recommendOnly, 'blockContact' => $stageRules->blockContact];
        }
        $output['editorialAdvisor'] = [
            'stages' => $stages,
            'recommendOnly' => $rules->recommendOnly,
            'blockContact' => $rules->blockContact,
            'recommendations' => $options,
            'reviewRoundId' => (int) DB::table('review_rounds')->where('submission_id', $submission->getId())
                ->where('stage_id', $stageId)->orderByDesc('round')->value('review_round_id'),
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
