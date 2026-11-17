<?php

namespace APP\plugins\generic\assistantPermissions\tests;

use APP\core\Request;
use APP\facades\Repo;
use APP\plugins\generic\assistantPermissions\AssistantPermissionsPlugin;
use APP\plugins\generic\assistantPermissions\classes\Access;
use APP\plugins\generic\assistantPermissions\classes\AssistantPermissionsUserGroupForm;
use APP\plugins\generic\assistantPermissions\classes\RequestPolicy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use PKP\core\Registry;
use PKP\plugins\Hook;
use PKP\security\authorization\AuthorizationDecisionManager;
use PKP\security\authorization\AuthorizationPolicy;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

/** Real OMP models/SQL, isolated with a rollback; no database restoration or real emails. */
class AccessIntegrationTest extends TestCase
{
    private int $contextId;
    private int $userId;
    private int $submissionId;
    private UserGroup $group;
    private mixed $previousRequest;
    private array $previousHooks;
    private mixed $previousTemplateManager;

    protected function setUp(): void
    {
        DB::beginTransaction();
        $this->previousHooks = Hook::getHooks();
        $this->previousRequest = Registry::get('request');
        $this->previousTemplateManager = Registry::get('templateManager');
        $empty = null;
        Registry::set('templateManager', $empty);
        $this->contextId = (int) DB::table('presses')->value('press_id');
        $this->userId = (int) DB::table('users')->value('user_id');
        $fixtureRequest = $this->request();
        Registry::set('request', $fixtureRequest);
        $plugin = $this->getMockBuilder(AssistantPermissionsPlugin::class)->onlyMethods(['getEnabled'])->getMock();
        $plugin->method('getEnabled')->willReturn(true);
        $plugin->register('generic', 'plugins/generic/assistantPermissions', $this->contextId);
        app('schema')->get('userGroup', true);
        $this->submissionId = DB::table('submissions')->insertGetId([
            'context_id' => $this->contextId, 'stage_id' => 3, 'status' => 1, 'locale' => 'en', 'submission_progress' => '',
        ]);
        $this->group = new UserGroup();
        $this->group->fill([
            'contextId' => $this->contextId, 'roleId' => Role::ROLE_ID_ASSISTANT,
            'name' => ['en' => 'Arbitrary counsel role'], 'abbrev' => ['en' => 'AC'],
            'recommendOnly' => false, Access::BLOCK_CONTACT => true, Access::RESTRICT_FILES => true,
        ]);
        $this->group->save();
        DB::table('user_user_groups')->insert([
            'user_group_id' => $this->group->id, 'user_id' => $this->userId,
        ]);
        DB::table('user_group_stage')->insert([
            'context_id' => $this->contextId, 'user_group_id' => $this->group->id, 'stage_id' => 3,
        ]);
        DB::table('stage_assignments')->insert([
            'submission_id' => $this->submissionId, 'user_group_id' => $this->group->id,
            'user_id' => $this->userId, 'date_assigned' => date('Y-m-d H:i:s'), 'recommend_only' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        Registry::set('request', $this->previousRequest);
        Registry::set('templateManager', $this->previousTemplateManager);
        $hooks = &Hook::getHooks();
        $hooks = $this->previousHooks;
        app('schema')->get('userGroup', true);
        parent::tearDown();
    }

    private function request(array $params = [], ?int $actorId = null): Request
    {
        $request = $this->createPartialMock(Request::class, ['getContext', 'getUser', 'getUserVar', 'getSite', 'getRouter']);
        $context = app('context')->get($this->contextId);
        $router = $this->createPartialMock(\PKP\core\PKPPageRouter::class, ['getContext', 'getRequestedArgs']);
        $router->method('getContext')->willReturn($context);
        $router->method('getRequestedArgs')->willReturn([$this->submissionId ?? 0]);
        $request->method('getRouter')->willReturn($router);
        $site = new \PKP\site\Site();
        $site->setData('primaryLocale', 'en');
        $site->setData('supportedLocales', ['en']);
        $request->method('getSite')->willReturn($site);
        $request->method('getContext')->willReturn($context);
        $request->method('getUser')->willReturn(Repo::user()->get($actorId ?? $this->userId));
        $request->method('getUserVar')->willReturnCallback(fn ($key) => $params[$key] ?? null);
        return $request;
    }

    public function testPersistsOptionsAndUsesAssignmentAndRoleLevelRatherThanName(): void
    {
        $rules = (new Access())->restrictions($this->contextId, $this->userId, $this->submissionId);
        self::assertTrue($rules->recommendOnly);
        self::assertTrue($rules->blockContact);
        self::assertTrue((new Access())->canRecommend($this->contextId, $this->userId, $this->submissionId, 3));
        self::assertFalse((new Access())->canRecommend($this->contextId, $this->userId, $this->submissionId, 2));
        self::assertFalse((new Access())->restrictions($this->contextId, $this->userId, $this->submissionId, 2)->recommendOnly);
        self::assertFalse((new Access())->canRecommend($this->contextId + 1000, $this->userId, $this->submissionId, 3));
        self::assertFalse((new Access())->restrictions($this->contextId, $this->userId, $this->submissionId + 100000)->recommendOnly);
    }

    public function testRecommendationFollowsEachSubmissionAssignmentRatherThanTheRole(): void
    {
        $access = new Access();
        self::assertTrue($access->canRecommend($this->contextId, $this->userId, $this->submissionId, 3));
        self::assertFalse($access->restrictions($this->contextId, $this->userId)->recommendOnly);
        $otherSubmission = DB::table('submissions')->insertGetId([
            'context_id' => $this->contextId, 'stage_id' => 3, 'status' => 1, 'locale' => 'en', 'submission_progress' => '',
        ]);
        DB::table('stage_assignments')->insert([
            'submission_id' => $otherSubmission, 'user_group_id' => $this->group->id, 'user_id' => $this->userId,
            'date_assigned' => date('Y-m-d H:i:s'), 'recommend_only' => false,
        ]);
        self::assertFalse((new Access())->restrictions($this->contextId, $this->userId, $otherSubmission, 3)->recommendOnly);
        self::assertFalse((new Access())->canRecommend($this->contextId, $this->userId, $otherSubmission, 3));

        $this->group->recommendOnly = true;
        $this->group->save();
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->update(['recommend_only' => false]);
        self::assertFalse((new Access())->restrictions($this->contextId, $this->userId, $this->submissionId, 3)->recommendOnly);
        self::assertFalse((new Access())->canRecommend($this->contextId, $this->userId, $this->submissionId, 3));
    }

    public function testExpiredMembershipDoesNotGrantRecommendation(): void
    {
        DB::table('user_user_groups')->where('user_group_id', $this->group->id)->update(['date_end' => '2000-01-01']);
        self::assertFalse((new Access())->canRecommend($this->contextId, $this->userId, $this->submissionId, 3));
    }

    public function testRestrictionsFollowAssignmentsTheCoreStillHonours(): void
    {
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->update(['recommend_only' => false]);
        DB::table('user_user_groups')->where('user_group_id', $this->group->id)->update(['date_end' => '2000-01-01']);
        $otherGroup = new UserGroup();
        $otherGroup->fill([
            'contextId' => $this->contextId, 'roleId' => Role::ROLE_ID_ASSISTANT,
            'name' => ['en' => 'Unrestricted assistant role'], 'abbrev' => ['en' => 'UA'], 'recommendOnly' => false,
        ]);
        $otherGroup->save();
        DB::table('user_user_groups')->insert(['user_group_id' => $otherGroup->id, 'user_id' => $this->userId]);
        DB::table('user_group_stage')->insert([
            'context_id' => $this->contextId, 'user_group_id' => $otherGroup->id, 'stage_id' => 3,
        ]);
        // The core grants the stage while the user holds the Assistant role in any active group.
        $accessible = Repo::user()->getAccessibleWorkflowStages($this->userId, $this->contextId, Repo::submission()->get($this->submissionId));
        self::assertContains(Role::ROLE_ID_ASSISTANT, $accessible[3] ?? []);
        $rules = (new Access())->restrictions($this->contextId, $this->userId, $this->submissionId, 3);
        self::assertTrue($rules->blockContact);
        self::assertTrue($rules->restrictFiles);
        self::assertTrue((new Access())->restrictions($this->contextId, $this->userId)->blockContact);

        DB::table('user_user_groups')->where('user_group_id', $otherGroup->id)->update(['date_end' => '2000-01-01']);
        $accessible = Repo::user()->getAccessibleWorkflowStages($this->userId, $this->contextId, Repo::submission()->get($this->submissionId));
        self::assertNotContains(Role::ROLE_ID_ASSISTANT, $accessible[3] ?? []);
        $rules = (new Access())->restrictions($this->contextId, $this->userId, $this->submissionId, 3);
        self::assertFalse($rules->blockContact);
        self::assertFalse($rules->restrictFiles);
    }

    public function testContributorAccountsMatchEmailsRegardlessOfLetterCase(): void
    {
        $contributor = DB::table('users')->where('user_id', '<>', $this->userId)->first(['user_id', 'email']);
        $publicationId = DB::table('publications')->insertGetId([
            'submission_id' => $this->submissionId, 'status' => 1, 'version' => 1,
        ]);
        DB::table('authors')->insert([
            'publication_id' => $publicationId, 'email' => strtoupper($contributor->email),
            'include_in_browse' => 1, 'seq' => 0,
        ]);
        self::assertContains((int) $contributor->user_id, (new Access())->forbiddenParticipants($this->submissionId));
    }

    public function testSpoofedSubmissionCannotBypassQueryContactRestriction(): void
    {
        $queryId = DB::table('queries')->insertGetId([
            'assoc_type' => \APP\core\Application::ASSOC_TYPE_SUBMISSION,
            'assoc_id' => $this->submissionId, 'stage_id' => 3, 'seq' => 1,
        ]);
        $otherUserId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
        $authorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        DB::table('stage_assignments')->insert([
            'submission_id' => $this->submissionId, 'user_group_id' => $authorGroup->id,
            'user_id' => $otherUserId, 'date_assigned' => date('Y-m-d H:i:s'),
        ]);
        DB::table('query_participants')->insert(['query_id' => $queryId, 'user_id' => $otherUserId]);
        $policy = new RequestPolicy(
            $this->request(['submissionId' => $this->submissionId + 100000, 'queryId' => $queryId]),
            'grid.queries.QueryNotesGridHandler',
            'insertNote'
        );
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
        $deletePolicy = new RequestPolicy(
            $this->request(['queryId' => $queryId]),
            'grid.queries.QueryNotesGridHandler',
            'deleteNote'
        );
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $deletePolicy->effect());
    }

    public function testRequestsOfUsersWithoutAssistantGroupsNeedOnlyTheGroupLookup(): void
    {
        $roundId = DB::table('review_rounds')->insertGetId([
            'submission_id' => $this->submissionId, 'stage_id' => 3, 'round' => 1,
        ]);
        $otherUserId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
        $request = $this->request(['submissionId' => $this->submissionId, 'stageId' => 3, 'reviewRoundId' => $roundId], $otherUserId);
        $policy = new RequestPolicy($request, 'grid.users.reviewer.ReviewerGridHandler', 'updateReviewer');
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, $policy->effect());
            self::assertCount(1, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy(
            $this->request(['submissionId' => $this->submissionId, 'stageId' => 3, 'reviewRoundId' => $roundId]),
            'grid.users.reviewer.ReviewerGridHandler',
            'updateReviewer'
        ))->effect());
    }

    public function testComponentPolicyBlocksManagementWithoutGrantingCoreAccess(): void
    {
        $request = $this->request(['submissionId' => $this->submissionId]);
        self::assertSame(
            AuthorizationPolicy::AUTHORIZATION_DENY,
            (new RequestPolicy($request, 'grid.users.reviewer.ReviewerGridHandler', 'updateReviewer'))->effect()
        );
        self::assertSame(
            AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE,
            (new RequestPolicy($request, 'grid.users.reviewer.ReviewerGridHandler', 'readReview'))->effect()
        );
    }

    public function testRecommendationDefaultIsAvailableInAssistantRoleSettings(): void
    {
        $form = new AssistantPermissionsUserGroupForm($this->contextId, 0);
        self::assertContains(Role::ROLE_ID_ASSISTANT, $form->getRecommendOnlyRoles());
    }
    public function testRoleFormCreatesAssistantWithBothOptionsAndRendersThem(): void
    {
        $form = new AssistantPermissionsUserGroupForm($this->contextId, 0);
        $form->initData();
        foreach ([
            'roleId' => Role::ROLE_ID_ASSISTANT, 'name' => ['en' => 'Counsel with custom name'],
            'abbrev' => ['en' => 'CC'], 'assignedStages' => [3], 'recommendOnly' => true,
            Access::BLOCK_CONTACT => true, Access::RESTRICT_FILES => true,
        ] as $key => $value) {
            $form->setData($key, $value);
        }
        $form->execute();
        $group = UserGroup::withContextIds([$this->contextId])->orderByDesc('user_group_id')->first();
        self::assertSame(Role::ROLE_ID_ASSISTANT, $group->roleId);
        self::assertTrue($group->recommendOnly);
        self::assertTrue($group->{Access::BLOCK_CONTACT});
        self::assertTrue($group->{Access::RESTRICT_FILES});
        $edit = new AssistantPermissionsUserGroupForm($this->contextId, $group->id);
        $edit->initData();
        \APP\template\TemplateManager::getManager(Registry::get('request'))->assign([
            'hasSystemNotifications' => false, 'requestOptions' => '{}',
            'notificationStyleClass' => '', 'FBV_sizeInfo' => '', 'refreshOn' => '',
        ]);
        Registry::get('request')->getSession()->regenerateToken();
        $html = $edit->fetch(Registry::get('request'));
        self::assertStringContainsString('name="assistantPermissionsBlockContact"', $html);
        self::assertStringContainsString('name="assistantPermissionsRestrictFiles"', $html);
        self::assertStringContainsString('name="recommendOnly"', $html);
    }

    public function testNativeDecisionPageIsExtendedWithoutReplacingItsRecordFlow(): void
    {
        $request = $this->request();
        Registry::set('request', $request);
        $page = 'decision';
        $op = 'record';
        $source = '';
        $handler = null;
        Hook::call('LoadHandler', [&$page, &$op, &$source, &$handler]);
        self::assertInstanceOf(\PKP\pages\decision\DecisionHandler::class, $handler);
        $method = new \ReflectionMethod($handler, 'record');
        self::assertSame(\PKP\pages\decision\DecisionHandler::class, $method->getDeclaringClass()->getName());
    }

    public function testApiRestrictionsAreRegisteredEvenWhenTheRecommendationControllerCannotBe(): void
    {
        $previousPathInfo = $_SERVER['PATH_INFO'] ?? null;
        $previousHttp = app(\Illuminate\Http\Request::class);
        $path = '/press/api/v1/submissions/' . $this->submissionId . '/decisions';
        $_SERVER['PATH_INFO'] = $path;
        $request = $this->createPartialMock(Request::class, ['getContext', 'getUser', 'getRequestPath']);
        $request->method('getContext')->willReturn(app('context')->get($this->contextId));
        $request->method('getUser')->willReturn(Repo::user()->get($this->userId));
        $request->method('getRequestPath')->willReturn('/index.php' . $path);
        Registry::set('request', $request);
        app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create($path, 'POST'));
        try {
            $router = new \PKP\core\APIRouter();
            // Another plugin already serves the submissions handler path.
            $router->registerPluginApiControllers([
                $this->getMockBuilder(\APP\API\v1\submissions\SubmissionController::class)->onlyMethods([])->getMock(),
            ]);
            Hook::run('APIHandler::endpoints::plugin', [$router]);
            self::assertArrayHasKey('APIHandler::endpoints::submissions', Hook::getHooks());
        } finally {
            $previousPathInfo === null ? $_SERVER['PATH_INFO'] = null : $_SERVER['PATH_INFO'] = $previousPathInfo;
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
    }

    public function testNewAssignmentsPreserveTheSelectedRecommendationPermission(): void
    {
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->delete();
        $assignment = Repo::stageAssignment()->build($this->submissionId, $this->group->id, $this->userId, false);
        self::assertFalse((bool) $assignment->recommendOnly);
        $assignment->recommendOnly = true;
        $assignment->save();
        self::assertTrue((bool) $assignment->fresh()->recommendOnly);
        $assignment->recommendOnly = false;
        $assignment->save();
        self::assertFalse((bool) $assignment->fresh()->recommendOnly);
    }

    public function testNativeParticipantFormCreatesAndEditsRecommendationPermission(): void
    {
        $actorId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
        $manager = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_MANAGER])->first();
        DB::table('user_user_groups')->updateOrInsert(
            ['user_group_id' => $manager->id, 'user_id' => $actorId],
            ['date_end' => null]
        );
        $actorRequest = $this->request([], $actorId);
        Registry::set('request', $actorRequest);
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->delete();
        $submission = Repo::submission()->get($this->submissionId);
        $form = new \PKP\controllers\grid\users\stageParticipant\form\AddParticipantForm($submission, 3);
        foreach (['userGroupId' => $this->group->id, 'userId' => $this->userId, 'recommendOnly' => true] as $key => $value) {
            $form->setData($key, $value);
        }
        $templateManager = \APP\template\TemplateManager::getManager($actorRequest);
        $templateManager->assign([
            'stageId' => 3, 'assignmentId' => null,
            'possibleRecommendOnlyUserGroupIds' => [], 'recommendOnlyUserGroupIds' => [$this->group->id],
        ]);
        $template = 'controllers/grid/users/stageParticipant/addParticipantForm.tpl';
        $templateArgs = [$templateManager, $template, null, null, null];
        Hook::call('TemplateManager::fetch', $templateArgs);
        self::assertContains($this->group->id, $templateManager->getTemplateVars('possibleRecommendOnlyUserGroupIds'));
        self::assertContains($this->group->id, $templateManager->getTemplateVars('recommendOnlyUserGroupIds'));
        $result = null;
        Hook::call('addparticipantform::validate', [$form, &$result]);
        self::assertTrue($form->isValid());
        [, , $assignmentId] = $form->execute();
        self::assertTrue((bool) \PKP\stageAssignment\StageAssignment::find($assignmentId)->recommendOnly);
        $templateManager->assign('assignmentId', $assignmentId);
        Hook::call('TemplateManager::fetch', $templateArgs);
        self::assertTrue($templateManager->getTemplateVars('isChangeRecommendOnlyAllowed'));
        foreach ([false, true] as $selected) {
            $edit = new \PKP\controllers\grid\users\stageParticipant\form\AddParticipantForm($submission, 3, $assignmentId);
            foreach (['userGroupId' => $this->group->id, 'userId' => $this->userId, 'recommendOnly' => $selected] as $key => $value) {
                $edit->setData($key, $value);
            }
            Hook::call('addparticipantform::validate', [$edit, &$result]);
            self::assertTrue($edit->isValid());
            $edit->execute();
            self::assertSame($selected, (bool) \PKP\stageAssignment\StageAssignment::find($assignmentId)->recommendOnly);
        }
        $roleForm = new AssistantPermissionsUserGroupForm($this->contextId, $this->group->id);
        $roleForm->initData();
        $roleForm->setData('recommendOnly', false);
        $roleForm->setData('assignedStages', [3]);
        $roleForm->execute();
        self::assertTrue((bool) \PKP\stageAssignment\StageAssignment::find($assignmentId)->recommendOnly);
    }

    public function testNativeParticipantFormRejectsSelfChangesAndSpoofedAssignments(): void
    {
        $submission = Repo::submission()->get($this->submissionId);
        $assignmentId = DB::table('stage_assignments')->where('submission_id', $this->submissionId)->value('stage_assignment_id');
        $form = new \PKP\controllers\grid\users\stageParticipant\form\AddParticipantForm($submission, 3, $assignmentId);
        $form->setData('userGroupId', $this->group->id);
        $form->setData('userId', $this->userId);
        $form->setData('recommendOnly', false);
        $result = null;
        Hook::call('addparticipantform::validate', [$form, &$result]);
        self::assertFalse($form->isValid());
        self::assertTrue((bool) \PKP\stageAssignment\StageAssignment::find($assignmentId)->recommendOnly);
        $actorId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
        $manager = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_MANAGER])->first();
        DB::table('user_user_groups')->updateOrInsert(
            ['user_group_id' => $manager->id, 'user_id' => $actorId],
            ['date_end' => null]
        );
        $actorRequest = $this->request([], $actorId);
        Registry::set('request', $actorRequest);
        $form = new \PKP\controllers\grid\users\stageParticipant\form\AddParticipantForm($submission, 3, $assignmentId);
        $form->setData('userGroupId', $this->group->id);
        $form->setData('userId', $this->userId + 100000);
        Hook::call('addparticipantform::validate', [$form, &$result]);
        self::assertFalse($form->isValid());
    }

    public function testParticipantEditCannotHideAnAssistantAssignmentBehindAnotherRole(): void
    {
        $assignmentId = DB::table('stage_assignments')->where('submission_id', $this->submissionId)->value('stage_assignment_id');
        $manager = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_MANAGER])->first();
        DB::table('user_user_groups')->updateOrInsert(
            ['user_group_id' => $manager->id, 'user_id' => $this->userId],
            ['date_end' => null]
        );
        $otherSubmissionId = DB::table('submissions')->insertGetId([
            'context_id' => $this->contextId, 'stage_id' => 3, 'status' => 1, 'locale' => 'en', 'submission_progress' => '',
        ]);
        $otherSubmission = Repo::submission()->get($otherSubmissionId);
        $form = new \PKP\controllers\grid\users\stageParticipant\form\AddParticipantForm($otherSubmission, 3, $assignmentId);
        $form->setData('userGroupId', $manager->id);
        $form->setData('userId', $this->userId);
        $form->setData('recommendOnly', false);
        $result = null;
        Hook::call('addparticipantform::validate', [$form, &$result]);
        self::assertFalse($form->isValid());
        self::assertArrayHasKey('recommendOnly', $form->getErrorsArray());
        self::assertTrue((bool) \PKP\stageAssignment\StageAssignment::find($assignmentId)->recommendOnly);
    }

    public function testDiscussionFormRejectsAnAuthorAsRecipient(): void
    {
        $otherUserId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
        $authorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        DB::table('stage_assignments')->insert([
            'submission_id' => $this->submissionId, 'user_group_id' => $authorGroup->id,
            'user_id' => $otherUserId, 'date_assigned' => date('Y-m-d H:i:s'),
        ]);
        $form = new \PKP\controllers\grid\queries\form\QueryForm(
            Registry::get('request'),
            \APP\core\Application::ASSOC_TYPE_SUBMISSION,
            $this->submissionId,
            3
        );
        $form->setData('users', [$this->userId, $otherUserId]);
        $args = [$form, null];
        (new \APP\plugins\generic\assistantPermissions\classes\DiscussionHooks())->validate('queryform::validate', $args);
        self::assertFalse($form->isValid());
        self::assertSame(__('plugins.generic.assistantPermissions.denied'), $form->getErrorsArray()['users']);
    }

    public function testReviewerAttachmentGridIsReadOnly(): void
    {
        $request = $this->request(['submissionId' => $this->submissionId, 'stageId' => 3]);
        Registry::set('request', $request);
        $component = 'grid.files.attachment.EditorReviewAttachmentsGridHandler';
        $operation = 'fetchGrid';
        $handler = null;
        Hook::call('LoadComponentHandler', [&$component, &$operation, &$handler]);
        self::assertFalse($handler->getCapabilities()->canAdd());
        self::assertFalse($handler->getCapabilities()->canEdit());
        self::assertFalse($handler->getCapabilities()->canDelete());
    }

    public function testExistingAuthorDiscussionRendersAsReadOnly(): void
    {
        $query = \PKP\query\Query::create([
            'assocType' => \APP\core\Application::ASSOC_TYPE_SUBMISSION,
            'assocId' => $this->submissionId, 'stageId' => 3, 'seq' => 1,
        ]);
        $authorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        $authorId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
        DB::table('stage_assignments')->insert([
            'submission_id' => $this->submissionId, 'user_group_id' => $authorGroup->id,
            'user_id' => $authorId, 'date_assigned' => date('Y-m-d H:i:s'),
        ]);
        foreach ([$authorId, $this->userId] as $userId) {
            \PKP\query\QueryParticipant::create(['queryId' => $query->id, 'userId' => $userId]);
        }
        $manager = \APP\template\TemplateManager::getManager(Registry::get('request'));
        $manager->assign([
            'query' => $query, 'queryNotesGridHandlerName' => 'grid.queries.QueryNotesGridHandler',
            'requestArgs' => ['submissionId' => $this->submissionId, 'stageId' => 3],
        ]);
        $html = $manager->fetch('controllers/grid/queries/readQuery.tpl');
        self::assertStringContainsString('data-cy="assistantPermissions-readonly-discussion"', $html);
        self::assertStringNotContainsString('add_note', $html);
    }

    public function testRecommendationReviewViewShowsReviewContentOnlyAfterCompletion(): void
    {
        $review = Repo::reviewAssignment()->newDataObject([
            'id' => 1, 'submissionId' => $this->submissionId, 'stageId' => 3, 'reviewerId' => $this->userId,
            'recommendation' => 1, 'competingInterests' => 'Interest statement',
        ]);
        $comment = new \PKP\submission\SubmissionComment();
        $comment->setComments('Reviewer comment text');
        $manager = \APP\template\TemplateManager::getManager(Registry::get('request'));
        foreach ([null => false, '2026-01-01 00:00:00' => true] as $dateCompleted => $visible) {
            $review->setDateCompleted($dateCompleted ?: null);
            $manager->assign([
                'reviewAssignment' => $review, 'submission' => Repo::submission()->get($this->submissionId),
                'comments' => new \PKP\core\ArrayItemIterator([$comment]),
                'commentsPrivate' => new \PKP\core\ArrayItemIterator([]),
            ]);
            $html = $manager->fetch('controllers/grid/users/reviewer/readReview.tpl');
            self::assertStringContainsString('data-cy="assistantPermissions-read-review"', $html);
            foreach (['Reviewer comment text', 'Interest statement', __('plugins.generic.assistantPermissions.reviewerRecommendation.1')] as $text) {
                self::assertSame($visible, str_contains($html, $text), $text);
            }
        }
    }

    public function testUnrelatedQueryCannotMaskTheStageOfANotification(): void
    {
        $query = \PKP\query\Query::create([
            'assocType' => \APP\core\Application::ASSOC_TYPE_SUBMISSION,
            'assocId' => $this->submissionId, 'stageId' => 2, 'seq' => 1,
        ]);
        $request = $this->request(['submissionId' => $this->submissionId, 'stageId' => 3, 'queryId' => $query->id]);
        $policy = new RequestPolicy($request, 'grid.users.stageParticipant.StageParticipantGridHandler', 'sendNotification');
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
    }

    public function testChangingAFileIntoAReviewerAttachmentIsDenied(): void
    {
        $reviewRoundId = DB::table('review_rounds')->insertGetId([
            'submission_id' => $this->submissionId, 'stage_id' => 3, 'round' => 1,
        ]);
        $review = (array) DB::table('review_assignments')->first();
        unset($review['review_id']);
        $review['submission_id'] = $this->submissionId;
        $review['stage_id'] = 3;
        $review['review_round_id'] = $reviewRoundId;
        $reviewId = DB::table('review_assignments')->insertGetId($review);
        $file = (array) DB::table('submission_files')->first();
        unset($file['submission_file_id']);
        $file['submission_id'] = $this->submissionId;
        $file['file_stage'] = \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_SUBMISSION;
        $fileId = DB::table('submission_files')->insertGetId($file);
        $params = [
            'submissionId' => $this->submissionId, 'submissionFileId' => $fileId,
            'fileStage' => \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT,
            'assocType' => \APP\core\Application::ASSOC_TYPE_REVIEW_ASSIGNMENT, 'assocId' => $reviewId,
        ];
        $controller = $this->getMockBuilder(\PKP\API\v1\submissions\PKPSubmissionFileController::class)
            ->onlyMethods(['getParameter'])->getMock();
        $queryParams = $params;
        $queryParams['fileStage'] = \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_SUBMISSION;
        $controller->method('getParameter')->willReturnCallback(fn ($name, $default = null) => $queryParams[$name] ?? $default);
        $previousHttp = app(\Illuminate\Http\Request::class);
        app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create('/submissions/' . $this->submissionId . '/files/' . $fileId . '?fileStage=2', 'PUT', $params));
        try {
            $policy = new RequestPolicy($this->request($params), controller: $controller);
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
        } finally {
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
    }

    public function testUploadCannotReplaceARestrictedFileEvenWithASpoofedStage(): void
    {
        $file = (array) DB::table('submission_files')->first();
        unset($file['submission_file_id']);
        $file['submission_id'] = $this->submissionId;
        $file['file_stage'] = \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION;
        $fileId = DB::table('submission_files')->insertGetId($file);
        $request = $this->request([
            'submissionId' => $this->submissionId, 'stageId' => 2, 'revisedFileId' => $fileId,
        ]);
        $policy = new RequestPolicy($request, 'wizard.fileUpload.FileUploadWizardHandler', 'uploadFile');
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
    }

    private function recommendationFixture(bool $writing = false, array $input = []): array
    {
        $roundId = DB::table('review_rounds')->insertGetId([
            'submission_id' => $this->submissionId, 'stage_id' => 3, 'round' => 1,
        ]);
        $editorId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
        $editorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])
            ->whereHas('userGroupStages', fn ($q) => $q->where('stage_id', 3))->first();
        DB::table('stage_assignments')->insert([
            'submission_id' => $this->submissionId, 'user_group_id' => $editorGroup->id,
            'user_id' => $editorId, 'recommend_only' => false, 'date_assigned' => date('Y-m-d H:i:s'),
        ]);
        $input += ['reviewRoundId' => $roundId, 'decision' => \APP\decision\Decision::RECOMMEND_ACCEPT,
            'actions' => [['id' => 'discussion', 'subject' => 'Recommendation', 'body' => 'Test']]];
        $request = $this->request($input);
        $policy = new \APP\plugins\generic\assistantPermissions\classes\RecommendationAccessPolicy($request, $writing);
        $policy->addAuthorizedContextObject(\APP\core\Application::ASSOC_TYPE_SUBMISSION, Repo::submission()->get($this->submissionId));
        $policy->addAuthorizedContextObject(\APP\core\Application::ASSOC_TYPE_DECISION_TYPE, Repo::decision()->getDecisionType($input['decision']));
        return [$policy, $input, $editorId];
    }

    public function testNativeRecommendationPolicyAllowsOnlyCurrentAssignedRecommendation(): void
    {
        [$policy] = $this->recommendationFixture();
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_PERMIT, $policy->effect());
        DB::table('review_rounds')->insert(['submission_id' => $this->submissionId, 'stage_id' => 3, 'round' => 2]);
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
    }

    public function testNativeRecommendationPolicyNeverAllowsFinalDecision(): void
    {
        [$policy] = $this->recommendationFixture(false, ['decision' => \APP\decision\Decision::ACCEPT]);
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
        $method = new \ReflectionMethod(\APP\plugins\generic\assistantPermissions\classes\AssistantPermissionsSubmissionController::class, 'addDecision');
        self::assertSame(\PKP\API\v1\submissions\PKPSubmissionController::class, $method->getDeclaringClass()->getName());
    }

    public function testNativeRecommendationPolicyRejectsRevokedAssignmentAndAuthorEditor(): void
    {
        [$policy, $input, $editorId] = $this->recommendationFixture();
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->where('user_id', $this->userId)
            ->update(['recommend_only' => false]);
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->where('user_id', $this->userId)
            ->update(['recommend_only' => true]);
        $authorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        DB::table('stage_assignments')->insert([
            'submission_id' => $this->submissionId, 'user_group_id' => $authorGroup->id,
            'user_id' => $editorId, 'date_assigned' => date('Y-m-d H:i:s'),
        ]);
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
    }

    public function testNativeRecommendationPostRejectsExtraActionsContactsAndAttachments(): void
    {
        [$policy, $input] = $this->recommendationFixture(true);
        $previousHttp = app(\Illuminate\Http\Request::class);
        try {
            app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create('/', 'POST', $input));
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_PERMIT, $policy->effect());
            $invalid = [[], [['id' => 'other']], [...$input['actions'], $input['actions'][0]]];
            foreach (['cc' => ['author@example.test'], 'bcc' => ['reviewer@example.test'],
                'attachments' => [['submissionFileId' => 12]], 'recipients' => [999999]] as $field => $value) {
                $action = $input['actions'][0];
                $action[$field] = $value;
                $invalid[] = [$action];
            }
            foreach ($invalid as $actions) {
                app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create('/', 'POST', array_replace($input, ['actions' => $actions])));
                self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
            }
        } finally {
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
        self::assertSame(0, DB::table('edit_decisions')->where('submission_id', $this->submissionId)->count());
    }

    public function testNativeRecommendationRejectsTextualIncompleteProgress(): void
    {
        [$policy] = $this->recommendationFixture();
        $submission = $policy->getAuthorizedContextObject(\APP\core\Application::ASSOC_TYPE_SUBMISSION);
        foreach (['start', 'details', 'review'] as $progress) {
            $submission->setData('submissionProgress', $progress);
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
        }
    }

    public function testParticipantNotifyAllowsEditorialTeamButRejectsAuthorsAndUnassignedUsers(): void
    {
        [$unused, $input, $editorId] = $this->recommendationFixture();
        foreach (['viewNotify', 'sendNotification'] as $op) {
            $request = $this->request(['submissionId' => $this->submissionId, 'stageId' => 3, 'userId' => $editorId]);
            $policy = new RequestPolicy($request, 'grid.users.stageParticipant.StageParticipantGridHandler', $op);
            self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, $policy->effect());
        }
        $outsider = (int) DB::table('users')->whereNotIn('user_id', [$this->userId, $editorId])->value('user_id');
        $request = $this->request(['submissionId' => $this->submissionId, 'stageId' => 3, 'userId' => $outsider]);
        self::assertSame(
            AuthorizationPolicy::AUTHORIZATION_DENY,
            (new RequestPolicy($request, 'grid.users.stageParticipant.StageParticipantGridHandler', 'sendNotification'))->effect()
        );
        $authorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        DB::table('stage_assignments')->insert([
            'submission_id' => $this->submissionId, 'user_group_id' => $authorGroup->id,
            'user_id' => $editorId, 'date_assigned' => date('Y-m-d H:i:s'),
        ]);
        foreach (['viewNotify', 'sendNotification'] as $op) {
            $request = $this->request(['submissionId' => $this->submissionId, 'stageId' => 3, 'userId' => $editorId]);
            self::assertSame(
                AuthorizationPolicy::AUTHORIZATION_DENY,
                (new RequestPolicy($request, 'grid.users.stageParticipant.StageParticipantGridHandler', $op))->effect()
            );
        }
    }

    public function testParticipantNotifyRejectsReviewerEvenWhenAlsoAnEditor(): void
    {
        [$unused, $input, $editorId] = $this->recommendationFixture();
        $review = (array) DB::table('review_assignments')->first();
        unset($review['review_id']);
        $review['submission_id'] = $this->submissionId;
        $review['reviewer_id'] = $editorId;
        $review['stage_id'] = 3;
        $review['review_round_id'] = $input['reviewRoundId'];
        DB::table('review_assignments')->insert($review);
        foreach (['viewNotify', 'sendNotification'] as $op) {
            $request = $this->request(['submissionId' => $this->submissionId, 'stageId' => 3, 'userId' => $editorId]);
            self::assertSame(
                AuthorizationPolicy::AUTHORIZATION_DENY,
                (new RequestPolicy($request, 'grid.users.stageParticipant.StageParticipantGridHandler', $op))->effect()
            );
        }
        self::assertNotContains($editorId, (new Access())->notificationRecipients($this->submissionId, 3));
    }

    public function testContactRestrictionFollowsTheActualNoteAndBlocksDiscussionAttachments(): void
    {
        $authorId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
        $authorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        DB::table('stage_assignments')->insert([
            'submission_id' => $this->submissionId, 'user_group_id' => $authorGroup->id,
            'user_id' => $authorId, 'date_assigned' => date('Y-m-d H:i:s'),
        ]);
        $queries = [];
        foreach ([false, true] as $withAuthor) {
            $query = \PKP\query\Query::create([
                'assocType' => \APP\core\Application::ASSOC_TYPE_SUBMISSION,
                'assocId' => $this->submissionId, 'stageId' => 3, 'seq' => 1,
            ]);
            if ($withAuthor) {
                \PKP\query\QueryParticipant::create(['queryId' => $query->id, 'userId' => $authorId]);
            }
            $queries[] = $query;
        }
        $note = \PKP\note\Note::create([
            'assocType' => \APP\core\Application::ASSOC_TYPE_QUERY, 'assocId' => $queries[1]->id,
            'userId' => $this->userId, 'contents' => 'Original',
        ]);
        $this->group->{Access::RESTRICT_FILES} = false;
        $this->group->save();
        foreach ([
            ['grid.queries.QueryNotesGridHandler', 'insertNote', $queries[0]->id],
            ['grid.files.query.ManageQueryNoteFilesGridHandler', 'updateQueryNoteFiles', $queries[1]->id],
            ['wizard.fileUpload.FileUploadWizardHandler', 'uploadFile', $queries[1]->id],
        ] as [$component, $operation, $queryId]) {
            $request = $this->request(['submissionId' => $this->submissionId, 'queryId' => $queryId, 'noteId' => $note->id, 'stageId' => 3]);
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy($request, $component, $operation))->effect());
        }
        self::assertSame('Original', $note->fresh()->contents);
        self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy(
            $this->request(['queryId' => $queries[1]->id, 'noteId' => $note->id]),
            'grid.files.query.ManageQueryNoteFilesGridHandler',
            'fetchGrid'
        ))->effect());
    }

    public function testParticipantMessagesCannotBypassTheContactRestriction(): void
    {
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->update(['recommend_only' => false]);
        $authorId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
        $authorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
        $submission = Repo::submission()->get($this->submissionId);
        $form = new \PKP\controllers\grid\users\stageParticipant\form\AddParticipantForm($submission, 3);
        $form->setData('userGroupId', $authorGroup->id);
        $form->setData('userId', $authorId);
        $form->setData('message', 'Forbidden message');
        $result = null;
        Hook::call('addparticipantform::validate', [$form, &$result]);
        self::assertFalse($form->isValid());
        $withoutMessage = new \PKP\controllers\grid\users\stageParticipant\form\AddParticipantForm($submission, 3);
        $withoutMessage->setData('userGroupId', $authorGroup->id);
        $withoutMessage->setData('userId', $authorId);
        Hook::call('addparticipantform::validate', [$withoutMessage, &$result]);
        self::assertTrue($withoutMessage->isValid());
    }

    public function testFileUploadRestrictionsFollowTheDestinationReviewRound(): void
    {
        DB::table('user_group_stage')->where('user_group_id', $this->group->id)->update(['stage_id' => 2]);
        $roundId = DB::table('review_rounds')->insertGetId([
            'submission_id' => $this->submissionId, 'stage_id' => 2, 'round' => 1, 'status' => 1,
        ]);
        $params = ['submissionId' => $this->submissionId,
            'fileStage' => \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
            'assocType' => \APP\core\Application::ASSOC_TYPE_REVIEW_ROUND, 'assocId' => $roundId,
        ];
        $controller = $this->getMockBuilder(\PKP\API\v1\submissions\PKPSubmissionFileController::class)
            ->onlyMethods(['getParameter'])->getMock();
        $controller->method('getParameter')->willReturnCallback(fn ($name, $default = null) => $params[$name] ?? $default);
        $previousHttp = app(\Illuminate\Http\Request::class);
        try {
            app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create('/submissions/' . $this->submissionId . '/files', 'POST', $params));
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy($this->request($params), controller: $controller))->effect());
            $this->group->{Access::RESTRICT_FILES} = false;
            $this->group->save();
            self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy($this->request($params), controller: $controller))->effect());
        } finally {
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
    }

    public function testFileDestinationIncludesAttachmentsCopiesAndDependentFiles(): void
    {
        DB::table('submissions')->where('submission_id', $this->submissionId)->update(['stage_id' => 2]);
        $file = (array) DB::table('submission_files')->first();
        unset($file['submission_file_id']);
        $file['submission_id'] = $this->submissionId;
        $file['file_stage'] = \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_SUBMISSION;
        $sourceId = DB::table('submission_files')->insertGetId($file);
        $file['file_stage'] = \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_FINAL;
        $parentId = DB::table('submission_files')->insertGetId($file);
        $previousHttp = app(\Illuminate\Http\Request::class);
        try {
            foreach ([
                [3, 'POST', ['fileStage' => \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_ATTACHMENT]],
                [4, 'PUT', ['submissionFileId' => $sourceId, 'toFileStage' => \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_COPYEDIT]],
                [4, 'POST', ['fileStage' => \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_DEPENDENT,
                    'assocType' => \APP\core\Application::ASSOC_TYPE_SUBMISSION_FILE, 'assocId' => $parentId]],
            ] as [$stageId, $method, $params]) {
                DB::table('user_group_stage')->where('user_group_id', $this->group->id)->update(['stage_id' => $stageId]);
                $params['submissionId'] = $this->submissionId;
                $controller = $this->getMockBuilder(\PKP\API\v1\submissions\PKPSubmissionFileController::class)
                    ->onlyMethods(['getParameter'])->getMock();
                $controller->method('getParameter')->willReturnCallback(fn ($name, $default = null) => $params[$name] ?? $default);
                $path = '/submissions/' . $this->submissionId . '/files' . ($method === 'PUT' ? '/' . $sourceId . '/copy' : '');
                app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create($path, $method, $params));
                $this->group->{Access::RESTRICT_FILES} = true;
                $this->group->save();
                self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy($this->request($params), controller: $controller))->effect());
                $this->group->{Access::RESTRICT_FILES} = false;
                $this->group->save();
                self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy($this->request($params), controller: $controller))->effect());
            }
        } finally {
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
    }

    public function testSubmissionDocumentRestrictionsIgnoreSpoofedWorkflowStage(): void
    {
        $request = $this->request(['submissionId' => $this->submissionId, 'stageId' => 2]);
        foreach (['saveFile', 'deleteFile', 'uploadFile'] as $operation) {
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy(
                $request,
                'grid.files.submissionDocuments.SubmissionDocumentsFilesGridHandler',
                $operation
            ))->effect());
        }
        self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy(
            $request,
            'grid.files.submissionDocuments.SubmissionDocumentsFilesGridHandler',
            'fetchGrid'
        ))->effect());
        $fileId = DB::table('library_files')->insertGetId([
            'context_id' => $this->contextId, 'submission_id' => $this->submissionId,
            'file_name' => 'audit-document.txt', 'original_file_name' => 'audit-document.txt',
            'file_type' => 'text/plain', 'file_size' => 0, 'type' => 1, 'public_access' => 0,
            'date_uploaded' => date('Y-m-d H:i:s'), 'date_modified' => date('Y-m-d H:i:s'),
        ]);
        $otherSubmissionId = DB::table('submissions')->insertGetId([
            'context_id' => $this->contextId, 'stage_id' => 2, 'status' => 1, 'locale' => 'en', 'submission_progress' => '',
        ]);
        $spoofed = $this->request(['submissionId' => $otherSubmissionId, 'fileId' => $fileId, 'stageId' => 2]);
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy(
            $spoofed,
            'grid.files.submissionDocuments.SubmissionDocumentsFilesGridHandler',
            'deleteFile'
        ))->effect());
        self::assertTrue(DB::table('library_files')->where('file_id', $fileId)->exists());
        $this->group->{Access::RESTRICT_FILES} = false;
        $this->group->save();
        self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy(
            $request,
            'grid.files.submissionDocuments.SubmissionDocumentsFilesGridHandler',
            'saveFile'
        ))->effect());
    }

    public function testNativeDecisionActionsRespectIndependentContactAndFileRestrictions(): void
    {
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->update(['recommend_only' => false]);
        $controller = $this->getMockBuilder(\APP\API\v1\submissions\SubmissionController::class)
            ->onlyMethods(['getParameter'])->getMock();
        $controller->method('getParameter')->willReturnCallback(fn ($name, $default = null) =>
            $name === 'submissionId' ? $this->submissionId : $default);
        $previousHttp = app(\Illuminate\Http\Request::class);
        try {
            foreach ([
                [true, false, ['id' => 'notifyAuthors', 'recipients' => [123]]],
                [true, false, ['id' => 'notifyReviewers', 'recipients' => [123]]],
                [true, false, ['id' => 'discussion', 'cc' => ['author@example.org']]],
                [true, false, ['id' => 'discussion', 'bcc' => ['reviewer@example.org']]],
                [false, true, ['id' => 'notifyAuthors', 'attachments' => [['submissionFileId' => 12]]]],
                [false, true, ['id' => 'discussion', 'attachments' => [['temporaryFileId' => 12]]]],
            ] as [$blockContact, $restrictFiles, $action]) {
                $this->group->{Access::BLOCK_CONTACT} = $blockContact;
                $this->group->{Access::RESTRICT_FILES} = $restrictFiles;
                $this->group->save();
                $http = \Illuminate\Http\Request::create('/submissions/' . $this->submissionId . '/decisions', 'POST', ['actions' => [$action]]);
                app()->instance(\Illuminate\Http\Request::class, $http);
                self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy($this->request(), controller: $controller))->effect());
            }
            $this->group->{Access::BLOCK_CONTACT} = true;
            $this->group->{Access::RESTRICT_FILES} = false;
            $this->group->save();
            $editorId = (int) DB::table('users')->where('user_id', '<>', $this->userId)->value('user_id');
            $editorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_MANAGER])->first();
            DB::table('stage_assignments')->insert([
                'submission_id' => $this->submissionId, 'user_group_id' => $editorGroup->id,
                'user_id' => $editorId, 'recommend_only' => false, 'date_assigned' => date('Y-m-d H:i:s'),
            ]);
            DB::table('user_group_stage')->updateOrInsert(
                ['user_group_id' => $editorGroup->id, 'stage_id' => 3],
                ['context_id' => $this->contextId]
            );
            foreach ([[], [['submissionFileId' => 12]]] as $attachments) {
                $action = ['id' => 'discussion', 'recipients' => [$editorId], 'attachments' => $attachments];
                app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create(
                    '/submissions/' . $this->submissionId . '/decisions',
                    'POST',
                    ['actions' => [$action]]
                ));
                self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy($this->request(), controller: $controller))->effect());
            }
            $authorGroup = UserGroup::withContextIds([$this->contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->first();
            DB::table('stage_assignments')->insert([
                'submission_id' => $this->submissionId, 'user_group_id' => $authorGroup->id,
                'user_id' => $editorId, 'date_assigned' => date('Y-m-d H:i:s'),
            ]);
            app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create(
                '/submissions/' . $this->submissionId . '/decisions',
                'POST',
                ['actions' => [['id' => 'discussion']]]
            ));
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy($this->request(), controller: $controller))->effect());
            app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create(
                '/submissions/' . $this->submissionId . '/decisions',
                'POST',
                ['actions' => []]
            ));
            self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy($this->request(), controller: $controller))->effect());
        } finally {
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
    }

    public function testContactOptionAppliesToInvitationsOutsideASubmission(): void
    {
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->update(['recommend_only' => false]);
        $controller = $this->getMockBuilder(\PKP\API\v1\invitations\InvitationController::class)
            ->onlyMethods(['getParameter'])->getMock();
        $controller->method('getParameter')->willReturnCallback(fn ($name, $default = null) => $default);
        $previousHttp = app(\Illuminate\Http\Request::class);
        try {
            foreach (['press/api/v1/invitations/7/populate', 'press/api/v1/invitations/7/invite'] as $path) {
                app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create('/' . $path, 'PUT'));
                $this->group->{Access::BLOCK_CONTACT} = true;
                $this->group->save();
                self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy($this->request(), controller: $controller))->effect());
                $this->group->{Access::BLOCK_CONTACT} = false;
                $this->group->save();
                self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy($this->request(), controller: $controller))->effect());
            }
        } finally {
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
    }

    public function testApiRulesUseThePathAsDecodedByTheRouter(): void
    {
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->update(['recommend_only' => false]);
        $previousHttp = app(\Illuminate\Http\Request::class);
        try {
            foreach ([
                [\PKP\API\v1\invitations\InvitationController::class, 'PUT', '/press/api/v1/invitations/7/%69nvite', []],
                [\PKP\API\v1\submissions\PKPSubmissionFileController::class, 'POST', '/press/api/v1/submissions/' . $this->submissionId . '/%66iles', []],
                [\APP\API\v1\submissions\SubmissionController::class, 'POST', '/press/api/v1/submissions/' . $this->submissionId . '/%64ecisions',
                    ['actions' => [['id' => 'notifyAuthors', 'recipients' => [123]]]]],
            ] as [$class, $method, $path, $input]) {
                $controller = $this->getMockBuilder($class)->onlyMethods(['getParameter'])->getMock();
                $controller->method('getParameter')->willReturnCallback(fn ($name, $default = null) =>
                    $name === 'submissionId' && !str_contains($path, 'invitations') ? $this->submissionId : $default);
                app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create($path, $method, $input));
                self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy($this->request(), controller: $controller))->effect(), $path);
            }
        } finally {
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
    }

    public function testFileOptionPersistsIndependentlyAndCanBeDisabled(): void
    {
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->update(['recommend_only' => false]);
        $this->group->recommendOnly = false;
        $this->group->{Access::BLOCK_CONTACT} = false;
        $this->group->{Access::RESTRICT_FILES} = true;
        $this->group->save();
        $rules = (new Access())->restrictions($this->contextId, $this->userId, $this->submissionId, 3);
        self::assertTrue($rules->restrictFiles);
        self::assertFalse($rules->recommendOnly);
        self::assertFalse($rules->blockContact);
        $request = $this->request(['submissionId' => $this->submissionId, 'stageId' => 3]);
        self::assertSame(
            AuthorizationPolicy::AUTHORIZATION_DENY,
            (new RequestPolicy($request, 'wizard.fileUpload.FileUploadWizardHandler', 'uploadFile'))->effect()
        );
        $form = new AssistantPermissionsUserGroupForm($this->contextId, $this->group->id);
        $form->initData();
        self::assertTrue($form->getData(Access::RESTRICT_FILES));
        $form->setData(Access::RESTRICT_FILES, false);
        $form->setData('recommendOnly', true);
        $form->setData('assignedStages', [3]);
        $form->execute();
        $rules = (new Access())->restrictions($this->contextId, $this->userId, $this->submissionId, 3);
        self::assertFalse($rules->restrictFiles);
        self::assertFalse($rules->recommendOnly);
        self::assertSame(
            AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE,
            (new RequestPolicy($request, 'wizard.fileUpload.FileUploadWizardHandler', 'uploadFile'))->effect()
        );
    }

    public function testRecommendationAttachmentGuardDependsOnFileOption(): void
    {
        [$policy, $input] = $this->recommendationFixture(true);
        $input['actions'][0]['attachments'] = [['submissionFileId' => 12]];
        $previousHttp = app(\Illuminate\Http\Request::class);
        try {
            app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create('/', 'POST', $input));
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $policy->effect());
            $this->group->{Access::RESTRICT_FILES} = false;
            $this->group->save();
            // The plugin's authorization no longer blocks attachments; native validation still applies.
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_PERMIT, $policy->effect());
        } finally {
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
    }

    public function testRestrictedAssistantKeepsAccessToTheirOwnProfile(): void
    {
        $request = $this->request();
        foreach (['saveIdentity', 'savePassword', 'saveAPIProfile'] as $operation) {
            self::assertSame(
                AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE,
                (new RequestPolicy($request, 'tab.user.ProfileTabHandler', $operation))->effect(),
                $operation
            );
        }
    }

    public function testFileOptionKeepsPublicationFormatFilesAtAnyStage(): void
    {
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->update(['recommend_only' => false]);
        // Format operations need submission access only, so the role's file option applies outside its stages too.
        DB::table('submissions')->where('submission_id', $this->submissionId)->update(['stage_id' => 5]);
        $publicationId = DB::table('publications')->insertGetId([
            'submission_id' => $this->submissionId, 'status' => 1, 'version' => 1,
        ]);
        $formatIds = [];
        foreach ([true, false] as $withFile) {
            $formatId = DB::table('publication_formats')->insertGetId([
                'publication_id' => $publicationId, 'seq' => 0, 'is_approved' => 0, 'is_available' => 0,
            ]);
            if ($withFile) {
                $file = (array) DB::table('submission_files')->first();
                unset($file['submission_file_id']);
                $file['submission_id'] = $this->submissionId;
                $file['file_stage'] = \PKP\submissionFile\SubmissionFile::SUBMISSION_FILE_PROOF;
                $file['assoc_type'] = \APP\core\Application::ASSOC_TYPE_REPRESENTATION;
                $file['assoc_id'] = $formatId;
                $proofId = DB::table('submission_files')->insertGetId($file);
            }
            $formatIds[$withFile] = $formatId;
        }
        $component = 'grid.catalogEntry.PublicationFormatGridHandler';
        $params = ['submissionId' => $this->submissionId, 'publicationId' => $publicationId];
        $effect = fn (string $operation, array $extra) => (new RequestPolicy($this->request($params + $extra), $component, $operation))->effect();
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $effect('deleteFormat', ['representationId' => $formatIds[true]]));
        self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, $effect('setProofFileCompletion', ['representationId' => $formatIds[true], 'submissionFileId' => $proofId]));
        self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, $effect('deleteFormat', ['representationId' => $formatIds[false]]));

        $controller = $this->getMockBuilder(\APP\API\v1\submissions\SubmissionController::class)->onlyMethods(['getParameter'])->getMock();
        $controller->method('getParameter')->willReturnCallback(fn ($name, $default = null) => $params[$name] ?? $default);
        $previousHttp = app(\Illuminate\Http\Request::class);
        try {
            app()->instance(\Illuminate\Http\Request::class, \Illuminate\Http\Request::create(
                '/press/api/v1/submissions/' . $this->submissionId . '/publications/' . $publicationId,
                'DELETE'
            ));
            self::assertSame(AuthorizationPolicy::AUTHORIZATION_DENY, (new RequestPolicy($this->request($params), controller: $controller))->effect());
            DB::table('submission_files')->where('submission_file_id', $proofId)->delete();
            self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy($this->request($params), controller: $controller))->effect());
            DB::table('submission_files')->insert(['submission_file_id' => $proofId] + $file);
            $this->group->{Access::RESTRICT_FILES} = false;
            $this->group->save();
            self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, (new RequestPolicy($this->request($params), controller: $controller))->effect());
            self::assertSame(AuthorizationDecisionManager::AUTHORIZATION_NOT_APPLICABLE, $effect('deleteFormat', ['representationId' => $formatIds[true]]));
        } finally {
            app()->instance(\Illuminate\Http\Request::class, $previousHttp);
        }
    }

    public function testFileOptionIsSavedEvenWithoutAssignedStages(): void
    {
        $form = new AssistantPermissionsUserGroupForm($this->contextId, 0);
        $form->initData();
        foreach (['roleId' => Role::ROLE_ID_ASSISTANT, 'name' => ['en' => 'File restriction without stages'],
            'abbrev' => ['en' => 'FR'], 'assignedStages' => [], Access::RESTRICT_FILES => true] as $key => $value) {
            $form->setData($key, $value);
        }
        $form->execute();
        $group = UserGroup::withContextIds([$this->contextId])->orderByDesc('user_group_id')->first();
        self::assertTrue($group->{Access::RESTRICT_FILES});
        $edit = new AssistantPermissionsUserGroupForm($this->contextId, $group->id);
        $edit->initData();
        $edit->setData(Access::RESTRICT_FILES, false);
        $edit->setData('assignedStages', []);
        $edit->execute();
        self::assertFalse(UserGroup::findById($group->id)->{Access::RESTRICT_FILES});
    }

}
