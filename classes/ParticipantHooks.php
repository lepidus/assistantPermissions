<?php

namespace APP\plugins\generic\assistantPermissions\classes;

use APP\core\Application;
use APP\submission\Submission;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\stageAssignment\StageAssignment;
use PKP\userGroup\UserGroup;

/** Extend the native participant form without replacing its validation or submission workflow. */
class ParticipantHooks
{
    public function prepareTemplate(string $name, array $args): bool
    {
        [$manager, $template] = $args;
        if ($template !== 'controllers/grid/users/stageParticipant/addParticipantForm.tpl') {
            return false;
        }

        $request = Application::get()->getRequest();
        $ids = UserGroup::withContextIds([$request->getContext()->getId()])
            ->withRoleIds([Role::ROLE_ID_ASSISTANT])
            ->whereHas('userGroupStages', fn ($query) => $query->where('stage_id', (int) $manager->getTemplateVars('stageId')))
            ->pluck('user_group_id')->all();
        $manager->assign('possibleRecommendOnlyUserGroupIds', array_values(array_unique([
            ...($manager->getTemplateVars('possibleRecommendOnlyUserGroupIds') ?? []), ...$ids,
        ])));

        $assignment = StageAssignment::find((int) $manager->getTemplateVars('assignmentId'));
        if ($assignment && in_array((int) $assignment->userGroupId, $ids, true)) {
            $submission = \APP\facades\Repo::submission()->get($assignment->submissionId);
            $manager->assign('isChangeRecommendOnlyAllowed', $submission && $this->canConfigure(
                $submission,
                (int) $manager->getTemplateVars('stageId'),
                (int) $assignment->userId,
                $assignment
            ));
        }

        return false;
    }

    public function validate(string $name, array $args): bool
    {
        $form = $args[0];
        $assignment = $form->_assignmentId ? StageAssignment::find($form->_assignmentId) : null;
        $group = UserGroup::findById((int) $form->getData('userGroupId'));
        if ($form->getData('message')) {
            $request = Application::get()->getRequest();
            $submission = $form->getSubmission();
            $access = new Access();
            $rules = $request->getContext() && $request->getUser() ? $access->restrictions(
                $request->getContext()->getId(),
                $request->getUser()->getId(),
                $submission->getId(),
                $form->getStageId()
            ) : null;
            if ($rules?->blockContact
                && (in_array($group?->roleId, [Role::ROLE_ID_AUTHOR, Role::ROLE_ID_REVIEWER], true)
                    || in_array(
                        (int) $form->getData('userId'),
                        $access->forbiddenParticipants($submission->getId()),
                        true
                    ))) {
                $form->addError('message', __('plugins.generic.assistantPermissions.denied'));
            }
        }
        // Inspect the actual assignment before trusting the submitted group's role.
        if ($group?->roleId != Role::ROLE_ID_ASSISTANT
            && $assignment?->userGroup?->roleId != Role::ROLE_ID_ASSISTANT) {
            return false;
        }

        $submission = $form->getSubmission();
        $valid = $group && $group->roleId == Role::ROLE_ID_ASSISTANT
            && (int) $group->contextId === (int) $submission->getData('contextId')
            && $group->userGroupStages->contains('stageId', $form->getStageId())
            && (!$form->_assignmentId || ($assignment
                && (int) $assignment->submissionId === $submission->getId()
                && (int) $assignment->userGroupId === (int) $group->id
                && (int) $assignment->userId === (int) $form->getData('userId')))
            && $this->canConfigure($submission, $form->getStageId(), (int) $form->getData('userId'), $assignment);
        if (!$valid) {
            $form->addError('recommendOnly', __('plugins.generic.assistantPermissions.denied'));
        }

        return false;
    }

    public function execute(string $name, array $args): bool
    {
        $form = $args[0];
        if (!$form->_assignmentId || !$form->isValid()) {
            return false;
        }

        $assignment = StageAssignment::find($form->_assignmentId);
        if ($assignment && $assignment->userGroup->roleId == Role::ROLE_ID_ASSISTANT
            && (int) $assignment->submissionId === $form->getSubmission()->getId()
            && (int) $assignment->userGroupId === (int) $form->getData('userGroupId')
            && (int) $assignment->userId === (int) $form->getData('userId')
            && $this->canConfigure($form->getSubmission(), $form->getStageId(), (int) $assignment->userId, $assignment)) {
            $assignment->recommendOnly = (bool) $form->getData('recommendOnly');
            $assignment->save();
        }

        return false;
    }

    private function canConfigure(Submission $submission, int $stageId, int $userId, ?StageAssignment $assignment): bool
    {
        $request = Application::get()->getRequest();
        $actor = $request->getUser();
        $contextId = (int) $submission->getData('contextId');
        if (!$actor || !$request->getContext() || $request->getContext()->getId() !== $contextId
            || $actor->getId() === $userId
            || StageAssignment::withSubmissionIds([$submission->getId()])->withStageIds([$stageId])
                ->withUserId($actor->getId())->withRecommendOnly(true)->exists()) {
            return false;
        }

        if ($assignment) {
            return Validation::canEditParticipant($actor, $submission, $assignment);
        }

        return $actor->hasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER], $contextId)
            || StageAssignment::withSubmissionIds([$submission->getId()])->withStageIds([$stageId])
                ->withUserId($actor->getId())->withRoleIds([Role::ROLE_ID_SUB_EDITOR])->exists();
    }
}
