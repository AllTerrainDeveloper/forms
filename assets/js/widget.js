var allTerrainFormsWidget = function(exports) {
  "use strict";
  function getShell() {
    const wp = window.wp;
    return wp?.os ?? null;
  }
  const config = window.allTerrainForms;
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
    if (!config?.restUrl) {
      throw new ApiError("AllTerrain Forms is not configured on this page.", 0);
    }
    const url = joinPath(config.restUrl, path);
    const headers = {
      "Content-Type": "application/json",
      ...init.headers ?? {}
    };
    if (config.nonce) {
      headers["X-WP-Nonce"] = config.nonce;
    }
    const shell = getShell();
    const doFetch = shell?.fetch ? (input, options) => shell.fetch(input, options, { source: "allterrain-forms" }) : (input, options) => fetch(input, options);
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
    if (!config?.wpRestUrl) {
      throw new ApiError("AllTerrain Forms is not configured on this page.", 0);
    }
    const headers = config.nonce ? { "X-WP-Nonce": config.nonce } : {};
    const shell = getShell();
    const url = joinPath(config.wpRestUrl, route);
    const response = shell?.fetch ? await shell.fetch(url, { credentials: "same-origin", headers }, { source: "allterrain-forms" }) : await fetch(url, { credentials: "same-origin", headers });
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
    /** MailPoet's presence, lists and logo — what the MailPoet window boots from. */
    mailpoet: () => get("/mailpoet"),
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
  const REFRESH_MS = 12e4;
  async function render(host) {
    try {
      const forms = await api.listForms();
      if (!forms.length) {
        clear(host);
        host.append(el("p", { class: "atfw__empty", text: "No forms yet." }));
        return;
      }
      const { entries } = await api.listEntries({ per_page: 8 });
      clear(host);
      host.append(summary(forms), list(entries));
    } catch (error) {
      clear(host);
      host.append(
        el("p", {
          class: "atfw__empty",
          text: error instanceof Error ? error.message : "Could not load submissions."
        })
      );
    }
  }
  function summary(forms) {
    const submissions = forms.reduce((total, form) => total + form.submissions, 0);
    const views = forms.reduce((total, form) => total + form.views, 0);
    const unread = forms.reduce((total, form) => total + form.unread, 0);
    const conversion = views > 0 ? Math.floor(submissions / views * 100) : 0;
    return el("div", {
      class: "atfw__summary",
      children: [
        stat(String(submissions), "submissions"),
        stat(`${conversion}%`, "converted"),
        stat(String(unread), "unread")
      ]
    });
  }
  function stat(value, label) {
    return el("div", {
      class: "atfw__stat",
      children: [
        el("strong", { class: "atfw__stat-value", text: value }),
        el("span", { class: "atfw__stat-label", text: label })
      ]
    });
  }
  function list(entries) {
    if (!entries.length) {
      return el("p", { class: "atfw__empty", text: "Nothing submitted yet." });
    }
    return el("ul", {
      class: "atfw__list",
      children: entries.map(
        (entry) => el("li", {
          class: `atfw__item${entry.status === "alltfo-unread" ? " is-unread" : ""}`,
          children: [
            el("span", { class: "atfw__title", text: entry.title }),
            el("span", { class: "atfw__meta", text: `${entry.formTitle} · ${entry.dateHuman}` })
          ]
        })
      )
    });
  }
  function renderWidget(host) {
    host.classList.add("atfw");
    void render(host);
    const timer = window.setInterval(() => void render(host), REFRESH_MS);
    const shell = window.wp?.os;
    const unsubscribe = shell?.subscribe?.("os.alltfo_entry.changed", () => void render(host));
    return () => {
      window.clearInterval(timer);
      unsubscribe?.();
    };
  }
  function mountStandalone() {
    document.querySelectorAll("[data-atfw-root]").forEach((host) => {
      if (host.dataset.atfwMounted) {
        return;
      }
      host.dataset.atfwMounted = "1";
      renderWidget(host);
    });
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mountStandalone);
  } else {
    mountStandalone();
  }
  exports.render = renderWidget;
  exports.renderWidget = renderWidget;
  Object.defineProperty(exports, Symbol.toStringTag, { value: "Module" });
  return exports;
}({});
