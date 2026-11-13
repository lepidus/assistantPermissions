<?php

namespace APP\plugins\generic\editorialAdvisor\classes;

use PKP\controllers\grid\settings\roles\form\UserGroupForm;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\userGroup\UserGroup;

class AdvisorUserGroupForm extends UserGroupForm
{
    public function initData()
    {
        parent::initData();
        $group = $this->getUserGroupId() ? UserGroup::findById($this->getUserGroupId(), $this->getContextId()) : null;
        $this->setData(Access::BLOCK_CONTACT, (bool) $group?->{Access::BLOCK_CONTACT});
    }

    public function readInputData()
    {
        parent::readInputData();
        $this->readUserVars([Access::BLOCK_CONTACT]);
    }

    public function getRecommendOnlyRoles(): array
    {
        return [...parent::getRecommendOnlyRoles(), Role::ROLE_ID_ASSISTANT];
    }

    /** The core invokes this after saving both new and existing groups, with their definitive ID. */
    public function _assignStagesToUserGroup($userGroupId, $userAssignedStages)
    {
        parent::_assignStagesToUserGroup($userGroupId, $userAssignedStages);
        $group = UserGroup::findById($userGroupId, $this->getContextId());
        $group->{Access::BLOCK_CONTACT} = $group->roleId == Role::ROLE_ID_ASSISTANT
            && (bool) $this->getData(Access::BLOCK_CONTACT);
        $group->save();
        if ($group->roleId == Role::ROLE_ID_ASSISTANT) {
            StageAssignment::withUserGroupId($group->id)->withContextId($this->getContextId())
                ->update(['recommend_only' => (bool) $group->recommendOnly]);
        }
    }
}
