{extends designs/site.tpl}

{block title}Push to Canvas &mdash; {$dwoo.parent}{/block}

{block content}
    <h1>Push to Canvas</h1>

    <h2>Input</h2>
    <h3>Run from template</h3>
    <ul>
        {foreach item=TemplateJob from=$templates}
            <li><a href="{$connectorBaseUrl}/synchronize/{$TemplateJob->Handle}" title="{$TemplateJob->Config|http_build_query|escape}">Job #{$TemplateJob->ID} &mdash; created by {$TemplateJob->Creator->Username} on {$TemplateJob->Created|date_format:'%c'}</a></li>
        {/foreach}
    </ul>

    <h3>Run or save a new job</h3>
    <form method="POST">
        <fieldset>
            <legend>Job Configuration</legend>
            <p>
                <label>
                    Pretend
                    <input type="checkbox" name="pretend" value="true" {refill field=pretend checked="true" default="true"}>
                </label>
                (Check to prevent saving any changes to the database)
            </p>
            <p>
                <label>
                    Create Template
                    <input type="checkbox" name="createTemplate" value="true" {refill field=createTemplate checked="true"}>
                </label>
                (Check to create a template job that can be repeated automatically instead of running it now)
            </p>
            <p>
                <label>
                    Email report
                    <input type="text" name="reportTo" {refill field=reportTo} length="100">
                </label>
                Email recipient or list of recipients to send post-sync report to
            </p>
        </fieldset>


        <fieldset>
            <legend>User Accounts</legend>
            <p>
                <label>
                    Push Users
                    <input type="checkbox" name="pushUsers" value="true" {refill field=pushUsers checked="true" default="false"}>
                </label>
                Check to push users to Canvas
            </p>
        </fieldset>


        <fieldset>
            <legend>Courses Sections & Enrollments</legend>
            <p>
                <label>
                    Push Sections
                    <input type="checkbox" name="pushSections" value="true" {refill field=pushSections checked="true" default="false"}>
                </label>
                Check to push sections
            </p>
            <hr>
            <p>
                <label>
                    Slate Master Term
                    <select name="masterTerm">
                        {foreach item=Term from=Slate\Term::getAllMaster()}
                            <option value="{$Term->Handle}" {refill field=masterTerm selected=$Term->Handle}>{$Term->Title|escape}</option>
                        {/foreach}
                    </select>
                    For sections and schedules, the school year to export
                </label>
            </p>
            <p>
                <label>
                    Canvas Term
                    {$termsData = RemoteSystems\Canvas::getTerms()}
                    <select name="canvasTerm">
                        <option value="">Don't set</option>
                        {foreach item=termData from=$termsData.enrollment_terms}
                            <option value="{$termData.id}" {refill field=canvasTerm selected=$termData.id}>{$termData.name|escape}</option>
                        {/foreach}
                    </select>
                    Canvas section to assign each section to
                </label>
            </p>
            <p>
                <label>
                    Include empty sections
                    <input type="checkbox" name="includeEmptySections" value="false" {refill field=includeEmptySections checked="true" default="false"}>
                </label>
                Check to include course sections in the push that don't have any students enrolled yet
            </p>
            <p>
                <label>
                    Sync Participant Enrollments
                    <input type="checkbox" name="syncParticiants" value="true" {refill field=syncParticiants checked="true" default="false"}>
                </label>
                Check to sync section teacher and student participant enrollments to Canvas
            </p>
            <p>
                <label>
                    &#x21B3; Conclude Enrollments past their end date
                    <input type="checkbox" name="concludeEndedEnrollments" value="true" {refill field=concludeEndedEnrollments checked="true" default="false"}>
                </label>
                Check to conclude student enrollments that have a past end date.
            </p>
            {*
            <p>
                <label>
                    &#x21B3; Remove Teachers
                    <input type="checkbox" name="removeTeachers" value="false" {refill field=removeTeachers checked="true" default="false"}>
                </label>
                Check to unenroll <strong>teachers</strong> from Canvas courses if they're no longer enrolled in Slate
            </p>
            *}
            {*
            <p>
                <label>
                    Sync Observer Enrollments
                    <input type="checkbox" name="syncObservers" value="true" {refill field=syncObservers checked="true" default="false"}>
                </label>
                Check to sync section parent and guardian observer enrollments to Canvas
            </p>
            *}
        </fieldset>

        <input type="submit" value="Synchronize">
    </form>
{/block}