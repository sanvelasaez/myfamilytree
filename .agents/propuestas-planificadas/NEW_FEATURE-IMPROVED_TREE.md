# NEW FEATURE: Improved Tree Module para webtrees

Fecha de planificacion: 2026-07-09

## Objetivo

Crear un nuevo modulo custom para webtrees 2.2.x que permita visualizar el arbol genealogico de forma mas avanzada que el arbol interactivo nativo.

La primera version debe centrarse en una visualizacion clasica vertical, publica para visitantes, respetando la privacidad global ya configurada en webtrees.

El modulo debe convertirse en la pestana favorita de la pagina de cada individuo colocando su tab como primer tab en la configuracion de webtrees, sin modificar core.

## Alcance confirmado

- Tamano esperado del arbol: entre 1000 y 2000 personas.
- Visibilidad: publico para todo el mundo.
- Privacidad: usar las reglas globales de webtrees; no crear una privacidad paralela.
- Primera vista: arbol clasico vertical.
- Futuro: permitir varios modos seleccionables.
- Prioridad de desarrollo: modulo nuevo, no parche del core.
- Nombre de trabajo del modulo: `improved-tree`.
- Carpeta runtime esperada: `modules_v4/improved-tree`.
- Nombre interno webtrees esperado: `_improved-tree_`.

## Decision tecnica principal

Crear un modulo nuevo que implemente estas interfaces:

```php
ModuleCustomInterface
ModuleTabInterface
ModuleChartInterface
ModuleConfigInterface
```

Esto permite que el modulo aparezca como:

- Pestana nueva en la pagina de cada individuo.
- Grafico nuevo en el menu de graficos/genealogia.
- Modulo configurable desde administracion.
- Pestana favorita mediante orden de tabs.

No se debe modificar `app/`, `resources/`, `public/` ni `vendor/` salvo peticion explicita.

## Por que no extender directamente el arbol interactivo nativo

El modulo nativo esta en:

```text
app/Module/InteractiveTreeModule.php
```

La clase de render interno esta en:

```text
app/Module/InteractiveTree/TreeView.php
```

Motivos para no reutilizarla directamente:

- Esta acoplada al modulo core `tree`.
- Genera rutas AJAX hardcodeadas hacia `module=tree`.
- Usa IDs globales como `tv_tree`, `tv_tools`, `tvbCompact`.
- Tiene metodos privados que dificultan sobrescritura limpia.
- La personalizacion seria fragil frente a upgrades.

Conclusion: usarla como referencia conceptual, no como clase base.

## Referencias existentes para estudiar o forkear

Estas opciones sirven como referencia tecnica. Antes de forkear, revisar licencia, compatibilidad con webtrees 2.2, dependencias y estado del repositorio.

### 1. Interactive Tree XT

Repositorio:

```text
https://github.com/huhwt/huhwt-xtv
```

Pagina oficial de modulos:

```text
https://webtrees.net/download/modules
```

Valor como referencia:

- Es el candidato mas cercano al objetivo funcional.
- Puede reemplazar o complementar el Interactive Tree nativo.
- Soporta expansion paso a paso.
- Soporta ocultar/mostrar subarboles y ramas familiares.
- Soporta PageMap/minimapa.
- Soporta exportacion PNG.
- Soporta estadisticas de personas mostradas.
- Maneja implex y multiples relaciones mejor que el core.

Riesgo:

- Puede ser complejo para forkear si la arquitectura interna esta muy acoplada a su propio modelo.
- Hay que revisar si conviene fork completo o solo tomar ideas de UX/algoritmo.

Recomendacion:

```text
Usarlo como primera referencia de comportamiento.
No asumir que es la base final sin revisar codigo.
```

### 2. MagicSunday Pedigree Chart

Repositorio:

```text
https://github.com/magicsunday/webtrees-pedigree-chart
```

Valor como referencia:

- Modulo webtrees moderno.
- Usa D3.js y SVG.
- Tiene multiples layouts.
- Tiene pan/zoom y exportaciones.
- Buen ejemplo de empaquetado frontend dentro de un modulo webtrees.
- Buen ejemplo para construir un layout vertical de ancestros.

Riesgo:

- Esta mas orientado a pedigree/ancestros que a arbol completo.
- No cubre toda la necesidad, pero es muy buena referencia frontend.

Recomendacion:

```text
Estudiarlo para arquitectura JS/CSS, empaquetado, D3 y experiencia de usuario.
```

### 3. MagicSunday Descendants Chart

Repositorio:

```text
https://github.com/magicsunday/webtrees-descendants-chart
```

Valor como referencia:

- Modulo SVG/D3 para descendientes.
- Muestra conyuges.
- Soporta hasta 25 generaciones.
- Buena referencia para orientacion top-to-bottom.
- Buena referencia para instalacion con assets ya compilados.

Riesgo:

- Esta orientado a descendientes, no a vista combinada ascendientes/descendientes.

Recomendacion:

```text
Estudiarlo para el modo vertical clasico de primera version.
```

### 4. MagicSunday Fan Chart

Repositorio:

```text
https://github.com/magicsunday/webtrees-fan-chart
```

Valor como referencia:

- Bueno para futuro modo radial.
- Usa D3/SVG.
- Tiene interactividad y exportacion.

Riesgo:

- No sirve como primera base para formato vertical clasico.

Recomendacion:

```text
Guardarlo para una futura vista radial.
```

### 5. H&Hwt LINEAGE

Repositorio:

```text
https://github.com/huhwt/huhwt-wtlin
```

Valor como referencia:

- Usa D3 force simulation.
- Representa personas como nodos y relaciones como enlaces.
- Es util para una futura vista de grafo libre.
- Documenta problemas de rendimiento con miles de nodos.

Riesgo:

- Depende del flujo CCE segun su documentacion.
- No parece pensado como visualizador principal independiente.
- Force layout no es el primer objetivo si queremos arbol vertical clasico.

Recomendacion:

```text
Usarlo como referencia para futuro modo grafo, no para la primera version.
```

### 6. Fancy Treeview local

Modulo ya instalado localmente:

```text
modules_v4/jc-fancy-treeview
```

Valor como referencia:

- Ejemplo real de modulo custom instalado.
- Implementa `ModuleCustomInterface`, `ModuleConfigInterface`, `ModuleGlobalInterface`, `ModuleTabInterface`, `ModuleMenuInterface` y `ModuleBlockInterface`.
- Registra namespace de vistas con `View::registerNamespace()`.
- Usa `resourcesFolder()`.
- Tiene vistas, CSS, traducciones y configuracion.

Riesgo:

- Es narrativo, no visual interactivo.
- No debe copiarse como solucion del arbol grafico.

Recomendacion:

```text
Usarlo como referencia local de estructura webtrees, no de visualizacion.
```

## Recomendacion de fork

Para este proyecto, la estrategia mas pragmatica es:

1. Revisar primero `huhwt/huhwt-xtv`.
2. Revisar despues `magicsunday/webtrees-descendants-chart`.
3. Si `huhwt/huhwt-xtv` esta razonablemente desacoplado, valorar fork.
4. Si `huhwt/huhwt-xtv` esta demasiado acoplado, crear modulo propio desde cero usando ideas de XTV y arquitectura frontend de MagicSunday.

Decision recomendada por defecto:

```text
Crear modulo propio desde cero y usar XTV/MagicSunday como referencias.
```

Motivo:

```text
Con 1000-2000 personas, no hace falta una arquitectura extrema. Un modulo propio con JSON incremental, SVG y limites razonables sera mas mantenible que adaptar un modulo complejo.
```

## Arquitectura propuesta

Proyecto fuente recomendado:

```text
D:\workspace\docker\www\improved-tree
```

Salida runtime:

```text
D:\workspace\docker\www\webtrees\modules_v4\improved-tree
```

Estructura:

```text
improved-tree/
  module.php
  ImprovedTreeModule.php
  src/
    Graph/
      GraphBuilder.php
      GraphNode.php
      GraphEdge.php
      GraphResult.php
    Http/
      GraphRequest.php
    Presentation/
      NodePresenter.php
    Support/
      ModulePreferences.php
  resources/
    views/
      tab.phtml
      page.phtml
      admin.phtml
      partials/
        toolbar.phtml
    css/
      improved-tree.css
    js/
      improved-tree.js
```

Para la primera version se puede simplificar:

```text
improved-tree/
  module.php
  ImprovedTreeModule.php
  GraphBuilder.php
  NodePresenter.php
  resources/
    views/
      tab.phtml
      page.phtml
      admin.phtml
    css/
      improved-tree.css
    js/
      improved-tree.js
```

## Contrato PHP del modulo

Clase principal:

```php
final class ImprovedTreeModule extends AbstractModule implements
    ModuleCustomInterface,
    ModuleTabInterface,
    ModuleChartInterface,
    ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleTabTrait;
    use ModuleChartTrait;
    use ModuleConfigTrait;

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function defaultTabOrder(): int
    {
        return 0;
    }

    public function canLoadAjax(): bool
    {
        return true;
    }
}
```

Metodos necesarios:

```text
title()
description()
customModuleAuthorName()
customModuleVersion()
resourcesFolder()
boot()
tabTitle()
defaultTabOrder()
hasTabContent()
getTabContent()
chartTitle()
chartUrl()
getChartAction()
getGraphAction()
getAdminAction()
postAdminAction()
```

Metodos opcionales para fases futuras:

```text
getNodeAction()
getSearchAction()
getExportPngAction()
getExportSvgAction()
```

## Tab de individuo

La pestana debe renderizar solo un contenedor inicial.

No debe imprimir todo el arbol en HTML.

`getTabContent()` debe devolver una vista similar a:

```php
return view($this->name() . '::tab', [
    'module'     => $this,
    'tree'       => $individual->tree(),
    'individual' => $individual,
    'graph_url'  => route('module', [
        'module' => $this->name(),
        'action' => 'Graph',
        'tree'   => $individual->tree()->name(),
        'xref'   => $individual->xref(),
        'context'=> 'tab',
    ]),
]);
```

La vista `tab.phtml` debe incluir:

```text
Contenedor raiz
Toolbar compacta
Selector de modo futuro, aunque en v1 solo este vertical
Boton centrar persona raiz
Boton expandir/contraer si se implementa
Script que inicializa el renderer
```

IDs y clases con prefijo propio:

```text
itree-root
itree-toolbar
itree-canvas
itree-node
itree-edge
```

No usar:

```text
tv_tree
tv_tools
tv_button
```

## Chart independiente

El modulo tambien debe aparecer como grafico.

El chart debe permitir una pantalla mas amplia que la pestana.

`getChartAction()` debe:

- Validar usuario/arbol/individuo.
- Comprobar acceso al componente `ModuleChartInterface`.
- Renderizar `page.phtml`.
- Incluir controles mas completos que el tab.

La vista chart puede ofrecer desde v1:

- Selector de persona raiz.
- Orientacion vertical.
- Profundidad ascendiente.
- Profundidad descendiente.
- Mostrar/ocultar conyuges.
- Mostrar/ocultar fotos.
- Boton pantalla completa.

## Endpoint JSON

Endpoint principal:

```text
GET /module/{module}/Graph
```

Metodo:

```php
public function getGraphAction(ServerRequestInterface $request): ResponseInterface
```

Parametros:

```text
tree
xref
context=tab|chart
mode=vertical
ancestor_depth
descendant_depth
include_spouses
include_siblings
show_photos
show_dates
limit
```

Valores por defecto recomendados para v1:

```text
mode=vertical
ancestor_depth=4
descendant_depth=4
include_spouses=true
include_siblings=false
show_photos=true
show_dates=true
limit=500
```

Limites recomendados:

```text
Visitante: max 500 nodos por request
Usuario autenticado: max 1000 nodos por request
Admin: max 2000 nodos por request
```

Como el arbol total tiene 1000-2000 personas, el renderer puede soportarlo, pero no conviene abrir todo por defecto en la pestana de individuo.

## Respuesta JSON

Formato recomendado:

```json
{
  "root": "I1",
  "layout": "vertical",
  "nodes": [
    {
      "id": "I1",
      "type": "individual",
      "label": "Juan Perez",
      "url": "...",
      "sex": "M",
      "lifespan": "1901-1980",
      "generation": 0,
      "thumbnail": null,
      "isRoot": true
    }
  ],
  "edges": [
    {
      "id": "I1-F1",
      "from": "I1",
      "to": "F1",
      "kind": "FAMS"
    }
  ],
  "meta": {
    "truncated": false,
    "limit": 500,
    "nodeCount": 1,
    "edgeCount": 1
  }
}
```

No enviar GEDCOM crudo.

No enviar rutas de ficheros privados.

No enviar datos que webtrees no mostraria al visitante actual.

## Construccion del grafo

Para v1, usar APIs de dominio de webtrees:

```text
Individual::childFamilies()
Individual::spouseFamilies()
Family::spouses()
Family::children()
GedcomRecord::canShow()
GedcomRecord::canShowName()
```

Ventajas:

- Respeta mejor la privacidad ya configurada.
- Evita duplicar reglas GEDCOM.
- Reduce riesgo de exponer datos ocultos.

Para futuras optimizaciones, usar tabla `link`:

```text
link.l_from
link.l_to
link.l_type
link.l_file
```

Pero si se usa `link`, convertir siempre los XREFs a registros webtrees y filtrar permisos antes de responder.

## Algoritmo v1: vertical clasico

Modelo recomendado:

```text
Raiz: individuo actual.
Arriba: ascendientes.
Abajo: descendientes.
Laterales: conyuges.
Familias: nodos tecnicos invisibles o semi-visibles para ordenar enlaces.
```

Rama ascendente (recursiva, hasta `ancestor_depth`):

1. Crear nodo raiz, `generation = 0`.
2. Para el individuo en frontera actual, leer `childFamilies()`.
3. Por cada familia, agregar padre y madre como nodos, `generation = generation_actual + 1`.
4. Repetir sobre cada padre/madre agregado como nueva frontera.
5. Parar al llegar a `ancestor_depth` o a `limit`.
6. Regla obligatoria: al subir por un ascendiente, **no** expandir sus otras
   `spouseFamilies()` (hermanos, tios, primos del root). Solo se sigue
   `childFamilies()` del ascendiente en curso. Esto evita que el arbol
   explote lateralmente pese a `include_siblings=false`.

Rama descendente (recursiva, hasta `descendant_depth`, simetrica a la
ascendente, no limitada a una sola generacion):

1. Para el individuo en frontera actual (root en el primer paso), leer
   `spouseFamilies()`.
2. Por cada familia, agregar conyuge como nodo si `include_spouses=true`,
   `generation = generation_actual`.
3. Agregar cada hijo (`children()`) como nodo, `generation = generation_actual - 1`.
4. Cada hijo agregado se convierte en nueva frontera y repite desde el paso 1
   con su propia `spouseFamilies()`.
5. Repetir hasta llegar a `descendant_depth` o a `limit`.

Control de ciclos e implex:

6. Evitar bucles con set `visited` (por xref), compartido entre ambas ramas.
7. Detectar implex con `seenIndividuals`.
8. Marcar nodos repetidos con `duplicateOf` o `isImplex` en vez de re-expandirlos.

Orden de truncado al alcanzar `limit` (obligatorio, no dejar a criterio del
implementador):

```text
Recorrer por niveles (BFS), no en profundidad (DFS):
  nivel ascendente 1, nivel descendente 1, nivel ascendente 2, nivel descendente 2, ...
Al alcanzar `limit` nodos:
  detener el recorrido inmediatamente, sin completar el nivel a medias de forma arbitraria.
  marcar meta.truncated = true.
  meta.nodeCount y meta.edgeCount deben reflejar el resultado real devuelto, no el nominal.
```

Motivo: sin orden de truncado explicito, un DFS puede cortar un subarbol de
forma incoherente (por ejemplo, mostrar 3 de 5 hijos de una familia y ningun
nieto, en vez de mostrar generaciones completas antes de profundizar mas).

El layout JS puede recibir `generation`:

```text
Ancestros: generation positiva
Raiz: generation 0
Descendientes: generation negativa
```

## Frontend v1

Renderer recomendado:

```text
SVG + JavaScript propio
```

Por el tamano esperado, SVG es suficiente si se limita el render inicial.

Evitar CDN. Empaquetar dependencias dentro del modulo.

Opciones:

```text
Opcion A: JS propio sin libreria externa para v1.
Opcion B: D3.js vendorizado en resources/js/vendor para pan/zoom/layout.
Opcion C: usar ELK/Dagre si el layout vertical manual se vuelve complejo.
```

Recomendacion:

```text
Usar D3.js vendorizado si se toma inspiracion de MagicSunday.
Usar JS propio si se quiere minimizar dependencias en v1.
```

Interacciones v1:

- Pan.
- Zoom.
- Centrar raiz.
- Click en persona abre su URL webtrees.
- Hover o click muestra datos resumidos.
- Boton recargar con configuracion actual.
- Indicador si el resultado fue truncado.

Interacciones v2:

- Busqueda por nombre.
- Expandir rama bajo demanda.
- Colapsar rama.
- Minimap.
- Export PNG/SVG.
- Modo radial.
- Modo grafo libre.
- Modo descendientes puro.
- Modo ascendientes puro.

## Configuracion admin

Preferencias globales del modulo:

```text
default_mode=vertical
default_ancestor_depth=4
default_descendant_depth=4
default_include_spouses=1
default_include_siblings=0
default_show_photos=1
default_show_dates=1
max_nodes_guest=500
max_nodes_user=1000
max_nodes_admin=2000
enable_full_tree_mode=0
enable_export=0
```

Guardar con:

```php
$this->setPreference('default_mode', $mode);
```

Leer con:

```php
$this->getPreference('default_mode', 'vertical');
```

Validar con allowlists:

```text
mode: vertical, ancestors, descendants, radial, graph
ancestor_depth: 0-25
descendant_depth: 0-25
max_nodes_guest: 100-2000
max_nodes_user: 100-2000
max_nodes_admin: 100-5000
```

Aunque la privacidad sea global, el modulo debe seguir usando las APIs de webtrees para que la privacidad global se aplique correctamente.

## Convertirlo en pestana favorita

No requiere codigo especial.

Pasos:

1. Instalar el modulo en `modules_v4/improved-tree`.
2. Activarlo en `Admin -> Control panel -> Modules -> All modules`.
3. Ir a `Admin -> Control panel -> Modules -> Tabs`.
4. Mover `Improved tree` al primer lugar.
5. Guardar.
6. Si se quiere sustituir visualmente al tab nativo, cambiar el acceso del tab nativo `tree` a `Hide from everyone`.
7. No desactivar globalmente el modulo `tree` si se quiere conservar su chart nativo.

Motivo:

```text
webtrees abre la primera pestana disponible cuando no hay hash en la URL.
```

Hash esperado:

```text
#tab-_improved-tree_
```

## Seguridad minima obligatoria

Aunque sea publico, no omitir seguridad.

Reglas:

- Validar `tree`, `xref`, `context`, `mode`, limites y booleanos.
- Aplicar `Auth::checkComponentAccess()`.
- Aplicar `Auth::checkIndividualAccess()` al individuo raiz.
- Filtrar cada individuo/familia con `canShow()` cuando proceda.
- Usar `canShowName()` para nombres.
- No devolver GEDCOM crudo.
- No devolver rutas internas de `data/`.
- No crear endpoints de export anonimos en v1.
- Limitar profundidad y numero de nodos.
- Escapar HTML en vistas.
- No insertar HTML recibido del JSON sin control.

Nota:

```text
La privacidad global de webtrees no significa "no validar nada". Significa que el modulo debe consultar los datos a traves de APIs que ya aplican esa privacidad.
```

El filtro interno de `Family::children()` usa `canShowName()`, no `canShow()`
completo. Es decir, un hijo puede aparecer en el arbol porque su nombre es
visible, pero eso no garantiza acceso a sus demas datos.

Regla obligatoria para `NodePresenter`:

```text
Por cada nodo (ascendiente, descendiente o conyuge), antes de rellenar
lifespan, thumbnail o dates, volver a comprobar canShow() sobre ese
individuo/familia especifico. No asumir que "aparece en el arbol" implica
"puede mostrar todos sus datos".
Si canShow() es false pero canShowName() es true: mostrar solo el nombre,
sin lifespan, sin thumbnail, sin dates, marcando el nodo como "limitado".
```

## Desarrollo paso a paso

1. Crear proyecto fuente `D:\workspace\docker\www\improved-tree`.
2. Crear carpeta runtime `D:\workspace\docker\www\webtrees\modules_v4\improved-tree`.
3. Crear `module.php`.
4. Crear `ImprovedTreeModule.php`.
5. Implementar interfaces custom, tab, chart y config.
6. Registrar vistas en `boot()`.
7. Crear `resources/views/tab.phtml`.
8. Crear `resources/views/page.phtml`.
9. Crear `resources/views/admin.phtml`.
10. Crear `GraphBuilder`.
11. Crear `NodePresenter`.
12. Implementar `getGraphAction()`.
13. Crear `resources/js/improved-tree.js`.
14. Crear `resources/css/improved-tree.css`.
15. Renderizar SVG vertical basico.
16. Agregar pan/zoom.
17. Agregar toolbar compacta.
18. Agregar preferencias admin.
19. Activar modulo en UI.
20. Mover tab al primer lugar.
21. Probar con visitante.
22. Probar con usuario autenticado.
23. Probar con admin.

## Pruebas minimas

Lint PHP:

```powershell
php -l modules_v4\improved-tree\module.php
php -l modules_v4\improved-tree\ImprovedTreeModule.php
php -l modules_v4\improved-tree\GraphBuilder.php
php -l modules_v4\improved-tree\NodePresenter.php
```

Casos:

- Individuo sin familia.
- Individuo con padres.
- Individuo con hijos.
- Individuo con varias parejas.
- Familias numerosas.
- Arbol con 500 nodos.
- Arbol con 1000 nodos.
- Arbol con 2000 nodos si se habilita modo completo.
- Visitante anonimo.
- Usuario autenticado.
- Admin.
- Personas vivas o privadas.
- Tab como primera pestana.
- Chart independiente.
- Endpoint JSON directo sin permisos.
- Navegador movil.
- Navegador escritorio.
- Consola JS sin errores.

## Criterios de aceptacion v1

- El modulo aparece en la lista de modulos.
- El modulo aparece como tab de individuo.
- El modulo aparece como chart.
- El tab carga por AJAX.
- El chart carga como pagina completa.
- La vista vertical clasica muestra raiz, padres, conyuges e hijos.
- El usuario puede hacer pan y zoom.
- Click en una persona abre su ficha webtrees.
- Los datos respetan privacidad global.
- El tab puede colocarse primero y abrirse por defecto.
- No hay cambios en core.
- No hay dependencias remotas por CDN.
- No hay errores PHP ni JS evidentes.

## Fases futuras

### Fase 2

- Busqueda dentro del arbol mostrado.
- Expandir ramas bajo demanda.
- Colapsar ramas.
- Indicador de implex.
- Indicador de truncado.
- Pantalla completa.

### Fase 3

- Minimap/PageMap.
- Export PNG.
- Export SVG.
- Modo radial.
- Modo grafo libre.
- Modo solo ascendientes.
- Modo solo descendientes.

### Fase 4

- Cache por usuario/arbol/xref/config.
- Carga incremental con cursor.
- Web worker para layout si el render se vuelve pesado.

## Notas para otro modelo

Construir un modulo webtrees 2.2 llamado `improved-tree`, instalado en `modules_v4/improved-tree`, sin modificar core.

Debe implementar:

```php
ModuleCustomInterface
ModuleTabInterface
ModuleChartInterface
ModuleConfigInterface
```

Debe ofrecer:

- Pestana en pagina de individuo.
- Chart independiente.
- Configuracion admin.
- Endpoint JSON `Graph`.
- Renderer SVG vertical.
- Pan/zoom.
- Publico para visitantes.
- Respeto de privacidad global webtrees.

No debe:

- Reutilizar directamente `TreeView` como clase base.
- Usar IDs `tv_*`.
- Exponer GEDCOM crudo.
- Cargar librerias desde CDN.
- Tocar core.

Referencias principales:

```text
https://github.com/huhwt/huhwt-xtv
https://github.com/magicsunday/webtrees-descendants-chart
https://github.com/magicsunday/webtrees-pedigree-chart
https://github.com/magicsunday/webtrees-fan-chart
https://github.com/huhwt/huhwt-wtlin
https://webtrees.net/download/modules
https://github.com/webtrees/example-module
```
