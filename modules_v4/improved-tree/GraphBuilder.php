<?php

declare(strict_types=1);

namespace ImprovedTree;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;

class GraphBuilder
{
    private array $nodes = [];
    private array $edges = [];
    private array $families = [];
    private array $visited = [];
    private int $nodeCount = 0;
    private int $phantomCount = 0;
    private int $limit = 500;
    private bool $truncated = false;
    private string $rootXref = '';
    private bool $showPhotos = true;
    private bool $showDates = true;

    /** @var array<string, string> xref => 'new'|'changed' (records with pending edits) */
    private array $pending = [];

    /** @var array<string, Individual> xref => individuo materializado (no phantom) */
    private array $ref = [];

    /**
     * Records with unapproved changes, so cards can wear a "pending" badge.
     *
     * @param array<string, string> $pending xref => 'new'|'changed'
     */
    public function setPending(array $pending): void
    {
        $this->pending = $pending;
    }

    public function buildGraph(
        Individual $root,
        int $ancestor_depth = 4,
        int $descendant_depth = 4,
        bool $include_spouses = true,
        bool $include_siblings = false,
        int $limit = 500,
        bool $show_photos = true,
        bool $show_dates = true
    ): array {
        $this->reset($root, $limit, $show_photos, $show_dates);

        $this->addNode($root, 0);

        if ($include_siblings && !$this->truncated) {
            $this->addSiblings($root);
        }

        $ancestor_frontier   = [$root];
        $descendant_frontier = [$root];
        $descendant_generation = 0;
        $max_level = max($ancestor_depth, $descendant_depth);

        for ($level = 1; $level <= $max_level && !$this->truncated; $level++) {
            if ($level <= $ancestor_depth) {
                $ancestor_frontier = $this->expandAncestorLevel($ancestor_frontier, $level);
            }

            if ($this->truncated) {
                break;
            }

            if ($level <= $descendant_depth) {
                $descendant_frontier = $this->expandDescendantLevel($descendant_frontier, $descendant_generation, $include_spouses);
                $descendant_generation--;
            }
        }

        return $this->getResult();
    }

    /**
     * Ancestors-only graph for the pedigree view: reuses the ancestor
     * level-BFS (no descendants, no spouses, no siblings, no phantom
     * spouses — client placeholders cover the gaps) and adds
     * meta.rootChildren for the root's descend arrow.
     */
    public function buildPedigree(
        Individual $root,
        int $depth,
        int $limit = 500,
        bool $show_photos = true,
        bool $show_dates = true
    ): array {
        $this->reset($root, $limit, $show_photos, $show_dates);
        $this->addNode($root, 0);

        $frontier = [$root];
        for ($level = 1; $level <= $depth && !$this->truncated; $level++) {
            $frontier = $this->expandAncestorLevel($frontier, $level);
        }

        $result = $this->getResult();
        $result['layout'] = 'pedigree';

        // Hijos de la raíz para la flecha de descendencia (canShowName: el
        // mismo umbral con el que addNode muestra un nombre).
        $children = [];
        foreach ($root->spouseFamilies() as $family) {
            foreach ($family->children() as $child) {
                if ($child->canShowName()) {
                    $children[$child->xref()] = [
                        'id'    => $child->xref(),
                        'label' => strip_tags($child->fullName()),
                        'sex'   => $child->sex(),
                    ];
                }
            }
        }
        $result['meta']['rootChildren'] = array_values($children);

        return $result;
    }

    /**
     * Build a graph of everyone within N "kinship links" of the root.
     *
     * Unlike a strict consanguinity degree, spouse edges are FREE (cost 0) while
     * every parent/child (filiation) step costs one level. A couple therefore
     * always travels together — and so do both of their blood networks: hopping
     * from a person to their spouse never drops anyone who was visible before,
     * which keeps the picture stable while navigating.
     *
     * Distances come from a 0-1 BFS (spouse = 0, filiation = 1). A node's layout
     * generation is the net vertical displacement of its shortest path
     * (parent = +1 above root, child = -1 below).
     */
    public function buildGraphByLinks(
        Individual $root,
        int $max_level,
        bool $include_spouses = true,
        int $limit = 500,
        bool $show_photos = true,
        bool $show_dates = true
    ): array {
        $this->reset($root, $limit, $show_photos, $show_dates);

        $root_xref = $root->xref();
        $level = [$root_xref => 0];   // link level each xref was settled at
        $gen   = [$root_xref => 0];   // vertical generation for layout
        $ref   = [$root_xref => $root];

        // 0-1 BFS. Spouse neighbours (weight 0) go to the front of the deque so
        // they settle at the current level before we spend a level going up or
        // down; filiation neighbours (weight 1) go to the back. Relaxation lowers
        // a node's level if a cheaper path appears later. A safety cap stops
        // pathological trees before the node limit trims the outer ring.
        $front  = [$root];
        $back   = [];
        $safety = max($this->limit * 4, 2000);

        while ($front !== [] || $back !== []) {
            $ind = $front !== [] ? array_pop($front) : array_shift($back);
            $xref = $ind->xref();
            $lv   = $level[$xref];
            $g    = $gen[$xref];

            if ($include_spouses) {
                foreach ($ind->spouseFamilies() as $family) {
                    foreach ($family->spouses() as $spouse) {
                        $sx = $spouse->xref();
                        if ($sx === $xref || (isset($level[$sx]) && $level[$sx] <= $lv)) {
                            continue;
                        }
                        $level[$sx] = $lv;
                        $gen[$sx]   = $g;
                        $ref[$sx]   = $spouse;
                        $front[]    = $spouse;
                    }
                }
            }

            if ($lv >= $max_level || count($level) >= $safety) {
                continue;
            }

            foreach ($ind->childFamilies() as $family) {
                foreach ($family->spouses() as $parent) {
                    $px = $parent->xref();
                    if (isset($level[$px]) && $level[$px] <= $lv + 1) {
                        continue;
                    }
                    $level[$px] = $lv + 1;
                    $gen[$px]   = $g + 1;
                    $ref[$px]   = $parent;
                    $back[]     = $parent;
                }
            }

            foreach ($ind->spouseFamilies() as $family) {
                foreach ($family->children() as $child) {
                    $cx = $child->xref();
                    if (isset($level[$cx]) && $level[$cx] <= $lv + 1) {
                        continue;
                    }
                    $level[$cx] = $lv + 1;
                    $gen[$cx]   = $g - 1;
                    $ref[$cx]   = $child;
                    $back[]     = $child;
                }
            }
        }

        // Materialise nodes closest-first so the node limit trims the outer ring.
        $order = array_keys($level);
        usort($order, static fn (string $a, string $b): int => $level[$a] <=> $level[$b]);

        foreach ($order as $xref) {
            if ($this->addNode($ref[$xref], $gen[$xref]) === 'limit') {
                break;
            }
        }

        // Edges among the materialised nodes. Each filiation link is emitted once,
        // from the child's side, so parent/child pairs never double up.
        foreach (array_keys($this->nodes) as $xref) {
            $ind = $ref[$xref];

            foreach ($ind->childFamilies() as $family) {
                foreach ($family->spouses() as $parent) {
                    if (isset($this->nodes[$parent->xref()])) {
                        $this->addEdge($xref, $parent->xref(), 'CHILD', $family->xref());
                    }
                }
            }

            if ($include_spouses) {
                foreach ($ind->spouseFamilies() as $family) {
                    foreach ($family->spouses() as $spouse) {
                        if ($spouse->xref() !== $xref && isset($this->nodes[$spouse->xref()])) {
                            $this->addEdge($xref, $spouse->xref(), 'SPOUSE', $family->xref());
                        }
                    }
                }
            }
        }

        $this->buildFamilies($ref);

        return $this->getResult();
    }

    /**
     * Group materialised individuals into the couples/nuclear families that the
     * renderer draws as a single "junction": descendants visibly branch out of
     * the union between the two parents, not from each parent separately.
     *
     * A family is recorded when it has at least one visible child. When only one
     * of the two parents is materialised, a phantom "Unknown" spouse is
     * synthesised — per family — so the child hangs from a couple and the card
     * can offer to add the missing spouse. This holds even when the known parent
     * already partners someone in another family: each union is a distinct
     * marriage, and the layout draws it flanked (spouse — person — spouse) with
     * its own bar and rings, so a child from a different union reads clearly as
     * such (prompting the viewer to add the missing co-parent, or to spot a
     * data-entry error).
     *
     * @param array<string, Individual> $ref
     */
    private function buildFamilies(array $ref): void
    {
        $seen = [];

        foreach (array_keys($this->nodes) as $xref) {
            if (!isset($ref[$xref])) {
                continue; // phantom nodes have no source individual
            }

            foreach ($ref[$xref]->spouseFamilies() as $family) {
                $fxref = $family->xref();
                if (isset($seen[$fxref])) {
                    continue;
                }
                $seen[$fxref] = true;

                $children = [];
                foreach ($family->children() as $child) {
                    if (isset($this->nodes[$child->xref()])) {
                        $children[] = $child->xref();
                    }
                }

                $parents = [];
                foreach ($family->spouses() as $spouse) {
                    if (isset($this->nodes[$spouse->xref()])) {
                        $parents[] = $spouse->xref();
                    }
                }
                if ($parents === []) {
                    continue; // both parents hidden: children float without a junction
                }

                // Single materialised parent → synthesise the missing spouse so
                // the descent reads as a couple and the card offers to add one.
                // Only when there are children: childless half-couples add noise.
                if ($children !== [] && count($parents) === 1) {
                    $phantom = $this->addPhantomSpouse($this->nodes[$parents[0]], $family);
                    if ($phantom !== null) {
                        $parents[] = $phantom;
                    }
                }

                // Childless couples are emitted too (the renderer skips them for
                // layout junctions) so the editing UI knows every union's famId
                // and which HUSB/WIFE slot is genuinely empty in the records.
                $this->families[] = [
                    'id'          => $family->xref(),
                    'parents'     => $parents,
                    'children'    => $children,
                    // children[] only lists MATERIALISED nodes; hasChildren is
                    // the record truth, so the edit UI never offers to delete
                    // a union whose children just fell outside the clipping.
                    'hasChildren' => $family->children()->isNotEmpty(),
                    'husb'        => $family->husband()?->xref(),
                    'wife'        => $family->wife()?->xref(),
                    'pending'     => $this->pending[$family->xref()] ?? null,
                    'marriage'    => $this->marriageInfo($family),
                ];
            }
        }
    }

    /**
     * Marriage summary for a union's clickable badge: year, record status
     * (married / divorced) and whether the anniversary falls within a week
     * (both spouses alive and still married). Null when nothing is recorded
     * or the family is private.
     *
     * @return array<string, mixed>|null
     */
    private function marriageInfo(Family $family): ?array
    {
        if (!$family->canShow()) {
            return null;
        }

        $date = $family->getMarriageDate();
        $year = $date->minimumDate()->year ?: null;

        $status = null;
        if ($family->facts(['DIV'])->isNotEmpty()) {
            $status = 'DIV';
        } elseif ($family->facts(['MARR'])->isNotEmpty()) {
            $status = 'MARR';
        }

        if ($year === null && $status === null) {
            return null;
        }

        $alive = $status === 'MARR';
        foreach ($family->spouses() as $spouse) {
            if ($spouse->isDead()) {
                $alive = false;
            }
        }

        return [
            'year'   => $year,
            'status' => $status,
            'soon'   => $alive && $this->isUpcoming($date),
        ];
    }

    /**
     * Does the month/day of this date fall within the next 7 days? Drives the
     * birthday/anniversary badges. Non-gregorian calendars compare their own
     * month numbers — close enough for a decorative hint.
     */
    private function isUpcoming(Date $date): bool
    {
        $min = $date->minimumDate();
        if ($min->day() === 0 || $min->month() === 0) {
            return false;
        }

        $today = new \DateTimeImmutable('today');
        for ($i = 0; $i <= 7; $i++) {
            $d = $today->modify('+' . $i . ' days');
            if ((int) $d->format('n') === $min->month() && (int) $d->format('j') === $min->day()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a placeholder card for the unknown spouse of a single-parent family,
     * sitting on the known parent's row. Returns its synthetic id, or null when
     * the node limit is reached.
     *
     * @param array<string, mixed> $known the known parent's node
     */
    private function addPhantomSpouse(array $known, Family $family): ?string
    {
        if ($this->nodeCount >= $this->limit) {
            return null;
        }

        // Id DETERMINISTA (solo el xref de la familia, una familia solo puede
        // tener un fantasma): con el ordinal por-build de antes, dos grafos de
        // vecindarios distintos traían el MISMO fantasma con ids diferentes y
        // el merge aditivo del cliente sembraba «Desconocidos» duplicados
        // descolgados de su barra.
        $id  = '__phantom-' . $family->xref();
        $this->phantomCount++;
        $sex = $known['sex'] === 'M' ? 'F' : ($known['sex'] === 'F' ? 'M' : 'U');

        $this->nodes[$id] = [
            'id'         => $id,
            'type'       => 'phantom',
            'label'      => I18N::translate('Unknown'),
            'url'        => null,
            'sex'        => $sex,
            'lifespan'   => null,
            'generation' => $known['generation'],
            'thumbnail'  => null,
            'isRoot'     => false,
            'isImplex'   => false,
            'limited'    => false,
            'phantom'    => true,
            // The client completes the family through its own popup; only the
            // capability travels in the JSON, never a page URL.
            'canComplete' => $family->canEdit(),
            'famId'      => $family->xref(),
            'canEdit'    => $family->canEdit(),
            'pending'    => null,
        ];
        $this->nodeCount++;

        return $id;
    }

    private function reset(Individual $root, int $limit, bool $show_photos, bool $show_dates): void
    {
        $this->nodes      = [];
        $this->edges      = [];
        $this->families   = [];
        $this->visited    = [];
        $this->ref        = [];
        $this->nodeCount  = 0;
        $this->phantomCount = 0;
        $this->limit      = max($limit, 1);
        $this->truncated  = false;
        $this->rootXref   = $root->xref();
        $this->showPhotos = $show_photos;
        $this->showDates  = $show_dates;
    }

    /**
     * Adds a node if new. If the individual is already present (implex — reached
     * via a second path), the existing node is flagged instead of being silently
     * dropped, so the caller can still draw the connecting edge.
     *
     * @return string 'new'|'implex'|'limit'
     */
    private function addNode(Individual $individual, int $generation): string
    {
        $xref = $individual->xref();

        if (isset($this->visited[$xref])) {
            $this->nodes[$xref]['isImplex'] = true;
            return 'implex';
        }

        if ($this->nodeCount >= $this->limit) {
            $this->truncated = true;
            return 'limit';
        }

        $this->visited[$xref] = true;
        $this->ref[$xref]     = $individual;

        // Privacy first: only compute the expensive fields (name, dates, photo) for
        // data the viewer may actually see. This is the single pass that used to be
        // split between the builder and a separate presenter.
        $can_show_name = $individual->canShowName();
        $can_show      = $individual->canShow();

        if (!$can_show_name) {
            $label     = '?';
            $lifespan  = null;
            $thumbnail = null;
        } elseif (!$can_show) {
            // Name visible but other data (dates, photo) hidden.
            $label     = strip_tags($individual->fullName());
            $lifespan  = null;
            $thumbnail = null;
        } else {
            $label     = strip_tags($individual->fullName());
            $lifespan  = $this->showDates ? $this->getLifespan($individual) : null;
            $thumbnail = $this->showPhotos ? $this->getThumbnail($individual) : null;
        }

        // Apellido del nombre primario (modo «Apellidos» del abanico). Los
        // marcadores GEDCOM de apellido desconocido (@N.N.) se limpian.
        $surname = '';
        if ($can_show_name) {
            $names   = $individual->getAllNames();
            $primary = $names[$individual->getPrimaryName()] ?? null;
            $surname = trim(str_replace('@N.N.', '', $primary['surname'] ?? ''));
        }

        // Edit capabilities, surfaced as the "+" and camera controls on each
        // card. Bare flags: the client's popups resolve their own endpoints
        // through the EditContext, so no page URL travels in the graph JSON.
        $can_edit = $can_show && $individual->canEdit();
        // Media uploads have their own gate (tree preference MEDIA_UPLOAD);
        // mirror the core UI, which hides "add media" when it is not met.
        $can_add_media = $can_edit && Auth::canUploadMedia($individual->tree(), Auth::user());

        $celebration = null;
        if ($can_show && !$individual->isDead() && $this->isUpcoming($individual->getBirthDate())) {
            $celebration = 'birthday';
        }

        $this->nodes[$xref] = [
            'id' => $xref,
            'type' => 'individual',
            'label' => $label,
            'surname' => $surname,
            'url' => $individual->url(),
            'sex' => $individual->sex(),
            'lifespan' => $lifespan,
            'generation' => $generation,
            'thumbnail' => $thumbnail,
            'isRoot' => $xref === $this->rootXref,
            'isImplex' => false,
            'limited' => !$can_show && $can_show_name,
            'canAddMedia' => $can_add_media,
            'celebration' => $celebration,
            // Verdad del registro (no del recorte): sin familia de origen en
            // los DATOS. Alimenta los huecos «Añadir padre/madre» del cliente.
            'noParents' => $individual->childFamilies()->isEmpty(),
            'add' => $can_edit ? true : null,
            'canEdit' => $can_edit,
            'pending' => $this->pending[$xref] ?? null,
        ];
        $this->nodeCount++;

        return 'new';
    }

    private function addSiblings(Individual $root): void
    {
        foreach ($root->childFamilies() as $family) {
            foreach ($family->children() as $sibling) {
                if ($sibling->xref() === $root->xref()) {
                    continue;
                }

                $status = $this->addNode($sibling, 0);

                if ($status === 'limit') {
                    return;
                }

                $this->addEdge($root->xref(), $sibling->xref(), 'SIBLING', $family->xref());
            }
        }
    }

    /**
     * @param Individual[] $frontier
     * @return Individual[] next ancestor frontier
     */
    private function expandAncestorLevel(array $frontier, int $generation): array
    {
        $next = [];

        foreach ($frontier as $individual) {
            foreach ($individual->childFamilies() as $family) {
                foreach ($family->spouses() as $parent) {
                    $status = $this->addNode($parent, $generation);

                    if ($status === 'limit') {
                        return $next;
                    }

                    $this->addEdge($individual->xref(), $parent->xref(), 'CHILD', $family->xref());

                    if ($status === 'new') {
                        $next[] = $parent;
                    }
                }
            }
        }

        return $next;
    }

    /**
     * @param Individual[] $frontier
     * @return Individual[] next descendant frontier
     */
    private function expandDescendantLevel(array $frontier, int $generation, bool $include_spouses): array
    {
        $next = [];
        $child_generation = $generation - 1;

        foreach ($frontier as $individual) {
            foreach ($individual->spouseFamilies() as $family) {
                if ($include_spouses) {
                    foreach ($family->spouses() as $spouse) {
                        if ($spouse->xref() === $individual->xref()) {
                            continue;
                        }

                        $status = $this->addNode($spouse, $generation);

                        if ($status === 'limit') {
                            return $next;
                        }

                        $this->addEdge($individual->xref(), $spouse->xref(), 'SPOUSE', $family->xref());
                    }
                }

                foreach ($family->children() as $child) {
                    $status = $this->addNode($child, $child_generation);

                    if ($status === 'limit') {
                        return $next;
                    }

                    $this->addEdge($individual->xref(), $child->xref(), 'PARENT', $family->xref());

                    if ($status === 'new') {
                        $next[] = $child;
                    }
                }
            }
        }

        return $next;
    }

    private function addEdge(string $from, string $to, string $kind, ?string $famId = null): void
    {
        // A couple can hold several FAM records (divorce + remarriage): the
        // famId is part of a SPOUSE edge's identity, so each union gets its
        // own clickable bar instead of the first family swallowing the rest.
        $suffix  = $kind === 'SPOUSE' && $famId !== null ? '-' . $famId : '';
        $edge_id = $from . '-' . $to . '-' . $kind . $suffix;

        // For undirected relationships, avoid adding both directions as separate edges.
        if ($kind === 'SPOUSE' || $kind === 'SIBLING') {
            $reverse_id = $to . '-' . $from . '-' . $kind . $suffix;
            if (isset($this->edges[$reverse_id])) {
                return;
            }
        }

        if (!isset($this->edges[$edge_id])) {
            $this->edges[$edge_id] = [
                'id' => $edge_id,
                'from' => $from,
                'to' => $to,
                'kind' => $kind,
                'famId' => $famId,
            ];
        }
    }

    private function getLifespan(Individual $individual): ?string
    {
        $birth_year = $individual->getBirthDate()->minimumDate()->year ?: '';
        $death_year = $individual->getDeathDate()->minimumDate()->year ?: '';

        if ($birth_year || $death_year) {
            return trim($birth_year . '-' . $death_year, '-');
        }

        return null;
    }

    private function getThumbnail(Individual $individual): ?string
    {
        foreach ($individual->facts(['OBJE']) as $fact) {
            $media = $fact->target();
            if ($media !== null && $media->canShow()) {
                foreach ($media->mediaFiles() as $media_file) {
                    return $media_file->imageUrl(100, 100, 'crop');
                }
            }
        }

        return null;
    }

    /**
     * Marca con hasMore=true cada nodo materializado que tenga familia próxima
     * (padres, hermanos, cónyuges o hijos) visible para este usuario y ausente
     * del grafo — alimenta el icono «ver su familia cercana» (re-root).
     */
    private function markHasMore(): void
    {
        foreach ($this->ref as $xref => $individual) {
            if ($this->hasUnloadedFamily($individual)) {
                $this->nodes[$xref]['hasMore'] = true;
            }
        }
    }

    private function hasUnloadedFamily(Individual $individual): bool
    {
        $xref = $individual->xref();

        foreach ($individual->childFamilies() as $family) {
            foreach ($family->spouses() as $parent) {
                if (!isset($this->nodes[$parent->xref()]) && $parent->canShowName()) {
                    return true;
                }
            }
            foreach ($family->children() as $sibling) {
                $sx = $sibling->xref();
                if ($sx !== $xref && !isset($this->nodes[$sx]) && $sibling->canShowName()) {
                    return true;
                }
            }
        }

        foreach ($individual->spouseFamilies() as $family) {
            foreach ($family->spouses() as $spouse) {
                $sx = $spouse->xref();
                if ($sx !== $xref && !isset($this->nodes[$sx]) && $spouse->canShowName()) {
                    return true;
                }
            }
            foreach ($family->children() as $child) {
                if (!isset($this->nodes[$child->xref()]) && $child->canShowName()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getResult(): array
    {
        $this->markHasMore();

        return [
            'root' => $this->rootXref,
            'layout' => 'vertical',
            'nodes' => array_values($this->nodes),
            'edges' => array_values($this->edges),
            'families' => $this->families,
            'meta' => [
                'truncated' => $this->truncated,
                'limit' => $this->limit,
                'nodeCount' => $this->nodeCount,
                'edgeCount' => count($this->edges),
            ],
        ];
    }
}
