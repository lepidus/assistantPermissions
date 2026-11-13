<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use APP\API\v1\submissions\SubmissionController;
use Illuminate\Support\Facades\Route;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\internal\DecisionStageValidPolicy;
use PKP\security\authorization\internal\DecisionTypeRequiredPolicy;
use PKP\security\authorization\internal\SubmissionCompletePolicy;
use PKP\security\authorization\SubmissionAccessPolicy;
use PKP\security\authorization\UserRequiredPolicy;
use PKP\security\Role;

/** Adapt only the native POST; validation, persistence, discussion and mail remain inherited. */
class AdvisorSubmissionController extends SubmissionController
{
    public function getGroupRoutes(): void
    {
        Route::post('{submissionId}/decisions', $this->addDecision(...))
            ->name('submission.decision.add')->whereNumber('submissionId')
            ->middleware(self::roleAuthorizer([Role::ROLE_ID_ASSISTANT]));
    }

    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        if (static::getRouteActionName($args[0]) !== 'addDecision') {
            return false;
        }
        $this->addPolicy(new UserRequiredPolicy($request));
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        $this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments));
        $this->addPolicy(new SubmissionCompletePolicy($request, $args));
        $this->addPolicy(new DecisionTypeRequiredPolicy($request, $args, (int) $request->getUserVar('decision')));
        $this->addPolicy(new DecisionStageValidPolicy());
        $this->addPolicy(new RecommendationAccessPolicy($request, true));
        // Preserve global API checks without adding the native editor-only DecisionAllowedPolicy.
        return PKPBaseController::authorize($request, $args, $roleAssignments);
    }
}
