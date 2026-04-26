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
