# webtrees-modules-pack

Pack de módulos y temas personalizados para [webtrees](https://webtrees.net) `2.2.x`.
La raíz de este repositorio se corresponde con la carpeta `modules_v4/` de una instalación webtrees.

## Contenido

- `argon-sanvelasaez/` — tema basado en Argon.
- `better-webtrees-forms/` — mejoras en formularios.
- `improved-tree/` — árbol interactivo multidioma.
- `kripton/` — tema Kripton.
- `AGENTS.md`, `CLAUDE.md`, `.agents/` — guías para agentes de IA que trabajen sobre el pack.
- `WEBTREES-MODULES-NOTES.md` — notas upstream de webtrees sobre la carpeta `modules_v4`.

## Instalación

Clonar el repositorio como carpeta `modules_v4` de una instalación webtrees existente:

```bash
cd /ruta/a/webtrees
mv modules_v4 modules_v4.bak   # si ya existe y quieres conservarla
git clone https://github.com/sanvelasaez/webtrees-modules-pack.git modules_v4
```

O, si `modules_v4` ya contiene otros módulos que quieres mantener:

```bash
cd /ruta/a/webtrees/modules_v4
git init
git remote add origin https://github.com/sanvelasaez/webtrees-modules-pack.git
git fetch origin
git checkout -f main
```

Después, activar los módulos en **Panel de control → Módulos**.
