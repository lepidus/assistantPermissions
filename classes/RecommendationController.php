<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use APP\facades\Repo;
use APP\submission\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PKP\core\Core;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\security\authorization\SubmissionAccessPolicy;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\validation\ValidatorFactory;

class RecommendationController extends PKPBaseController
{
    public function getHandlerPath(): string
    {
        return 'plugins/editorial-advisor';
    }

    public function getRouteGroupMiddleware(): array
    {
        return ['has.user', 'has.context', self::roleAuthorizer([Role::ROLE_ID_ASSISTANT])];
    }

    public function getGroupRoutes(): void
    {
        Route::post('{submissionId}/recommendations', $this->recommend(...))->whereNumber('submissionId');
    }

    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        $this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function recommend(Request $http): JsonResponse
    {
        $validator = ValidatorFactory::make($http->all(), [
            'decision' => 'required|integer',
            'stageId' => 'required|integer',
            'reviewRoundId' => 'required|integer',
            'comment' => 'required|string|max:20000',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->toArray()], 422);
        }
        $input = $validator->validated();
        $request = $this->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        $submissionId = (int) $this->getParameter('submissionId');
        return DB::transaction(function () use ($input, $context, $user, $submissionId) {
            DB::table('submissions')->where('submission_id', $submissionId)->lockForUpdate()->first();
            $submission = Repo::submission()->get($submissionId);
            $stageId = (int) $input['stageId'];
            $access = new Access();
            if (!$access->canRecommend($context->getId(), $user->getId(), $submissionId, $stageId)
                || (int) $submission->getData('status') !== Submission::STATUS_QUEUED) {
                return response()->json(['error' => __('plugins.generic.editorialAdvisor.denied')], 403);
            }
            $type = Repo::decision()->getDecisionType((int) $input['decision']);
            $roundId = (int) DB::table('review_rounds')->where('submission_id', $submissionId)
                ->where('stage_id', $stageId)->orderByDesc('round')->value('review_round_id');
            if (!$type || !Repo::decision()->isRecommendation($type->getDecision())
                || $type->getStageId() !== $stageId || !$roundId || $roundId !== (int) $input['reviewRoundId']) {
                return response()->json(['error' => __('plugins.generic.editorialAdvisor.invalidRecommendation')], 422);
            }
            $props = [
                'submissionId' => $submissionId,
                'stageId' => $stageId,
                'editorId' => $user->getId(),
                'decision' => $type->getDecision(),
                'reviewRoundId' => $roundId,
                'dateDecided' => Core::getCurrentDate(),
                'actions' => [],
            ];
            $errors = Repo::decision()->validate($props, $type, $submission, $context);
            $editors = StageAssignment::withSubmissionIds([$submissionId])->withStageIds([$stageId])
                ->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])->withRecommendOnly(false)
                ->pluck('user_id')->all();
            $editors = array_values(array_diff($editors, $access->forbiddenParticipants($submissionId)));
            if ($errors || !$editors) {
                return response()->json(['errors' => $errors, 'error' => __('plugins.generic.editorialAdvisor.noEditors')], 422);
            }
            $id = Repo::decision()->add(Repo::decision()->newDataObject($props));
            // A plain-text note, with no client-controlled recipients, attachments, cc or bcc.
            Repo::query()->addQuery(
                $submissionId,
                $stageId,
                $type->getLabel(),
                nl2br(htmlspecialchars($input['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                $user,
                array_unique([...$editors, $user->getId()]),
                $context->getId(),
                false
            );
            return response()->json(['id' => $id], 201);
        });
    }
}
