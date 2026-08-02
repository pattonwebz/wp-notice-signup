import type { Page } from "@playwright/test";

/**
 * Named interactions a scan target can reference via its `state` field.
 * axe-core only ever sees whatever DOM state is on screen when it runs, so
 * anything that only appears after a click (a modal, a validation error)
 * needs one of these to put the page into that state first.
 */
export const interactions: Record<string, (page: Page) => Promise<void>> = {
  async openBannerModal(page) {
    await page.locator("[data-wpns-open-modal]").click();
    await page.locator(".wpns-modal-shell.is-open").waitFor({ state: "visible" });
  }
};
