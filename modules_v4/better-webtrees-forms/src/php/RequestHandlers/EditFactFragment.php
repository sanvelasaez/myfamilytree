<?php

declare(strict_types=1);

namespace BetterWebtreesForms\RequestHandlers;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomEditService;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function redirect;
use function route;

/**
 * Espejo de core EditFactPage renderizado con `layouts/ajax` (solo el fragmento
 * del formulario, sin el chrome del layout completo → mucho más rápido). El
 * `action` del <form> lo genera la propia vista hacia el *Action de core, así
 * que el guardado (POST) sigue yendo al core sin cambios.
 *
 * Réplica de la lógica fina de core; ver nota de drift en CLAUDE.md.
 */
final class EditFactFragment implements RequestHandlerInterface
{
    use ViewResponseTrait;

    public function __construct(
        private readonly GedcomEditService $gedcom_edit_service,
    ) {
        // $layout viene del trait; no se puede redeclarar la propiedad con otro
        // valor inicial (PHP lo considera incompatible), así que se fija aquí.
        $this->layout = 'layouts/ajax';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree           = Validator::attributes($request)->tree();
        $xref           = Validator::attributes($request)->isXref()->string('xref');
        $fact_id        = Validator::attributes($request)->string('fact_id');
        $include_hidden = Validator::queryParams($request)->boolean('include_hidden', false);

        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        $record = Auth::checkRecordAccess($record, true);

        $fact = $record->facts()->first(fn (Fact $fact): bool => $fact->id() === $fact_id && $fact->canEdit());

        if ($fact === null) {
            return redirect($record->url());
        }

        $can_edit_raw = Auth::isAdmin() || $tree->getPreference('SHOW_GEDCOM_RECORD') === '1';

        $gedcom = $this->gedcom_edit_service->insertMissingFactSubtags($fact, $include_hidden);
        $hidden = $this->gedcom_edit_service->insertMissingFactSubtags($fact, true);
        $url    = Validator::queryParams($request)->isLocalUrl()->string('url', $record->url());

        if ($gedcom === $hidden) {
            $hidden_url = '';
        } else {
            $hidden_url = route(self::class, [
                'fact_id'        => $fact_id,
                'include_hidden' => true,
                'tree'           => $tree->name(),
                'url'            => $url,
                'xref'           => $xref,
            ]);
        }

        $title = $record->fullName() . ' - ' . $fact->label();

        return $this->viewResponse('edit/edit-fact', [
            'can_edit_raw' => $can_edit_raw,
            'fact'         => $fact,
            'gedcom'       => $gedcom,
            'hidden_url'   => $hidden_url,
            'title'        => $title,
            'tree'         => $tree,
            'url'          => $url,
        ]);
    }
}
