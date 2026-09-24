import { defineConfig, devices } from '@playwright/test';

// Local (XAMPP): http://localhost:8080/e-commerce-pro/ (this machine's Apache listens on 8080).
// CI: overridden to the PHP built-in server started by .github/workflows/ci.yml.
const baseURL = process.env.BASE_URL || 'http://localhost:8080/e-commerce-pro/';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI ? [['html', { open: 'never' }], ['github']] : 'list',
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      // Los specs *.authenticated.spec.ts registran su propia cuenta cliente
      // desechable (ver tests/e2e/helpers.ts::registerAndLogin) en vez de
      // compartir una sesión vía storageState, para poder correr en paralelo
      // sin serializarse por el lock de archivo de sesión de PHP.
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
