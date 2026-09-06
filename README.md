# webtrees-modules-pack

Pack de módulos y temas personalizados para [webtrees](https://webtrees.net) `2.2.x`.
La raíz de este repositorio se corresponde con la carpeta `modules_v4/` de una instalación webtrees.

## Contenido

- `argon-sanvelasaez/` — tema basado en Argon.
- `better-webtrees-forms/` — mejoras en formularios.
- `improved-tree/` — árbol interactivo multidioma.
- `kripton/` — tema Kripton.
- `AGENTS.md`, `CLAUDE.md`, `.agents/` — guías para agentes de IA que trabajen sobre el pack (no se incluyen en el zip de distribución).

## Instalación

Todos los comandos se ejecutan desde la carpeta raíz de la instalación webtrees (la que contiene `index.php`).
Hay tres formas de obtener el pack; elige una.

### Opción A — Zip de la release (sin git, recomendada)

Cada release publica `webtrees-modules-pack.zip`, generado con `git archive`: **no lleva carpeta raíz**, así que se extrae directamente dentro de `modules_v4/` y los módulos quedan en `modules_v4/<módulo>/`. Los módulos que ya tuvieras en `modules_v4/` se conservan.

Descarga manual: bajar el zip de la última release y extraerlo dentro de `modules_v4/`.

<https://github.com/sanvelasaez/webtrees-modules-pack/releases/latest/download/webtrees-modules-pack.zip>

Linux / macOS / WSL / Git Bash:

```bash
curl -fsSL -o pack.zip https://github.com/sanvelasaez/webtrees-modules-pack/releases/latest/download/webtrees-modules-pack.zip && unzip -o pack.zip -d modules_v4 && rm pack.zip
```

Windows (PowerShell 5.1 o 7+):

```powershell
curl.exe -fsSL -o pack.zip https://github.com/sanvelasaez/webtrees-modules-pack/releases/latest/download/webtrees-modules-pack.zip; Expand-Archive -Force pack.zip modules_v4; del pack.zip
```

Windows (CMD):

```bat
curl.exe -fsSL -o pack.zip https://github.com/sanvelasaez/webtrees-modules-pack/releases/latest/download/webtrees-modules-pack.zip & tar -xf pack.zip -C modules_v4 & del pack.zip
```

Para una versión concreta, sustituir `latest/download` por `download/<tag>`, por ejemplo `download/v1.0.0`.

### Opción B — `git clone` (un solo comando, igual en Linux, macOS y Windows)

Al indicar `modules_v4` como destino, git clona directamente en esa carpeta y no crea `webtrees-modules-pack/`:

```bash
git clone https://github.com/sanvelasaez/webtrees-modules-pack.git modules_v4
```

Requiere que `modules_v4` no exista o esté vacía. Si ya contiene otros módulos que quieres mantener:

```bash
cd modules_v4
git init
git remote add origin https://github.com/sanvelasaez/webtrees-modules-pack.git
git fetch origin
git checkout -f -B main origin/main
```

Actualizar más adelante: `git -C modules_v4 pull`.

### Opción C — Comprimido de la rama `main` (sin git, sin release)

GitHub genera el comprimido de cualquier rama en `archive/refs/heads/<rama>.tar.gz`, pero dentro va una carpeta raíz `webtrees-modules-pack-<rama>/`. `tar --strip-components=1` la elimina al extraer.

Linux / macOS / WSL / Git Bash:

```bash
mkdir -p modules_v4 && curl -fsSL https://github.com/sanvelasaez/webtrees-modules-pack/archive/refs/heads/main.tar.gz | tar -xz --strip-components=1 -C modules_v4
```

Windows (PowerShell). No se puede encadenar `curl | tar` porque PowerShell trata la tubería como texto y corrompe el binario; se descarga primero a fichero:

```powershell
mkdir -Force modules_v4 | Out-Null; curl.exe -fsSL -o pack.tar.gz https://github.com/sanvelasaez/webtrees-modules-pack/archive/refs/heads/main.tar.gz; tar -xzf pack.tar.gz --strip-components=1 -C modules_v4; del pack.tar.gz
```

### Activar los módulos

Después de cualquiera de las opciones, activar los módulos en **Panel de control → Módulos**.

## Notas sobre la carpeta `modules_v4` (upstream webtrees)

- Un módulo es una carpeta que contiene un fichero `module.php` en su raíz; puede llevar CSS, JS, plantillas, idiomas, datos, etc.
- Instalar un módulo = copiar su carpeta a `modules_v4/`. Desinstalarlo = borrar la carpeta.
- El nombre de la carpeta no puede contener espacios ni los caracteres `.`, `[`, `]`, y tiene un máximo de 30 caracteres.
- Truco: renombrar `<módulo>` a `<módulo>.disable` lo oculta de webtrees sin borrarlo, porque las carpetas con `.` se ignoran.
- Los módulos integrados están en `app/Module/` del core y sirven de ejemplo; hay más módulos de ejemplo en <https://github.com/webtrees>.

## Publicar una release

El workflow `.github/workflows/release.yml` se ejecuta al subir un tag `v*`: genera `webtrees-modules-pack.zip` con `git archive` (respetando los `export-ignore` de `.gitattributes`) y crea la release con el zip adjunto.

```bash
git tag v1.0.0
git push origin v1.0.0
```
