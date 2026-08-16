var allTerrainFormsDock = function(exports) {
  "use strict";
  const config = window.allTerrainForms;
  const BUILDER = "allterrain-forms";
  const ENTRIES = "allterrain-forms-entries";
  const THEMES = "allterrain-forms-themes";
  const ANALYTICS = "allterrain-forms-analytics";
  const IMPORT = "allterrain-forms-import";
  function open(id) {
    shell()?.openWindow?.(id, { source: "dock" });
  }
  function openUrl(id, url, title) {
    const manager = shell()?.windowManager;
    if (manager?.open) {
      void manager.open({ id, url, title, icon: "dashicons-download" });
      return;
    }
    window.location.assign(url);
  }
  function shell() {
    return window.wp?.os ?? null;
  }
  function submenuFor(config2) {
    const submenu = [];
    if (config2?.canEdit) {
      submenu.push({
        title: "Forms",
        url: "",
        onSelect: () => open(BUILDER),
        windowId: BUILDER
      });
    }
    if (config2?.canRead) {
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
    if (config2?.canRead) {
      submenu.push({
        title: "Analytics",
        url: "",
        onSelect: () => open(ANALYTICS),
        windowId: ANALYTICS
      });
    }
    if (config2?.canEdit) {
      submenu.push({
        title: "Themes",
        url: "",
        onSelect: () => open(THEMES),
        windowId: THEMES
      });
    }
    if (config2?.canEdit && config2?.adminUrl) {
      const url = `${config2.adminUrl}admin.php?page=allterrain-forms-import`;
      submenu.push({
        title: "Import forms",
        // Kept in step with what `onSelect` opens: the shell reads the
        // callback, but the URL is what the row means.
        url,
        onSelect: () => openUrl(IMPORT, url, "Import forms"),
        // Declaring it lets the constellation list this row under "Open
        // windows" once it is, rather than offering to open a second copy.
        windowId: IMPORT
      });
    }
    if (config2?.canEdit && config2?.devMode) {
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
    return submenu;
  }
  function registerTile() {
    const os = shell();
    if (!os?.registerSystemTile) {
      return;
    }
    const submenu = submenuFor(config);
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
  exports.submenuFor = submenuFor;
  Object.defineProperty(exports, Symbol.toStringTag, { value: "Module" });
  return exports;
}({});
