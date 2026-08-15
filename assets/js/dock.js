(function() {
  "use strict";
  const config = window.allTerrainForms;
  const BUILDER = "allterrain-forms";
  const ENTRIES = "allterrain-forms-entries";
  const THEMES = "allterrain-forms-themes";
  const ANALYTICS = "allterrain-forms-analytics";
  function open(id) {
    shell()?.openWindow?.(id, { source: "dock" });
  }
  function shell() {
    return window.wp?.os ?? null;
  }
  function registerTile() {
    const os = shell();
    if (!os?.registerSystemTile) {
      return;
    }
    const submenu = [];
    if (config?.canEdit) {
      submenu.push({
        title: "Forms",
        url: "",
        onSelect: () => open(BUILDER),
        windowId: BUILDER
      });
    }
    if (config?.canRead) {
      submenu.push({
        title: "Form entries",
        url: "",
        onSelect: () => open(ENTRIES),
        // Declaring the window lets the flyout list this row under
        // "Open windows" when it already is, instead of offering to open a
        // second copy.
        windowId: ENTRIES
      });
    }
    if (config?.canRead) {
      submenu.push({
        title: "Analytics",
        url: "",
        onSelect: () => open(ANALYTICS),
        windowId: ANALYTICS
      });
    }
    if (config?.canEdit) {
      submenu.push({
        title: "Themes",
        url: "",
        onSelect: () => open(THEMES),
        windowId: THEMES
      });
    }
    if (config?.canEdit && config?.devMode) {
      submenu.push({
        title: "Demo data",
        url: "",
        onSelect: () => {
          open(ANALYTICS);
          document.dispatchEvent(new CustomEvent("atf-open-demo-panel"));
        },
        windowId: ANALYTICS
      });
    }
    try {
      os.registerSystemTile({
        id: "allterrain-forms",
        title: "AllTerrain Forms",
        icon: "dashicons-feedback",
        // Ahead of the shell's own trailing cluster, which starts at 10.
        order: 5,
        // The flyout is a hover gesture and never fans out for keyboard or
        // touch, so the tile's own activation has to go somewhere useful:
        // the builder, which is what the tile is named after.
        onOpen: () => open(config?.canEdit ? BUILDER : ENTRIES),
        isOpen: () => Boolean(
          os.windowManager?.getById?.(BUILDER) || os.windowManager?.getById?.(ENTRIES) || os.windowManager?.getById?.(THEMES)
        ),
        submenu
      });
    } catch {
    }
  }
  function boot() {
    const os = shell();
    if (os?.ready) {
      os.ready(registerTile);
      return true;
    }
    if (os?.whenReady) {
      os.whenReady(registerTile);
      return true;
    }
    if (os?.registerSystemTile) {
      registerTile();
      return true;
    }
    return false;
  }
  if (!boot()) {
    document.addEventListener("os-init", () => void boot(), { once: true });
  }
})();
