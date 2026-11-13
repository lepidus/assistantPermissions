<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use APP\handler\Handler;
use PKP\pages\decision\DecisionHandler;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\internal\DecisionStageValidPolicy;
use PKP\security\authorization\internal\DecisionTypeRequiredPolicy;
use PKP\security\authorization\internal\SubmissionRequiredPolicy;
use PKP\security\authorization\UserRequiredPolicy;
use PKP\security\Role;

/** Keep record(), its steps and templates native; authorize only assigned Assistant recommenders. */
class AdvisorDecisionHandler extends DecisionHandler
{
    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment([Role::ROLE_ID_ASSISTANT], ['record']);
    }

    public function authorize($request, &$args, $roleAssignments): bool
    {
        if ($request->getRouter()->getRequestedOp($request) !== 'record') {
            return false;
        }
        // Native page resolution accepts a query ID first: require it to match the path.
        if ($request->getUserVar('submissionId') !== null
            && (int) $request->getUserVar('submissionId') !== (int) ($args[0] ?? 0)) {
            return false;
        }
        $this->addPolicy(new UserRequiredPolicy($request));
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        $this->addPolicy(new SubmissionRequiredPolicy($request, $args, 'submissionId'));
        $this->addPolicy(new DecisionTypeRequiredPolicy($request, $args, (int) $request->getUserVar('decision')));
        $this->addPolicy(new DecisionStageValidPolicy());
        $this->addPolicy(new RecommendationAccessPolicy($request));
        // Use the common ancestor's global checks, not DecisionHandler's editor-only role policy.
        return Handler::authorize($request, $args, $roleAssignments);
    }
}
