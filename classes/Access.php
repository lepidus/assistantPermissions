<?php

namespace APP\plugins\generic\assistantPermissions\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\userGroup\UserGroup;

class Access
{
    public const BLOCK_CONTACT = 'assistantPermissionsBlockContact';
    public const RESTRICT_FILES = 'assistantPermissionsRestrictFiles';

    private array $groupsByUser = [];

    /** Users without an applicable Assistant group are never restricted by this plugin. */
    public function hasApplicableGroups(int $contextId, int $userId): bool
    {
        return $this->applicableAssistantGroups($contextId, $userId)->isNotEmpty();
    }

    /** Restrictions apply if any applicable Assistant group is restricted, regardless of its name. */
    public function restrictions(int $contextId, int $userId, ?int $submissionId = null, ?int $stageId = null): Restrictions
    {
        $groups = $this->applicableAssistantGroups($contextId, $userId);

        if ($submissionId !== null) {
            $stageId ??= (int) DB::table('submissions')->where('submission_id', $submissionId)->value('stage_id');
            $groups = $groups->filter(fn ($group) =>
                $group->stageAssignments->contains('submissionId', $submissionId)
                && $group->userGroupStages->contains('stageId', $stageId));
        }

        return new Restrictions(
            $submissionId !== null && $groups->contains(fn ($group) => $group->stageAssignments->contains(
                fn ($assignment) => (int) $assignment->submissionId === $submissionId && (bool) $assignment->recommendOnly
            )),
            $groups->contains(fn ($g) => (bool) $g->{self::BLOCK_CONTACT}),
            $groups->contains(fn ($g) => (bool) $g->{self::RESTRICT_FILES})
        );
    }

    /**
     * Assistant groups whose rules apply to the user: groups with an active membership and, like the core,
     * which honours an Assistant stage assignment while the user holds the Assistant role in any active group,
     * groups the user is still assigned through after that membership ended.
     */
    private function applicableAssistantGroups(int $contextId, int $userId)
    {
        $key = $contextId . ':' . $userId;
        if (array_key_exists($key, $this->groupsByUser)) {
            return $this->groupsByUser[$key];
        }

        $groups = UserGroup::withContextIds([$contextId])->withRoleIds([Role::ROLE_ID_ASSISTANT]);
        $activeMembership = fn ($q) => $q->withActive()->withUserId($userId);
        if (!(clone $groups)->whereHas('userUserGroups', $activeMembership)->exists()) {
            return $this->groupsByUser[$key] = collect();
        }

        return $this->groupsByUser[$key] = $groups
            ->where(fn ($q) => $q->whereHas('userUserGroups', $activeMembership)
                ->orWhereHas('stageAssignments', fn ($q) => $q->withUserId($userId)))
            ->with(['userGroupStages', 'stageAssignments' => fn ($q) => $q->withUserId($userId)])
            ->get();
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
            ->whereHas('userGroupStages', fn ($q) => $q->where('stage_id', $stageId))
            ->whereHas('userUserGroups', fn ($q) => $q->withActive()->withUserId($userId))
            ->pluck('user_group_id')->all();
        return StageAssignment::withSubmissionIds([$submissionId])->withStageIds([$stageId])
            ->withUserId($userId)->withRecommendOnly(true)->whereIn('user_group_id', $groupIds)->exists();
    }

    /** Only assigned editorial staff can receive direct participant notifications. */
    public function notificationRecipients(int $submissionId, int $stageId): array
    {
        $staff = StageAssignment::withSubmissionIds([$submissionId])->withStageIds([$stageId])
            ->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT])
            ->pluck('user_id')->all();
        return array_values(array_diff(array_unique(array_map('intval', $staff)), $this->forbiddenParticipants($submissionId)));
    }

    /** Authors and reviewers are excluded even when they also hold an editorial role. */
    public function forbiddenParticipants(int $submissionId): array
    {
        $authors = StageAssignment::withSubmissionIds([$submissionId])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])->pluck('user_id')->all();
        $reviewers = Repo::reviewAssignment()->getCollector()->filterBySubmissionIds([$submissionId])
            ->getMany()->map(fn ($r) => $r->getReviewerId())->all();
        // Contributors may have an account without an Author stage assignment. Emails match regardless of case:
        // PostgreSQL compares case-sensitively and the core indexes users by LOWER(email) there; MySQL and MariaDB
        // already compare them with the case-insensitive collation used by PKP, which keeps the email index usable.
        $sameEmail = DB::connection()->getDriverName() === 'pgsql'
            ? fn ($join) => $join->on(DB::raw('LOWER(u.email)'), '=', DB::raw('LOWER(a.email)'))
            : fn ($join) => $join->on('u.email', '=', 'a.email');
        $contributors = DB::table('authors as a')->join('publications as p', 'p.publication_id', '=', 'a.publication_id')
            ->join('users as u', $sameEmail)->where('p.submission_id', $submissionId)
            ->pluck('u.user_id')->all();
        return array_unique(array_map('intval', [...$authors, ...$reviewers, ...$contributors]));
    }
}
