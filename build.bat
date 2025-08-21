@echo off
setlocal EnableExtensions EnableDelayedExpansion

REM ===== Config =====
set "PLUGIN_DIR=sii-boleta-dte"
set "PLUGIN_FILE=%PLUGIN_DIR%\sii-boleta-dte.php"
set "DIST=dist"

REM ===== Checks =====
if not exist "%PLUGIN_FILE%" (
  echo [ERROR] No se encuentra "%PLUGIN_FILE%".
  exit /b 1
)

REM ===== Leer Version: X.Y.Z del header =====
set "VERSION="
for /f "usebackq tokens=1,* delims=:" %%A in (`findstr /B /I "Version:" "%PLUGIN_FILE%"`) do (
  set "VERSION=%%B"
  goto :ver_found
)
:ver_found

if "%VERSION%"=="" (
  echo [ERROR] No se pudo leer la version desde "%PLUGIN_FILE%".
  exit /b 1
)

REM Quitar espacios sobrantes
for /f "tokens=1 delims= " %%V in ("%VERSION%") do set "VERSION=%%~V"
set "VERSION=%VERSION: =%"

if "%VERSION%"=="" (
  echo [ERROR] La version detectada esta vacia.
  exit /b 1
)

set "ZIP=%DIST%\sii-boleta-dte-%VERSION%.zip"

REM ===== Preparar dist =====
if exist "%DIST%" rmdir /s /q "%DIST%" >nul 2>&1
mkdir "%DIST%" >nul 2>&1

echo [INFO] Version detectada: %VERSION%
echo [INFO] Generando "%ZIP%"...

REM ===== Intentar con tar.exe (Windows 10/11) =====
where tar >nul 2>&1
if %ERRORLEVEL%==0 (
  tar -a -c -f "%ZIP%" "%PLUGIN_DIR%"
  goto :done_zip
)

REM ===== Intentar con 7-Zip si tar no existe =====
where 7z >nul 2>&1
if %ERRORLEVEL%==0 (
  7z a -tzip "%ZIP%" ".\%PLUGIN_DIR%\*" -mx=9 -xr0!*.DS_Store >nul
  goto :done_zip
)

echo [ERROR] No se encontro ^"tar.exe^" ni ^"7z.exe^" en el PATH.
echo         Opciones:
echo         - En Windows 10/11 habilita tar.exe (deberia venir por defecto).
echo         - Instala 7-Zip y agrega 7z.exe al PATH.
exit /b 1

:done_zip
if exist "%ZIP%" (
  echo [OK] Paquete creado: %ZIP%
  exit /b 0
) else (
  echo [ERROR] Fallo al crear el ZIP.
  exit /b 1
)
