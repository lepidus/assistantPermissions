/* OMP 3.5 exposes Vue and the supported store extension API through pkp. */
(() => {
    const {h, ref, unref} = pkp.modules.vue;
    const {useLocalize} = pkp.modules.useLocalize;
    const {useUrl} = pkp.modules.useUrl;
    const {useDataChanged} = pkp.modules.useDataChanged;

    pkp.registry.registerComponent('EditorialAdvisorRecommendation', {
        props: {submission: {type: Object, required: true}},
        setup(props) {
            const {t} = useLocalize();
            const {triggerDataChange} = useDataChanged();
            const decision = ref('');
            const comment = ref('');
            const busy = ref(false);
            const message = ref('');
            const failed = ref(false);
            const {apiUrl} = useUrl(`plugins/editorial-advisor/${props.submission.id}/recommendations`);
            async function save(event) {
                event.preventDefault();
                busy.value = true;
                message.value = '';
                try {
                    const response = await fetch(unref(apiUrl), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': pkp.currentUser.csrfToken,
                        },
                        body: JSON.stringify({
                            decision: Number(decision.value),
                            stageId: props.submission.stageId,
                            reviewRoundId: props.submission.editorialAdvisor.reviewRoundId,
                            comment: comment.value,
                        }),
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || t('plugins.generic.editorialAdvisor.failed'));
                    failed.value = false;
                    message.value = t('plugins.generic.editorialAdvisor.saved');
                    comment.value = '';
                    triggerDataChange();
                } catch (error) {
                    failed.value = true;
                    message.value = error.message;
                } finally {
                    busy.value = false;
                }
            }
            return () => h('section', {class: 'pkpForm', 'data-cy': 'advisor-recommendation'}, [
                h('h2', t('plugins.generic.editorialAdvisor.recommend')),
                h('form', {onSubmit: save}, [
                    h('label', {class: 'block'}, [
                        t('plugins.generic.editorialAdvisor.recommendation'),
                        h('select', {
                            value: decision.value, required: true, disabled: busy.value,
                            onChange: (event) => { decision.value = event.target.value; },
                        }, [h('option', {value: ''}, t('common.select')), ...props.submission.editorialAdvisor.recommendations.map(
                            (option) => h('option', {value: option.value}, option.label)
                        )]),
                    ]),
                    h('label', {class: 'block'}, [
                        t('plugins.generic.editorialAdvisor.comment'),
                        h('textarea', {
                            value: comment.value, required: true, maxlength: 20000, rows: 5,
                            disabled: busy.value, class: 'w-full',
                            onInput: (event) => { comment.value = event.target.value; },
                        }),
                    ]),
                    h('button', {type: 'submit', disabled: busy.value, class: 'pkpButton'}, t('common.save')),
                    message.value ? h('p', {role: failed.value ? 'alert' : 'status'}, message.value) : null,
                ]),
            ]);
        },
    });

    const rules = (submission, stageId = submission?.stageId) => submission?.editorialAdvisor?.stages?.[stageId] || submission?.editorialAdvisor || {};
    pkp.registry.storeExtend('workflow', ({store}) => {
        store.extender.extendFn('getActionItems', (items, args) => rules(args.submission, args.selectedMenuState?.stageId).recommendOnly ? [] : items);
        store.extender.extendFn('getPrimaryItems', (items, args) => {
            const config = args.submission?.editorialAdvisor || {};
            if (!config.recommendations?.length || !config.reviewRoundId
                || args.selectedMenuState?.primaryMenuItem !== 'workflow'
                || args.selectedMenuState.stageId !== args.submission.stageId
                || Number(args.selectedMenuState.reviewRoundId) !== Number(config.reviewRoundId)) return items;
            return [...items, {component: 'EditorialAdvisorRecommendation', props: {submission: args.submission}}];
        });
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
            return items.filter((item) => !(config.blockContact && item.name === 'participantNotify')
                && !(config.recommendOnly && ['participantEdit', 'participantRemove', 'participantLoginAs'].includes(item.name)));
        });
    });
    pkp.registry.storeExtend('fileManager', ({store}) => {
        store.extender.extendFn('getItemActions', (items) => rules(store.props.submission, store.props.submissionStageId).recommendOnly
            ? items.filter((item) => item.name !== 'fileDelete') : items);
    });
})();
