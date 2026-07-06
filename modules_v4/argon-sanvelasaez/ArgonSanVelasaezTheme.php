<?php

declare(strict_types=1);

namespace SanVelasaez\Themes\Argon;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\MinimalTheme;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\View;

class ArgonSanVelasaezTheme extends MinimalTheme implements ModuleCustomInterface, ModuleGlobalInterface
{
    use ModuleCustomTrait;
    use ModuleGlobalTrait;

    public function title(): string
    {
        return I18N::translate('Argon');
    }

    public function bootstrapColorScheme(): string
    {
        return 'light';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        // Global
        View::registerCustomView('::layouts/default', $this->name() . '::layouts/default');
        View::registerCustomView('::modules/contact-links/footer', $this->name() . '::modules/contact-links/footer');
        View::registerCustomView('::modules/hit-counter/footer', $this->name() . '::modules/hit-counter/footer');
        View::registerCustomView('::modules/powered-by-webtrees/footer', $this->name() . '::modules/powered-by-webtrees/footer');
        View::registerCustomView('::modules/privacy-policy/footer', $this->name() . '::modules/privacy-policy/footer');

        // Blocks
        View::registerCustomView('::modules/gedcom_stats/statistics', $this->name() . '::modules/gedcom_stats/statistics');
        View::registerCustomView('::modules/todo/research-tasks', $this->name() . '::modules/todo/research-tasks');
        View::registerCustomView('::modules/recent_changes/changes-list', $this->name() . '::modules/recent_changes/changes-list');

        // Individual page
        View::registerCustomView('::individual-page-images', $this->name() . '::individual-page-images');
        View::registerCustomView('::individual-page-tabs', $this->name() . '::individual-page-tabs');
        View::registerCustomView('::modules/relatives/family', $this->name() . '::modules/relatives/family');
        View::registerCustomView('::modules/stories/tab', $this->name() . '::modules/stories/tab');
        View::registerCustomView('::modules/lightbox/tab', $this->name() . '::modules/lightbox/tab');
        View::registerCustomView('::modules/places/tab', $this->name() . '::modules/places/tab');
        View::registerCustomView('::modules/descendancy/sidebar', $this->name() . '::modules/descendancy/sidebar');

        // Charts
        View::registerCustomView('::modules/interactive-tree/chart', $this->name() . '::modules/interactive-tree/chart');
        View::registerCustomView('::chart-box', $this->name() . '::chart-box');
        View::registerCustomView('::modules/lifespans-chart/chart', $this->name() . '::modules/lifespans-chart/chart');
        View::registerCustomView('::modules/pedigree-map/chart', $this->name() . '::modules/pedigree-map/chart');
        View::registerCustomView('::modules/statistics-chart/page', $this->name() . '::modules/statistics-chart/page');

        // FAQ
        View::registerCustomView('::modules/faq/show', $this->name() . '::modules/faq/show');

        // Lists
        View::registerCustomView('::lists/surnames-table', $this->name() . '::lists/surnames-table');
        View::registerCustomView('::modules/media-list/page', $this->name() . '::modules/media-list/page');
        View::registerCustomView('::modules/place-hierarchy/page', $this->name() . '::modules/place-hierarchy/page');
        View::registerCustomView('::place-hierarchy', $this->name() . '::place-hierarchy');
        View::registerCustomView('::modules/place-hierarchy/map', $this->name() . '::modules/place-hierarchy/map');
        View::registerCustomView('::modules/place-hierarchy/sidebar', $this->name() . '::modules/place-hierarchy/sidebar');
        View::registerCustomView('::modules/place-hierarchy/popup', $this->name() . '::modules/place-hierarchy/popup');
        View::registerCustomView('::modules/place-hierarchy/list', $this->name() . '::modules/place-hierarchy/list');
        View::registerCustomView('::lists/repositories-table', $this->name() . '::lists/repositories-table');
        View::registerCustomView('::lists/notes-table', $this->name() . '::lists/notes-table');
        View::registerCustomView('::lists/sources-table', $this->name() . '::lists/sources-table');

        // Calendar
        View::registerCustomView('::calendar-page', $this->name() . '::calendar-page');
        View::registerCustomView('::calendar-list', $this->name() . '::calendar-list');
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function customModuleAuthorName(): string
    {
        return 'sanvelasaez (based on jchue/argon-webtrees-theme)';
    }

    public function customModuleVersion(): string
    {
        return '1.0.0';
    }

    public function customModuleLatestVersionUrl(): string
    {
        return '';
    }

    public function customModuleSupportUrl(): string
    {
        return '';
    }

    public function stylesheets(): array
    {
        return [
            $this->assetUrl('css/vendor.css'),
            $this->assetUrl('css/theme.css'),
        ];
    }

    public function bodyContent(): string
    {
        return '<script src="' . $this->assetUrl('js/theme.js') . '"></script>';
    }
}
