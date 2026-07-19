@echo off
REM Quick script to run Playwright header tests
echo ========================================
echo CRM Header Visual Tests
echo ========================================
echo.

REM Check if node_modules exists
if not exist "node_modules" (
    echo Installing dependencies...
    call npm install
    echo.
)

REM Check if browsers are installed
if not exist "node_modules\.cache\playwright" (
    echo Installing Playwright browsers...
    call npm run install-browsers
    echo.
)

echo Running header visual tests...
echo.
call npm run test:header

echo.
echo ========================================
echo Tests complete!
echo Check tests/screenshots/ for visual results
echo ========================================
pause
