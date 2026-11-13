<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use APP\core\Application;
use PKP\query\QueryParticipant;

class DiscussionHooks
{
    public function validate(string $hookName, array $args): bool
    {
        $form = $args[0];
        $request = Application::get()->getRequest();
        $query = $form->getQuery();
        if (!$query || !$request->getUser() || !$request->getContext()) {
            return false;
        }
        $access = new Access();
        if (!$access->restrictions($request->getContext()->getId(), $request->getUser()->getId(), (int) $query->assocId, (int) $query->stageId)->blockContact) {
            return false;
        }
        $participants = $form->getData('users');
        if (!is_array($participants)) {
            $participants = QueryParticipant::withQueryId($query->id)->pluck('user_id')->all();
        }
        if (array_intersect($access->forbiddenParticipants((int) $query->assocId), array_map('intval', $participants))) {
            $form->addError('users', __('plugins.generic.editorialAdvisor.denied'));
            $args[1] = false;
            return true;
        }
        return false;
    }
}
