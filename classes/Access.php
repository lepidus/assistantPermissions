<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\userGroup\UserGroup;

class Access
{
    public const BLOCK_CONTACT = 'editorialAdvisorBlockContact';

    /** Restrictions apply if any active Assistant assignment is restricted, regardless of its name. */
    public function restrictions(int $contextId, int $userId, ?int $submissionId = null, ?int $stageId = null): Restrictions
    {
        $groups = UserGroup::withContextIds([$contextId])
            ->withRoleIds([Role::ROLE_ID_ASSISTANT])
            ->whereHas('userUserGroups', fn ($q) => $q->withActive()->withUserId($userId))
            ->get();
        if ($submissionId !== null) {
            $stageId ??= (int) DB::table('submissions')->where('submission_id', $submissionId)->value('stage_id');
            $assignedIds = StageAssignment::withSubmissionIds([$submissionId])->withStageIds([$stageId])
                ->withUserId($userId)->pluck('user_group_id')->all();
            $groups = $groups->whereIn('id', $assignedIds);
        }
        return new Restrictions(
            $groups->contains(fn ($g) => (bool) $g->recommendOnly),
            $groups->contains(fn ($g) => (bool) $g->{self::BLOCK_CONTACT})
        );
    }

    public function canRecommend(int $contextId, int $userId, int $submissionId, int $stageId): bool
    {
        $submission = Repo::submission()->get($submissionId);
        if (!$submission || (int) $submission->getData('contextId') !== $contextId
            || (int) $submission->getData('stageId') !== $stageId) {
            return false;
        }
        // Do not grant an author/reviewer an editorial capability on their own submission.
        if (in_array($userId, $this->forbiddenParticipants($submissionId), true)
            || StageAssignment::withSubmissionIds([$submissionId])->withUserId($userId)
                ->withRoleIds([Role::ROLE_ID_AUTHOR])->exists()
            || Repo::reviewAssignment()->getCollector()->filterBySubmissionIds([$submissionId])
                ->filterByReviewerIds([$userId])->getCount()) {
            return false;
        }
        $groupIds = UserGroup::withContextIds([$contextId])->withRoleIds([Role::ROLE_ID_ASSISTANT])
            ->isRecommendOnly(true)
            ->whereHas('userUserGroups', fn ($q) => $q->withActive()->withUserId($userId))
            ->pluck('user_group_id')->all();
        return StageAssignment::withSubmissionIds([$submissionId])->withStageIds([$stageId])
            ->withUserId($userId)->whereIn('user_group_id', $groupIds)->exists();
    }

    /** Authors and reviewers are excluded even when they also hold an editorial role. */
    public function forbiddenParticipants(int $submissionId): array
    {
        $authors = StageAssignment::withSubmissionIds([$submissionId])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])->pluck('user_id')->all();
        $reviewers = Repo::reviewAssignment()->getCollector()->filterBySubmissionIds([$submissionId])
            ->getMany()->map(fn ($r) => $r->getReviewerId())->all();
        // Contributors may have an account without an Author stage assignment.
        $contributors = DB::table('authors as a')->join('publications as p', 'p.publication_id', '=', 'a.publication_id')
            ->join('users as u', 'u.email', '=', 'a.email')->where('p.submission_id', $submissionId)
            ->pluck('u.user_id')->all();
        return array_unique(array_map('intval', [...$authors, ...$reviewers, ...$contributors]));
    }
}
