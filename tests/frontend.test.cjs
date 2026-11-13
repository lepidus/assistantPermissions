const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const fileManagerNamespaces = ['SUBMISSION_FILES', 'EDITOR_REVIEW_FILES', 'WORKFLOW_REVIEW_REVISIONS', 'COPYEDITED_FILES', 'FINAL_DRAFT_FILES', 'PRODUCTION_READY_FILES'];
function setup(submission, submissionStageId = submission?.stageId) {
    const stores = {};
    const hooks = {};
    const pkp = {
        modules: {vue: {}, useLocalize: {}, useUrl: {}, useDataChanged: {}},
        registry: {
            registerComponent() {},
            storeExtend(name, callback) {
                // Like VueRegistry.storeExtend, dispatch only for an actual Pinia store ID.
                const actualIds = ['workflow', 'reviewerManager', 'participantManager', ...fileManagerNamespaces.map(ns => `fileManager_${ns}`)];
                if (!actualIds.includes(name)) return;
                stores[name] = {getItemActions({participant}) { return hooks[`${name}.getItemActions`]([{name: 'participantNotify'}, {name: 'participantRemove'}], {submission, submissionStageId, participant}); }, props: {submission, submissionStageId}, extender: {extendFn(key, fn) {hooks[`${name}.${key}`] = fn;}}};
                callback({store: stores[name]});
            },
        },
    };
    vm.runInNewContext(readFileSync(`${__dirname}/../public/build/editorialAdvisor.js`, 'utf8'), {pkp});
    return hooks;
}
test('advisor keeps reviewer names/content and only read actions', () => {
    const hooks = setup({editorialAdvisor: {recommendOnly: true}});
    const actions = [{name: 'reviewerReadReview'}, {name: 'reviewerEmailReviewer'}, {name: 'reviewerCancelReviewer'}];
    assert.deepEqual(hooks['reviewerManager.getItemActions'](actions), actions.slice(0, 1));
    assert.equal(hooks['reviewerManager.getTopItems']([{}]).length, 0);
});
test('no-contact alone preserves unrelated actions', () => {
    const hooks = setup({editorialAdvisor: {blockContact: true}});
    const actions = [{name: 'reviewerReviewHistory'}, {name: 'reviewerEmailReviewer'}];
    assert.deepEqual(hooks['reviewerManager.getItemActions'](actions), actions.slice(0, 1));
});
test('recommend-only keeps only file details and hides final decisions', () => {
    const submission = {editorialAdvisor: {recommendOnly: true}};
    const hooks = setup(submission);
    const actions = [{name: 'fileDelete'}, {name: 'fileEdit'}, {name: 'fileSeeNotes'}];
    for (const namespace of fileManagerNamespaces) {
        const filter = hooks[`fileManager_${namespace}.getItemActions`] || (items => items);
        assert.deepEqual(filter(actions), actions.slice(2), namespace);
    }
    assert.equal(hooks['workflow.getActionItems']([{}], {submission}).length, 0);
});

test('completed reviews retain the OMP read-only details action', () => {
    const hooks = setup({editorialAdvisor: {recommendOnly: true}});
    const actions = [{name: 'reviewerReviewDetails'}, {name: 'reviewerThankReviewer'}];
    assert.deepEqual(hooks['reviewerManager.getItemActions'](actions), actions.slice(0, 1));
});
test('advisor preserves native recommendation controls while hiding final decisions', () => {
    const submission = {stageId: 3, editorialAdvisor: {recommendOnly: true}};
    const hooks = setup(submission);
    const items = [{component: 'WorkflowRecommendOnlyControls'}, {component: 'WorkflowActionButton'}];
    assert.deepEqual(hooks['workflow.getActionItems'](items, {submission, selectedStageId: 3}), items.slice(0, 1));
    assert.equal(hooks['workflow.getActionItems']([], {submission, selectedStageId: 3}).length, 0);
});
test('a restriction in another stage does not hide current-stage actions', () => {
    const submission = {stageId: 3, editorialAdvisor: {recommendOnly: true,
        stages: {2: {recommendOnly: false}, 3: {recommendOnly: true}}}};
    const hooks = setup(submission);
    const actions = [{props: {action: 'test'}}];
    assert.equal(hooks['workflow.getActionItems'](actions, {submission, selectedMenuState: {stageId: 2}}).length, 1);
});
test('participant identities remain but notify and management actions are removed', () => {
    const submission = {editorialAdvisor: {recommendOnly: true, blockContact: true}};
    const hooks = setup(submission);
    const actions = [{name: 'participantNotify'}, {name: 'participantRemove'}];
    assert.equal(hooks['participantManager.getItemActions'](actions, {submission, submissionStageId: 3}).length, 0);
});

test('unrestricted file managers preserve their original actions', () => {
    const hooks = setup({stageId: 3, editorialAdvisor: {recommendOnly: false}}, 3);
    const actions = [{name: 'fileDelete'}, {name: 'fileEdit'}, {name: 'fileSeeNotes'}];
    for (const ns of fileManagerNamespaces) {
        const filter = hooks[`fileManager_${ns}.getItemActions`] || (items => items);
        assert.deepEqual(filter(actions), actions);
    }
});
test('file restrictions follow the displayed stage', () => {
    const submission = {stageId: 3, editorialAdvisor: {recommendOnly: true, stages: {2:{recommendOnly:false}, 3:{recommendOnly:true}}}};
    const hooks = setup(submission, 2);
    const actions = [{name: 'fileEdit'}, {name: 'fileDelete'}, {name:'fileSeeNotes'}];
    const filter = hooks['fileManager_EDITOR_REVIEW_FILES.getItemActions'] || (items => items);
    assert.deepEqual(filter(actions), actions);
});

test('restricted file managers hide upload and selection', () => {
    const hooks = setup({stageId: 3, editorialAdvisor: {recommendOnly: true}});
    const actions = [{props: {action: 'fileUpload'}}, {props: {action: 'fileSelectUpload'}}];
    for (const ns of fileManagerNamespaces) {
        const filter = hooks[`fileManager_${ns}.getTopItems`] || (items => items);
        assert.equal(filter(actions).length, 0, ns);
    }
});
test('file upload actions remain available outside the restricted stage and for no-contact alone', () => {
    const actions = [{props: {action: 'fileUpload'}}, {props: {action: 'fileSelectUpload'}}];
    for (const submission of [
        {stageId: 3, editorialAdvisor: {blockContact: true}},
        {stageId: 3, editorialAdvisor: {recommendOnly: true, stages: {2: {recommendOnly: false}}}},
    ]) {
        const hooks = setup(submission, 2);
        for (const ns of fileManagerNamespaces) {
            const filter = hooks[`fileManager_${ns}.getTopItems`] || (items => items);
            assert.deepEqual(filter(actions), actions);
        }
    }
});

test('participant notification remains for editorial team but not forbidden participants', () => {
    const submission = {stageId: 3, editorialAdvisor: {recommendOnly: true, blockContact: true, notifyParticipantIds: [2]}};
    const hooks = setup(submission);
    const actions = [{name: 'participantNotify'}, {name: 'participantEdit'}, {name: 'participantRemove'}, {name: 'participantLoginAs'}];
    assert.deepEqual(hooks['participantManager.getItemActions'](actions, {submission, submissionStageId: 3, participant: {id: 2}}), actions.slice(0, 1));
    assert.equal(hooks['participantManager.getItemActions'](actions, {submission, submissionStageId: 3, participant: {id: 3}}).length, 0);
});
test('empty participant menus are marked without changing identities or nonempty menus', () => {
    const submission = {stageId: 3, editorialAdvisor: {recommendOnly: true, blockContact: true, notifyParticipantIds: [2]}};
    const hooks = setup(submission);
    const items = [{component: 'ParticipantManagerItemInfoName', props: {class: 'existing-class'}}];
    const mark = hooks['participantManager.getItemInfoItems'];
    assert.equal(typeof mark, 'function');
    const empty = mark(items, {submission, submissionStageId: 3, participant: {id: 3}});
    assert.equal(empty[0].props['data-advisor-empty-actions'], true);
    assert.equal(empty[0].props.class, 'existing-class');
    assert.deepEqual(mark(items, {submission, submissionStageId: 3, participant: {id: 2}}), items);
});

test('contact restriction preserves management unless recommend-only also applies', () => {
    const submission = {stageId: 3, editorialAdvisor: {blockContact: true, notifyParticipantIds: [2]}};
    const hooks = setup(submission);
    const actions = [{name: 'participantNotify'}, {name: 'participantEdit'}];
    assert.deepEqual(hooks['participantManager.getItemActions'](actions, {submission, submissionStageId: 3, participant: {id: 2}}), actions);
    assert.deepEqual(hooks['participantManager.getItemActions'](actions, {submission, submissionStageId: 3, participant: {id: 3}}), actions.slice(1));
});

test('participant notifications follow the displayed stage allowlist', () => {
    const submission = {stageId: 3, editorialAdvisor: {blockContact: true, notifyParticipantIds: [2], stages: {
        2: {blockContact: true, notifyParticipantIds: [7]}, 3: {blockContact: true, notifyParticipantIds: [2]},
    }}};
    const hooks = setup(submission, 2);
    const actions = [{name: 'participantNotify'}];
    assert.equal(hooks['participantManager.getItemActions'](actions, {submission, submissionStageId: 2, participant: {id: 2}}).length, 0);
    assert.deepEqual(hooks['participantManager.getItemActions'](actions, {submission, submissionStageId: 2, participant: {id: 7}}), actions);
});
