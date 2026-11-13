<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use PKP\controllers\grid\settings\roles\UserGroupGridHandler;
use PKP\core\JSONMessage;
use PKP\db\DAO;

/** Keep the core settings authorization; replace only its private form factory. */
class AdvisorUserGroupGridHandler extends UserGroupGridHandler
{
    private function advisorForm($request): AdvisorUserGroupForm
    {
        $id = (int) $request->getUserVar('userGroupId');
        return new AdvisorUserGroupForm($request->getContext()->getId(), $id);
    }

    public function editUserGroup($args, $request)
    {
        $form = $this->advisorForm($request);
        $form->initData();
        return new JSONMessage(true, $form->fetch($request));
    }

    public function updateUserGroup($args, $request)
    {
        $form = $this->advisorForm($request);
        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }
        $form->execute();
        $json = DAO::getDataChangedEvent();
        $json->setGlobalEvent('userGroupUpdated');
        return $json;
    }
}
