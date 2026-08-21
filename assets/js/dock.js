var allTerrainFormsDock = function(exports) {
  "use strict";
  function getShell() {
    const wp = window.wp;
    return wp?.os ?? null;
  }
  const config$1 = window.allTerrainForms;
  class ApiError extends Error {
    constructor(message, status, code = "") {
      super(message);
      this.name = "ApiError";
      this.status = status;
      this.code = code;
    }
  }
  function joinPath(base, path) {
    return base.includes("?") ? `${base}${path.replace("?", "&")}` : `${base}${path}`;
  }
  async function request(path, init = {}) {
    if (!config$1?.restUrl) {
      throw new ApiError("AllTerrain Forms is not configured on this page.", 0);
    }
    const url = joinPath(config$1.restUrl, path);
    const headers = {
      "Content-Type": "application/json",
      ...init.headers ?? {}
    };
    if (config$1.nonce) {
      headers["X-WP-Nonce"] = config$1.nonce;
    }
    const shell2 = getShell();
    const doFetch = shell2?.fetch ? (input, options) => shell2.fetch(input, options, { source: "allterrain-forms" }) : (input, options) => fetch(input, options);
    const response = await doFetch(url, {
      credentials: "same-origin",
      ...init,
      headers
    });
    if (!response.ok) {
      let message = `Request failed with status ${response.status}.`;
      let code = "";
      try {
        const body = await response.json();
        message = body.message ?? message;
        code = body.code ?? "";
      } catch {
      }
      throw new ApiError(message, response.status, code);
    }
    if (response.status === 204) {
      return void 0;
    }
    return await response.json();
  }
  const get = (path) => request(path);
  async function wpGet(route) {
    if (!config$1?.wpRestUrl) {
      throw new ApiError("AllTerrain Forms is not configured on this page.", 0);
    }
    const headers = config$1.nonce ? { "X-WP-Nonce": config$1.nonce } : {};
    const shell2 = getShell();
    const url = joinPath(config$1.wpRestUrl, route);
    const response = shell2?.fetch ? await shell2.fetch(url, { credentials: "same-origin", headers }, { source: "allterrain-forms" }) : await fetch(url, { credentials: "same-origin", headers });
    if (!response.ok) {
      throw new ApiError(`Request failed with status ${response.status}.`, response.status);
    }
    return await response.json();
  }
  function decodeEntities(html) {
    if (!html || !html.includes("&")) {
      return html;
    }
    const textarea = document.createElement("textarea");
    textarea.innerHTML = html;
    return textarea.value;
  }
  const post = (path, body) => request(path, { method: "POST", body: JSON.stringify(body) });
  const del = (path) => request(path, { method: "DELETE" });
  function query(params) {
    const search = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
      if (value === void 0 || value === "" || value === false) {
        continue;
      }
      search.set(key, String(value));
    }
    const string = search.toString();
    return string ? `?${string}` : "";
  }
  function withObjectOverrides(form) {
    const settings = form?.schema?.settings;
    if (settings && (!settings.themeOverrides || Array.isArray(settings.themeOverrides))) {
      settings.themeOverrides = { ...settings.themeOverrides };
    }
    return form;
  }
  const api = {
    config: () => get("/config"),
    listForms: () => get("/forms"),
    /** The other side of the archive: the retired forms, same shape. */
    listArchivedForms: () => get("/forms?archived=1"),
    /** Retires a form — it leaves every picker, its entries leave every list, its stats go with it. */
    archiveForm: (id) => post(`/forms/${id}/archive`, {}),
    /** Brings an archived form back, entries and stats included, in its pre-archive status. */
    unarchiveForm: (id) => post(`/forms/${id}/unarchive`, {}),
    getForm: (id) => get(`/forms/${id}`).then(withObjectOverrides),
    createForm: (body) => post("/forms", body).then(withObjectOverrides),
    updateForm: (id, body) => post(`/forms/${id}`, body).then(withObjectOverrides),
    duplicateForm: (id) => post(`/forms/${id}/duplicate`, {}).then(withObjectOverrides),
    deleteForm: (id) => del(`/forms/${id}`),
    preview: (id, body) => post(`/forms/${id}/preview`, body),
    /**
     * The site's published pages, for the "send them to a page" confirmation.
     *
     * Core's own route rather than one of ours: `wp/v2/pages` already knows about
     * capabilities, pagination and the page hierarchy, and a plugin re-exposing
     * the same list is a second thing to keep in step with it. `_fields` keeps the
     * payload to the two values the picker shows — a full page response carries
     * rendered content, and a hundred of those is megabytes.
     */
    pages: () => wpGet(
      "wp/v2/pages?per_page=100&status=publish&orderby=title&order=asc&_fields=id,title"
    ).then(
      (pages) => pages.map((page) => ({
        id: page.id,
        // The REST API returns titles HTML-encoded; `el()` sets text through
        // `textContent`, so without decoding, a page called "Q&A" shows as
        // "Q&amp;A" in the picker.
        title: decodeEntities(page.title?.rendered ?? "") || `#${page.id}`
      }))
    ),
    mergeTags: (id) => get(`/forms/${id}/merge-tags`).then((response) => response.groups),
    analytics: (id, dimension = "") => get(
      `/forms/${id}/analytics${dimension ? `?dimension=${encodeURIComponent(dimension)}` : ""}`
    ),
    /**
     * The demo-data tools.
     *
     * Every one of these 404s unless developer mode is on, which is why the
     * window asks for the status before drawing the panel rather than drawing the
     * panel and letting the buttons fail.
     */
    demoStatus: () => get("/demo"),
    /** Generates one chunk. Called until `remaining` reaches zero. */
    demoSeed: (count) => post("/demo", count ? { count } : {}),
    demoRemove: () => del("/demo"),
    listEntries: (params) => get(`/entries${query(params)}`),
    getEntry: (id) => get(`/entries/${id}`),
    updateEntry: (id, body) => post(`/entries/${id}`, body),
    deleteEntry: (id) => del(`/entries/${id}`),
    exportEntries: (params) => get(`/entries/export${query(params)}`),
    listThemes: () => get("/themes"),
    saveTheme: (body) => post("/themes", body),
    deleteTheme: (id) => del(`/themes/${id}`)
  };
  function requestedFormKeyFor(surface) {
    return `allterrain-forms/requested-form-${surface}`;
  }
  function rememberFormFor(surface, formId) {
    try {
      window.sessionStorage.setItem(requestedFormKeyFor(surface), String(formId));
    } catch {
    }
  }
  function openWindowOnForm(windowId, surface, event, formId) {
    rememberFormFor(surface, formId);
    window.wp?.os?.openWindow?.(
      windowId,
      { source: "wp-explorer" }
    );
    document.dispatchEvent(new CustomEvent(event, { detail: { formId } }));
  }
  function el(tag, options = {}) {
    const node = document.createElement(tag);
    if (options.class) {
      node.className = options.class;
    }
    if (options.text !== void 0) {
      node.textContent = options.text;
    }
    if (options.html !== void 0) {
      node.innerHTML = options.html;
    }
    if (options.title) {
      node.title = options.title;
    }
    if (options.type && "type" in node) {
      node.type = options.type;
    }
    if (options.value !== void 0 && "value" in node) {
      node.value = options.value;
    }
    if (options.placeholder !== void 0 && "placeholder" in node) {
      node.placeholder = options.placeholder;
    }
    if (options.href && "href" in node) {
      node.href = options.href;
    }
    for (const [name, value] of Object.entries(options.attrs ?? {})) {
      if (value === void 0 || value === false) {
        continue;
      }
      node.setAttribute(name, value === true ? "" : String(value));
    }
    Object.assign(node.style, options.style ?? {});
    for (const [event, handler] of Object.entries(options.on ?? {})) {
      node.addEventListener(event, handler);
    }
    for (const child of options.children ?? []) {
      if (child === null || child === void 0 || child === false) {
        continue;
      }
      node.append(child);
    }
    return node;
  }
  function clear(node) {
    node.replaceChildren();
  }
  function icon(name) {
    return el("span", { class: `dashicons ${name} atfx-icon`, attrs: { "aria-hidden": "true" } });
  }
  function card(label, value) {
    return el("div", {
      class: "atfx-card",
      children: [
        el("strong", { class: "atfx-card__value", text: value }),
        el("span", { class: "atfx-card__label", text: label })
      ]
    });
  }
  function tile(args) {
    return el("button", {
      class: `atfx-tile${args.selected ? " is-selected" : ""}`,
      type: "button",
      children: [
        icon(args.icon),
        el("strong", { class: "atfx-tile__label", text: args.label }),
        args.vitals ? el("span", { class: "atfx-tile__vitals", text: args.vitals }) : null
      ],
      on: {
        click: () => (args.onSelect ?? args.onOpen)(),
        dblclick: args.onOpen
      }
    });
  }
  function statCards(form) {
    const conversion = form.views > 0 ? `${Math.floor(form.submissions / form.views * 100)}%` : "—";
    return el("div", {
      class: "atfx-cards",
      children: [
        card("Entries", String(form.entries)),
        card("Unread", String(form.unread)),
        card("Views", String(form.views)),
        card("Conversion", conversion)
      ]
    });
  }
  function livePreview(formId, title, full = false) {
    const frame = el("div", {
      class: `atfx-preview${full ? " atfx-preview--full" : ""}`,
      children: [el("p", { class: "atfx-empty", text: "Loading the preview…" })]
    });
    void api.getForm(formId).then((detail) => {
      if (!detail.previewUrl) {
        throw new Error("no preview");
      }
      clear(frame);
      frame.append(
        el("iframe", {
          class: "atfx-preview__frame",
          attrs: { src: detail.previewUrl, title: `Preview of ${title}`, loading: "lazy", tabindex: "-1" }
        })
      );
    }).catch(() => {
      clear(frame);
      frame.append(el("p", { class: "atfx-empty", text: "No preview available." }));
    });
    return frame;
  }
  function renderFormsKind(host) {
    if ("detail" === host.route.kind) {
      renderFolder(host, Number(host.route.postId ?? 0), String(host.route.postTitle ?? ""));
      return;
    }
    renderList(host);
  }
  function renderList(host) {
    let forms = [];
    let selected = 0;
    let searchTerm = "";
    const grid = el("div", { class: "atfx-grid" });
    const pane = el("div", { class: "atfx-pane" });
    const search = el("input", {
      class: "atfb-input atfx-search",
      type: "search",
      placeholder: "Search forms…",
      attrs: { "aria-label": "Search forms" }
    });
    clear(host.body);
    host.body.append(
      el("div", { class: "atfx", children: [el("div", { class: "atfx-side", children: [search, grid] }), pane] })
    );
    const openFolder = (form) => host.navigate({
      kind: "detail",
      entityId: "atf-forms",
      postId: form.id,
      postTitle: form.title || `#${form.id}`
    });
    const paint = () => {
      clear(grid);
      for (const form of forms) {
        if (searchTerm && !(form.title || "").toLowerCase().includes(searchTerm)) {
          continue;
        }
        grid.append(
          tile({
            icon: "dashicons-feedback",
            label: form.title || `#${form.id}`,
            vitals: `${form.fields} ${1 === form.fields ? "question" : "questions"} · ${form.entries} ${1 === form.entries ? "entry" : "entries"}`,
            selected: form.id === selected,
            onSelect: () => {
              selected = form.id;
              paint();
              paintPane();
            },
            onOpen: () => openFolder(form)
          })
        );
      }
      if (!grid.childElementCount) {
        grid.append(el("p", { class: "atfx-empty", text: searchTerm ? "No form matches that." : "No forms yet." }));
      }
    };
    const paintPane = () => {
      const form = forms.find((candidate) => candidate.id === selected);
      clear(pane);
      if (!form) {
        pane.append(el("p", { class: "atfx-empty", text: "Pick a form to see it. Double-click to open it." }));
        return;
      }
      pane.append(
        el("h2", { class: "atfx-title", text: form.title || `#${form.id}` }),
        statCards(form),
        host.previewActionRow({ item: { ...form } }) ?? el("span"),
        livePreview(form.id, form.title),
        el("p", { class: "atfx-shortcode", children: [el("code", { text: form.shortcode })] })
      );
    };
    search.addEventListener("input", () => {
      searchTerm = search.value.trim().toLowerCase();
      paint();
    });
    void api.listForms().then((loaded) => {
      forms = loaded;
      selected = forms[0]?.id ?? 0;
      paint();
      paintPane();
    }).catch(() => {
      clear(grid);
      grid.append(el("p", { class: "atfx-empty", text: "Could not load the forms." }));
    });
  }
  function renderFolder(host, formId, title) {
    const body = el("div", { class: "atfx-full" });
    clear(host.body);
    host.body.append(el("div", { class: "atfx", children: [body] }));
    body.append(el("p", { class: "atfx-empty", text: "Loading…" }));
    const paintFolder = (form) => {
      clear(body);
      body.append(
        statCards(form),
        el("div", {
          class: "atfx-grid atfx-grid--folder",
          children: [
            // The form itself first: the folder is named after it, and
            // opening it means building on it.
            tile({
              icon: "dashicons-feedback",
              label: form.title || `#${form.id}`,
              vitals: `${form.fields} ${1 === form.fields ? "question" : "questions"} — open in the builder`,
              onOpen: () => openWindowOnForm("allterrain-forms", "builder", "atf-open-form", form.id)
            }),
            // Entries and Report are doors, not depths: the windows are
            // where those jobs are done, so the tiles take you there.
            tile({
              icon: "dashicons-list-view",
              label: "Entries",
              vitals: `${form.entries} stored`,
              onOpen: () => openWindowOnForm("allterrain-forms-entries", "entries", "atf-open-entries-form", form.id)
            }),
            tile({
              icon: "dashicons-chart-bar",
              label: "Report",
              vitals: "conversion, NPS, answers",
              onOpen: () => openWindowOnForm("allterrain-forms-analytics", "analytics", "atf-open-analytics-form", form.id)
            })
          ]
        }),
        // The rest of the folder is the form itself, live and full size.
        livePreview(form.id, form.title, true)
      );
    };
    void api.listForms().then((forms) => {
      const form = forms.find((candidate) => candidate.id === formId);
      if (!form) {
        clear(body);
        body.append(el("p", { class: "atfx-empty", text: `“${title}” is not a form any more.` }));
        return;
      }
      paintFolder(form);
    }).catch(() => {
      clear(body);
      body.append(el("p", { class: "atfx-empty", text: "Could not load the form." }));
    });
  }
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
        title: "Entries",
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
        onSelect: () => openUrl(IMPORT, url, "AllTerrain Forms — Import"),
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
    registerFormsKind();
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
  function registerExplorerActions() {
    const hooks = window.wp?.hooks;
    if (!hooks?.addFilter) {
      return;
    }
    const targets = {
      "allterrain-forms/open-builder": { windowId: BUILDER, surface: "builder", event: "atf-open-form" },
      "allterrain-forms/open-entries": { windowId: ENTRIES, surface: "entries", event: "atf-open-entries-form" },
      "allterrain-forms/open-analytics": { windowId: ANALYTICS, surface: "analytics", event: "atf-open-analytics-form" }
    };
    hooks.addFilter(
      "os.my-wordpress.preview-actions",
      "allterrain-forms/explorer",
      (actions) => actions.map((action) => {
        const id = action.id ?? "";
        const target = targets[id];
        if (!target) {
          return action;
        }
        return {
          ...action,
          // The context carries the selected entity, so the window opens
          // ON the form the click was about rather than wherever it was.
          onSelect: (ctx) => {
            const formId = Number(ctx?.itemId ?? ctx?.item?.id ?? 0);
            if (formId) {
              openWindowOnForm(target.windowId, target.surface, target.event, formId);
            } else {
              open(target.windowId);
            }
          }
        };
      })
    );
  }
  function registerFormsKind() {
    const mw = window.wp?.os?.myWordpress;
    mw?.registerEntityKind?.("atf-form", renderFormsKind);
  }
  registerExplorerActions();
  exports.submenuFor = submenuFor;
  Object.defineProperty(exports, Symbol.toStringTag, { value: "Module" });
  return exports;
}({});
