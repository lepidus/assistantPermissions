<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\query\Query;
use PKP\query\QueryParticipant;
use PKP\security\authorization\AuthorizationDecisionManager;
use PKP\security\authorization\AuthorizationPolicy;

/** Additional deny policy: it never grants access that the core would otherwise deny. */
class RequestPolicy extends AuthorizationPolicy
{
    private RequestTargets $requestTargets;

    public function __construct(
        private PKPRequest $request,
        private string $component = '',
        private string $operation = '',
        private ?PKPBaseController $controller = null
    ) {
        parent::__construct('plugins.generic.editorialAdvisor.denied');

        $this->requestTargets = new RequestTargets($request, $controller);
    }

    public function effect(): int
    {
        $context = $this->request->getContext();
        $user = $this->request->getUser();
        if (!$context || !$user) {
            return AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE;
        }

        $access = new Access();
        $targets = $this->requestTargets->resolve();
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
                $recipient = $this->requestTargets->parameter('userId');
                if (!$submissionId || !$stageId || !is_scalar($recipient) || !ctype_digit((string) $recipient)
                    || !in_array((int) $recipient, $access->notificationRecipients($submissionId, $stageId), true)) {
                    return self::AUTHORIZATION_DENY;
                }
            }
            if ($rules->restrictFiles && $this->modifiesReviewerAttachment()) {
                return self::AUTHORIZATION_DENY;
            }
            if ($rules->blockContact && in_array(strtolower($this->operation), ['addnote', 'insertnote', 'updatenote', 'deletenote', 'editquery', 'updatequery', 'deletequery', 'openquery', 'closequery'], true)) {
                $queryId = (int) $this->requestTargets->parameter('queryId');
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

        $fileStages = [(int) $this->requestTargets->parameter('fileStage')];
        foreach (['submissionFileId', 'revisedFileId'] as $fileParameter) {
            if (!($id = (int) $this->requestTargets->parameter($fileParameter))) {
                continue;
            }
            $file = \APP\facades\Repo::submissionFile()->get($id);
            $fileStages[] = (int) $file?->getData('fileStage');
        }

        return in_array(\PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT, $fileStages, true);
    }

}
