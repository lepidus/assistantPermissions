<?php

namespace APP\plugins\generic\assistantPermissions\classes;

use Illuminate\Support\Facades\DB;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;

/** Resolve canonical submissions and stages from legacy component and API resources. */
class RequestTargets
{
    public function __construct(private PKPRequest $request, private ?PKPBaseController $controller = null)
    {
    }

    public function parameter(string $name): mixed
    {
        if ($this->controller && in_array($name, ['fileStage', 'assocType', 'assocId'], true)) {
            // Match the core write path: the request body takes precedence over the query string.
            return app(\Illuminate\Http\Request::class)->input($name);
        }

        return $this->controller
            ? $this->controller->getParameter($name, $this->request->getUserVar($name))
            : $this->request->getUserVar($name);
    }

    /** Object stages take precedence over user-supplied IDs and stages. */
    public function resolve(): array
    {
        $targets = [];
        $apiPath = $this->controller?->getHandlerPath();
        if ($this->controller && $apiPath !== 'reviews' && $apiPath !== 'submissions/{submissionId}/files') {
            // Ignore arbitrary body/query IDs on non-resource APIs, including bulk email.
            $id = (int) $this->parameter('submissionId');
            return $apiPath === 'submissions' && $id ? [[$id, null]] : [];
        }

        foreach ([
            'queryId' => ['queries', 'query_id', 'assoc_id'],
            'reviewAssignmentId' => ['review_assignments', 'review_id', 'submission_id'],
            'reviewId' => ['review_assignments', 'review_id', 'submission_id'],
            'reviewRoundId' => ['review_rounds', 'review_round_id', 'submission_id'],
        ] as $name => [$table, $key, $column]) {
            if ($this->controller && ($apiPath !== 'reviews' || $name !== 'reviewAssignmentId')) {
                continue;
            }
            if ($id = (int) $this->parameter($name)) {
                $row = DB::table($table)->where($key, $id)->first();
                if ($row) {
                    $targets[] = [(int) $row->{$column}, (int) $row->stage_id];
                }
            }
        }

        foreach (['submissionFileId', 'revisedFileId'] as $fileParameter) {
            if (!($id = (int) $this->parameter($fileParameter))) {
                continue;
            }
            $file = \APP\facades\Repo::submissionFile()->get($id);
            if ($file) {
                $stageId = \APP\facades\Repo::submissionFile()->getWorkflowStageId($file);
                if ($file->getData('assocType') === \APP\core\Application::ASSOC_TYPE_REVIEW_ASSIGNMENT) {
                    $stageId = DB::table('review_assignments')->where('review_id', $file->getData('assocId'))->value('stage_id');
                }
                $targets[] = [(int) $file->getData('submissionId'), $stageId === null ? null : (int) $stageId];
            }
        }

        if ((int) $this->parameter('assocType') === \APP\core\Application::ASSOC_TYPE_REVIEW_ASSIGNMENT
            && ($id = (int) $this->parameter('assocId'))) {
            $review = DB::table('review_assignments')->where('review_id', $id)->first();
            if ($review) {
                $targets[] = [(int) $review->submission_id, (int) $review->stage_id];
            }
        }

        $submissionId = (int) $this->parameter('submissionId');
        if ($submissionId && ((!$this->controller && (int) $this->parameter('stageId'))
            || !in_array($submissionId, array_column($targets, 0), true))) {
            // API decisions act on the current stage; component stages are also checked by the core.
            $stageId = $this->controller ? null : ((int) $this->parameter('stageId') ?: null);
            $targets[] = [$submissionId, $stageId];
        }

        return $targets;
    }
}
