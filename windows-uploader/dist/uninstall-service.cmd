@echo off
REM AegisCore EVE Log Uploader — uninstall the Windows Service.
REM Run as administrator.

setlocal

set "SERVICE_NAME=AegisCoreEveLogUploader"

sc.exe query "%SERVICE_NAME%" >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [INFO] Service "%SERVICE_NAME%" not installed.
    pause
    exit /b 0
)

sc.exe stop "%SERVICE_NAME%"
sc.exe delete "%SERVICE_NAME%"

echo.
echo Service "%SERVICE_NAME%" stopped and removed.
echo Now delete the program folder + %%APPDATA%%\AegisCore\EveLogUploader\ if you want it fully gone.
echo And revoke the token in the AegisCore portal so it cannot be reused.
pause
