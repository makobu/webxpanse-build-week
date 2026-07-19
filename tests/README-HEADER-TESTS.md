# Header Visual Tests

This directory contains Playwright tests for the CRM header to help with aesthetic improvements.

## Setup

1. **Install Node.js and npm** (if not already installed)
   - Download from: https://nodejs.org/

2. **Install Playwright**
   ```bash
   npm install
   ```

3. **Install Playwright browsers**
   ```bash
   npm run install-browsers
   ```

## Running Tests

### Run all header tests
```bash
npm run test:header
```

### Run with UI mode (interactive)
```bash
npm run test:ui
```

### Run in headed mode (see browser)
```bash
npm run test:headed
```

### Run in debug mode
```bash
npm run test:debug
```

## Test Coverage

The tests cover:

1. **Header Spacing**
   - Verifies header height is compact (< 80px)
   - Checks proper spacing between elements

2. **Search Field Separation**
   - Verifies search field has proper borders
   - Checks padding and margins

3. **Dropdown Styling**
   - Tests all dropdown menus (Sales, Communication, Analytics, More, Admin)
   - Verifies consistent font sizes (13px)
   - Checks padding and spacing

4. **Hover Effects**
   - Tests dropdown item hover states
   - Verifies color changes and transitions

5. **Mobile Responsiveness**
   - Tests mobile menu toggle
   - Verifies mobile menu functionality

6. **Color Consistency**
   - Checks brand colors
   - Verifies nav link colors
   - Tests dropdown toggle colors

## Screenshots

Screenshots are saved to `tests/screenshots/`:
- `header-full.png` - Full header view
- `search-field.png` - Search field area
- `dropdown-*.png` - Individual dropdown menus
- `dropdown-item-hover.png` - Hover state
- `mobile-menu.png` - Mobile menu view
- `header-layout.png` - Layout measurements

## Making Aesthetic Improvements

1. Run tests to see current state:
   ```bash
   npm run test:header
   ```

2. Review screenshots in `tests/screenshots/`

3. Make CSS/styling changes

4. Re-run tests to see improvements:
   ```bash
   npm run test:header
   ```

5. Iterate until satisfied with aesthetics

## Test User

The tests use a test user:
- Email: `test@crm.local`
- Password: `test123456`

This user is automatically created if it doesn't exist.
