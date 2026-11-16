<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPRequest;
use PKP\security\authorization\AuthorizationPolicy;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;

/** Strict authorization for the Assistant's native recommendation page and POST. */
class RecommendationAccessPolicy extends AuthorizationPolicy
{
    public function __construct(private PKPRequest $request, private bool $writing = false)
    {
        parent::__construct('plugins.generic.editorialAdvisor.denied');
    }

    public function effect(): int
    {
        $context = $this->request->getContext();
        $user = $this->request->getUser();
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
        $type = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_DECISION_TYPE);

        if (!$context || !$user || !$submission || !$type) {
            return self::AUTHORIZATION_DENY;
        }

        $stageId = (int) $submission->getData('stageId');
        $access = new Access();

        if (!in_array($stageId, [WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW], true)
            || !Repo::decision()->isRecommendation($type->getDecision()) || $type->getStageId() !== $stageId
            || (int) $submission->getData('status') !== Submission::STATUS_QUEUED
            || (string) $submission->getData('submissionProgress') !== ''
            || !$access->canRecommend($context->getId(), $user->getId(), $submission->getId(), $stageId)) {
            return self::AUTHORIZATION_DENY;
        }

        if (!$this->isCurrentReviewRound($submission->getId(), $stageId)) {
            return self::AUTHORIZATION_DENY;
        }

        $editors = StageAssignment::withSubmissionIds([$submission->getId()])->withStageIds([$stageId])
            ->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])->withRecommendOnly(false)
            ->pluck('user_id')->all();
        // The native flow notifies every deciding editor, so it cannot run if any is forbidden.
        if (!$editors || array_intersect($editors, $access->forbiddenParticipants($submission->getId()))) {
            return self::AUTHORIZATION_DENY;
        }

        if ($this->writing) {
            $fileRules = (new Access())->restrictions(
                $context->getId(),
                $user->getId(),
                $submission->getId(),
                $stageId
            );

            if (!$this->allowsDiscussionAction($editors, $fileRules->restrictFiles)) {
                return self::AUTHORIZATION_DENY;
            }
        }

        return self::AUTHORIZATION_PERMIT;
    }

    private function isCurrentReviewRound(int $submissionId, int $stageId): bool
    {
        $roundId = (int) DB::table('review_rounds')->where('submission_id', $submissionId)
            ->where('stage_id', $stageId)->orderByDesc('round')->value('review_round_id');

        $requestedRoundId = $this->writing
            ? app(\Illuminate\Http\Request::class)->input('reviewRoundId')
            : $this->request->getUserVar('reviewRoundId');

        return $roundId && $roundId === (int) $requestedRoundId;
    }

    private function allowsDiscussionAction(array $editors, bool $restrictFiles): bool
    {
        $actions = app(\Illuminate\Http\Request::class)->input('actions');

        if (!is_array($actions) || count($actions) !== 1) {
            return false;
        }

        $action = reset($actions);

        if (!is_array($action) || ($action['id'] ?? '') !== 'discussion'
            || !empty($action['cc']) || !empty($action['bcc'])
            || ($restrictFiles && !empty($action['attachments']))
            || !is_array($action['recipients'] ?? [])
            || array_diff($action['recipients'] ?? [], $editors)) {
            return false;
        }

        return true;
    }
}
