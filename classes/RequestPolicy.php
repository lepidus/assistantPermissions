<?php

namespace APP\plugins\generic\assistantPermissions\classes;

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
        parent::__construct('plugins.generic.assistantPermissions.denied');

        $this->requestTargets = new RequestTargets($request, $controller, $component);
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
                if (!$rules->allowsApi($http->method(), $http->path())
                    || !$this->allowsDecisionActions($rules, $access, $submissionId, $stageId)) {
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
            if ($rules->blockContact && $this->modifiesDiscussion()) {
                foreach ($this->requestTargets->queryIds() as $queryId) {
                    $query = Query::find($queryId);
                    if ($query && array_intersect(
                        $access->forbiddenParticipants((int) $query->assocId),
                        QueryParticipant::withQueryId($queryId)->pluck('user_id')->all()
                    )) {
                        return self::AUTHORIZATION_DENY;
                    }
                }
            }
        }

        return AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE;
    }

    private function modifiesDiscussion(): bool
    {
        if ($this->controller) {
            return $this->controller->getHandlerPath() === 'submissions/{submissionId}/files'
                && !in_array(app(\Illuminate\Http\Request::class)->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        }

        return (str_contains(strtolower($this->component), 'quer') || str_contains(strtolower($this->component), 'file'))
            && !in_array(strtolower($this->operation), [
                'fetchgrid', 'fetchcategory', 'fetchrow', 'fetch', 'readquery', 'participants', 'fetchtemplatebody',
                'viewfile', 'downloadfile', 'downloadallfiles', 'downloadlibraryfile', 'recorddownload', 'enablelinkaction',
                'viewinformationcenter', 'viewhistory', 'viewnotes', 'listnotes',
            ], true);
    }

    /** Native editorial decisions can send mail and copy files without using their dedicated APIs. */
    private function allowsDecisionActions(Restrictions $rules, Access $access, ?int $submissionId, ?int $stageId): bool
    {
        $http = app(\Illuminate\Http\Request::class);
        if ((!$rules->blockContact && !$rules->restrictFiles) || $http->method() !== 'POST'
            || !preg_match('~(?:^|/)submissions/\d+/decisions/?$~', $http->path())) {
            return true;
        }

        $actions = $http->input('actions', []);
        if (!is_array($actions)) {
            return false;
        }

        foreach ($actions as $action) {
            if (!is_array($action) || ($rules->restrictFiles && !empty($action['attachments']))) {
                return false;
            }
            if (!$rules->blockContact) {
                continue;
            }
            if (in_array($action['id'] ?? '', ['notifyAuthors', 'notifyReviewers'], true)
                || !empty($action['cc']) || !empty($action['bcc'])) {
                return false;
            }
            if (($action['id'] ?? '') !== 'discussion') {
                continue;
            }
            $stageId ??= $submissionId
                ? (int) \APP\facades\Repo::submission()->get($submissionId)?->getData('stageId') : null;
            if (!$submissionId || !$stageId) {
                return false;
            }

            // The native recommendation discussion notifies all deciding editors, not just the input recipients.
            $editors = \PKP\stageAssignment\StageAssignment::withSubmissionIds([$submissionId])->withStageIds([$stageId])
                ->withRoleIds([\PKP\security\Role::ROLE_ID_MANAGER, \PKP\security\Role::ROLE_ID_SUB_EDITOR])
                ->withRecommendOnly(false)->pluck('user_id')->all();
            if (!$editors || array_intersect($editors, $access->forbiddenParticipants($submissionId))
                || !is_array($action['recipients'] ?? []) || array_diff($action['recipients'] ?? [], $editors)) {
                return false;
            }
        }

        return true;
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
