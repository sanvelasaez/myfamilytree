<?php

declare(strict_types=1);

namespace ImprovedTree;

use Fisharebest\Webtrees\Individual;

class GraphBuilder
{
    private array $nodes = [];
    private array $edges = [];
    private array $visited = [];
    private int $nodeCount = 0;
    private int $limit = 500;
    private bool $truncated = false;
    private string $rootXref = '';
    private bool $showPhotos = true;
    private bool $showDates = true;

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
                        $this->addEdge($xref, $parent->xref(), 'CHILD');
                    }
                }
            }

            if ($include_spouses) {
                foreach ($ind->spouseFamilies() as $family) {
                    foreach ($family->spouses() as $spouse) {
                        if ($spouse->xref() !== $xref && isset($this->nodes[$spouse->xref()])) {
                            $this->addEdge($xref, $spouse->xref(), 'SPOUSE');
                        }
                    }
                }
            }
        }

        return $this->getResult();
    }

    private function reset(Individual $root, int $limit, bool $show_photos, bool $show_dates): void
    {
        $this->nodes      = [];
        $this->edges      = [];
        $this->visited    = [];
        $this->nodeCount  = 0;
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

        $this->nodes[$xref] = [
            'id' => $xref,
            'type' => 'individual',
            'label' => $label,
            'url' => $individual->url(),
            'sex' => $individual->sex(),
            'lifespan' => $lifespan,
            'generation' => $generation,
            'thumbnail' => $thumbnail,
            'isRoot' => $xref === $this->rootXref,
            'isImplex' => false,
            'limited' => !$can_show && $can_show_name,
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

                $this->addEdge($root->xref(), $sibling->xref(), 'SIBLING');
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

                    $this->addEdge($individual->xref(), $parent->xref(), 'CHILD');

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

                        $this->addEdge($individual->xref(), $spouse->xref(), 'SPOUSE');
                    }
                }

                foreach ($family->children() as $child) {
                    $status = $this->addNode($child, $child_generation);

                    if ($status === 'limit') {
                        return $next;
                    }

                    $this->addEdge($individual->xref(), $child->xref(), 'PARENT');

                    if ($status === 'new') {
                        $next[] = $child;
                    }
                }
            }
        }

        return $next;
    }

    private function addEdge(string $from, string $to, string $kind): void
    {
        $edge_id = $from . '-' . $to . '-' . $kind;

        // For undirected relationships, avoid adding both directions as separate edges.
        if ($kind === 'SPOUSE' || $kind === 'SIBLING') {
            $reverse_id = $to . '-' . $from . '-' . $kind;
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

    private function getResult(): array
    {
        return [
            'root' => $this->rootXref,
            'layout' => 'vertical',
            'nodes' => array_values($this->nodes),
            'edges' => array_values($this->edges),
            'meta' => [
                'truncated' => $this->truncated,
                'limit' => $this->limit,
                'nodeCount' => $this->nodeCount,
                'edgeCount' => count($this->edges),
            ],
        ];
    }
}
