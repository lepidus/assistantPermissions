<?php

/**
 * Editorial advisor permissions for OMP.
 * Derived from PKP pluginTemplate (4f6d1d4), distributed under GNU GPL v3.
 */

namespace APP\plugins\generic\editorialAdvisor;

use APP\core\Application;
use PKP\plugins\GenericPlugin;

class EditorialAdvisorPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null): bool
    {
        return parent::register($category, $path, $mainContextId);
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.editorialAdvisor.name');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.editorialAdvisor.description');
    }
}
