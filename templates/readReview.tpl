<div class="pkp_form" data-cy="assistantPermissions-read-review">
    <h2>{$reviewAssignment->getReviewerFullName()|escape}</h2>
    {if $reviewAssignment->getRecommendation()}
        <p>{$assistantPermissionsReviewerRecommendation|escape}</p>
    {/if}
    {if $reviewAssignment->getCompetingInterests()}
        <h3>{translate key="reviewer.submission.competingInterests"}</h3>
        <div>{$reviewAssignment->getCompetingInterests()|strip_unsafe_html}</div>
    {/if}
    {if $reviewAssignment->getReviewFormId()}
        {include file="reviewer/review/reviewFormResponse.tpl"}
    {else}
        {iterate from=comments item=comment}
            <h3>{translate key="submission.comments.canShareWithAuthor"}</h3>
            <div>{$comment->getComments()|strip_unsafe_html}</div>
        {/iterate}
        {iterate from=commentsPrivate item=comment}
            <h3>{translate key="submission.comments.cannotShareWithAuthor"}</h3>
            <div>{$comment->getComments()|strip_unsafe_html}</div>
        {/iterate}
    {/if}
    <h3>{translate key="reviewer.submission.reviewerFiles"}</h3>
    {capture assign=reviewAttachmentsGridUrl}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component="grid.files.attachment.EditorReviewAttachmentsGridHandler" op="fetchGrid" submissionId=$submission->getId() reviewId=$reviewAssignment->getId() stageId=$reviewAssignment->getStageId() escape=false}{/capture}
    {load_url_in_div id="assistantPermissionsReviewAttachmentsGrid" url=$reviewAttachmentsGridUrl}
</div>
