<?php

namespace APP\plugins\generic\editorialAdvisor\tests;

use APP\plugins\generic\editorialAdvisor\classes\Restrictions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/Restrictions.php';

class RestrictionsTest extends TestCase
{
    public function testRecommendationProfileAllowsReadingButNotReviewerManagement(): void
    {
        $rules = new Restrictions(true, false);
        self::assertTrue($rules->allowsComponent('grid.users.reviewer.ReviewerGridHandler', 'readReview'));
        self::assertTrue($rules->allowsComponent('grid.users.reviewer.ReviewerGridHandler', 'fetchGrid'));
        foreach (['updateReviewer', 'updateReview', 'unassignReviewer', 'reviewRead', 'unconsiderReview'] as $op) {
            self::assertFalse($rules->allowsComponent('grid.users.reviewer.ReviewerGridHandler', $op));
        }
    }

    public function testContactOptionDoesNotRestrictReadingOrUnrelatedOperations(): void
    {
        $rules = new Restrictions(false, true);
        self::assertTrue($rules->allowsComponent('grid.users.reviewer.ReviewerGridHandler', 'readReview'));
        self::assertFalse($rules->allowsComponent('grid.users.reviewer.ReviewerGridHandler', 'updateReview'));
        self::assertFalse($rules->allowsComponent('grid.users.reviewer.ReviewerGridHandler', 'updateUnassignReviewer'));
        self::assertFalse($rules->allowsComponent('grid.users.reviewer.ReviewerGridHandler', 'updateReinstateReviewer'));
        self::assertFalse($rules->allowsComponent('grid.users.reviewer.ReviewerGridHandler', 'sendEmail'));
        self::assertFalse($rules->allowsComponent('grid.users.stageParticipant.StageParticipantGridHandler', 'sendNotification'));
    }

    public function testFileDeletionAndFinalDecisionsAreDenied(): void
    {
        $rules = new Restrictions(true, true);
        self::assertFalse($rules->allowsComponent('grid.files.review.EditorReviewFilesGridHandler', 'deleteFile'));
        self::assertTrue($rules->allowsComponent('grid.files.review.EditorReviewFilesGridHandler', 'fetchGrid'));
        self::assertFalse($rules->allowsApi('DELETE', 'submissions/12/files/3'));
        self::assertFalse($rules->allowsApi('POST', 'submissions/12/decisions'));
        self::assertTrue($rules->allowsApi('GET', 'submissions/12'));
    }

    public function testUnrestrictedProfilePreservesCorePermissions(): void
    {
        $rules = new Restrictions(false, false);
        self::assertTrue($rules->allowsComponent('grid.users.reviewer.ReviewerGridHandler', 'sendEmail'));
        self::assertTrue($rules->allowsApi('DELETE', 'submissions/12/files/3'));
    }

    public function testNoContactApiCannotSendMessages(): void
    {
        $rules = new Restrictions(false, true);
        self::assertFalse($rules->allowsApi('POST', 'submissions/12/emails'));
        self::assertTrue($rules->allowsApi('GET', 'submissions/12/emails'));
    }
}
