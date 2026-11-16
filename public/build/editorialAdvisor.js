/* OMP 3.5 exposes Vue and the supported store extension API through pkp. */
(() => {
    const rules = (submission, stageId = submission?.stageId) => submission?.editorialAdvisor?.stages?.[stageId] || submission?.editorialAdvisor || {};
    pkp.registry.storeExtend('workflow', ({store}) => {
        store.extender.extendFn('getActionItems', (items, args) => rules(args.submission, args.selectedStageId ?? args.selectedMenuState?.stageId).recommendOnly
            ? items.filter((item) => item.component === 'WorkflowRecommendOnlyControls') : items);
    });
    pkp.registry.storeExtend('reviewerManager', ({store}) => {
        const currentRules = () => rules(store.props.submission, store.props.submissionStageId);
        const filterActions = (items) => items.filter((item) => {
            const action = item.name || item.props?.action;
            if (currentRules().recommendOnly) return ['reviewerReadReview', 'reviewerReviewDetails', 'reviewerReviewHistory'].includes(action);
            return !currentRules().blockContact || ![
                'reviewerEmailReviewer', 'reviewerResendRequest', 'reviewerThankReviewer',
                'reviewerSendReminder', 'reviewerSendToOrcid', 'reviewerEditReview',
                'reviewerCancelReviewer', 'reviewerUnassignReviewer', 'reviewerReinstateReviewer',
            ].includes(action);
        });
        store.extender.extendFn('getTopItems', (items) => currentRules().recommendOnly || currentRules().blockContact ? [] : items);
        store.extender.extendFn('getItemActions', filterActions);
        store.extender.extendFn('getItemPrimaryActions', filterActions);
    });
    pkp.registry.storeExtend('participantManager', ({store}) => {
        store.extender.extendFn('getTopItems', (items, args) => rules(args.submission, args.submissionStageId).recommendOnly ? [] : items);
        store.extender.extendFn('getItemActions', (items, args) => {
            const config = rules(args.submission, args.submissionStageId);
            return items.filter((item) => !(config.blockContact && item.name === 'participantNotify'
                && !config.notifyParticipantIds?.includes(Number(args.participant?.id)))
                && !(config.recommendOnly && ['participantEdit', 'participantRemove', 'participantLoginAs'].includes(item.name)));
        });
        store.extender.extendFn('getItemInfoItems', (items, args) => {
            if (!items.length || store.getItemActions({participant: args.participant}).length) return items;
            // Native info components forward attributes to their root; CSS hides this row's empty menu.
            return items.map((item, index) => index ? item : {...item,
                props: {...item.props, 'data-advisor-empty-actions': true},
            });
        });
    });
    // FileManager stores are namespaced; VueRegistry matches the complete Pinia store ID.
    for (const namespace of [
        'SUBMISSION_FILES', 'EDITOR_REVIEW_FILES', 'WORKFLOW_REVIEW_REVISIONS',
        'COPYEDITED_FILES', 'FINAL_DRAFT_FILES', 'PRODUCTION_READY_FILES',
    ]) {
        pkp.registry.storeExtend(`fileManager_${namespace}`, ({store}) => {
            store.extender.extendFn('getTopItems', (items) => rules(store.props.submission, store.props.submissionStageId).restrictFiles ? [] : items);
            store.extender.extendFn('getItemActions', (items) => rules(store.props.submission, store.props.submissionStageId).restrictFiles
                ? items.filter((item) => item.name === 'fileSeeNotes') : items);
        });
    }
})();
