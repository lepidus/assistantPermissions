<?php

namespace APP\plugins\generic\assistantPermissions\classes;

use Illuminate\Support\Facades\DB;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;

/** Resolve canonical submissions and stages from legacy component and API resources. */
class RequestTargets
{
    public function __construct(
        private PKPRequest $request,
        private ?PKPBaseController $controller = null,
        private string $component = ''
    ) {
    }

    public function parameter(string $name): mixed
    {
        if ($this->controller && in_array($name, ['fileStage', 'toFileStage', 'assocType', 'assocId'], true)) {
            // Match the core write path: the request body takes precedence over the query string.
            return app(\Illuminate\Http\Request::class)->input($name);
        }

        return $this->controller
            ? $this->controller->getParameter($name, $this->request->getUserVar($name))
            : $this->request->getUserVar($name);
    }

    /** Discussions reached through notes and attachments are targets too, not just the submitted queryId. */
    public function queryIds(): array
    {
        $ids = [(int) $this->parameter('queryId')];
        $noteIds = [(int) $this->parameter('noteId')];
        if ((int) $this->parameter('assocType') === \APP\core\Application::ASSOC_TYPE_NOTE) {
            $noteIds[] = (int) $this->parameter('assocId');
        }
        foreach (['submissionFileId', 'revisedFileId'] as $parameter) {
            $file = ($id = (int) $this->parameter($parameter)) ? \APP\facades\Repo::submissionFile()->get($id) : null;
            if ($file && (int) $file->getData('assocType') === \APP\core\Application::ASSOC_TYPE_NOTE) {
                $noteIds[] = (int) $file->getData('assocId');
            }
        }
        foreach (array_filter(array_unique($noteIds)) as $noteId) {
            $note = \PKP\note\Note::find($noteId);
            if ($note && (int) $note->assocType === \APP\core\Application::ASSOC_TYPE_QUERY) {
                $ids[] = (int) $note->assocId;
            }
        }
        return array_values(array_filter(array_unique($ids)));
    }

    /** Object stages take precedence over user-supplied IDs and stages. */
    public function resolve(): array
    {
        $targets = [];
        if (!$this->controller && (str_contains(strtolower($this->component), 'submissiondocuments')
            || str_contains(strtolower($this->component), 'libraryfile'))) {
            // Library documents belong to a submission, not to the caller's arbitrary workflow stage.
            $fileId = (int) $this->parameter('fileId');
            if ($fileId) {
                $dao = \PKP\db\DAORegistry::getDAO('LibraryFileDAO');
                $file = $dao->getById($fileId);
                if ($file && $file->getSubmissionId()) {
                    $targets[] = [(int) $file->getSubmissionId(), null];
                }
            }
            if (str_contains(strtolower($this->component), 'submissiondocuments')
                && ($id = (int) $this->parameter('submissionId'))) {
                $targets[] = [$id, null];
            }
            return $targets;
        }

        $apiPath = $this->controller?->getHandlerPath();
        if ($this->controller && $apiPath !== 'reviews' && $apiPath !== 'submissions/{submissionId}/files') {
            // Ignore arbitrary body/query IDs on non-resource APIs, including bulk email.
            $id = (int) $this->parameter('submissionId');
            return $apiPath === 'submissions' && $id ? [[$id, null]] : [];
        }

        if (!$this->controller || $apiPath === 'submissions/{submissionId}/files') {
            foreach ($this->queryIds() as $queryId) {
                $query = \PKP\query\Query::find($queryId);
                if ($query && (int) $query->assocType === \APP\core\Application::ASSOC_TYPE_SUBMISSION) {
                    $targets[] = [(int) $query->assocId, (int) $query->stageId];
                }
            }
        }

        foreach ([
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
                $targets[] = $this->fileTarget($file);
            }
        }

        if ((int) $this->parameter('assocType') === \APP\core\Application::ASSOC_TYPE_REVIEW_ASSIGNMENT
            && ($id = (int) $this->parameter('assocId'))) {
            $review = DB::table('review_assignments')->where('review_id', $id)->first();
            if ($review) {
                $targets[] = [(int) $review->submission_id, (int) $review->stage_id];
            }
        }

        if ((int) $this->parameter('assocType') === \APP\core\Application::ASSOC_TYPE_REVIEW_ROUND
            && ($roundId = (int) $this->parameter('assocId'))) {
            $round = DB::table('review_rounds')->where('review_round_id', $roundId)->first();
            if ($round) {
                $targets[] = [(int) $round->submission_id, (int) $round->stage_id];
            }
        }

        if ((int) $this->parameter('assocType') === \APP\core\Application::ASSOC_TYPE_SUBMISSION_FILE
            && ($parentId = (int) $this->parameter('assocId'))) {
            $parent = \APP\facades\Repo::submissionFile()->get($parentId);
            if ($parent) {
                $targets[] = $this->fileTarget($parent);
            }
        }

        $submissionId = (int) $this->parameter('submissionId');
        foreach (['fileStage', 'toFileStage'] as $destination) {
            if (!$submissionId || !($fileStage = (int) $this->parameter($destination))) {
                continue;
            }
            $file = \PKP\submissionFile\SubmissionFile::class;
            $destinationStage = match ($fileStage) {
                $file::SUBMISSION_FILE_SUBMISSION => WORKFLOW_STAGE_ID_SUBMISSION,
                $file::SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
                $file::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION => WORKFLOW_STAGE_ID_INTERNAL_REVIEW,
                $file::SUBMISSION_FILE_REVIEW_FILE, $file::SUBMISSION_FILE_REVIEW_REVISION,
                $file::SUBMISSION_FILE_ATTACHMENT => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
                $file::SUBMISSION_FILE_FINAL, $file::SUBMISSION_FILE_COPYEDIT => WORKFLOW_STAGE_ID_EDITING,
                $file::SUBMISSION_FILE_PROOF, $file::SUBMISSION_FILE_PRODUCTION_READY,
                $file::SUBMISSION_FILE_JATS => WORKFLOW_STAGE_ID_PRODUCTION,
                default => null,
            };
            if ($destinationStage !== null) {
                $targets[] = [$submissionId, $destinationStage];
            }
        }
        if ($submissionId && ((!$this->controller && (int) $this->parameter('stageId'))
            || !in_array($submissionId, array_column($targets, 0), true))) {
            // API decisions act on the current stage; component stages are also checked by the core.
            $stageId = $this->controller ? null : ((int) $this->parameter('stageId') ?: null);
            $targets[] = [$submissionId, $stageId];
        }

        return $targets;
    }
    /** Match reviewer attachments and dependent files to their actual workflow destination. */
    private function fileTarget(\PKP\submissionFile\SubmissionFile $file, array $visited = []): array
    {
        $submissionId = (int) $file->getData('submissionId');
        if (in_array($file->getId(), $visited, true)) {
            return [$submissionId, null];
        }
        $visited[] = $file->getId();
        if ((int) $file->getData('fileStage') === \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_DEPENDENT) {
            $parent = \APP\facades\Repo::submissionFile()->get((int) $file->getData('assocId'));
            return $parent ? $this->fileTarget($parent, $visited) : [$submissionId, null];
        }
        $stageId = (int) $file->getData('assocType') === \APP\core\Application::ASSOC_TYPE_REVIEW_ASSIGNMENT
            ? DB::table('review_assignments')->where('review_id', $file->getData('assocId'))->value('stage_id')
            : \APP\facades\Repo::submissionFile()->getWorkflowStageId($file);
        return [$submissionId, $stageId === null ? null : (int) $stageId];
    }

}
