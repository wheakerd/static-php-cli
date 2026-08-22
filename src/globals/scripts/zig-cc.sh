#!/usr/bin/env bash

SCRIPT_DIR="$(dirname "${BASH_SOURCE[0]}")"
BUILDROOT_ABS="${BUILD_ROOT_PATH:-$(realpath "$SCRIPT_DIR/../../../buildroot/include" 2>/dev/null || true)}"
PARSED_ARGS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        -isystem)
            shift
            ARG="$1"
            shift
            ARG_ABS="$(realpath "$ARG" 2>/dev/null || true)"
            [[ "$ARG_ABS" == "$BUILDROOT_ABS" ]] && PARSED_ARGS+=("-I$ARG") || PARSED_ARGS+=("-isystem" "$ARG")
            ;;
        -isystem*)
            ARG="${1#-isystem}"
            shift
            ARG_ABS="$(realpath "$ARG" 2>/dev/null || true)"
            [[ "$ARG_ABS" == "$BUILDROOT_ABS" ]] && PARSED_ARGS+=("-I$ARG") || PARSED_ARGS+=("-isystem$ARG")
            ;;
        -march=*|-mcpu=*)
            OPT_NAME="${1%%=*}"
            OPT_VALUE="${1#*=}"
            # zig rejects -march=armv8-a but accepts -mcpu=generic+v8a; rewrite
            # armv<X>[.<Y>]-a[+feat] -> generic+v<X>[_<Y>]a[+feat] so it goes through.
            if [[ "$OPT_VALUE" =~ ^armv([89])(\.([0-9]+))?-a(\+.*)?$ ]]; then
                arch_feat="v${BASH_REMATCH[1]}"
                [[ -n "${BASH_REMATCH[3]}" ]] && arch_feat="${arch_feat}_${BASH_REMATCH[3]}"
                OPT_VALUE="generic+${arch_feat}a${BASH_REMATCH[4]}"
            fi
            # zig uses snake_case in CPU/feature names (x86-64 -> x86_64).
            OPT_VALUE="${OPT_VALUE//-/_}"
            PARSED_ARGS+=("${OPT_NAME}=${OPT_VALUE}")
            shift
            ;;
        *)
            PARSED_ARGS+=("$1")
            shift
            ;;
    esac
done

[[ -n "$SPC_TARGET" ]] && TARGET="-target $SPC_TARGET" || TARGET=""

if [[ "$SPC_TARGET" =~ \.[0-9]+\.[0-9]+ ]]; then
    output=$(zig cc $TARGET $SPC_COMPILER_EXTRA "${PARSED_ARGS[@]}" 2>&1)
    status=$?

    if [[ $status -eq 0 ]]; then
        echo "$output"
        exit 0
    fi

    if echo "$output" | grep -qE "version '.*' in target triple"; then
        filtered_output=$(echo "$output" | grep -vE "version '.*' in target triple")
        echo "$filtered_output"
        exit 0
    fi
fi

exec zig cc $TARGET $SPC_COMPILER_EXTRA "${PARSED_ARGS[@]}"
