<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use APP\core\Application;
use APP\submission\Submission;

/** Publish stage-specific restrictions and native recommendation permissions to the backend UI. */
class SubmissionPermissions
{
    public function __construct(private Access $access)
    {
    }

    public function map(array $output, Submission $submission): array
    {
        $request = Application::get()->getRequest();

        if (!$request->getContext() || !$request->getUser()) {
            return $output;
        }

        $access = $this->access;
        $contextId = $request->getContext()->getId();
        $userId = $request->getUser()->getId();
        $stageId = (int) $submission->getData('stageId');
        $rules = $access->restrictions($contextId, $userId, $submission->getId(), $stageId);
        $stages = $this->stagePermissions($access, $submission, $contextId, $userId);

        foreach ($output['stages'] ?? [] as $key => $stage) {
            $id = (int) $stage['id'];
            if ($stages[$id]['recommendOnly'] ?? false) {
                $output['stages'][$key]['currentUserCanRecommendOnly'] = $access->canRecommend(
                    $contextId,
                    $userId,
                    $submission->getId(),
                    $id
                );
            }
        }

        $output['editorialAdvisor'] = [
            'stages' => $stages,
            'recommendOnly' => $rules->recommendOnly,
            'blockContact' => $rules->blockContact,
            'restrictFiles' => $rules->restrictFiles,
            'notifyParticipantIds' => $stages[$stageId]['notifyParticipantIds'] ?? [],
        ];
        return $output;
    }

    private function stagePermissions(Access $access, Submission $submission, int $contextId, int $userId): array
    {
        $stages = [];

        foreach ([1, 2, 3, 4, 5] as $id) {
            $stageRules = $access->restrictions($contextId, $userId, $submission->getId(), $id);
            $stages[$id] = [
                'recommendOnly' => $stageRules->recommendOnly, 'blockContact' => $stageRules->blockContact,
                'restrictFiles' => $stageRules->restrictFiles,
                'notifyParticipantIds' => $stageRules->blockContact ? $access->notificationRecipients($submission->getId(), $id) : [],
            ];
        }

        return $stages;
    }

}
