<div class="pkp_controllers_query" data-cy="advisor-readonly-discussion">
    <h4>{translate key="editor.submission.stageParticipants"}</h4>
    <ul class="participants">
        {foreach from=$advisorQueryParticipants item=participant}
            <li>{$participant->getFullName()|escape}</li>
        {/foreach}
    </ul>
    <p>{translate key="plugins.generic.editorialAdvisor.readOnlyDiscussion"}</p>
    {capture assign=queryNotesGridUrl}{url router=PKP\core\PKPApplication::ROUTE_COMPONENT component=$queryNotesGridHandlerName op="fetchGrid" params=$requestArgs queryId=$query->id escape=false}{/capture}
    {load_url_in_div id="advisorQueryNotesGrid" url=$queryNotesGridUrl}
</div>
