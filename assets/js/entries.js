var allTerrainFormsEntries = function(exports) {
  "use strict";
  function hosts() {
    const found = [window];
    try {
      if (window.parent && window.parent !== window) {
        found.push(window.parent);
      }
    } catch {
    }
    return found;
  }
  function ownWindow(host) {
    const all = host.wp?.os?.windowManager?.getAll?.() ?? [];
    for (const win of all) {
      const frame = win.iframe ?? win.element?.querySelector?.("iframe");
      if (frame && frame.contentWindow === window) {
        return win;
      }
    }
    return null;
  }
  const ATTACH_TIMEOUT_MS$1 = 4e3;
  const ATTACH_POLL_MS$1 = 100;
  function closeOwnWindow(host, openedId) {
    const deadline = Date.now() + ATTACH_TIMEOUT_MS$1;
    const attempt = () => {
      const own = ownWindow(host);
      if (own && own.id !== openedId) {
        if (typeof own.close === "function") {
          own.close();
        } else {
          host.wp?.os?.windowManager?.remove?.(own.id);
        }
        return;
      }
      if (!own && Date.now() < deadline) {
        window.setTimeout(attempt, ATTACH_POLL_MS$1);
      }
    };
    attempt();
  }
  function handOffToWindow() {
    const pointer = document.querySelector("[data-atf-handoff]");
    if (!pointer) {
      return;
    }
    const id = pointer.getAttribute("data-atf-handoff") ?? "";
    if (!id) {
      return;
    }
    const form = Number(new URLSearchParams(window.location.search).get("form")) || 0;
    const params = form > 0 ? { form } : void 0;
    if (form > 0) {
      rememberRequestedForm(form);
    }
    for (const host of hosts()) {
      if (!host.wp?.os?.openWindow?.(id, { source: "handoff", params })) {
        continue;
      }
      window.setTimeout(() => closeOwnWindow(host, id), 0);
      return;
    }
  }
  function watchHandoffButton() {
    document.addEventListener("click", (event) => {
      const button2 = event.target?.closest?.("[data-atf-open-window]");
      if (!button2) {
        return;
      }
      event.preventDefault();
      const id = button2.getAttribute("data-atf-open-window") ?? "";
      for (const host of hosts()) {
        if (host.wp?.os?.openWindow?.(id, { source: "handoff" })) {
          return;
        }
      }
    });
  }
  const REQUESTED_FORM_KEY = "allterrain-forms/requested-form";
  function rememberRequestedForm(formId) {
    try {
      window.sessionStorage.setItem(REQUESTED_FORM_KEY, String(formId));
    } catch {
    }
  }
  function requestedFormKeyFor(surface) {
    return `allterrain-forms/requested-form-${surface}`;
  }
  function takeFormFor(surface) {
    try {
      const value = window.sessionStorage.getItem(requestedFormKeyFor(surface));
      window.sessionStorage.removeItem(requestedFormKeyFor(surface));
      return Number(value) || 0;
    } catch {
      return 0;
    }
  }
  function takeRequestedForm() {
    try {
      const value = window.sessionStorage.getItem(REQUESTED_FORM_KEY);
      window.sessionStorage.removeItem(REQUESTED_FORM_KEY);
      return Number(value) || 0;
    } catch {
      return 0;
    }
  }
  const DRAG_THRESHOLD_PX = 4;
  const CLICK_GUARD_MS = 500;
  class FallbackDragManager {
    constructor() {
      this.targets = [];
      this.active = null;
      this.lastEndMs = 0;
    }
    start(opts) {
      if (this.active || opts.origin.button !== 0) {
        return null;
      }
      const { payload, origin } = opts;
      const startX = origin.clientX;
      const startY = origin.clientY;
      let lifted = false;
      let finished = false;
      let ghost = null;
      let hovered = null;
      let offsetX = 0;
      let offsetY = 0;
      const cleanup = () => {
        document.removeEventListener("pointermove", onMove);
        document.removeEventListener("pointerup", onUp);
        document.removeEventListener("pointercancel", onCancel);
        document.removeEventListener("keydown", onKey);
        window.removeEventListener("blur", onCancel);
        ghost?.remove();
        ghost = null;
        payload.source.classList.remove("atf-is-dragging");
        document.body.classList.remove("atf-drag-active");
        hovered?.onLeave?.(session);
        hovered = null;
        this.active = null;
        this.lastEndMs = Date.now();
      };
      const session = {
        payload,
        isFinished: () => finished,
        cancel: (reason = "caller") => {
          if (finished) {
            return;
          }
          finished = true;
          cleanup();
          opts.onCancel?.(reason);
        }
      };
      const lift = (event) => {
        lifted = true;
        payload.source.classList.add("atf-is-dragging");
        document.body.classList.add("atf-drag-active");
        const rect = payload.source.getBoundingClientRect();
        offsetX = payload.ghost?.offsetX ?? startX - rect.left;
        offsetY = payload.ghost?.offsetY ?? startY - rect.top;
        ghost = payload.ghost?.element ?? payload.source.cloneNode(true);
        ghost.classList.add("atf-drag-ghost");
        ghost.style.width = `${rect.width}px`;
        document.body.appendChild(ghost);
        position(event);
      };
      const position = (event) => {
        if (ghost) {
          ghost.style.transform = `translate3d(${event.clientX - offsetX}px, ${event.clientY - offsetY}px, 0)`;
        }
      };
      const onMove = (event) => {
        if (finished) {
          return;
        }
        if (!lifted) {
          if (Math.hypot(event.clientX - startX, event.clientY - startY) < DRAG_THRESHOLD_PX) {
            return;
          }
          lift(event);
        }
        position(event);
        const next = this.hitTest(event.clientX, event.clientY);
        if (next !== hovered) {
          hovered?.onLeave?.(session);
          hovered = next;
          hovered?.onEnter?.(session);
        }
      };
      const onUp = (event) => {
        if (finished) {
          return;
        }
        if (!lifted) {
          finished = true;
          cleanup();
          opts.onClickOnly?.();
          return;
        }
        const target = hovered;
        finished = true;
        cleanup();
        if (target && target.accept(payload)) {
          opts.onCommit?.(target);
          void target.onDrop(session, { clientX: event.clientX, clientY: event.clientY });
          return;
        }
        opts.onCancel?.(target ? "rejected" : "no-target");
      };
      const onCancel = () => session.cancel("pointercancel");
      const onKey = (event) => {
        if (event.key === "Escape") {
          session.cancel("escape");
        }
      };
      document.addEventListener("pointermove", onMove);
      document.addEventListener("pointerup", onUp);
      document.addEventListener("pointercancel", onCancel);
      document.addEventListener("keydown", onKey);
      window.addEventListener("blur", onCancel);
      this.active = session;
      return session;
    }
    registerDropTarget(target) {
      this.targets = this.targets.filter((candidate) => candidate.id !== target.id);
      this.targets.push(target);
      return () => {
        this.targets = this.targets.filter((candidate) => candidate.id !== target.id);
      };
    }
    isDragging() {
      return this.active !== null;
    }
    recentlyEndedDrag(withinMs = CLICK_GUARD_MS) {
      return Date.now() - this.lastEndMs < withinMs;
    }
    /**
     * The registered target the cursor is most specifically over.
     *
     * Depth first, so a target nested inside another wins — that is what makes
     * dropping on a field mean something more specific than dropping on the
     * canvas that holds it.
     *
     * Ties go to whichever element comes *later* in document order, which for
     * overlapping siblings is the one painted on top and therefore the one the
     * user believes they are aiming at. Without the tie-break, two overlapping
     * siblings resolve by registration order instead, and a small target sitting
     * on top of a large one never receives a drop at all — including when its
     * job was to refuse one.
     */
    hitTest(x, y) {
      let best = null;
      let bestDepth = -1;
      for (const target of this.targets) {
        const rect = target.element.getBoundingClientRect();
        if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) {
          continue;
        }
        const depth = depthOf(target.element);
        if (depth > bestDepth) {
          best = target;
          bestDepth = depth;
          continue;
        }
        if (depth === bestDepth && best && follows(target.element, best.element)) {
          best = target;
        }
      }
      return best;
    }
  }
  function depthOf(element) {
    let depth = 0;
    let node = element;
    while (node) {
      depth++;
      node = node.parentElement;
    }
    return depth;
  }
  function follows(a, b) {
    return (b.compareDocumentPosition(a) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
  }
  function getShell() {
    const wp = window.wp;
    return wp?.os ?? null;
  }
  let fallback = null;
  function getDragManager() {
    const shell = getShell();
    if (shell?.dragManager) {
      return shell.dragManager;
    }
    if (!fallback) {
      fallback = new FallbackDragManager();
    }
    return fallback;
  }
  function watchShellDragVisuals(payloadTypes) {
    const sourceOf = (event) => {
      const payload = event.detail?.payload;
      return payload && payloadTypes.includes(payload.type) ? payload.source : null;
    };
    const onStart = (event) => {
      const source = sourceOf(event);
      if (source) {
        source.classList.add("atf-is-dragging");
        document.body.classList.add("atf-drag-active");
      }
    };
    const onEnd = (event) => {
      const source = sourceOf(event);
      if (source) {
        source.classList.remove("atf-is-dragging");
        document.body.classList.remove("atf-drag-active");
      }
    };
    document.addEventListener("os.drag.start", onStart);
    document.addEventListener("os.drag.end", onEnd);
    return () => {
      document.removeEventListener("os.drag.start", onStart);
      document.removeEventListener("os.drag.end", onEnd);
    };
  }
  function buildPayload(type, source, data, origin, ghost) {
    const rect = source.getBoundingClientRect();
    if (ghost) {
      ghost.style.width = `${Math.round(rect.width)}px`;
      ghost.style.maxWidth = `${Math.round(rect.width)}px`;
      ghost.style.boxSizing = "border-box";
    }
    return {
      type,
      source,
      data,
      ghost: {
        element: ghost,
        offsetX: origin.clientX - rect.left,
        offsetY: origin.clientY - rect.top,
        hint: {
          neutral: "",
          accept: "",
          // Only the reject case earns a chip. "Drop here" over a canvas
          // the field is visibly hovering says nothing the drop indicator
          // hasn't already said; "can't drop here" is information.
          reject: "",
          hidden: true
        }
      }
    };
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
  function icon(slug) {
    return el("span", {
      class: `dashicons ${slug.startsWith("dashicons-") ? slug : `dashicons-${slug}`}`,
      attrs: { "aria-hidden": "true" }
    });
  }
  function textInput(value, onChange, placeholder = "") {
    return el("input", {
      class: "atfb-input",
      type: "text",
      value,
      placeholder,
      on: {
        input: (event) => onChange(event.target.value)
      }
    });
  }
  function select(value, options, onChange) {
    if (hasComponent("os-select") && hasComponent("os-option")) {
      const host = document.createElement("os-select");
      host.setAttribute("value", value);
      host.classList.add("atfb-field");
      for (const option of options) {
        const item = document.createElement("os-option");
        item.setAttribute("value", option.value);
        item.textContent = option.label;
        host.append(item);
      }
      host.addEventListener("os-pick", (event) => {
        onChange(String(event.detail?.value ?? ""));
      });
      return host;
    }
    return el("select", {
      class: "atfb-input atfb-select",
      on: {
        change: (event) => onChange(event.target.value)
      },
      children: options.map(
        (option) => el("option", {
          value: option.value,
          text: option.label,
          attrs: { selected: option.value === value }
        })
      )
    });
  }
  function checkbox(label, checked, onChange) {
    if (hasComponent("os-checkbox-label")) {
      const host = document.createElement("os-checkbox-label");
      host.setAttribute("label", label);
      host.classList.add("atfb-check");
      if (checked) {
        host.setAttribute("checked", "");
      }
      host.addEventListener("os-checkbox-change", (event) => {
        onChange(Boolean(event.detail?.checked));
      });
      return host;
    }
    const input = el("input", {
      type: "checkbox",
      on: {
        change: (event) => onChange(event.target.checked)
      }
    });
    input.checked = checked;
    return el("label", {
      class: "atfb-check",
      children: [input, el("span", { text: label })]
    });
  }
  function button(label, onClick, variant = "secondary", iconSlug) {
    const children = [iconSlug ? icon(iconSlug) : null, el("span", { text: label })];
    if (hasComponent("os-button")) {
      const host = document.createElement("os-button");
      host.setAttribute("variant", variant);
      host.setAttribute("type", "button");
      host.classList.add("atfb-button", `atfb-button--${variant}`);
      host.addEventListener("click", onClick);
      for (const child of children) {
        if (child) {
          host.append(child);
        }
      }
      return host;
    }
    return el("button", {
      class: `atfb-button atfb-button--${variant}`,
      type: "button",
      on: { click: onClick },
      children
    });
  }
  function hasComponent(tag) {
    return typeof customElements !== "undefined" && Boolean(customElements.get(tag));
  }
  const COMPONENTS = [
    "os-button",
    "os-checkbox-label",
    "os-number-field",
    "os-select",
    "os-option",
    "os-segmented",
    "os-segment",
    "os-color-field",
    "os-empty-state"
  ];
  let componentsPending = null;
  function whenComponents() {
    if (componentsPending) {
      return componentsPending;
    }
    const shell = window.wp?.os;
    componentsPending = shell?.loadComponents ? shell.loadComponents(COMPONENTS).catch(() => void 0) : Promise.resolve();
    return componentsPending;
  }
  async function confirmAction(message, title = "") {
    const shell = window.wp?.os;
    if (shell?.confirm) {
      return shell.confirm({ title, message, danger: true });
    }
    return window.confirm(message);
  }
  function notify(title, body = "", type = "info") {
    const shell = window.wp?.os;
    if (shell?.notify) {
      shell.notify({ title, body, type });
      return;
    }
    console.info(`[AllTerrain Forms] ${title}${body ? `: ${body}` : ""}`);
  }
  function debounce(fn, wait) {
    let timer = 0;
    return (...args) => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => fn(...args), wait);
    };
  }
  function pinWindowBodyScroll(root) {
    const body = root.closest(".os-window__body");
    if (!body) {
      return;
    }
    body.addEventListener(
      "scroll",
      () => {
        if (body.scrollTop) {
          body.scrollTop = 0;
        }
        if (body.scrollLeft) {
          body.scrollLeft = 0;
        }
      },
      { passive: true }
    );
    body.scrollTop = 0;
    body.scrollLeft = 0;
  }
  const FORM_TYPE = "allterrain-forms/form";
  const ENTRY_TYPE = "allterrain-forms/entry";
  function relations() {
    const os = window.wp?.os;
    return os?.relations ?? null;
  }
  function windowIdOf(element) {
    const host = element.closest("[data-window-id], .os-window");
    if (!host) {
      return null;
    }
    const attribute = host.getAttribute("data-window-id");
    if (attribute) {
      return attribute;
    }
    const id = host.id ?? "";
    return id ? id.replace(/^wp-window-/, "") : null;
  }
  const ATTACH_TIMEOUT_MS = 6e3;
  const ATTACH_POLL_MS = 120;
  function setIdentity(element, ref) {
    const api2 = relations();
    wanted.set(element, ref);
    if (!api2?.set) {
      return;
    }
    const attempt = (deadline) => {
      if (pending.get(element) !== token) {
        return;
      }
      const windowId = windowIdOf(element);
      if (!windowId) {
        if (Date.now() < deadline) {
          window.setTimeout(() => attempt(deadline), ATTACH_POLL_MS);
        }
        return;
      }
      try {
        api2.set(windowId, ref);
      } catch (error) {
        if (!warned) {
          warned = true;
          console.error("[AllTerrain Forms] The shell refused a window identity.", error, ref);
        }
        pending.delete(element);
        return;
      }
      const stuck = !ref || api2.get?.(windowId)?.id === ref.id;
      if (stuck || Date.now() >= deadline) {
        pending.delete(element);
        return;
      }
      window.setTimeout(() => attempt(deadline), ATTACH_POLL_MS);
    };
    const token = Symbol("atf-identity");
    pending.set(element, token);
    attempt(Date.now() + ATTACH_TIMEOUT_MS);
  }
  let warned = false;
  const pending = /* @__PURE__ */ new WeakMap();
  const wanted = /* @__PURE__ */ new Map();
  function reapply() {
    for (const [element, ref] of wanted) {
      if (!element.isConnected) {
        wanted.delete(element);
        continue;
      }
      setIdentity(element, ref);
    }
  }
  if (typeof document !== "undefined") {
    for (const event of ["os-window-content-loaded", "os-window-opened"]) {
      document.addEventListener(event, () => reapply());
    }
  }
  function entryIdentity(entry, adminUrl) {
    const links = [];
    if (entry.userId) {
      links.push({ type: "user", id: entry.userId, rel: "references" });
    }
    for (const field of entry.fields) {
      if (field.type !== "file" || !Array.isArray(field.value)) {
        continue;
      }
      for (const id of field.value) {
        const attachment = Number(id);
        if (attachment > 0) {
          links.push({ type: "media", id: attachment, rel: "child" });
        }
      }
    }
    const related = [
      {
        id: `allterrain-forms/form-${entry.formId}`,
        label: entry.formTitle || "The form",
        url: `${adminUrl}admin.php?page=allterrain-forms&form=${entry.formId}`,
        group: "allterrain-forms",
        groupLabel: "Forms",
        icon: "dashicons-feedback"
      }
    ];
    if (entry.userId) {
      related.push({
        id: `allterrain-forms/user-${entry.userId}`,
        label: "Who submitted it",
        url: `${adminUrl}user-edit.php?user_id=${entry.userId}`,
        group: "users",
        groupLabel: "People",
        icon: "dashicons-admin-users"
      });
    }
    return {
      type: ENTRY_TYPE,
      id: entry.id,
      root: { type: FORM_TYPE, id: entry.formId },
      label: entry.title || `Entry #${entry.id}`,
      links: links.slice(0, 32),
      related
    };
  }
  const ENTRY_PAYLOAD_TYPE = "allterrain-forms/entry";
  class EntriesWindow {
    constructor(root) {
      this.forms = [];
      this.entries = [];
      this.selected = null;
      this.selection = /* @__PURE__ */ new Set();
      this.paintedSelectionId = -1;
      this.loadSeq = 0;
      this.selectSeq = 0;
      this.formId = 0;
      this.status = "inbox";
      this.search = "";
      this.starred = false;
      this.page = 1;
      this.pages = 1;
      this.total = 0;
      this.teardowns = [];
      this.root = root;
      this.bar = root.querySelector("[data-atfe-bar]") ?? el("div");
      this.list = root.querySelector("[data-atfe-list]") ?? el("div");
      this.detail = root.querySelector("[data-atfe-detail]") ?? el("div");
    }
    /** Loads the form list and the first page of entries. */
    async start() {
      this.teardowns.push(watchShellDragVisuals([ENTRY_PAYLOAD_TYPE]));
      try {
        this.forms = await api.listForms();
      } catch (error) {
        clear(this.bar);
        this.bar.append(
          el("p", { class: "atfb-error", text: error instanceof Error ? error.message : "Could not load forms." })
        );
        return;
      }
      const requested = takeFormFor("entries") || takeRequestedForm();
      this.formId = (requested && this.forms.some((form) => form.id === requested) ? requested : 0) || // Otherwise the form with unread entries, which is almost always what
      // somebody coming to this window wants, and saves a click every visit.
      (this.forms.find((form) => form.unread > 0) ?? this.forms[0])?.id || 0;
      this.renderBar();
      await this.load();
      const shell = window.wp?.os;
      if (shell?.subscribe) {
        this.teardowns.push(
          shell.subscribe("os.atf_entry.changed", () => {
            if (!this.root.isConnected) {
              this.destroy();
              return;
            }
            void this.load();
          })
        );
      }
    }
    /** Releases every listener. */
    destroy() {
      this.teardowns.forEach((teardown) => teardown());
      this.teardowns = [];
    }
    /** The filter bar. */
    renderBar() {
      clear(this.bar);
      const searchBox = textInput(
        this.search,
        debounce((value) => {
          this.search = value;
          this.page = 1;
          void this.load();
        }, 300),
        "Search answers"
      );
      searchBox.type = "search";
      searchBox.setAttribute("aria-label", "Search entries");
      this.bar.append(
        select(
          String(this.formId),
          this.forms.map((form) => ({
            value: String(form.id),
            label: form.unread ? `${form.title} (${form.unread})` : form.title
          })),
          (value) => {
            this.formId = Number(value);
            this.page = 1;
            this.selection.clear();
            this.selected = null;
            void this.load();
          }
        ),
        select(
          this.status,
          [
            { value: "inbox", label: "All" },
            { value: "atf-unread", label: "Unread" },
            { value: "atf-read", label: "Read" },
            { value: "atf-spam", label: "Spam" }
          ],
          (value) => {
            this.status = value;
            this.page = 1;
            void this.load();
          }
        ),
        searchBox,
        checkbox("Starred only", this.starred, (value) => {
          this.starred = value;
          this.page = 1;
          void this.load();
        }),
        el("span", { class: "atfe__count", attrs: { role: "status" }, text: "" }),
        button("Export CSV", () => void this.exportEntries("csv"), "secondary", "download"),
        button("JSON", () => void this.exportEntries("json"), "secondary")
      );
    }
    /** Fetches and paints the current page. */
    /**
     * Deep-link entry: show one form's entries.
     *
     * @param formId The form.
     */
    async showForm(formId) {
      this.formId = formId;
      this.page = 1;
      this.selection.clear();
      this.selected = null;
      this.renderBar();
      await this.load();
    }
    async load() {
      if (!this.formId) {
        clear(this.list);
        this.list.append(el("p", { class: "atfb-hint", text: "No forms yet." }));
        return;
      }
      const seq = ++this.loadSeq;
      try {
        const result = await api.listEntries({
          form_id: this.formId,
          status: this.status === "inbox" ? void 0 : this.status,
          search: this.search,
          page: this.page,
          starred: this.starred
        });
        if (seq !== this.loadSeq) {
          return;
        }
        if (this.page > 1 && this.page > result.pages) {
          this.page = Math.max(1, result.pages);
          return this.load();
        }
        this.entries = result.entries;
        this.total = result.total;
        this.pages = result.pages;
        this.renderList();
        this.reconcileSelection();
        const count = this.bar.querySelector(".atfe__count");
        if (count) {
          count.textContent = `${this.total} ${this.total === 1 ? "entry" : "entries"}`;
        }
      } catch (error) {
        if (seq !== this.loadSeq) {
          return;
        }
        clear(this.list);
        this.list.append(
          el("p", { class: "atfb-error", text: error instanceof Error ? error.message : "Could not load entries." })
        );
      }
    }
    /**
     * Drops a selection the current view no longer contains, and repaints the
     * detail pane when that changes what is on screen.
     *
     * Every filter — the form picker, the status tabs, search, starred, and the
     * bulk actions — narrows the list, and any of them can leave the detail pane
     * showing a submission that is no longer in it. Switching form was the
     * visible case: the list emptied while the previous form's entry stayed
     * open beside it, so the window claimed "No entries yet" and displayed one.
     *
     * Reconciling here rather than in each callback means a filter added later
     * cannot reintroduce the bug by forgetting to clear it.
     *
     * The pane is only repainted when the selection actually changes, so a
     * background refresh — the shell broadcasts one on every new submission —
     * does not collapse the "Where it came from" section under someone who is
     * reading it.
     */
    reconcileSelection() {
      if (this.selected && !this.entries.some((entry) => entry.id === this.selected?.id)) {
        this.selected = null;
      }
      if (this.paintedSelectionId !== (this.selected?.id ?? 0)) {
        this.renderDetail();
      }
    }
    /** Paints the list. */
    renderList() {
      clear(this.list);
      if (!this.entries.length) {
        this.list.append(
          el("div", {
            class: "atfb-placeholder",
            children: [
              el("p", { text: this.search ? "Nothing matches that." : "No entries yet." })
            ]
          })
        );
        return;
      }
      const onScreen = new Set(this.entries.map((entry) => entry.id));
      for (const id of Array.from(this.selection)) {
        if (!onScreen.has(id)) {
          this.selection.delete(id);
        }
      }
      this.list.append(this.renderBulkBar());
      const rows = el("div", { class: "atfe__rows", attrs: { role: "list" } });
      for (const entry of this.entries) {
        rows.append(this.renderRow(entry));
      }
      this.list.append(rows);
      if (this.pages > 1) {
        this.list.append(
          el("div", {
            class: "atfe__pager",
            children: [
              button("Previous", () => {
                this.page = Math.max(1, this.page - 1);
                void this.load();
              }),
              el("span", { text: `Page ${this.page} of ${this.pages}` }),
              button("Next", () => {
                this.page = Math.min(this.pages, this.page + 1);
                void this.load();
              })
            ]
          })
        );
      }
    }
    /**
     * The select-all row, and the actions that appear once something is ticked.
     *
     * The actions are rendered only when there is a selection rather than being
     * disabled, because a row of permanently-greyed buttons is noise on the
     * ninety-nine percent of visits that are just reading.
     */
    renderBulkBar() {
      const count = this.selection.size;
      const all = el("input", {
        type: "checkbox",
        attrs: { "aria-label": "Select every entry on this page" },
        on: {
          change: (event) => {
            const checked = event.target.checked;
            this.entries.forEach((entry) => {
              if (checked) {
                this.selection.add(entry.id);
              } else {
                this.selection.delete(entry.id);
              }
            });
            this.renderList();
          }
        }
      });
      all.checked = count > 0 && count === this.entries.length;
      all.indeterminate = count > 0 && count < this.entries.length;
      return el("div", {
        class: "atfe__bulk",
        children: [
          all,
          el("span", {
            class: "atfe__bulk-count",
            attrs: { role: "status" },
            text: count ? `${count} selected` : "Select"
          }),
          count ? button("Read", () => void this.bulk("atf-read")) : null,
          count ? button("Unread", () => void this.bulk("atf-unread")) : null,
          count ? button("Spam", () => void this.bulk("atf-spam")) : null,
          count && config?.canEdit ? button("Delete", () => void this.bulkDelete(), "danger") : null
        ]
      });
    }
    /**
     * Applies a status to everything selected.
     *
     * Sequential rather than concurrent. A bulk action over two hundred entries
     * fired as two hundred simultaneous requests is a self-inflicted denial of
     * service on a shared host, and the wall-clock difference is a second.
     */
    async bulk(status) {
      const ids = Array.from(this.selection);
      for (const id of ids) {
        try {
          await api.updateEntry(id, { status });
        } catch {
        }
      }
      this.selection.clear();
      await this.load();
    }
    /** Deletes everything selected, after one confirmation for the lot. */
    async bulkDelete() {
      const ids = Array.from(this.selection);
      const confirmed = await confirmAction(
        `Delete ${ids.length} ${ids.length === 1 ? "entry" : "entries"} and any files uploaded with them? It cannot be undone.`,
        "Delete entries"
      );
      if (!confirmed) {
        return;
      }
      for (const id of ids) {
        try {
          await api.deleteEntry(id);
        } catch {
        }
      }
      this.selection.clear();
      this.selected = null;
      await this.load();
      this.renderDetail();
    }
    /** One row, draggable. */
    renderRow(entry) {
      const unread = entry.status === "atf-unread";
      const row = el("div", {
        class: `atfe__row${unread ? " is-unread" : ""}${this.selected?.id === entry.id ? " is-selected" : ""}`,
        attrs: {
          role: "listitem",
          tabindex: "0",
          "data-entry": entry.id
        },
        children: [
          el("input", {
            class: "atfe__select",
            type: "checkbox",
            attrs: {
              "aria-label": `Select ${entry.title}`,
              checked: this.selection.has(entry.id)
            },
            on: {
              click: (event) => event.stopPropagation(),
              change: (event) => {
                if (event.target.checked) {
                  this.selection.add(entry.id);
                } else {
                  this.selection.delete(entry.id);
                }
                this.list.querySelector(".atfe__bulk")?.replaceWith(this.renderBulkBar());
              }
            }
          }),
          el("button", {
            class: `atfe__star${entry.starred ? " is-on" : ""}`,
            type: "button",
            attrs: { "aria-label": entry.starred ? "Unstar this entry" : "Star this entry" },
            on: {
              click: (event) => {
                event.stopPropagation();
                void this.toggleStar(entry);
              }
            },
            children: [icon("star-filled")]
          }),
          el("div", {
            class: "atfe__row-body",
            children: [
              el("strong", { text: entry.title }),
              el("span", { class: "atfe__row-meta", text: entry.dateHuman })
            ]
          }),
          entry.notes ? el("span", { class: "atfb-badge", text: `💬 ${entry.notes}` }) : null,
          entry.spam ? el("span", { class: "atfb-badge atfb-badge--spam", text: "spam" }) : null
        ]
      });
      row.addEventListener("click", () => {
        if (getDragManager().recentlyEndedDrag()) {
          return;
        }
        void this.select(entry);
      });
      row.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          void this.select(entry);
        }
      });
      row.addEventListener("pointerdown", (event) => {
        if (event.target.closest("button")) {
          return;
        }
        const ghost = el("div", {
          class: "atfe__ghost",
          children: [icon("feedback"), el("span", { text: entry.title })]
        });
        getDragManager().start({
          payload: buildPayload(
            ENTRY_PAYLOAD_TYPE,
            row,
            { entry, formId: entry.formId, formTitle: entry.formTitle },
            event,
            ghost
          ),
          origin: event
        });
      });
      return row;
    }
    /** Opens one entry in the detail pane. */
    async select(entry) {
      const seq = ++this.selectSeq;
      try {
        const fetched = await api.getEntry(entry.id);
        if (seq !== this.selectSeq) {
          return;
        }
        this.selected = fetched;
        const stale = this.entries.find((candidate) => candidate.id === entry.id);
        if (stale) {
          stale.status = this.selected.status;
        }
        this.renderList();
        this.renderDetail();
      } catch (error) {
        if (seq !== this.selectSeq) {
          return;
        }
        notify("Could not open that entry", error instanceof Error ? error.message : "", "error");
      }
    }
    /** Paints the detail pane. */
    renderDetail() {
      this.paintedSelectionId = this.selected?.id ?? 0;
      setIdentity(
        this.root,
        this.selected ? entryIdentity(this.selected, config?.adminUrl ?? "") : null
      );
      clear(this.detail);
      const entry = this.selected;
      if (!entry) {
        this.detail.append(
          el("div", { class: "atfb-placeholder", children: [el("p", { text: "Pick an entry to read it." })] })
        );
        return;
      }
      const answers = el("dl", { class: "atfe__answers" });
      for (const field of entry.fields) {
        if (!field.formatted) {
          continue;
        }
        answers.append(
          el("dt", { text: field.label || field.id }),
          el("dd", { text: field.formatted })
        );
      }
      this.detail.append(
        el("div", {
          class: "atfe__detail",
          children: [
            el("div", {
              class: "atfe__detail-head",
              children: [
                el("h2", { text: entry.title }),
                el("p", { class: "atfb-hint", text: `${entry.formTitle} — ${entry.dateHuman}` })
              ]
            }),
            answers,
            entry.quiz ? el("p", {
              class: "atfe__quiz",
              text: `Score: ${entry.quiz.score} of ${entry.quiz.total} (${entry.quiz.percent}%) — ${entry.quiz.passed ? "passed" : "not passed"}`
            }) : null,
            el("details", {
              class: "atfb-section",
              children: [
                el("summary", { text: "Where it came from" }),
                el("p", { class: "atfb-hint", text: entry.ip ? `IP: ${entry.ip}` : "No IP recorded." }),
                entry.referrer ? el("p", { class: "atfb-hint", text: `Referrer: ${entry.referrer}` }) : null,
                entry.userAgent ? el("p", { class: "atfb-hint", text: entry.userAgent }) : null
              ]
            }),
            el("div", {
              class: "atfe__actions",
              children: [
                button(
                  entry.status === "atf-spam" ? "Not spam" : "Mark as spam",
                  () => void this.setStatus(entry, entry.status === "atf-spam" ? "atf-read" : "atf-spam")
                ),
                button(
                  entry.status === "atf-unread" ? "Mark read" : "Mark unread",
                  () => void this.setStatus(entry, entry.status === "atf-unread" ? "atf-read" : "atf-unread")
                ),
                entry.canDelete ? button("Delete", () => void this.remove(entry), "danger") : null
              ]
            })
          ]
        })
      );
    }
    /** Stars or unstars. */
    async toggleStar(entry) {
      try {
        const updated = await api.updateEntry(entry.id, { starred: !entry.starred });
        entry.starred = updated.starred;
        this.renderList();
      } catch (error) {
        notify("Could not update that entry", error instanceof Error ? error.message : "", "error");
      }
    }
    /** Changes an entry's status. */
    async setStatus(entry, status) {
      try {
        const updated = await api.updateEntry(entry.id, { status });
        this.selected = updated;
        await this.load();
        this.renderDetail();
      } catch (error) {
        notify("Could not update that entry", error instanceof Error ? error.message : "", "error");
      }
    }
    /** Deletes an entry for good. */
    async remove(entry) {
      if (!await confirmAction("Delete this entry and any files uploaded with it? It cannot be undone.", "Delete entry")) {
        return;
      }
      try {
        await api.deleteEntry(entry.id);
        this.selected = null;
        await this.load();
        this.renderDetail();
      } catch (error) {
        notify("Could not delete that entry", error instanceof Error ? error.message : "", "error");
      }
    }
    /**
     * Downloads the current view as CSV.
     *
     * The CSV comes back in a JSON envelope rather than as a file response,
     * because this is a native window inside a single-page shell: navigating to
     * a download URL would take the whole desktop with it. A Blob and an
     * object URL keep the navigation local to a link nobody sees.
     */
    async exportEntries(format) {
      try {
        const { filename, csv } = await api.exportEntries({
          form_id: this.formId,
          search: this.search,
          starred: this.starred,
          format
        });
        const blob = new Blob([csv], {
          type: format === "json" ? "application/json" : "text/csv;charset=utf-8"
        });
        const url = URL.createObjectURL(blob);
        const link = el("a", { href: url, attrs: { download: filename } });
        document.body.append(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1e3);
      } catch (error) {
        notify("Could not export", error instanceof Error ? error.message : "", "error");
      }
    }
  }
  let mountedEntries = null;
  document.addEventListener("atf-open-entries-form", (event) => {
    const formId = Number(event.detail?.formId ?? 0);
    if (!formId) {
      return;
    }
    void mountedEntries?.showForm(formId);
  });
  let mountedEntriesRoot = null;
  function mountEntries() {
    if (mountedEntriesRoot?.isConnected) {
      return;
    }
    if (mountedEntries) {
      mountedEntries.destroy();
      mountedEntries = null;
      mountedEntriesRoot = null;
    }
    const root = document.querySelector("[data-atfe-root]:not([data-atfe-mounted])");
    if (!root || !config?.canRead) {
      return;
    }
    root.dataset.atfeMounted = "1";
    mountedEntriesRoot = root;
    pinWindowBodyScroll(root);
    void whenComponents().then(() => {
      if (!root.isConnected) {
        return;
      }
      mountedEntries = new EntriesWindow(root);
      void mountedEntries.start();
    });
  }
  function bootEntries() {
    mountEntries();
    handOffToWindow();
  }
  watchHandoffButton();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootEntries);
  } else {
    bootEntries();
  }
  document.addEventListener("os-window-content-loaded", mountEntries);
  exports.mountEntries = mountEntries;
  Object.defineProperty(exports, Symbol.toStringTag, { value: "Module" });
  return exports;
}({});
