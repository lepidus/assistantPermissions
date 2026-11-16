<?php

namespace APP\plugins\generic\assistantPermissions\tests;

use APP\plugins\generic\assistantPermissions\classes\Restrictions;
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
        // Recipient-dependent restrictions are enforced by RequestPolicy.
        self::assertTrue($rules->allowsComponent('grid.users.stageParticipant.StageParticipantGridHandler', 'sendNotification'));
    }

    public function testFileDeletionAndFinalDecisionsAreDenied(): void
    {
        $rules = new Restrictions(true, true, true);
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
    public function testRestrictedFilesCanOnlyBeReadThroughComponentsAndApi(): void
    {
        $rules = new Restrictions(false, false, true);
        foreach (['editMetadata', 'editMetadataTab', 'saveMetadata', 'deleteFile'] as $op) {
            self::assertFalse($rules->allowsComponent('api.file.ManageFileApiHandler', $op), $op);
        }
        self::assertTrue($rules->allowsComponent('informationCenter.FileInformationCenterHandler', 'viewInformationCenter'));
        self::assertTrue($rules->allowsComponent('api.file.FileApiHandler', 'downloadFile'));
        self::assertFalse($rules->allowsApi('PUT', 'submissions/12/files/3'));
        self::assertFalse($rules->allowsApi('PATCH', 'submissions/12/files/3'));
        self::assertTrue($rules->allowsApi('GET', 'submissions/12/files/3'));
        self::assertTrue((new Restrictions(false, true))->allowsApi('PUT', 'submissions/12/files/3'));
    }

    public function testRestrictedFilesCannotBeUploadedSelectedOrCopied(): void
    {
        $rules = new Restrictions(false, false, true);
        foreach (['startWizard', 'displayFileUploadForm', 'uploadFile', 'finishFileSubmission'] as $op) {
            self::assertFalse($rules->allowsComponent('wizard.fileUpload.FileUploadWizardHandler', $op), $op);
        }
        self::assertFalse($rules->allowsComponent('grid.files.review.EditorReviewFilesGridHandler', 'selectFiles'));
        self::assertFalse($rules->allowsComponent('grid.files.review.ManageReviewFilesGridHandler', 'updateReviewFiles'));
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertFalse($rules->allowsApi($method, 'submissions/12/files'));
            self::assertFalse($rules->allowsApi($method, 'submissions/12/files/3/copy'));
        }
        self::assertTrue($rules->allowsApi('GET', 'submissions/12/files'));
        foreach (['downloadAllFiles', 'downloadLibraryFile', 'recordDownload', 'enableLinkAction'] as $op) {
            self::assertTrue($rules->allowsComponent('api.file.FileApiHandler', $op));
        }
        self::assertTrue((new Restrictions(false, true))->allowsComponent('wizard.fileUpload.FileUploadWizardHandler', 'uploadFile'));
        self::assertTrue((new Restrictions(false, true))->allowsApi('POST', 'submissions/12/files'));
    }

    public function testFileRestrictionIsIndependentOfRecommendationAndContact(): void
    {
        foreach ([false, true] as $recommend) {
            foreach ([false, true] as $contact) {
                foreach ([false, true] as $files) {
                    $rules = new Restrictions($recommend, $contact, $files);
                    self::assertSame(!$files, $rules->allowsComponent('wizard.fileUpload.FileUploadWizardHandler', 'uploadFile'));
                    self::assertSame(!$files, $rules->allowsComponent('api.file.ManageFileApiHandler', 'deleteFile'));
                    self::assertSame(!$files, $rules->allowsApi('POST', 'submissions/12/files'));
                    self::assertSame(!$files, $rules->allowsApi('DELETE', 'submissions/12/files/3'));
                    self::assertTrue($rules->allowsApi('GET', 'submissions/12/files'));
                    self::assertSame(!$recommend, $rules->allowsApi('POST', 'submissions/12/decisions'));
                }
            }
        }
    }

}
