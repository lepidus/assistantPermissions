<?php

namespace APP\plugins\generic\editorialAdvisor\tests;

use APP\core\Request;
use APP\facades\Repo;
use APP\plugins\generic\editorialAdvisor\classes\Access;
use APP\plugins\generic\editorialAdvisor\classes\AdvisorUserGroupForm;
use APP\plugins\generic\editorialAdvisor\classes\RequestPolicy;
use APP\plugins\generic\editorialAdvisor\EditorialAdvisorPlugin;
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
        $plugin = $this->getMockBuilder(EditorialAdvisorPlugin::class)->onlyMethods(['getEnabled'])->getMock();
        $plugin->method('getEnabled')->willReturn(true);
        $plugin->register('generic', 'plugins/generic/editorialAdvisor', $this->contextId);
        app('schema')->get('userGroup', true);
        $this->submissionId = DB::table('submissions')->insertGetId([
            'context_id' => $this->contextId, 'stage_id' => 3, 'status' => 1, 'locale' => 'en',
        ]);
        $this->group = new UserGroup();
        $this->group->fill([
            'contextId' => $this->contextId, 'roleId' => Role::ROLE_ID_ASSISTANT,
            'name' => ['en' => 'Arbitrary counsel role'], 'abbrev' => ['en' => 'AC'],
            'recommendOnly' => true, Access::BLOCK_CONTACT => true,
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
            'user_id' => $this->userId, 'date_assigned' => date('Y-m-d H:i:s'),
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

    private function request(array $params = []): Request
    {
        $request = $this->createPartialMock(Request::class, ['getContext', 'getUser', 'getUserVar', 'getSite', 'getRouter']);
        $context = app('context')->get($this->contextId);
        $router = $this->createPartialMock(\PKP\core\PKPPageRouter::class, ['getContext']);
        $router->method('getContext')->willReturn($context);
        $request->method('getRouter')->willReturn($router);
        $site = new \PKP\site\Site();
        $site->setData('primaryLocale', 'en');
        $site->setData('supportedLocales', ['en']);
        $request->method('getSite')->willReturn($site);
        $request->method('getContext')->willReturn($context);
        $request->method('getUser')->willReturn(Repo::user()->get($this->userId));
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

    public function testExpiredMembershipDoesNotGrantRecommendation(): void
    {
        DB::table('user_user_groups')->where('user_group_id', $this->group->id)->update(['date_end' => '2000-01-01']);
        self::assertFalse((new Access())->canRecommend($this->contextId, $this->userId, $this->submissionId, 3));
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

    public function testAssistantOptionIsAvailableToNewRoles(): void
    {
        $form = new AdvisorUserGroupForm($this->contextId, 0);
        self::assertContains(Role::ROLE_ID_ASSISTANT, $form->getRecommendOnlyRoles());
    }
    public function testRoleFormCreatesAssistantWithBothOptionsAndRendersThem(): void
    {
        $form = new AdvisorUserGroupForm($this->contextId, 0);
        $form->initData();
        foreach ([
            'roleId' => Role::ROLE_ID_ASSISTANT, 'name' => ['en' => 'Counsel with custom name'],
            'abbrev' => ['en' => 'CC'], 'assignedStages' => [3], 'recommendOnly' => true,
            Access::BLOCK_CONTACT => true,
        ] as $key => $value) {
            $form->setData($key, $value);
        }
        $form->execute();
        $group = UserGroup::withContextIds([$this->contextId])->orderByDesc('user_group_id')->first();
        self::assertSame(Role::ROLE_ID_ASSISTANT, $group->roleId);
        self::assertTrue($group->recommendOnly);
        self::assertTrue($group->{Access::BLOCK_CONTACT});
        $edit = new AdvisorUserGroupForm($this->contextId, $group->id);
        $edit->initData();
        \APP\template\TemplateManager::getManager(Registry::get('request'))->assign([
            'hasSystemNotifications' => false, 'requestOptions' => '{}',
            'notificationStyleClass' => '', 'FBV_sizeInfo' => '', 'refreshOn' => '',
        ]);
        Registry::get('request')->getSession()->regenerateToken();
        $html = $edit->fetch(Registry::get('request'));
        self::assertStringContainsString('name="editorialAdvisorBlockContact"', $html);
        self::assertStringContainsString('name="recommendOnly"', $html);
    }

    public function testRecommendationEndpointRejectsFinalDecisionWithoutWriting(): void
    {
        $controller = $this->getMockBuilder(\APP\plugins\generic\editorialAdvisor\classes\RecommendationController::class)
            ->onlyMethods(['getParameter', 'getRequest'])->getMock();
        $controller->method('getRequest')->willReturn(Registry::get('request'));
        $controller->method('getParameter')->willReturn($this->submissionId);
        $roundId = DB::table('review_rounds')->insertGetId([
            'submission_id' => $this->submissionId, 'stage_id' => 3, 'round' => 1,
        ]);
        $response = $controller->recommend(\Illuminate\Http\Request::create('/', 'POST', [
            'decision' => \APP\decision\Decision::ACCEPT, 'stageId' => 3,
            'reviewRoundId' => $roundId, 'comment' => 'Analysis',
        ]));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, DB::table('edit_decisions')->where('submission_id', $this->submissionId)->count());
    }

    public function testNewAssignmentsUseTheAssistantGroupRecommendationSetting(): void
    {
        DB::table('stage_assignments')->where('submission_id', $this->submissionId)->delete();
        $assignment = Repo::stageAssignment()->build($this->submissionId, $this->group->id, $this->userId, false);
        self::assertTrue((bool) $assignment->recommendOnly);
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
        (new \APP\plugins\generic\editorialAdvisor\classes\DiscussionHooks())->validate('queryform::validate', $args);
        self::assertFalse($form->isValid());
        self::assertSame(__('plugins.generic.editorialAdvisor.denied'), $form->getErrorsArray()['users']);
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
        self::assertStringContainsString('data-cy="advisor-readonly-discussion"', $html);
        self::assertStringNotContainsString('add_note', $html);
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

}
