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
            if ($rules->blockContact && in_array(strtolower($this->operation), ['addnote', 'insertnote', 'updatenote', 'editquery', 'updatequery'], true)) {
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

    private function parameter(string $name): mixed
    {
        return $this->controller
            ? $this->controller->getParameter($name, $this->request->getUserVar($name))
            : $this->request->getUserVar($name);
    }

    /** Object stages take precedence over user-supplied IDs and stages. */
    private function targets(): array
    {
        $targets = [];
        foreach ([
            'queryId' => ['queries', 'query_id', 'assoc_id'],
            'reviewAssignmentId' => ['review_assignments', 'review_id', 'submission_id'],
            'reviewId' => ['review_assignments', 'review_id', 'submission_id'],
            'reviewRoundId' => ['review_rounds', 'review_round_id', 'submission_id'],
        ] as $name => [$table, $key, $column]) {
            if ($id = (int) $this->parameter($name)) {
                $row = DB::table($table)->where($key, $id)->first();
                if ($row) {
                    $targets[] = [(int) $row->{$column}, (int) $row->stage_id];
                }
            }
        }
        if ($id = (int) $this->parameter('submissionFileId')) {
            $file = \APP\facades\Repo::submissionFile()->get($id);
            if ($file) {
                $targets[] = [(int) $file->getData('submissionId'), \APP\facades\Repo::submissionFile()->getWorkflowStageId($file)];
            }
        }
        $submissionId = (int) $this->parameter('submissionId');
        if ($submissionId && !in_array($submissionId, array_column($targets, 0), true)) {
            // API decisions act on the current stage; component stages are also checked by the core.
            $stageId = $this->controller ? null : ((int) $this->parameter('stageId') ?: null);
            $targets[] = [$submissionId, $stageId];
        }
        return $targets;
    }
}
