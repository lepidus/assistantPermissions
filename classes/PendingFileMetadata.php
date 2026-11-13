<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use Illuminate\Support\Facades\DB;

/** A server-created, atomically consumed permission for initial upload metadata. */
class PendingFileMetadata
{
    private const KEY = 'editorialAdvisorInitialMetadata';

    public function __construct(private string $sessionId, private int $userId)
    {
    }

    private function query(int $fileId): \Illuminate\Database\Query\Builder
    {
        return DB::table('submission_file_settings')->where([
            'submission_file_id' => $fileId,
            'locale' => '',
            'setting_name' => self::KEY,
            'setting_value' => hash('sha256', $this->sessionId . ':' . $this->userId),
        ]);
    }

    public function remember(int $fileId): void
    {
        DB::table('submission_file_settings')->insert([
            'submission_file_id' => $fileId, 'locale' => '', 'setting_name' => self::KEY,
            'setting_value' => hash('sha256', $this->sessionId . ':' . $this->userId),
        ]);
    }

    public function allows(string $component, string $operation, int $fileId): bool
    {
        return in_array(strtolower($component), [
            'wizard.fileupload.fileuploadwizardhandler', 'api.file.managefileapihandler',
        ], true)
            && in_array(strtolower($operation), ['editmetadata', 'editmetadatatab', 'savemetadata'], true)
            && $this->query($fileId)->exists();
    }

    public function consume(int $fileId): bool
    {
        return $this->query($fileId)->delete() === 1;
    }
}
