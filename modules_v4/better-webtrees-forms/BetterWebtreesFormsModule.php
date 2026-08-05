<?php

declare(strict_types=1);

namespace BetterWebtreesForms;

use BetterWebtreesForms\RequestHandlers\AddChildToIndividualFragment;
use BetterWebtreesForms\RequestHandlers\AddFactFragment;
use BetterWebtreesForms\RequestHandlers\AddParentToIndividualFragment;
use BetterWebtreesForms\RequestHandlers\AddSpouseToIndividualFragment;
use BetterWebtreesForms\RequestHandlers\EditFactFragment;
use BetterWebtreesForms\RequestHandlers\EditRecordFragment;
use Fisharebest\Webtrees\Http\Middleware\AuthEditor;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Registry;

use function e;

/**
 * Better Webtrees Forms.
 *
 * Reemplaza los formularios de edición/creación de individuos (que en webtrees
 * navegan a una página completa) por popups AJAX. No modifica el core: se
 * limita a inyectar un CSS + JS global en todas las páginas mediante
 * ModuleGlobalInterface. Toda la lógica de interceptación vive en el bundle JS.
 */
final class BetterWebtreesFormsModule extends AbstractModule implements
    ModuleCustomInterface,
    ModuleGlobalInterface
{
    use ModuleCustomTrait;
    use ModuleGlobalTrait;

    public const CUSTOM_TITLE   = 'Better Webtrees Forms';
    public const CUSTOM_AUTHOR  = 'sanvelas';
    public const CUSTOM_VERSION = '1.0.0';

    /**
     * Registra rutas GET "gemelas" de los endpoints de formulario de core, pero
     * con prefijo `bwf-` y renderizadas con `layouts/ajax` (solo el fragmento del
     * <form>, sin el chrome del layout → mucho más rápido). El POST de guardado
     * sigue yendo a los *Action de core (lo pone la propia vista). boot() lo
     * invoca webtrees en cada ModuleCustomInterface.
     */
    public function boot(): void
    {
        $route_map = Registry::routeFactory()->routeMap();

        $routes = [
            [EditFactFragment::class, '/tree/{tree}/bwf-edit-fact/{xref}/{fact_id}'],
            [AddFactFragment::class, '/tree/{tree}/bwf-add-fact/{xref}/{fact}'],
            [EditRecordFragment::class, '/tree/{tree}/bwf-edit-record/{xref}'],
            [AddChildToIndividualFragment::class, '/tree/{tree}/bwf-add-child-to-individual/{xref}'],
            [AddParentToIndividualFragment::class, '/tree/{tree}/bwf-add-parent-to-individual/{xref}/{sex}'],
            [AddSpouseToIndividualFragment::class, '/tree/{tree}/bwf-add-spouse-to-individual/{xref}'],
        ];

        foreach ($routes as [$handler, $url]) {
            $route_map->get($handler, $url, $handler)
                ->extras(['middleware' => [AuthEditor::class]]);
        }
    }

    public function customModuleAuthorName(): string
    {
        return self::CUSTOM_AUTHOR;
    }

    public function customModuleVersion(): string
    {
        return self::CUSTOM_VERSION;
    }

    public function title(): string
    {
        return self::CUSTOM_TITLE;
    }

    public function description(): string
    {
        return I18N::translate('Replaces individual edit/create forms with non-blocking AJAX popups.');
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    /**
     * Assets globales en el <head>. El bundle difiere su arranque a
     * DOMContentLoaded, así que es seguro cargarlo aquí. assetUrl() añade un
     * cache-buster basado en filemtime.
     */
    public function headContent(): string
    {
        return '<link rel="stylesheet" href="' . e($this->assetUrl('css/better-webtrees-forms.css')) . '">'
            . '<script src="' . e($this->assetUrl('js/better-webtrees-forms.js')) . '"></script>';
    }

    /**
     * Etiquetas propias del módulo en español (webtrees solo traduce sus
     * cadenas de core, no las de módulos custom).
     *
     * @return array<string, string>
     */
    public function customTranslations(string $language): array
    {
        if (str_starts_with($language, 'es')) {
            return [
                'Replaces individual edit/create forms with non-blocking AJAX popups.'
                    => 'Sustituye los formularios de edición/creación de individuos por popups AJAX sin bloqueo.',
            ];
        }

        return [];
    }
}
