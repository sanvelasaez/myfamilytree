<?php

declare(strict_types=1);

namespace ImprovedTree;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Http\RequestHandlers\AddNewFact;
use Fisharebest\Webtrees\Http\RequestHandlers\EditFactPage;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleBlockInterface;
use Fisharebest\Webtrees\Module\ModuleBlockTrait;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\SearchService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function redirect;
use function response;
use function route;
use function view;

final class ImprovedTreeModule extends AbstractModule implements
    ModuleCustomInterface,
    ModuleGlobalInterface,
    ModuleMenuInterface,
    ModuleBlockInterface,
    ModuleChartInterface,
    ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleGlobalTrait;
    use ModuleMenuTrait;
    use ModuleBlockTrait;
    use ModuleChartTrait;
    use ModuleConfigTrait;

    public const CUSTOM_TITLE = 'Improved Tree';
    public const CUSTOM_AUTHOR = 'sanvelas';
    public const CUSTOM_VERSION = '1.2.0';

    // Allowed values / ranges for admin preferences.
    private const ALLOWED_MODES = ['vertical'];
    private const MIN_DEPTH = 0;
    private const MAX_DEPTH = 25;
    private const MIN_LINK_LEVEL = 0;
    private const MAX_LINK_LEVEL = 8;
    private const DEFAULT_LINK_LEVEL = 4;
    private const MIN_MAX_NODES_GUEST_USER = 100;
    private const MAX_MAX_NODES_GUEST_USER = 2000;
    private const MIN_MAX_NODES_ADMIN = 100;
    private const MAX_MAX_NODES_ADMIN = 5000;

    // Server-side cache TTL (seconds) for the built graph JSON. The tree changes
    // rarely relative to how often it is viewed, so a short TTL removes almost all
    // rebuild cost while keeping staleness acceptable for a chart.
    private const GRAPH_CACHE_TTL = 600;

    private const PREFERENCE_DEFAULTS = [
        'default_mode' => 'vertical',
        'default_ancestor_depth' => '4',
        'default_descendant_depth' => '4',
        'default_include_spouses' => '1',
        'default_include_siblings' => '0',
        'default_link_level' => '4',
        'default_show_photos' => '1',
        'default_show_dates' => '1',
        'show_home_button' => '1',
        'max_nodes_guest' => '500',
        'max_nodes_user' => '1000',
        'max_nodes_admin' => '2000',
    ];

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
        return 'Advanced genealogical tree visualization with customizable layouts and privacy respecting.';
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function headContent(): string
    {
        return '<link rel="stylesheet" href="' . e($this->assetUrl('css/improved-tree.css')) . '">' .
            '<script src="' . e($this->assetUrl('js/improved-tree.js')) . '"></script>';
    }

    // Menu interface methods — puts an "access the tree" button on every page,
    // including the tree home page. Gated by the show_home_button preference.
    public function defaultMenuOrder(): int
    {
        return 1;
    }

    public function getMenu(Tree $tree): ?Menu
    {
        if ($this->preference('show_home_button') !== '1') {
            return null;
        }

        $url = route('module', [
            'module' => $this->name(),
            'action' => 'Tree',
            'tree'   => $tree->name(),
        ]);

        // The tree opens as an in-page overlay (no navigation). data-itree-modal-url
        // is picked up by the bundle; the href stays as a no-JS / deep-link fallback.
        $modal_url = route('module', [
            'module' => $this->name(),
            'action' => 'Modal',
            'tree'   => $tree->name(),
        ]);

        return new Menu(
            I18N::translate('Interactive tree'),
            $url,
            'menu-improved-tree',
            ['rel' => 'nofollow', 'data-itree-modal-url' => $modal_url]
        );
    }

    /**
     * Spanish labels for this module's own strings (webtrees only ships
     * translations for its core strings, not custom-module ones).
     *
     * @return array<string, string>
     */
    public function customTranslations(string $language): array
    {
        if (str_starts_with($language, 'es')) {
            return [
                'Interactive tree'                => 'Árbol interactivo',
                'Kinship level'                   => 'Nivel de parentesco',
                'Default kinship level'           => 'Nivel de parentesco por defecto',
                'When set, the tree shows everyone within this many kinship links, replacing the ancestor/descendant depths. Spouse links are free, so a couple always appears together.'
                                                  => 'Si se activa, el árbol muestra a todos dentro de estos enlaces de parentesco, sustituyendo las profundidades de ascendientes/descendientes. Los enlaces de cónyuge no cuentan, así que una pareja siempre aparece junta.',
                'Off (use depths)'                => 'Desactivado (usar profundidades)',
                'Level %s'                        => 'Nivel %s',
                'Ancestor depth'                  => 'Profundidad de ascendientes',
                'Descendant depth'                => 'Profundidad de descendientes',
                'Siblings'                        => 'Hermanos',
                'Parents'                         => 'Padres',
                'Spouses'                         => 'Cónyuges',
                'Children'                        => 'Hijos',
                'Photos'                          => 'Fotos',
                'Options'                         => 'Opciones',
                'Refresh'                         => 'Actualizar',
                'Back'                            => 'Atrás',
                'Forward'                         => 'Adelante',
                'History'                         => 'Historial',
                'Search individuals'              => 'Buscar personas',
                'Copy link'                       => 'Copiar enlace',
                'Zoom in'                         => 'Acercar',
                'Zoom out'                        => 'Alejar',
                'Download as PNG'                 => 'Descargar como PNG',
                'Download as SVG'                 => 'Descargar como SVG',
                'Print'                           => 'Imprimir',
                'Media'                           => 'Multimedia',
                'Previous'                        => 'Anterior',
                'Next'                            => 'Siguiente',
                'Open family tree'                => 'Abrir árbol genealógico',
                'Explore the tree of %s'          => 'Explora el árbol de %s',
                'Explore your family tree'        => 'Explora tu árbol genealógico',
                'Open the interactive tree on a full screen and navigate between all your relatives.'
                    => 'Abre el árbol interactivo a pantalla completa y navega entre todos tus familiares.',
                'Open the interactive tree on a full screen, rooted on this person. Pan, zoom and jump to any relative.'
                    => 'Abre el árbol interactivo a pantalla completa, con esta persona como raíz. Desplázate, haz zoom y salta a cualquier familiar.',
                'Home page'                       => 'Página de inicio',
                'Show a “Family tree” button in the main menu'
                    => 'Mostrar un botón de “Árbol genealógico” en el menú principal',
                'Adds a button to the main menu (shown on the home page and everywhere else) that opens the full-screen tree, rooted on the tree’s default individual.'
                    => 'Añade un botón al menú principal (visible en la página de inicio y en el resto) que abre el árbol a pantalla completa, con el individuo por defecto del árbol como raíz.',
            ];
        }

        return [];
    }

    // Block interface methods — lets users add an "open the tree" button to the
    // tree home page (and their own page) via "Customize this page".
    public function getBlock(Tree $tree, int $block_id, string $context, array $config = []): string
    {
        $content = view($this->name() . '::block', [
            'module'    => $this,
            'tree_url'  => route('module', [
                'module' => $this->name(),
                'action' => 'Tree',
                'tree'   => $tree->name(),
            ]),
            'modal_url' => route('module', [
                'module' => $this->name(),
                'action' => 'Modal',
                'tree'   => $tree->name(),
            ]),
        ]);

        if ($context !== self::CONTEXT_EMBED) {
            return view('modules/block-template', [
                'block'      => Str::kebab($this->name()),
                'id'         => $block_id,
                'config_url' => '',
                'title'      => I18N::translate('Interactive tree'),
                'content'    => $content,
            ]);
        }

        return $content;
    }

    public function isTreeBlock(): bool
    {
        return true;
    }

    public function isUserBlock(): bool
    {
        return true;
    }

    // Chart interface methods
    public function chartTitle(Individual $individual): string
    {
        return I18N::translate('Improved tree of %s', $individual->fullName());
    }

    public function chartUrl(Individual $individual, array $parameters = []): string
    {
        return route('module', [
            'module' => $this->name(),
            'action' => 'Chart',
            'tree'   => $individual->tree()->name(),
            'xref'   => $individual->xref(),
        ] + $parameters);
    }

    public function getChartAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $xref = Validator::queryParams($request)->isXref()->string('xref');

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual, false, true);

        return $this->treePageResponse($tree, $individual);
    }

    /**
     * Full-viewport tree page. Entry point for the home-page menu button and the
     * individual-page button. When no xref is given, roots the tree at the tree's
     * significant individual so it can be opened from the home page.
     */
    public function getTreeAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $xref       = Validator::queryParams($request)->string('xref', '');
        $individual = $tree->significantIndividual($user, $xref);
        $individual = Auth::checkIndividualAccess($individual, false, true);

        return $this->treePageResponse($tree, $individual);
    }

    /**
     * Render the shared full-viewport tree view rooted at the given individual,
     * as a standalone page (deep-link / no-JS fallback).
     */
    private function treePageResponse(Tree $tree, Individual $individual): ResponseInterface
    {
        return $this->viewResponse($this->name() . '::page', [
            'module' => $this,
            'title'  => $this->chartTitle($individual),
            'tree'   => $tree,
        ] + $this->shellData($tree, $individual));
    }

    /**
     * In-page overlay: returns just the tree shell (no layout) so the bundle can
     * inject it as a popup without a full page load. xref is optional — when
     * absent it roots at the tree's significant individual (home-page button).
     */
    public function getModalAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $xref       = Validator::queryParams($request)->string('xref', '');
        $individual = $tree->significantIndividual($user, $xref);
        $individual = Auth::checkIndividualAccess($individual, false, true);

        return response(view($this->name() . '::_tree-shell', $this->shellData($tree, $individual)));
    }

    /**
     * Data shared by the standalone page and the overlay: the graph endpoint,
     * the close/back target and the default control values.
     *
     * @return array<string, mixed>
     */
    /**
     * Default kinship level, falling back to a sensible radius when the admin
     * has never saved a preference (an explicit 0 = "off" is preserved).
     */
    private function defaultLinkLevel(): int
    {
        $pref = $this->preference('default_link_level');

        return $pref === '' ? self::DEFAULT_LINK_LEVEL : (int) $pref;
    }

    private function shellData(Tree $tree, Individual $individual): array
    {
        return [
            'individual' => $individual,
            'back_url'   => $individual->url(),
            'default_link_level'           => $this->defaultLinkLevel(),
            'default_ancestor_depth'       => (int) $this->preference('default_ancestor_depth'),
            'default_descendant_depth'     => (int) $this->preference('default_descendant_depth'),
            'default_include_spouses'      => $this->preference('default_include_spouses') === '1',
            'default_include_siblings'     => $this->preference('default_include_siblings') === '1',
            'default_show_photos'          => $this->preference('default_show_photos') === '1',
            'graph_url'  => route('module', [
                'module'  => $this->name(),
                'action'  => 'Graph',
                'tree'    => $tree->name(),
                'xref'    => $individual->xref(),
                'context' => 'chart',
            ]),
            'detail_url' => route('module', [
                'module'  => $this->name(),
                'action'  => 'Individual',
                'tree'    => $tree->name(),
                'xref'    => $individual->xref(),
            ]),
            'search_url' => route('module', [
                'module'  => $this->name(),
                'action'  => 'Search',
                'tree'    => $tree->name(),
            ]),
            'tree_url'   => route('module', [
                'module'  => $this->name(),
                'action'  => 'Tree',
                'tree'    => $tree->name(),
                'xref'    => $individual->xref(),
            ]),
        ];
    }

    public function getGraphAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $xref = Validator::queryParams($request)->isXref()->string('xref');
        $context = Validator::queryParams($request)->isInArray(['tab', 'chart'])->string('context', 'tab');

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual, false, true);

        $mode                  = Validator::queryParams($request)->string('mode', $this->preference('default_mode'));
        $ancestor_depth        = Validator::queryParams($request)->isBetween(self::MIN_DEPTH, self::MAX_DEPTH)->integer('ancestor_depth', (int) $this->preference('default_ancestor_depth'));
        $descendant_depth      = Validator::queryParams($request)->isBetween(self::MIN_DEPTH, self::MAX_DEPTH)->integer('descendant_depth', (int) $this->preference('default_descendant_depth'));
        $include_spouses       = Validator::queryParams($request)->boolean('include_spouses', (bool) (int) $this->preference('default_include_spouses'));
        $include_siblings      = Validator::queryParams($request)->boolean('include_siblings', (bool) (int) $this->preference('default_include_siblings'));
        $link_level            = Validator::queryParams($request)->isBetween(self::MIN_LINK_LEVEL, self::MAX_LINK_LEVEL)->integer('link_level', $this->defaultLinkLevel());
        $show_photos           = Validator::queryParams($request)->boolean('show_photos', (bool) (int) $this->preference('default_show_photos'));
        $show_dates            = Validator::queryParams($request)->boolean('show_dates', (bool) (int) $this->preference('default_show_dates'));
        $requested_limit       = Validator::queryParams($request)->integer('limit', $this->maxNodesForUser($user));
        $limit                 = min($requested_limit, $this->maxNodesForUser($user));

        // Cache the built graph. The key covers everything that changes the output,
        // including the viewer's access level (privacy is applied while building),
        // so entries are safely shared between users at the same access level.
        $cache_key = implode('|', [
            'improved-tree-graph',
            self::CUSTOM_VERSION,
            $tree->id(),
            $individual->xref(),
            $mode,
            $ancestor_depth,
            $descendant_depth,
            (int) $include_spouses,
            (int) $include_siblings,
            $link_level,
            (int) $show_photos,
            (int) $show_dates,
            $limit,
            Auth::accessLevel($tree, $user),
        ]);

        $graph = Registry::cache()->file()->remember(
            $cache_key,
            static function () use (
                $individual,
                $ancestor_depth,
                $descendant_depth,
                $include_spouses,
                $include_siblings,
                $link_level,
                $show_photos,
                $show_dates,
                $limit
            ): array {
                $builder = new GraphBuilder();

                if ($link_level > 0) {
                    return $builder->buildGraphByLinks(
                        $individual,
                        $link_level,
                        $include_spouses,
                        $limit,
                        $show_photos,
                        $show_dates
                    );
                }

                return $builder->buildGraph(
                    $individual,
                    $ancestor_depth,
                    $descendant_depth,
                    $include_spouses,
                    $include_siblings,
                    $limit,
                    $show_photos,
                    $show_dates
                );
            },
            self::GRAPH_CACHE_TTL
        );

        // Let the browser reuse the JSON across navigations too (private: never on
        // a shared proxy, since the payload is privacy-filtered per access level).
        return response($graph)
            ->withHeader('Cache-Control', 'private, max-age=' . self::GRAPH_CACHE_TTL);
    }

    /**
     * Lightweight per-individual detail, fetched on demand by the overlay's side
     * panel as the user navigates. Kept out of the graph JSON so selecting a node
     * never blocks on the whole tree being rebuilt. Privacy-filtered and cached
     * per access level, exactly like the graph.
     */
    public function getIndividualAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $xref = Validator::queryParams($request)->isXref()->string('xref');

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual, false, true);

        $cache_key = implode('|', [
            'improved-tree-detail',
            self::CUSTOM_VERSION,
            $tree->id(),
            $individual->xref(),
            Auth::accessLevel($tree, $user),
        ]);

        $detail = Registry::cache()->file()->remember(
            $cache_key,
            fn (): array => $this->buildIndividualDetail($individual),
            self::GRAPH_CACHE_TTL
        );

        return response($detail)
            ->withHeader('Cache-Control', 'private, max-age=' . self::GRAPH_CACHE_TTL);
    }

    /**
     * Name search for the overlay's search box. Matches every whitespace-separated
     * word against the full name (given names AND surnames), so "ana" or "fulano"
     * both hit. Privacy is enforced by the search service's access filter and a
     * final canShowName check; results are a compact stub the panel can jump to.
     */
    public function getSearchAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $q = trim(Validator::queryParams($request)->string('q', ''));

        if (mb_strlen($q) < 2) {
            return response([]);
        }

        $words = preg_split('/\s+/', $q) ?: [$q];

        $search      = Registry::container()->get(SearchService::class);
        $individuals = $search->searchIndividualNames([$tree], $words, 0, 15);

        $results = [];
        foreach ($individuals as $individual) {
            if (!$individual->canShowName()) {
                continue;
            }
            $results[] = [
                'xref'     => $individual->xref(),
                'name'     => strip_tags($individual->fullName()),
                'sex'      => $individual->sex(),
                'lifespan' => $this->lifespan($individual),
            ];
        }

        return response($results);
    }

    /**
     * Assemble the side-panel payload for one individual. Privacy first: only the
     * name is exposed when the record is name-visible-only; facts and relatives are
     * built solely when the viewer may see the record.
     *
     * @return array<string, mixed>
     */
    private function buildIndividualDetail(Individual $individual): array
    {
        $can_show_name = $individual->canShowName();
        $can_show      = $individual->canShow();

        if (!$can_show_name) {
            return [
                'xref'   => $individual->xref(),
                'name'   => '?',
                'sex'    => $individual->sex(),
                'canShow' => false,
                'facts'  => [],
                'groups' => [],
            ];
        }

        $detail = [
            'xref'      => $individual->xref(),
            'name'      => strip_tags($individual->fullName()),
            'sex'       => $individual->sex(),
            'lifespan'  => $this->lifespan($individual),
            'url'       => $individual->url(),
            'thumbnail' => $this->portrait($individual),
            'canShow'   => $can_show,
            'facts'     => [],
            'groups'    => [],
        ];

        if (!$can_show) {
            return $detail;
        }

        $can_edit = $individual->canEdit();

        $detail['canEdit']    = $can_edit;
        $detail['facts']      = $this->buildFacts($individual, $can_edit);
        $detail['timeline']   = $this->buildTimeline($individual, $can_edit);
        $detail['attributes'] = $this->buildAttributes($individual, $can_edit);
        $detail['media']      = $this->buildMedia($individual);
        $detail['groups']     = [
            ['label' => I18N::translate('Parents'), 'people' => $this->parents($individual)],
            ['label' => I18N::translate('Spouses'), 'people' => $this->spouses($individual)],
            ['label' => I18N::translate('Children'), 'people' => $this->children($individual)],
            ['label' => I18N::translate('Siblings'), 'people' => $this->siblings($individual)],
        ];

        if ($can_edit) {
            $detail['addEventUrl']     = $this->factAddUrl($individual, 'EVEN');
            $detail['addAttributeUrl'] = $this->factAddUrl($individual, 'FACT');
        }

        return $detail;
    }

    /**
     * Structural / meta tags that never belong in the timeline or attribute
     * lists (names, pointers, media, sources, notes, bookkeeping).
     */
    private const NON_EVENT_TAGS = [
        'NAME', 'SEX', 'FAMC', 'FAMS', 'OBJE', 'SOUR', 'NOTE', 'CHAN',
        'RESN', 'SUBM', 'RFN', 'AFN', 'REFN', '_UID',
    ];

    /**
     * The individual's dated events in chronological order, for the timeline
     * section. Each row is {label, date, place, editUrl?}. This is the full
     * life chronology (birth, death and everything in between).
     *
     * @return array<int, array<string, string>>
     */
    private function buildTimeline(Individual $individual, bool $can_edit): array
    {
        $events = [];

        foreach ($individual->facts([], true) as $fact) {
            if (in_array($this->factTag($fact), self::NON_EVENT_TAGS, true)) {
                continue;
            }
            if (!$fact->date()->isOK()) {
                continue;
            }

            $row = [
                'label' => strip_tags($fact->label()),
                'date'  => strip_tags($fact->date()->display()),
                'place' => $fact->place()->gedcomName(),
            ];
            if ($can_edit) {
                $row['editUrl'] = $this->factEditUrl($individual, $fact->id());
            }
            $events[] = $row;
        }

        return $events;
    }

    /**
     * The individual's undated attributes (occupation, residence, education,
     * titles, etc.) that carry a value — the "more info" section so the user
     * need not open the full record. Each row is {label, value, place, editUrl?}.
     *
     * @return array<int, array<string, string>>
     */
    private function buildAttributes(Individual $individual, bool $can_edit): array
    {
        $rows = [];

        foreach ($individual->facts([], true) as $fact) {
            if (in_array($this->factTag($fact), self::NON_EVENT_TAGS, true)) {
                continue;
            }
            if ($fact->date()->isOK()) {
                continue;
            }

            $value = strip_tags($fact->value());
            $place = $fact->place()->gedcomName();

            if ($value === '' && $place === '') {
                continue;
            }

            $row = [
                'label' => strip_tags($fact->label()),
                'value' => $value,
                'place' => $place,
            ];
            if ($can_edit) {
                $row['editUrl'] = $this->factEditUrl($individual, $fact->id());
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The bare GEDCOM subtag of a fact (e.g. "OCCU" from "INDI:OCCU").
     */
    private function factTag(Fact $fact): string
    {
        $parts = explode(':', $fact->tag());

        return (string) end($parts);
    }

    private function factEditUrl(Individual $individual, string $fact_id): string
    {
        return route(EditFactPage::class, [
            'tree'    => $individual->tree()->name(),
            'xref'    => $individual->xref(),
            'fact_id' => $fact_id,
        ]);
    }

    private function factAddUrl(Individual $individual, string $subtag): string
    {
        return route(AddNewFact::class, [
            'tree' => $individual->tree()->name(),
            'xref' => $individual->xref(),
            'fact' => $subtag,
        ]);
    }

    /**
     * A curated set of life events, each as {label, value, place}.
     *
     * @return array<int, array<string, string>>
     */
    private function buildFacts(Individual $individual, bool $can_edit = false): array
    {
        $facts = [];

        foreach ($individual->facts(['BIRT', 'CHR', 'DEAT', 'BURI', 'OCCU', 'RESI'], true) as $fact) {
            $value = strip_tags($fact->date()->display());
            $place = $fact->place()->gedcomName();

            if ($value === '' && $place === '') {
                continue;
            }

            $row = [
                'label' => strip_tags($fact->label()),
                'value' => $value,
                'place' => $place,
            ];
            if ($can_edit) {
                $row['editUrl'] = $this->factEditUrl($individual, $fact->id());
            }
            $facts[] = $row;
        }

        return $facts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parents(Individual $individual): array
    {
        $people = [];
        foreach ($individual->childFamilies() as $family) {
            foreach ($family->spouses() as $parent) {
                $people[$parent->xref()] = $this->relativeStub($parent);
            }
        }

        return array_values($people);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function spouses(Individual $individual): array
    {
        $people = [];
        foreach ($individual->spouseFamilies() as $family) {
            foreach ($family->spouses() as $spouse) {
                if ($spouse->xref() === $individual->xref()) {
                    continue;
                }
                $people[$spouse->xref()] = $this->relativeStub($spouse);
            }
        }

        return array_values($people);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function children(Individual $individual): array
    {
        $people = [];
        foreach ($individual->spouseFamilies() as $family) {
            foreach ($family->children() as $child) {
                $people[$child->xref()] = $this->relativeStub($child);
            }
        }

        return array_values($people);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function siblings(Individual $individual): array
    {
        $people = [];
        foreach ($individual->childFamilies() as $family) {
            foreach ($family->children() as $sibling) {
                if ($sibling->xref() === $individual->xref()) {
                    continue;
                }
                $people[$sibling->xref()] = $this->relativeStub($sibling);
            }
        }

        return array_values($people);
    }

    /**
     * A compact, privacy-aware reference to a related individual for the panel's
     * relative lists. Non-name-visible people become a non-clickable "?".
     *
     * @return array<string, mixed>
     */
    private function relativeStub(Individual $individual): array
    {
        if (!$individual->canShowName()) {
            return [
                'xref'      => null,
                'name'      => '?',
                'sex'       => 'U',
                'lifespan'  => null,
                'url'       => null,
                'clickable' => false,
            ];
        }

        return [
            'xref'      => $individual->xref(),
            'name'      => strip_tags($individual->fullName()),
            'sex'       => $individual->sex(),
            'lifespan'  => $this->lifespan($individual),
            'url'       => $individual->url(),
            'clickable' => true,
        ];
    }

    private function lifespan(Individual $individual): ?string
    {
        $birth_year = $individual->getBirthDate()->minimumDate()->year ?: '';
        $death_year = $individual->getDeathDate()->minimumDate()->year ?: '';

        if ($birth_year || $death_year) {
            return trim($birth_year . '-' . $death_year, '-');
        }

        return null;
    }

    private function portrait(Individual $individual): ?string
    {
        foreach ($individual->facts(['OBJE']) as $fact) {
            $media = $fact->target();
            if ($media !== null && $media->canShow()) {
                foreach ($media->mediaFiles() as $media_file) {
                    return $media_file->imageUrl(240, 240, 'crop');
                }
            }
        }

        return null;
    }

    /**
     * All displayable image media of the individual, each as {thumb, full, title}.
     * `thumb` feeds the panel gallery grid; `full` the lightbox viewer.
     *
     * @return array<int, array<string, string>>
     */
    private function buildMedia(Individual $individual): array
    {
        $items = [];

        foreach ($individual->facts(['OBJE']) as $fact) {
            $media = $fact->target();
            if ($media === null || !$media->canShow()) {
                continue;
            }
            foreach ($media->mediaFiles() as $media_file) {
                if (!$media_file->isImage()) {
                    continue;
                }
                $items[] = [
                    'thumb' => $media_file->imageUrl(160, 160, 'crop'),
                    'full'  => $media_file->imageUrl(1200, 1200, 'contain'),
                    'title' => strip_tags($media->fullName()),
                ];
            }
        }

        return $items;
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::admin', [
            'module'      => $this,
            'title'       => $this->title(),
            'preferences' => $this->allPreferences(),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = Validator::parsedBody($request);

        $this->setPreference('default_mode', $params->isInArray(self::ALLOWED_MODES)->string('default_mode', 'vertical'));
        $this->setPreference('default_ancestor_depth', (string) $params->isBetween(self::MIN_DEPTH, self::MAX_DEPTH)->integer('default_ancestor_depth', 4));
        $this->setPreference('default_descendant_depth', (string) $params->isBetween(self::MIN_DEPTH, self::MAX_DEPTH)->integer('default_descendant_depth', 4));
        $this->setPreference('default_include_spouses', $params->boolean('default_include_spouses', false) ? '1' : '0');
        $this->setPreference('default_include_siblings', $params->boolean('default_include_siblings', false) ? '1' : '0');
        $this->setPreference('default_link_level', (string) $params->isBetween(self::MIN_LINK_LEVEL, self::MAX_LINK_LEVEL)->integer('default_link_level', self::DEFAULT_LINK_LEVEL));
        $this->setPreference('default_show_photos', $params->boolean('default_show_photos', false) ? '1' : '0');
        $this->setPreference('default_show_dates', $params->boolean('default_show_dates', false) ? '1' : '0');
        $this->setPreference('show_home_button', $params->boolean('show_home_button', false) ? '1' : '0');
        $this->setPreference('max_nodes_guest', (string) $params->isBetween(self::MIN_MAX_NODES_GUEST_USER, self::MAX_MAX_NODES_GUEST_USER)->integer('max_nodes_guest', 500));
        $this->setPreference('max_nodes_user', (string) $params->isBetween(self::MIN_MAX_NODES_GUEST_USER, self::MAX_MAX_NODES_GUEST_USER)->integer('max_nodes_user', 1000));
        $this->setPreference('max_nodes_admin', (string) $params->isBetween(self::MIN_MAX_NODES_ADMIN, self::MAX_MAX_NODES_ADMIN)->integer('max_nodes_admin', 2000));

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    /**
     * Read a preference, falling back to this module's documented default.
     */
    private function preference(string $key): string
    {
        return $this->getPreference($key, self::PREFERENCE_DEFAULTS[$key]);
    }

    /**
     * @return array<string, string>
     */
    private function allPreferences(): array
    {
        $values = [];
        foreach (self::PREFERENCE_DEFAULTS as $key => $default) {
            $values[$key] = $this->getPreference($key, $default);
        }

        return $values;
    }

    /**
     * Maximum nodes a request may return, based on the requester's role.
     * Applied server-side regardless of what the client asks for.
     */
    private function maxNodesForUser($user): int
    {
        if (Auth::isAdmin($user)) {
            return (int) $this->preference('max_nodes_admin');
        }

        if ($user->id() !== 0) {
            return (int) $this->preference('max_nodes_user');
        }

        return (int) $this->preference('max_nodes_guest');
    }
}
