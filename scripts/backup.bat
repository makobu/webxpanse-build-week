@echo off
REM CRM Backup Script (Windows)
REM Backs up database (mysqldump) and uploads directory.
REM Usage: scripts\backup.bat
REM Task Scheduler: Run daily at 2 AM
REM
REM Set BACKUP_DIR, DB_* in environment or create .env in CRM root.

setlocal
cd /d "%~dp0\.."

set CRM_DIR=%CD%
set BACKUP_DIR=%BACKUP_DIR%
if "%BACKUP_DIR%"=="" set BACKUP_DIR=%CRM_DIR%\backups
set DB_HOST=%DB_HOST%
if "%DB_HOST%"=="" set DB_HOST=localhost
set DB_NAME=%DB_NAME%
if "%DB_NAME%"=="" set DB_NAME=crm_db
set DB_USER=%DB_USER%
if "%DB_USER%"=="" set DB_USER=root
set DB_PASS=%DB_PASS%
set RETENTION_DAYS=%RETENTION_DAYS%
if "%RETENTION_DAYS%"=="" set RETENTION_DAYS=7

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

for /f "tokens=2 delims==" %%a in ('wmic os get localdatetime /value ^| find "="') do set DT=%%a
set DATE=%DT:~0,4%%DT:~4,2%%DT:~6,2%_%DT:~8,2%%DT:~10,2%%DT:~12,2%

echo [%date% %time%] Starting backup...

REM Database backup (requires mysqldump in PATH, e.g. from MySQL or XAMPP)
set DB_FILE=%BACKUP_DIR%\db_%DATE%.sql
where mysqldump >nul 2>&1
if %errorlevel% equ 0 (
    if "%DB_PASS%"=="" (
        mysqldump -h %DB_HOST% -u %DB_USER% %DB_NAME% > "%DB_FILE%" 2>nul
    ) else (
        mysqldump -h %DB_HOST% -u %DB_USER% -p%DB_PASS% %DB_NAME% > "%DB_FILE%" 2>nul
    )
    if exist "%DB_FILE%" (
        where gzip >nul 2>&1
        if %errorlevel% equ 0 (gzip -f "%DB_FILE%") else (echo   DB: %DB_FILE%)
    )
) else (
    echo   mysqldump not found, skipping DB backup
)

REM Uploads backup (requires tar - Windows 10+ has built-in tar)
if exist "%CRM_DIR%\uploads" (
    set FILES_FILE=%BACKUP_DIR%\uploads_%DATE%.tar.gz
    tar -czf "%FILES_FILE%" -C "%CRM_DIR%" uploads 2>nul
    if exist "%FILES_FILE%" echo   Uploads: %FILES_FILE%
)

echo [%date% %time%] Backup complete.
endlocal
