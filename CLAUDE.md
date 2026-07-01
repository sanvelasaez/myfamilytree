# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project purpose

Personal family tree installation. All custom development happens exclusively in `modules_v4/`. The core `app/` is upstream webtrees — do not modify it.

**Version: 2.2.6** (confirmed in `app/Webtrees.php::VERSION`). Any third-party module must declare compatibility with this version. Browse available community modules at https://webtrees.net/download/modules.

## Building and developing

PHP dependencies (required before running):
```bash
php composer.phar install
```

JavaScript dependencies and build:
```bash
npm install
npm run production   # Re-run after any change to webtrees.js
```

There are no test commands configured in this local installation. Upstream CI uses PHPUnit — see the GitHub Actions workflow at `fisharebest/webtrees`.

## Architecture

**Entry point**: `index.php` calls `Webtrees::new()->run(PHP_SAPI)`.

**Namespace**: `Fisharebest\Webtrees` (PSR-4 autoloaded from `app/`).

**Request lifecycle** (middleware stack in `app/Http/Middleware/`):
1. `ReadConfigIni` → connects DB credentials from `data/config.ini.php`
2. `UpdateDatabaseSchema` → runs migrations on startup
3. `UseSession`, `UseLanguage`, `UseTheme` → bootstrap context
4. `LoadRoutes` → registers `app/Http/Routes/WebRoutes.php` and `ApiRoutes.php`
5. `Router` dispatches to a PSR-15 request handler

**Request handlers** (`app/Http/RequestHandlers/`): one class per action. Naming convention:
- `*Page` — GET, renders view
- `*Action` — POST, processes form, redirects
- Auth middleware applied per-route: `AuthAdministrator`, `AuthEditor`, `AuthLoggedIn`, etc.

**Modules** (`app/Module/`): all built-in features are modules implementing interfaces from `app/Contracts/`. Third-party and custom modules go in `modules_v4/` — each is a folder with a `module.php`. Module names must be ≤ 30 chars, no spaces, no `.`, `[`, or `]`. Rename to `<name.disable>` to hide without deleting.

**Custom module pattern**: extend `AbstractModule`, implement one or more `Module*Interface`, and implement `ModuleCustomInterface` + use `ModuleCustomTrait` (provides author/version metadata and auto-update check). Key interfaces:
- `ModuleBlockInterface` — homepage/dashboard blocks
- `ModuleChartInterface` — genealogy chart pages
- `ModuleTabInterface` — tabs on individual pages
- `ModuleSidebarInterface` — sidebars on individual pages
- `ModuleMenuInterface` — top navigation menu items
- `ModuleGlobalInterface` — inject HTML into every page `<head>`/`<body>`
- `ModuleThemeInterface` — full visual theme

`boot()` fires for all enabled modules — use it to register routes, assets, or service bindings. `resourcesFolder()` must return `__DIR__ . '/resources/'` when using `ModuleCustomTrait`.

**Services** (`app/Services/`): business logic injected via the DI container (`app/Container.php`). Key services: `GedcomImportService`, `GedcomExportService`, `UserService`, `TreeService`.

**Views**: Blade-style templates in `resources/views/`. Static assets compiled into `public/`.

**Data directory** (runtime, mostly gitignored):
- `data/config.ini.php` — DB connection (gitignored)
- `data/cache/` — auto-regenerated
- `data/media/` — user uploads
- Create `data/offline.txt` to show maintenance page to visitors

## Windows / tooling

This repo runs on Windows. `ctx_batch_execute` and Bash tool use POSIX shell — they fail on `D:\...` paths. Use **PowerShell** for all file exploration commands.

`vendor/` is committed to git. `composer.json` and `package.json` are **not** in this repo (upstream source only) — do not expect them to exist locally.

## Coding standards

PHP: PSR-1, PSR-2, PSR-12. JavaScript: semistandard. Strict types declared in all PHP files (`declare(strict_types=1)`).
