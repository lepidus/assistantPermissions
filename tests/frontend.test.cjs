const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
function setup(submission) {
    const stores = {};
    const hooks = {};
    const pkp = {
        modules: {vue: {}, useLocalize: {}, useUrl: {}, useDataChanged: {}},
        registry: {
            registerComponent() {},
            storeExtend(name, callback) {
                stores[name] = {props: {submission}, extender: {extendFn(key, fn) {hooks[`${name}.${key}`] = fn;}}};
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
test('recommend-only hides file deletion and final decisions', () => {
    const submission = {editorialAdvisor: {recommendOnly: true}};
    const hooks = setup(submission);
    const actions = [{name: 'fileDelete'}, {name: 'fileEdit'}];
    assert.deepEqual(hooks['fileManager.getItemActions'](actions), actions.slice(1));
    assert.equal(hooks['workflow.getActionItems']([{}], {submission}).length, 0);
});

test('completed reviews retain the OMP read-only details action', () => {
    const hooks = setup({editorialAdvisor: {recommendOnly: true}});
    const actions = [{name: 'reviewerReviewDetails'}, {name: 'reviewerThankReviewer'}];
    assert.deepEqual(hooks['reviewerManager.getItemActions'](actions), actions.slice(0, 1));
});
test('recommendation form is absent on historical rounds', () => {
    const submission = {id: 12, stageId: 3, editorialAdvisor: {
        recommendOnly: true, recommendations: [{value: 9, label: 'Accept'}], reviewRoundId: 20,
    }};
    const hooks = setup(submission);
    const args = {submission, selectedMenuState: {primaryMenuItem: 'workflow', stageId: 3, reviewRoundId: 19}};
    assert.equal(hooks['workflow.getPrimaryItems']([], args).length, 0);
    args.selectedMenuState.reviewRoundId = 20;
    assert.equal(hooks['workflow.getPrimaryItems']([], args).length, 1);
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
