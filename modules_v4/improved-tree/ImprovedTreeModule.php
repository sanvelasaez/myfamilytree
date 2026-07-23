<?php

declare(strict_types=1);

namespace ImprovedTree;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function redirect;
use function response;
use function route;
use function view;

final class ImprovedTreeModule extends AbstractModule implements
    ModuleCustomInterface,
    ModuleGlobalInterface,
    ModuleTabInterface,
    ModuleChartInterface,
    ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleGlobalTrait;
    use ModuleTabTrait;
    use ModuleChartTrait;
    use ModuleConfigTrait;

    public const CUSTOM_TITLE = 'Improved Tree';
    public const CUSTOM_AUTHOR = 'sanvelas';
    public const CUSTOM_VERSION = '1.0.0';

    // Allowed values / ranges for admin preferences.
    private const ALLOWED_MODES = ['vertical'];
    private const MIN_DEPTH = 0;
    private const MAX_DEPTH = 25;
    private const MIN_MAX_NODES_GUEST_USER = 100;
    private const MAX_MAX_NODES_GUEST_USER = 2000;
    private const MIN_MAX_NODES_ADMIN = 100;
    private const MAX_MAX_NODES_ADMIN = 5000;

    private const PREFERENCE_DEFAULTS = [
        'default_mode' => 'vertical',
        'default_ancestor_depth' => '4',
        'default_descendant_depth' => '4',
        'default_include_spouses' => '1',
        'default_include_siblings' => '0',
        'default_show_photos' => '1',
        'default_show_dates' => '1',
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

    // Tab interface methods
    public function tabTitle(): string
    {
        return $this->title();
    }

    public function defaultTabOrder(): int
    {
        return 0;
    }

    public function hasTabContent(Individual $individual): bool
    {
        return $individual->canShow();
    }

    public function isGrayedOut(Individual $individual): bool
    {
        return false;
    }

    public function getTabContent(Individual $individual): string
    {
        return view($this->name() . '::tab', [
            'module'     => $this,
            'tree'       => $individual->tree(),
            'individual' => $individual,
            'graph_url'  => route('module', [
                'module'  => $this->name(),
                'action'  => 'Graph',
                'tree'    => $individual->tree()->name(),
                'xref'    => $individual->xref(),
                'context' => 'tab',
            ]),
        ]);
    }

    public function canLoadAjax(): bool
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

        return $this->viewResponse($this->name() . '::page', [
            'module'     => $this,
            'tree'       => $tree,
            'individual' => $individual,
            'title'      => $this->chartTitle($individual),
            'graph_url'  => route('module', [
                'module'  => $this->name(),
                'action'  => 'Graph',
                'tree'    => $tree->name(),
                'xref'    => $individual->xref(),
                'context' => 'chart',
            ]),
        ]);
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

        $mode              = Validator::queryParams($request)->string('mode', $this->preference('default_mode'));
        $ancestor_depth    = Validator::queryParams($request)->isBetween(self::MIN_DEPTH, self::MAX_DEPTH)->integer('ancestor_depth', (int) $this->preference('default_ancestor_depth'));
        $descendant_depth  = Validator::queryParams($request)->isBetween(self::MIN_DEPTH, self::MAX_DEPTH)->integer('descendant_depth', (int) $this->preference('default_descendant_depth'));
        $include_spouses   = Validator::queryParams($request)->boolean('include_spouses', (bool) (int) $this->preference('default_include_spouses'));
        $include_siblings  = Validator::queryParams($request)->boolean('include_siblings', (bool) (int) $this->preference('default_include_siblings'));
        $show_photos       = Validator::queryParams($request)->boolean('show_photos', (bool) (int) $this->preference('default_show_photos'));
        $show_dates        = Validator::queryParams($request)->boolean('show_dates', (bool) (int) $this->preference('default_show_dates'));
        $requested_limit   = Validator::queryParams($request)->integer('limit', $this->maxNodesForUser($user));
        $limit             = min($requested_limit, $this->maxNodesForUser($user));

        $builder = new GraphBuilder();
        $graph = $builder->buildGraph(
            $individual,
            $ancestor_depth,
            $descendant_depth,
            $include_spouses,
            $include_siblings,
            $limit
        );

        $presenter = new NodePresenter($tree);
        $graph = $presenter->presentGraph($graph, $show_photos, $show_dates);

        return response($graph);
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
        $this->setPreference('default_show_photos', $params->boolean('default_show_photos', false) ? '1' : '0');
        $this->setPreference('default_show_dates', $params->boolean('default_show_dates', false) ? '1' : '0');
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
