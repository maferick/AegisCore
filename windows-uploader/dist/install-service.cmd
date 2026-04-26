@echo off
REM AegisCore EVE Log Uploader — install as a Windows Service.
REM Run as administrator.

setlocal

set "SERVICE_NAME=AegisCoreEveLogUploader"
set "SERVICE_DISPLAY=AegisCore EVE Log Uploader"
set "EXE_PATH=%~dp0AegisCore.EveLogUploader.exe"

if not exist "%EXE_PATH%" (
    echo [ERROR] AegisCore.EveLogUploader.exe not found next to this script.
    echo Place the .exe in the same folder as install-service.cmd and try again.
    pause
    exit /b 1
)

sc.exe query "%SERVICE_NAME%" >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo [INFO] Service "%SERVICE_NAME%" already exists; updating binPath.
    sc.exe config "%SERVICE_NAME%" binPath= "\"%EXE_PATH%\"" start= auto
) else (
    sc.exe create "%SERVICE_NAME%" binPath= "\"%EXE_PATH%\"" start= auto DisplayName= "%SERVICE_DISPLAY%"
    if errorlevel 1 (
        echo [ERROR] sc create failed. Make sure you're running as administrator.
        pause
        exit /b 1
    )
)

sc.exe description "%SERVICE_NAME%" "Ships EVE Online client logs to the AegisCore intelligence server."

sc.exe start "%SERVICE_NAME%"

echo.
echo Service "%SERVICE_NAME%" installed and started.
echo Open the AegisCore portal -> Intelligence -> EVE Log Uploaders to verify.
pause
