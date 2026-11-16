<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use Illuminate\Support\Facades\DB;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\query\Query;
use PKP\query\QueryParticipant;
use PKP\security\authorization\AuthorizationDecisionManager;
use PKP\security\authorization\AuthorizationPolicy;

/** Additional deny policy: it never grants access that the core would otherwise deny. */
class RequestPolicy extends AuthorizationPolicy
{
    public function __construct(
        private PKPRequest $request,
        private string $component = '',
        private string $operation = '',
        private ?PKPBaseController $controller = null
    ) {
        parent::__construct('plugins.generic.editorialAdvisor.denied');
    }

    public function effect(): int
    {
        $context = $this->request->getContext();
        $user = $this->request->getUser();
        if (!$context || !$user) {
            return AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE;
        }
        $access = new Access();
        $targets = $this->targets();
        foreach ($targets ?: [[null, null]] as [$submissionId, $stageId]) {
            $rules = $access->restrictions($context->getId(), $user->getId(), $submissionId, $stageId);
            if ($this->controller) {
                $http = app(\Illuminate\Http\Request::class);
                if (!$rules->allowsApi($http->method(), $http->path())) {
                    return self::AUTHORIZATION_DENY;
                }
            } elseif (!$rules->allowsComponent($this->component, $this->operation)) {
                return self::AUTHORIZATION_DENY;
            }
            if ($rules->blockContact && str_contains(strtolower($this->component), 'stageparticipant')
                && in_array(strtolower($this->operation), ['viewnotify', 'sendnotification'], true)) {
                $recipient = $this->parameter('userId');
                if (!$submissionId || !$stageId || !is_scalar($recipient) || !ctype_digit((string) $recipient)
                    || !in_array((int) $recipient, $access->notificationRecipients($submissionId, $stageId), true)) {
                    return self::AUTHORIZATION_DENY;
                }
            }
            if ($rules->restrictFiles && $this->modifiesReviewerAttachment()) {
                return self::AUTHORIZATION_DENY;
            }
            if ($rules->blockContact && in_array(strtolower($this->operation), ['addnote', 'insertnote', 'updatenote', 'deletenote', 'editquery', 'updatequery', 'deletequery', 'openquery', 'closequery'], true)) {
                $queryId = (int) $this->parameter('queryId');
                $query = $queryId ? Query::find($queryId) : null;
                if ($query && array_intersect(
                    $access->forbiddenParticipants((int) $query->assocId),
                    QueryParticipant::withQueryId($queryId)->pluck('user_id')->all()
                )) {
                    return self::AUTHORIZATION_DENY;
                }
            }
        }
        return AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE;
    }

    private function modifiesReviewerAttachment(): bool
    {
        $readOperations = ['fetchgrid', 'fetchrow', 'downloadfile', 'viewfile', 'fetch'];
        if ($this->controller ? in_array(app(\Illuminate\Http\Request::class)->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            : in_array(strtolower($this->operation), $readOperations, true)) {
            return false;
        }
        $fileStages = [(int) $this->parameter('fileStage')];
        foreach (['submissionFileId', 'revisedFileId'] as $fileParameter) {
            if (!($id = (int) $this->parameter($fileParameter))) {
                continue;
            }
            $file = \APP\facades\Repo::submissionFile()->get($id);
            $fileStages[] = (int) $file?->getData('fileStage');
        }
        return in_array(\PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT, $fileStages, true);
    }

    private function parameter(string $name): mixed
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
    private function targets(): array
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
