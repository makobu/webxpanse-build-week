# Quick Start Guide - Header Visual Tests

## Prerequisites

1. **Node.js and npm** must be installed
   - Download: https://nodejs.org/
   - Verify: `node --version` and `npm --version`

2. **XAMPP/Apache** must be running
   - CRM should be accessible at: `http://localhost/crm`

## Quick Setup (Windows)

### Option 1: Use Batch Script (Easiest)
```bash
run-header-tests.bat
```

### Option 2: Manual Setup

1. **Install dependencies:**
   ```bash
   npm install
   ```

2. **Install Playwright browsers:**
   ```bash
   npm run install-browsers
   ```

3. **Run tests:**
   ```bash
   npm run test:header
   ```

## Running Tests

### Basic Test Run
```bash
npm run test:header
```

### Interactive UI Mode (Recommended for visual testing)
```bash
npm run test:ui
```

### Headed Mode (See browser)
```bash
npm run test:headed
```

### Debug Mode
```bash
npm run test:debug
```

## What the Tests Do

1. **Login** to CRM with test user
2. **Take screenshots** of header in various states
3. **Measure spacing** and layout
4. **Test dropdowns** - all menus (Sales, Communication, Analytics, More, Admin)
5. **Verify styling** - font sizes, colors, spacing
6. **Test hover effects** - dropdown item interactions
7. **Test mobile menu** - responsive behavior

## Screenshots Location

All screenshots are saved to: `tests/screenshots/`

- `header-full.png` - Complete header view
- `search-field.png` - Search area
- `dropdown-sales.png` - Sales dropdown
- `dropdown-communication.png` - Communication dropdown
- `dropdown-analytics.png` - Analytics dropdown
- `dropdown-more.png` - More dropdown
- `dropdown-admin.png` - Admin dropdown
- `dropdown-item-hover.png` - Hover state
- `mobile-menu.png` - Mobile view
- `header-layout.png` - Layout measurements

## Making Aesthetic Improvements

1. **Run tests** to see current state:
   ```bash
   npm run test:ui
   ```

2. **Review screenshots** in `tests/screenshots/`

3. **Make CSS changes** in:
   - `public/assets/css/main.css`
   - `public/assets/css/components.css`

4. **Refresh and re-run tests**:
   ```bash
   npm run test:header
   ```

5. **Compare before/after** screenshots

6. **Iterate** until satisfied

## Troubleshooting

### PowerShell Execution Policy Error
If you get a PowerShell execution policy error:
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

### Port Already in Use
If port conflicts occur, change the base URL in `playwright.config.js`

### Login Fails
- Ensure test user exists (created automatically)
- Check database connection
- Verify XAMPP is running

### Tests Timeout
- Increase timeout in `playwright.config.js`
- Check that CRM is accessible at `http://localhost/crm`

## Test User Credentials

- **Email:** `test@crm.local`
- **Password:** `test123456`
- **Role:** Admin

This user is automatically created if it doesn't exist.
