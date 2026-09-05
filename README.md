# webtrees-modules-pack

Pack de módulos y temas personalizados para [webtrees](https://webtrees.net) `2.2.x`.

## Contenido

- `modules_v4/argon-sanvelasaez` — tema basado en Argon.
- `modules_v4/better-webtrees-forms` — mejoras en formularios.
- `modules_v4/improved-tree` — árbol interactivo multidioma.
- `modules_v4/kripton` — tema Kripton.
- `AGENTS.md`, `CLAUDE.md`, `.agents/` — guías para agentes de IA que trabajen sobre el pack.

## Instalación

Clonar el repositorio directamente en la raíz de una instalación webtrees existente:

```bash
cd /ruta/a/webtrees
git init            # si la instalación aún no es un repositorio
git remote add origin https://github.com/sanvelasaez/webtrees-modules-pack.git
git fetch origin
git checkout -f main
```

El `.gitignore` está en modo lista blanca: solo `modules_v4/` y los documentos raíz quedan bajo control de versiones; el core de webtrees permanece en disco sin ser rastreado.

Después, activar los módulos en **Panel de control → Módulos**.
