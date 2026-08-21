var allTerrainFormsAnalytics = function(exports) {
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
    listForms: () => get("/forms"),
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
  function takeFormFor(surface) {
    try {
      const value = window.sessionStorage.getItem(requestedFormKeyFor(surface));
      window.sessionStorage.removeItem(requestedFormKeyFor(surface));
      return Number(value) || 0;
    } catch {
      return 0;
    }
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
  function button(label, onClick, variant = "secondary", iconSlug) {
    const children = [null, el("span", { text: label })];
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
  const NPS_BANDS = [
    { key: "detractors", label: "Detractors", hint: "0–6" },
    { key: "passives", label: "Passives", hint: "7–8" },
    { key: "promoters", label: "Promoters", hint: "9–10" }
  ];
  const MAX_HISTOGRAM_BUCKETS = 50;
  function histogramBuckets(numbers) {
    const lo = Math.floor(numbers.min);
    const hi = Math.ceil(numbers.max);
    if (!Number.isFinite(lo) || !Number.isFinite(hi) || hi < lo) {
      return [];
    }
    const span = hi - lo + 1;
    const size = Math.max(1, Math.ceil(span / MAX_HISTOGRAM_BUCKETS));
    const total = Math.ceil(span / size);
    const indexOf = (value) => Math.min(total - 1, Math.max(0, Math.floor((value - lo) / size)));
    const buckets = [];
    for (let index = 0; index < total; index++) {
      const start = lo + index * size;
      const end = Math.min(hi, start + size - 1);
      buckets.push({
        label: size === 1 ? String(start) : `${start}–${end}`,
        count: 0,
        holdsMean: false
      });
    }
    for (const [key, count] of Object.entries(numbers.distribution)) {
      const value = Math.round(Number(key));
      if (!Number.isFinite(value)) {
        continue;
      }
      buckets[indexOf(value)].count += count;
    }
    const mean = Math.round(numbers.mean);
    if (Number.isFinite(mean)) {
      buckets[indexOf(mean)].holdsMean = true;
    }
    return buckets;
  }
  class AnalyticsWindow {
    constructor(root) {
      this.forms = [];
      this.formId = 0;
      this.dimension = "";
      this.report = null;
      this.demo = null;
      this.demoAvailable = false;
      this.bar = root.querySelector("[data-atfa-bar]") ?? el("div");
      this.body = root.querySelector("[data-atfa-body]") ?? el("div");
    }
    /** Loads the form list and draws the first report. */
    async start() {
      await whenComponents();
      try {
        this.forms = await api.listForms();
      } catch {
        this.fail("Could not load your forms.");
        return;
      }
      if (!this.forms.length) {
        this.fail("There are no forms yet. Build one, collect a few submissions, and this fills in.");
        return;
      }
      if (config?.devMode && config?.canEdit) {
        try {
          this.demo = await api.demoStatus();
          this.demoAvailable = true;
        } catch {
          this.demoAvailable = false;
        }
      }
      const requested = takeFormFor("analytics");
      if (requested && this.forms.some((form) => form.id === requested)) {
        this.formId = requested;
      } else if (!this.formId) {
        this.formId = this.forms[0].id;
      }
      await this.load();
    }
    /** Fetches the report for the current form and redraws. */
    /**
     * Deep-link entry: report on one form.
     *
     * @param formId The form.
     */
    async showForm(formId) {
      this.formId = formId;
      this.dimension = "";
      await this.load();
    }
    async load() {
      try {
        this.report = await api.analytics(this.formId, this.dimension);
      } catch {
        this.fail("Could not load that report.");
        return;
      }
      this.dimension = this.report.breakdown?.id ?? "";
      this.renderBar();
      this.render();
    }
    /** Says why there is nothing to show. */
    fail(message) {
      clear(this.bar);
      clear(this.body);
      this.body.append(el("p", { class: "atfa__empty", text: message }));
    }
    /** The form picker, the grouping picker, and the headline counts. */
    renderBar() {
      clear(this.bar);
      this.bar.append(
        select(
          String(this.formId),
          this.forms.map((form) => ({ value: String(form.id), label: form.title || `#${form.id}` })),
          (value) => {
            this.formId = Number(value);
            this.dimension = "";
            void this.load();
          }
        )
      );
      const dimensions = this.report?.dimensions ?? [];
      if (dimensions.length > 1) {
        this.bar.append(
          el("span", { class: "atfa__bar-label", text: "Group by" }),
          select(
            this.dimension,
            dimensions.map((item) => ({ value: item.id, label: item.label })),
            (value) => {
              this.dimension = value;
              void this.load();
            }
          )
        );
      }
    }
    /** Draws the whole report. */
    render() {
      const report = this.report;
      clear(this.body);
      if (!report) {
        return;
      }
      this.body.append(this.kpis(report));
      if (report.submissions < 1 && report.sampled < 1) {
        this.body.append(
          el("p", {
            class: "atfa__empty",
            text: "No submissions yet. Everything below fills in as they arrive."
          })
        );
      } else {
        this.body.append(this.timeline(report));
        for (const field of report.fields) {
          if (field.nps) {
            this.body.append(this.npsPanel(field));
          }
        }
        if (report.breakdown && report.breakdown.groups.length) {
          this.body.append(this.breakdownPanel(report.breakdown));
        }
        this.body.append(this.questions(report));
      }
      if (this.demoAvailable) {
        this.body.append(this.demoPanel());
      }
    }
    /** The headline numbers. */
    kpis(report) {
      const cards = [
        { label: "Submissions", value: String(report.submissions) },
        { label: "Views", value: String(report.views) },
        { label: "Conversion", value: `${report.conversion}%`, hint: "of people who saw it" },
        { label: "Completion", value: `${report.completion}%`, hint: "of people who started" },
        { label: "Unread", value: String(report.unread) },
        { label: "Spam", value: String(report.spam) }
      ];
      return el("div", {
        class: "atfa-kpis",
        children: cards.map(
          (card) => el("div", {
            class: "atfa-kpi",
            children: [
              el("span", { class: "atfa-kpi__value", text: card.value }),
              el("span", { class: "atfa-kpi__label", text: card.label }),
              card.hint ? el("span", { class: "atfa-kpi__hint", text: card.hint }) : null
            ]
          })
        )
      });
    }
    /**
     * Submissions per day.
     *
     * Drawn as one element per day rather than an SVG path, so a day can carry its
     * own tooltip and the whole thing stays readable when the window is narrow.
     * The server sends every day in the window including the empty ones, which is
     * what keeps a quiet fortnight looking quiet instead of closing up.
     */
    timeline(report) {
      const days = report.timeline;
      const peak = days.reduce((most, day) => Math.max(most, day.count), 0);
      const total = days.reduce((sum, day) => sum + day.count, 0);
      return this.panel(
        "When they answered",
        `${total} in the last ${days.length} days · busiest day ${peak}`,
        [
          el("div", {
            class: "atfa-timeline",
            children: days.map(
              (day) => el("div", {
                class: `atfa-timeline__day${day.count ? "" : " is-empty"}`,
                attrs: {
                  // A percentage rather than a pixel height, so the chart
                  // scales with the window instead of being redrawn on
                  // every resize.
                  style: `height:${peak ? Math.max(2, day.count / peak * 100) : 0}%`,
                  title: `${day.date}: ${day.count}`
                }
              })
            )
          }),
          el("div", {
            class: "atfa-timeline__axis",
            children: [
              el("span", { text: days[0]?.date ?? "" }),
              el("span", { text: days[days.length - 1]?.date ?? "" })
            ]
          })
        ]
      );
    }
    /**
     * The NPS panel.
     *
     * The three bands are drawn to scale as one bar, which is the only honest way
     * to show a score that is a *difference* between two of them: the passives in
     * the middle contribute nothing to the number, and a chart that omits them
     * makes the score look like a share of something.
     */
    npsPanel(field) {
      const nps = field.nps;
      if (!nps) {
        return el("div");
      }
      const share = (count) => nps.responses ? count / nps.responses * 100 : 0;
      return this.panel(field.label, `${nps.responses} answers · Net Promoter Score`, [
        el("div", {
          class: "atfa-nps",
          children: [
            el("div", {
              class: `atfa-nps__score is-${nps.score >= 50 ? "great" : nps.score >= 0 ? "ok" : "poor"}`,
              children: [
                el("span", {
                  class: "atfa-nps__number",
                  // Signed, always. An NPS of 6 and an NPS of -6 are very
                  // different results and differ by one character.
                  text: `${nps.score > 0 ? "+" : ""}${nps.score}`
                }),
                el("span", { class: "atfa-nps__caption", text: "NPS" })
              ]
            }),
            el("div", {
              class: "atfa-nps__detail",
              children: [
                el("div", {
                  class: "atfa-nps__bar",
                  children: NPS_BANDS.map(
                    (band) => el("div", {
                      class: `atfa-nps__band is-${band.key}`,
                      attrs: {
                        style: `width:${share(nps[band.key])}%`,
                        title: `${band.label} (${band.hint}): ${nps[band.key]}`
                      }
                    })
                  )
                }),
                el("div", {
                  class: "atfa-nps__legend",
                  children: NPS_BANDS.map(
                    (band) => el("span", {
                      class: `atfa-nps__key is-${band.key}`,
                      children: [
                        el("strong", { text: String(nps[band.key]) }),
                        el("span", { text: ` ${band.label} ` }),
                        el("small", { text: band.hint })
                      ]
                    })
                  )
                })
              ]
            })
          ]
        })
      ]);
    }
    /**
     * The cross-tab.
     *
     * "The average score is 7.5" is a fact nobody can act on. "Support scores 5.8
     * and everyone else is above 7" is a fact with an obvious next step, and this
     * is the panel that turns the first into the second.
     *
     * A table, genuinely — rows of numbers compared across a shared scale is what
     * a table is for, and it reads correctly in a screen reader without any of the
     * describing an SVG would need.
     */
    breakdownPanel(breakdown) {
      const metrics = breakdown.groups[0]?.metrics ?? [];
      if (!metrics.length) {
        return el("div");
      }
      const ceilings = /* @__PURE__ */ new Map();
      for (const group of breakdown.groups) {
        for (const metric of group.metrics) {
          ceilings.set(metric.id, Math.max(ceilings.get(metric.id) ?? 0, metric.mean));
        }
      }
      const head = el("tr", {
        children: [
          el("th", { text: breakdown.label, attrs: { scope: "col" } }),
          el("th", { text: "Answers", attrs: { scope: "col" } }),
          ...metrics.map((metric) => el("th", { text: metric.label, attrs: { scope: "col" } }))
        ]
      });
      const rows = breakdown.groups.map(
        (group) => el("tr", {
          children: [
            el("th", { class: "atfa-table__label", text: group.label, attrs: { scope: "row" } }),
            el("td", { class: "atfa-table__count", text: String(group.count) }),
            ...group.metrics.map(
              (metric) => el("td", {
                children: [
                  el("div", {
                    class: "atfa-meter",
                    children: [
                      el("div", {
                        class: "atfa-meter__fill",
                        attrs: {
                          style: `width:${metric.mean / (ceilings.get(metric.id) || 1) * 100}%`
                        }
                      })
                    ]
                  }),
                  el("span", {
                    class: "atfa-meter__value",
                    text: null !== metric.nps ? `${metric.mean.toFixed(1)} · NPS ${metric.nps > 0 ? "+" : ""}${metric.nps}` : metric.mean.toFixed(2)
                  })
                ]
              })
            )
          ]
        })
      );
      return this.panel(`Broken down by ${breakdown.label.toLowerCase()}`, "", [
        el("table", {
          class: "atfa-table",
          children: [el("thead", { children: [head] }), el("tbody", { children: rows })]
        })
      ]);
    }
    /** One panel per question. */
    questions(report) {
      return el("div", {
        class: "atfa-questions",
        children: report.fields.map((field) => this.question(field, report.sampled))
      });
    }
    /** One question. */
    question(field, sampled) {
      const parts = [];
      if (field.choices.length) {
        const ceiling = field.choices.reduce((most, choice) => Math.max(most, choice.count), 0);
        parts.push(
          el("ul", {
            class: "atfa-bars",
            children: field.choices.map(
              (choice) => el("li", {
                class: "atfa-bars__row",
                children: [
                  el("span", { class: "atfa-bars__label", text: choice.label }),
                  el("div", {
                    class: "atfa-meter",
                    children: [
                      el("div", {
                        class: "atfa-meter__fill",
                        attrs: { style: `width:${ceiling ? choice.count / ceiling * 100 : 0}%` }
                      })
                    ]
                  }),
                  el("span", {
                    class: "atfa-bars__value",
                    text: `${choice.count}  ${field.answered ? Math.round(choice.count / field.answered * 100) : 0}%`
                  })
                ]
              })
            )
          })
        );
      }
      if (field.numbers) {
        parts.push(this.histogram(field));
      }
      if (!parts.length) {
        parts.push(
          el("p", {
            class: "atfa-question__note",
            // A repeater sub-field is answered per row, not per
            // person, and says so through `of` and `unit`.
            text: `${field.answered} of ${field.of ?? sampled} ${field.unit ?? "people"} answered this.`
          })
        );
      }
      return this.panel(field.label, this.questionSummary(field, sampled), parts);
    }
    /** The line under a question's title. */
    questionSummary(field, sampled) {
      const bits = [`${field.rate}% answered`];
      if (field.numbers) {
        bits.push(`mean ${field.numbers.mean}`, `median ${field.numbers.median}`);
      }
      bits.push(`${field.answered}/${field.of ?? sampled}`);
      return bits.join(" · ");
    }
    /**
     * A numeric question's distribution.
     *
     * The bar the mean falls in is marked, because the interesting cases are the
     * ones where it falls in a bar almost nobody chose — which is exactly when
     * quoting the mean on its own would have misled.
     */
    histogram(field) {
      const numbers = field.numbers;
      if (!numbers) {
        return el("div");
      }
      const buckets = histogramBuckets(numbers);
      const peak = buckets.reduce((most, bucket) => Math.max(most, bucket.count), 0);
      return el("div", {
        class: "atfa-hist",
        children: buckets.map(
          (bucket) => el("div", {
            class: `atfa-hist__col${bucket.holdsMean ? " is-mean" : ""}`,
            attrs: { title: `${bucket.label}: ${bucket.count}` },
            children: [
              el("span", { class: "atfa-hist__count", text: bucket.count ? String(bucket.count) : "" }),
              el("div", {
                class: "atfa-hist__bar",
                attrs: { style: `height:${peak ? Math.max(2, bucket.count / peak * 100) : 0}%` }
              }),
              el("span", { class: "atfa-hist__tick", text: bucket.label })
            ]
          })
        )
      });
    }
    /**
     * The developer panel.
     *
     * Deliberately at the bottom, deliberately labelled, and deliberately not
     * present at all unless developer mode is on. It writes several hundred
     * entries into whatever database this happens to be, which is a fine thing to
     * do on a laptop and a terrible one to do by accident.
     */
    demoPanel() {
      const status = this.demo;
      const progress = el("p", { class: "atfa-demo__status", text: this.demoSummary(status) });
      const seed = button("Fill with demo data", async () => {
        await this.seedAll(progress);
      });
      const remove = button("Remove demo data", async () => {
        if (!await confirmAction("Delete the demo survey and every entry it generated?", "Remove demo data")) {
          return;
        }
        progress.textContent = "Removing…";
        try {
          const result = await api.demoRemove();
          notify("Demo data removed", `${result.entries} entries and ${result.forms} form.`);
        } catch {
          notify("Could not remove the demo data", "", "error");
        }
        await this.refreshDemo(progress);
      });
      return el("div", {
        class: "atfa-demo",
        attrs: { "data-atfa-demo": "" },
        children: [
          el("h3", { class: "atfa-demo__title", text: "Developer" }),
          el("p", {
            class: "atfa-demo__note",
            text: "Generates a team pulse survey and several hundred submissions through the real submission pipeline, so this report has something to report on. Everything it makes is tagged, and removing it deletes exactly that and nothing else."
          }),
          progress,
          el("div", { class: "atfa-demo__actions", children: [seed, remove] })
        ]
      });
    }
    /** What the developer panel says about the current state. */
    demoSummary(status) {
      if (!status || !status.formId) {
        return "No demo data on this site.";
      }
      return `${status.title}: ${status.entries} of ${status.target} submissions.`;
    }
    /**
     * Generates every remaining chunk.
     *
     * A loop of small requests rather than one big one. Each chunk is a few dozen
     * passes through the whole submission pipeline, and the server caps what it
     * will do per call — so this asks repeatedly, which is also what gives the
     * count something true to report while it runs.
     */
    async seedAll(progress) {
      let guard = 0;
      let failures = 0;
      for (; ; ) {
        try {
          const status = await api.demoSeed();
          this.demo = status;
          failures = 0;
          progress.textContent = `Generating… ${status.entries} of ${status.target}`;
          if (status.remaining < 1) {
            notify("Demo data ready", `${status.entries} submissions.`);
            break;
          }
        } catch {
          failures += 1;
          if (failures >= 3) {
            progress.textContent = "Stopped early. Press it again to carry on from here.";
            notify("Could not finish the demo data", "Some submissions were made. Try again to continue.", "error");
            break;
          }
        }
        guard += 1;
        if (guard > 200) {
          break;
        }
      }
      await this.refreshDemo(progress);
      if (this.demo?.formId) {
        try {
          this.forms = await api.listForms();
        } catch {
        }
        this.formId = this.demo.formId;
        this.dimension = "";
      }
      await this.load();
    }
    /** Re-reads the demo status and redraws the line that shows it. */
    async refreshDemo(progress) {
      try {
        this.demo = await api.demoStatus();
      } catch {
        this.demo = null;
      }
      progress.textContent = this.demoSummary(this.demo);
    }
    /** A titled block. */
    panel(title, note, children) {
      return el("section", {
        class: "atfa-panel",
        children: [
          el("header", {
            class: "atfa-panel__head",
            children: [
              el("h3", { class: "atfa-panel__title", text: title }),
              note ? el("p", { class: "atfa-panel__note", text: note }) : null
            ]
          }),
          ...children
        ]
      });
    }
    /** Scrolls the developer panel into view. */
    revealDemo() {
      this.body.querySelector("[data-atfa-demo]")?.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }
  let mounted = null;
  document.addEventListener("atf-open-analytics-form", (event) => {
    const formId = Number(event.detail?.formId ?? 0);
    if (!formId) {
      return;
    }
    void mounted?.showForm(formId);
  });
  function mountAnalytics() {
    const root = document.querySelector("[data-atfa-root]");
    if (!root || root.dataset.atfaMounted) {
      return;
    }
    root.dataset.atfaMounted = "1";
    pinWindowBodyScroll(root);
    mounted = new AnalyticsWindow(root);
    void mounted.start();
  }
  let wantsDemo = false;
  document.addEventListener("atf-open-demo-panel", () => {
    wantsDemo = true;
    window.setTimeout(() => {
      mounted?.revealDemo();
      wantsDemo = false;
    }, 600);
  });
  document.addEventListener("os-window-content-loaded", () => {
    mountAnalytics();
    if (wantsDemo) {
      window.setTimeout(() => mounted?.revealDemo(), 400);
    }
  });
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mountAnalytics);
  } else {
    mountAnalytics();
  }
  exports.MAX_HISTOGRAM_BUCKETS = MAX_HISTOGRAM_BUCKETS;
  exports.histogramBuckets = histogramBuckets;
  exports.mountAnalytics = mountAnalytics;
  Object.defineProperty(exports, Symbol.toStringTag, { value: "Module" });
  return exports;
}({});
