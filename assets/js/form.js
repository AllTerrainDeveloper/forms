var allTerrainFormsFront = function(exports) {
  "use strict";
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
      (row) => numericValue(row[subId] ?? "", sub)
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
    for (const row of value) {
      if (!row || typeof row !== "object" || Array.isArray(row)) {
        continue;
      }
      const filled = Object.values(row).some(
        (item) => item !== "" && item !== null && item !== void 0 && item !== false && !(Array.isArray(item) && !item.length)
      );
      if (filled) {
        rows.push(row);
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
  function applyCalculations(fields, values) {
    const next = { ...values };
    for (const field of fields) {
      const formula = field.formula;
      if (!formula) {
        continue;
      }
      const result = calculate(formula, next, fields);
      if (result === null) {
        next[field.id] = "";
        continue;
      }
      const decimals = typeof field.decimals === "number" ? field.decimals : 2;
      const factor = Math.pow(10, decimals);
      next[field.id] = Math.round(result * factor) / factor;
    }
    return next;
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
    const icon = success.icon || SUCCESS_STYLE_ICONS[success.style];
    if (success.style === "check") {
      inner.insertAdjacentHTML(
        "beforeend",
        '<svg class="atf-success__check" viewBox="0 0 52 52" aria-hidden="true"><circle class="atf-success__check-ring" cx="26" cy="26" r="24" fill="none" /><path class="atf-success__check-mark" fill="none" d="M14 27l8 8 16-17" /></svg>'
      );
    } else if (icon) {
      const glyph = document.createElement("span");
      glyph.className = "atf-success__icon";
      glyph.setAttribute("aria-hidden", "true");
      glyph.textContent = icon;
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
      again.addEventListener("click", () => window.location.reload());
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
  function conditionsMet(logic, values) {
    if (!logic?.enabled || !logic.rules?.length) {
      return true;
    }
    const match = logic.match === "any" ? "any" : "all";
    for (const rule of logic.rules) {
      const value = Object.prototype.hasOwnProperty.call(values, rule.field) ? values[rule.field] : null;
      const passed = rulePasses(rule, value);
      if (match === "any" && passed) {
        return true;
      }
      if (match === "all" && !passed) {
        return false;
      }
    }
    return match === "all";
  }
  function logicPasses(logic, values) {
    if (!logic?.enabled) {
      return true;
    }
    const met = conditionsMet(logic, values);
    return logic.action === "hide" ? !met : met;
  }
  function rulePasses(rule, value) {
    const operator = rule.operator ?? "is";
    const expected = rule.value ?? "";
    if (Array.isArray(value)) {
      const filled = value.filter((item) => item !== "" && item !== null && item !== void 0);
      if (operator === "empty") {
        return filled.length === 0;
      }
      if (operator === "not_empty") {
        return filled.length > 0;
      }
      if (operator === "is_not" || operator === "not_contains") {
        return value.every((item) => compare(operator, item, expected));
      }
      return value.some((item) => compare(operator, item, expected));
    }
    return compare(operator, value, expected);
  }
  function compare(operator, actual, expected) {
    if (typeof actual === "boolean") {
      actual = actual ? "1" : "";
    }
    const left = actual === null || actual === void 0 ? "" : String(actual);
    switch (operator) {
      case "is":
        return left === expected;
      case "is_not":
        return left !== expected;
      case "contains":
        return expected !== "" && left.toLowerCase().includes(expected.toLowerCase());
      case "not_contains":
        return expected === "" || !left.toLowerCase().includes(expected.toLowerCase());
      case "starts_with":
        return expected !== "" && left.toLowerCase().startsWith(expected.toLowerCase());
      case "ends_with":
        return expected !== "" && left.toLowerCase().endsWith(expected.toLowerCase());
      case "empty":
        return left.trim() === "";
      case "not_empty":
        return left.trim() !== "";
      case "greater":
      case "less":
      case "greater_equal":
      case "less_equal": {
        if (!isNumeric(left) || !isNumeric(expected)) {
          return false;
        }
        const a = parseFloat(left);
        const b = parseFloat(expected);
        if (operator === "greater") {
          return a > b;
        }
        if (operator === "less") {
          return a < b;
        }
        return operator === "greater_equal" ? a >= b : a <= b;
      }
      default:
        return false;
    }
  }
  function visibleFields(fields, values) {
    const visible = {};
    for (const field of fields) {
      visible[field.id] = true;
    }
    const MAX_PASSES = 10;
    for (let pass = 0; pass < MAX_PASSES; pass++) {
      let changed = false;
      const effective = {};
      for (const field of fields) {
        effective[field.id] = visible[field.id] && Object.prototype.hasOwnProperty.call(values, field.id) ? values[field.id] : null;
      }
      for (const field of fields) {
        const expected = logicPasses(field.logic, effective);
        if (expected !== visible[field.id]) {
          visible[field.id] = expected;
          changed = true;
        }
      }
      if (!changed) {
        break;
      }
    }
    return visible;
  }
  function isNumeric(value) {
    return value.trim() !== "" && !Number.isNaN(Number(value));
  }
  function isEmptyValue(value) {
    if (typeof value === "boolean") {
      return !value;
    }
    if (Array.isArray(value)) {
      return value.every((item) => isEmptyValue(item));
    }
    if (value !== null && typeof value === "object") {
      return Object.values(value).every((item) => isEmptyValue(item));
    }
    return value === "" || value === null || value === void 0;
  }
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
  function luhnPasses(value) {
    const digits = value.replace(/[^0-9]/g, "");
    if (digits.length < 12) {
      return false;
    }
    let sum = 0;
    let double = false;
    for (let index = digits.length - 1; index >= 0; index--) {
      let digit = digits.charCodeAt(index) - 48;
      if (double) {
        digit *= 2;
        if (digit > 9) {
          digit -= 9;
        }
      }
      sum += digit;
      double = !double;
    }
    return 0 === sum % 10;
  }
  function presetPasses(slug, value) {
    const preset = validationPreset(slug);
    if (!preset) {
      return null;
    }
    let expression;
    try {
      expression = new RegExp(preset.pattern, "u");
    } catch {
      return true;
    }
    if (!expression.test(value)) {
      return false;
    }
    if (preset.luhn && !luhnPasses(value)) {
      return false;
    }
    return true;
  }
  const config = window.allTerrainForms;
  const i18n = (key, fallback) => config?.i18n?.[key] ?? fallback;
  class AllTerrainForm {
    constructor(form, schema) {
      this.step = 0;
      this.started = false;
      this.submitting = false;
      this.form = form;
      this.schema = schema;
      this.pages = Array.from(form.querySelectorAll("[data-atf-page]"));
      this.errorSummary = form.querySelector(".atf-errors");
      this.bind();
      this.showStep(0, false);
      this.update();
    }
    /** Wires every listener. */
    bind() {
      this.form.addEventListener("input", (event) => this.onInput(event));
      this.form.addEventListener("change", (event) => this.onInput(event));
      this.form.addEventListener("submit", (event) => void this.onSubmit(event));
      this.form.addEventListener("click", (event) => {
        const target = event.target;
        if (target.closest("[data-atf-next]")) {
          event.preventDefault();
          this.next();
        }
        if (target.closest("[data-atf-prev]")) {
          event.preventDefault();
          this.previous();
        }
        if (target.closest("[data-atf-repeater-add]")) {
          event.preventDefault();
          this.addRepeaterRow(target.closest("[data-atf-repeater]"));
        }
        if (target.closest("[data-atf-repeater-remove]")) {
          event.preventDefault();
          this.removeRepeaterRow(target.closest("[data-atf-repeater-row]"));
        }
        if (target.closest("[data-atf-resume]")) {
          event.preventDefault();
          void this.saveForLater();
        }
      });
      this.form.addEventListener(
        "blur",
        (event) => {
          const field = event.target?.closest?.("[data-atf-field]");
          if (field) {
            this.validateField(field);
          }
        },
        true
      );
      this.form.querySelectorAll("[data-atf-signature]").forEach((pad) => {
        this.initSignature(pad);
      });
      this.form.querySelectorAll(".atf-range__input").forEach((range) => {
        const output = range.parentElement?.querySelector(".atf-range__output");
        range.addEventListener("input", () => {
          if (output) {
            output.textContent = range.value;
          }
        });
      });
      this.initOtherToggles();
    }
    /** Reads every value out of the DOM. */
    values() {
      const values = {};
      for (const field of this.schema.fields) {
        values[field.id] = this.readField(field);
      }
      return values;
    }
    /** Reads one field's value. */
    readField(field) {
      const wrapper = this.fieldElement(field.id);
      if (!wrapper) {
        return null;
      }
      if (field.type === "repeater") {
        return this.readRepeater(field, wrapper);
      }
      const inputs = Array.from(
        wrapper.querySelectorAll(
          "input, select, textarea"
        )
      ).filter((input) => !input.disabled && !input.closest("template"));
      if (!inputs.length) {
        return null;
      }
      if (["name", "address", "date_range", "likert"].includes(field.type)) {
        const object = {};
        for (const input of inputs) {
          const key = input.name.match(/\[([^\]]+)\]$/)?.[1];
          if (!key) {
            continue;
          }
          if (input instanceof HTMLInputElement && (input.type === "radio" || input.type === "checkbox")) {
            if (input.checked) {
              object[key] = input.value;
            }
            continue;
          }
          object[key] = input.value;
        }
        return object;
      }
      const checkboxes = inputs.filter(
        (input) => input instanceof HTMLInputElement && input.type === "checkbox"
      );
      if (checkboxes.length) {
        if (checkboxes.length === 1 && !checkboxes[0].name.endsWith("[]")) {
          return checkboxes[0].checked;
        }
        return checkboxes.filter((input) => input.checked).map((input) => input.value);
      }
      const radios = inputs.filter(
        (input) => input instanceof HTMLInputElement && input.type === "radio"
      );
      if (radios.length) {
        return radios.find((input) => input.checked)?.value ?? "";
      }
      const first = inputs[0];
      if (first instanceof HTMLSelectElement && first.multiple) {
        return Array.from(first.selectedOptions).map((option) => option.value);
      }
      if (first instanceof HTMLInputElement && first.type === "file") {
        return first.files && first.files.length ? [String(first.files.length)] : [];
      }
      return first.value;
    }
    /**
     * Reads a repeater's rows out of the DOM.
     *
     * Every schema sub-field is present in every row, defaulting to '', so a
     * formula referencing an untouched box sees zero rather than a hole. The
     * sub-field id is parsed back out of the posted name —
     * `atf[rep][0][age]` — because that name is the one thing the clone
     * machinery is already required to keep correct.
     */
    readRepeater(field, wrapper) {
      const subs = field.fields ?? [];
      const rows = [];
      wrapper.querySelectorAll("[data-atf-repeater-row]").forEach((rowElement) => {
        const row = {};
        for (const sub of subs) {
          row[sub.id] = "";
        }
        rowElement.querySelectorAll(
          "input, select, textarea"
        ).forEach((input) => {
          if (input.disabled) {
            return;
          }
          const match = input.name.match(/\[\d+\]\[([^\]]+)\]/);
          if (!match) {
            return;
          }
          const subId = match[1];
          if (input instanceof HTMLInputElement && input.type === "checkbox") {
            if (input.name.endsWith("[]")) {
              const list = Array.isArray(row[subId]) ? row[subId] : [];
              if (input.checked) {
                list.push(input.value);
              }
              row[subId] = list;
              return;
            }
            row[subId] = input.checked;
            return;
          }
          if (input instanceof HTMLInputElement && input.type === "radio") {
            if (input.checked) {
              row[subId] = input.value;
            }
            return;
          }
          if (input instanceof HTMLSelectElement && input.multiple) {
            row[subId] = Array.from(input.selectedOptions).map((option) => option.value);
            return;
          }
          row[subId] = input.value;
        });
        rows.push(row);
      });
      return rows;
    }
    /** The wrapper element for a field. */
    fieldElement(fieldId) {
      return this.form.querySelector(`[data-atf-field="${CSS.escape(fieldId)}"]`);
    }
    /** Reacts to any change: recompute logic, recompute totals, count the start. */
    onInput(event) {
      const target = event.target;
      if (target?.closest("template")) {
        return;
      }
      if (!this.started) {
        this.started = true;
        this.reportStart();
      }
      this.update();
      const field = target?.closest("[data-atf-field]");
      if (field?.classList.contains("has-error")) {
        this.validateField(field);
      }
    }
    /** Applies conditional logic and calculations to the current DOM. */
    update() {
      const values = this.values();
      const visible = visibleFields(this.schema.fields, values);
      for (const field of this.schema.fields) {
        const element = this.fieldElement(field.id);
        if (!element) {
          continue;
        }
        const show = visible[field.id] !== false;
        if (element.hidden === show) {
          element.hidden = !show;
        }
        element.querySelectorAll("input, select, textarea").forEach((input) => {
          if (input.closest("template")) {
            return;
          }
          if (input.hasAttribute("data-atf-other-input")) {
            if (!show) {
              input.disabled = true;
            }
            return;
          }
          input.disabled = !show;
        });
      }
      const calculated = applyCalculations(this.schema.fields, values);
      for (const field of this.schema.fields) {
        if (!field.formula) {
          continue;
        }
        const input = this.fieldElement(field.id)?.querySelector("[data-atf-total]");
        if (!input) {
          continue;
        }
        const value = calculated[field.id];
        const decimals = typeof field.decimals === "number" ? field.decimals : 2;
        input.value = typeof value === "number" ? value.toFixed(decimals) : "";
      }
    }
    /* ---------------------------------------------------------------- Steps */
    /** Shows one page of a multi-page form. */
    showStep(index, focus = true) {
      if (this.pages.length < 2) {
        return;
      }
      this.step = Math.max(0, Math.min(this.pages.length - 1, index));
      this.pages.forEach((page, position) => {
        page.hidden = position !== this.step;
        delete page.dataset.atfPageHidden;
      });
      this.updateProgress();
      if (focus) {
        const page = this.pages[this.step];
        page.setAttribute("tabindex", "-1");
        page.focus({ preventScroll: true });
        page.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    }
    /** Repaints the step indicator. */
    updateProgress() {
      const bar = this.form.querySelector(".atf-progress--bar");
      if (bar) {
        const fill = bar.querySelector(".atf-progress__fill");
        const percent = (this.step + 1) / this.pages.length * 100;
        if (fill) {
          fill.style.width = `${percent}%`;
        }
        bar.setAttribute("aria-valuenow", String(this.step + 1));
      }
      this.form.querySelectorAll(".atf-progress__step").forEach((step, index) => {
        step.classList.toggle("is-current", index === this.step);
        step.classList.toggle("is-done", index < this.step);
        if (index === this.step) {
          step.setAttribute("aria-current", "step");
        } else {
          step.removeAttribute("aria-current");
        }
      });
    }
    /** Moves forward, if this page validates. */
    next() {
      if (!this.validatePage(this.step)) {
        return;
      }
      this.showStep(this.step + 1);
    }
    /** Moves back. Never validates — going back to fix something must always work. */
    previous() {
      this.showStep(this.step - 1);
    }
    /* ----------------------------------------------------------- Validation */
    /**
     * Validates every visible field on one page.
     *
     * Only the current page, because a multi-page form that refused to advance
     * over a problem three steps ahead would be unusable.
     */
    validatePage(index) {
      const page = this.pages[index];
      if (!page) {
        return true;
      }
      const fields = Array.from(page.querySelectorAll("[data-atf-field]"));
      let firstBad = null;
      for (const field of fields) {
        if (!this.validateField(field) && !firstBad) {
          firstBad = field;
        }
      }
      if (firstBad) {
        this.focusField(firstBad);
        return false;
      }
      return true;
    }
    /** Validates one field and paints the result. */
    validateField(element) {
      if (element.hidden) {
        this.setFieldError(element, "");
        return true;
      }
      const fieldId = element.dataset.atfField ?? "";
      const field = this.schema.fields.find((candidate) => candidate.id === fieldId);
      if (!field) {
        return true;
      }
      if ("repeater" === field.type) {
        return this.validateRepeater(element, field);
      }
      const value = this.readField(field);
      const error = this.checkField(field, value);
      this.setFieldError(element, error);
      return error === "";
    }
    /**
     * Validates a repeater the way the server does: control by control.
     *
     * Each failing box is marked itself, its row's card wears the error
     * border, and the repeater's own error node carries the "Attendee 2: …"
     * summary — the same message the server would send. A row where every box
     * is empty is skipped entirely, mirroring the sanitiser dropping it; only
     * a row somebody has started owes its required answers.
     */
    validateRepeater(element, field) {
      const subs = field.fields ?? [];
      const rows = this.readField(field);
      const values = Array.isArray(rows) ? rows : [];
      const rowElements = Array.from(element.querySelectorAll("[data-atf-repeater-row]"));
      const itemLabel = element.querySelector("[data-atf-repeater]")?.dataset.atfItemLabel || "";
      let summary = "";
      rowElements.forEach((rowElement, index) => {
        const row = values[index] ?? {};
        const empty = subs.every((sub) => isEmptyValue(row[sub.id] ?? ""));
        let rowBad = false;
        for (const sub of subs) {
          const wrapper = rowElement.querySelector(`[data-atf-sub="${sub.id}"]`);
          if (!wrapper) {
            continue;
          }
          const error = empty ? "" : this.checkField(sub, row[sub.id] ?? "");
          this.setFieldError(wrapper, error);
          if (error !== "") {
            rowBad = true;
            if (summary === "") {
              summary = `${itemLabel} ${index + 1}: ${error}`;
            }
          }
        }
        rowElement.classList.toggle("has-error", rowBad);
      });
      this.setFieldError(element, summary);
      return summary === "";
    }
    /**
     * The client's copy of the validation rules.
     *
     * A deliberate subset of the server's: everything here is a rule the browser
     * can check without a round trip. Uniqueness and the spam checks are not,
     * and are left to the server rather than guessed at.
     */
    checkField(field, value) {
      const messages = field.messages ?? {};
      const empty = isEmptyValue(value);
      if (field.required && empty) {
        return messages.required || i18n("required", "This is required.");
      }
      if (empty) {
        return "";
      }
      if (typeof value === "string") {
        if (field.type === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
          return messages.invalid || i18n("invalidEmail", "That does not look like an email address.");
        }
        if (field.type === "url" && !/^https?:\/\/[^\s]+$/i.test(value)) {
          return messages.invalid || i18n("invalidUrl", "That does not look like a web address.");
        }
        const min = Number(field.minlength);
        const max = Number(field.maxlength);
        if (field.minlength && value.length < min) {
          return messages.min || i18n("tooShort", "That is too short.");
        }
        if (field.maxlength && value.length > max) {
          return messages.max || i18n("tooLong", "That is too long.");
        }
        const presetSlug = "string" === typeof field.validation && "" !== field.validation && "custom" !== field.validation ? field.validation : "";
        if (presetSlug && false === presetPasses(presetSlug, value)) {
          return messages.invalid || validationPreset(presetSlug)?.message || i18n("badFormat", "That is not in the expected format.");
        }
        if (field.pattern) {
          try {
            if (!new RegExp(String(field.pattern)).test(value)) {
              return messages.invalid || i18n("badFormat", "That is not in the expected format.");
            }
          } catch {
          }
        }
      }
      if (value !== "" && !Number.isNaN(Number(value)) && typeof value !== "boolean" && !Array.isArray(value)) {
        const numeric = Number(value);
        if (field.min !== void 0 && field.min !== "" && numeric < Number(field.min)) {
          return messages.min || i18n("tooSmall", "That number is too small.");
        }
        if (field.max !== void 0 && field.max !== "" && numeric > Number(field.max)) {
          return messages.max || i18n("tooBig", "That number is too large.");
        }
      }
      if (Array.isArray(value)) {
        const chosen = value.filter((item) => item !== "").length;
        if (field.minChoices && chosen < Number(field.minChoices)) {
          return messages.min || i18n("required", "This is required.");
        }
        if (field.maxChoices && chosen > Number(field.maxChoices)) {
          return messages.max || i18n("tooBig", "That is too many.");
        }
      }
      return "";
    }
    /** Paints or clears one field's error. */
    setFieldError(element, message) {
      const error = element.querySelector(":scope > .atf-error") ?? element.querySelector(".atf-error");
      element.classList.toggle("has-error", message !== "");
      if (error) {
        error.textContent = message;
        error.hidden = message === "";
      }
      if ("repeater" === element.dataset.atfType) {
        return;
      }
      element.querySelectorAll("input, select, textarea").forEach((input) => {
        if (message === "") {
          input.removeAttribute("aria-invalid");
        } else {
          input.setAttribute("aria-invalid", "true");
        }
      });
    }
    /**
     * Resolves a server error key like `rep.0.age` to that control's wrapper.
     *
     * The server numbers rows after the sanitiser drops the all-empty ones, so
     * its row 0 is the first row somebody actually filled in — the DOM rows
     * are filtered the same way before indexing. The matched row's card is
     * marked on the way through.
     */
    repeaterSubElement(key) {
      const parts = key.split(".");
      if (parts.length !== 3) {
        return null;
      }
      const [repId, rowIndex, subId] = parts;
      const element = this.fieldElement(repId);
      const field = this.schema.fields.find((candidate) => candidate.id === repId);
      if (!element || !field || "repeater" !== field.type) {
        return null;
      }
      const subs = field.fields ?? [];
      const rows = this.readField(field);
      const values = Array.isArray(rows) ? rows : [];
      const kept = Array.from(element.querySelectorAll("[data-atf-repeater-row]")).filter(
        (row, index) => row && !subs.every((sub) => isEmptyValue(values[index]?.[sub.id] ?? ""))
      );
      const rowElement = kept[Number(rowIndex)];
      const wrapper = rowElement?.querySelector(`[data-atf-sub="${subId}"]`) ?? null;
      if (wrapper && rowElement) {
        rowElement.classList.add("has-error");
      }
      return wrapper;
    }
    /** Moves focus to a field's first control. */
    focusField(element) {
      const input = element.querySelector("input, select, textarea");
      (input ?? element).focus?.({ preventScroll: true });
      element.scrollIntoView({ behavior: "smooth", block: "center" });
    }
    /* ----------------------------------------------------------- Submission */
    /** Handles the submit. */
    async onSubmit(event) {
      if (this.pages.length > 1 && this.step < this.pages.length - 1) {
        event.preventDefault();
        this.next();
        return;
      }
      for (let index = 0; index < this.pages.length; index++) {
        if (!this.validatePage(index)) {
          event.preventDefault();
          this.showStep(index);
          this.announceErrors();
          return;
        }
      }
      if (!this.schema.settings.ajax) {
        return;
      }
      event.preventDefault();
      if (this.submitting) {
        return;
      }
      await this.submit();
    }
    /** Posts the form over the REST API. */
    async submit() {
      const button = this.form.querySelector("[data-atf-submit]");
      const status = this.form.querySelector(".atf-status");
      this.submitting = true;
      button?.classList.add("is-busy");
      if (button) {
        button.disabled = true;
      }
      if (status) {
        status.textContent = i18n("sending", "Sending…");
      }
      try {
        const body = new FormData(this.form);
        const response = await fetch(`${config?.restUrl ?? ""}/submit`, {
          method: "POST",
          body,
          credentials: "same-origin",
          headers: config?.nonce ? { "X-WP-Nonce": config.nonce } : {}
        });
        const result = await response.json();
        if (!result.success) {
          this.showServerErrors(result);
          return;
        }
        this.showConfirmation(result);
      } catch {
        if (status) {
          status.textContent = i18n("failed", "That did not send. Please try again.");
        }
      } finally {
        this.submitting = false;
        button?.classList.remove("is-busy");
        if (button) {
          button.disabled = false;
        }
      }
    }
    /** Paints the errors the server sent back. */
    showServerErrors(result) {
      const status = this.form.querySelector(".atf-status");
      if (status) {
        status.textContent = "";
      }
      let firstBad = null;
      let firstPage = 0;
      for (const [fieldId, message] of Object.entries(result.errors ?? {})) {
        const element = this.fieldElement(fieldId) ?? this.repeaterSubElement(fieldId);
        if (!element) {
          continue;
        }
        this.setFieldError(element, message);
        if (!firstBad) {
          firstBad = element;
          firstPage = this.pages.findIndex((page) => page.contains(element));
        }
      }
      this.announceErrors(result.message);
      if (firstBad) {
        if (firstPage >= 0) {
          this.showStep(firstPage, false);
        }
        this.focusField(firstBad);
      }
    }
    /** Fills and focuses the error summary. */
    announceErrors(message = "") {
      if (!this.errorSummary) {
        return;
      }
      const bad = Array.from(this.form.querySelectorAll(".atf-field.has-error")).filter(
        (element) => !element.closest("[data-atf-repeater]")
      );
      if (!bad.length && !message) {
        this.errorSummary.hidden = true;
        return;
      }
      const line = (element, prefix = "") => {
        const label = this.labelTextOf(element);
        const text = (element.querySelector(":scope > .atf-error") ?? element.querySelector(".atf-error"))?.textContent?.trim() ?? "";
        const id = element.querySelector("input, select, textarea")?.id ?? "";
        return `<li><a href="#${id}">${escapeHtml(prefix + (label ? `${label}: ${text}` : text))}</a></li>`;
      };
      const items = bad.map((element) => {
        const failing = Array.from(
          element.querySelectorAll(".atf-repeater__field.has-error")
        );
        if (failing.length) {
          const label = this.labelTextOf(element);
          const id = failing[0].querySelector("input, select, textarea")?.id ?? "";
          const nested = failing.map((sub) => {
            const row = sub.closest("[data-atf-repeater-row]")?.querySelector("[data-atf-repeater-title]")?.textContent?.trim();
            return line(sub, row ? `${row} — ` : "");
          }).join("");
          return `<li><a href="#${id}">${escapeHtml(label)}</a><ul class="atf-errors__sub">${nested}</ul></li>`;
        }
        return line(element);
      }).join("");
      this.errorSummary.innerHTML = `<p class="atf-errors__title">${escapeHtml(
        message || i18n("errorsFound", "There are problems to fix.")
      )}</p><ul>${items}</ul>`;
      this.errorSummary.hidden = false;
      this.errorSummary.focus();
    }
    /**
     * The human name of a field, for the error summary.
     *
     * Two things this has to get right, and both are visible the moment a form
     * fails validation.
     *
     * The **asterisk is stripped**. It is `aria-hidden` in the markup precisely
     * so it is never announced, and reading `textContent` off the label puts it
     * straight back — the summary would say "Your name star: this is required",
     * which is exactly the noise the `aria-hidden` was there to prevent.
     *
     * A **consent or toggle field keeps its own label** in `.atf-toggle__label`
     * rather than `.atf-label`, so a lookup that only knows the latter leaves
     * those rows with no name at all — an entry in the summary that says nothing
     * but "This is required", pointing at nothing the reader can identify.
     */
    labelTextOf(element) {
      const source = element.querySelector(".atf-label, legend, .atf-toggle__label");
      if (!source) {
        return "";
      }
      const clone = source.cloneNode(true);
      clone.querySelectorAll('.atf-required, [aria-hidden="true"]').forEach((node) => node.remove());
      return (clone.textContent ?? "").replace(/\s+/g, " ").trim().replace(/[.:;,]+$/, "");
    }
    /** Replaces the form with its confirmation, or follows a redirect. */
    showConfirmation(result) {
      const confirmation = result.confirmation ?? {};
      if (confirmation.url) {
        window.location.assign(confirmation.url);
        return;
      }
      const wrapper = this.form.parentElement;
      if (!wrapper) {
        return;
      }
      const panel = renderSuccessScreen(confirmation.message ?? "", confirmation.success);
      this.form.replaceWith(panel);
      playSuccessEffects(panel, confirmation.success);
      panel.focus();
      panel.scrollIntoView({ behavior: "smooth", block: "center" });
      document.dispatchEvent(
        new CustomEvent("atf-submitted", {
          detail: { formId: Number(this.form.dataset.atfForm), entryId: result.entry_id },
          bubbles: true
        })
      );
    }
    /**
     * Saves what has been filled in so far and shows the way back.
     *
     * Deliberately does not validate. A half-finished form is by definition
     * missing required answers, and refusing to save it because of that would
     * make the feature useless — which is exactly the mistake that makes
     * "save for later" feel broken in the plugins that have it.
     */
    async saveForLater() {
      const button = this.form.querySelector("[data-atf-resume]");
      const tokenField = this.form.querySelector("[data-atf-resume-token]");
      if (button) {
        button.disabled = true;
      }
      try {
        const body = new FormData(this.form);
        const response = await fetch(`${config?.restUrl ?? ""}/resume`, {
          method: "POST",
          body,
          credentials: "same-origin",
          headers: config?.nonce ? { "X-WP-Nonce": config.nonce } : {}
        });
        const result = await response.json();
        if (!result.success || !result.url) {
          this.showResumeMessage(result.message ?? i18n("failed", "That did not save. Please try again."), "");
          return;
        }
        if (tokenField && result.token) {
          tokenField.value = result.token;
        }
        this.showResumeMessage(
          result.days ? `Saved. Come back to this within ${result.days} days using the link below.` : "Saved. Come back using the link below.",
          result.url
        );
      } catch {
        this.showResumeMessage(i18n("failed", "That did not save. Please try again."), "");
      } finally {
        if (button) {
          button.disabled = false;
        }
      }
    }
    /**
     * Shows the resume link.
     *
     * On the page rather than only in an e-mail, because the visitor is looking
     * at the screen right now and may not have given an address yet. The input is
     * `readonly` and selects itself, so copying it is one gesture.
     */
    showResumeMessage(message, url) {
      this.form.querySelector(".atf-resume-panel")?.remove();
      const panel = document.createElement("div");
      panel.className = "atf-resume-panel";
      panel.setAttribute("role", "status");
      panel.setAttribute("tabindex", "-1");
      const text = document.createElement("p");
      text.textContent = message;
      panel.append(text);
      if (url) {
        const field = document.createElement("input");
        field.type = "text";
        field.className = "atf-input";
        field.readOnly = true;
        field.value = url;
        field.setAttribute("aria-label", "Your link back to this form");
        field.addEventListener("focus", () => field.select());
        panel.append(field);
      }
      this.form.querySelector(".atf-actions")?.after(panel);
      panel.focus();
    }
    /** Tells the server somebody started filling this in. */
    reportStart() {
      const formId = Number(this.form.dataset.atfForm);
      if (!formId || !config?.restUrl) {
        return;
      }
      void fetch(`${config.restUrl}/track`, {
        method: "POST",
        keepalive: true,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ form_id: formId, event: "start" })
      }).catch(() => {
      });
    }
    /* ------------------------------------------------------------ Repeaters */
    /** Clones the template row into a repeater. */
    addRepeaterRow(repeater) {
      if (!repeater) {
        return;
      }
      const rows = repeater.querySelector(".atf-repeater__rows");
      const template = repeater.querySelector("[data-atf-repeater-template]");
      const max = Number(repeater.dataset.atfMax ?? 10);
      if (!rows || !template) {
        return;
      }
      if (rows.querySelectorAll("[data-atf-repeater-row]").length >= max) {
        return;
      }
      const index = nextRepeaterIndex(rows);
      const clone = template.content.cloneNode(true);
      clone.querySelectorAll("[name], [id], [for]").forEach((element) => {
        for (const attribute of ["name", "id", "for"]) {
          const value = element.getAttribute(attribute);
          if (value?.includes("__INDEX__")) {
            element.setAttribute(attribute, value.replace(/__INDEX__/g, String(index)));
          }
        }
      });
      rows.appendChild(clone);
      const added = rows.lastElementChild;
      added?.querySelector("input, select, textarea")?.focus();
      this.renumberRepeater(repeater);
      this.update();
    }
    /**
     * Rewrites every row's title — "Attendee 1", "Attendee 2" — after an add
     * or a remove.
     *
     * By position, not by the posted index: removing the middle attendee must
     * not leave the survivors reading "1" and "3", even though their *names*
     * deliberately keep those indexes so the array slots never collide.
     */
    renumberRepeater(repeater) {
      const label = repeater.dataset.atfItemLabel ?? "";
      repeater.querySelectorAll("[data-atf-repeater-row]").forEach((row, index) => {
        const title = `${label} ${index + 1}`.trim();
        const node = row.querySelector("[data-atf-repeater-title]");
        if (node) {
          node.textContent = title;
        }
        row.setAttribute("aria-label", title);
      });
    }
    /** Removes a repeater row, unless it is the last one the field allows. */
    removeRepeaterRow(row) {
      const repeater = row?.closest("[data-atf-repeater]");
      if (!row || !repeater) {
        return;
      }
      const rows = repeater.querySelectorAll("[data-atf-repeater-row]");
      const min = Number(repeater.dataset.atfMin ?? 1);
      if (rows.length <= min) {
        row.querySelectorAll("input, select, textarea").forEach((input) => {
          input.value = "";
        });
        this.update();
        return;
      }
      const focusAfter = row.nextElementSibling ?? row.previousElementSibling;
      row.remove();
      focusAfter?.querySelector("input, select, textarea")?.focus();
      this.renumberRepeater(repeater);
      this.update();
    }
    /* ------------------------------------------------------------ Signature */
    /**
     * Turns the canvas into a signature pad.
     *
     * Pointer events rather than mouse plus touch, so a finger, a stylus and a
     * mouse are one code path. `touch-action: none` in the CSS is what stops the
     * page scrolling under a finger that is trying to sign.
     */
    initSignature(pad) {
      const canvas = pad.querySelector("canvas");
      const input = pad.querySelector('input[type="hidden"]');
      const clear = pad.querySelector("[data-atf-signature-clear]");
      const context = canvas?.getContext("2d");
      if (!canvas || !input || !context) {
        return;
      }
      context.lineWidth = 2;
      context.lineCap = "round";
      context.lineJoin = "round";
      context.strokeStyle = getComputedStyle(pad).color || "#000";
      let drawing = false;
      const positionOf = (event) => {
        const rect = canvas.getBoundingClientRect();
        return {
          x: (event.clientX - rect.left) / rect.width * canvas.width,
          y: (event.clientY - rect.top) / rect.height * canvas.height
        };
      };
      canvas.addEventListener("pointerdown", (event) => {
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        const { x, y } = positionOf(event);
        context.beginPath();
        context.moveTo(x, y);
      });
      canvas.addEventListener("pointermove", (event) => {
        if (!drawing) {
          return;
        }
        const { x, y } = positionOf(event);
        context.lineTo(x, y);
        context.stroke();
      });
      const finish = () => {
        if (!drawing) {
          return;
        }
        drawing = false;
        input.value = canvas.toDataURL("image/png");
        input.dispatchEvent(new Event("change", { bubbles: true }));
      };
      canvas.addEventListener("pointerup", finish);
      canvas.addEventListener("pointercancel", finish);
      canvas.addEventListener("pointerleave", finish);
      clear?.addEventListener("click", () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        input.value = "";
        input.dispatchEvent(new Event("change", { bubbles: true }));
      });
    }
    /** Enables the "Other" text box only while its choice is picked. */
    initOtherToggles() {
      const sync = () => {
        this.form.querySelectorAll(".atf-choice--other").forEach((wrapper) => {
          const toggle = wrapper.querySelector("[data-atf-other-toggle]");
          const input = wrapper.querySelector("[data-atf-other-input]");
          if (!toggle || !input) {
            return;
          }
          input.disabled = !toggle.checked;
          input.hidden = !toggle.checked;
        });
      };
      this.form.addEventListener("change", sync);
      sync();
    }
  }
  function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }
  function nextRepeaterIndex(rows) {
    let highest = -1;
    rows.querySelectorAll("[data-atf-repeater-row] [name]").forEach((element) => {
      const match = /^atf\[[^\]]*\]\[(\d+)\]/.exec(element.getAttribute("name") ?? "");
      if (match) {
        highest = Math.max(highest, Number(match[1]));
      }
    });
    return highest + 1;
  }
  function readSchema(form) {
    const instance = form.dataset.atfInstance ?? "";
    const script = document.getElementById(`${instance}-schema`);
    if (!script?.textContent) {
      return null;
    }
    try {
      return JSON.parse(script.textContent);
    } catch {
      return null;
    }
  }
  function boot() {
    document.querySelectorAll("form[data-atf-form]").forEach((form) => {
      if (form.dataset.atfBooted) {
        return;
      }
      const schema = readSchema(form);
      if (!schema) {
        return;
      }
      form.dataset.atfBooted = "1";
      try {
        new AllTerrainForm(form, schema);
      } catch (error) {
        console.error("[AllTerrain Forms] Could not enhance a form.", error);
      }
    });
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
  document.addEventListener("atf-refresh", boot);
  exports.AllTerrainForm = AllTerrainForm;
  exports.boot = boot;
  exports.nextRepeaterIndex = nextRepeaterIndex;
  Object.defineProperty(exports, Symbol.toStringTag, { value: "Module" });
  return exports;
}({});
