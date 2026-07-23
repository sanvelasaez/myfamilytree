<?php

declare(strict_types=1);

namespace ImprovedTree;

use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

class NodePresenter
{
    private Tree $tree;

    public function __construct(Tree $tree)
    {
        $this->tree = $tree;
    }

    public function presentGraph(array $graph, bool $show_photos = true, bool $show_dates = true): array
    {
        $presented_nodes = [];

        foreach ($graph['nodes'] as $node) {
            $xref = $node['id'];
            $individual = Registry::individualFactory()->make($xref, $this->tree);

            if ($individual === null) {
                continue;
            }

            $can_show = $individual->canShow();
            $can_show_name = $individual->canShowName();

            $presented_node = $node;
            $presented_node['limited'] = !$can_show && $can_show_name;

            if (!$can_show_name) {
                $presented_node['label'] = '?';
                $presented_node['lifespan'] = null;
                $presented_node['thumbnail'] = null;
            } elseif (!$can_show) {
                // Name visible but other data (lifespan, thumbnail) hidden.
                $presented_node['lifespan'] = null;
                $presented_node['thumbnail'] = null;
            } else {
                if (!$show_dates) {
                    $presented_node['lifespan'] = null;
                }

                $presented_node['thumbnail'] = $show_photos ? $this->getThumbnail($individual) : null;
            }

            $presented_nodes[] = $presented_node;
        }

        $graph['nodes'] = $presented_nodes;

        return $graph;
    }

    private function getThumbnail($individual): ?string
    {
        foreach ($individual->facts(['OBJE']) as $fact) {
            $media = $fact->target();
            if ($media !== null && $media->canShow()) {
                foreach ($media->mediaFiles() as $media_file) {
                    return $media_file->imageUrl(100, 100, 'thumb');
                }
            }
        }

        return null;
    }
}
