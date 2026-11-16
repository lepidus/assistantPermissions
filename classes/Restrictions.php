<?php

namespace APP\plugins\generic\assistantPermissions\classes;

/** Rules shared by the legacy component handlers and the REST API. */
class Restrictions
{
    public function __construct(public bool $recommendOnly, public bool $blockContact, public bool $restrictFiles = false)
    {
    }

    public function allowsComponent(string $component, string $operation): bool
    {
        $component = strtolower($component);
        $operation = strtolower($operation);
        if ($this->recommendOnly && str_contains($component, 'reviewer')) {
            return in_array($operation, ['fetchgrid', 'fetchrow', 'readreview', 'reviewhistory'], true);
        }

        if ($this->blockContact && str_contains($component, 'reviewer')
            && in_array($operation, [
                'sendemail', 'thankreviewer', 'editthankreviewer', 'sendreminder', 'editreminder',
                'resendrequestreviewer', 'updateresendrequestreviewer', 'updatereviewer',
                'showreviewerform', 'reloadreviewerform', 'editreview', 'updatereview',
                'unassignreviewer', 'updateunassignreviewer', 'reinstatereviewer', 'updatereinstatereviewer',
            ], true)) {
            return false;
        }

        if ($this->recommendOnly && str_contains($component, 'stageparticipant')
            && in_array($operation, ['saveparticipant', 'deleteparticipant', 'addparticipant'], true)) {
            return false;
        }

        if ($this->restrictFiles && str_contains($component, 'file')) {
            return in_array($operation, [
                'fetchgrid', 'fetchrow', 'fetch', 'viewfile', 'downloadfile', 'downloadallfiles',
                'downloadlibraryfile', 'recorddownload', 'enablelinkaction',
                'viewinformationcenter', 'viewhistory', 'viewnotes', 'listnotes',
            ], true);
        }

        if ($this->recommendOnly && (str_contains($component, 'decision')
            || str_contains($component, 'reviewround'))) {
            return in_array($operation, ['fetchgrid', 'fetchrow', 'fetch'], true);
        }

        return true;
    }

    public function allowsApi(string $method, string $path): bool
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $path = strtolower($path);
        if ($this->restrictFiles && preg_match('~(?:^|/)submissions/\d+/files(?:/|$)~', $path)) {
            return false;
        }

        if ($this->blockContact && preg_match('~(?:^|/)(_?emails?|messages?|reviews?|reviewers?|reviewassignments)(?:/|$)~', $path)) {
            return false;
        }

        if ($this->recommendOnly && preg_match('~(?:^|/)(decisions?|reviews?|reviewers?|reviewassignments|reviewrounds)(?:/|$)~', $path)) {
            return false;
        }

        if ($this->recommendOnly && preg_match('~/submissions/\d+(?:/publications/\d+/(?:publish|unpublish))?/?$~', '/' . ltrim($path, '/'))) {
            return false;
        }

        return !(strtoupper($method) === 'DELETE'
            && (($this->restrictFiles && str_contains($path, 'file'))
                || ($this->recommendOnly && !preg_match('~(?:^|/)files(?:/|$)~', $path)
                    && (str_contains($path, 'publication') || str_contains($path, 'submission')))));
    }
}
