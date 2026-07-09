# Project Guide For AI Agents

## Project Identity

This repository is a local webtrees installation used for a personal family-tree site.

- Application: webtrees, online collaborative genealogy.
- Local version: `2.2.6`, confirmed in `app/Webtrees.php`.
- Runtime module folder: `modules_v4/`.
- Main rule: do not edit upstream core unless explicitly asked. Runtime custom module output belongs in `modules_v4/`.
- Module/plugin source projects may live outside this repository and compile/sync into `modules_v4/`.
- This looks like an installed distribution, not a full upstream development checkout: `vendor/` exists, but `composer.json` and `package.json` are not present in this repo.
- `RTK.md` is a Claude Code global configuration file, not a local project file. Do not look for it in this repository.

## Important Local State

- Valid local custom module/theme: `modules_v4/argon-sanvelasaez`.
- `modules_v4/argon-sanvelasaez/module.php` returns `ArgonSanVelasaezTheme`.
- `argon-sanvelasaez` extends `Fisharebest\Webtrees\Module\MinimalTheme` and registers many custom views with `View::registerCustomView()`.
- `modules_v4/evang-argonlight` currently contains only empty/resource directories and no root `module.php`, so webtrees will ignore it as a module.
- Example external module/theme source project: `D:\workspace\docker\www\argon-sanvelasaez`.
- The external example has `composer.json`, `package.json`, `webpack.config.js`, `src/`, `resources/`, `module.php` and `KriptonTheme.php`.
- In the external example, `npm run dev` runs `webpack --watch` and syncs PHP files, views and compiled assets into `..\webtrees\modules_v4\kripton`.
- In the external example, `npm run build` runs vendor install, lint and production webpack output into `dist/`.
- `modules_v4/README.md` contains the upstream webtrees module notes, but terminal output may show mojibake for smart quotes. Keep new docs ASCII unless there is a reason to use Unicode.

## External Research Notes

Sources checked:

- Official modules/themes list: https://webtrees.net/download/modules
- Official install docs: https://webtrees.net/install/
- Official webtrees GitHub organization with example modules: https://github.com/webtrees
- Example base module: https://github.com/webtrees/example-module
- Example theme module: https://github.com/webtrees/example-module-theme
- Example footer/page module: https://github.com/webtrees/example-module-footer

Key findings:

- webtrees 2.0, 2.1 and 2.2 install third-party modules and themes by copying a folder into `modules_v4`.
- Module compatibility is strict. The official modules page says 2.2 modules run only with webtrees 2.2.
- For this local version, only install or create modules compatible with webtrees `2.2.x`.
- Official install docs map webtrees `2.2.6` to PHP `8.3` through `8.5`.
- The webtrees GitHub organization provides example modules. Use these as patterns before inventing structure.
- The official example module notes that every custom module must implement `ModuleCustomInterface`; other module interfaces are optional.
- The official theme example uses `assetUrl()` for CSS because module assets should be served via module callbacks rather than assuming direct public web access.

## Architecture Snapshot

- Entry point: `index.php` calls `Webtrees::new()->run(PHP_SAPI)`.
- Namespace: `Fisharebest\Webtrees`.
- Main app root: `app/`.
- Runtime data: `data/`.
- Public web assets and entry files: `public/` plus root `index.php`.
- Built-in views: `resources/views/`.
- Built-in modules and module interfaces/traits: `app/Module/`.
- Main module loading service: `app/Services/ModuleService.php`.
- Module route handler: `app/Http/RequestHandlers/ModuleAction.php`.

HTTP middleware order is defined in `app/Webtrees.php::MIDDLEWARE`. Important items:

- `ReadConfigIni` reads DB config from `data/config.ini.php`.
- `UseDatabase`, `UpdateDatabaseSchema`, `UseSession`, `UseLanguage`, `UseTheme` prepare runtime context.
- `LoadRoutes` loads web/API routes.
- `BootModules` boots enabled modules.
- `Router` dispatches the request.

## Custom Module Loading Rules

webtrees discovers custom modules with this pattern:

```text
modules_v4/*/module.php
```

A module folder is ignored when its folder name:

- contains `.`
- contains a space
- contains `[` or `]`
- is longer than 30 characters

`<module>.disable` works as a simple disable/hide mechanism because folders containing `.` are ignored.

For a valid custom module:

- `module.php` must return an object implementing `Fisharebest\Webtrees\Module\ModuleCustomInterface`.
- The runtime module name is set from the folder and wrapped in underscores, e.g. folder `my-module` becomes internal name `_my-module_`.
- Constructors can run even for disabled modules, so constructors must stay cheap and avoid relying on other modules.
- `boot()` runs for enabled modules during HTTP request handling.
- For theme modules, only the current active theme is booted.

Minimal pattern:

```php
<?php

declare(strict_types=1);

namespace Vendor\Webtrees\Example;

use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;

require __DIR__ . '/ExampleModule.php';

return new ExampleModule();
```

The class should normally:

- extend `AbstractModule` or a suitable existing core module/theme.
- implement `ModuleCustomInterface`.
- use `ModuleCustomTrait`.
- implement any feature interface needed, such as `ModuleBlockInterface`, `ModuleMenuInterface`, `ModuleTabInterface`, `ModuleSidebarInterface`, `ModuleGlobalInterface`, `ModuleThemeInterface`, `ModuleFooterInterface`, `ModuleReportInterface` or `ModuleConfigInterface`.
- use the matching trait when a matching trait exists.
- return `__DIR__ . '/resources/'` from `resourcesFolder()` when using module resources.

## Module Source Project Workflow

For custom modules/plugins, prefer a separate source repository instead of editing generated runtime files inside `webtrees/modules_v4` directly.

Example source project:

```text
D:\workspace\docker\www\argon-sanvelasaez
```

Observed source-project layout:

```text
module.php
KriptonTheme.php
composer.json
package.json
webpack.config.js
src/
resources/
dist/
```

Observed scripts:

```powershell
npm run dev
npm run build
```

Current example behavior:

- `npm run dev` uses webpack watch mode.
- Development output is copied directly to `D:\workspace\docker\www\webtrees\modules_v4\kripton`.
- PHP entry files and view overrides are copied from the source project into the runtime module folder.
- CSS/JS are compiled from `src/` into the runtime module `resources/` folder.
- Production output goes to `dist/` for packaging.
- The external source project's `composer.json` declares `fisharebest/webtrees: ^2.2` as a dev dependency.

Important:

- Treat `webtrees/modules_v4/*` as runtime output when there is a matching external source project.
- Before editing a module, check whether its source lives outside this repository.
- Prefer editing the source project, then compile/sync into `modules_v4`.
- Do not assume the source project folder name, runtime module folder name and PHP class name are identical. Verify `webpack.config.js`, `module.php` and README first.

## Module HTTP Actions

Module URLs are generated with:

```php
route('module', [
    'module' => $this->name(),
    'action' => 'Page',
    'tree' => $tree?->name(),
]);
```

`ModuleAction` maps the HTTP verb plus action to a method:

- `GET /module/{module}/Page` calls `getPageAction()`.
- `POST /module/{module}/Save` calls `postSaveAction()`.
- Any action containing `Admin` requires an administrator.
- The module itself must still validate tree-level access and other permissions.

For assets, prefer:

```php
$this->assetUrl('css/theme.css')
```

`ModuleCustomTrait::assetUrl()` serves files via `getAssetAction()`, adds a filemtime cache hash and rejects `..` path traversal.

## Views And Assets

Recommended custom module layout:

```text
modules_v4/my-module/
  module.php
  MyModule.php
  resources/
    views/
    css/
    js/
    img/
```

In `boot()`, register custom views/assets:

```php
View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
View::registerCustomView('::chart-box', $this->name() . '::chart-box');
```

Rules:

- Use `view($this->name() . '::view-name', [...])` for module views.
- Escape output with webtrees helpers such as `e()`.
- Do not assume files under a module are web-accessible directly.
- Keep generated/compiled assets inside the module folder if the module needs to be portable.

## Installing Third-Party Modules

Checklist before installing:

- Confirm the module says it supports webtrees `2.2`.
- Prefer a release archive if the module has compiled CSS/JS assets.
- If only source is available, verify whether build assets are committed.
- Verify the final folder under `modules_v4` contains `module.php` at its root.
- Verify the folder name follows webtrees rules and any README-required name.
- If a module ships `composer.json`, check compatibility such as `fisharebest/webtrees: ^2.2`.
- Avoid modifying root `vendor/` or installing Composer dependencies into this installation unless explicitly planned.
- Activate modules in the web UI: Admin -> Control panel -> Modules.

PowerShell install shape:

```powershell
Invoke-WebRequest -Uri "https://github.com/{owner}/{repo}/archive/refs/tags/{version}.zip" -OutFile "$env:TEMP\module.zip" -UseBasicParsing
Expand-Archive -Path "$env:TEMP\module.zip" -DestinationPath "$env:TEMP\module-extract" -Force
Move-Item -Path "$env:TEMP\module-extract\{extracted-folder}" -Destination "D:\workspace\docker\www\webtrees\modules_v4\{module-folder}"
```

Then verify:

```powershell
Test-Path "D:\workspace\docker\www\webtrees\modules_v4\{module-folder}\module.php"
```

## Local Development Workflow

- Use PowerShell commands in this workspace.
- Prefer `rg` for searches.
- There is no local `composer.json`, no local `package.json`, and no configured local test command.
- For PHP changes, at minimum run `php -l path\to\file.php` if PHP is available.
- For custom modules, validate by checking the web UI and by watching for module fatal-error flash messages.
- Do not edit `data/config.ini.php` or commit secrets/private data.
- `data/cache/` can be regenerated.
- `data/media/` contains user media and should be treated as private runtime data.
- Create `data/offline.txt` before risky upgrades or file replacement operations.

## Git Safety

- Do not run `git commit` unless the user explicitly asks for a commit.
- Do not run `git push` unless the user explicitly asks for a push.
- It is OK to run read-only git commands such as `git status`, `git diff`, `git log` or `git show`.
- If a commit is requested, inspect `git status` first and avoid staging unrelated user changes.
- Never rewrite history, amend commits, reset hard or discard changes unless the user explicitly asks and the target is clear.

## Security And Privacy For Plugins

Custom modules run inside the webtrees process and can access genealogy data. Treat them as trusted code:

- Never install random module code without reading it first.
- Validate all request parameters with webtrees validators where possible.
- Check `Auth::isAdmin()` or per-tree permissions for admin or private-data actions.
- Escape all rendered data.
- Do not expose files from `data/`.
- Do not add unauthenticated export/download endpoints unless explicitly required.
- Be careful with GEDCOM exports, living-person privacy and media paths.
- Keep module update URLs and support URLs plain and stable if using `ModuleCustomTrait` metadata.

## Upstream Editing Policy

- Avoid modifying `app/`, `vendor/`, `resources/` or `public/` for site customizations.
- If upstream core behavior must be changed, prefer a custom module that extends/replaces views or registers routes.
- When copying a core view into a theme/module, keep the upstream path in the filename or comment so future upgrades can compare it.
- After webtrees upgrades, review custom views because upstream view variables and markup can change.
