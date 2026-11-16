<?php

namespace APP\plugins\generic\assistantPermissions\classes;

use PKP\controllers\grid\settings\roles\UserGroupGridHandler;
use PKP\core\JSONMessage;
use PKP\db\DAO;

/** Keep the core settings authorization; replace only its private form factory. */
class AssistantPermissionsUserGroupGridHandler extends UserGroupGridHandler
{
    private function assistantPermissionsForm($request): AssistantPermissionsUserGroupForm
    {
        $id = (int) $request->getUserVar('userGroupId');
        return new AssistantPermissionsUserGroupForm($request->getContext()->getId(), $id);
    }

    public function editUserGroup($args, $request)
    {
        $form = $this->assistantPermissionsForm($request);
        $form->initData();
        return new JSONMessage(true, $form->fetch($request));
    }

    public function updateUserGroup($args, $request)
    {
        $form = $this->assistantPermissionsForm($request);
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
