<?php

declare(strict_types=1);

namespace Fisharebest\Webtrees\Module;

use BetterWebtreesForms\BetterWebtreesFormsModule;

// webtrees no autoloada las clases del módulo: se cargan a mano. Los handlers
// de fragmento deben estar declarados antes de que boot() registre sus rutas.
require_once __DIR__ . '/src/php/RequestHandlers/EditFactFragment.php';
require_once __DIR__ . '/src/php/RequestHandlers/AddFactFragment.php';
require_once __DIR__ . '/src/php/RequestHandlers/EditRecordFragment.php';
require_once __DIR__ . '/src/php/RequestHandlers/AddChildToIndividualFragment.php';
require_once __DIR__ . '/src/php/RequestHandlers/AddParentToIndividualFragment.php';
require_once __DIR__ . '/src/php/RequestHandlers/AddSpouseToIndividualFragment.php';

require __DIR__ . '/BetterWebtreesFormsModule.php';

return new BetterWebtreesFormsModule();
