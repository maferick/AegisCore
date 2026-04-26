#!/usr/bin/env bash
# Cross-compile the AegisCore EVE Log Uploader for win-x64 from any
# Linux host using the .NET 8 SDK container. Publishes a single
# self-contained .exe so the operator does not need to install the
# .NET runtime separately.
#
# Output: windows-uploader/publish/win-x64/AegisCore.EveLogUploader.exe
set -euo pipefail

cd "$(dirname "$0")"

PUBLISH_DIR="${PWD}/publish/win-x64"
mkdir -p "$PUBLISH_DIR"

echo "[build] cleaning previous publish output…"
rm -rf "$PUBLISH_DIR"/*

echo "[build] running dotnet publish (win-x64, self-contained, single-file)…"
docker run --rm \
    -v "${PWD}":/src \
    -w /src/AegisCore.EveLogUploader \
    -e DOTNET_NOLOGO=1 \
    -e DOTNET_CLI_TELEMETRY_OPTOUT=1 \
    mcr.microsoft.com/dotnet/sdk:8.0 \
    dotnet publish \
        -c Release \
        -r win-x64 \
        --self-contained true \
        -p:PublishSingleFile=true \
        -p:IncludeNativeLibrariesForSelfExtract=true \
        -p:DebugType=None \
        -p:DebugSymbols=false \
        -o /src/publish/win-x64

echo "[build] artefacts:"
ls -la "$PUBLISH_DIR" | head -20

EXE="$PUBLISH_DIR/AegisCore.EveLogUploader.exe"
if [[ -f "$EXE" ]]; then
    SIZE=$(du -h "$EXE" | cut -f1)
    echo "[build] OK — $EXE ($SIZE)"
else
    echo "[build] ERROR — exe not produced" >&2
    exit 1
fi

# Bundle the portable distribution zip alongside. Drops the .exe,
# install/uninstall scripts, the example config, and the member
# README into a single archive normal alliance members can unzip
# anywhere.
ZIP_NAME="AegisCore.EveLogUploader-portable-$(date -u +%Y%m%d).zip"
ZIP_OUT="${PWD}/publish/${ZIP_NAME}"
STAGE="${PWD}/publish/_stage"

echo "[zip] staging portable bundle…"
rm -rf "$STAGE"
mkdir -p "$STAGE"
cp "$EXE" "$STAGE/"
cp "${PWD}/dist/install-service.cmd" "$STAGE/"
cp "${PWD}/dist/uninstall-service.cmd" "$STAGE/"
cp "${PWD}/dist/config.example.json" "$STAGE/"
cp "${PWD}/dist/README-MEMBER.md" "$STAGE/README.md"

if ! command -v zip >/dev/null 2>&1; then
    echo "[zip] zip not installed locally; building inside container."
    docker run --rm \
        -v "${PWD}/publish":/work \
        -w /work \
        alpine:3.20 \
        sh -c "apk add --quiet zip >/dev/null && cd _stage && zip -qr ../${ZIP_NAME} ."
else
    (cd "$STAGE" && zip -qr "$ZIP_OUT" .)
fi

rm -rf "$STAGE"

if [[ -f "$ZIP_OUT" ]]; then
    ZSIZE=$(du -h "$ZIP_OUT" | cut -f1)
    echo "[zip] OK — $ZIP_OUT ($ZSIZE)"
else
    echo "[zip] ERROR — zip not produced" >&2
    exit 1
fi
