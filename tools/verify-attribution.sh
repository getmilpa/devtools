#!/usr/bin/env bash
# Verifica las cuatro invariantes de atribución en cada paquete getmilpa-*.
#
# Por qué existe: Apache-2.0 §4(d) obliga a arrastrar el NOTICE si existe, pero no obliga a crearlo.
# Sin NOTICE, la atribución viaja solo en vectores que un fork puede perder sin violar la licencia —
# los headers se reescriben, el composer.json se sustituye, el LICENSE se reemplaza por el genérico.
# El NOTICE es el único que la licencia obliga a conservar.
#
# Una pasada manual previa dejó 12 paquetes atribuidos y 13 sin tocar, y además dejó huecos DENTRO de
# paquetes ya "hechos" (runtime 5/18, tool-runtime 1/39). Un verificador convierte eso en un gate.
#
# Uso:  bash verify-attribution.sh          → reporta y sale 1 si hay violaciones
#       bash verify-attribution.sh --quiet  → solo el resumen
set -uo pipefail

NOMBRE="${ATTRIBUTION_NAME:-Rodrigo Vicente - TeamX Agency}"
QUIET=0
[ "${1:-}" = "--quiet" ] && QUIET=1

# Se ejecuta desde el directorio que contiene los paquetes getmilpa-*.
# Por defecto asume que este archivo vive en <familia>/getmilpa-devtools/tools/.
cd "${MILPA_FAMILY_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

viol=0
paquetes=0

say() { [ "$QUIET" -eq 1 ] || printf '%s\n' "$*"; }

for d in getmilpa-*; do
    [ -d "$d" ] || continue
    [ -f "$d/composer.json" ] || continue
    paquetes=$((paquetes + 1))
    fallos=()

    # 1 · NOTICE presente y con el nombre canónico
    if [ ! -f "$d/NOTICE" ]; then
        fallos+=("sin NOTICE")
    elif ! grep -qF "$NOMBRE" "$d/NOTICE"; then
        fallos+=("NOTICE sin el nombre canónico")
    fi

    # 2 · composer.json declara authors con el nombre canónico
    autor=$(php -r '$j=json_decode(@file_get_contents($argv[1]),true)?:[];
        foreach(($j["authors"]??[]) as $a) { echo ($a["name"]??""),"\n"; }' "$d/composer.json" 2>/dev/null)
    if [ -z "$autor" ]; then
        fallos+=("composer.json sin authors")
    elif ! printf '%s' "$autor" | grep -qF "$NOMBRE"; then
        fallos+=("authors sin el nombre canónico")
    fi

    # 3 · todo archivo fuente lleva el header de atribución
    sin=0
    while IFS= read -r f; do
        grep -q "(c) $NOMBRE" "$f" 2>/dev/null || sin=$((sin + 1))
    done < <(find "$d/src" -name '*.php' 2>/dev/null)
    [ "$sin" -gt 0 ] && fallos+=("$sin archivo(s) sin header")

    # 4 · LICENSE presente con línea de copyright
    if [ ! -f "$d/LICENSE" ]; then
        fallos+=("sin LICENSE")
    elif ! grep -qi '^ *Copyright [0-9]\{4\}' "$d/LICENSE"; then
        fallos+=("LICENSE sin línea de copyright")
    fi

    if [ "${#fallos[@]}" -gt 0 ]; then
        viol=$((viol + 1))
        say "  ✗ ${d#getmilpa-}"
        for f in "${fallos[@]}"; do say "      · $f"; done
    fi
done

say ""
printf 'atribución: %d/%d paquetes conformes (nombre canónico: "%s")\n' \
    "$((paquetes - viol))" "$paquetes" "$NOMBRE"
[ "$viol" -eq 0 ] || exit 1
