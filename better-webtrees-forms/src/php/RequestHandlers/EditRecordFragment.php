<?php

declare(strict_types=1);

namespace BetterWebtreesForms\RequestHandlers;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomEditService;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_key_exists;
use function route;

/**
 * Espejo de core EditRecordPage renderizado con `layouts/ajax`. Ver EditFactFragment.
 */
final class EditRecordFragment implements RequestHandlerInterface
{
    use ViewResponseTrait;

    public function __construct(
        private readonly GedcomEditService $gedcom_edit_service,
    ) {
        // $layout viene del trait; se fija aquí (no se puede redeclarar).
        $this->layout = 'layouts/ajax';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree           = Validator::attributes($request)->tree();
        $xref           = Validator::attributes($request)->isXref()->string('xref');
        $record         = Registry::gedcomRecordFactory()->make($xref, $tree);
        $record         = Auth::checkRecordAccess($record, true);
        $include_hidden = Validator::queryParams($request)->boolean('include_hidden', false);
        $can_edit_raw   = Auth::isAdmin() || $tree->getPreference('SHOW_GEDCOM_RECORD') === '1';
        $subtags        = Registry::elementFactory()->make($record->tag())->subtags();

        $gedcom = $this->gedcom_edit_service->insertMissingRecordSubtags($record, $include_hidden);
        $hidden = $this->gedcom_edit_service->insertMissingRecordSubtags($record, true);

        if ($gedcom === $hidden) {
            $hidden_url = '';
        } else {
            $hidden_url = route(self::class, [
                'include_hidden' => true,
                'tree'           => $tree->name(),
                'xref'           => $xref,
            ]);
        }

        return $this->viewResponse('edit/edit-record', [
            'can_edit_raw' => $can_edit_raw,
            'gedcom'       => $gedcom,
            'has_chan'     => array_key_exists('CHAN', $subtags),
            'hidden_url'   => $hidden_url,
            'record'       => $record,
            'title'        => $record->fullName(),
            'tree'         => $tree,
        ]);
    }
}
