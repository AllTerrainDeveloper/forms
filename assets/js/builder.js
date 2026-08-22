var allTerrainFormsBuilder = function(exports) {
  "use strict";
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
    const shell2 = getShell();
    if (shell2?.dragManager) {
      return shell2.dragManager;
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
  function insertionIndex(container, selector, clientY, ignore) {
    const children = Array.from(container.querySelectorAll(selector)).filter(
      (child) => child !== ignore && !child.classList.contains("atf-drag-ghost")
    );
    for (let index = 0; index < children.length; index++) {
      const rect = children[index].getBoundingClientRect();
      if (clientY < rect.top + rect.height / 2) {
        return index;
      }
    }
    return children.length;
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
    if (!config?.wpRestUrl) {
      throw new ApiError("AllTerrain Forms is not configured on this page.", 0);
    }
    const headers = config.nonce ? { "X-WP-Nonce": config.nonce } : {};
    const shell2 = getShell();
    const url = joinPath(config.wpRestUrl, route);
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
  function row(label, control2, hint2) {
    const target = control2.matches("input, select, textarea, button") ? control2 : control2.querySelector("input, select, textarea");
    if (target) {
      const id = target.id || `atf-c-${Math.random().toString(36).slice(2, 9)}`;
      target.id = id;
      return el("div", {
        class: "atfb-row",
        children: [
          el("label", { class: "atfb-row__label", text: label, attrs: { for: id } }),
          control2,
          hint2 ? el("p", { class: "atfb-row__hint", text: hint2 }) : null
        ]
      });
    }
    return el("div", {
      class: "atfb-row",
      children: [
        el("span", { class: "atfb-row__label", text: label }),
        control2,
        hint2 ? el("p", { class: "atfb-row__hint", text: hint2 }) : null
      ]
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
  function numberInput(value, onChange) {
    if (hasComponent("os-number-field")) {
      const host = document.createElement("os-number-field");
      host.setAttribute("value", value);
      host.classList.add("atfb-field");
      host.addEventListener("os-input-change", (event) => {
        onChange(String(event.detail?.value ?? ""));
      });
      return host;
    }
    return el("input", {
      class: "atfb-input",
      type: "number",
      value,
      on: {
        input: (event) => onChange(event.target.value)
      }
    });
  }
  function textArea(value, onChange, rows = 4) {
    const node = el("textarea", {
      class: "atfb-input atfb-input--area",
      attrs: { rows },
      on: {
        input: (event) => onChange(event.target.value)
      }
    });
    node.value = value;
    return node;
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
    const shell2 = window.wp?.os;
    componentsPending = shell2?.loadComponents ? shell2.loadComponents(COMPONENTS).catch(() => void 0) : Promise.resolve();
    return componentsPending;
  }
  async function confirmAction(message, title = "") {
    const shell2 = window.wp?.os;
    if (shell2?.confirm) {
      return shell2.confirm({ title, message, danger: true });
    }
    return window.confirm(message);
  }
  function notify(title, body = "", type = "info") {
    const shell2 = window.wp?.os;
    if (shell2?.notify) {
      shell2.notify({ title, body, type });
      return;
    }
    console.info(`[AllTerrain Forms] ${title}${body ? `: ${body}` : ""}`);
  }
  function raf(fn) {
    let queued = 0;
    let last;
    return (...args) => {
      last = args;
      if (queued) {
        return;
      }
      queued = window.requestAnimationFrame(() => {
        queued = 0;
        fn(...last);
      });
    };
  }
  function debounce(fn, wait) {
    let timer = 0;
    return (...args) => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => fn(...args), wait);
    };
  }
  function readSetting(key) {
    try {
      return window.localStorage.getItem(key) ?? "";
    } catch {
      return "";
    }
  }
  function writeSetting(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch {
    }
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
  const OPERATOR_LABELS = {
    is: "is",
    is_not: "is not",
    contains: "contains",
    not_contains: "does not contain",
    starts_with: "starts with",
    ends_with: "ends with",
    greater: "is more than",
    less: "is less than",
    greater_equal: "is at least",
    less_equal: "is at most",
    empty: "is empty",
    not_empty: "has any answer"
  };
  const VALUELESS_OPERATORS = ["empty", "not_empty"];
  function labelOf(fields, id) {
    const field = fields.find((candidate) => candidate.id === id);
    if (!field) {
      return null;
    }
    return field.label || "an untitled question";
  }
  function valueOf(fields, id, value) {
    const field = fields.find((candidate) => candidate.id === id);
    const choice = field?.choices?.find((candidate) => candidate.value === value);
    return choice?.label || value;
  }
  function describeTrigger(rule, fields) {
    const operator = OPERATOR_LABELS[rule.operator] ?? String(rule.operator);
    if (VALUELESS_OPERATORS.includes(rule.operator)) {
      return operator;
    }
    const value = valueOf(fields, rule.field, rule.value);
    const prefix = "is" === rule.operator ? "" : `${operator} `;
    return `${prefix}${value !== "" ? value : "(nothing)"}`;
  }
  function ruleTokens(rule, fields, ruleIndex = 0) {
    const subject = labelOf(fields, rule.field);
    const operator = OPERATOR_LABELS[rule.operator] ?? String(rule.operator);
    if (subject === null) {
      return [{ kind: "field", text: "a question that no longer exists", fieldId: rule.field, missing: true }];
    }
    const tokens = [
      { kind: "field", text: subject, fieldId: rule.field, missing: false },
      { kind: "operator", text: operator, operator: rule.operator, ruleIndex }
    ];
    if (VALUELESS_OPERATORS.includes(rule.operator)) {
      return tokens;
    }
    const value = valueOf(fields, rule.field, rule.value);
    tokens.push({
      kind: "value",
      text: value !== "" ? value : "(nothing)",
      raw: rule.value,
      sourceId: rule.field,
      ruleIndex
    });
    return tokens;
  }
  function logicTokens(field, fields) {
    const logic = field.logic;
    if (!logic?.enabled || !logic.rules.length) {
      return [];
    }
    const tokens = [
      { kind: "verb", text: logic.action === "hide" ? "Hidden when" : "Shown when" }
    ];
    logic.rules.forEach((rule, index) => {
      if (index > 0) {
        tokens.push({ kind: "join", text: logic.match === "all" ? "and" : "or" });
      }
      tokens.push(...ruleTokens(rule, fields, index));
    });
    return tokens;
  }
  function tokensToText(tokens) {
    return tokens.map((token) => token.text).join(" ");
  }
  function describeRule(rule, fields) {
    return tokensToText(ruleTokens(rule, fields));
  }
  function logicEdges(fields) {
    const edges = [];
    for (const field of fields) {
      const logic = field.logic;
      if (!logic?.enabled) {
        continue;
      }
      for (const rule of logic.rules) {
        if (!rule.field || rule.field === field.id) {
          continue;
        }
        edges.push({
          from: rule.field,
          to: field.id,
          label: describeRule(rule, fields),
          short: describeTrigger(rule, fields),
          action: logic.action,
          broken: labelOf(fields, rule.field) === null
        });
      }
    }
    return edges;
  }
  function controlCounts(fields) {
    const counts = /* @__PURE__ */ new Map();
    const seen = /* @__PURE__ */ new Set();
    for (const edge of logicEdges(fields)) {
      const pair = `${edge.from}->${edge.to}`;
      if (edge.broken || seen.has(pair)) {
        continue;
      }
      seen.add(pair);
      counts.set(edge.from, (counts.get(edge.from) ?? 0) + 1);
    }
    return counts;
  }
  const MARGIN = 10;
  const LABEL_MAX = 22;
  const SVG_NS = "http://www.w3.org/2000/svg";
  class LogicMap {
    constructor(host) {
      this.edges = [];
      this.teardowns = [];
      this.frame = 0;
      this.host = host;
      this.svg = document.createElementNS(SVG_NS, "svg");
      this.svg.setAttribute("class", "atfb-logicmap");
      this.svg.setAttribute("aria-hidden", "true");
      this.svg.setAttribute("focusable", "false");
      host.append(this.svg);
      const redraw = () => this.schedule();
      window.addEventListener("resize", redraw);
      this.teardowns.push(() => window.removeEventListener("resize", redraw));
      const scroller = host.closest(".atfb__canvas") ?? host;
      scroller.addEventListener("scroll", redraw, { passive: true });
      this.teardowns.push(() => scroller.removeEventListener("scroll", redraw));
      if (typeof ResizeObserver !== "undefined") {
        const observer = new ResizeObserver(redraw);
        observer.observe(host);
        this.teardowns.push(() => observer.disconnect());
      }
    }
    /** Replaces the connections and redraws. */
    setEdges(edges) {
      this.edges = edges;
      this.schedule();
    }
    /**
     * Dims every curve that does not touch a field.
     *
     * Passing an empty id restores them all. Applied as a class on the layer
     * rather than per-path styles so the transition is one paint.
     */
    highlight(fieldId) {
      this.svg.classList.toggle("is-focused", Boolean(fieldId));
      this.svg.querySelectorAll("[data-from]").forEach((node) => {
        const touches = fieldId && (node.dataset.from === fieldId || node.dataset.to === fieldId);
        node.classList.toggle("is-lit", Boolean(touches));
      });
    }
    /** Removes the layer and every listener. */
    destroy() {
      this.teardowns.forEach((teardown) => teardown());
      this.teardowns = [];
      this.svg.remove();
      if (this.frame) {
        cancelAnimationFrame(this.frame);
      }
    }
    /** Coalesces redraw requests to one per frame. */
    schedule() {
      if (this.frame) {
        return;
      }
      this.frame = requestAnimationFrame(() => {
        this.frame = 0;
        this.draw();
      });
    }
    /** Measures the cards and rebuilds every path. */
    draw() {
      this.svg.replaceChildren();
      const host = this.host.getBoundingClientRect();
      this.svg.setAttribute("viewBox", `0 0 ${host.width} ${host.height}`);
      this.svg.setAttribute("width", String(host.width));
      this.svg.setAttribute("height", String(host.height));
      const lanes = /* @__PURE__ */ new Map();
      for (const edge of this.edges) {
        const from = this.cardRect(edge.from);
        const to = this.cardRect(edge.to);
        if (!from || !to) {
          continue;
        }
        const lane = lanes.get(edge.from) ?? 0;
        lanes.set(edge.from, lane + 1);
        this.drawEdge(edge, from, to, host, lane);
      }
    }
    /** One card's box, in the host's coordinate space. */
    cardRect(fieldId) {
      const card = this.host.querySelector(
        `[data-atfb-card="${CSS.escape(fieldId)}"]`
      );
      return card ? card.getBoundingClientRect() : null;
    }
    /** Draws one connection and its label. */
    drawEdge(edge, from, to, host, lane) {
      const startX = from.right - host.left;
      const startY = from.top + from.height / 2 - host.top;
      const endX = to.right - host.left;
      const endY = to.top + to.height / 2 - host.top;
      const available = Math.max(0, host.width - MARGIN - Math.max(startX, endX));
      const wanted2 = 22 + lane * 11 + Math.min(22, Math.abs(endY - startY) / 8);
      const reach = Math.max(12, Math.min(wanted2, available * 0.4));
      const path = document.createElementNS(SVG_NS, "path");
      path.setAttribute(
        "d",
        `M ${startX} ${startY} C ${startX + reach} ${startY}, ${endX + reach} ${endY}, ${endX} ${endY}`
      );
      path.setAttribute("class", `atfb-logicmap__path is-${edge.action}${edge.broken ? " is-broken" : ""}`);
      path.dataset.from = edge.from;
      path.dataset.to = edge.to;
      const title = document.createElementNS(SVG_NS, "title");
      title.textContent = edge.label;
      path.append(title);
      const dot = document.createElementNS(SVG_NS, "circle");
      dot.setAttribute("cx", String(endX));
      dot.setAttribute("cy", String(endY));
      dot.setAttribute("r", "3.5");
      dot.setAttribute("class", `atfb-logicmap__dot is-${edge.action}${edge.broken ? " is-broken" : ""}`);
      dot.dataset.from = edge.from;
      dot.dataset.to = edge.to;
      this.svg.append(path, dot);
      const text = document.createElementNS(SVG_NS, "text");
      text.setAttribute("x", String(host.width - MARGIN));
      text.setAttribute("y", String(endY + 3));
      text.setAttribute("text-anchor", "end");
      text.setAttribute("class", "atfb-logicmap__label");
      text.dataset.from = edge.from;
      text.dataset.to = edge.to;
      text.textContent = edge.short.length > LABEL_MAX ? `${edge.short.slice(0, LABEL_MAX - 1)}…` : edge.short;
      const labelTitle = document.createElementNS(SVG_NS, "title");
      labelTitle.textContent = edge.label;
      text.append(labelTitle);
      this.svg.append(text);
    }
  }
  const SHAPES = {
    text: "text",
    email: "text",
    url: "text",
    tel: "text",
    number: "text",
    password: "text",
    date: "text",
    time: "text",
    datetime: "text",
    date_range: "composite",
    textarea: "textarea",
    select: "select",
    country: "select",
    multiselect: "options",
    radio: "options",
    checkboxes: "options",
    image_choice: "options",
    quiz: "options",
    switch: "toggle",
    consent: "toggle",
    file: "file",
    range: "range",
    rating: "summary",
    scale: "summary",
    likert: "summary",
    signature: "summary",
    repeater: "repeater",
    color: "text",
    name: "composite",
    address: "composite",
    total: "text",
    hidden: "static",
    heading: "static",
    html: "static",
    divider: "static",
    spacer: "static",
    page_break: "static"
  };
  function shapeFor(type) {
    return SHAPES[type] ?? "text";
  }
  function boundValue(field, key) {
    const choice = /^choice:(\d+):(label|value)$/.exec(key);
    if (choice) {
      return String(field.choices?.[Number(choice[1])]?.[choice[2]] ?? "");
    }
    return String(field[key] ?? "");
  }
  function editableText(options) {
    const { value, onInput, onCommit } = options;
    const node = el("span", {
      class: `${options.class} atfb-editable`,
      text: value,
      attrs: {
        contenteditable: "plaintext-only",
        role: "textbox",
        spellcheck: "false",
        "data-placeholder": options.placeholder
      }
    });
    if (options.bind) {
      node.dataset.atfbBind = options.bind;
    }
    node.addEventListener("pointerdown", (event) => event.stopPropagation());
    node.addEventListener("input", () => onInput(node.textContent ?? ""));
    node.addEventListener("keydown", (event) => {
      if ("Enter" === event.key) {
        event.preventDefault();
        node.blur();
      }
      if ("Escape" === event.key) {
        node.textContent = value;
        node.blur();
      }
      event.stopPropagation();
    });
    node.addEventListener("blur", () => onCommit?.());
    return node;
  }
  function optionInputFor(type) {
    const input = el("input", {
      class: "atf-choice__input",
      type: "checkboxes" === type || "multiselect" === type ? "checkbox" : "radio"
    });
    input.disabled = true;
    return input;
  }
  function renderFieldPreview(field, type, handlers) {
    const shape = shapeFor(field.type);
    const label = editableText({
      value: field.label,
      placeholder: "Write the question…",
      class: "atf-label",
      bind: "label",
      onInput: (value) => handlers.edit((live) => {
        live.label = value;
      }),
      // Committed on blur rather than per keystroke: the label appears in other
      // cards' condition chips and in the merge-tag picker, and repainting the
      // canvas on every character would take the caret with it.
      onCommit: () => handlers.restructure(() => {
      })
    });
    const parts = [
      // A toggle draws its own label beside the switch, exactly as the front end
      // does — a second one above it was the same words twice.
      "static" === shape || "toggle" === shape ? null : label,
      control(field, type, shape, handlers),
      hint(field, type, handlers)
    ];
    return el("div", {
      // `.atf-form` is what the theme's custom properties are scoped to, and
      // `.atf-field` is what gives the control its spacing. Both are the real
      // front-end classes: the look here is the stylesheet, not a copy of it.
      class: "atfb-preview atf-form",
      children: [
        el("div", {
          class: `atf-field atf-field--${field.type}`,
          children: parts
        })
      ]
    });
  }
  function hint(field, type, handlers) {
    if (!type?.supports.includes("hint")) {
      return null;
    }
    const node = editableText({
      value: field.hint ?? "",
      placeholder: "Add a hint…",
      class: "atf-hint",
      bind: "hint",
      onInput: (value) => handlers.edit((live) => {
        live.hint = value;
      })
    });
    node.setAttribute("aria-label", "Hint");
    return el("p", { class: "atfb-preview__hint", children: [node] });
  }
  function control(field, type, shape, handlers) {
    switch (shape) {
      case "text":
        return placeholderBox(field, "atf-input", handlers);
      case "textarea":
        return placeholderBox(field, "atf-input atf-textarea", handlers, true);
      case "select":
        return el("div", {
          class: "atfb-preview__select",
          children: [placeholderBox(field, "atf-input atf-select", handlers)]
        });
      case "options":
        return optionList(field, handlers);
      case "toggle":
        return el("div", {
          // The modifier matters: a switch and a consent tick box are the same
          // shape here but not the same control on the page, and the front end
          // tells them apart by exactly this class.
          class: "switch" === field.type ? "atf-toggle atf-toggle--switch" : "atf-toggle",
          children: [
            (() => {
              const box = el("input", { class: "atf-toggle__input", type: "checkbox" });
              box.disabled = true;
              return box;
            })(),
            editableText({
              value: field.label || "",
              placeholder: "consent" === field.type ? "What are they agreeing to?…" : "What does this turn on?…",
              class: "atf-toggle__label",
              bind: "label",
              onInput: (value) => handlers.edit((live) => {
                live.label = value;
              }),
              onCommit: () => handlers.restructure(() => {
              })
            })
          ]
        });
      case "file":
        return el("div", { class: "atf-file", children: [el("input", { class: "atf-file__input", type: "file" })] });
      case "range":
        return el("input", { class: "atf-range__input", type: "range" });
      case "composite":
        return el("div", {
          class: "atf-composite__parts",
          children: [
            el("span", { class: "atf-input atfb-preview__ghost", text: "" }),
            el("span", { class: "atf-input atfb-preview__ghost", text: "" })
          ]
        });
      case "static":
        return staticBlock(field, type, handlers);
      case "repeater":
        return repeaterContainer(field, handlers);
      default:
        return el("div", {
          class: "atfb-preview__stack",
          children: [
            el("p", {
              class: "atfb-preview__summary",
              text: `${type?.label ?? field.type} — set this up in the panel on the right.`
            })
          ]
        });
    }
  }
  function repeaterContainer(field, handlers) {
    const subs = field.fields ?? [];
    const zone = el("div", {
      class: "atfb-repeater__zone",
      attrs: { "data-atfb-repeater-zone": field.id }
    });
    subs.forEach((sub) => zone.append(repeaterSubCard(field, sub, handlers)));
    if (!subs.length) {
      zone.append(
        el("p", {
          class: "atfb-repeater__empty",
          text: "Drag fields from the palette in here — the visitor gets a fresh copy of each with every row they add."
        })
      );
    }
    return el("div", {
      class: "atfb-repeater",
      attrs: { "data-atfb-repeater": field.id },
      children: [
        el("div", {
          class: "atf-repeater__row atfb-repeater__frame",
          children: [
            el("div", {
              class: "atf-repeater__row-head",
              children: [
                el("span", {
                  class: "atf-repeater__title atfb-repeater__title",
                  children: [
                    editableText({
                      value: String(field.itemLabel ?? ""),
                      placeholder: "Row",
                      class: "atfb-repeater__item-label",
                      bind: "itemLabel",
                      onInput: (value) => handlers.edit((live) => {
                        live.itemLabel = value;
                      })
                    }),
                    el("span", { class: "atfb-repeater__ordinal", text: "1", attrs: { "aria-hidden": "true" } })
                  ]
                })
              ]
            }),
            zone
          ]
        }),
        repeatButton(field, handlers)
      ]
    });
  }
  function repeaterSubCard(repeater, sub, handlers) {
    const subId = sub.id;
    const forSub = (apply) => (live) => {
      const target = (live.fields ?? []).find((candidate) => candidate.id === subId);
      if (target) {
        apply(target);
      }
    };
    const subHandlers = {
      edit: (apply) => handlers.edit(forSub(apply)),
      restructure: (apply) => handlers.restructure(forSub(apply)),
      types: handlers.types,
      selectedId: handlers.selectedId
    };
    const type = handlers.types?.(sub.type);
    return el("div", {
      class: `atfb-subcard${handlers.selectedId === subId ? " is-selected" : ""}`,
      attrs: {
        "data-atfb-subfield": subId,
        "data-atfb-parent": repeater.id,
        tabindex: "0",
        role: "button",
        "aria-label": `${sub.label || type?.label || sub.type}, inside ${repeater.label || "the repeater"}`
      },
      children: [
        el("div", {
          class: "atfb-subcard__head",
          children: [
            el("span", { class: "atfb-subcard__type", text: type?.label ?? sub.type }),
            sub.required ? el("span", { class: "atfb-subcard__required", text: "Required" }) : null,
            el("button", {
              class: "atfb-preview__remove",
              type: "button",
              title: "Remove this field from the repeater",
              attrs: { "aria-label": `Remove ${sub.label || type?.label || "this field"}` },
              on: {
                pointerdown: (event) => event.stopPropagation(),
                click: (event) => {
                  event.stopPropagation();
                  handlers.restructure((live) => {
                    const list = live.fields ?? [];
                    const index = list.findIndex((candidate) => candidate.id === subId);
                    if (index >= 0) {
                      list.splice(index, 1);
                    }
                  });
                }
              },
              children: [el("span", { text: "×" })]
            })
          ]
        }),
        renderFieldPreview(sub, type, subHandlers)
      ]
    });
  }
  function placeholderBox(field, className, handlers, tall = false) {
    const box = editableText({
      value: field.placeholder ?? "",
      placeholder: "select" === field.type || "country" === field.type ? "Choose…" : "Placeholder…",
      class: `${className} atfb-preview__box${tall ? " atfb-preview__box--tall" : ""}`,
      bind: "placeholder",
      onInput: (value) => handlers.edit((live) => {
        live.placeholder = value;
      })
    });
    box.setAttribute("aria-label", "Placeholder");
    return box;
  }
  function labelledButton(text, fallback2, className, bind2, handlers, write) {
    return el("span", {
      class: `atf-button ${className} atfb-preview__button`,
      children: [
        editableText({
          value: text,
          placeholder: fallback2,
          class: "atfb-preview__button-text",
          bind: bind2,
          onInput: (value) => handlers.edit((live) => write(live, value))
        })
      ]
    });
  }
  function repeatButton(field, handlers) {
    return labelledButton(
      String(field.addLabel ?? ""),
      "Add another",
      "atf-button--ghost atfb-repeater__add",
      "addLabel",
      handlers,
      (live, value) => {
        live.addLabel = value;
      }
    );
  }
  function optionList(field, handlers) {
    const list = el("div", { class: "atf-choices__list" });
    (field.choices ?? []).forEach((choice, index) => {
      list.append(
        el("div", {
          class: "atf-choice atfb-preview__option",
          children: [
            optionInputFor(field.type),
            editableText({
              value: choice.label,
              placeholder: "Option…",
              class: "atf-choice__label",
              bind: `choice:${index}:label`,
              onInput: (value) => {
                handlers.edit((live) => {
                  const target = live.choices?.[index];
                  if (!target) {
                    return;
                  }
                  const mirroring = !target.value || target.value === target.label;
                  target.label = value;
                  if (mirroring) {
                    target.value = value;
                  }
                });
              }
            }),
            el("button", {
              class: "atfb-preview__remove",
              type: "button",
              title: "Remove this option",
              attrs: { "aria-label": `Remove ${choice.label || "this option"}` },
              on: {
                pointerdown: (event) => event.stopPropagation(),
                click: (event) => {
                  event.stopPropagation();
                  handlers.restructure((live) => {
                    live.choices.splice(index, 1);
                  });
                }
              },
              children: [el("span", { text: "×" })]
            })
          ]
        })
      );
    });
    list.append(
      el("button", {
        class: "atfb-preview__add",
        type: "button",
        on: {
          pointerdown: (event) => event.stopPropagation(),
          click: (event) => {
            event.stopPropagation();
            handlers.restructure((live) => {
              const next = live.choices.length + 1;
              live.choices.push({ label: `Option ${next}`, value: `Option ${next}` });
            });
          }
        },
        children: [el("span", { text: "+ Add option" })]
      })
    );
    return el("fieldset", { class: "atf-choices", children: [list] });
  }
  function staticBlock(field, type, handlers) {
    if ("heading" === field.type) {
      return editableText({
        value: field.label,
        placeholder: "Section heading…",
        class: "atf-heading",
        bind: "label",
        onInput: (value) => handlers.edit((live) => {
          live.label = value;
        }),
        onCommit: () => handlers.restructure(() => {
        })
      });
    }
    if ("divider" === field.type) {
      return el("hr", { class: "atf-divider" });
    }
    if ("page_break" === field.type) {
      return el("div", {
        class: "atfb-preview__stack",
        children: [
          el("p", {
            class: "atfb-progress-name",
            children: [
              editableText({
                value: field.label,
                placeholder: "Name this step…",
                class: "atf-progress__label",
                bind: "label",
                onInput: (value) => handlers.edit((live) => {
                  live.label = value;
                }),
                // The name appears in the step indicator on every page of
                // the form, so the preview has to be repainted once the
                // wording settles.
                onCommit: () => handlers.restructure(() => {
                })
              })
            ]
          }),
          el("p", { class: "atfb-preview__summary", text: "Everything after this is a new page." }),
          el("div", {
            class: "atf-nav atfb-preview__nav",
            children: [
              labelledButton(
                String(field.prevLabel ?? ""),
                "Back",
                "atf-button--secondary",
                "prevLabel",
                handlers,
                (live, value) => {
                  live.prevLabel = value;
                }
              ),
              labelledButton(
                String(field.nextLabel ?? ""),
                "Next",
                "",
                "nextLabel",
                handlers,
                (live, value) => {
                  live.nextLabel = value;
                }
              )
            ]
          })
        ]
      });
    }
    return el("p", {
      class: "atfb-preview__summary",
      text: `${type?.label ?? field.type} — nothing is shown to the visitor here.`
    });
  }
  const cache = /* @__PURE__ */ new Map();
  function mergeTags(formId) {
    let pending2 = cache.get(formId);
    if (!pending2) {
      pending2 = api.mergeTags(formId).catch(() => []);
      cache.set(formId, pending2);
    }
    return pending2;
  }
  function forgetMergeTags(formId) {
    cache.delete(formId);
  }
  function flatten(groups) {
    const all = /* @__PURE__ */ new Map();
    for (const group of groups) {
      for (const item of group.items) {
        all.set(item.tag, item);
      }
    }
    return all;
  }
  function resolvePreview(text, groups) {
    const all = flatten(groups);
    return text.replace(/\{[a-z_]+(?::[^}]*)?\}/gi, (match) => {
      const known = all.get(match.toLowerCase());
      return known ? known.sample : match;
    });
  }
  function hasTags(text) {
    return /\{[a-z_]+(?::[^}]*)?\}/i.test(text);
  }
  let openPicker = null;
  function closePicker() {
    openPicker?.remove();
    openPicker = null;
  }
  if (typeof document !== "undefined") {
    document.addEventListener("pointerdown", (event) => {
      const target = event.target;
      if (target?.closest(".atfb-tagpick__open")) {
        return;
      }
      if (openPicker && !openPicker.contains(target)) {
        closePicker();
      }
    });
    document.addEventListener("keydown", (event) => {
      if ("Escape" === event.key && openPicker) {
        closePicker();
        event.stopPropagation();
      }
    });
  }
  function insertAtCursor(field, text) {
    const start = field.selectionStart ?? field.value.length;
    const end = field.selectionEnd ?? field.value.length;
    field.value = field.value.slice(0, start) + text + field.value.slice(end);
    const caret = start + text.length;
    field.setSelectionRange(caret, caret);
    field.focus();
    field.dispatchEvent(new Event("input", { bubbles: true }));
  }
  function clipBottom(from) {
    let node = from.parentElement;
    while (node && node !== document.body) {
      const overflow = getComputedStyle(node).overflowY;
      if ("auto" === overflow || "scroll" === overflow || "hidden" === overflow) {
        return node.getBoundingClientRect().bottom;
      }
      node = node.parentElement;
    }
    return window.innerHeight;
  }
  function buildPicker(groups, onPick) {
    const search = el("input", {
      class: "atfb-input atfb-tagpick__search",
      type: "search",
      placeholder: "Search values…",
      attrs: { "aria-label": "Search values" }
    });
    const list = el("div", { class: "atfb-tagpick__list" });
    const paint = (query2) => {
      list.replaceChildren();
      const needle = query2.trim().toLowerCase();
      let shown = 0;
      for (const group of groups) {
        const matches = group.items.filter(
          (item) => !needle || item.label.toLowerCase().includes(needle) || item.tag.toLowerCase().includes(needle)
        );
        if (!matches.length) {
          if (group.empty && !needle && !group.items.length) {
            list.append(
              el("p", { class: "atfb-tagpick__group", text: group.label }),
              el("p", { class: "atfb-tagpick__empty", text: group.empty })
            );
          }
          continue;
        }
        list.append(el("p", { class: "atfb-tagpick__group", text: group.label }));
        for (const item of matches) {
          shown += 1;
          list.append(
            el("button", {
              class: "atfb-tagpick__item",
              type: "button",
              on: {
                click: () => {
                  onPick(item.tag);
                  closePicker();
                }
              },
              children: [
                el("span", {
                  class: "atfb-tagpick__item-main",
                  children: [
                    el("span", { class: "atfb-tagpick__label", text: item.label }),
                    el("code", { class: "atfb-tagpick__tag", text: item.tag })
                  ]
                }),
                item.hint || item.sample ? el("span", {
                  class: "atfb-tagpick__meta",
                  // The sample is the part people actually read, so it
                  // leads; the hint explains the cases where it is empty.
                  text: item.sample ? `e.g. ${item.sample}` : item.hint
                }) : null
              ]
            })
          );
        }
      }
      if (!shown && needle) {
        list.append(el("p", { class: "atfb-tagpick__empty", text: `Nothing matches “${query2}”.` }));
      }
    };
    paint("");
    search.addEventListener("input", () => paint(search.value));
    return el("div", {
      class: "atfb-tagpick",
      attrs: { role: "dialog", "aria-label": "Insert a value" },
      children: [
        el("p", {
          class: "atfb-tagpick__intro",
          text: "Pick something to drop in. It is filled in when the form is submitted."
        }),
        search,
        list
      ]
    });
  }
  function taggable(field, options) {
    const insert = el("button", {
      class: "atfb-button atfb-button--ghost atfb-tagpick__open",
      type: "button",
      title: "Insert a value from the submission",
      children: [icon("shortcode"), el("span", { text: "Insert a value" })]
    });
    const wrapper = el("div", {
      class: "atfb-taggable",
      children: [field, el("div", { class: "atfb-taggable__tools", children: [insert] })]
    });
    const preview = options.preview === false ? null : el("p", { class: "atfb-taggable__preview" });
    if (preview) {
      wrapper.append(preview);
    }
    const repaint = () => {
      if (!preview) {
        return;
      }
      if (!hasTags(field.value)) {
        preview.textContent = "";
        preview.hidden = true;
        return;
      }
      void mergeTags(options.formId).then((groups) => {
        preview.hidden = false;
        preview.replaceChildren(
          el("span", { class: "atfb-taggable__preview-label", text: "Reads as" }),
          el("span", { text: resolvePreview(field.value, groups) })
        );
      });
    };
    field.addEventListener("input", repaint);
    repaint();
    insert.addEventListener("click", (event) => {
      event.stopPropagation();
      if (openPicker && wrapper.contains(openPicker)) {
        closePicker();
        return;
      }
      closePicker();
      void mergeTags(options.formId).then((groups) => {
        const picker = buildPicker(groups, (tag) => {
          insertAtCursor(field, tag);
          repaint();
        });
        wrapper.append(picker);
        openPicker = picker;
        if (window.innerHeight - picker.getBoundingClientRect().top < picker.offsetHeight) {
          picker.classList.add("atfb-tagpick--above");
        } else if (picker.getBoundingClientRect().bottom > clipBottom(wrapper)) {
          picker.classList.add("atfb-tagpick--above");
        }
        picker.querySelector(".atfb-tagpick__search")?.focus();
      });
    });
    return wrapper;
  }
  function px(value, fallback2) {
    const parsed = parseFloat(String(value ?? ""));
    return Number.isFinite(parsed) ? parsed : fallback2;
  }
  function nearest(value, steps) {
    let best = steps[0];
    for (const step of steps) {
      if (Math.abs(step.at - value) < Math.abs(best.at - value)) {
        best = step;
      }
    }
    return best.value;
  }
  const ROUNDNESS = [
    { value: "square", label: "Square", at: 0 },
    { value: "soft", label: "Soft", at: 4 },
    { value: "rounded", label: "Rounded", at: 10 },
    { value: "pill", label: "Pill", at: 999 }
  ];
  const DENSITY = [
    { value: "compact", label: "Compact", at: 7 },
    { value: "cosy", label: "Cosy", at: 9 },
    { value: "roomy", label: "Roomy", at: 13 }
  ];
  const SHADOW = [
    { value: "none", label: "None" },
    { value: "subtle", label: "Subtle" },
    { value: "lifted", label: "Lifted" },
    { value: "hard", label: "Hard" }
  ];
  const FIELD_STYLE = [
    { value: "outline", label: "Outlined" },
    { value: "filled", label: "Filled" },
    { value: "underline", label: "Underline" },
    { value: "none", label: "Bare" }
  ];
  const LABELS = [
    { value: "top", label: "Above" },
    { value: "floating", label: "Floating" },
    { value: "left", label: "In the margin" },
    { value: "hidden", label: "Hidden" }
  ];
  function quickDials() {
    return [
      {
        id: "accent",
        label: "Accent",
        hint: "The colour of buttons, focus rings and anything selected.",
        kind: "colour",
        owns: ["accent", "accent-soft", "border-focus", "focus-ring-color", "button-bg", "button-bg-hover"],
        read: (current) => current.accent ?? "#2271b1",
        apply: (value) => ({
          accent: value,
          // The soft wash and the focus ring are the accent at other
          // strengths. Setting them together is the difference between
          // "changed the accent" and "changed one of six places the accent
          // appears, and now they disagree".
          "accent-soft": `color-mix( in srgb, ${value} 12%, transparent )`,
          "border-focus": value,
          "focus-ring-color": value,
          "button-bg": value,
          "button-bg-hover": `color-mix( in srgb, ${value} 88%, #000 )`
        })
      },
      {
        id: "roundness",
        label: "Roundness",
        hint: "Corners, everywhere at once.",
        kind: "scale",
        steps: ROUNDNESS.map(({ value, label }) => ({ value, label })),
        owns: ["radius-field", "radius-button", "radius-card", "radius-check"],
        read: (current) => nearest(px(current["radius-field"], 4), ROUNDNESS),
        apply: (step) => {
          const base = ROUNDNESS.find((r) => r.value === step)?.at ?? 4;
          return {
            "radius-field": `${base}px`,
            "radius-button": `${base}px`,
            "radius-card": `${Math.min(base * 2, 28)}px`,
            "radius-check": `${Math.min(base, 6)}px`
          };
        }
      },
      {
        id: "density",
        label: "Density",
        hint: "How much air the form has.",
        kind: "scale",
        steps: DENSITY.map(({ value, label }) => ({ value, label })),
        owns: ["pad-field-x", "pad-field-y", "gap-fields", "gap-label", "button-pad-x", "button-pad-y"],
        read: (current) => nearest(px(current["pad-field-y"], 9), DENSITY),
        apply: (step) => {
          const y = DENSITY.find((d) => d.value === step)?.at ?? 9;
          return {
            "pad-field-y": `${y}px`,
            "pad-field-x": `${Math.round(y * 1.4)}px`,
            "gap-fields": `${Math.round(y * 2.2)}px`,
            "gap-label": `${Math.max(4, Math.round(y * 0.7))}px`,
            "button-pad-y": `${y + 2}px`,
            "button-pad-x": `${Math.round(y * 2.2)}px`
          };
        }
      },
      {
        id: "shadow",
        label: "Depth",
        hint: "How far the form sits off the page.",
        kind: "scale",
        steps: SHADOW,
        owns: ["shadow-field", "shadow-field-focus", "shadow-button", "shadow-button-hover", "shadow-card"],
        read: (current) => {
          const card = current["shadow-card"] ?? "none";
          if (card === "none" || card.trim() === "") {
            return current["shadow-field"] && current["shadow-field"] !== "none" ? "hard" : "none";
          }
          return card.includes("0 1px") || card.includes("2px") ? "subtle" : "lifted";
        },
        apply: (step) => {
          switch (step) {
            case "subtle":
              return {
                "shadow-field": "none",
                "shadow-field-focus": "none",
                "shadow-button": "0 1px 2px rgba( 0, 0, 0, 0.12 )",
                "shadow-button-hover": "0 2px 4px rgba( 0, 0, 0, 0.16 )",
                "shadow-card": "0 1px 3px rgba( 0, 0, 0, 0.1 )"
              };
            case "lifted":
              return {
                "shadow-field": "none",
                "shadow-field-focus": "0 4px 12px rgba( 0, 0, 0, 0.12 )",
                "shadow-button": "0 4px 10px rgba( 0, 0, 0, 0.16 )",
                "shadow-button-hover": "0 8px 20px rgba( 0, 0, 0, 0.2 )",
                "shadow-card": "0 10px 30px rgba( 0, 0, 0, 0.14 )"
              };
            case "hard":
              return {
                "shadow-field": "3px 3px 0 currentColor",
                "shadow-field-focus": "5px 5px 0 currentColor",
                "shadow-button": "4px 4px 0 currentColor",
                "shadow-button-hover": "2px 2px 0 currentColor",
                "shadow-card": "none"
              };
            default:
              return {
                "shadow-field": "none",
                "shadow-field-focus": "none",
                "shadow-button": "none",
                "shadow-button-hover": "none",
                "shadow-card": "none"
              };
          }
        }
      },
      {
        id: "field-style",
        label: "Fields",
        hint: "How an input is drawn.",
        kind: "choice",
        steps: FIELD_STYLE,
        owns: ["field-style"],
        read: (current) => current["field-style"] ?? "outline",
        apply: (step) => ({ "field-style": step })
      },
      {
        id: "labels",
        label: "Labels",
        hint: "Where the question sits relative to the answer.",
        kind: "choice",
        steps: LABELS,
        owns: ["label-position"],
        read: (current) => current["label-position"] ?? "top",
        apply: (step) => ({ "label-position": step })
      }
    ];
  }
  function dialOwning(token) {
    return quickDials().find((dial) => dial.owns.includes(token)) ?? null;
  }
  function mountThemeControls(options) {
    let active = options.activeSlug;
    let overrides = { ...options.overrides };
    let themes = options.themes;
    const replaceOverrides = (next) => {
      overrides = next;
      options.onOverridesReplaced?.({ ...next });
    };
    const preview = el("div", { class: "atfs-preview__frame" });
    const controls = el("div", { class: "atfs-controls__body" });
    const quick = el("div", { class: "atfs-quick" });
    const swatches = el("div", { class: "atfs-themes" });
    const repaint = debounce(async () => {
      try {
        const html = await options.previewFor(active, overrides);
        const pane = preview.closest(".atf-studio__preview");
        const scrolled = pane?.scrollTop ?? 0;
        preview.innerHTML = html;
        if (pane) {
          pane.scrollTop = scrolled;
        }
        document.dispatchEvent(new CustomEvent("atf-refresh"));
      } catch (error) {
        clear(preview);
        preview.append(
          el("p", { class: "atfb-error", text: error instanceof Error ? error.message : "Preview failed." })
        );
      }
    }, 180);
    const CLASS_TOKENS = {
      "label-position": "atf-labels-",
      "field-style": "atf-fields-"
    };
    const swapClass = (target, prefix, value) => {
      const safe = value.replace(/[^a-z0-9_-]/gi, "");
      for (const existing of [...target.classList]) {
        if (existing.startsWith(prefix)) {
          target.classList.remove(existing);
        }
      }
      if (safe) {
        target.classList.add(prefix + safe);
      }
    };
    const paintNow = (written) => {
      const wrap = preview.querySelector(".atf-form-wrap");
      const formEl = wrap?.querySelector(".atf-form");
      if (!wrap) {
        return;
      }
      for (const [token, value] of Object.entries(written)) {
        if ("" === value) {
          wrap.style.removeProperty(`--atf-${token}`);
        } else {
          wrap.style.setProperty(`--atf-${token}`, value);
        }
        if (formEl && CLASS_TOKENS[token]) {
          swapClass(formEl, CLASS_TOKENS[token], value || (themes.find((t) => t.slug === active)?.resolved[token] ?? ""));
        }
      }
    };
    const paintTheme = (theme) => {
      paintNow(theme.resolved);
      const formEl = preview.querySelector(".atf-form");
      if (formEl) {
        swapClass(formEl, "atf-theme-", theme.slug);
        formEl.classList.toggle("atf-is-dark", !!theme.dark);
      }
    };
    const resolve = (token) => {
      if (overrides[token.token] !== void 0) {
        return overrides[token.token];
      }
      const theme = themes.find((candidate) => candidate.slug === active);
      return theme?.resolved?.[token.token] ?? token.default;
    };
    const renderThemes = () => {
      clear(swatches);
      for (const theme of themes) {
        const card = el("button", {
          class: `atfs-theme${theme.slug === active ? " is-active" : ""}`,
          type: "button",
          attrs: { "aria-pressed": theme.slug === active, title: theme.description },
          children: [
            // A miniature of the theme, painted from its own resolved
            // tokens — so the picker previews each theme rather than
            // showing ten identical cards with different names.
            el("span", {
              class: "atfs-theme__chip",
              style: {
                background: theme.resolved["surface"] ?? "#fff",
                borderColor: theme.resolved["border"] ?? "#ccc",
                borderRadius: theme.resolved["radius-field"] ?? "4px",
                boxShadow: theme.resolved["shadow-card"] ?? "none"
              },
              children: [
                el("span", {
                  class: "atfs-theme__accent",
                  style: {
                    background: theme.resolved["accent"] ?? "#2271b1",
                    borderRadius: theme.resolved["radius-button"] ?? "4px"
                  }
                })
              ]
            }),
            el("span", { class: "atfs-theme__name", text: theme.label }),
            theme.custom ? el("span", { class: "atfb-badge", text: "yours" }) : null
          ],
          on: {
            click: () => {
              active = theme.slug;
              replaceOverrides({});
              options.onTheme(active);
              paintTheme(theme);
              renderThemes();
              syncQuick();
              syncControlsSoon();
              syncDeleteButton();
            }
          }
        });
        swatches.append(card);
      }
    };
    const currentTokens = () => {
      const theme = themes.find((candidate) => candidate.slug === active);
      const resolved = { ...theme?.resolved ?? {} };
      for (const [name, value] of Object.entries(overrides)) {
        resolved[name] = value;
      }
      return resolved;
    };
    const keepScroll = (render) => {
      const scroller = quick.closest(".atf-studio__controls");
      const scrolled = scroller?.scrollTop ?? 0;
      render();
      if (scroller) {
        scroller.scrollTop = scrolled;
      }
    };
    const syncControlsSoon = debounce(() => keepScroll(renderControls), 150);
    const syncQuick = () => {
      const tokens = currentTokens();
      const rows = quick.querySelectorAll(".atfs-dial");
      quickDials().forEach((dial, index) => {
        const row2 = rows[index];
        if (!row2) {
          return;
        }
        const at = dial.read(tokens);
        if (dial.kind === "colour") {
          const picker = row2.querySelector('input[type="color"]');
          const text = row2.querySelector("input.atfb-input");
          if (picker && document.activeElement !== picker) {
            picker.value = normaliseHex(at);
          }
          if (text && document.activeElement !== text) {
            text.value = at;
          }
          return;
        }
        row2.querySelectorAll(".atfs-segment").forEach((segment, step) => {
          const on = dial.steps?.[step]?.value === at;
          segment.classList.toggle("is-on", on);
          segment.setAttribute("aria-pressed", String(on));
        });
      });
    };
    const applyDial = (dial, step) => {
      const written = dial.apply(step, currentTokens());
      for (const [token, value] of Object.entries(written)) {
        overrides[token] = value;
        options.onOverride(token, value);
      }
      paintNow(written);
      syncQuick();
      syncControlsSoon();
    };
    const renderQuick = () => {
      clear(quick);
      const tokens = currentTokens();
      for (const dial of quickDials()) {
        const at = dial.read(tokens);
        let control2;
        if (dial.kind === "colour") {
          const picker = el("input", {
            class: "atfs-color",
            type: "color",
            value: normaliseHex(at),
            attrs: { "aria-label": dial.label },
            on: {
              input: (event) => applyDial(dial, event.target.value)
            }
          });
          control2 = el("div", {
            class: "atfs-color-row",
            children: [
              picker,
              textInput(at, (value) => applyDial(dial, value))
            ]
          });
        } else {
          control2 = el("div", {
            class: `atfs-segmented atfs-segmented--${dial.kind}`,
            attrs: { role: "group", "aria-label": dial.label },
            children: (dial.steps ?? []).map(
              (step) => el("button", {
                class: `atfs-segment${step.value === at ? " is-on" : ""}`,
                type: "button",
                text: step.label,
                attrs: { "aria-pressed": step.value === at },
                on: { click: () => applyDial(dial, step.value) }
              })
            )
          });
        }
        quick.append(
          el("div", {
            class: "atfs-dial",
            children: [
              el("div", {
                class: "atfs-dial__head",
                children: [
                  el("span", { class: "atfs-dial__label", text: dial.label }),
                  el("span", { class: "atfs-dial__hint", text: dial.hint })
                ]
              }),
              control2
            ]
          })
        );
      }
    };
    const renderControls = () => {
      clear(controls);
      const grouped = /* @__PURE__ */ new Map();
      for (const token of options.tokens) {
        const list = grouped.get(token.group) ?? [];
        list.push(token);
        grouped.set(token.group, list);
      }
      const groupLabels = {
        colour: "Colour",
        shape: "Corners and borders",
        shadow: "Shadows",
        fields: "Field style",
        space: "Spacing",
        type: "Type",
        labels: "Labels",
        button: "Buttons",
        focus: "Focus ring",
        motion: "Motion"
      };
      for (const [group, tokens] of grouped) {
        controls.append(
          el("details", {
            class: "atfs-group",
            attrs: { open: group === "colour" },
            children: [
              el("summary", { text: groupLabels[group] ?? group }),
              ...tokens.map((token) => renderTokenControl(token))
            ]
          })
        );
      }
    };
    const renderTokenControl = (token) => {
      const value = resolve(token);
      const change = (next) => {
        if (next === "") {
          delete overrides[token.token];
        } else {
          overrides[token.token] = next;
        }
        options.onOverride(token.token, next);
        paintNow({ [token.token]: next });
      };
      let control2;
      if (token.control === "color") {
        const picker = el("input", {
          class: "atfs-color",
          type: "color",
          value: normaliseHex(value),
          attrs: { "aria-label": `${token.label} colour` },
          on: {
            input: (event) => {
              const next = event.target.value;
              text.value = next;
              change(next);
            }
          }
        });
        const text = textInput(value, (next) => {
          picker.value = normaliseHex(next);
          change(next);
        });
        control2 = el("div", { class: "atfs-color-row", children: [picker, text] });
      } else if (token.control === "select") {
        control2 = select(
          value,
          (token.options ?? []).map((option) => ({ value: option, label: option })),
          change
        );
      } else if (token.control === "length") {
        const numeric = parseFloat(value) || 0;
        const unit = token.unit ?? "px";
        const max = token.token.includes("radius") ? 60 : 80;
        const range = el("input", {
          class: "atfs-range",
          type: "range",
          value: String(numeric),
          attrs: { min: "0", max: String(max), step: "1", "aria-label": token.label },
          on: {
            input: (event) => {
              const next = `${event.target.value}${unit}`;
              text.value = next;
              change(next);
            }
          }
        });
        const text = textInput(value, (next) => {
          range.value = String(parseFloat(next) || 0);
          change(next);
        });
        control2 = el("div", { class: "atfs-length-row", children: [range, text] });
      } else {
        control2 = textInput(value, change);
      }
      const wrapper = row(token.label, control2);
      const dial = dialOwning(token.token);
      if (dial) {
        wrapper.classList.add("is-dialled");
        wrapper.append(el("span", { class: "atfs-owned", text: dial.label }));
      }
      if (overrides[token.token] !== void 0) {
        wrapper.classList.add("is-overridden");
        wrapper.append(
          el("button", {
            class: "atfs-reset",
            type: "button",
            text: "Reset",
            attrs: { "aria-label": `Reset ${token.label} to the theme's value` },
            on: {
              click: () => {
                change("");
                keepScroll(renderControls);
              }
            }
          })
        );
      }
      return wrapper;
    };
    const saveAsTheme = async () => {
      const source = themes.find((candidate) => candidate.slug === active);
      const suggested = source ? `${source.label} (mine)` : "My theme";
      const label = window.prompt("Name this theme", suggested);
      if (!label) {
        return;
      }
      try {
        const resolved = {};
        for (const token of options.tokens) {
          const value = resolve(token);
          if (value !== token.default) {
            resolved[token.token] = value;
          }
        }
        const saved = await api.saveTheme({ label, tokens: resolved });
        themes = [...themes.filter((candidate) => candidate.slug !== saved.slug), saved];
        options.onThemesChanged(themes);
        active = saved.slug;
        replaceOverrides({});
        options.onTheme(active);
        renderThemes();
        syncQuick();
        keepScroll(renderControls);
        notify("Theme saved", saved.label);
      } catch (error) {
        notify("Could not save the theme", error instanceof Error ? error.message : "", "error");
      }
    };
    const deleteTheme = async () => {
      const theme = themes.find((candidate) => candidate.slug === active);
      if (!theme?.custom) {
        return;
      }
      if (!await confirmAction(`Delete “${theme.label}”? Forms using it fall back to Clean.`, "Delete theme")) {
        return;
      }
      try {
        await api.deleteTheme(theme.id);
        themes = themes.filter((candidate) => candidate.slug !== theme.slug);
        options.onThemesChanged(themes);
        active = "clean";
        replaceOverrides({});
        options.onTheme(active);
        renderThemes();
        syncQuick();
        keepScroll(renderControls);
        const clean = themes.find((candidate) => candidate.slug === active);
        if (clean) {
          paintTheme(clean);
        }
      } catch (error) {
        notify("Could not delete the theme", error instanceof Error ? error.message : "", "error");
      }
    };
    const exportTheme = async () => {
      const theme = themes.find((candidate) => candidate.slug === active);
      const payload = {
        label: theme?.label ?? active,
        tokens: { ...theme?.tokens ?? {}, ...overrides }
      };
      const json = JSON.stringify(payload, null, "	");
      try {
        await navigator.clipboard.writeText(json);
        notify("Theme copied", "Paste it into another site to import it.");
      } catch {
        window.prompt("Copy this theme", json);
      }
    };
    const importTheme = () => {
      const json = window.prompt("Paste a theme");
      if (!json) {
        return;
      }
      try {
        const parsed = JSON.parse(json);
        if (!parsed.tokens || typeof parsed.tokens !== "object") {
          throw new Error("That does not look like a theme.");
        }
        for (const [name, value] of Object.entries(parsed.tokens)) {
          overrides[name] = String(value);
          options.onOverride(name, String(value));
        }
        paintNow({ ...overrides });
        syncQuick();
        keepScroll(renderControls);
      } catch (error) {
        notify("Could not read that theme", error instanceof Error ? error.message : "", "error");
      }
    };
    const deleteButton = button("Delete", () => void deleteTheme(), "danger");
    const syncDeleteButton = () => {
      const theme = themes.find((candidate) => candidate.slug === active);
      deleteButton.hidden = !theme?.custom;
    };
    renderThemes();
    renderQuick();
    renderControls();
    repaint();
    syncDeleteButton();
    return el("div", {
      class: "atf-studio",
      children: [
        el("div", {
          class: "atf-studio__top",
          children: [
            // The actions share the heading's line, not the strip's.
            // Beside the strip they took the width the last chip needed
            // and cut it down the middle, which reads as a broken card
            // rather than as "there is more, scroll".
            el("div", {
              class: "atf-studio__topbar",
              children: [
                el("h2", { class: "atf-studio__heading", text: "Theme" }),
                el("div", {
                  class: "atfs__actions",
                  children: [
                    button("Save as a theme", () => void saveAsTheme(), "primary"),
                    button("Export", () => void exportTheme()),
                    button("Import", importTheme),
                    deleteButton
                  ]
                })
              ]
            }),
            swatches
          ]
        }),
        el("div", {
          class: "atf-studio__panes",
          children: [
            el("aside", {
              class: "atf-studio__controls",
              children: [
                el("p", {
                  class: "atfb-hint",
                  text: "Changes apply to this form only, until you save them as a theme."
                }),
                quick,
                // Everything, for the cases the dials above cannot
                // express. Closed by default: a list of 69 controls
                // is a reference, not a starting point — but a
                // control you cannot escape is worse than none, so
                // it is always one click away.
                el("details", {
                  class: "atfs-advanced",
                  children: [
                    el("summary", { text: "Every setting" }),
                    el("p", {
                      class: "atfb-hint",
                      text: "The full token list. The dials above write into it."
                    }),
                    controls
                  ]
                })
              ]
            }),
            el("main", {
              class: "atf-studio__preview",
              children: [el("h2", { class: "screen-reader-text", text: "Live preview" }), preview]
            })
          ]
        })
      ]
    });
  }
  async function mountThemeStudio() {
    const root = document.querySelector("[data-atfs-root]");
    if (!root || root.dataset.atfsMounted) {
      return;
    }
    root.dataset.atfsMounted = "1";
    pinWindowBodyScroll(root);
    const bar = root.querySelector("[data-atfs-bar]");
    const body = root.querySelector(".atfs__body") ?? root;
    try {
      const [config2, themes, forms] = await Promise.all([api.config(), api.listThemes(), api.listForms()]);
      if (!forms.length) {
        clear(body);
        body.append(
          el("div", {
            class: "atfb-empty",
            children: [
              el("h2", { text: "No forms to preview" }),
              el("p", { text: "Make a form first — a theme needs something to dress." })
            ]
          })
        );
        return;
      }
      let previewForm = forms[0];
      let overrides = {};
      let activeSlug = previewForm.theme;
      const render = () => {
        clear(body);
        body.append(
          mountThemeControls({
            themes,
            tokens: config2.tokens,
            activeSlug,
            overrides,
            standalone: true,
            onTheme: (slug) => {
              activeSlug = slug;
            },
            onOverride: (token, value) => {
              if (value === "") {
                delete overrides[token];
              } else {
                overrides[token] = value;
              }
            },
            onOverridesReplaced: (next) => {
              overrides = { ...next };
            },
            previewFor: async (slug, tokens) => {
              const form = await api.getForm(previewForm.id);
              form.schema.settings.theme = slug;
              form.schema.settings.themeOverrides = tokens;
              const { html } = await api.preview(previewForm.id, { schema: form.schema, theme: slug });
              return html;
            },
            onThemesChanged: (next) => {
              themes.length = 0;
              themes.push(...next);
            }
          })
        );
      };
      if (bar) {
        clear(bar);
        bar.append(
          el("span", { class: "atfs__label", text: "Preview against" }),
          select(
            String(previewForm.id),
            forms.map((form) => ({ value: String(form.id), label: form.title || "(untitled)" })),
            (value) => {
              previewForm = forms.find((form) => String(form.id) === value) ?? forms[0];
              overrides = {};
              render();
            }
          )
        );
      }
      render();
    } catch (error) {
      clear(body);
      body.append(
        el("p", { class: "atfb-error", text: error instanceof Error ? error.message : "Could not load themes." })
      );
    }
  }
  if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", () => void mountThemeStudio());
    } else {
      void mountThemeStudio();
    }
    document.addEventListener("os-window-content-loaded", () => void mountThemeStudio());
  }
  function normaliseHex(value) {
    const trimmed = value.trim();
    if (/^#[0-9a-f]{6}$/i.test(trimmed)) {
      return trimmed;
    }
    if (/^#[0-9a-f]{3}$/i.test(trimmed)) {
      return `#${trimmed[1]}${trimmed[1]}${trimmed[2]}${trimmed[2]}${trimmed[3]}${trimmed[3]}`;
    }
    return "#888888";
  }
  const SUCCESS_STYLE_ICONS = {
    plain: "",
    simple: "✓",
    minimal: "",
    card: "🎉",
    check: "",
    confetti: "🎉",
    fireworks: "🎆",
    sparkles: "✨",
    typewriter: ""
  };
  function defaultSuccessScreen() {
    return {
      style: "simple",
      title: "",
      icon: "",
      accent: "",
      intensity: "medium",
      showButton: false,
      buttonLabel: ""
    };
  }
  function normalizeSuccessScreen(raw) {
    const success = { ...defaultSuccessScreen(), ...raw ?? {} };
    if (!(success.style in SUCCESS_STYLE_ICONS)) {
      success.style = "simple";
    }
    return success;
  }
  function reducedMotion() {
    return typeof window.matchMedia === "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }
  function renderSuccessScreen(message, raw, onAgain) {
    const success = normalizeSuccessScreen(raw);
    const root = document.createElement("div");
    root.className = `atf-confirmation atf-success atf-success--${success.style}`;
    root.setAttribute("role", "status");
    root.setAttribute("tabindex", "-1");
    if (success.style === "plain") {
      root.className = "atf-confirmation";
      root.innerHTML = message;
      return root;
    }
    if (success.accent) {
      root.style.setProperty("--atf-accent", success.accent);
    }
    const inner = document.createElement("div");
    inner.className = "atf-success__inner";
    root.append(inner);
    const icon2 = success.icon || SUCCESS_STYLE_ICONS[success.style];
    if (success.style === "check") {
      inner.insertAdjacentHTML(
        "beforeend",
        '<svg class="atf-success__check" viewBox="0 0 52 52" aria-hidden="true"><circle class="atf-success__check-ring" cx="26" cy="26" r="24" fill="none" /><path class="atf-success__check-mark" fill="none" d="M14 27l8 8 16-17" /></svg>'
      );
    } else if (icon2) {
      const glyph = document.createElement("span");
      glyph.className = "atf-success__icon";
      glyph.setAttribute("aria-hidden", "true");
      glyph.textContent = icon2;
      inner.append(glyph);
    }
    if (success.title) {
      const title = document.createElement("h2");
      title.className = "atf-success__title";
      title.textContent = success.title;
      inner.append(title);
    }
    const body = document.createElement("div");
    body.className = "atf-success__message";
    body.innerHTML = message;
    inner.append(body);
    if (success.style === "typewriter") {
      root.setAttribute("aria-label", body.textContent ?? "");
    }
    if (success.showButton) {
      const again = document.createElement("button");
      again.type = "button";
      again.className = "atf-button atf-button--ghost atf-success__again";
      again.textContent = success.buttonLabel || "Fill it in again";
      again.addEventListener("click", () => onAgain ? onAgain() : window.location.reload());
      inner.append(again);
    }
    return root;
  }
  function playSuccessEffects(root, raw) {
    const success = normalizeSuccessScreen(raw);
    if (reducedMotion()) {
      return () => {
      };
    }
    switch (success.style) {
      case "confetti":
        return confetti(root, success);
      case "fireworks":
        return fireworks(success);
      case "sparkles":
        return sparkles(root, success);
      case "typewriter":
        return typewriter(root);
      default:
        return () => {
        };
    }
  }
  function scale(success) {
    return { low: 0.5, medium: 1, high: 1.8 }[success.intensity];
  }
  function makeCanvas() {
    const canvas = document.createElement("canvas");
    canvas.className = "atf-success-canvas";
    canvas.setAttribute("aria-hidden", "true");
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.floor(window.innerWidth * dpr);
    canvas.height = Math.floor(window.innerHeight * dpr);
    document.body.append(canvas);
    const ctx = canvas.getContext("2d");
    ctx?.scale(dpr, dpr);
    return { canvas, ctx, stop: () => canvas.remove() };
  }
  const CONFETTI_COLORS = ["#f43f5e", "#f59e0b", "#10b981", "#3b82f6", "#8b5cf6", "#ec4899", "#eab308"];
  function confetti(root, success) {
    const { ctx, stop } = makeCanvas();
    if (!ctx) {
      stop();
      return () => {
      };
    }
    const colors = success.accent ? [success.accent, ...CONFETTI_COLORS] : CONFETTI_COLORS;
    const pieces = [];
    const rect = root.getBoundingClientRect();
    const originX = rect.left + rect.width / 2;
    const originY = Math.min(rect.top + 40, window.innerHeight - 20);
    const make = (x, y, burst) => {
      const angle = burst ? Math.PI * (1.15 + 0.7 * Math.random()) : 0;
      const speed = burst ? 9 + Math.random() * 8 : 0;
      return {
        x,
        y,
        vx: burst ? Math.cos(angle) * speed * (Math.random() < 0.5 ? 1 : -1) : (Math.random() - 0.5) * 1.5,
        vy: burst ? Math.sin(angle) * speed : 1 + Math.random() * 2,
        w: 6 + Math.random() * 5,
        h: 8 + Math.random() * 7,
        angle: Math.random() * Math.PI,
        spin: (Math.random() - 0.5) * 0.3,
        color: colors[Math.floor(Math.random() * colors.length)],
        wobble: Math.random() * Math.PI * 2
      };
    };
    const burstCount = Math.round(90 * scale(success));
    for (let i = 0; i < burstCount; i++) {
      pieces.push(make(originX, originY, true));
    }
    const rainCount = Math.round(70 * scale(success));
    let rained = 0;
    const rain = window.setInterval(() => {
      if (rained >= rainCount) {
        window.clearInterval(rain);
        return;
      }
      pieces.push(make(Math.random() * window.innerWidth, -20, false));
      rained++;
    }, 2e3 / rainCount);
    let frame = 0;
    const started = performance.now();
    const tick = (now) => {
      ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
      let alive = false;
      for (const piece of pieces) {
        piece.vy += 0.16;
        piece.vx *= 0.99;
        piece.vy *= 0.985;
        piece.wobble += 0.1;
        piece.x += piece.vx + Math.sin(piece.wobble) * 0.8;
        piece.y += piece.vy;
        piece.angle += piece.spin;
        if (piece.y < window.innerHeight + 30) {
          alive = true;
        }
        ctx.save();
        ctx.translate(piece.x, piece.y);
        ctx.rotate(piece.angle);
        ctx.scale(1, 0.4 + 0.6 * Math.abs(Math.cos(piece.wobble)));
        ctx.fillStyle = piece.color;
        ctx.fillRect(-piece.w / 2, -piece.h / 2, piece.w, piece.h);
        ctx.restore();
      }
      if (alive && now - started < 7e3) {
        frame = window.requestAnimationFrame(tick);
      } else {
        stop();
      }
    };
    frame = window.requestAnimationFrame(tick);
    return () => {
      window.clearInterval(rain);
      window.cancelAnimationFrame(frame);
      stop();
    };
  }
  function fireworks(success) {
    const { ctx, stop } = makeCanvas();
    if (!ctx) {
      stop();
      return () => {
      };
    }
    const rockets = [];
    const sparks = [];
    const total = Math.round(6 * scale(success)) + 2;
    let launched = 0;
    const launch = () => {
      rockets.push({
        x: window.innerWidth * (0.15 + 0.7 * Math.random()),
        y: window.innerHeight,
        vy: -(9 + Math.random() * 4),
        targetY: window.innerHeight * (0.15 + 0.3 * Math.random()),
        hue: Math.floor(Math.random() * 360)
      });
      launched++;
    };
    launch();
    const launcher = window.setInterval(() => {
      if (launched >= total) {
        window.clearInterval(launcher);
        return;
      }
      launch();
    }, 3500 / total);
    const explode = (rocket) => {
      const count = Math.round(70 * scale(success));
      for (let i = 0; i < count; i++) {
        const angle = Math.PI * 2 * i / count + Math.random() * 0.1;
        const speed = 2 + Math.random() * 4.5;
        sparks.push({
          x: rocket.x,
          y: rocket.y,
          vx: Math.cos(angle) * speed,
          vy: Math.sin(angle) * speed,
          life: 1,
          decay: 0.012 + Math.random() * 0.014,
          hue: rocket.hue + Math.floor(Math.random() * 40) - 20
        });
      }
    };
    let frame = 0;
    const started = performance.now();
    const tick = (now) => {
      ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
      for (let i = rockets.length - 1; i >= 0; i--) {
        const rocket = rockets[i];
        rocket.y += rocket.vy;
        rocket.vy += 0.08;
        ctx.fillStyle = `hsl(${rocket.hue} 90% 65%)`;
        ctx.fillRect(rocket.x - 1.5, rocket.y, 3, 10);
        if (rocket.y <= rocket.targetY || rocket.vy >= -1) {
          explode(rocket);
          rockets.splice(i, 1);
        }
      }
      for (let i = sparks.length - 1; i >= 0; i--) {
        const spark = sparks[i];
        spark.x += spark.vx;
        spark.y += spark.vy;
        spark.vy += 0.045;
        spark.vx *= 0.985;
        spark.vy *= 0.985;
        spark.life -= spark.decay;
        if (spark.life <= 0) {
          sparks.splice(i, 1);
          continue;
        }
        const flicker = spark.life * (0.7 + 0.3 * Math.random());
        ctx.globalAlpha = Math.max(0, flicker);
        ctx.fillStyle = `hsl(${spark.hue} 95% ${55 + 25 * spark.life}%)`;
        ctx.beginPath();
        ctx.arc(spark.x, spark.y, 1.1 + 1.6 * spark.life, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.globalAlpha = 1;
      const done = launched >= total && rockets.length === 0 && sparks.length === 0;
      if (!done && now - started < 9e3) {
        frame = window.requestAnimationFrame(tick);
      } else {
        stop();
      }
    };
    frame = window.requestAnimationFrame(tick);
    return () => {
      window.clearInterval(launcher);
      window.cancelAnimationFrame(frame);
      stop();
    };
  }
  function sparkles(root, success) {
    const glyph = success.icon || SUCCESS_STYLE_ICONS.sparkles;
    const count = Math.round(22 * scale(success));
    const spawned = [];
    let made = 0;
    const spawn = () => {
      const spark = document.createElement("span");
      spark.className = "atf-success__spark";
      spark.setAttribute("aria-hidden", "true");
      spark.textContent = glyph;
      spark.style.insetInlineStart = `${4 + Math.random() * 92}%`;
      spark.style.animationDuration = `${2.6 + Math.random() * 1.8}s`;
      spark.style.animationDelay = `${Math.random() * 0.3}s`;
      spark.style.fontSize = `${14 + Math.random() * 14}px`;
      spark.addEventListener("animationend", () => spark.remove());
      root.append(spark);
      spawned.push(spark);
      made++;
    };
    spawn();
    const spawner = window.setInterval(() => {
      if (made >= count) {
        window.clearInterval(spawner);
        return;
      }
      spawn();
    }, 2800 / count);
    return () => {
      window.clearInterval(spawner);
      spawned.forEach((spark) => spark.remove());
    };
  }
  function typewriter(root) {
    const body = root.querySelector(".atf-success__message");
    if (!body) {
      return () => {
      };
    }
    const html = body.innerHTML;
    const text = body.textContent ?? "";
    if (!text) {
      return () => {
      };
    }
    body.textContent = "";
    body.classList.add("is-typing");
    const step = Math.min(45, 3800 / text.length);
    let at = 0;
    const typer = window.setInterval(() => {
      at++;
      body.textContent = text.slice(0, at);
      if (at >= text.length) {
        window.clearInterval(typer);
        body.classList.remove("is-typing");
        body.innerHTML = html;
      }
    }, step);
    return () => {
      window.clearInterval(typer);
      body.classList.remove("is-typing");
      body.innerHTML = html;
    };
  }
  const FUNCTIONS = {
    min: -1,
    max: -1,
    sum: -1,
    avg: -1,
    round: -1,
    ceil: 1,
    floor: 1,
    abs: 1,
    sqrt: 1,
    pow: 2
  };
  const PRECEDENCE = {
    "+": { precedence: 1, right: false },
    "-": { precedence: 1, right: false },
    "*": { precedence: 2, right: false },
    "/": { precedence: 2, right: false },
    "%": { precedence: 2, right: false },
    "^": { precedence: 4, right: true }
  };
  function calculate(formula, values, fields = []) {
    if (!formula || !formula.trim()) {
      return null;
    }
    if (formula.length > 2e3) {
      return null;
    }
    const resolved = resolveRefs(formula, values, fields);
    const tokens = tokenize(resolved);
    if (!tokens) {
      return null;
    }
    const postfix = toPostfix(tokens);
    if (!postfix) {
      return null;
    }
    const result = evalPostfix(postfix);
    return result === null || !Number.isFinite(result) ? null : result;
  }
  function resolveRefs(formula, values, fields) {
    return formula.replace(
      /\{([a-zA-Z0-9_]+)(?:\.([a-zA-Z0-9_]+))?\}/g,
      (match, fieldId, subId, offset) => refLiteral(formula, offset, match.length, fieldId, subId ?? "", values, fields)
    );
  }
  function refLiteral(formula, offset, length, fieldId, subId, values, fields) {
    const field = findField(fields, fieldId);
    const value = Object.prototype.hasOwnProperty.call(values, fieldId) ? values[fieldId] : null;
    if (subId === "") {
      const number = field?.type === "repeater" ? repeaterRows(value).length : numericValue(value, field);
      return numberLiteral(number);
    }
    const subs = field?.fields ?? [];
    const sub = subs.find((candidate) => candidate.id === subId) ?? null;
    const numbers = repeaterRows(value).map(
      (row2) => numericValue(row2[subId] ?? "", sub)
    );
    if (!numbers.length) {
      return "0";
    }
    const literals = numbers.map(numberLiteral);
    if (refSpreads(formula, offset, length)) {
      return literals.join(", ");
    }
    return literals.length === 1 ? literals[0] : `( ${literals.join(" + ")} )`;
  }
  function findField(fields, fieldId) {
    for (const field of fields) {
      if (field.id === fieldId) {
        return field;
      }
      for (const sub of field.fields ?? []) {
        if (sub.id === fieldId) {
          return sub;
        }
      }
    }
    return null;
  }
  function refSpreads(formula, offset, length) {
    const after = formula.slice(offset + length).replace(/^\s+/, "");
    if (after === "" || after[0] !== ")") {
      return false;
    }
    const before = formula.slice(0, offset).replace(/\s+$/, "");
    const match = /([a-zA-Z_][a-zA-Z0-9_]*)\s*\($/.exec(before);
    return !!match && ["sum", "avg", "min", "max"].includes(match[1].toLowerCase());
  }
  function repeaterRows(value) {
    if (!Array.isArray(value)) {
      return [];
    }
    const rows = [];
    for (const row2 of value) {
      if (!row2 || typeof row2 !== "object" || Array.isArray(row2)) {
        continue;
      }
      const filled = Object.values(row2).some(
        (item) => item !== "" && item !== null && item !== void 0 && item !== false && !(Array.isArray(item) && !item.length)
      );
      if (filled) {
        rows.push(row2);
      }
    }
    return rows;
  }
  function numberLiteral(number) {
    const literal = number.toFixed(10).replace(/0+$/, "").replace(/\.$/, "");
    return literal === "" || literal === "-" ? "0" : literal;
  }
  function numericValue(value, field) {
    if (typeof value === "boolean") {
      return value ? 1 : 0;
    }
    if (Array.isArray(value)) {
      return value.reduce((total, item) => total + numericValue(item, field), 0);
    }
    if (value === null || value === void 0 || value === "") {
      return 0;
    }
    const choices = field?.choices ?? [];
    for (const choice of choices) {
      if (String(choice.value) === String(value)) {
        if (typeof choice.price === "number") {
          return choice.price;
        }
        if (typeof choice.points === "number") {
          return choice.points;
        }
        break;
      }
    }
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  function tokenize(formula) {
    const tokens = [];
    let i = 0;
    while (i < formula.length) {
      const char = formula[i];
      if (/\s/.test(char)) {
        i++;
        continue;
      }
      if (/[0-9.]/.test(char)) {
        let number = "";
        while (i < formula.length && /[0-9.]/.test(formula[i])) {
          number += formula[i];
          i++;
        }
        const parsed = Number(number);
        if (!Number.isFinite(parsed)) {
          return null;
        }
        tokens.push({ type: "number", value: parsed });
        continue;
      }
      if (/[a-zA-Z_]/.test(char)) {
        let name = "";
        while (i < formula.length && /[a-zA-Z0-9_]/.test(formula[i])) {
          name += formula[i];
          i++;
        }
        name = name.toLowerCase();
        if (!Object.prototype.hasOwnProperty.call(FUNCTIONS, name)) {
          return null;
        }
        tokens.push({ type: "function", value: name });
        continue;
      }
      if (char === "(" || char === ")") {
        tokens.push({ type: char, value: char });
        i++;
        continue;
      }
      if (char === ",") {
        tokens.push({ type: "comma", value: "," });
        i++;
        continue;
      }
      if ("+-*/%^".includes(char)) {
        const previous = tokens[tokens.length - 1];
        const isUnary = char === "-" && (!previous || previous.type === "operator" || previous.type === "unary" || previous.type === "(" || previous.type === "comma");
        tokens.push({ type: isUnary ? "unary" : "operator", value: char });
        i++;
        continue;
      }
      return null;
    }
    return tokens;
  }
  function precedenceOf(token) {
    if (token.type === "unary") {
      return { precedence: 3, right: true };
    }
    return PRECEDENCE[token.value] ?? { precedence: 0, right: false };
  }
  function toPostfix(tokens) {
    const output = [];
    const operators = [];
    const arity = [];
    for (const token of tokens) {
      switch (token.type) {
        case "number":
          output.push(token);
          break;
        case "function":
          operators.push(token);
          arity.push(1);
          break;
        case "comma":
          while (operators.length && operators[operators.length - 1].type !== "(") {
            output.push(operators.pop());
          }
          if (!operators.length) {
            return null;
          }
          if (arity.length) {
            arity[arity.length - 1]++;
          }
          break;
        case "unary":
          operators.push(token);
          break;
        case "operator": {
          const info = PRECEDENCE[token.value];
          while (operators.length) {
            const top = operators[operators.length - 1];
            if (top.type !== "operator" && top.type !== "unary") {
              break;
            }
            const topInfo = precedenceOf(top);
            if (topInfo.precedence > info.precedence || topInfo.precedence === info.precedence && !info.right) {
              output.push(operators.pop());
              continue;
            }
            break;
          }
          operators.push(token);
          break;
        }
        case "(":
          operators.push(token);
          break;
        case ")": {
          while (operators.length && operators[operators.length - 1].type !== "(") {
            output.push(operators.pop());
          }
          if (!operators.length) {
            return null;
          }
          operators.pop();
          if (operators.length && operators[operators.length - 1].type === "function") {
            const fn = operators.pop();
            fn.arity = arity.length ? arity.pop() : 1;
            output.push(fn);
          }
          break;
        }
      }
    }
    while (operators.length) {
      const top = operators.pop();
      if (top.type === "(") {
        return null;
      }
      output.push(top);
    }
    return output;
  }
  function evalPostfix(postfix) {
    const stack = [];
    for (const token of postfix) {
      switch (token.type) {
        case "number":
          stack.push(token.value);
          break;
        case "unary": {
          if (!stack.length) {
            return null;
          }
          stack.push(-stack.pop());
          break;
        }
        case "operator": {
          if (stack.length < 2) {
            return null;
          }
          const right = stack.pop();
          const left = stack.pop();
          switch (token.value) {
            case "+":
              stack.push(left + right);
              break;
            case "-":
              stack.push(left - right);
              break;
            case "*":
              stack.push(left * right);
              break;
            case "/":
              stack.push(right === 0 ? 0 : left / right);
              break;
            case "%":
              stack.push(right === 0 ? 0 : left % right);
              break;
            case "^":
              stack.push(Math.pow(left, right));
              break;
            default:
              return null;
          }
          break;
        }
        case "function": {
          const count = token.arity ?? 1;
          if (stack.length < count) {
            return null;
          }
          const args = [];
          for (let i = 0; i < count; i++) {
            args.unshift(stack.pop());
          }
          const result = applyFunction(token.value, args);
          if (result === null) {
            return null;
          }
          stack.push(result);
          break;
        }
        default:
          return null;
      }
    }
    return stack.length === 1 ? stack[0] : null;
  }
  function applyFunction(name, args) {
    if (!args.length) {
      return null;
    }
    switch (name) {
      case "min":
        return Math.min(...args);
      case "max":
        return Math.max(...args);
      case "sum":
        return args.reduce((total, value) => total + value, 0);
      case "avg":
        return args.reduce((total, value) => total + value, 0) / args.length;
      case "round": {
        const precision = Math.max(-10, Math.min(10, Math.trunc(args[1] ?? 0)));
        const factor = Math.pow(10, precision);
        return Math.round(args[0] * factor) / factor;
      }
      case "ceil":
        return Math.ceil(args[0]);
      case "floor":
        return Math.floor(args[0]);
      case "abs":
        return Math.abs(args[0]);
      case "sqrt":
        return args[0] < 0 ? 0 : Math.sqrt(args[0]);
      case "pow":
        return Math.pow(args[0], args[1] ?? 2);
      default:
        return null;
    }
  }
  const FORMULA_FUNCTIONS = ["sum", "min", "max", "avg", "round", "ceil", "floor", "abs", "sqrt", "pow"];
  const NUMERIC_FRIENDLY = [
    "number",
    "range",
    "scale",
    "rating",
    "total",
    "select",
    "multiselect",
    "radio",
    "checkboxes",
    "switch",
    "quiz"
  ];
  function formulaTargets(fields, except) {
    return fields.filter((field) => field.id !== except && NUMERIC_FRIENDLY.includes(field.type));
  }
  function repeaterReferences(fields) {
    const references = [];
    for (const field of fields) {
      if (field.type !== "repeater") {
        continue;
      }
      const name = field.label || field.id;
      references.push({ label: `${name} (how many)`, insert: `{${field.id}}` });
      for (const sub of field.fields ?? []) {
        if (!NUMERIC_FRIENDLY.includes(sub.type)) {
          continue;
        }
        references.push({
          label: `${name} · ${sub.label || sub.id}`,
          insert: `{${field.id}.${sub.id}}`
        });
      }
    }
    return references;
  }
  function formulaSampleValues(fields, except) {
    const values = {};
    formulaTargets(fields, except).forEach((field, index) => {
      values[field.id] = index + 1;
    });
    for (const field of fields) {
      if (field.type !== "repeater" || field.id === except) {
        continue;
      }
      const subs = field.fields ?? [];
      const row2 = (bump) => Object.fromEntries(subs.map((sub, index) => [sub.id, index + 1 + bump]));
      values[field.id] = [row2(0), row2(1)];
    }
    return values;
  }
  function openFormulaEditor(options) {
    const overlay = el("div", { class: "atfb-overlay" });
    const close = () => {
      overlay.remove();
      document.removeEventListener("keydown", onKeydown);
    };
    const onKeydown = (event) => {
      if (event.key === "Escape") {
        close();
      }
    };
    const input = el("textarea", {
      class: "atfb-input atfb-formula__input",
      attrs: { rows: "3", "aria-label": "Formula" }
    });
    input.value = String(options.field.formula ?? "");
    const result = el("p", { class: "atfb-formula__result", attrs: { "aria-live": "polite" } });
    const samples = formulaSampleValues(options.fields, options.field.id);
    const preview = () => {
      const formula = input.value.trim();
      if ("" === formula) {
        result.textContent = "Empty. Reference a question below to start.";
        result.classList.remove("is-error");
        return;
      }
      const computed = calculate(formula, samples, options.fields);
      if (null === computed) {
        result.textContent = "This does not compute yet — check the braces and parentheses.";
        result.classList.add("is-error");
        return;
      }
      const sampled = formulaTargets(options.fields, options.field.id).map((field, index) => `${field.label || field.id} = ${index + 1}`).concat(
        options.fields.filter((field) => field.type === "repeater" && field.id !== options.field.id).map((field) => `${field.label || field.id} = 2 sample rows`)
      ).join(", ");
      result.textContent = `With sample answers (${sampled}): ${computed}`;
      result.classList.remove("is-error");
    };
    input.addEventListener("input", preview);
    const chip = (label, insert, caretBack = 0) => el("button", {
      class: "atfb-formula__chip",
      type: "button",
      text: label,
      on: {
        click: () => {
          insertAtCursor(input, insert);
          if (caretBack > 0) {
            const caret = (input.selectionStart ?? input.value.length) - caretBack;
            input.setSelectionRange(caret, caret);
          }
        }
      }
    });
    const targets = formulaTargets(options.fields, options.field.id);
    const repeaters = repeaterReferences(options.fields);
    const questions = el("div", {
      class: "atfb-formula__chips",
      children: targets.length || repeaters.length ? [
        ...targets.map((field) => chip(field.label || field.id, `{${field.id}}`)),
        ...repeaters.map((reference) => chip(reference.label, reference.insert))
      ] : [el("p", { class: "atfb-hint", text: "No number-shaped questions yet — add a number, scale or priced choice field and it appears here." })]
    });
    const functions = el("div", {
      class: "atfb-formula__chips",
      children: FORMULA_FUNCTIONS.map((name) => chip(`${name}()`, `${name}()`, 1))
    });
    overlay.append(
      el("div", {
        class: "atfb-modal atfb-formula",
        attrs: { role: "dialog", "aria-label": "Formula editor" },
        children: [
          el("h2", { text: "Formula" }),
          input,
          result,
          row("Your questions", questions, "Click one to reference its answer."),
          row("Functions", functions),
          el("div", {
            class: "atfb-modal__actions",
            children: [
              button("Cancel", close),
              button(
                "Save formula",
                () => {
                  options.onSave(input.value.trim());
                  close();
                },
                "primary"
              )
            ]
          })
        ]
      })
    );
    overlay.addEventListener("click", (event) => {
      if (event.target === overlay) {
        close();
      }
    });
    document.addEventListener("keydown", onKeydown);
    options.root.append(overlay);
    preview();
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
  }
  const FORM_TYPE = "allterrain-forms/form";
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
  function formIdentity(form, adminUrl) {
    return {
      type: FORM_TYPE,
      id: form.id,
      label: form.title || "Untitled form",
      related: [
        {
          id: `allterrain-forms/entries-${form.id}`,
          label: "Entries for this form",
          url: `${adminUrl}admin.php?page=allterrain-forms-entries&form=${form.id}`,
          group: "allterrain-forms",
          groupLabel: "Forms",
          icon: "dashicons-list-view"
        }
      ]
    };
  }
  const CHAR_GROUPS = {
    letters: { label: "Letters", chars: "A-Za-zÀ-ÖØ-öø-ÿ" },
    numbers: { label: "Numbers", chars: "0-9" },
    spaces: { label: "Spaces", chars: " " },
    punctuation: { label: `Punctuation ( . , ! ? ' " - )`, chars: `.,!?'"()\\-:;` },
    symbols: { label: "Symbols ( @ # & _ / + )", chars: "@#&_/+*%=" }
  };
  function emptyRecipe() {
    return {
      mode: "blocks",
      starts: "",
      ends: "",
      contains: "",
      notContains: "",
      chars: [],
      minLen: "",
      maxLen: "",
      regex: "",
      message: "",
      tests: []
    };
  }
  function parseRecipe(json) {
    const recipe = emptyRecipe();
    let raw;
    try {
      raw = JSON.parse(json);
    } catch {
      return recipe;
    }
    if (!raw || "object" !== typeof raw) {
      return recipe;
    }
    const source = raw;
    recipe.mode = "regex" === source.mode ? "regex" : "blocks";
    for (const key of ["starts", "ends", "contains", "notContains", "minLen", "maxLen", "regex", "message"]) {
      if ("string" === typeof source[key]) {
        recipe[key] = source[key];
      }
    }
    if (Array.isArray(source.chars)) {
      recipe.chars = source.chars.filter((item) => "string" === typeof item && item in CHAR_GROUPS);
    }
    if (Array.isArray(source.tests)) {
      recipe.tests = source.tests.filter((item) => "string" === typeof item).slice(0, 10);
    }
    return recipe;
  }
  function escapeRegex(text) {
    return text.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&");
  }
  function compileRecipe(recipe) {
    if ("regex" === recipe.mode) {
      return recipe.regex.trim();
    }
    const parts = [];
    const min = recipe.minLen.trim();
    const max = recipe.maxLen.trim();
    if ("" !== min || "" !== max) {
      parts.push(`(?=.{${"" === min ? "0" : parseInt(min, 10) || 0},${"" === max ? "" : parseInt(max, 10) || ""}}$)`);
    }
    if ("" !== recipe.contains) {
      parts.push(`(?=.*${escapeRegex(recipe.contains)})`);
    }
    if ("" !== recipe.notContains) {
      parts.push(`(?!.*${escapeRegex(recipe.notContains)})`);
    }
    if ("" !== recipe.starts) {
      parts.push(`(?=${escapeRegex(recipe.starts)})`);
    }
    if ("" !== recipe.ends) {
      parts.push(`(?=.*${escapeRegex(recipe.ends)}$)`);
    }
    const charClass = recipe.chars.map((key) => CHAR_GROUPS[key]?.chars ?? "").join("");
    const body = charClass ? `[${charClass}]*$` : ".*$";
    if (!parts.length && !charClass) {
      return "";
    }
    return `^${parts.join("")}${body}`;
  }
  function describeRecipe(recipe) {
    if ("regex" === recipe.mode) {
      return recipe.regex.trim() ? `Matches the expression ${recipe.regex.trim()}` : "";
    }
    const phrases = [];
    if (recipe.starts) {
      phrases.push(`starts with “${recipe.starts}”`);
    }
    if (recipe.ends) {
      phrases.push(`ends with “${recipe.ends}”`);
    }
    if (recipe.contains) {
      phrases.push(`contains “${recipe.contains}”`);
    }
    if (recipe.notContains) {
      phrases.push(`never contains “${recipe.notContains}”`);
    }
    if (recipe.chars.length) {
      const names = recipe.chars.map(
        (key) => (CHAR_GROUPS[key]?.label ?? key).split(" (")[0].toLowerCase()
      );
      phrases.push(`uses only ${names.join(" and ")}`);
    }
    const min = recipe.minLen.trim();
    const max = recipe.maxLen.trim();
    if (min && max) {
      phrases.push(`is ${min}–${max} characters long`);
    } else if (min) {
      phrases.push(`is at least ${min} characters long`);
    } else if (max) {
      phrases.push(`is at most ${max} characters long`);
    }
    if (!phrases.length) {
      return "";
    }
    const sentence = phrases.length > 1 ? `${phrases.slice(0, -1).join(", ")}, and ${phrases[phrases.length - 1]}` : phrases[0];
    return `The answer ${sentence}.`;
  }
  function recipePasses(recipe, value) {
    const pattern = compileRecipe(recipe);
    if ("" === pattern) {
      return null;
    }
    try {
      return new RegExp(pattern).test(value);
    } catch {
      return null;
    }
  }
  function shellWindows() {
    return window.wp?.os?.windowManager ?? null;
  }
  const EDITOR_WINDOW_BASE = "allterrain-forms-validation";
  function openValidationEditor(options) {
    const manager = shellWindows();
    const parentId = windowIdOf(options.root);
    if (manager?.openChild && parentId) {
      openAsChildWindow(manager, parentId, options);
      return;
    }
    openAsOverlay(options);
  }
  function openAsChildWindow(manager, parentId, options) {
    const id = `${EDITOR_WINDOW_BASE}-${options.field.id}`;
    if (manager.getById?.(id)) {
      manager.remove?.(id);
    }
    const state = { saved: false };
    const close = () => {
      const win = manager.getById?.(id);
      if (win?.close) {
        win.close();
      } else {
        manager.remove?.(id);
      }
    };
    void manager.openChild?.(parentId, {
      id,
      baseId: EDITOR_WINDOW_BASE,
      url: `#${id}`,
      title: `Custom rule — ${options.field.label || "this question"}`,
      icon: "dashicons-yes-alt",
      native: true,
      width: 560,
      height: 700,
      minWidth: 440,
      minHeight: 480,
      // A rule mid-edit is not worth resurrecting against a field that may
      // be gone; children are excluded from snapshots anyway, this says so.
      ephemeral: true,
      autofocus: ".atfb-valwin__pane:not([hidden]) input, .atfb-valwin__pane:not([hidden]) textarea",
      render: (body) => {
        const host = el("div", { class: "atfa atfb-valwin atfb-valwin--window" });
        const scroll = el("div", { class: "atfb-valwin__scroll" });
        host.append(scroll);
        buildEditor(scroll, options, state, close, "window");
        const actions = scroll.querySelector(".atfb-modal__actions");
        if (actions) {
          host.append(actions);
        }
        body.append(host);
      },
      // Fires however the window closes — Save, Cancel, the title-bar X,
      // or its owner closing. One place decides whether that was a cancel.
      onClose: () => {
        if (!state.saved) {
          options.onCancel?.();
        }
      }
    });
  }
  function openAsOverlay(options) {
    const overlay = el("div", { class: "atfb-overlay" });
    const state = { saved: false };
    const close = () => {
      overlay.remove();
      document.removeEventListener("keydown", onKeydown);
      if (!state.saved) {
        options.onCancel?.();
      }
    };
    const onKeydown = (event) => {
      if ("Escape" === event.key) {
        close();
      }
    };
    const modal = el("div", {
      class: "atfb-modal atfb-valwin",
      attrs: { role: "dialog", "aria-label": "Custom validation rule" },
      children: [el("h2", { text: "Custom rule" })]
    });
    buildEditor(modal, options, state, close, "overlay");
    overlay.append(modal);
    overlay.addEventListener("click", (event) => {
      if (event.target === overlay) {
        close();
      }
    });
    document.addEventListener("keydown", onKeydown);
    options.root.append(overlay);
    overlay.querySelector(
      ".atfb-valwin__pane:not([hidden]) input, .atfb-valwin__pane:not([hidden]) textarea"
    )?.focus();
  }
  function buildEditor(host, options, state, requestClose, chrome) {
    const recipe = parseRecipe(String(options.field.validationRecipe ?? ""));
    if ("blocks" === recipe.mode && "" === compileRecipe(recipe) && options.field.pattern) {
      recipe.mode = "regex";
      recipe.regex = String(options.field.pattern);
    }
    if (!recipe.message) {
      recipe.message = String(options.field.messages?.invalid ?? "");
    }
    const blockInput = (key, placeholder) => {
      const input = el("input", {
        class: "atfb-input",
        value: recipe[key],
        placeholder,
        attrs: { type: "text" }
      });
      input.addEventListener("input", () => {
        recipe[key] = input.value;
        refresh();
      });
      return input;
    };
    const lengthInput = (key, label) => {
      const input = el("input", {
        class: "atfb-input atfb-valwin__len",
        value: recipe[key],
        attrs: { type: "number", min: "0", "aria-label": label }
      });
      input.addEventListener("input", () => {
        recipe[key] = input.value;
        refresh();
      });
      return input;
    };
    const charBoxes = el("div", {
      class: "atfb-valwin__chars",
      children: Object.entries(CHAR_GROUPS).map(
        ([key, group]) => checkbox(group.label, recipe.chars.includes(key), (checked) => {
          recipe.chars = checked ? [...recipe.chars, key] : recipe.chars.filter((item) => item !== key);
          refresh();
        })
      )
    });
    const blocksPane = el("div", {
      class: "atfb-valwin__pane",
      children: [
        row("Starts with", blockInput("starts", "e.g. AT-")),
        row("Ends with", blockInput("ends", "e.g. -2026")),
        row("Must contain", blockInput("contains", "e.g. @")),
        row("Must not contain", blockInput("notContains", "e.g. spaces? type one")),
        row("Only these characters", charBoxes, "Leave every box unticked to allow anything."),
        row(
          "Length",
          el("div", {
            class: "atfb-valwin__lengths",
            children: [
              el("span", { text: "between" }),
              lengthInput("minLen", "Minimum length"),
              el("span", { text: "and" }),
              lengthInput("maxLen", "Maximum length"),
              el("span", { text: "characters" })
            ]
          }),
          "Leave a box empty for no limit."
        )
      ]
    });
    const regexInput = el("textarea", {
      class: "atfb-input atfb-valwin__regex",
      attrs: { rows: "2", "aria-label": "Regular expression", placeholder: "^AT-[0-9]{4}$" }
    });
    regexInput.value = recipe.regex;
    regexInput.addEventListener("input", () => {
      recipe.regex = regexInput.value;
      refresh();
    });
    const regexPane = el("div", {
      class: "atfb-valwin__pane",
      children: [
        row(
          "Expression",
          regexInput,
          "A regular expression, without slashes. Checked against the whole answer only if you anchor it with ^ and $."
        )
      ]
    });
    const MODES = [
      ["blocks", "Easy blocks"],
      ["regex", "Expression (advanced)"]
    ];
    const setMode = (mode) => {
      recipe.mode = mode;
      blocksPane.hidden = "blocks" !== mode;
      regexPane.hidden = "regex" !== mode;
      refresh();
    };
    const buildTabs = () => {
      if (hasComponent("os-segmented") && hasComponent("os-segment")) {
        const host2 = document.createElement("os-segmented");
        host2.setAttribute("value", recipe.mode);
        host2.setAttribute("label", "How to write the rule");
        host2.classList.add("atfb-valwin__tabs");
        for (const [mode, label] of MODES) {
          const segment = document.createElement("os-segment");
          segment.setAttribute("value", mode);
          segment.textContent = label;
          host2.append(segment);
        }
        host2.addEventListener("os-pick", (event) => {
          const mode = event.detail?.value;
          if ("blocks" === mode || "regex" === mode) {
            host2.setAttribute("value", mode);
            setMode(mode);
          }
        });
        return host2;
      }
      const list = el("div", { class: "atfb-valwin__tabs", attrs: { role: "tablist" } });
      const paint = () => {
        list.replaceChildren(
          ...MODES.map(([mode, label]) => {
            const active = recipe.mode === mode;
            return el("button", {
              class: `atfb-valwin__tab${active ? " is-active" : ""}`,
              type: "button",
              text: label,
              attrs: { role: "tab", "aria-selected": active ? "true" : "false" },
              on: {
                click: () => {
                  setMode(mode);
                  paint();
                }
              }
            });
          })
        );
      };
      paint();
      return list;
    };
    const tabs = buildTabs();
    blocksPane.hidden = "blocks" !== recipe.mode;
    regexPane.hidden = "regex" !== recipe.mode;
    const messageInput = el("input", {
      class: "atfb-input",
      value: recipe.message,
      placeholder: "That is not in the expected format.",
      attrs: { type: "text" }
    });
    messageInput.addEventListener("input", () => {
      recipe.message = messageInput.value;
    });
    const summary = el("p", { class: "atfb-valwin__summary", attrs: { "aria-live": "polite" } });
    const samples = el("div", { class: "atfb-valwin__samples" });
    const sampleRow = (initial) => {
      const verdict = el("span", { class: "atfb-valwin__verdict", attrs: { "aria-live": "polite" } });
      const input = el("input", {
        class: "atfb-input",
        value: initial,
        placeholder: "Type a sample answer…",
        attrs: { type: "text" }
      });
      input.addEventListener("input", () => refresh());
      samples.append(el("div", { class: "atfb-valwin__sample", children: [input, verdict] }));
    };
    for (const test of recipe.tests.length ? recipe.tests : ["", "", ""]) {
      sampleRow(test);
    }
    const readSamples = () => Array.from(samples.querySelectorAll("input")).map((input) => input.value);
    const refresh = () => {
      const description = describeRecipe(recipe);
      summary.textContent = description || "Nothing yet — fill in a block above and the rule appears here in plain words.";
      for (const sample of Array.from(samples.querySelectorAll(".atfb-valwin__sample"))) {
        const input = sample.querySelector("input");
        const verdict = sample.querySelector(".atfb-valwin__verdict");
        if (!input || !verdict) {
          continue;
        }
        const result = "" === input.value ? null : recipePasses(recipe, input.value);
        verdict.textContent = null === result ? "·" : result ? "✓ passes" : "✗ fails";
        verdict.classList.toggle("is-pass", true === result);
        verdict.classList.toggle("is-fail", false === result);
      }
    };
    host.append(
      el("p", {
        class: "atfb-hint",
        text: `Describe what a good answer to “${options.field.label || "this question"}” looks like — no code needed.`
      }),
      tabs,
      blocksPane,
      regexPane,
      el("div", {
        class: "atfb-valwin__try",
        children: [
          el("h3", { text: "Try it out" }),
          summary,
          samples,
          button(
            "Add another sample",
            () => {
              sampleRow("");
              refresh();
            },
            "ghost",
            "plus-alt2"
          )
        ]
      }),
      row(
        "When it fails, say",
        messageInput,
        "Shown to the visitor when their answer breaks the rule. Leave empty for the default wording."
      ),
      el("div", {
        class: "atfb-modal__actions",
        children: [
          button("window" === chrome ? "Close without saving" : "Cancel", requestClose),
          button(
            "Save rule",
            () => {
              const pattern = compileRecipe(recipe);
              recipe.tests = readSamples().filter((value) => "" !== value).slice(0, 10);
              state.saved = true;
              options.onSave({ pattern, recipe, message: recipe.message.trim() });
              requestClose();
            },
            "primary"
          )
        ]
      })
    );
    refresh();
  }
  const VALIDATION_GROUPS = ["Contact", "Numbers & codes", "Text shape", "Web"];
  const VALIDATION_PRESETS = [
    {
      slug: "email",
      label: "An email address",
      group: "Contact",
      example: "jane@example.com",
      pattern: "^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$",
      message: "That does not look like an email address."
    },
    {
      slug: "phone",
      label: "A phone number",
      group: "Contact",
      example: "+34 612 345 678",
      pattern: "^(?=(?:[^0-9]*[0-9]){5,})\\+?[0-9 ().-]{5,24}$",
      message: "That does not look like a phone number."
    },
    {
      slug: "handle",
      label: "A username or @handle",
      group: "Contact",
      example: "@yourname",
      pattern: "^@?[A-Za-z0-9_]{2,30}$",
      message: "That does not look like a username."
    },
    {
      slug: "digits",
      label: "Numbers only",
      group: "Numbers & codes",
      example: "12345",
      pattern: "^[0-9]+$",
      message: "Numbers only, please."
    },
    {
      slug: "decimal",
      label: "A number, decimals allowed",
      group: "Numbers & codes",
      example: "3.14",
      pattern: "^-?[0-9]+([.,][0-9]+)?$",
      message: "That does not look like a number."
    },
    {
      slug: "price",
      label: "A price",
      group: "Numbers & codes",
      example: "19.99",
      pattern: "^[0-9]+([.,][0-9]{1,2})?$",
      message: "That does not look like a price."
    },
    {
      slug: "zip_us",
      label: "A ZIP code (US)",
      group: "Numbers & codes",
      example: "90210",
      pattern: "^[0-9]{5}(-[0-9]{4})?$",
      message: "That does not look like a ZIP code."
    },
    {
      slug: "postcode_uk",
      label: "A postcode (UK)",
      group: "Numbers & codes",
      example: "SW1A 1AA",
      pattern: "^[A-Za-z]{1,2}[0-9][A-Za-z0-9]? ?[0-9][A-Za-z]{2}$",
      message: "That does not look like a postcode."
    },
    {
      slug: "iban",
      label: "An IBAN",
      group: "Numbers & codes",
      example: "DE89 3704 0044 0532 0130 00",
      pattern: "^[A-Za-z]{2}[0-9]{2}(?: ?[A-Za-z0-9]){10,32}$",
      message: "That does not look like an IBAN."
    },
    {
      slug: "credit_card",
      label: "A card number",
      group: "Numbers & codes",
      example: "4242 4242 4242 4242",
      pattern: "^[0-9](?:[0-9 -]{9,21})?[0-9]$",
      message: "That does not look like a card number.",
      luhn: true
    },
    {
      slug: "letters",
      label: "Letters only",
      group: "Text shape",
      example: "María López",
      pattern: "^[\\p{L}\\p{M} .'’-]+$",
      message: "Letters only, please."
    },
    {
      slug: "alphanumeric",
      label: "Letters and numbers only",
      group: "Text shape",
      example: "abc123",
      pattern: "^[A-Za-z0-9]+$",
      message: "Letters and numbers only, please."
    },
    {
      slug: "no_spaces",
      label: "One word, no spaces",
      group: "Text shape",
      example: "one-word",
      pattern: "^\\S+$",
      message: "No spaces allowed."
    },
    {
      slug: "url",
      label: "A web address",
      group: "Web",
      example: "https://example.com",
      pattern: "^(https?://)?([A-Za-z0-9-]+\\.)+[A-Za-z]{2,}([/?#]\\S*)?$",
      message: "That does not look like a web address."
    },
    {
      slug: "ip",
      label: "An IP address",
      group: "Web",
      example: "192.168.0.1",
      pattern: "^((25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\\.){3}(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])$",
      message: "That does not look like an IP address."
    },
    {
      slug: "slug",
      label: "A URL slug",
      group: "Web",
      example: "my-page-title",
      pattern: "^[a-z0-9]+(-[a-z0-9]+)*$",
      message: "Lowercase letters, numbers and dashes only."
    },
    {
      slug: "hex_color",
      label: "A hex colour",
      group: "Web",
      example: "#3366ff",
      pattern: "^#?([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$",
      message: "That does not look like a colour code."
    }
  ];
  function validationPreset(slug) {
    return VALIDATION_PRESETS.find((preset) => preset.slug === slug) ?? null;
  }
  function shell() {
    return window.wp?.os ?? null;
  }
  const BUTTON_ID = "allterrain-forms/preview";
  const PREVIEW_WINDOW_ID = "allterrain-forms-preview";
  function registerPreviewButton(source) {
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
          onClick: () => void openPreview(source),
          owner: "allterrain-forms-builder"
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
  function refreshPreview(formId, title, url) {
    const os = shell();
    if (!os?.windowManager?.open) {
      return;
    }
    const open = document.querySelector(`[data-window-id="${PREVIEW_WINDOW_ID}-${formId}"]`);
    if (!open) {
      return;
    }
    const separator = url.includes("?") ? "&" : "?";
    openPreviewWindow(formId, title, `${url}${separator}atf_r=${Date.now()}`);
  }
  const FIELD_PAYLOAD_TYPE = "allterrain-forms/field";
  const MEDIA_PAYLOAD_TYPES = ["openstation/file", "desktop-mode/file", "openstation/attachment"];
  const LOGIC_MAP_SETTING = "allterrain-forms/logic-map-v2";
  function bind(control2, key) {
    control2.dataset.atfbBind = key;
    return control2;
  }
  const SETTING_CONTROLS = {
    level: {
      key: "level",
      label: "Heading level",
      control: "select",
      hint: "Headings should step down one at a time.",
      options: [
        { value: "2", label: "Heading 2" },
        { value: "3", label: "Heading 3" },
        { value: "4", label: "Heading 4" },
        { value: "5", label: "Heading 5" },
        { value: "6", label: "Heading 6" }
      ]
    },
    content: {
      key: "content",
      label: "HTML",
      control: "textarea",
      hint: "Shown as written. Scripts are stripped when the form is saved."
    },
    consenttext: {
      key: "consentText",
      label: "What they are agreeing to",
      control: "textarea",
      hint: "Shown beside the tick box. Links are allowed."
    },
    height: {
      key: "height",
      label: "Height",
      control: "number",
      hint: "In pixels."
    },
    columns: {
      key: "columns",
      label: "Columns",
      control: "number",
      hint: "How many pictures sit side by side."
    },
    multiple: {
      key: "multiple",
      label: "Let them choose more than one",
      control: "checkbox"
    },
    inline: {
      key: "inline",
      label: "Lay the options out in a row",
      control: "checkbox"
    },
    other: {
      key: "other",
      label: "Offer an “Other” box",
      control: "checkbox",
      hint: "Adds a final option with a box to type in."
    },
    filetypes: {
      key: "filetypes",
      label: "Accepted file types",
      control: "commas",
      hint: "Extensions, separated by commas. Empty accepts anything the site allows."
    },
    maxsize: {
      key: "maxsize",
      label: "Largest file",
      control: "number",
      hint: "In megabytes."
    },
    maxfiles: {
      key: "maxfiles",
      label: "How many files",
      control: "number"
    },
    minrows: {
      key: "minRows",
      label: "Fewest rows",
      control: "number",
      also: { key: "maxRows", label: "Most rows" }
    },
    itemlabel: {
      key: "itemLabel",
      label: "What is one row called?",
      control: "text",
      hint: "Names each card — “Attendee 1”, “Attendee 2” — and the Remove button. Also editable on the card itself."
    },
    endlabels: {
      key: "minLabel",
      label: "Label at the low end",
      control: "text",
      also: { key: "maxLabel", label: "Label at the high end" }
    },
    points: {
      key: "points",
      label: "Points if correct",
      control: "number"
    },
    minchoices: {
      key: "minChoices",
      label: "Fewest they may pick",
      control: "number",
      also: { key: "maxChoices", label: "Most they may pick" }
    }
  };
  const SETTINGS_HANDLED_ELSEWHERE = {
    label: "the canvas edits it in place, and the inspector mirrors it",
    placeholder: "edited by typing into the control on the canvas",
    hint: "edited under the control on the canvas",
    nextlabel: "edited on the page break’s own Next button",
    prevlabel: "edited on the page break’s own Back button",
    addlabel: "edited on the repeater’s own Add button",
    choices: "the choices editor, and the option list on the canvas",
    required: "the toggle on the card",
    width: "its own row",
    css: "its own row",
    prefill: "its own section",
    logic: "the conditional logic section",
    formula: "its own row, with the currency that goes with it",
    currency: "rendered with the formula",
    correct: "the choices editor marks the right answer",
    default: "its own row, typed to match what the field stores",
    rows: "a row count on a textarea; the statement list on a Likert matrix",
    parts: "a tick box per part, listed by the server",
    min: "the validation section, paired with max",
    max: "the validation section, paired with min",
    step: "the validation section",
    minlength: "the validation section, paired with maxlength",
    maxlength: "the validation section, paired with minlength",
    mindate: "the validation section, paired with maxdate",
    maxdate: "the validation section, paired with mindate",
    mintime: "the validation section, paired with maxtime",
    maxtime: "the validation section, paired with mintime",
    pattern: "the validation section",
    unique: "the validation section",
    maxchoices: "rendered with minchoices",
    maxrows: "rendered with minrows"
  };
  function settingRow(field, setting, update) {
    const raw = field[setting.key];
    const write = (value) => update(setting.key, value);
    if ("checkbox" === setting.control) {
      return checkbox(setting.label, Boolean(raw), write);
    }
    if ("commas" === setting.control) {
      const list = Array.isArray(raw) ? raw : [];
      return row(
        setting.label,
        textInput(list.join(", "), (value) => {
          write(
            value.split(",").map((item) => item.trim().replace(/^\./, "").toLowerCase()).filter(Boolean)
          );
        }),
        setting.hint
      );
    }
    if ("select" === setting.control) {
      return row(setting.label, select(String(raw ?? ""), setting.options ?? [], write), setting.hint);
    }
    if ("textarea" === setting.control) {
      return row(setting.label, textArea(String(raw ?? ""), write), setting.hint);
    }
    if ("number" === setting.control) {
      return row(setting.label, numberInput(String(raw ?? ""), write), setting.hint);
    }
    return row(setting.label, bind(textInput(String(raw ?? ""), write), setting.key), setting.hint);
  }
  function restatement(rows, text) {
    const used = new Set(rows.map((statement) => statement.key).filter(Boolean));
    let next = rows.length + 1;
    return text.split("\n").map((line) => line.trim()).filter(Boolean).map((label, index) => {
      const existing = rows[index]?.key;
      if (existing) {
        return { key: existing, label };
      }
      while (used.has(`r${next}`)) {
        next += 1;
      }
      used.add(`r${next}`);
      return { key: `r${next}`, label };
    });
  }
  function fieldMove(fields, fieldId, index) {
    const from = fields.findIndex((field) => field.id === fieldId);
    if (from < 0) {
      return null;
    }
    const to = Math.max(0, Math.min(index, fields.length - 1));
    return to === from ? null : { from, to };
  }
  const PREFILL_SOURCES = [
    { value: "user:email", label: "Their email address", group: "About the person filling it in", tag: "{user:email}" },
    { value: "user:display_name", label: "Their name", group: "About the person filling it in", tag: "{user:display_name}" },
    { value: "user:first_name", label: "Their first name", group: "About the person filling it in" },
    { value: "user:last_name", label: "Their last name", group: "About the person filling it in" },
    { value: "user:login", label: "Their username", group: "About the person filling it in" },
    { value: "date:today", label: "Today’s date", group: "The date and time", tag: "{date}" },
    { value: "date:now", label: "The time right now", group: "The date and time", tag: "{time}" },
    { value: "site", label: "This site’s name", group: "About this site", tag: "{site}" },
    { value: "site:url", label: "This site’s address", group: "About this site", tag: "{site:url}" },
    { value: "site:admin_email", label: "The site administrator’s email", group: "About this site", tag: "{admin_email}" }
  ];
  const PREFILL_GROUPS = ["About the person filling it in", "The date and time", "About this site"];
  const i18n = (key, fallback2) => config?.i18n?.[key] ?? fallback2;
  class Builder {
    constructor(root) {
      this.config = null;
      this.themes = [];
      this.forms = [];
      this.form = null;
      this.schema = null;
      this.selected = null;
      this.tab = "build";
      this.logicMap = null;
      this.canvasTheme = el("style");
      this.canvasThemeSignature = "";
      this.openSections = /* @__PURE__ */ new Map();
      this.logicMapOn = "off" !== readSetting(LOGIC_MAP_SETTING);
      this.dirty = false;
      this.editGeneration = 0;
      this.saveInFlight = false;
      this.queuedSave = null;
      this.teardowns = [];
      this.canvasTarget = null;
      this.history = [];
      this.historyAt = -1;
      this.autosave = debounce(() => {
        void this.save(true);
      }, 2500);
      this.renderInspector = raf(() => {
        clear(this.inspector);
        if (!this.schema) {
          return;
        }
        this.root.classList.toggle("atfb--build-only-panes", this.tab !== "build");
        if (this.tab !== "build") {
          return;
        }
        const located = this.selected ? this.locateField(this.selected) : void 0;
        const field = located?.field;
        const parent = located?.parent ?? null;
        this.root.classList.toggle("atfb--has-selection", !!field);
        if (!field) {
          this.inspector.append(
            el("div", {
              class: "atfb-placeholder",
              children: [
                el("p", { text: "Select a field to change it." }),
                el("p", {
                  class: "atfb-hint",
                  text: "Drag a field from the palette, or press one to add it to the end."
                })
              ]
            })
          );
          return;
        }
        const definition = this.config?.fieldTypes.find((candidate) => candidate.type === field.type);
        const supports = definition?.supports ?? [];
        const update = (key, value) => {
          const live = this.liveField(field.id);
          if (!live) {
            return;
          }
          live[key] = value;
          this.markDirty();
          this.renderCanvas();
        };
        this.inspector.append(el("h3", { class: "atfb-inspector__title", text: definition?.label ?? field.type }));
        if (parent) {
          this.inspector.append(
            el("p", {
              class: "atfb-hint atfb-inspector__crumb",
              text: `Inside ${parent.label || "a repeater"} — the visitor answers this once per ${String(parent.itemLabel ?? "") || "row"}.`
            }),
            el("p", {
              class: "atfb-hint",
              text: `Formulas aggregate it as {${parent.id}.${field.id}} — e.g. sum( {${parent.id}.${field.id}} ).`
            })
          );
        } else {
          this.inspector.append(
            el("p", { class: "atfb-hint", text: `Reference this field as {field:${field.id}}` })
          );
        }
        if (supports.includes("label")) {
          this.inspector.append(
            row(
              "Label",
              bind(textInput(field.label, (value) => update("label", value)), "label")
            )
          );
        }
        if (supports.includes("placeholder")) {
          this.inspector.append(
            row(
              "Placeholder",
              bind(textInput(field.placeholder, (value) => update("placeholder", value)), "placeholder")
            )
          );
        }
        if (supports.includes("hint")) {
          this.inspector.append(
            row(
              "Hint",
              bind(textInput(field.hint, (value) => update("hint", value)), "hint"),
              "Shown under the field, and read out with it."
            )
          );
        }
        if (supports.includes("nextlabel")) {
          this.inspector.append(
            row(
              "Next button",
              bind(
                textInput(String(field.nextLabel ?? ""), (value) => update("nextLabel", value)),
                "nextLabel"
              ),
              "Leave empty for “Next”."
            )
          );
        }
        if (supports.includes("prevlabel")) {
          this.inspector.append(
            row(
              "Back button",
              bind(
                textInput(String(field.prevLabel ?? ""), (value) => update("prevLabel", value)),
                "prevLabel"
              ),
              "Leave empty for “Back”."
            )
          );
        }
        if (supports.includes("addlabel")) {
          this.inspector.append(
            row(
              "Add button",
              bind(
                textInput(String(field.addLabel ?? ""), (value) => update("addLabel", value)),
                "addLabel"
              ),
              "Leave empty for “Add another”."
            )
          );
        }
        if (supports.includes("required")) {
          this.inspector.append(
            checkbox("Required", field.required, (value) => update("required", value))
          );
        }
        if (supports.includes("width")) {
          this.inspector.append(
            row(
              "Width",
              select(
                field.width,
                [
                  { value: "full", label: "Full width" },
                  { value: "two-thirds", label: "Two thirds" },
                  { value: "half", label: "Half" },
                  { value: "third", label: "One third" },
                  { value: "quarter", label: "One quarter" }
                ],
                (value) => update("width", value)
              )
            )
          );
        }
        if (definition?.choices) {
          this.inspector.append(this.renderChoicesEditor(field, update));
        }
        this.renderTypeSettings(field, definition, supports, update);
        if (field.type === "total" || supports.includes("formula")) {
          this.inspector.append(
            row(
              "Formula",
              el("div", {
                class: "atfb-formula__row",
                children: [
                  textInput(String(field.formula ?? ""), (value) => update("formula", value)),
                  // The editor is where the formula is meant to be
                  // written: the questions and the functions are
                  // buttons there, and the result computes live
                  // against sample answers. The bare box stays for
                  // somebody pasting one in.
                  button(
                    "Formula editor",
                    () => openFormulaEditor({
                      root: this.root,
                      fields: this.schema?.fields ?? [],
                      field,
                      onSave: (formula) => {
                        update("formula", formula);
                        this.renderInspector();
                      }
                    })
                  )
                ]
              }),
              "Reference answers with braces — {f1} * {f2} + 10 — or open the editor and click them in."
            ),
            row("Currency symbol", textInput(String(field.currency ?? ""), (value) => update("currency", value)))
          );
        }
        this.inspector.append(this.renderValidationSection(field, supports, update));
        if (!parent) {
          this.inspector.append(this.renderLogicSection(field));
          if (supports.includes("prefill")) {
            this.inspector.append(this.prefillControl(field, update));
          }
        }
        if (supports.includes("css")) {
          this.inspector.append(
            row("CSS class", textInput(field.cssClass, (value) => update("cssClass", value)))
          );
        }
      });
      this.root = root;
      this.bar = root.querySelector("[data-atfb-bar]") ?? el("div");
      this.palette = root.querySelector("[data-atfb-palette]") ?? el("div");
      this.canvas = root.querySelector("[data-atfb-canvas]") ?? el("div");
      this.inspector = root.querySelector("[data-atfb-inspector]") ?? el("div");
      this.canvas.addEventListener("click", (event) => {
        const target = event.target;
        if (this.tab !== "build" || !this.selected || target.closest("[data-atfb-card], button, os-button, a, input, textarea, select, os-select, label, [contenteditable]")) {
          return;
        }
        this.selected = null;
        for (const card of this.canvas.querySelectorAll(".atfb-card.is-selected")) {
          card.classList.remove("is-selected");
        }
        this.renderInspector();
      });
    }
    /** Loads everything and paints. */
    async start() {
      try {
        const [config2, themes, forms] = await Promise.all([api.config(), api.listThemes(), api.listForms()]);
        this.config = config2;
        this.themes = themes;
        this.forms = forms;
      } catch (error) {
        this.fail(error);
        return;
      }
      this.teardowns.push(watchShellDragVisuals([FIELD_PAYLOAD_TYPE]));
      this.teardowns.push(
        registerPreviewButton({
          current: () => this.form ? { id: this.form.id, title: this.form.title, previewUrl: this.form.previewUrl } : null,
          isDirty: () => this.dirty,
          save: () => this.save(true)
        })
      );
      const beforeUnload = (event) => {
        if (this.dirty) {
          event.preventDefault();
          event.returnValue = "";
        }
      };
      window.addEventListener("beforeunload", beforeUnload);
      this.teardowns.push(() => window.removeEventListener("beforeunload", beforeUnload));
      const onKey = (event) => {
        if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== "z") {
          return;
        }
        const target = event.target;
        if (target?.closest("input, textarea, [contenteditable]")) {
          return;
        }
        event.preventDefault();
        this.travel(event.shiftKey ? 1 : -1);
      };
      this.root.addEventListener("keydown", onKey);
      this.teardowns.push(() => this.root.removeEventListener("keydown", onKey));
      this.renderBar();
      this.renderPalette();
      if (this.forms.length) {
        const requested = takeFormFor("builder");
        await this.open(
          requested && this.forms.some((form) => form.id === requested) ? requested : this.forms[0].id
        );
      } else {
        this.renderFormsList();
      }
    }
    /** Releases every listener this instance registered. */
    destroy() {
      this.canvasTarget?.();
      this.canvasTarget = null;
      this.teardowns.forEach((teardown) => teardown());
      this.teardowns = [];
    }
    /** Shows a load failure rather than an empty window. */
    fail(error) {
      clear(this.bar);
      this.bar.append(
        el("p", {
          class: "atfb-error",
          text: error instanceof Error ? error.message : "Something went wrong loading your forms."
        })
      );
    }
    /* ------------------------------------------------------------- Toolbar */
    /** The top bar: form picker, tabs, save. */
    renderBar() {
      clear(this.bar);
      const picker = select(
        String(this.form?.id ?? ""),
        [
          ...this.forms.map((form) => ({ value: String(form.id), label: form.title || "(untitled)" }))
        ],
        (value) => void this.open(Number(value))
      );
      picker.setAttribute("aria-label", "Choose a form");
      const title = el("input", {
        class: "atfb-title",
        type: "text",
        value: this.form?.title ?? "",
        placeholder: "Untitled form",
        attrs: { "aria-label": "Form title" },
        on: {
          input: (event) => {
            if (this.form) {
              this.form.title = event.target.value;
              this.markDirty();
            }
          }
        }
      });
      const tabs = el("div", {
        class: "atfb-tabs",
        attrs: { role: "tablist" },
        children: [
          ["build", "Build"],
          ["theme", "Theme"],
          ["settings", "Settings"],
          ["notify", "Notifications"],
          ["confirm", "Confirmations"]
        ].map(
          ([id, label]) => el("button", {
            class: `atfb-tab${this.tab === id ? " is-active" : ""}`,
            type: "button",
            text: label,
            attrs: { role: "tab", "aria-selected": this.tab === id },
            on: {
              click: () => {
                this.tab = id;
                this.renderBar();
                this.renderInspector();
                this.renderCanvas();
              }
            }
          })
        )
      });
      this.bar.append(
        el("div", {
          class: "atfb-bar__left",
          children: [this.forms.length > 1 ? picker : null, title]
        }),
        tabs,
        el("div", {
          class: "atfb-bar__right",
          children: [
            // No save-status label here. OpenStation's title bar already
            // carries one — the activity ring that `wp.os.fetch` drives,
            // which every request in `api.ts` goes through — so a second
            // one in the toolbar is the same information twice, in the
            // less prominent place. The Save button below shows whether
            // there is anything to save; the window says what happened
            // to it.
            //
            // Undo and redo are also Cmd/Ctrl+Z and Shift+Cmd/Ctrl+Z; the
            // buttons exist because a builder whose only undo is a
            // shortcut is a builder most people never discover has one.
            this.historyButton("undo", "Undo", -1),
            this.historyButton("redo", "Redo", 1),
            button("New", () => void this.showTemplates(), "secondary", "plus-alt2"),
            button("Export", () => void this.exportForm(), "secondary", "download"),
            button("Import", () => void this.importForm(), "secondary", "upload"),
            // The same action the title bar's eye performs, for the admin
            // page — where there is no title bar to put an eye in.
            button("Preview", () => void this.preview(), "secondary", "visibility"),
            button("Entries", () => this.openEntries(), "secondary", "list-view"),
            this.logicMapButton(),
            this.saveButton()
          ]
        })
      );
    }
    /**
     * The field with this id in the *current* schema.
     *
     * Returns undefined when it has gone — deleted in another window, or dropped
     * by the server's normalisation — which is a write that should simply not
     * happen rather than one that should throw.
     *
     * @param fieldId The field's id.
     * @return The live field, if it is still there.
     */
    liveField(fieldId) {
      return this.locateField(fieldId)?.field;
    }
    /**
     * A field found wherever it lives — the top level, or inside a repeater —
     * along with the list holding it, so a caller can move or remove it.
     *
     * @param fieldId The field's id.
     * @return The field, the list it sits in, its index there, and the repeater
     *         containing it (null at the top level).
     */
    locateField(fieldId) {
      const fields = this.schema?.fields ?? [];
      for (let index = 0; index < fields.length; index++) {
        if (fields[index].id === fieldId) {
          return { field: fields[index], list: fields, index, parent: null };
        }
        const subs = fields[index].fields ?? [];
        for (let at = 0; at < subs.length; at++) {
          if (subs[at].id === fieldId) {
            return { field: subs[at], list: subs, index: at, parent: fields[index] };
          }
        }
      }
      return void 0;
    }
    /**
     * Writes a field's current values into its card on the canvas.
     *
     * The mirror image of `syncInspector()`, for the same reason: the two panes
     * edit one value and have to agree while it is being typed. The inspector's
     * `update()` already repaints the canvas wholesale, but the choices editor
     * mutates in place and only marks the form dirty — which was invisible until
     * the canvas started drawing the options.
     *
     * Writes `textContent` rather than re-rendering, so the caret in the
     * inspector is untouched.
     *
     * Driven by whatever `data-atfb-bind` keys the card happens to carry rather
     * than by a list of properties kept here. The list was the bug waiting to
     * happen: every editable added to the canvas had to be remembered in two
     * other places, and forgetting one gave a value that mirrored in one
     * direction only — which looks like it works right up until you use the other
     * pane.
     *
     * @param field The field that was edited in the inspector.
     */
    syncCanvas(field) {
      const card = this.canvas.querySelector(`[data-atfb-card="${CSS.escape(field.id)}"]`) ?? this.canvas.querySelector(`[data-atfb-subfield="${CSS.escape(field.id)}"]`);
      if (!card) {
        return;
      }
      for (const node of card.querySelectorAll(".atfb-editable[data-atfb-bind]")) {
        const value = boundValue(field, node.dataset.atfbBind ?? "");
        if (node.textContent !== value) {
          node.textContent = value;
        }
      }
    }
    /**
     * Writes a field's current values into the inspector's matching controls.
     *
     * Only when the inspector is actually showing that field — editing a card that
     * is not selected must not rewrite the pane describing a different one.
     *
     * Deliberately one-directional and value-only: the inspector's own handlers
     * already write to the schema, and firing them from here would put the two
     * panes in a loop, each telling the other about a change it had just made.
     *
     * @param field The field that was edited on the canvas.
     */
    syncInspector(field) {
      if (this.selected !== field.id) {
        return;
      }
      for (const control2 of this.inspector.querySelectorAll(
        "[data-atfb-bind]"
      )) {
        const value = boundValue(field, control2.dataset.atfbBind ?? "");
        if (control2.value === value) {
          continue;
        }
        if ("value" in control2) {
          control2.value = value;
        } else {
          control2.setAttribute("value", value);
        }
      }
    }
    /**
     * Rebuilds the canvas so its cards point at the current schema objects.
     *
     * Deferred while the canvas holds focus. An autosave fires 2.5 seconds after
     * the last keystroke, which is exactly when somebody has paused mid-sentence
     * with the caret still in a label — and rebuilding then would take the caret
     * away for no reason they could see. Waiting for the blur costs nothing: the
     * card on screen already shows what they typed, and the rebind only has to
     * happen before the *next* edit.
     */
    rebindCanvas() {
      if ("build" !== this.tab && "confirm" !== this.tab && "notify" !== this.tab) {
        return;
      }
      const focused = document.activeElement;
      if (focused instanceof HTMLElement && this.canvas.contains(focused)) {
        focused.addEventListener("blur", () => this.rebindCanvas(), { once: true });
        return;
      }
      this.renderCanvas();
    }
    /**
     * Takes a history snapshot.
     *
     * Called before a *structural* change — adding, moving, duplicating or
     * deleting a field — and not on every keystroke. Snapshotting each character
     * typed into a label would make undo mean "remove one letter", which is not
     * what anybody reaches for it to do.
     */
    snapshot() {
      if (!this.schema) {
        return;
      }
      const json = JSON.stringify(this.schema);
      if (this.history[this.historyAt] === json) {
        return;
      }
      this.history = this.history.slice(0, this.historyAt + 1);
      this.history.push(json);
      if (this.history.length > 60) {
        this.history.shift();
      }
      this.historyAt = this.history.length - 1;
      this.syncHistoryButtons();
    }
    /** Steps backwards or forwards through the history. */
    travel(delta) {
      const next = this.historyAt + delta;
      if (!this.schema || next < 0 || next >= this.history.length) {
        return;
      }
      this.historyAt = next;
      this.schema = JSON.parse(this.history[next]);
      if (!this.schema.fields.some((field) => field.id === this.selected)) {
        this.selected = null;
      }
      this.markDirty();
      this.renderBar();
      this.renderCanvas();
      this.renderInspector();
    }
    /** An undo or redo button, disabled when there is nowhere to go. */
    historyButton(iconSlug, label, delta) {
      const node = button(label, () => this.travel(delta), "secondary", iconSlug);
      node.setAttribute("data-atfb-history", String(delta));
      node.disabled = !this.canTravel(delta);
      return node;
    }
    /** Whether the history has anywhere to go in this direction. */
    canTravel(delta) {
      const target = this.historyAt + delta;
      return target >= 0 && target < this.history.length;
    }
    /**
     * Refreshes Undo and Redo's disabled state in place.
     *
     * The state was computed only in `renderBar()`, which structural edits never
     * call — so Undo sat disabled all session while Cmd+Z quietly worked. The
     * buttons are updated whenever the history moves instead.
     */
    syncHistoryButtons() {
      for (const node of this.bar.querySelectorAll(
        "[data-atfb-history]"
      )) {
        node.disabled = !this.canTravel(Number(node.dataset.atfbHistory));
      }
    }
    /**
     * Downloads the current form as JSON.
     *
     * The exported document is the schema and the title — the same shape
     * `/forms` accepts on the way back in, so an export from one site is an
     * import on another with nothing in between. Entry data is deliberately not
     * in it: this is the form, not what people said in it.
     */
    exportForm() {
      if (!this.form || !this.schema) {
        return;
      }
      const payload = {
        plugin: "allterrain-forms",
        version: config?.version ?? "",
        title: this.form.title,
        schema: this.schema
      };
      const blob = new Blob([JSON.stringify(payload, null, "	")], { type: "application/json" });
      const url = URL.createObjectURL(blob);
      const link = el("a", {
        href: url,
        attrs: { download: `${this.form.title.replace(/[^a-z0-9]+/gi, "-").toLowerCase() || "form"}.json` }
      });
      document.body.append(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1e3);
    }
    /**
     * Creates a form from an exported JSON document.
     *
     * A new form rather than an overwrite of the open one. Import is the sort of
     * action people try to see what happens, and "see what happens" must never
     * mean "replace the form I spent an afternoon on".
     *
     * The schema is normalised server-side on the way in, so a hand-edited or
     * out-of-date document cannot put anything unusable into the database.
     */
    async importForm() {
      const picker = el("input", { type: "file", attrs: { accept: "application/json,.json" } });
      picker.addEventListener("change", async () => {
        const file = picker.files?.[0];
        if (!file) {
          return;
        }
        try {
          const parsed = JSON.parse(await file.text());
          if (!parsed.schema) {
            throw new Error("That file does not contain a form.");
          }
          const created = await api.createForm({
            title: parsed.title ?? file.name.replace(/\.json$/i, ""),
            schema: parsed.schema
          });
          this.forms.unshift({
            id: created.id,
            title: created.title,
            status: created.status,
            modified: created.modified,
            fields: created.schema.fields.length,
            theme: created.schema.settings.theme,
            entries: 0,
            unread: 0,
            views: 0,
            submissions: 0,
            shortcode: created.shortcode
          });
          this.form = created;
          this.schema = created.schema;
          this.selected = null;
          this.dirty = false;
          this.history = [];
          this.historyAt = -1;
          this.snapshot();
          this.renderBar();
          this.renderCanvas();
          this.renderInspector();
          notify("Form imported", created.title);
        } catch (error) {
          notify("Could not import that file", error instanceof Error ? error.message : "", "error");
        }
      });
      picker.click();
    }
    /**
     * The Save button, which is the only place unsaved state is shown.
     *
     * Disabled while there is nothing to save, so the button itself answers "is
     * my work in?" without a label beside it repeating the answer.
     */
    saveButton() {
      const node = button("Save", () => void this.save(), "primary");
      node.disabled = !this.dirty;
      node.setAttribute("data-atfb-save", "");
      return node;
    }
    /** Marks the form as having unsaved changes and schedules an autosave. */
    markDirty() {
      this.dirty = true;
      this.editGeneration += 1;
      const save = this.bar.querySelector("[data-atfb-save]");
      if (save) {
        save.disabled = false;
      }
      this.autosave();
    }
    /** Writes the form back. */
    async save(silent = false) {
      if (!this.form || !this.schema) {
        return;
      }
      if (this.saveInFlight) {
        this.queuedSave = { silent: silent && (this.queuedSave?.silent ?? true) };
        return;
      }
      this.saveInFlight = true;
      const generation = this.editGeneration;
      try {
        const saved = await api.updateForm(this.form.id, {
          title: this.form.title,
          schema: this.schema
        });
        if (generation === this.editGeneration) {
          this.form = saved;
          this.schema = saved.schema;
          this.dirty = false;
          this.rebindCanvas();
          const save = this.bar.querySelector("[data-atfb-save]");
          if (save) {
            save.disabled = true;
          }
        }
        forgetMergeTags(saved.id);
        const summary = this.forms.find((candidate) => candidate.id === saved.id);
        if (summary) {
          summary.title = saved.title;
        }
        refreshPreview(saved.id, saved.title, saved.previewUrl);
        if (!silent) {
          notify("Form saved", saved.title);
        }
      } catch (error) {
        notify(
          i18n("saveFailed", "Could not save"),
          error instanceof Error ? error.message : "",
          "error"
        );
      } finally {
        this.saveInFlight = false;
        const queued = this.queuedSave;
        this.queuedSave = null;
        if (queued) {
          void this.save(queued.silent);
        }
      }
    }
    /* ---------------------------------------------------------------- Forms */
    /** Opens a form. */
    /**
     * Deep-link entry: WP Explorer (and anything else) asks for a form by id.
     *
     * @param id The form to open on the canvas.
     */
    async openFormById(id) {
      await this.open(id);
    }
    async open(id) {
      if (this.dirty && !await confirmAction("You have unsaved changes. Discard them?")) {
        return;
      }
      try {
        this.form = await api.getForm(id);
        this.schema = this.form.schema;
        this.selected = null;
        this.dirty = false;
        this.history = [];
        this.historyAt = -1;
        this.snapshot();
      } catch (error) {
        this.fail(error);
        return;
      }
      this.renderBar();
      this.renderCanvas();
      this.renderInspector();
      this.announceIdentity();
    }
    /**
     * Tells the shell which form this window is showing.
     *
     * That one call is what makes an entries window for the same form draw a tie
     * to this one, and what fills the title bar's Related menu. Re-announced on
     * every open, because the identity is the *form*, not the window.
     */
    announceIdentity() {
      if (!this.form) {
        return;
      }
      setIdentity(this.root, formIdentity(this.form, config?.adminUrl ?? ""));
    }
    /** The template picker, for a new form. */
    async showTemplates() {
      if (!this.config) {
        return;
      }
      const overlay = el("div", { class: "atfb-overlay" });
      const onKeydown = (event) => {
        if (event.key === "Escape") {
          close();
        }
      };
      const close = () => {
        overlay.remove();
        document.removeEventListener("keydown", onKeydown);
      };
      const grid = el("div", {
        class: "atfb-templates",
        children: this.config.templates.map(
          (template) => el("button", {
            class: "atfb-template",
            type: "button",
            on: {
              click: async () => {
                close();
                try {
                  const created = await api.createForm({ template: template.slug });
                  this.forms.unshift({
                    id: created.id,
                    title: created.title,
                    status: created.status,
                    modified: created.modified,
                    fields: created.schema.fields.length,
                    theme: created.schema.settings.theme,
                    entries: 0,
                    unread: 0,
                    views: 0,
                    submissions: 0,
                    shortcode: created.shortcode
                  });
                  this.form = created;
                  this.schema = created.schema;
                  this.selected = null;
                  this.dirty = false;
                  this.history = [];
                  this.historyAt = -1;
                  this.snapshot();
                  this.renderBar();
                  this.renderCanvas();
                  this.renderInspector();
                  this.announceIdentity();
                } catch (error) {
                  notify("Could not create the form", error instanceof Error ? error.message : "", "error");
                }
              }
            },
            children: [
              icon(template.icon),
              el("strong", { text: template.label }),
              el("span", { text: template.description })
            ]
          })
        )
      });
      overlay.append(
        el("div", {
          class: "atfb-modal",
          attrs: { role: "dialog", "aria-label": "Start a new form" },
          children: [
            el("h2", { text: "Start a new form" }),
            grid,
            this.archivedFormsSection(close),
            el("div", { class: "atfb-modal__actions", children: [button("Cancel", close)] })
          ]
        })
      );
      overlay.addEventListener("click", (event) => {
        if (event.target === overlay) {
          close();
        }
      });
      document.addEventListener("keydown", onKeydown);
      this.root.append(overlay);
      grid.querySelector("button")?.focus();
    }
    /**
     * The archive's door, inside the "Start a new form" dialog.
     *
     * Restoring a retired form is a way of getting a form, so it lives where
     * getting a form lives — not behind a settings tab on a form you would
     * have to already have open. The list arrives asynchronously and the
     * section simply is not there when the archive is empty, so the dialog
     * costs nothing on the sites that never archive anything.
     *
     * @param close Closes the dialog this section sits in.
     * @return The section, filled in when the archive answers.
     */
    archivedFormsSection(close) {
      const section = el("div", { class: "atfb-archived" });
      void api.listArchivedForms().then((archived) => {
        if (!archived.length) {
          return;
        }
        section.append(
          el("h3", { class: "atfb-archived__title", text: "Or bring one back from the archive" }),
          ...archived.map(
            (form) => el("div", {
              class: "atfb-archived__row",
              children: [
                el("div", {
                  class: "atfb-archived__meta",
                  children: [
                    el("strong", { text: form.title || "(untitled)" }),
                    el("span", {
                      class: "atfb-hint",
                      text: `${form.entries} ${form.entries === 1 ? "entry" : "entries"} · ${form.submissions} submissions · ${form.views} views`
                    })
                  ]
                }),
                button(
                  "Restore",
                  async () => {
                    try {
                      const restored = await api.unarchiveForm(form.id);
                      close();
                      this.forms.unshift(restored);
                      notify("Form restored", `${restored.title || "(untitled)"} is back, with its entries and stats.`);
                      await this.open(restored.id);
                    } catch (error) {
                      notify(
                        "Could not restore the form",
                        error instanceof Error ? error.message : "",
                        "error"
                      );
                    }
                  },
                  "secondary",
                  "undo"
                )
              ]
            })
          )
        );
      }).catch(() => {
      });
      return section;
    }
    /** Shown when the site has no forms at all. */
    renderFormsList() {
      clear(this.canvas);
      this.canvas.append(
        el("div", {
          class: "atfb-empty",
          children: [
            el("h2", { text: "No forms yet" }),
            el("p", { text: "Start from a template, or build one from nothing." }),
            button("New form", () => void this.showTemplates(), "primary", "plus-alt2")
          ]
        })
      );
    }
    /** Opens the entries window, or the entries admin page. */
    openEntries() {
      const shell2 = window.wp?.os;
      if (shell2?.openWindow) {
        shell2.openWindow("allterrain-forms-entries");
        return;
      }
      window.location.assign(`${config?.adminUrl ?? ""}admin.php?page=allterrain-forms-entries`);
    }
    /* -------------------------------------------------------------- Palette */
    /** Draws the field palette, grouped. */
    renderPalette() {
      if (!this.config) {
        return;
      }
      clear(this.palette);
      const grouped = /* @__PURE__ */ new Map();
      for (const type of this.config.fieldTypes) {
        const list = grouped.get(type.group) ?? [];
        list.push(type);
        grouped.set(type.group, list);
      }
      const search = el("input", {
        class: "atfb-input atfb-palette__search",
        type: "search",
        placeholder: "Search fields",
        attrs: { "aria-label": "Search field types" },
        on: {
          input: (event) => {
            const term = event.target.value.toLowerCase().trim();
            this.palette.querySelectorAll(".atfb-chip").forEach((chip) => {
              const label = (chip.textContent ?? "").toLowerCase();
              chip.hidden = term !== "" && !label.includes(term);
            });
            this.palette.querySelectorAll(".atfb-group").forEach((group) => {
              const visible = Array.from(group.querySelectorAll(".atfb-chip")).some(
                (chip) => !chip.hidden
              );
              group.hidden = !visible;
            });
          }
        }
      });
      this.palette.append(search);
      for (const [slug, label] of Object.entries(this.config.groups)) {
        const types = grouped.get(slug);
        if (!types?.length) {
          continue;
        }
        this.palette.append(
          el("div", {
            class: "atfb-group",
            children: [
              el("h3", { class: "atfb-group__title", text: label }),
              el("div", {
                class: "atfb-group__items",
                children: types.map((type) => this.paletteChip(type))
              })
            ]
          })
        );
      }
    }
    /**
     * One palette entry.
     *
     * A real `<button>`, so it is reachable by keyboard and activating it adds
     * the field to the end of the form. The drag is layered on top of that
     * rather than replacing it — `onClickOnly` is what the drag manager calls
     * when a press never travelled far enough to become a drag, so one element
     * serves both interactions without a click firing after a drop.
     */
    paletteChip(type) {
      const chip = el("button", {
        class: "atfb-chip",
        type: "button",
        title: type.description,
        attrs: { "data-atf-type": type.type },
        children: [icon(type.icon), el("span", { text: type.label })]
      });
      chip.addEventListener("pointerdown", (event) => {
        const ghost = el("div", {
          class: "atfb-chip atfb-chip--ghost",
          children: [icon(type.icon), el("span", { text: type.label })]
        });
        getDragManager().start({
          payload: buildPayload(FIELD_PAYLOAD_TYPE, chip, { fieldType: type.type, isNew: true }, event, ghost),
          origin: event,
          onClickOnly: () => this.addField(type.type)
        });
      });
      chip.addEventListener("click", (event) => {
        if (getDragManager().recentlyEndedDrag()) {
          event.preventDefault();
        }
      });
      return chip;
    }
    /* --------------------------------------------------------------- Canvas */
    /** Draws the canvas for the current tab. */
    renderCanvas() {
      clear(this.canvas);
      if (!this.schema || !this.form) {
        this.renderFormsList();
        return;
      }
      if (this.tab !== "build") {
        this.canvas.append(this.renderTabCanvas());
        return;
      }
      const list = el("div", { class: "atfb-canvas__list", attrs: { "data-atfb-list": "" } });
      if (!this.schema.fields.length) {
        list.append(
          el("div", {
            class: "atfb-placeholder",
            text: i18n("emptyCanvas", "Drag a field from the left to begin.")
          })
        );
      }
      this.schema.fields.forEach((field, index) => {
        list.append(this.renderFieldCard(field, index));
      });
      const inner = el("div", {
        class: "atfb-canvas__inner",
        children: [
          el("p", {
            class: "atfb-shortcode",
            text: this.form.shortcode,
            title: "Paste this anywhere to place the form"
          }),
          list
        ]
      });
      this.canvas.append(inner);
      this.registerCanvasTarget(list);
      this.paintLogicMap(inner);
      void this.paintCanvasTheme();
    }
    /**
     * Where a field's opening value comes from, asked in plain language.
     *
     * This box used to be free text under the hint
     * `query:utm_source, user:email, user:name, site:name or date:today` — a list
     * of five examples of a syntax nobody had been taught, two of which
     * (`user:name`, `site:name`) were not even things the resolver understood. So
     * the one person who typed exactly what the hint said got an empty field and
     * no error, because an unrecognised source resolves to nothing.
     *
     * The sources are a closed set, so they are offered as a list. The stored
     * value is still the same string — a form built before this opens in whichever
     * mode its value already matches, and a plugin adding a source through
     * `atf_resolve_prefill` still works via Advanced.
     */
    prefillControl(field, update) {
      const isQuery = field.prefill.startsWith("query:");
      const known = PREFILL_SOURCES.some((source) => source.value === field.prefill);
      const mode = isQuery ? "query" : known && field.prefill || (field.prefill ? "custom" : "");
      const detail = el("div", { class: "atfb-prefill__detail" });
      const preview = el("p", { class: "atfb-row__hint atfb-prefill__preview" });
      const paint = (current) => {
        detail.replaceChildren();
        preview.replaceChildren();
        if ("query" === current) {
          const name = field.prefill.startsWith("query:") ? field.prefill.slice(6) : "";
          detail.append(
            textInput(
              name,
              (value) => {
                const trimmed = value.trim();
                update("prefill", trimmed ? `query:${trimmed}` : "");
                paintPreview(`query:${trimmed}`);
              },
              "utm_source"
            ),
            el("p", {
              class: "atfb-row__hint",
              text: "The name of the parameter in the link people arrive on."
            })
          );
        }
        if ("custom" === current) {
          detail.append(
            textInput(
              field.prefill,
              (value) => {
                update("prefill", value);
                paintPreview(value);
              },
              "myplugin:something"
            ),
            el("p", {
              class: "atfb-row__hint",
              text: "For a source another plugin has added through atf_resolve_prefill."
            })
          );
        }
        paintPreview(field.prefill);
      };
      const paintPreview = (source) => {
        preview.replaceChildren();
        if (!source) {
          return;
        }
        if (source.startsWith("query:")) {
          const name = source.slice(6);
          if (name) {
            preview.textContent = `A visit to …/your-page/?${name}=abc opens the form with “abc” in it.`;
          }
          return;
        }
        const tag = PREFILL_SOURCES.find((candidate) => candidate.value === source)?.tag;
        if (!tag) {
          return;
        }
        void mergeTags(this.form?.id ?? 0).then((groups) => {
          for (const group of groups) {
            for (const item of group.items) {
              if (item.tag === tag) {
                const personal = source.startsWith("user:");
                if (!item.sample) {
                  preview.textContent = "Empty unless the visitor is signed in.";
                  return;
                }
                preview.textContent = personal ? `Opens with “${item.sample}” for you — empty for a visitor who is not signed in.` : `Opens with “${item.sample}”.`;
                return;
              }
            }
          }
        });
      };
      paint(mode);
      const picker = el("select", {
        class: "atfb-input atfb-select",
        on: {
          change: (event) => {
            const value = event.target.value;
            update("prefill", "query" === value || "custom" === value ? "" : value);
            paint(value);
          }
        }
      });
      picker.append(
        el("option", { value: "", text: "Nothing — leave it empty", attrs: { selected: "" === mode } })
      );
      for (const group of PREFILL_GROUPS) {
        const optgroup = document.createElement("optgroup");
        optgroup.label = group;
        for (const source of PREFILL_SOURCES.filter((candidate) => candidate.group === group)) {
          optgroup.append(
            el("option", {
              value: source.value,
              text: source.label,
              attrs: { selected: source.value === mode }
            })
          );
        }
        picker.append(optgroup);
      }
      const link = document.createElement("optgroup");
      link.label = "From the link they arrived on";
      link.append(
        el("option", { value: "query", text: "A parameter in the web address", attrs: { selected: "query" === mode } })
      );
      picker.append(
        link,
        el("option", { value: "custom", text: "Something else (advanced)", attrs: { selected: "custom" === mode } })
      );
      return row(
        "Pre-fill this with",
        el("div", { class: "atfb-prefill", children: [picker, detail, preview] }),
        "What the box already contains when the form opens. They can still change it."
      );
    }
    /**
     * A field's condition, drawn as its parts rather than as a sentence.
     *
     * "Shown when Can you make it? is Yes, I will be there" is five things in a
     * row with nothing to separate them, and two of the five are text somebody
     * typed — so the question ends in a question mark and the answer contains a
     * comma, and the punctuation the sentence relies on for structure is also in
     * the content. Reading it means parsing it.
     *
     * Drawn as parts, no parsing is needed: the referenced question is a chip,
     * the answer is a chip, and the verb and comparison are quiet text between
     * them. The whole row still carries the plain sentence as its `aria-label`,
     * because a screen reader reading five chips as five unrelated fragments
     * would be worse off than before.
     *
     * The question chip is a button that selects that field — the reference is
     * the useful kind, the kind you can follow.
     */
    renderCondition(owner, tokens) {
      const broken = tokens.some((token) => "field" === token.kind && token.missing);
      const wrap = el("span", {
        class: `atfb-cond${broken ? " is-broken" : ""}`,
        attrs: { "aria-label": tokensToText(tokens) },
        children: [
          icon("randomize"),
          ...tokens.map((token) => this.renderConditionToken(owner, token))
        ]
      });
      wrap.addEventListener("pointerdown", (event) => event.stopPropagation());
      wrap.addEventListener("click", (event) => event.stopPropagation());
      wrap.addEventListener("keydown", (event) => event.stopPropagation());
      return wrap;
    }
    /**
     * Writes to a field's live logic block and repaints what shows it.
     *
     * With `rebuild` false the cards are left alone and only the curve labels
     * refresh — the mode for every keystroke in the value box, where a rebuild
     * would destroy the input mid-word. The commit (change/blur) passes true and
     * everything redraws, with focus put back on the control named by `refocus`
     * so keyboard editing survives the rebuild.
     */
    editCondition(fieldId, mutate, rebuild = true, refocus = "") {
      const live = this.liveField(fieldId)?.logic;
      if (!live) {
        return;
      }
      mutate(live);
      this.markDirty();
      if (!rebuild) {
        this.logicMap?.setEdges(logicEdges(this.schema?.fields ?? []));
        return;
      }
      this.renderCanvas();
      this.renderInspector();
      if (refocus) {
        window.requestAnimationFrame(() => {
          this.canvas.querySelector(`[data-cond="${CSS.escape(refocus)}"]`)?.focus();
        });
      }
    }
    /**
     * A small dropdown for the condition row.
     *
     * The shell's own `<os-select>` when its components are loaded, so the
     * control on the card is the same control everywhere else on the desktop —
     * a bare browser `<select>` next to os-styled chrome read as a seam. On
     * the plain admin page, where the components do not exist, a native select
     * is the seamless choice for exactly the same reason.
     *
     * @param value    The selected value.
     * @param options  What can be picked.
     * @param key      The `data-cond` refocus key.
     * @param label    The accessible name.
     * @param onChange Called with the newly picked value.
     * @return The control.
     */
    condSelect(value, options, key, label, onChange) {
      if (hasComponent("os-select") && hasComponent("os-option")) {
        const host = document.createElement("os-select");
        host.setAttribute("value", value);
        host.setAttribute("aria-label", label);
        host.setAttribute("data-cond", key);
        host.className = "atfb-cond__control";
        host.title = label;
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
        class: "atfb-cond__control atfb-cond__control--native",
        title: label,
        attrs: { "aria-label": label, "data-cond": key },
        on: {
          change: (event) => onChange(event.target.value)
        },
        children: options.map(
          (option) => el("option", { value: option.value, text: option.label, attrs: { selected: option.value === value } })
        )
      });
    }
    /**
     * One tagged part of a condition — as the control that edits it.
     *
     * The row used to *describe* the rule and send you to the inspector to
     * change it, which is the opposite of direct manipulation: the words were
     * right there and none of them answered to a click. Now each part is the
     * editor for what it shows — the verb flips show/hide, the comparison is a
     * small select, the answer is an input (or a select of the source field's
     * choices), and "and"/"or" toggles how rules combine.
     */
    renderConditionToken(owner, token) {
      if ("field" === token.kind && !token.missing) {
        const chip = el("button", {
          class: "atfb-cond__chip atfb-cond__chip--field",
          type: "button",
          text: token.text,
          title: "Go to this question",
          // The row is inside a card that is itself a button; without this the
          // click selects the card the chip is *on* rather than the question it
          // names, which is the opposite of what it offers.
          on: {
            click: (event) => {
              event.stopPropagation();
              this.selectField(token.fieldId);
              this.canvas.querySelector(`[data-atfb-card="${CSS.escape(token.fieldId)}"]`)?.scrollIntoView({ block: "nearest", behavior: "smooth" });
            }
          }
        });
        return chip;
      }
      if ("verb" === token.kind) {
        return el("button", {
          class: "atfb-cond__verb",
          type: "button",
          text: token.text,
          title: "Switch between showing and hiding this field when the condition matches.",
          attrs: { "data-cond": `${owner.id}:verb` },
          on: {
            click: () => this.editCondition(
              owner.id,
              (logic) => {
                logic.action = "hide" === logic.action ? "show" : "hide";
              },
              true,
              `${owner.id}:verb`
            )
          }
        });
      }
      if ("join" === token.kind) {
        return el("button", {
          class: "atfb-cond__join",
          type: "button",
          text: token.text,
          title: "Switch between needing every rule (and) or any one of them (or).",
          attrs: { "data-cond": `${owner.id}:join` },
          on: {
            click: () => this.editCondition(
              owner.id,
              (logic) => {
                logic.match = "all" === logic.match ? "any" : "all";
              },
              true,
              `${owner.id}:join`
            )
          }
        });
      }
      if ("operator" === token.kind) {
        const key = `${owner.id}:op:${token.ruleIndex}`;
        return this.condSelect(
          token.operator,
          Object.entries(OPERATOR_LABELS).map(([value, label]) => ({ value, label })),
          key,
          "How the answer is compared",
          (picked) => this.editCondition(
            owner.id,
            (logic) => {
              const rule = logic.rules[token.ruleIndex];
              if (rule) {
                rule.operator = picked;
              }
            },
            true,
            key
          )
        );
      }
      if ("value" === token.kind) {
        return this.renderConditionValue(owner, token);
      }
      return el("span", { class: "atfb-cond__chip atfb-cond__chip--missing", text: token.text });
    }
    /**
     * The answer half of a condition, as the control it deserves.
     *
     * When the question being consulted has choices, the honest editor is a
     * select of those choices — typing free text against a radio group can only
     * produce a rule that never matches. Anything else gets a text box, sized to
     * its content so it reads as part of the sentence rather than as a form.
     */
    renderConditionValue(owner, token) {
      const key = `${owner.id}:value:${token.ruleIndex}`;
      const source = this.schema?.fields.find((candidate) => candidate.id === token.sourceId);
      const write = (value, rebuild) => this.editCondition(
        owner.id,
        (logic) => {
          const rule = logic.rules[token.ruleIndex];
          if (rule) {
            rule.value = value;
          }
        },
        rebuild,
        key
      );
      if (source?.choices?.length) {
        const options = source.choices.map((choice) => ({
          value: choice.value,
          label: choice.label || choice.value
        }));
        if (token.raw !== "" && !source.choices.some((choice) => choice.value === token.raw)) {
          options.unshift({ value: token.raw, label: token.text });
        }
        return this.condSelect(
          token.raw,
          options,
          key,
          "The answer that triggers this",
          (picked) => write(picked, true)
        );
      }
      const numeric = ["number", "range", "scale", "rating", "total"].includes(source?.type ?? "");
      const input = el("input", {
        class: "atfb-cond__chip atfb-cond__chip--value atfb-cond__value",
        value: token.raw,
        title: "The answer that triggers this. Edit it here.",
        attrs: {
          type: "text",
          "aria-label": "The answer that triggers this",
          "data-cond": key,
          inputmode: numeric ? "decimal" : void 0,
          size: String(Math.max(2, Math.min(24, token.raw.length || 2)))
        }
      });
      input.addEventListener("input", () => {
        input.size = Math.max(2, Math.min(24, input.value.length || 2));
        write(input.value, false);
      });
      input.addEventListener("change", () => write(input.value, true));
      input.addEventListener("keydown", (event) => {
        if ("Enter" === event.key) {
          event.preventDefault();
          write(input.value, true);
        }
      });
      return input;
    }
    /**
     * A disclosure panel that remembers whether it was open.
     *
     * `openByDefault` decides only what happens the *first* time a key is seen —
     * a field that already has a condition opens showing it, because arriving at
     * a field and being told nothing about a rule that governs it is worse than a
     * little extra height. After that the person's own choice wins.
     *
     * What this deliberately does not do is derive `open` from the data inside
     * it. Conditional logic used to: `open: logic.enabled`, so unticking "Only
     * show this field sometimes" collapsed the panel around the checkbox that had
     * just been clicked. Whether a panel is open is a question about the
     * *person's attention*; whether a feature is on is a question about the
     * *form*. Binding one to the other means neither can be set independently.
     *
     * @param key           Stable identity for this panel.
     * @param summary       The panel's heading.
     * @param children      What it contains.
     * @param openByDefault Whether to open it the first time it is rendered.
     * @return The panel.
     */
    section(key, summary, children, openByDefault = false) {
      const details = el("details", {
        class: "atfb-section",
        attrs: { open: this.openSections.get(key) ?? openByDefault },
        children: [el("summary", { text: summary }), ...children]
      });
      details.addEventListener("toggle", () => this.openSections.set(key, details.open));
      return details;
    }
    /**
     * The toolbar's toggle for the logic overlay.
     *
     * Hidden entirely on a form with no conditions. A control for a thing that
     * is not there teaches nothing and takes up a slot in a toolbar that already
     * has eight.
     */
    logicMapButton() {
      const has = logicEdges(this.schema?.fields ?? []).length > 0;
      const toggle = button(
        this.logicMapOn ? "Hide logic" : "Show logic",
        () => {
          this.logicMapOn = !this.logicMapOn;
          writeSetting(LOGIC_MAP_SETTING, this.logicMapOn ? "on" : "off");
          this.renderBar();
          this.renderCanvas();
        },
        this.logicMapOn ? "primary" : "secondary",
        "randomize"
      );
      toggle.title = "Draw a line from each question to the ones it decides.";
      toggle.hidden = !has;
      return toggle;
    }
    /**
     * Draws the conditional-logic connections over the canvas.
     *
     * Rebuilt with the canvas rather than kept alive across renders: the layer
     * measures cards that this render has just replaced, and an instance holding
     * a `ResizeObserver` on a detached element is a leak that also stops
     * redrawing. Cheap enough — it is one `<svg>` and a handful of paths.
     *
     * @param inner The canvas element the layer covers.
     */
    paintLogicMap(inner) {
      this.logicMap?.destroy();
      this.logicMap = null;
      const fields = this.schema?.fields ?? [];
      const edges = logicEdges(fields);
      if (!edges.length || !this.logicMapOn) {
        return;
      }
      inner.classList.add("has-logicmap");
      const map = new LogicMap(inner);
      map.setEdges(edges);
      map.highlight(this.selected ?? "");
      this.logicMap = map;
      inner.addEventListener("pointerover", (event) => {
        const card = event.target.closest("[data-atfb-card]");
        map.highlight(card?.dataset.atfbCard ?? this.selected ?? "");
      });
      inner.addEventListener("pointerleave", () => map.highlight(this.selected ?? ""));
    }
    /** One field on the canvas. */
    renderFieldCard(field, index) {
      const type = this.config?.fieldTypes.find((candidate) => candidate.type === field.type);
      const selected = this.selected === field.id;
      const fields = this.schema?.fields ?? [];
      const condition = logicTokens(field, fields);
      const controls = controlCounts(fields).get(field.id) ?? 0;
      const card = el("div", {
        class: `atfb-card${selected ? " is-selected" : ""}`,
        attrs: {
          "data-atfb-card": field.id,
          "data-index": index,
          tabindex: "0",
          role: "button",
          "aria-pressed": selected,
          "aria-label": `${field.label || type?.label || field.type}, ${index + 1} of ${this.schema?.fields.length ?? 0}`
        },
        children: [
          // The card is a miniature of the window that contains it: a title
          // bar carrying the grip, the field's identity and the actions,
          // with the field itself as the window body below. The bar is one
          // element rather than three floated ones so the shell's titlebar
          // surface can paint across it edge to edge.
          el("div", {
            class: "atfb-card__bar",
            children: [
              el("div", {
                class: "atfb-card__grip",
                attrs: { "aria-hidden": "true" },
                children: [icon("menu")]
              }),
              el("div", {
                class: "atfb-card__head",
                children: [
                  icon(type?.icon ?? "dashicons-forms"),
                  el("span", { class: "atfb-card__type", text: type?.label ?? field.type }),
                  this.requiredToggle(field),
                  controls ? el("span", {
                    class: "atfb-badge atfb-badge--controls",
                    text: 1 === controls ? "controls 1 field" : `controls ${controls} fields`,
                    title: "Other questions appear or disappear based on this answer."
                  }) : null
                ]
              }),
              el("div", {
                class: "atfb-card__actions",
                children: [
                  this.cardAction("admin-page", "Duplicate", () => this.duplicateField(field.id)),
                  this.cardAction("trash", "Delete", () => void this.deleteField(field.id))
                ]
              })
            ]
          }),
          el("div", {
            class: "atfb-card__body",
            children: [
              // The field itself, drawn with the real front-end classes and the
              // form's own theme, with its text editable where it sits.
              renderFieldPreview(field, type, {
                // The live field is looked up by id on every write. A save
                // replaces `this.schema` with the server's normalised copy,
                // so the object this card was rendered from stops being the
                // one that gets serialised — see `PreviewHandlers`.
                edit: (apply) => {
                  const live = this.liveField(field.id);
                  if (!live) {
                    return;
                  }
                  apply(live);
                  this.markDirty();
                  this.syncInspector(live);
                  const selected2 = this.selected ? this.locateField(this.selected) : void 0;
                  if (selected2?.parent && selected2.parent.id === live.id) {
                    this.syncInspector(selected2.field);
                  }
                },
                restructure: (apply) => {
                  const live = this.liveField(field.id);
                  if (!live) {
                    return;
                  }
                  this.snapshot();
                  apply(live);
                  this.markDirty();
                  this.renderCanvas();
                  this.renderInspector();
                },
                // A repeater draws each sub-field through the same
                // preview machinery, and needs to know their types
                // and which of them is selected.
                types: (name) => this.config?.fieldTypes.find((candidate) => candidate.type === name),
                selectedId: this.selected
              }),
              condition.length ? this.renderCondition(field, condition) : null
            ]
          })
        ]
      });
      card.addEventListener("click", (event) => {
        if (event.target.closest(".atfb-card__actions")) {
          return;
        }
        if (getDragManager().recentlyEndedDrag()) {
          return;
        }
        this.selectField(field.id);
      });
      card.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          this.selectField(field.id);
          return;
        }
        if (event.altKey && (event.key === "ArrowUp" || event.key === "ArrowDown")) {
          event.preventDefault();
          this.moveField(field.id, event.key === "ArrowUp" ? index - 1 : index + 1);
          window.requestAnimationFrame(() => {
            this.canvas.querySelector(`[data-atfb-card="${CSS.escape(field.id)}"]`)?.focus();
          });
        }
        if (event.key === "Delete" || event.key === "Backspace") {
          event.preventDefault();
          void this.deleteField(field.id);
        }
      });
      card.addEventListener("pointerdown", (event) => {
        if (event.target.closest(".atfb-card__actions")) {
          return;
        }
        getDragManager().start({
          payload: buildPayload(FIELD_PAYLOAD_TYPE, card, { fieldId: field.id, field, isNew: false }, event),
          origin: event
        });
      });
      return card;
    }
    /**
     * The required flag, as a toggle on the card rather than a badge.
     *
     * It was already displayed here as a read-only badge, and the switch that set
     * it was in the inspector — so the canvas told you a field was required and
     * made you go somewhere else to change it. Marking a question required is a
     * decision you make while writing it, not afterwards.
     */
    requiredToggle(field) {
      const toggle = el("button", {
        class: `atfb-req${field.required ? " is-on" : ""}`,
        type: "button",
        text: field.required ? "Required" : "Optional",
        title: field.required ? "This must be answered. Click to make it optional." : "Click to make this required.",
        attrs: { "aria-pressed": field.required ? "true" : "false" },
        on: {
          // The card is draggable and clicking it selects the field; neither
          // should happen when the target was this switch.
          pointerdown: (event) => event.stopPropagation(),
          click: (event) => {
            event.stopPropagation();
            field.required = !field.required;
            this.markDirty();
            this.renderCanvas();
            this.renderInspector();
          }
        }
      });
      return toggle;
    }
    /** A small icon button on a field card. */
    cardAction(iconSlug, label, onClick) {
      return el("button", {
        class: "atfb-card__action",
        type: "button",
        title: label,
        attrs: { "aria-label": label },
        on: {
          click: (event) => {
            event.stopPropagation();
            onClick();
          }
        },
        children: [icon(iconSlug)]
      });
    }
    /**
     * Makes the canvas a drop target.
     *
     * Accepts this plugin's own field payload — from the palette, from this
     * canvas, or from a *second* builder window, which is what the shell's
     * shared drag manager buys and an iframe could not.
     */
    registerCanvasTarget(list) {
      this.canvasTarget?.();
      this.canvasTarget = null;
      const marker = el("div", { class: "atfb-marker", attrs: { "aria-hidden": "true" } });
      const teardown = getDragManager().registerDropTarget({
        id: `atfb-canvas-${this.form?.id ?? 0}`,
        element: list,
        accept: (payload) => payload.type === FIELD_PAYLOAD_TYPE,
        onEnter: () => list.classList.add("is-dropping"),
        onLeave: () => {
          list.classList.remove("is-dropping");
          marker.remove();
        },
        onDrop: (session, position) => {
          list.classList.remove("is-dropping");
          marker.remove();
          const data = session.payload.data;
          const source = data.fieldId ? this.canvas.querySelector(`[data-atfb-card="${CSS.escape(data.fieldId)}"]`) : null;
          const index = insertionIndex(list, ".atfb-card", position.clientY, source ?? void 0);
          if (data.isNew && data.fieldType) {
            this.addField(data.fieldType, index);
            return;
          }
          if (data.fieldId && this.locateField(data.fieldId)) {
            this.relocateField(data.fieldId, null, index);
            return;
          }
          if (data.field) {
            this.insertField({ ...data.field, id: "" }, index);
          }
        }
      });
      const zoneTeardowns = this.wireRepeaterZones(list);
      const onMove = (event) => {
        const detail = event.detail;
        if (detail?.payload?.type !== FIELD_PAYLOAD_TYPE || !list.classList.contains("is-dropping")) {
          return;
        }
        const dragged = detail.payload.data?.fieldId ? this.canvas.querySelector(
          `[data-atfb-card="${CSS.escape(detail.payload.data.fieldId)}"]`
        ) : null;
        const y = detail.clientY ?? 0;
        const index = insertionIndex(list, ".atfb-card", y, dragged ?? void 0);
        const cards = Array.from(list.querySelectorAll(".atfb-card")).filter(
          (card) => card !== dragged
        );
        if (index >= cards.length) {
          list.append(marker);
        } else {
          cards[index].before(marker);
        }
      };
      document.addEventListener("os.drag.move", onMove);
      this.canvasTarget = () => {
        teardown();
        zoneTeardowns.forEach((zoneTeardown) => zoneTeardown());
        document.removeEventListener("os.drag.move", onMove);
      };
    }
    /**
     * Makes every repeater on the canvas a drop target of its own, and its
     * sub-field cards draggable, selectable and keyboard-operable.
     *
     * The zone elements are drawn by the preview (`field-preview.ts`), which
     * cannot reach the drag manager; this is where they come alive. Zones are
     * nested inside the canvas target, and the manager resolves hits depth
     * first, so a drop lands in the repeater when it is over one and on the
     * canvas when it is not.
     *
     * @param list The canvas list, freshly rendered.
     * @return One teardown per registered zone.
     */
    wireRepeaterZones(list) {
      const teardowns = [];
      list.querySelectorAll("[data-atfb-repeater-zone]").forEach((zone) => {
        const repeaterId = zone.dataset.atfbRepeaterZone ?? "";
        teardowns.push(
          getDragManager().registerDropTarget({
            id: `atfb-repzone-${this.form?.id ?? 0}-${repeaterId}`,
            element: zone,
            accept: (payload) => {
              if (payload.type !== FIELD_PAYLOAD_TYPE) {
                return false;
              }
              const data = payload.data;
              if (data.fieldId === repeaterId) {
                return false;
              }
              const type = data.isNew ? data.fieldType : this.locateField(data.fieldId ?? "")?.field.type ?? data.field?.type;
              return !!type && this.allowedInRepeater(type);
            },
            onEnter: () => zone.classList.add("is-dropping"),
            onLeave: () => zone.classList.remove("is-dropping"),
            onDrop: (session, position) => {
              zone.classList.remove("is-dropping");
              const data = session.payload.data;
              const source = data.fieldId ? list.querySelector(
                `[data-atfb-subfield="${CSS.escape(data.fieldId)}"]`
              ) : null;
              const index = insertionIndex(zone, ".atfb-subcard", position.clientY, source ?? void 0);
              if (data.isNew && data.fieldType) {
                this.addFieldToRepeater(data.fieldType, repeaterId, index);
                return;
              }
              if (data.fieldId && this.locateField(data.fieldId)) {
                this.relocateField(data.fieldId, repeaterId, index);
                return;
              }
              if (data.field) {
                this.insertFieldIntoRepeater({ ...data.field, id: "" }, repeaterId, index);
              }
            }
          })
        );
        zone.querySelectorAll(".atfb-subcard").forEach((card) => {
          const subId = card.dataset.atfbSubfield ?? "";
          card.addEventListener("click", (event) => {
            if (event.target.closest(".atfb-preview__remove")) {
              return;
            }
            event.stopPropagation();
            if (getDragManager().recentlyEndedDrag()) {
              return;
            }
            this.selectField(subId);
          });
          card.addEventListener("keydown", (event) => {
            event.stopPropagation();
            if (event.key === "Enter" || event.key === " ") {
              event.preventDefault();
              this.selectField(subId);
              return;
            }
            if (event.altKey && (event.key === "ArrowUp" || event.key === "ArrowDown")) {
              event.preventDefault();
              const now = this.locateField(subId);
              if (now) {
                this.relocateField(
                  subId,
                  repeaterId,
                  event.key === "ArrowUp" ? now.index - 1 : now.index + 1
                );
                window.requestAnimationFrame(() => {
                  this.canvas.querySelector(`[data-atfb-subfield="${CSS.escape(subId)}"]`)?.focus();
                });
              }
              return;
            }
            if (event.key === "Delete" || event.key === "Backspace") {
              event.preventDefault();
              void this.deleteField(subId);
            }
          });
          card.addEventListener("pointerdown", (event) => {
            event.stopPropagation();
            if (event.target.closest(".atfb-preview__remove")) {
              return;
            }
            const found = this.locateField(subId);
            if (!found) {
              return;
            }
            getDragManager().start({
              payload: buildPayload(
                FIELD_PAYLOAD_TYPE,
                card,
                { fieldId: subId, parentId: repeaterId, field: found.field, isNew: false },
                event
              ),
              origin: event
            });
          });
        });
      });
      return teardowns;
    }
    /* -------------------------------------------------------- Field editing */
    /** Adds a field of a type, at an index or at the end. */
    addField(type, index) {
      const field = this.buildField(type);
      if (field) {
        this.insertField(field, index);
      }
    }
    /** A brand-new field of a type, with the type's defaults and a fresh id. */
    buildField(type) {
      const definition = this.config?.fieldTypes.find((candidate) => candidate.type === type);
      if (!definition || !this.schema) {
        return void 0;
      }
      const field = {
        id: this.nextFieldId(),
        type,
        label: definition.input ? definition.label : "",
        placeholder: "",
        hint: "",
        required: false,
        width: "full",
        cssClass: "",
        default: "",
        choices: definition.choices ? [
          { label: "First choice", value: "first" },
          { label: "Second choice", value: "second" }
        ] : [],
        logic: { enabled: false, action: "show", match: "all", rules: [] },
        messages: {},
        prefill: "",
        ...definition.settings
      };
      return field;
    }
    /**
     * Whether a field type may live inside a repeater.
     *
     * The exclusions are each a real constraint, not taste: a repeater inside
     * a repeater has no addressable rows; a page break splits a *form*, not a
     * row; file uploads and signatures are wired to top-level names in the
     * submission pipeline; and a total is computed once per form, so a copy
     * per row would be a number that lies. Layout blocks are out because a
     * repeater's rows are answers, and analytics reads them as answers.
     */
    allowedInRepeater(type) {
      const definition = this.config?.fieldTypes.find((candidate) => candidate.type === type);
      if (!definition || !definition.input) {
        return false;
      }
      return !["repeater", "page_break", "file", "signature", "total"].includes(type);
    }
    /** Adds a brand-new field of a type inside a repeater. */
    addFieldToRepeater(type, repeaterId, index) {
      if (!this.allowedInRepeater(type)) {
        return;
      }
      const field = this.buildField(type);
      if (field) {
        this.insertFieldIntoRepeater(field, repeaterId, index);
      }
    }
    /** Puts a field into a repeater's sub-field list. */
    insertFieldIntoRepeater(field, repeaterId, index) {
      const repeater = this.liveField(repeaterId);
      if (!this.schema || !repeater || repeater.type !== "repeater") {
        return;
      }
      if (!field.id) {
        field.id = this.nextFieldId();
      }
      this.snapshot();
      if (!Array.isArray(repeater.fields)) {
        repeater.fields = [];
      }
      const list = repeater.fields;
      const at = index === void 0 ? list.length : Math.max(0, Math.min(index, list.length));
      list.splice(at, 0, field);
      this.selected = field.id;
      this.markDirty();
      this.renderCanvas();
      this.renderInspector();
      window.requestAnimationFrame(() => {
        this.canvas.querySelector(`[data-atfb-subfield="${CSS.escape(field.id)}"]`)?.focus();
      });
    }
    /**
     * Moves a field to wherever it was dropped — the top level, or inside a
     * repeater — from wherever it was.
     *
     * `index` counts the destination list *without* the moved field, exactly
     * as `insertionIndex()` computes it with the dragged card excluded.
     */
    relocateField(fieldId, repeaterId, index) {
      if (!this.schema) {
        return;
      }
      const found = this.locateField(fieldId);
      if (!found) {
        return;
      }
      if (!found.parent && !repeaterId) {
        this.moveField(fieldId, index);
        return;
      }
      const repeater = repeaterId ? this.liveField(repeaterId) : null;
      if (repeaterId && (!repeater || repeater.type !== "repeater" || fieldId === repeaterId)) {
        return;
      }
      if (repeater && !Array.isArray(repeater.fields)) {
        repeater.fields = [];
      }
      const targetList = repeater ? repeater.fields : this.schema.fields;
      this.snapshot();
      found.list.splice(found.index, 1);
      const at = Math.max(0, Math.min(index, targetList.length));
      targetList.splice(at, 0, found.field);
      this.selected = found.field.id;
      this.markDirty();
      this.renderCanvas();
      this.renderInspector();
    }
    /** Puts a field into the schema. */
    insertField(field, index) {
      if (!this.schema) {
        return;
      }
      if (!field.id) {
        field.id = this.nextFieldId();
      }
      this.snapshot();
      const at = index === void 0 ? this.schema.fields.length : Math.max(0, Math.min(index, this.schema.fields.length));
      this.schema.fields.splice(at, 0, field);
      this.selected = field.id;
      this.markDirty();
      this.renderCanvas();
      this.renderInspector();
      window.requestAnimationFrame(() => {
        this.canvas.querySelector(`[data-atfb-card="${CSS.escape(field.id)}"]`)?.focus();
      });
    }
    /**
     * Moves a field to an index.
     *
     * `index` counts the list *without* the moved field — see {@link fieldMove}
     * for why every caller works in that space.
     */
    moveField(fieldId, index) {
      if (!this.schema) {
        return;
      }
      const move = fieldMove(this.schema.fields, fieldId, index);
      if (!move) {
        return;
      }
      this.snapshot();
      const [field] = this.schema.fields.splice(move.from, 1);
      this.schema.fields.splice(move.to, 0, field);
      this.markDirty();
      this.renderCanvas();
    }
    /** Copies a field, id and all but the id. */
    duplicateField(fieldId) {
      if (!this.schema) {
        return;
      }
      const found = this.locateField(fieldId);
      if (!found) {
        return;
      }
      const copy = JSON.parse(JSON.stringify(found.field));
      const used = this.usedFieldIds();
      const mint = () => {
        let index = used.size + 1;
        while (used.has(`f${index}`)) {
          index++;
        }
        const id = `f${index}`;
        used.add(id);
        return id;
      };
      copy.id = mint();
      for (const sub of copy.fields ?? []) {
        sub.id = mint();
      }
      if (found.parent) {
        this.snapshot();
        found.list.splice(found.index + 1, 0, copy);
        this.selected = copy.id;
        this.markDirty();
        this.renderCanvas();
        this.renderInspector();
        return;
      }
      this.insertField(copy, found.index + 1);
    }
    /** Removes a field, wherever it lives. */
    async deleteField(fieldId) {
      if (!this.schema) {
        return;
      }
      const found = this.locateField(fieldId);
      if (!found) {
        return;
      }
      const dependents = this.schema.fields.filter(
        (field) => field.logic?.rules?.some((rule) => rule.field === fieldId)
      );
      const message = dependents.length ? `Delete this field? ${dependents.length} other field${dependents.length === 1 ? "" : "s"} use it in a condition, and those conditions will stop working.` : i18n("confirmDelete", "Delete this? It cannot be undone.");
      if (!await confirmAction(message, "Delete field")) {
        return;
      }
      this.snapshot();
      found.list.splice(found.index, 1);
      if (this.selected === fieldId) {
        this.selected = null;
      }
      this.markDirty();
      this.renderCanvas();
      this.renderInspector();
    }
    /** Selects a field and shows it in the inspector. */
    selectField(fieldId) {
      this.selected = fieldId;
      for (const card of this.canvas.querySelectorAll("[data-atfb-card]")) {
        const isSelected = card.dataset.atfbCard === fieldId;
        card.classList.toggle("is-selected", isSelected);
        card.setAttribute("aria-pressed", isSelected ? "true" : "false");
      }
      for (const card of this.canvas.querySelectorAll("[data-atfb-subfield]")) {
        card.classList.toggle("is-selected", card.dataset.atfbSubfield === fieldId);
      }
      this.logicMap?.highlight(fieldId);
      this.renderInspector();
    }
    /**
     * A field id not already in use — anywhere, repeater sub-fields included.
     *
     * Sub-fields draw from the same pool as top-level fields because a formula
     * names them as `{repeater.sub}` and a field can be dragged out of a
     * repeater onto the canvas: an id that collided the moment it surfaced
     * would make both of those ambiguous.
     */
    nextFieldId() {
      const used = this.usedFieldIds();
      let index = used.size + 1;
      while (used.has(`f${index}`)) {
        index++;
      }
      return `f${index}`;
    }
    /** Every field id in the schema, repeater sub-fields included. */
    usedFieldIds() {
      const used = /* @__PURE__ */ new Set();
      for (const field of this.schema?.fields ?? []) {
        used.add(field.id);
        for (const sub of field.fields ?? []) {
          used.add(sub.id);
        }
      }
      return used;
    }
    /**
     * An id for a new notification or confirmation, not already in use.
     *
     * Minted against the ids present, like `nextFieldId()`, rather than from
     * the list's length: after a delete-then-add, `length + 1` re-issues an id
     * the list still contains, and two entries sharing one id share one
     * disclosure panel — opening either folds and unfolds both.
     */
    nextEntryId(prefix, items) {
      const used = new Set(items.map((item) => item.id));
      let index = items.length + 1;
      while (used.has(`${prefix}${index}`)) {
        index++;
      }
      return `${prefix}${index}`;
    }
    /**
     * The settings a field type brings with it.
     *
     * Driven by {@link SETTING_CONTROLS} for everything that is a plain value, and
     * by hand for the three that are not: `default` has to match whatever the field
     * stores, `rows` means a row count on a textarea and a list of statements on a
     * Likert matrix, and `parts` is a tick box per part with the list coming from
     * the server so a filtered part cannot go missing.
     *
     * @param field      The field.
     * @param definition Its registered type.
     * @param supports   What that type declares.
     * @param update     Writes one property and repaints.
     * @return void
     */
    renderTypeSettings(field, definition, supports, update) {
      for (const flag of supports) {
        const setting = SETTING_CONTROLS[flag];
        if (!setting) {
          continue;
        }
        this.inspector.append(settingRow(field, setting, update));
        if (setting.also) {
          this.inspector.append(
            settingRow(
              field,
              { ...setting, key: setting.also.key, label: setting.also.label, hint: setting.also.hint },
              update
            )
          );
        }
      }
      if (supports.includes("rows") && "likert" !== field.type) {
        this.inspector.append(
          row(
            "Lines tall",
            numberInput(String(field.rows ?? ""), (value) => update("rows", value))
          )
        );
      }
      if (supports.includes("rows") && "likert" === field.type) {
        this.inspector.append(this.renderStatementList(field, update));
      }
      if (supports.includes("parts")) {
        this.inspector.append(this.renderPartsEditor(field, definition, update));
      }
      if (supports.includes("default") && !["files", "object"].includes(definition?.value ?? "")) {
        this.inspector.append(this.renderDefaultControl(field, definition, update));
      }
    }
    /**
     * The statements a Likert matrix asks about, one per line.
     *
     * A textarea rather than a repeating row editor: these are a handful of short
     * sentences, they are almost always pasted in from somewhere else, and one box
     * you can paste five lines into beats five boxes you have to create first.
     *
     * # The keys are the reason this is not just a list of strings
     *
     * A row is `{ key, label }`, and the *key* is what an answer is stored
     * against — `atf[f1][r2]`. So a row's key has to survive its wording being
     * changed, or correcting a typo in a statement silently detaches every answer
     * already given to it, in entries that were collected months ago and are not
     * looked at again until somebody exports them.
     *
     * Rows are therefore matched to lines by position: line three keeps row
     * three's key however it is reworded. A line added at the end mints a fresh
     * key, never one that has been used, because reusing a key would attach new
     * answers to old ones.
     *
     * Reordering the lines does move the answers, which is the one case position
     * matching gets wrong — and is also indistinguishable, from here, from
     * rewriting both statements. The alternative costs a visible id per row in the
     * box, which is a worse trade for the common case.
     *
     * @param field  The field.
     * @param update Writes one property.
     * @return The row.
     */
    renderStatementList(field, update) {
      const rows = Array.isArray(field.rows) ? field.rows : [];
      return row(
        "Statements",
        textArea(
          rows.map((statement) => statement.label ?? "").join("\n"),
          (value) => update("rows", restatement(rows, value)),
          5
        ),
        "One per line. Each becomes a row of the matrix."
      );
    }
    /**
     * Which parts of a name or an address to ask for.
     *
     * The available parts come from the server, because `atf_name_parts` and
     * `atf_address_parts` are both filterable — a builder with the list baked in
     * would offer five while the form drew seven.
     *
     * Order follows the server's, not the order they were ticked, so the tick
     * boxes read in the same order as the fields they turn on.
     *
     * @param field      The field.
     * @param definition Its registered type.
     * @param update     Writes one property.
     * @return The section.
     */
    renderPartsEditor(field, definition, update) {
      const available = definition?.parts ?? [];
      const enabled = Array.isArray(field.parts) ? field.parts : available.map((part) => part.key);
      const boxes = available.map(
        (part) => checkbox(part.label, enabled.includes(part.key), (checked) => {
          const next = available.map((candidate) => candidate.key).filter((key) => key === part.key ? checked : enabled.includes(key));
          update("parts", next.length ? next : [part.key]);
        })
      );
      return el("div", {
        class: "atfb-section",
        children: [el("h4", { class: "atfb-section__title", text: "Parts to ask for" }), ...boxes]
      });
    }
    /**
     * The answer a field starts with.
     *
     * Typed to match what the field stores: a dropdown of the options where there
     * are options, a tick box where the field is a toggle, a plain box otherwise.
     * A text box offering to set the default of a checkbox group is a control that
     * cannot say the right thing.
     *
     * Left out entirely for the types whose value is a structure — a file, a
     * signature, a repeater — where there is no single value to pre-fill.
     *
     * @param field      The field.
     * @param definition Its registered type.
     * @param update     Writes one property.
     * @return The row, or null where a default makes no sense.
     */
    renderDefaultControl(field, definition, update) {
      const hint2 = "Filled in before they start. They can change it.";
      if ("switch" === field.type || "consent" === field.type) {
        return checkbox("On by default", Boolean(field.default), (value) => update("default", value));
      }
      if (definition?.choices && (field.choices ?? []).length) {
        return row(
          "Default answer",
          select(
            String(field.default ?? ""),
            [
              { value: "", label: "Nothing chosen" },
              ...(field.choices ?? []).map((choice) => ({
                value: String(choice.value),
                label: choice.label || String(choice.value)
              }))
            ],
            (value) => update("default", value)
          ),
          hint2
        );
      }
      return row(
        "Default answer",
        textInput(String(field.default ?? ""), (value) => update("default", value)),
        hint2
      );
    }
    /** The choices editor, with drag-in image support. */
    renderChoicesEditor(field, update) {
      const choices = field.choices ?? [];
      const liveChoice = (index) => this.liveField(field.id)?.choices?.[index];
      const list = el("div", { class: "atfb-choices" });
      choices.forEach((choice, index) => {
        const rowEl = el("div", {
          class: "atfb-choice-row",
          children: [
            // The image well only exists on a field that shows
            // pictures. Everywhere else it would be a column of
            // empty boxes for a setting that does nothing.
            field.type === "image_choice" ? this.choiceImageWell(choice, index, field) : null,
            bind(textInput(choice.label, (value) => {
              const live = liveChoice(index);
              if (!live) {
                return;
              }
              const mirroring = !live.value || live.value === live.label;
              live.label = value;
              if (mirroring) {
                live.value = value;
              }
              this.markDirty();
              const parent = this.liveField(field.id);
              if (parent) {
                this.syncCanvas(parent);
              }
            }), `choice:${index}:label`),
            bind(
              textInput(
                choice.value,
                (value) => {
                  const live = liveChoice(index);
                  if (live) {
                    live.value = value;
                    this.markDirty();
                  }
                },
                "value"
              ),
              `choice:${index}:value`
            ),
            field.type === "quiz" || choice.points !== void 0 ? numberInput(String(choice.points ?? ""), (value) => {
              const live = liveChoice(index);
              if (live) {
                live.points = value === "" ? void 0 : Number(value);
                this.markDirty();
              }
            }) : numberInput(String(choice.price ?? ""), (value) => {
              const live = liveChoice(index);
              if (live) {
                live.price = value === "" ? void 0 : Number(value);
                this.markDirty();
              }
            }),
            el("button", {
              class: "atfb-card__action",
              type: "button",
              attrs: { "aria-label": `Remove ${choice.label}` },
              on: {
                click: () => {
                  const parent = this.liveField(field.id);
                  if (!parent) {
                    return;
                  }
                  (parent.choices ?? []).splice(index, 1);
                  update("choices", parent.choices);
                  this.renderInspector();
                }
              },
              children: [icon("trash")]
            })
          ]
        });
        list.append(rowEl);
      });
      return el("div", {
        class: "atfb-section",
        children: [
          el("h4", { text: "Choices" }),
          el("p", {
            class: "atfb-hint",
            text: field.type === "quiz" ? "Label, value, points." : "Label, value, and a price for calculations."
          }),
          list,
          button(
            "Add choice",
            () => {
              const parent = this.liveField(field.id);
              if (!parent) {
                return;
              }
              const next = parent.choices ?? [];
              next.push({ label: "", value: "" });
              update("choices", next);
              this.renderInspector();
            },
            "ghost",
            "plus-alt2"
          ),
          field.type === "quiz" ? row(
            "Correct answer",
            select(
              String(field.correct ?? ""),
              [
                { value: "", label: "—" },
                ...choices.map((choice) => ({ value: choice.value, label: choice.label }))
              ],
              (value) => update("correct", value)
            )
          ) : null
        ]
      });
    }
    /**
     * The image well on one choice of an image-choice field.
     *
     * A drop target for media dragged out of WP Explorer. This is the clearest
     * demonstration of why the builder is a native window: WP Explorer is a
     * different window entirely, and its file tiles ride the same
     * `wp.os.dragManager` this target registers with — so a photograph on the
     * desktop can be dropped straight onto a form's option. Across an iframe
     * boundary the two would never meet.
     *
     * The attachment id is what gets stored; the URL in the payload is used only
     * to paint the thumbnail immediately, so the well fills the moment the drop
     * lands rather than after a round trip.
     */
    choiceImageWell(choice, index, field) {
      const well = el("div", {
        class: `atfb-well${choice.image ? " has-image" : ""}`,
        attrs: {
          "data-choice": index,
          "aria-label": `Image for ${choice.label || `choice ${index + 1}`}`
        },
        children: [choice.image ? el("span", { class: "atfb-well__id", text: `#${choice.image}` }) : icon("format-image")]
      });
      const teardown = getDragManager().registerDropTarget({
        id: `atfb-well-${field.id}-${index}`,
        element: well,
        // WP Explorer has used more than one payload slug across shell
        // versions, so every spelling this plugin knows about is accepted
        // rather than betting on one.
        accept: (payload) => MEDIA_PAYLOAD_TYPES.includes(payload.type),
        onEnter: () => well.classList.add("is-dropping"),
        onLeave: () => well.classList.remove("is-dropping"),
        onDrop: (session) => {
          well.classList.remove("is-dropping");
          const data = session.payload.data;
          const id = Number(data.attachmentId ?? data.id ?? data.file?.id ?? 0);
          if (!id) {
            notify("That is not an image this field can use", "", "error");
            return;
          }
          const live = this.liveField(field.id)?.choices?.[index];
          if (!live) {
            return;
          }
          live.image = id;
          this.markDirty();
          this.renderInspector();
        }
      });
      this.teardowns.push(teardown);
      well.addEventListener("click", () => {
        const live = this.liveField(field.id)?.choices?.[index];
        if (!live?.image) {
          return;
        }
        live.image = void 0;
        this.markDirty();
        this.renderInspector();
      });
      return well;
    }
    /** Validation settings for a field. */
    renderValidationSection(field, supports, update) {
      const rows = [];
      const pairs = [];
      if (supports.includes("minlength")) {
        pairs.push(["minlength", "Minimum characters"], ["maxlength", "Maximum characters"]);
      }
      if (supports.includes("min")) {
        pairs.push(["min", "Minimum"], ["max", "Maximum"]);
      } else if (supports.includes("max")) {
        pairs.push(["max", "Highest"]);
      }
      if (supports.includes("step")) {
        pairs.push(["step", "Steps of"]);
      }
      if (supports.includes("mindate")) {
        pairs.push(["minDate", "Earliest date"], ["maxDate", "Latest date"]);
      }
      if (supports.includes("mintime")) {
        pairs.push(["minTime", "Earliest time"], ["maxTime", "Latest time"]);
      }
      for (const [key, label] of pairs) {
        rows.push(
          row(
            label,
            textInput(String(field[key] ?? ""), (value) => update(key, value))
          )
        );
      }
      if (supports.includes("pattern")) {
        rows.push(...this.renderAnswerShapeRows(field, update));
      }
      if (supports.includes("unique")) {
        rows.push(
          checkbox(
            "No two people may submit the same value",
            Boolean(field.unique),
            (value) => update("unique", value)
          )
        );
      }
      const messages = field.messages ?? {};
      if (supports.includes("required")) {
        rows.push(
          row(
            "Message when required",
            textInput(messages.required ?? "", (value) => {
              messages.required = value;
              update("messages", messages);
            }),
            "Leave empty for the default wording."
          )
        );
      }
      if (!rows.length) {
        return el("div", { class: "atfb-section is-empty" });
      }
      return this.section(`validation:${field.id}`, "Validation", rows);
    }
    /**
     * The answer-shape dropdown itself.
     *
     * The shell's `<os-select>` when its components are loaded — the same
     * control as every other inspector dropdown. It has no notion of
     * `optgroup`, so the group headings ride along as disabled options, which
     * its listbox paints muted and unpickable: the same reading an optgroup
     * heading gives. The plain admin page gets a native select with real
     * optgroups.
     *
     * @param current The selected value.
     * @param onPick  Called with the newly picked value.
     * @return The control.
     */
    buildShapePicker(current, onPick) {
      const groups = [
        { heading: null, options: [{ value: "", label: "Anything at all" }] },
        ...VALIDATION_GROUPS.map((group) => ({
          heading: group,
          options: VALIDATION_PRESETS.filter((preset) => preset.group === group).map((preset) => ({
            value: preset.slug,
            label: preset.label
          }))
        })),
        { heading: "Your own", options: [{ value: "custom", label: "A custom rule…" }] }
      ];
      if (hasComponent("os-select") && hasComponent("os-option")) {
        const host = document.createElement("os-select");
        host.setAttribute("value", current);
        host.setAttribute("aria-label", "What the answer should look like");
        host.classList.add("atfb-field");
        for (const group of groups) {
          if (group.heading) {
            const heading = document.createElement("os-option");
            heading.setAttribute("value", `__heading:${group.heading}`);
            heading.setAttribute("disabled", "");
            heading.textContent = group.heading;
            host.append(heading);
          }
          for (const option of group.options) {
            const item = document.createElement("os-option");
            item.setAttribute("value", option.value);
            item.textContent = option.label;
            host.append(item);
          }
        }
        host.addEventListener("os-pick", (event) => {
          onPick(String(event.detail?.value ?? ""));
        });
        return host;
      }
      const picker = el("select", {
        class: "atfb-input atfb-select",
        attrs: { "aria-label": "What the answer should look like" },
        on: {
          change: (event) => onPick(event.target.value)
        }
      });
      for (const group of groups) {
        const parent = group.heading ? (() => {
          const optgroup = document.createElement("optgroup");
          optgroup.label = group.heading;
          picker.append(optgroup);
          return optgroup;
        })() : picker;
        for (const option of group.options) {
          parent.append(
            el("option", {
              value: option.value,
              text: option.label,
              attrs: { selected: option.value === current }
            })
          );
        }
      }
      return picker;
    }
    /**
     * "The answer should look like…" — the validation picker.
     *
     * The pattern box asked for a regular expression, which is asking the
     * wrong person the wrong question. The picker asks the one they can
     * answer: an email address, a phone number, a ZIP code — each preset
     * enforced identically by the browser and the server. When nothing fits,
     * "A custom rule…" opens the rule builder, where the blocks are plain
     * questions and a playground judges sample answers live.
     *
     * @param field  The field being inspected.
     * @param update The inspector's writer.
     * @return The rows for the validation section.
     */
    renderAnswerShapeRows(field, update) {
      const stored = "string" === typeof field.validation ? field.validation : "";
      const current = stored || (field.pattern ? "custom" : "");
      const openEditor = () => openValidationEditor({
        root: this.root,
        field: this.liveField(field.id) ?? field,
        onSave: (result) => {
          update("validation", "custom");
          update("pattern", result.pattern);
          update("validationRecipe", JSON.stringify(result.recipe));
          const messages = { ...this.liveField(field.id)?.messages ?? {} };
          messages.invalid = result.message;
          update("messages", messages);
          this.renderInspector();
        },
        onCancel: () => this.renderInspector()
      });
      const onPick = (value) => {
        if ("custom" === value) {
          openEditor();
          return;
        }
        update("validation", value);
        update("pattern", "");
        update("validationRecipe", "");
        this.renderInspector();
      };
      const picker = this.buildShapePicker(current, onPick);
      const preset = validationPreset(current);
      const rows = [
        row(
          "The answer should be",
          picker,
          preset ? `e.g. ${preset.example}` : "Checked as they type, and again on the server."
        )
      ];
      if ("custom" === current) {
        const recipe = parseRecipe(String(field.validationRecipe ?? ""));
        const described = describeRecipe(recipe) || (field.pattern ? `Matches the expression ${String(field.pattern)}` : "No rule yet — open the builder.");
        rows.push(
          row(
            "Your rule",
            el("div", {
              class: "atfb-valrule",
              children: [
                el("p", { class: "atfb-valrule__words", text: described }),
                button("Edit the rule…", openEditor, "secondary", "edit")
              ]
            }),
            compileRecipe(recipe) || field.pattern ? "Built in the rule builder, with a playground to test it." : void 0
          )
        );
      }
      return rows;
    }
    /**
     * The rule cards, joiners and Add-rule button shared by every logic editor.
     *
     * Fields, confirmations and notifications all carry the same `Logic`
     * block. What differs is where the live copy lives and what a write must
     * repaint, so the write arrives as a callback; `exclude` keeps a field
     * from offering itself as its own condition.
     *
     * Returns the rule stack and the Add-rule button as separate elements so
     * each caller can place its own sentence between the enable switch and
     * the rules.
     */
    logicRulesEditor(logic, write, exclude = "") {
      const others = (this.schema?.fields ?? []).filter(
        (candidate) => candidate.id !== exclude && candidate.type !== "page_break"
      );
      const joiner = () => el("div", {
        class: "atfb-rule-join",
        children: [
          el("button", {
            class: "atfb-rule-join__chip",
            type: "button",
            text: "all" === logic.match ? "and" : "or",
            title: "Switch between needing every rule (and) or any one of them (or).",
            on: {
              click: () => write((live) => {
                live.match = "all" === live.match ? "any" : "all";
              }, true)
            }
          })
        ]
      });
      const ruleCard = (rule, index) => {
        const remove = el("button", {
          class: "atfb-card__action",
          type: "button",
          attrs: { "aria-label": "Remove this rule" },
          title: "Remove this rule",
          on: {
            click: () => write((live) => {
              live.rules.splice(index, 1);
            }, true)
          },
          children: [icon("trash")]
        });
        const children = [
          el("div", {
            class: "atfb-rule__top",
            children: [
              select(
                rule.field,
                others.map((candidate) => ({
                  value: candidate.id,
                  label: candidate.label || candidate.id
                })),
                (value) => (
                  // A new source question invalidates the old
                  // answer — a value picked from one field's
                  // choices means nothing against another's.
                  write((live) => {
                    const liveRule = live.rules[index];
                    if (liveRule) {
                      liveRule.field = value;
                      liveRule.value = "";
                    }
                  }, true)
                )
              ),
              remove
            ]
          }),
          select(
            rule.operator,
            Object.entries(this.config?.operators ?? {}).map(([value, label]) => ({ value, label })),
            (value) => (
              // "is empty" needs no answer and "is" does, so the
              // card's own shape depends on this — rebuild.
              write((live) => {
                const liveRule = live.rules[index];
                if (liveRule) {
                  liveRule.operator = value;
                }
              }, true)
            )
          )
        ];
        if (!VALUELESS_OPERATORS.includes(rule.operator)) {
          const source = this.schema?.fields.find((candidate) => candidate.id === rule.field);
          if (source?.choices?.length) {
            const options = source.choices.map((choice) => ({
              value: choice.value,
              label: choice.label || choice.value
            }));
            if ("" !== rule.value && !source.choices.some((choice) => choice.value === rule.value)) {
              options.unshift({ value: rule.value, label: rule.value });
            }
            children.push(
              select(
                rule.value,
                options,
                (value) => write((live) => {
                  const liveRule = live.rules[index];
                  if (liveRule) {
                    liveRule.value = value;
                  }
                })
              )
            );
          } else {
            children.push(
              textInput(
                rule.value,
                (value) => write((live) => {
                  const liveRule = live.rules[index];
                  if (liveRule) {
                    liveRule.value = value;
                  }
                }),
                "The answer to compare against"
              )
            );
          }
        }
        return el("div", { class: "atfb-rule", children });
      };
      const rules = el("div", { class: "atfb-rules" });
      logic.rules.forEach((rule, index) => {
        if (index > 0) {
          rules.append(joiner());
        }
        rules.append(ruleCard(rule, index));
      });
      const add = button(
        "Add rule",
        () => {
          write((live) => {
            live.rules.push({
              field: others[0]?.id ?? "",
              operator: "is",
              value: ""
            });
          }, true);
        },
        "ghost",
        "plus-alt2"
      );
      return [rules, add];
    }
    /**
     * The conditions section for a confirmation or a notification.
     *
     * Fields decide their own visibility through `renderLogicSection`; these
     * two decide whether they *fire* — the same `Logic` block without the
     * show/hide half, evaluated by `atf_logic_conditions_met()` on submit.
     * The copy above the confirmations list has promised "the first one whose
     * conditions match" since the list existed; this is the editor that
     * promise was missing.
     *
     * Writes mutate the object in place, the way every other control on
     * these panes does — the pane is rebuilt from the schema on structural
     * changes and the `section()` key keeps it open across the rebuild.
     */
    conditionsSection(key, noun, logic) {
      const write = (mutate, rebuild = false) => {
        mutate(logic);
        this.markDirty();
        if (rebuild) {
          this.renderCanvas();
        }
      };
      const verb = "confirmation" === noun ? "Use" : "Send";
      return this.section(
        `conditions:${key}`,
        "Conditions",
        [
          checkbox(
            `Only ${verb.toLowerCase()} this ${noun} sometimes`,
            logic.enabled,
            (value) => write((live) => {
              live.enabled = value;
            }, true)
          ),
          logic.enabled ? el("div", {
            children: [
              el("div", {
                class: "atfb-rule-head",
                children: [
                  el("span", { text: `${verb} it when` }),
                  select(
                    logic.match,
                    [
                      { value: "all", label: "all" },
                      { value: "any", label: "any" }
                    ],
                    (value) => write((live) => {
                      live.match = value;
                    }, true)
                  ),
                  el("span", { text: "of these match:" })
                ]
              }),
              ...this.logicRulesEditor(logic, write)
            ]
          }) : null
        ],
        // One that already has conditions opens showing them, for the same
        // reason a conditioned field's logic section does.
        logic.enabled
      );
    }
    /** The conditional-logic editor. */
    renderLogicSection(field) {
      const logic = field.logic;
      const liveLogic = () => this.liveField(field.id)?.logic;
      const write = (mutate, rebuild = false) => {
        const live = liveLogic();
        if (!live) {
          return;
        }
        mutate(live);
        this.markDirty();
        this.renderCanvas();
        if (rebuild) {
          this.renderInspector();
        }
      };
      const editor = this.logicRulesEditor(logic, write, field.id);
      return this.section(
        `logic:${field.id}`,
        "Conditional logic",
        [
          checkbox("Only show this field sometimes", logic.enabled, (value) => {
            write((live) => {
              live.enabled = value;
            }, true);
          }),
          logic.enabled ? el("div", {
            children: [
              el("div", {
                class: "atfb-rule-head",
                children: [
                  select(
                    logic.action,
                    [
                      { value: "show", label: "Show" },
                      { value: "hide", label: "Hide" }
                    ],
                    (value) => write((live) => {
                      live.action = value;
                    }, true)
                  ),
                  el("span", { text: "this field when" }),
                  select(
                    logic.match,
                    [
                      { value: "all", label: "all" },
                      { value: "any", label: "any" }
                    ],
                    (value) => write((live) => {
                      live.match = value;
                    }, true)
                  ),
                  el("span", { text: "of these match:" })
                ]
              }),
              ...editor
            ]
          }) : null
        ],
        // A field that already has a condition opens showing it: being told a
        // rule governs this field and not what it says is the problem the
        // whole logic display exists to solve.
        logic.enabled
      );
    }
    /* ------------------------------------------------------------ Tab panes */
    /** The canvas contents for the non-Build tabs. */
    renderTabCanvas() {
      if (!this.schema) {
        return el("div");
      }
      if (this.tab === "theme") {
        return mountThemeControls({
          themes: this.themes,
          tokens: this.config?.tokens ?? [],
          activeSlug: this.schema.settings.theme,
          overrides: this.schema.settings.themeOverrides,
          onTheme: (slug) => {
            this.schema.settings.theme = slug;
            this.markDirty();
          },
          onOverride: (token, value) => {
            if (value === "") {
              delete this.schema.settings.themeOverrides[token];
            } else {
              this.schema.settings.themeOverrides[token] = value;
            }
            this.markDirty();
          },
          // The studio clears the whole set on a theme switch, save or
          // delete. Without this the schema keeps the old theme's tuning
          // while the preview shows none of it, and the published form
          // disagrees with what the Theme tab said it would look like.
          onOverridesReplaced: (overrides) => {
            this.schema.settings.themeOverrides = { ...overrides };
            this.markDirty();
          },
          previewFor: (slug, overrides) => this.previewHtml(slug, overrides),
          onThemesChanged: (themes) => {
            this.themes = themes;
          }
        });
      }
      if (this.tab === "settings") {
        return this.renderSettingsPane();
      }
      if (this.tab === "notify") {
        return this.renderNotificationsPane();
      }
      return this.renderConfirmationsPane();
    }
    /**
     * Puts the form's own theme tokens onto the canvas.
     *
     * The previews on the canvas use the real front-end classes, so they are
     * already styled by `form.css` — but `form.css` reads everything from custom
     * properties, and without them it falls back to the built-in defaults. The
     * result would be a canvas that looks like Clean whatever theme the form is
     * set to, which is the one thing a WYSIWYG canvas must not do.
     *
     * The values come from the server's own renderer rather than being resolved
     * again here. A form's theme is a base theme plus per-form overrides plus
     * whatever `atf_theme_tokens` filters did to it, and a second resolver in
     * TypeScript would be a second answer to "what colour is this" — the same
     * twin-engine problem the logic and calculation code goes to some length to
     * avoid. One render is asked for, its `<style>` block is lifted, and its
     * selector is repointed at the canvas.
     *
     * Failure is silent on purpose: no tokens means the previews render in the
     * default theme, which is a worse-looking canvas and a working builder.
     */
    async paintCanvasTheme() {
      if (!this.form || !this.schema) {
        return;
      }
      const theme = this.schema.settings.theme;
      const signature = JSON.stringify([theme, this.schema.settings.themeOverrides]);
      if (signature === this.canvasThemeSignature) {
        return;
      }
      this.canvasThemeSignature = signature;
      try {
        const html = await this.previewHtml(theme, this.schema.settings.themeOverrides ?? {});
        this.root.classList.toggle("atfb--dark-form", /atf-is-dark/.test(html));
        const block = /<style>([\s\S]*?)<\/style>/.exec(html);
        if (!block) {
          return;
        }
        const css = block[1].replace(/#atf-[\d-]+/g, ".atfb .atfb-preview");
        this.canvasTheme.textContent = css;
        if (!this.canvasTheme.isConnected) {
          this.root.append(this.canvasTheme);
        }
      } catch {
      }
    }
    /** Renders the current schema to HTML for a preview. */
    async previewHtml(theme, overrides) {
      if (!this.form || !this.schema) {
        return "";
      }
      const schema = JSON.parse(JSON.stringify(this.schema));
      schema.settings.theme = theme;
      schema.settings.themeOverrides = overrides;
      const { html } = await api.preview(this.form.id, { schema, theme });
      return html;
    }
    /** The form's own settings. */
    renderSettingsPane() {
      const settings = this.schema.settings;
      const set = (path, value) => {
        const parts = path.split(".");
        let target = settings;
        for (let i = 0; i < parts.length - 1; i++) {
          target = target[parts[i]];
        }
        target[parts[parts.length - 1]] = value;
        this.markDirty();
      };
      return el("div", {
        class: "atfb-pane",
        children: [
          el("h2", { text: "Settings" }),
          el("section", {
            children: [
              el("h3", { text: "Submitting" }),
              row("Button label", textInput(settings.submitLabel, (value) => set("submitLabel", value))),
              checkbox("Submit without reloading the page", settings.ajax, (value) => set("ajax", value)),
              row(
                "Progress indicator",
                select(
                  settings.progressBar,
                  [
                    { value: "steps", label: "Numbered steps" },
                    { value: "bar", label: "A bar" },
                    { value: "none", label: "None" }
                  ],
                  (value) => set("progressBar", value)
                ),
                "Only shown on forms with a page break."
              )
            ]
          }),
          el("section", {
            children: [
              el("h3", { text: "Who can fill this in" }),
              checkbox("Only logged-in users", settings.requireLogin, (value) => set("requireLogin", value)),
              row(
                "Message for everyone else",
                textInput(settings.loginMessage, (value) => set("loginMessage", value))
              ),
              row(
                "Open from",
                el("input", {
                  class: "atfb-input",
                  type: "datetime-local",
                  value: settings.schedule.start,
                  on: {
                    input: (event) => set("schedule.start", event.target.value)
                  }
                })
              ),
              row(
                "Closes",
                el("input", {
                  class: "atfb-input",
                  type: "datetime-local",
                  value: settings.schedule.end,
                  on: {
                    input: (event) => set("schedule.end", event.target.value)
                  }
                })
              ),
              row(
                "Message when closed",
                textInput(settings.schedule.message, (value) => set("schedule.message", value))
              ),
              row(
                "Stop after this many submissions",
                numberInput(
                  String(settings.limit.total || ""),
                  (value) => set("limit.total", Number(value) || 0)
                ),
                "0 means no limit."
              ),
              row(
                "Submissions per logged-in user",
                numberInput(
                  String(settings.limit.perUser || ""),
                  (value) => set("limit.perUser", Number(value) || 0)
                )
              )
            ]
          }),
          el("section", {
            children: [
              el("h3", { text: "Spam" }),
              el("p", {
                class: "atfb-hint",
                text: "No captcha. Nothing here asks the visitor to prove anything."
              }),
              checkbox("Honeypot field", settings.spam.honeypot, (value) => set("spam.honeypot", value)),
              row(
                "Reject submissions faster than (seconds)",
                numberInput(
                  String(settings.spam.timeTrap),
                  (value) => set("spam.timeTrap", Number(value) || 0)
                ),
                "A human cannot fill in a form in under a second. A script can."
              ),
              row(
                "Submissions allowed per hour, per address",
                numberInput(
                  String(settings.spam.rateLimit),
                  (value) => set("spam.rateLimit", Number(value) || 0)
                )
              ),
              row(
                "Blocked words",
                textArea(settings.spam.blocklist, (value) => set("spam.blocklist", value), 4),
                "One per line."
              ),
              checkbox(
                "Use Akismet when it is installed",
                settings.spam.akismet,
                (value) => set("spam.akismet", value)
              ),
              checkbox(
                "Ask a simple sum before sending",
                settings.spam.challenge,
                (value) => set("spam.challenge", value)
              ),
              el("p", {
                class: "atfb-hint",
                text: "Only for a form under sustained attack — it is the one check here that asks the visitor to do something. Still kinder than an image captcha: it is answerable by a screen reader, and it hands no data to anyone."
              })
            ]
          }),
          el("section", {
            children: [
              el("h3", { text: "Storage and privacy" }),
              checkbox("Keep entries", settings.storage.entries, (value) => set("storage.entries", value)),
              checkbox("Record IP addresses", settings.storage.ip, (value) => set("storage.ip", value)),
              checkbox(
                "Anonymise recorded IP addresses",
                settings.storage.anonymise,
                (value) => set("storage.anonymise", value)
              ),
              row(
                "Delete entries after (days)",
                numberInput(
                  String(settings.storage.retention || ""),
                  (value) => set("storage.retention", Number(value) || 0)
                ),
                "0 keeps them forever. Anything else deletes automatically, every day."
              )
            ]
          }),
          el("section", {
            children: [
              el("h3", { text: "Analytics" }),
              checkbox(
                "Count views and starts",
                settings.analytics.enabled,
                (value) => set("analytics.enabled", value)
              ),
              checkbox(
                "Tally device, browser and system",
                settings.analytics.tech,
                (value) => set("analytics.tech", value)
              ),
              el("p", {
                class: "atfb-hint",
                text: "Counters, never people: no cookie, no fingerprint, no per-visitor record — the tallies keep coarse classes like “phone” and “Chrome”, not the visitor’s user-agent string. Nothing here needs a consent banner. Turning the first switch off stops view and start counting entirely."
              })
            ]
          }),
          el("section", {
            children: [
              el("h3", { text: "Save and continue later" }),
              checkbox(
                "Let people save a half-finished form",
                settings.resume.enabled,
                (value) => set("resume.enabled", value)
              ),
              row(
                "Keep a saved form for (days)",
                numberInput(
                  String(settings.resume.days),
                  (value) => set("resume.days", Math.max(1, Number(value) || 30))
                )
              ),
              el("p", {
                class: "atfb-hint",
                text: "The link this creates is the only key to those answers — anyone holding it can read them. For genuinely sensitive questions, require login instead."
              })
            ]
          }),
          el("section", {
            children: [
              el("h3", { text: "Archive" }),
              el("p", {
                class: "atfb-hint",
                text: "Retire the form when its moment has passed. It stops accepting responses and leaves every list, and its entries and stats go with it — nothing is deleted, and restoring it brings all of it back exactly as it was."
              }),
              button("Archive this form", () => void this.archiveCurrentForm(), "secondary", "archive")
            ]
          })
        ]
      });
    }
    /**
     * Archives the open form, entries and stats included.
     *
     * Unsaved edits are saved first: the archive keeps whatever the form is at
     * the moment it goes in, and losing the last half hour of edits because
     * archiving skipped the save would be a quiet little disaster.
     */
    async archiveCurrentForm() {
      if (!this.form) {
        return;
      }
      const title = this.form.title || "(untitled)";
      const entries = this.forms.find((form) => form.id === this.form.id)?.entries ?? 0;
      const confirmed = await confirmAction(
        `Archive “${title}”? It stops accepting responses and leaves every list — its ${entries} ${entries === 1 ? "entry" : "entries"} and its stats go with it. Nothing is deleted: restore it any time from “Start a new form”.`,
        "Archive form"
      );
      if (!confirmed) {
        return;
      }
      try {
        if (this.dirty) {
          await this.save(true);
        }
        await api.archiveForm(this.form.id);
        notify("Form archived", `${title} — restore it any time from the New form dialog.`);
        this.forms = this.forms.filter((form) => form.id !== this.form.id);
        this.form = null;
        this.schema = null;
        this.selected = null;
        this.dirty = false;
        this.history = [];
        this.historyAt = -1;
        if (this.forms.length) {
          await this.open(this.forms[0].id);
          return;
        }
        this.renderBar();
        this.renderCanvas();
        this.renderInspector();
      } catch (error) {
        notify("Could not archive the form", error instanceof Error ? error.message : "", "error");
      }
    }
    /** A one-line input that understands merge tags. */
    taggableInput(value, onChange, placeholder = "") {
      return taggable(textInput(value, onChange, placeholder), { formId: this.form.id });
    }
    /** A multi-line input that understands merge tags. */
    taggableArea(value, onChange, rows = 6) {
      return taggable(textArea(value, onChange, rows), { formId: this.form.id });
    }
    /**
     * Who the notification goes to, asked in plain language.
     *
     * Almost every notification is addressed one of three ways, and only one of
     * them has anything to do with merge tags:
     *
     * - to whoever runs the site — `{admin_email}`, and the person should never
     *   have to learn that;
     * - to a fixed address they type;
     * - back to the visitor, at whatever address they gave — which means naming
     *   one of the form's own email questions, the case where `{field:f2}` used
     *   to be the entire interface.
     *
     * So the choice is offered as a choice, the email questions are listed by
     * their labels, and the free-text box appears only for the fourth case —
     * several addresses, or a tag we have not thought of. The stored value is
     * still a plain string of tags, so nothing about the format changed and a form
     * built before this existed opens in whichever mode its value already
     * matches.
     */
    recipientControl(notification) {
      const emailFields = this.schema.fields.filter((field) => "email" === field.type);
      const modeOf = (value) => {
        if ("{admin_email}" === value.trim()) {
          return "admin";
        }
        const named = value.trim().match(/^\{field:([a-z0-9_-]+)\}$/i);
        if (named && emailFields.some((field) => field.id === named[1])) {
          return `field:${named[1]}`;
        }
        return /\{/.test(value) ? "custom" : "address";
      };
      const options = [
        { value: "admin", label: "Whoever runs this site" },
        ...emailFields.map((field) => ({
          value: `field:${field.id}`,
          label: `The person who filled it in — ${field.label || "their email answer"}`
        })),
        { value: "address", label: "A specific email address" },
        { value: "custom", label: "Something else (advanced)" }
      ];
      const mode = modeOf(notification.to);
      const detail = el("div", { class: "atfb-recipient__detail" });
      const paintDetail = (current) => {
        detail.replaceChildren();
        if ("address" === current) {
          detail.append(
            textInput(
              /\{/.test(notification.to) ? "" : notification.to,
              (value) => {
                notification.to = value;
                this.markDirty();
              },
              "name@example.com"
            )
          );
          return;
        }
        if ("custom" === current) {
          detail.append(
            this.taggableInput(
              notification.to,
              (value) => {
                notification.to = value;
                this.markDirty();
              },
              "{admin_email}, sales@example.com"
            ),
            el("p", {
              class: "atfb-row__hint",
              text: "Separate several addresses with commas."
            })
          );
        }
      };
      paintDetail(mode);
      return row(
        "Send it to",
        el("div", {
          class: "atfb-recipient",
          children: [
            select(mode, options, (value) => {
              if ("admin" === value) {
                notification.to = "{admin_email}";
              } else if (value.startsWith("field:")) {
                notification.to = `{${value}}`;
              } else if ("address" === value) {
                notification.to = /\{/.test(notification.to) ? "" : notification.to;
              }
              this.markDirty();
              paintDetail(value);
            }),
            detail
          ]
        }),
        emailFields.length ? void 0 : "Add an Email question on the Build tab to reply straight back to the visitor."
      );
    }
    /** The notification editor. */
    renderNotificationsPane() {
      const notifications = this.schema.notifications;
      const list = el("div", { class: "atfb-list" });
      if (!notifications.length) {
        list.append(
          el("p", {
            class: "atfb-hint",
            text: "With none set up, one email goes to the site administrator with every answer in it."
          })
        );
      }
      notifications.forEach((notification, index) => {
        list.append(
          this.section(
            `notification:${notification.id}`,
            notification.name || `Notification ${index + 1}`,
            [
              row(
                "Name",
                textInput(notification.name, (value) => {
                  notification.name = value;
                  this.markDirty();
                })
              ),
              this.recipientControl(notification),
              row(
                "Reply to",
                this.taggableInput(
                  notification.replyTo,
                  (value) => {
                    notification.replyTo = value;
                    this.markDirty();
                  },
                  "Leave empty to reply to you"
                ),
                "Set this to the visitor’s email address and hitting Reply answers them directly."
              ),
              row(
                "Subject",
                this.taggableInput(notification.subject, (value) => {
                  notification.subject = value;
                  this.markDirty();
                })
              ),
              row(
                "Message",
                this.taggableArea(
                  notification.message,
                  (value) => {
                    notification.message = value;
                    this.markDirty();
                  },
                  8
                )
              ),
              checkbox("Attach uploaded files", notification.attachFiles, (value) => {
                notification.attachFiles = value;
                this.markDirty();
              }),
              this.conditionsSection(notification.id, "notification", notification.logic),
              button(
                "Delete this notification",
                () => {
                  notifications.splice(index, 1);
                  this.markDirty();
                  this.renderCanvas();
                },
                "danger"
              )
            ]
          )
        );
      });
      return el("div", {
        class: "atfb-pane",
        children: [
          el("h2", { text: "Notifications" }),
          list,
          button(
            "Add a notification",
            () => {
              notifications.push({
                id: this.nextEntryId("n", notifications),
                enabled: true,
                name: "Notification",
                to: "{admin_email}",
                cc: "",
                bcc: "",
                replyTo: "",
                fromName: "",
                fromEmail: "",
                subject: "New submission",
                message: "{all_fields}",
                attachFiles: false,
                logic: { enabled: false, action: "show", match: "all", rules: [] }
              });
              this.markDirty();
              this.renderCanvas();
            },
            "primary",
            "plus-alt2"
          )
        ]
      });
    }
    /**
     * The part of a confirmation that depends on what it does.
     *
     * "Send them to a page" used to render the same free-text URL box as "Send
     * them to a URL", which made the two options identical in every visible way
     * while writing to different fields — so picking the page option and typing an
     * address stored a URL the confirmation would never read. A page is chosen
     * from the site's pages, which is the only reading of that option that means
     * anything.
     */
    confirmationDetail(confirmation) {
      if ("message" === confirmation.type) {
        return el("div", {
          children: [
            row(
              "Message",
              this.taggableArea(
                confirmation.message,
                (value) => {
                  confirmation.message = value;
                  this.markDirty();
                },
                5
              ),
              "Insert an answer to greet them by name, or show back what they sent."
            ),
            this.successDesigner(confirmation)
          ]
        });
      }
      const query2 = row(
        "Extra query parameters",
        this.taggableInput(
          confirmation.query,
          (value) => {
            confirmation.query = value;
            this.markDirty();
          },
          "ref={entry:id}&name={field:f1}"
        ),
        "Added to the address, so the page they land on can read them. Leave empty for none."
      );
      if ("redirect" === confirmation.type) {
        return el("div", {
          children: [
            row(
              "Web address",
              this.taggableInput(
                confirmation.url,
                (value) => {
                  confirmation.url = value;
                  this.markDirty();
                },
                "https://example.com/thank-you"
              ),
              "A full address, starting with https://."
            ),
            query2
          ]
        });
      }
      const holder = el("div", { class: "atfb-pagepicker" });
      const paint = (options) => {
        holder.replaceChildren(
          select(String(confirmation.pageId || 0), options, (value) => {
            confirmation.pageId = Number(value) || 0;
            this.markDirty();
          })
        );
      };
      paint([{ value: "0", label: "Loading pages…" }]);
      void api.pages().then((pages) => {
        paint([
          { value: "0", label: "Choose a page…" },
          ...pages.map((page) => ({ value: String(page.id), label: page.title }))
        ]);
      }).catch(() => {
        paint([{ value: "0", label: "Could not load the pages" }]);
      });
      return row(
        "Page",
        holder,
        "They are sent to this page after submitting. Its own content is shown, not the form’s message."
      );
    }
    /**
     * The success screen designer: what the thank-you moment looks like.
     *
     * A gallery of styles rather than a dropdown, because the styles are
     * *looks* and a look chosen from a list of words is a look chosen blind.
     * Picking one plays the real screen immediately — the renderer previewing
     * here is the same code the visitor's browser runs, so what the author
     * sees is what ships.
     */
    successDesigner(confirmation) {
      confirmation.success = normalizeSuccessScreen(confirmation.success);
      const success = confirmation.success;
      const holder = el("div", { class: "atfb-success" });
      const styles = [
        { key: "plain", label: "Plain", glyph: "¶", blurb: "Just the message." },
        { key: "simple", label: "Simple", glyph: "✓", blurb: "Check mark and a gentle fade." },
        { key: "minimal", label: "Minimalistic", glyph: "—", blurb: "Quiet type, generous space." },
        { key: "card", label: "Card", glyph: "🎫", blurb: "An elevated card that pops in." },
        { key: "check", label: "Check mark", glyph: "✔", blurb: "A big check draws itself." },
        { key: "confetti", label: "Confetti", glyph: "🎉", blurb: "Paper rains over the page." },
        { key: "fireworks", label: "Fireworks", glyph: "🎆", blurb: "The full night-sky show." },
        { key: "sparkles", label: "Sparkles", glyph: "✨", blurb: "Your emoji floats up around it." },
        { key: "typewriter", label: "Typewriter", glyph: "⌨", blurb: "Types itself out, letter by letter." }
      ];
      const paint = () => {
        const gallery = el("div", {
          class: "atfb-success__styles",
          attrs: { role: "radiogroup", "aria-label": "Success screen style" },
          children: styles.map(
            (style) => el("button", {
              class: `atfb-success__style${success.style === style.key ? " is-selected" : ""}`,
              type: "button",
              attrs: {
                role: "radio",
                "aria-checked": success.style === style.key ? "true" : "false",
                title: style.blurb
              },
              children: [
                el("span", { class: "atfb-success__style-glyph", text: style.glyph }),
                el("span", { class: "atfb-success__style-label", text: style.label })
              ],
              on: {
                click: () => {
                  success.style = style.key;
                  this.markDirty();
                  paint();
                  if ("plain" !== style.key) {
                    this.previewSuccessScreen(confirmation);
                  }
                }
              }
            })
          )
        });
        const controls = [];
        if ("plain" !== success.style) {
          controls.push(
            row(
              "Heading",
              this.taggableInput(
                success.title,
                (value) => {
                  success.title = value;
                  this.markDirty();
                },
                "Thank you, {field:name}!"
              ),
              "Shown above the message. Leave empty for none."
            )
          );
          if (["simple", "card", "confetti", "fireworks", "sparkles"].includes(success.style)) {
            controls.push(
              row(
                "Emoji",
                textInput(success.icon, (value) => {
                  success.icon = value;
                  this.markDirty();
                }, SUCCESS_STYLE_ICONS[success.style] || "🎉"),
                "sparkles" === success.style ? "Also the particle that floats up — try 🎈 or ❤️." : "The badge above the heading."
              )
            );
          }
          controls.push(this.successAccentRow(success));
          if (["confetti", "fireworks", "sparkles"].includes(success.style)) {
            controls.push(
              row(
                "Intensity",
                select(
                  success.intensity,
                  [
                    { value: "low", label: "Calm" },
                    { value: "medium", label: "Festive" },
                    { value: "high", label: "Over the top" }
                  ],
                  (value) => {
                    success.intensity = value;
                    this.markDirty();
                  }
                )
              )
            );
          }
          controls.push(
            checkbox("Offer to fill it in again", success.showButton, (value) => {
              success.showButton = value;
              this.markDirty();
              paint();
            })
          );
          if (success.showButton) {
            controls.push(
              row(
                "Button label",
                textInput(success.buttonLabel, (value) => {
                  success.buttonLabel = value;
                  this.markDirty();
                }, "Fill it in again")
              )
            );
          }
        }
        holder.replaceChildren(
          el("div", {
            class: "atfb-success__head",
            children: [
              el("h4", { text: "Success screen" }),
              button("Preview", () => this.previewSuccessScreen(confirmation), "ghost", "controls-play")
            ]
          }),
          gallery,
          ...controls
        );
      };
      paint();
      return holder;
    }
    /** The accent picker: a colour, or the theme's own accent by default. */
    successAccentRow(success) {
      const input = el("input", {
        class: "atfb-success__accent",
        attrs: { type: "color", "aria-label": "Accent colour" }
      });
      input.value = success.accent || "#3858e9";
      const reset = button("Use the theme accent", () => {
        success.accent = "";
        input.value = "#3858e9";
        reset.style.display = "none";
        this.markDirty();
      }, "ghost");
      reset.style.display = success.accent ? "" : "none";
      input.addEventListener("input", () => {
        success.accent = input.value;
        reset.style.display = "";
        this.markDirty();
      });
      return row(
        "Accent",
        el("div", { class: "atfb-success__accent-row", children: [input, reset] }),
        "Recolours the screen; the theme decides when left alone."
      );
    }
    /**
     * Plays the success screen over the builder, exactly as it will ship.
     *
     * The stage wears the canvas's own preview classes so the form's real theme
     * tokens reach it, and the screen inside is built by the front-end renderer
     * itself. Escape, the backdrop or the close button put the builder back.
     */
    previewSuccessScreen(confirmation) {
      const message = confirmation.message || "Thank you. Your submission has been received.";
      let cleanup = () => {
      };
      const overlay = el("div", {
        class: "atfb-success-preview",
        attrs: { role: "dialog", "aria-label": "Success screen preview", "aria-modal": "true" }
      });
      const dismiss = () => {
        cleanup();
        overlay.remove();
        document.removeEventListener("keydown", onKey);
      };
      const onKey = (event) => {
        if ("Escape" === event.key) {
          event.stopPropagation();
          dismiss();
        }
      };
      const screen = renderSuccessScreen(message, confirmation.success, dismiss);
      const stage = el("div", { class: "atfb-success-preview__stage atfb-preview atf-form", children: [screen] });
      const play = () => {
        cleanup();
        cleanup = playSuccessEffects(screen, confirmation.success);
      };
      overlay.append(
        stage,
        el("div", {
          class: "atfb-success-preview__bar",
          children: [
            button("Replay", play, "secondary", "controls-repeat"),
            button("Close", dismiss, "primary")
          ]
        })
      );
      overlay.addEventListener("click", (event) => {
        if (event.target === overlay) {
          dismiss();
        }
      });
      document.addEventListener("keydown", onKey);
      this.root.append(overlay);
      window.requestAnimationFrame(play);
    }
    /** The confirmation editor. */
    renderConfirmationsPane() {
      const confirmations = this.schema.confirmations;
      const list = el("div", { class: "atfb-list" });
      if (!confirmations.length) {
        list.append(
          el("p", { class: "atfb-hint", text: "With none set up, the form says thank you and stops." })
        );
      }
      confirmations.forEach((confirmation, index) => {
        const detail = el("div", { class: "atfb-confirm__detail" });
        const paintDetail = () => {
          detail.replaceChildren(this.confirmationDetail(confirmation));
        };
        paintDetail();
        list.append(
          this.section(
            `confirmation:${confirmation.id}`,
            confirmation.name || `Confirmation ${index + 1}`,
            [
              row(
                "Name",
                textInput(confirmation.name, (value) => {
                  confirmation.name = value;
                  this.markDirty();
                })
              ),
              row(
                "What happens",
                select(
                  confirmation.type,
                  [
                    { value: "message", label: "Show a message" },
                    { value: "redirect", label: "Send them to a URL" },
                    { value: "page", label: "Send them to a page" }
                  ],
                  (value) => {
                    confirmation.type = value;
                    this.markDirty();
                    paintDetail();
                  }
                )
              ),
              detail,
              this.conditionsSection(confirmation.id, "confirmation", confirmation.logic),
              button(
                "Delete this confirmation",
                () => {
                  confirmations.splice(index, 1);
                  this.markDirty();
                  this.renderCanvas();
                },
                "danger"
              )
            ]
          )
        );
      });
      return el("div", {
        class: "atfb-pane",
        children: [
          el("h2", { text: "Confirmations" }),
          el("p", {
            class: "atfb-hint",
            text: "The first one whose conditions match is the one they see."
          }),
          list,
          button(
            "Add a confirmation",
            () => {
              confirmations.push({
                id: this.nextEntryId("c", confirmations),
                enabled: true,
                name: "Confirmation",
                type: "message",
                message: "Thank you. Your submission has been received.",
                url: "",
                pageId: 0,
                query: "",
                success: defaultSuccessScreen(),
                logic: { enabled: false, action: "show", match: "all", rules: [] }
              });
              this.markDirty();
              this.renderCanvas();
            },
            "primary",
            "plus-alt2"
          )
        ]
      });
    }
    /**
     * Opens the form's real front-end preview.
     *
     * The same code path the title bar's eye takes, so the toolbar button and
     * the eye cannot drift apart. Inside OpenStation it opens a window paired
     * with this one; on a plain admin page it opens a tab.
     */
    async preview() {
      await openPreview({
        current: () => this.form ? { id: this.form.id, title: this.form.title, previewUrl: this.form.previewUrl } : null,
        isDirty: () => this.dirty,
        save: () => this.save(true)
      });
    }
  }
  let mounted = null;
  let mountedRoot = null;
  document.addEventListener("atf-open-form", (event) => {
    const formId = Number(event.detail?.formId ?? 0);
    if (!formId) {
      return;
    }
    void mounted?.openFormById(formId);
  });
  function mountBuilder() {
    if (mountedRoot?.isConnected) {
      return;
    }
    if (mounted) {
      mounted.destroy();
      mounted = null;
      mountedRoot = null;
    }
    const root = document.querySelector("[data-atfb-root]:not([data-atfb-mounted])");
    if (!root) {
      return;
    }
    root.dataset.atfbMounted = "1";
    mountedRoot = root;
    pinWindowBodyScroll(root);
    void whenComponents().then(() => {
      if (!root.isConnected) {
        return;
      }
      mounted = new Builder(root);
      void mounted.start();
    });
  }
  function boot() {
    mountBuilder();
    handOffToWindow();
  }
  watchHandoffButton();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
  document.addEventListener("os-window-content-loaded", mountBuilder);
  exports.Builder = Builder;
  exports.SETTINGS_HANDLED_ELSEWHERE = SETTINGS_HANDLED_ELSEWHERE;
  exports.SETTING_CONTROLS = SETTING_CONTROLS;
  exports.fieldMove = fieldMove;
  exports.mountBuilder = mountBuilder;
  exports.restatement = restatement;
  Object.defineProperty(exports, Symbol.toStringTag, { value: "Module" });
  return exports;
}({});
