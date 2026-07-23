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
        $this->nodes = [];
        $this->edges = [];
        $this->visited = [];
        $this->nodeCount = 0;
        $this->limit = max($limit, 1);
        $this->truncated = false;
        $this->rootXref = $root->xref();

        $this->addNode($root, 0);

        if ($include_siblings && !$this->truncated) {
            $this->addSiblings($root);
        }

        $ancestor_frontier = [$root];
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
            'label' => $individual->fullName(),
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
        $birth_year = '';
        $death_year = '';

        foreach ($individual->facts(['BIRT']) as $fact) {
            $birth_year = $fact->date()->minimumDate()->year() ?? '';
            if ($birth_year) {
                break;
            }
        }

        foreach ($individual->facts(['DEAT']) as $fact) {
            $death_year = $fact->date()->minimumDate()->year() ?? '';
            if ($death_year) {
                break;
            }
        }

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
