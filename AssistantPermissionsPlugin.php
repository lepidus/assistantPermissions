<?php

/**
 * Assistant permissions for OMP.
 * Derived from PKP pluginTemplate (4f6d1d4), distributed under GNU GPL v3.
 */

namespace APP\plugins\generic\assistantPermissions;

use APP\core\Application;
use APP\plugins\generic\assistantPermissions\classes\Access;
use APP\plugins\generic\assistantPermissions\classes\AssistantPermissionsDecisionHandler;
use APP\plugins\generic\assistantPermissions\classes\AssistantPermissionsSubmissionController;
use APP\plugins\generic\assistantPermissions\classes\AssistantPermissionsUserGroupGridHandler;
use APP\plugins\generic\assistantPermissions\classes\DiscussionHooks;
use APP\plugins\generic\assistantPermissions\classes\RequestPolicy;
use APP\plugins\generic\assistantPermissions\classes\SubmissionPermissions;
use APP\plugins\generic\assistantPermissions\classes\TemplateHooks;
use APP\submission\maps\Schema;
use PKP\core\APIRouter;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class AssistantPermissionsPlugin extends GenericPlugin
{
    private ?Access $access = null;

    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);

        if (!$success || Application::isUnderMaintenance() || !$this->getEnabled($mainContextId)) {
            return $success;
        }

        Hook::add('Schema::get::userGroup', $this->extendUserGroupSchema(...));
        \PKP\stageAssignment\StageAssignment::saving($this->synchronizeRecommendationSetting(...));

        Hook::add('LoadHandler', $this->loadDecisionHandler(...));
        Hook::add('LoadComponentHandler', $this->loadComponent(...), Hook::SEQUENCE_LAST);
        Hook::add('APIHandler::endpoints::plugin', $this->registerApiControllers(...));

        $templates = new TemplateHooks($this, $this->access ??= new Access());
        Hook::add('TemplateManager::fetch', $templates->prepareTemplate(...));

        $discussions = new DiscussionHooks();
        Hook::add('queryform::validate', $discussions->validate(...));
        Hook::add('querynoteform::validate', $discussions->validate(...));

        $permissions = new SubmissionPermissions($this->access);
        app('maps')->extend(Schema::class, $permissions->map(...));
        Hook::add('TemplateManager::display', $templates->prepareDisplay(...));

        return $success;
    }

    private function extendUserGroupSchema(string $name, array $args): bool
    {
        foreach ([Access::BLOCK_CONTACT, Access::RESTRICT_FILES] as $option) {
            $args[0]->properties->{$option} = (object) [
                'type' => 'boolean', 'origin' => 'setting', 'validation' => ['boolean'],
            ];
        }

        return false;
    }

    private function synchronizeRecommendationSetting($assignment): void
    {
        $group = \PKP\userGroup\UserGroup::findById((int) $assignment->userGroupId);

        if ($group && $group->roleId == Role::ROLE_ID_ASSISTANT && $this->getEnabled($group->contextId)) {
            $assignment->recommendOnly = (bool) $group->recommendOnly;
        }
    }

    private function loadDecisionHandler(string $name, array $args): bool
    {
        if ($args[0] !== 'decision' || $args[1] !== 'record' || $args[3]) {
            return false;
        }

        $request = Application::get()->getRequest();
        $submissionId = (int) ($request->getRouter()->getRequestedArgs($request)[0] ?? 0);

        if ($request->getContext() && $request->getUser()
            && (new Access())->restrictions($request->getContext()->getId(), $request->getUser()->getId(), $submissionId)->recommendOnly) {
            $args[3] = new AssistantPermissionsDecisionHandler();
            return true;
        }

        return false;
    }

    private function registerApiControllers(string $name, APIRouter $router): bool
    {
        $request = Application::get()->getRequest();

        if (app(\Illuminate\Http\Request::class)->method() === 'POST'
            && preg_match('~/submissions/(\d+)/decisions/?$~', $request->getRequestPath(), $match)
            && $request->getContext() && $request->getUser()
            && (new Access())->restrictions($request->getContext()->getId(), $request->getUser()->getId(), (int) $match[1])->recommendOnly) {
            $router->registerPluginApiControllers([new AssistantPermissionsSubmissionController()]);
        }

        Hook::add('APIHandler::endpoints::' . $router->getEntity(), $this->applyApiRestrictions(...));

        return false;
    }

    private function applyApiRestrictions($name, $controller, $handler): bool
    {
        if (!$controller instanceof AssistantPermissionsSubmissionController) {
            $controller->addPolicy(new RequestPolicy(Application::get()->getRequest(), controller: $controller));
        }

        return false;
    }

    public function loadComponent(string $name, array $args): bool
    {
        [$component, $operation] = $args;

        if ($component === 'grid.settings.roles.UserGroupGridHandler') {
            $args[2] = new AssistantPermissionsUserGroupGridHandler();
            return true;
        }

        $handler = $this->resolveComponentHandler($component, $args[2]);

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

    private function resolveComponentHandler(string $component, $handler)
    {
        // Preserve a handler provided by another plugin.
        if ($handler) {
            return $handler;
        }

        foreach (['APP', 'PKP'] as $namespace) {
            $class = $namespace . '\\controllers\\' . str_replace('.', '\\', $component);

            if (class_exists($class)) {
                return new $class();
            }
        }

        return null;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.assistantPermissions.name');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.assistantPermissions.description');
    }
}
