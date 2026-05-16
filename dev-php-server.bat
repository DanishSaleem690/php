@echo off
setlocal
cd /d "%~dp0"

REM New terminals in Cursor/VS Code inherit PATH from when the app was started.
REM If `php` was added to Windows PATH later, fully quit and reopen the editor — or rely on the fallbacks below.
where php >nul 2>nul
if errorlevel 1 if exist "C:\php-8.5.6-Win32-vs17-x64\php.exe" set "PATH=C:\php-8.5.6-Win32-vs17-x64;%PATH%"
where php >nul 2>nul
if errorlevel 1 for /f "delims=" %%P in ('dir /b /ad "C:\laragon\bin\php\php-*" 2^>nul') do set "PATH=C:\laragon\bin\php\%%P;%PATH%" & goto php_ready
:php_ready
where php >nul 2>nul
if errorlevel 1 (
  echo [php:serve] ERROR: php.exe not on PATH and no fallback found.
  echo [php:serve] Quit Cursor completely and reopen, or add your PHP folder to User PATH.
  exit /b 1
)

echo [php:serve] document root: %CD%
echo [php:serve] http://127.0.0.1:8080/contact_dev_ok.php
echo [php:serve] http://127.0.0.1:8080/contact.php
php -S 127.0.0.1:8080 -t .
exit /b %ERRORLEVEL%
