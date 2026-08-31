<?php

declare(strict_types=1);

namespace ImprovedTree;

use Fisharebest\Algorithm\Dijkstra;
use Fisharebest\Webtrees\Age;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Http\RequestHandlers\AddChildToFamilyAction;
use Fisharebest\Webtrees\Http\RequestHandlers\AddChildToIndividualAction;
use Fisharebest\Webtrees\Http\RequestHandlers\AddParentToIndividualAction;
use Fisharebest\Webtrees\Http\RequestHandlers\AddSpouseToFamilyAction;
use Fisharebest\Webtrees\Http\RequestHandlers\AddSpouseToIndividualAction;
use Fisharebest\Webtrees\Http\RequestHandlers\ChangeFamilyMembersAction;
use Fisharebest\Webtrees\Http\RequestHandlers\CreateMediaObjectAction;
use Fisharebest\Webtrees\Http\RequestHandlers\DeleteFact;
use Fisharebest\Webtrees\Http\RequestHandlers\DeleteRecord;
use Fisharebest\Webtrees\Http\RequestHandlers\EditFactAction;
use Fisharebest\Webtrees\Http\RequestHandlers\LinkChildToFamilyAction;
use Fisharebest\Webtrees\Http\RequestHandlers\LinkSpouseToIndividualAction;
use Fisharebest\Webtrees\Http\RequestHandlers\PendingChangesAcceptRecord;
use Fisharebest\Webtrees\Http\RequestHandlers\TomSelectIndividual;
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
use Fisharebest\Webtrees\Module\IndividualListModule;
use Fisharebest\Webtrees\Module\ModuleListInterface;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\SearchService;
use Fisharebest\Webtrees\Session;
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
    public const CUSTOM_VERSION = '1.7.1';

    // Allowed values / ranges for admin preferences.
    private const ALLOWED_MODES = ['vertical'];

    /** Modos de dibujo que acepta el endpoint Graph (vertical = árbol clásico). */
    private const ALLOWED_VIEWS = ['vertical', 'pedigree'];
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
        // Vistas disponibles en el conmutador (el árbol es la base, siempre).
        'enable_pedigree' => '1',
        'enable_fan' => '1',
        'enable_list' => '1',
        // Tope de tarjetas que la animación FLIP mueve a la vez (0 = sin límite).
        'flip_limit' => '0',
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
                'Improved tree of %s'             => 'Árbol interactivo de %s',
                'Branch colours'                  => 'Colores por rama',
                'Slots to add missing parents'    => 'Huecos para añadir padres',
                'Fan view'                        => 'Vista de abanico',
                'Animation card limit'            => 'Límite de tarjetas animadas',
                'Maximum number of cards the tree animates when it reorganises (0 = no limit). Lower it if large trees feel sluggish.' => 'Número máximo de tarjetas que el árbol anima al reorganizarse (0 = sin límite). Bájalo si los árboles grandes van a tirones.',
                'Generations of ancestors'        => 'Generaciones de ascendientes',
                'Pedigree'                        => 'Pedigrí',
                'Surnames'                        => 'Apellidos',
                'Views'                           => 'Vistas',
                'Theme'                           => 'Tema',
                'Which views the tree switcher offers. The family tree view is always available.'
                                                  => 'Qué vistas ofrece el conmutador del árbol. La vista de árbol familiar está siempre disponible.',
                'View'                            => 'Vista',
                'Tree'                            => 'Árbol',
                'Fan'                             => 'Abanico',
                'List'                            => 'Lista',
                '%1$s is the %2$s of %3$s'        => '%1$s es %2$s de %3$s',
                'Collapse'                        => 'Contraer',
                'The tree is very large, so only part of it is shown. Select a person to see more of their branch.'
                                                  => 'El árbol es muy grande y se muestra solo una parte. Toca a una persona para ver más de su rama.',
                'Defaults'                        => 'Valores por defecto',
                'Default ancestor depth'          => 'Profundidad de ascendientes por defecto',
                'Default descendant depth'        => 'Profundidad de descendientes por defecto',
                'Include spouses by default'      => 'Incluir cónyuges por defecto',
                'Include siblings by default'     => 'Incluir hermanos por defecto',
                'Show photos by default'          => 'Mostrar fotos por defecto',
                'Show dates by default'           => 'Mostrar fechas por defecto',
                'Node limits'                     => 'Límites de nodos',
                'The maximum number of individuals rendered in a single tree, by role. Requests for more are truncated.'
                                                  => 'Número máximo de individuos dibujados en un árbol, por rol. Las peticiones que lo superen se truncan.',
                'Maximum nodes for visitors'      => 'Máximo de nodos para visitantes',
                'Maximum nodes for logged-in users'
                                                  => 'Máximo de nodos para usuarios registrados',
                'Maximum nodes for administrators'
                                                  => 'Máximo de nodos para administradores',
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
                'Direct family'                   => 'Familia directa',
                'Legend'                          => 'Leyenda',
                'Main person'                     => 'Persona principal',
                'Filiation'                       => 'Filiación',
                'Couple'                          => 'Pareja',
                'Starting person'                 => 'Persona inicial',
                'Selected person'                 => 'Persona seleccionada',
                'Parents and children'            => 'Padres e hijos',
                'Person without details yet'      => 'Persona sin datos (desconocida)',
                'Private information'             => 'Información privada',
                'Also appears in another branch'  => 'Aparece también en otra rama',
                'How much family to show'         => '¿Cuánta familia mostrar?',
                'Choose generations manually…'    => 'Elegir generaciones a mano…',
                'Close family only'               => 'Solo la familia cercana',
                'Also grandparents and grandchildren'
                                                  => 'También abuelos y nietos',
                'Extended family (uncles, aunts and cousins)'
                                                  => 'Familia extensa (tíos y primos)',
                'Very extended family'            => 'Familia muy extensa',
                'Everything possible (level %s)'  => 'Todo lo posible (nivel %s)',
                'Generations up (ancestors)'      => 'Generaciones hacia arriba (antepasados)',
                'Generations down (descendants)'  => 'Generaciones hacia abajo (descendientes)',
                'Save image'                      => 'Guardar imagen',
                'Save drawing (SVG)'              => 'Guardar dibujo (SVG)',
                'Save the tree as an image (PNG)' => 'Guardar el árbol como imagen (PNG)',
                'Save the tree as a scalable drawing (SVG)'
                                                  => 'Guardar el árbol como dibujo escalable (SVG)',
                'View the tutorial'               => 'Ver el tutorial',
                'Generations'                     => 'Generaciones',
                'Generations before/after this person'
                                                  => 'Generaciones antes/después de esta persona',
                'The interactive tree needs JavaScript. You can still browse the records directly.'
                                                  => 'El árbol interactivo necesita JavaScript. Puedes seguir consultando las fichas directamente.',
                'Open the record'                 => 'Abrir la ficha',
                'Text size'                       => 'Tamaño del texto',
                'Create individual'               => 'Crear individuo',
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
                'Center on the selected person'   => 'Centrar en la persona seleccionada',
                'Fullscreen'                      => 'Pantalla completa',
                'Add image'                       => 'Añadir imagen',
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
        // Vista «Lista»: enlace a la lista nativa de individuos de webtrees
        // (si el módulo está activo para este árbol y usuario).
        $list_url    = '';
        $list_module = (new ModuleService())
            ->findByComponent(ModuleListInterface::class, $tree, Auth::user())
            ->first(static fn ($m): bool => $m instanceof IndividualListModule);
        if ($list_module !== null) {
            $list_url = $list_module->listUrl($tree);
        }

        return [
            'list_url'        => $list_url,
            'enable_pedigree' => $this->preference('enable_pedigree') === '1',
            'enable_fan'      => $this->preference('enable_fan') === '1',
            'enable_list'     => $this->preference('enable_list') === '1',
            'flip_limit'      => (int) $this->preference('flip_limit'),
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
            'relationship_url' => route('module', [
                'module' => $this->name(),
                'action' => 'Relationship',
                'tree'   => $tree->name(),
            ]),
            'tree_url'   => route('module', [
                'module'  => $this->name(),
                'action'  => 'Tree',
                'tree'    => $tree->name(),
                'xref'    => $individual->xref(),
            ]),
            // Sin permisos de edición la URL no se emite: el JS no crea el
            // subsistema de edición y el visitante no ve lápices ni "Quitar".
            'edit_context_url' => Auth::isEditor($tree) ? route('module', [
                'module' => $this->name(),
                'action' => 'EditContext',
                'tree'   => $tree->name(),
            ]) : '',
            // The record with pending changes is only known at settle time (it
            // may even be a FAM), so the client fills in the xref itself.
            'accept_url_template' => route(PendingChangesAcceptRecord::class, [
                'tree' => $tree->name(),
                'xref' => 'XREFPLACEHOLDER',
            ]),
            'can_edit'     => Auth::isEditor($tree),
            'can_moderate' => Auth::isModerator($tree),
        ];
    }

    /**
     * A stamp that moves whenever ANY edit — pending or accepted, from this
     * module, better-webtrees-forms or the native UI — touches the tree. Baked
     * into cache keys it invalidates every cached combination at once;
     * superseded entries just age out with their TTL. The pending count is
     * needed because accepting/rejecting a change only UPDATEs its status
     * column, which MAX(change_id) alone would miss.
     */
    private function treeChangeStamp(Tree $tree): string
    {
        $row = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->selectRaw("MAX(change_id) AS max_id, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending")
            ->first();

        return ($row->max_id ?? 0) . '.' . ($row->pending ?? 0);
    }

    /**
     * Records with unapproved changes, keyed by xref: 'new' when the record
     * itself is a pending addition, 'changed' otherwise. Only meaningful for
     * viewers who see pending edits merged in (editors and up).
     *
     * @return array<string, string>
     */
    private function pendingXrefs(Tree $tree): array
    {
        $rows = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->where('status', '=', 'pending')
            ->orderBy('change_id')
            ->get(['xref', 'old_gedcom']);

        $pending = [];
        foreach ($rows as $row) {
            if (!isset($pending[$row->xref])) {
                $pending[$row->xref] = trim((string) $row->old_gedcom) === '' ? 'new' : 'changed';
            }
        }

        return $pending;
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

        // Read-only endpoint: release the session write-lock so parallel AJAX
        // (panel detail, edit forms) is not serialised behind graph builds.
        Session::save();

        $mode                  = Validator::queryParams($request)->isInArray(self::ALLOWED_VIEWS)->string('mode', $this->preference('default_mode'));
        $ancestor_depth        = Validator::queryParams($request)->isBetween(self::MIN_DEPTH, self::MAX_DEPTH)->integer('ancestor_depth', (int) $this->preference('default_ancestor_depth'));
        $descendant_depth      = Validator::queryParams($request)->isBetween(self::MIN_DEPTH, self::MAX_DEPTH)->integer('descendant_depth', (int) $this->preference('default_descendant_depth'));
        $include_spouses       = Validator::queryParams($request)->boolean('include_spouses', (bool) (int) $this->preference('default_include_spouses'));
        $include_siblings      = Validator::queryParams($request)->boolean('include_siblings', (bool) (int) $this->preference('default_include_siblings'));
        $link_level            = Validator::queryParams($request)->isBetween(self::MIN_LINK_LEVEL, self::MAX_LINK_LEVEL)->integer('link_level', $this->defaultLinkLevel());
        $show_photos           = Validator::queryParams($request)->boolean('show_photos', (bool) (int) $this->preference('default_show_photos'));
        $show_dates            = Validator::queryParams($request)->boolean('show_dates', (bool) (int) $this->preference('default_show_dates'));
        $requested_limit       = Validator::queryParams($request)->integer('limit', $this->maxNodesForUser($user));
        $limit                 = min($requested_limit, $this->maxNodesForUser($user));

        // Editors see their own pending edits merged into the records, so their
        // cache entries are per-user; the pending map drives the cards' badges.
        $is_editor = Auth::isEditor($tree, $user);
        $pending   = $is_editor ? $this->pendingXrefs($tree) : [];

        // Cache the built graph. The key covers everything that changes the output,
        // including the viewer's access level (privacy is applied while building),
        // so entries are safely shared between users at the same access level.
        // The change stamp retires every entry the moment the tree is edited.
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
            $is_editor ? (string) Auth::id() : '',
            $this->treeChangeStamp($tree),
            // Los badges de cumpleaños/aniversario dependen del día de hoy.
            date('Y-m-d'),
        ]);

        $graph = Registry::cache()->file()->remember(
            $cache_key,
            static function () use (
                $individual,
                $mode,
                $ancestor_depth,
                $descendant_depth,
                $include_spouses,
                $include_siblings,
                $link_level,
                $show_photos,
                $show_dates,
                $limit,
                $pending
            ): array {
                $builder = new GraphBuilder();
                $builder->setPending($pending);

                if ($mode === 'pedigree') {
                    return $builder->buildPedigree(
                        $individual,
                        $ancestor_depth,
                        $limit,
                        $show_photos,
                        $show_dates
                    );
                }

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

        // Total del árbol para el contador «X de Y personas» (COUNT barato,
        // fuera de la caché del grafo para no atarlo a sus claves).
        $graph['meta']['totalIndividuals'] = (int) DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->count();

        // no-cache (not max-age): the browser must revalidate after every edit;
        // the server-side file cache absorbs the rebuild cost. private: the
        // payload is privacy-filtered per access level, never for shared proxies.
        return response($graph)
            ->withHeader('Cache-Control', 'private, no-cache');
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

        // Read-only: free the session lock (see getGraphAction).
        Session::save();

        $cache_key = implode('|', [
            'improved-tree-detail',
            self::CUSTOM_VERSION,
            $tree->id(),
            $individual->xref(),
            Auth::accessLevel($tree, $user),
            Auth::isEditor($tree, $user) ? (string) Auth::id() : '',
            $this->treeChangeStamp($tree),
            // La edad de los vivos cambia con la fecha.
            date('Y-m-d'),
        ]);

        $detail = Registry::cache()->file()->remember(
            $cache_key,
            fn (): array => $this->buildIndividualDetail($individual),
            self::GRAPH_CACHE_TTL
        );

        return response($detail)
            ->withHeader('Cache-Control', 'private, no-cache');
    }

    /**
     * Cadena de parentesco A→B («¿Qué parentesco tenemos?»): camino mínimo
     * sobre la tabla `link` (patrón del chart de relaciones del core) con los
     * nombres localizados por RelationshipService. Read-only, cacheado por
     * idioma y nivel de acceso.
     */
    public function getRelationshipAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree  = Validator::attributes($request)->tree();
        $user  = Validator::attributes($request)->user();
        $xref  = Validator::queryParams($request)->isXref()->string('xref');
        $xref2 = Validator::queryParams($request)->isXref()->string('xref2');

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $from = Registry::individualFactory()->make($xref, $tree);
        $from = Auth::checkIndividualAccess($from, false, true);
        $to   = Registry::individualFactory()->make($xref2, $tree);
        $to   = Auth::checkIndividualAccess($to, false, true);

        // Read-only: liberar el write-lock de sesión (ver getGraphAction).
        Session::save();

        $cache_key = implode('|', [
            'improved-tree-rel',
            self::CUSTOM_VERSION,
            $tree->id(),
            $from->xref(),
            $to->xref(),
            I18N::languageTag(),
            Auth::accessLevel($tree, $user),
            Auth::isEditor($tree, $user) ? (string) Auth::id() : '',
            $this->treeChangeStamp($tree),
        ]);

        $payload = Registry::cache()->file()->remember(
            $cache_key,
            fn (): array => $this->buildRelationship($from, $to),
            self::GRAPH_CACHE_TTL
        );

        return response($payload)->withHeader('Cache-Control', 'private, no-cache');
    }

    /**
     * Camino mínimo A→B sobre la tabla `link` (FAMS/FAMC), como el chart de
     * relaciones del core pero solo con los caminos mínimos de Dijkstra.
     *
     * @return array<int, array<int, string>> caminos como xrefs INDI/FAM alternados
     */
    private function shortestLinkPaths(Individual $from, Individual $to): array
    {
        $rows = DB::table('link')
            ->where('l_file', '=', $from->tree()->id())
            ->whereIn('l_type', ['FAMS', 'FAMC'])
            ->select(['l_from', 'l_to'])
            ->get();

        $graph = [];
        foreach ($rows as $row) {
            $graph[$row->l_from][$row->l_to] = 1;
            $graph[$row->l_to][$row->l_from] = 1;
        }

        $dijkstra = new Dijkstra($graph);
        $paths    = $dijkstra->shortestPaths($from->xref(), $to->xref());

        // Dijkstra castea claves numéricas a int: volver a string SIEMPRE.
        return array_map(
            static fn (array $p): array => array_map(static fn ($x): string => (string) $x, $p),
            $paths
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRelationship(Individual $from, Individual $to): array
    {
        $language = I18N::language();
        $service  = new RelationshipService();

        $none = [
            'from'         => $this->kinStub($from),
            'to'           => $this->kinStub($to),
            'relationship' => '',
            'phrase'       => '',
            'steps'        => [],
            'meta'         => ['pathCount' => 0],
        ];

        if ($from->xref() === $to->xref()) {
            return array_merge($none, [
                'relationship' => $service->nameFromPath([$from], $language),
                'meta'         => ['pathCount' => 1],
            ]);
        }

        $paths = $this->shortestLinkPaths($from, $to);

        if ($paths === []) {
            return $none;
        }

        $path = $paths[array_key_first($paths)];

        // Materializar los nodos (INDI en pares, FAM en impares). Un link
        // huérfano se trata como «sin parentesco», nunca como fatal.
        $nodes = [];
        foreach ($path as $i => $x) {
            $rec = $i % 2 === 0
                ? Registry::individualFactory()->make($x, $from->tree())
                : Registry::familyFactory()->make($x, $from->tree());
            if ($rec === null) {
                return $none;
            }
            $nodes[] = $rec;
        }

        // Frase global: qué es B respecto de A, localizado por webtrees.
        $relationship = $service->nameFromPath($nodes, $language);

        $steps = [];
        for ($i = 0, $n = count($nodes); $i < $n; $i += 2) {
            $step = ['person' => $this->kinStub($nodes[$i])];
            if ($i > 0) {
                $step['rel'] = $service->nameFromPath(
                    [$nodes[$i - 2], $nodes[$i - 1], $nodes[$i]],
                    $language
                );
            }
            $steps[] = $step;
        }

        $phrase = $relationship === '' ? '' : I18N::translate(
            '%1$s is the %2$s of %3$s',
            strip_tags($to->canShowName() ? $to->fullName() : '?'),
            $relationship,
            strip_tags($from->canShowName() ? $from->fullName() : '?')
        );

        return [
            'from'         => $this->kinStub($from),
            'to'           => $this->kinStub($to),
            'relationship' => $relationship,
            'phrase'       => $phrase,
            'steps'        => $steps,
            'meta'         => ['pathCount' => count($paths)],
        ];
    }

    /**
     * Stub privacy-aware para una tarjeta de la cadena de parentesco.
     *
     * @return array<string, mixed>
     */
    private function kinStub(Individual $individual): array
    {
        if (!$individual->canShowName()) {
            return [
                'xref'      => null,
                'name'      => '?',
                'sex'       => $individual->sex(),
                'lifespan'  => null,
                'thumbnail' => null,
                'clickable' => false,
            ];
        }

        return [
            'xref'      => $individual->xref(),
            'name'      => strip_tags($individual->fullName()),
            'sex'       => $individual->sex(),
            'lifespan'  => $this->lifespan($individual),
            'thumbnail' => $individual->canShow() ? $this->portrait($individual) : null,
            'clickable' => true,
        ];
    }

    /**
     * Everything the client-side editor needs to act on one individual: the
     * family structure with its genuinely empty HUSB/WIFE slots, and ready-made
     * URLs for every applicable core edit endpoint (URLs are always generated
     * server-side; the client never assembles one). Cached like the graph — the
     * change stamp refreshes it after every edit, which also keeps the embedded
     * fact ids (md5 of each fact's GEDCOM) valid.
     */
    public function getEditContextAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $xref = Validator::queryParams($request)->isXref()->string('xref');

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $individual = Registry::individualFactory()->make($xref, $tree);
        $individual = Auth::checkIndividualAccess($individual, false, true);

        if (!$individual->canEdit()) {
            return response(['canEdit' => false], 403);
        }

        $cache_key = implode('|', [
            'improved-tree-editctx',
            self::CUSTOM_VERSION,
            $tree->id(),
            $individual->xref(),
            (string) Auth::id(),
            $this->treeChangeStamp($tree),
        ]);

        $context = Registry::cache()->file()->remember(
            $cache_key,
            fn (): array => $this->buildEditContext($individual),
            self::GRAPH_CACHE_TTL
        );

        return response($context)
            ->withHeader('Cache-Control', 'private, no-cache');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEditContext(Individual $individual): array
    {
        $tree      = $individual->tree();
        $tree_name = $tree->name();
        $xref      = $individual->xref();

        $famc = [];
        foreach ($individual->childFamilies() as $family) {
            $famc[] = $this->familyEditContext($family);
        }

        $fams = [];
        foreach ($individual->spouseFamilies() as $family) {
            $fams[] = $this->familyEditContext($family);
        }

        $primary = $individual->getAllNames()[$individual->getPrimaryName()] ?? [];

        return [
            'xref'        => $xref,
            'canEdit'     => true,
            'canModerate' => Auth::isModerator($tree),
            'sex'         => $individual->sex(),
            'names'       => [
                'given'   => trim(str_replace('@N.N.', '', $primary['givn'] ?? '')),
                'surname' => trim(str_replace('@N.N.', '', $primary['surn'] ?? '')),
            ],
            'famc'        => $famc,
            'fams'        => $fams,
            'actions'     => [
                'addChildToIndividual'  => route(AddChildToIndividualAction::class, ['tree' => $tree_name, 'xref' => $xref]),
                'addSpouseToIndividual' => route(AddSpouseToIndividualAction::class, ['tree' => $tree_name, 'xref' => $xref]),
                'addParent'             => route(AddParentToIndividualAction::class, ['tree' => $tree_name, 'xref' => $xref]),
                'linkSpouse'            => route(LinkSpouseToIndividualAction::class, ['tree' => $tree_name, 'xref' => $xref]),
                // The chosen person's xref replaces the placeholder client-side
                // (alphanumeric on purpose: it survives URL-encoding intact).
                'linkChildTemplate'     => route(LinkChildToFamilyAction::class, ['tree' => $tree_name, 'xref' => 'XREFPLACEHOLDER']),
                'changeFamilyMembers'   => route(ChangeFamilyMembersAction::class, ['tree' => $tree_name]),
                'delete'                => route(DeleteRecord::class, ['tree' => $tree_name, 'xref' => $xref]),
                'accept'                => route(PendingChangesAcceptRecord::class, ['tree' => $tree_name, 'xref' => $xref]),
                'newFactUrl'            => $this->factUpdateUrl($individual, 'new'),
                // JSON endpoint: creates (and auto-accepts) the media object;
                // the client then links it with a new OBJE fact. Gated by the
                // tree's MEDIA_UPLOAD preference (the core endpoint itself only
                // checks AuthEditor); the client shows mediaUnavailable on null.
                'createMedia'           => Auth::canUploadMedia($tree, Auth::user())
                    ? route(CreateMediaObjectAction::class, ['tree' => $tree_name])
                    : null,
                'search'                => route(TomSelectIndividual::class, ['tree' => $tree_name, 'at' => '']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function familyEditContext(Family $family): array
    {
        $tree_name = $family->tree()->name();
        $fxref     = $family->xref();
        $husband   = $family->husband();
        $wife      = $family->wife();
        $can_edit  = $family->canEdit();
        // Skip pending deletions, like visibleFacts() does for individuals:
        // otherwise an in-flight MARR edit would serve the superseded fact.
        $marr      = $family->facts(['MARR'], true)
            ->filter(static fn (Fact $fact): bool => !$fact->isPendingDeletion())
            ->first();

        $children = [];
        foreach ($family->children() as $child) {
            $children[] = $this->personStub($child);
        }

        return [
            'famId'      => $fxref,
            'husb'       => $husband instanceof Individual ? $this->personStub($husband) : null,
            'wife'       => $wife instanceof Individual ? $this->personStub($wife) : null,
            'children'   => $children,
            'marr'       => $marr instanceof Fact ? $this->factEditData($family, $marr) : null,
            'newFactUrl' => $can_edit ? $this->factUpdateUrl($family, 'new') : null,
            'pending'    => $family->isPendingAddition(),
            'actions'    => $can_edit
                ? [
                    'addChild'  => route(AddChildToFamilyAction::class, ['tree' => $tree_name, 'xref' => $fxref]),
                    // Only offered while a HUSB/WIFE slot is genuinely empty:
                    // the core endpoint fails silently on a complete family.
                    'addSpouse' => $husband === null || $wife === null
                        ? route(AddSpouseToFamilyAction::class, ['tree' => $tree_name, 'xref' => $fxref])
                        : null,
                    // Deletes the FAM record itself (the core cleans FAMS/FAMC
                    // pointers); the client only offers it on childless unions.
                    // Gated on the RAW gedcom, not children(): a child hidden
                    // by privacy must still block the delete, or DeleteRecord
                    // would silently strip that child's FAMC.
                    'delete'    => preg_match('/\n1 CHIL @/', $family->gedcom()) === 1
                        ? null
                        : route(DeleteRecord::class, ['tree' => $tree_name, 'xref' => $fxref]),
                ]
                : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function personStub(Individual $individual): array
    {
        return [
            'xref'  => $individual->xref(),
            'label' => $individual->canShowName() ? strip_tags($individual->fullName()) : '?',
        ];
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

        // Read-only: free the session lock (see getGraphAction).
        Session::save();

        $q = trim(Validator::queryParams($request)->string('q', ''));

        if (mb_strlen($q) < 2) {
            return response([]);
        }

        $words = preg_split('/\s+/', $q) ?: [$q];

        // Uno más del tope: si aparece, hay más resultados que no se muestran
        // y el cliente lo avisa (antes el corte a 15 era silencioso y la
        // persona buscada "no existía").
        $search      = Registry::container()->get(SearchService::class);
        $individuals = $search->searchIndividualNames([$tree], $words, 0, 16);

        $results  = [];
        $has_more = false;
        foreach ($individuals as $individual) {
            if (!$individual->canShowName()) {
                continue;
            }
            if (count($results) >= 15) {
                $has_more = true;
                break;
            }
            // Contexto de desambiguación: en genealogía los nombres se repiten
            // generación tras generación; lugar de nacimiento y padres son lo
            // único que separa a dos homónimos en la lista.
            $birth_place = $individual->canShow()
                ? strip_tags($individual->getBirthPlace()->placeName())
                : '';
            $parents = [];
            if ($individual->canShow()) {
                $child_family = $individual->childFamilies()->first();
                if ($child_family !== null) {
                    foreach ($child_family->spouses() as $parent) {
                        if ($parent->canShowName()) {
                            $parents[] = strip_tags($parent->fullName());
                        }
                    }
                }
            }
            $results[] = [
                'xref'       => $individual->xref(),
                'name'       => strip_tags($individual->fullName()),
                'sex'        => $individual->sex(),
                'lifespan'   => $this->lifespan($individual),
                'birthPlace' => $birth_place,
                'parents'    => $parents,
            ];
        }

        return response(['results' => $results, 'hasMore' => $has_more]);
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
            'groups'    => [],
        ];

        if (!$can_show) {
            return $detail;
        }

        $can_edit = $individual->canEdit();

        // Edad actual (vivos) o al fallecer (muertos con fecha), para la
        // cabecera del panel — como la muestra MyHeritage.
        $birth = $individual->getBirthDate();
        if ($birth->isOK()) {
            $end = $individual->isDead()
                ? $individual->getDeathDate()
                : new Date(strtoupper(date('d M Y')));
            if ($end->isOK()) {
                $years = (new Age($birth, $end))->ageYears();
                if ($years >= 0) {
                    $detail['age']    = $years;
                    $detail['isDead'] = $individual->isDead();
                }
            }
        }

        $detail['canEdit']    = $can_edit;
        $detail['timeline']   = $this->buildTimeline($individual, $can_edit);
        $detail['attributes'] = $this->buildAttributes($individual, $can_edit);
        $detail['media']      = $this->buildMedia($individual);
        // `role` is a stable key (labels are localised) so the client can map a
        // relative back to the family that links them (unlink, F6).
        $detail['groups']     = [
            ['role' => 'parents', 'label' => I18N::translate('Parents'), 'people' => $this->parents($individual)],
            ['role' => 'spouses', 'label' => I18N::translate('Spouses'), 'people' => $this->spouses($individual)],
            ['role' => 'children', 'label' => I18N::translate('Children'), 'people' => $this->children($individual)],
            ['role' => 'siblings', 'label' => I18N::translate('Siblings'), 'people' => $this->siblings($individual)],
        ];

        if ($can_edit) {
            $detail['newFactUrl']      = $this->factUpdateUrl($individual, 'new');
            $detail['vitals']          = $this->buildVitals($individual);
        }

        return $detail;
    }

    /**
     * The editable vital facts (name, sex, birth, death) with their raw GEDCOM
     * lines, so the client can build simplified edit forms that resubmit every
     * line — update-fact REPLACES the whole fact, and untouched substructures
     * (sources, notes) must survive a partial edit. null = the fact does not
     * exist yet; create it via newFactUrl (fact_id=new).
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function buildVitals(Individual $individual): array
    {
        $vitals = [];

        foreach (['NAME', 'SEX', 'BIRT', 'DEAT'] as $tag) {
            $fact = $this->visibleFacts($individual, [$tag])->first();

            $vitals[strtolower($tag)] = $fact instanceof Fact
                ? $this->factEditData($individual, $fact)
                : null;
        }

        return $vitals;
    }

    /**
     * The client-side editing bundle for one fact: its id (md5 of its GEDCOM —
     * only valid until the fact changes, which the change-stamp cache keys
     * guarantee), the core update-fact URL, and its full GEDCOM lines.
     *
     * @return array<string, mixed>
     */
    private function factEditData(GedcomRecord $record, Fact $fact): array
    {
        $data = [
            'factId'    => $fact->id(),
            'updateUrl' => $this->factUpdateUrl($record, $fact->id()),
            'lines'     => $this->factLines($fact),
            'pending'   => $fact->isPendingAddition(),
        ];

        // Same fact_id as updateUrl; the DeleteFact handler re-checks
        // canEdit() itself and answers 204.
        if ($fact->canEdit()) {
            $data['deleteUrl'] = route(DeleteFact::class, [
                'tree'    => $record->tree()->name(),
                'xref'    => $record->xref(),
                'fact_id' => $fact->id(),
            ]);
        }

        return $data;
    }

    private function factUpdateUrl(GedcomRecord $record, string $fact_id): string
    {
        return route(EditFactAction::class, [
            'tree'    => $record->tree()->name(),
            'xref'    => $record->xref(),
            'fact_id' => $fact_id,
        ]);
    }

    /**
     * Parse a fact's GEDCOM into the parallel arrays the core's update-fact
     * handler consumes (level/tag/value per line, parents before children).
     *
     * @return array{levels: array<int>, tags: array<string>, values: array<string>}
     */
    private function factLines(Fact $fact): array
    {
        $levels = [];
        $tags   = [];
        $values = [];

        foreach (preg_split('/\r?\n/', trim($fact->gedcom())) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\w+)\s?(.*)$/', $line, $match) !== 1) {
                continue;
            }
            $levels[] = (int) $match[1];
            $tags[]   = $match[2];
            $values[] = $match[3];
        }

        return ['levels' => $levels, 'tags' => $tags, 'values' => $values];
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
     * section. Each row is {label, date, place} plus, for editors, the
     * factEditData bundle (updateUrl/lines/…) that feeds the in-place editor.
     * This is the full life chronology (birth, death and everything between).
     *
     * @return array<int, array<string, string>>
     */
    private function buildTimeline(Individual $individual, bool $can_edit): array
    {
        $events = [];

        foreach ($this->visibleFacts($individual) as $fact) {
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
                $row += $this->factEditData($individual, $fact);
            }
            $events[] = $row;
        }

        return $events;
    }

    /**
     * The individual's undated attributes (occupation, residence, education,
     * titles, etc.) that carry a value — the "more info" section so the user
     * need not open the full record. Each row is {label, value, place} plus,
     * for editors, the factEditData bundle (updateUrl/lines/…).
     *
     * @return array<int, array<string, string>>
     */
    private function buildAttributes(Individual $individual, bool $can_edit): array
    {
        $rows = [];

        foreach ($this->visibleFacts($individual) as $fact) {
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
                $row += $this->factEditData($individual, $fact);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Facts as they will read once pending changes are approved. A pending
     * edit makes facts() return BOTH the old fact (pending deletion) and its
     * replacement (pending addition); showing the old one would duplicate the
     * fact across the panel and offer an edit that silently overwrites the
     * pending change.
     *
     * @param list<string> $filter
     *
     * @return \Illuminate\Support\Collection<int, Fact>
     */
    private function visibleFacts(Individual $individual, array $filter = []): \Illuminate\Support\Collection
    {
        return $individual->facts($filter, true)
            ->filter(static fn (Fact $fact): bool => !$fact->isPendingDeletion());
    }

    /**
     * The bare GEDCOM subtag of a fact (e.g. "OCCU" from "INDI:OCCU").
     */
    private function factTag(Fact $fact): string
    {
        $parts = explode(':', $fact->tag());

        return (string) end($parts);
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
        $this->setPreference('enable_pedigree', $params->boolean('enable_pedigree', false) ? '1' : '0');
        $this->setPreference('enable_fan', $params->boolean('enable_fan', false) ? '1' : '0');
        $this->setPreference('enable_list', $params->boolean('enable_list', false) ? '1' : '0');
        $this->setPreference('flip_limit', (string) $params->isBetween(0, 10000)->integer('flip_limit', 0));
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
