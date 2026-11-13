<?php

namespace APP\plugins\generic\editorialAdvisor\tests;

use APP\plugins\generic\editorialAdvisor\classes\PendingFileMetadata;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

class PendingFileMetadataTest extends TestCase
{
    public function testOnlyNewUploadMetadataCanBeConsumedOnceByItsSessionAndUser(): void
    {
        DB::beginTransaction();
        try {
            $fileId = (int) DB::table('submission_files')->value('submission_file_id');
            $pending = new PendingFileMetadata('test-session', 39);
            $component = 'api.file.ManageFileApiHandler';
            self::assertFalse($pending->allows($component, 'saveMetadata', $fileId));
            $pending->remember($fileId);
            self::assertFalse((new PendingFileMetadata('other-session', 39))->consume($fileId));
            self::assertFalse((new PendingFileMetadata('test-session', 40))->consume($fileId));
            self::assertFalse($pending->allows($component, 'deleteFile', $fileId));
            self::assertTrue($pending->allows($component, 'saveMetadata', $fileId));
            self::assertTrue($pending->allows('wizard.fileUpload.FileUploadWizardHandler', 'editMetadata', $fileId));
            self::assertTrue($pending->consume($fileId));
            self::assertFalse($pending->consume($fileId));
            self::assertFalse($pending->allows($component, 'saveMetadata', $fileId));
        } finally {
            DB::rollBack();
        }
    }
}
