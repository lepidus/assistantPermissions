<?php

namespace APP\plugins\generic\assistantPermissions\classes;

use PKP\controllers\grid\settings\roles\form\UserGroupForm;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

class AssistantPermissionsUserGroupForm extends UserGroupForm
{
    public function initData()
    {
        parent::initData();
        $group = $this->getUserGroupId() ? UserGroup::findById($this->getUserGroupId(), $this->getContextId()) : null;
        foreach ([Access::BLOCK_CONTACT, Access::RESTRICT_FILES] as $option) {
            $this->setData($option, (bool) $group?->{$option});
        }
    }

    public function readInputData()
    {
        parent::readInputData();
        $this->readUserVars([Access::BLOCK_CONTACT, Access::RESTRICT_FILES]);
    }

    /** Use the native role option as the default for new participant assignments. */
    public function getRecommendOnlyRoles(): array
    {
        return [...parent::getRecommendOnlyRoles(), Role::ROLE_ID_ASSISTANT];
    }

    /** This native preparation method runs before saving both new and existing groups. */
    public function _setUserGroupLocaleFields($userGroup, $request): UserGroup
    {
        $group = parent::_setUserGroupLocaleFields($userGroup, $request);
        foreach ([Access::BLOCK_CONTACT, Access::RESTRICT_FILES] as $option) {
            $group->{$option} = $group->roleId == Role::ROLE_ID_ASSISTANT && (bool) $this->getData($option);
        }

        return $group;
    }

}
