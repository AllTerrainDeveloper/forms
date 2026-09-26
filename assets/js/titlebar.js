(function() {
  "use strict";
  function shell() {
    return window.wp?.os ?? null;
  }
  const BUTTON_ID = "allterrain-forms/preview";
  const PREVIEW_WINDOW_ID = "allterrain-forms-preview";
  const SOURCES_KEY = "allTerrainFormsPreviewSources";
  function sources() {
    const host = window;
    return host[SOURCES_KEY] ?? (host[SOURCES_KEY] = []);
  }
  function registerPreviewButton() {
    const os = shell();
    if (!os?.registerTitleBarButton) {
      return () => {
      };
    }
    const register = () => {
      try {
        os.registerTitleBarButton({
          id: BUTTON_ID,
          label: "Preview this form",
          icon: "dashicons-visibility",
          placement: "right",
          // Just before the shell's own Related button, so the builder's
          // eye lands where every other window's eye is.
          order: 90,
          // Only the builder window. The predicate is called against a live
          // `Window`, and a throw counts as "does not match" — so a shell
          // whose `Window` shape differs simply does not show the button
          // rather than erroring on every repaint.
          match: (window2) => {
            const id = window2?.id ?? window2?.config?.id ?? "";
            return id === "allterrain-forms" || id.startsWith("allterrain-forms#");
          },
          onClick: () => {
            const list = sources();
            const source = list[list.length - 1];
            if (source) {
              void openPreview(source);
            }
          },
          owner: "allterrain-forms-titlebar"
        });
      } catch {
      }
    };
    if (os.ready) {
      os.ready(register);
    } else {
      register();
    }
    return () => {
      try {
        os.unregisterTitleBarButton?.(BUTTON_ID);
      } catch {
      }
    };
  }
  async function openPreview(source) {
    if (source.isDirty()) {
      await source.save();
    }
    const form = source.current();
    if (!form) {
      return;
    }
    openPreviewWindow(form.id, form.title, form.previewUrl);
  }
  function openPreviewWindow(formId, title, url) {
    const os = shell();
    if (!os?.windowManager?.open) {
      window.open(url, "_blank", "noopener");
      return;
    }
    os.windowManager.open({
      id: `${PREVIEW_WINDOW_ID}-${formId}`,
      baseId: PREVIEW_WINDOW_ID,
      url,
      title: `Preview: ${title}`,
      icon: "dashicons-visibility"
    });
  }
  registerPreviewButton();
})();
