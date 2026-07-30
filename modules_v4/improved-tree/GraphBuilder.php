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

    public function buildGraph(
        Individual $root,
        int $ancestor_depth = 4,
        int $descendant_depth = 4,
        bool $include_spouses = true,
        bool $include_siblings = false,
        int $limit = 500
    ): array {
        $this->reset($root, $limit);

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
     * Build a graph showing all individuals within the given civil-law consanguinity degree.
     *
     * Civil-law degree (Spanish): number of genealogical steps between two persons through
     * their nearest common ancestor (the ancestor itself is not counted).
     *   - Parents/children: degree 1
     *   - Siblings / grandparents: degree 2
     *   - Uncles/aunts / great-grandparents: degree 3
     *   - First cousins: degree 4
     *   - Children of first cousins: degree 5
     *
     * Algorithm:
     *   Phase 1 — BFS upward: collect all ancestors with depth k (generations above root).
     *   Phase 2 — BFS downward from each ancestor: expand up to (degree - k) steps down.
     *   Generation of a node = k - d  (positive = above root, negative = below root).
     *   Consanguinity degree of that node = k + d.
     */
    public function buildGraphByDegree(
        Individual $root,
        int $degree,
        bool $include_spouses = true,
        int $limit = 500
    ): array {
        $this->reset($root, $limit);

        // Phase 1: BFS upward — collect all ancestors with their depth k above root.
        // Stop expanding when k >= degree (nothing useful can be reached further up).
        $ancestors = [$root->xref() => [$root, 0]];
        $up_queue  = [[$root, 0]];
        $uqi       = 0;

        while ($uqi < count($up_queue)) {
            [$ind, $k] = $up_queue[$uqi++];

            if ($k >= $degree) {
                continue;
            }

            foreach ($ind->childFamilies() as $family) {
                foreach ($family->spouses() as $parent) {
                    $pxref = $parent->xref();
                    if (!isset($ancestors[$pxref])) {
                        $ancestors[$pxref] = [$parent, $k + 1];
                        $up_queue[] = [$parent, $k + 1];
                    }
                }
            }
        }

        // Phase 2: from each ancestor at depth k, BFS downward up to (degree - k) steps.
        foreach ($ancestors as [$ancestor, $k]) {
            if ($this->truncated) {
                break;
            }

            $this->addNode($ancestor, $k);

            $down_queue = [[$ancestor, 0]];
            $dqi        = 0;

            while ($dqi < count($down_queue)) {
                [$ind, $d] = $down_queue[$dqi++];

                if ($degree - $k - $d <= 0) {
                    continue;
                }

                $gen = $k - $d;

                foreach ($ind->spouseFamilies() as $family) {
                    if ($include_spouses) {
                        foreach ($family->spouses() as $spouse) {
                            if ($spouse->xref() === $ind->xref()) {
                                continue;
                            }
                            $status = $this->addNode($spouse, $gen);
                            if ($status === 'limit') {
                                return $this->getResult();
                            }
                            $this->addEdge($ind->xref(), $spouse->xref(), 'SPOUSE');
                        }
                    }

                    foreach ($family->children() as $child) {
                        $status = $this->addNode($child, $gen - 1);
                        if ($status === 'limit') {
                            return $this->getResult();
                        }
                        $this->addEdge($child->xref(), $ind->xref(), 'CHILD');
                        if ($status === 'new') {
                            $down_queue[] = [$child, $d + 1];
                        }
                    }
                }
            }
        }

        return $this->getResult();
    }

    private function reset(Individual $root, int $limit): void
    {
        $this->nodes     = [];
        $this->edges     = [];
        $this->visited   = [];
        $this->nodeCount = 0;
        $this->limit     = max($limit, 1);
        $this->truncated = false;
        $this->rootXref  = $root->xref();
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
        $this->nodes[$xref] = [
            'id' => $xref,
            'type' => 'individual',
            'label' => strip_tags($individual->fullName()),
            'url' => $individual->url(),
            'sex' => $individual->sex(),
            'lifespan' => $this->getLifespan($individual),
            'generation' => $generation,
            'thumbnail' => null,
            'isRoot' => $xref === $this->rootXref,
            'isImplex' => false,
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
