import { imageVariants } from "./media-map.js";
const $ = (s, r = document) => r.querySelector(s),
  $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = (s) =>
  String(s ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
const commandId = () => {
  if (typeof crypto.randomUUID === "function") return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return [...bytes].map(x => x.toString(16).padStart(2,"0")).join("");
};
const num = (n) => new Intl.NumberFormat("fa-IR").format(Number(n) || 0),
  money = (n) => `${num(n)} تومان`,
  stamp = (s, withTime = false) => {
    if (!s) return "—";
    const date = new Date(s.replace(" ", "T") + "Z");
    if (Number.isNaN(date.getTime())) return s;
    return new Intl.DateTimeFormat("fa-IR", {
      timeZone: "Asia/Tehran", dateStyle: "medium",
      ...(withTime ? { timeStyle: "short" } : {}),
    }).format(date);
  };
// Preferences and cart remain usable when Safari disables persistent storage.
const preferenceMemory = new Map();
const preferences = {
  getItem(key) { try { return localStorage.getItem(key); } catch { return preferenceMemory.get(key) ?? null; } },
  setItem(key,value) { preferenceMemory.set(key,String(value)); try { localStorage.setItem(key,value); } catch {} },
  removeItem(key) { preferenceMemory.delete(key); try { localStorage.removeItem(key); } catch {} },
};
const parse = (key, fallback) => {
  try {
    return JSON.parse(preferences.getItem(key)) ?? fallback;
  } catch {
    return fallback;
  }
};
let cart = (
    Array.isArray(parse("amir_cart_v2", [])) ? parse("amir_cart_v2", []) : []
  ).filter(
    (x) =>
      x &&
      typeof x.id === "string" &&
      Number.isInteger(x.qty) &&
      x.qty > 0 &&
      x.qty <= 99,
  ),
  compare = Array.isArray(parse("amir_compare_v2", []))
    ? parse("amir_compare_v2", [])
    : [],
  wishlist = Array.isArray(parse("amir_wishlist_v2", []))
    ? parse("amir_wishlist_v2", [])
    : [],
  boot,
  seq = 0,
  quote = null,
  installPrompt;
for (const key of ["ks_auth_token", "ks_user", "ks_registered_users"])
  preferences.removeItem(key);
const paths = {
  speaker:
    '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
  search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
  cart: '<path d="M3 3h2l3 12h10l3-8H6M9 20h.01M18 20h.01"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a7 7 0 0 1 14 0v2"/>',
  arrow: '<path d="M20 12H4m6-6-6 6 6 6"/>',
  check: '<path d="m5 12 4 4L20 5"/>',
  shield:
    '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6z"/><path d="m8 12 3 3 5-6"/>',
  truck:
    '<path d="M2 5h12v12H2zM14 9h4l4 4v4h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
  tool: '<path d="m14 6 4-4a7 7 0 0 1-8 9L3 18a2 2 0 0 0 3 3l7-8a7 7 0 0 1 9-8l-4 4-4-3z"/>',
  headphone: '<path d="M3 15v-4a9 9 0 0 1 18 0v4M3 13h4v8H3zM17 13h4v8h-4z"/>',
  compare: '<path d="M8 3v18M16 3v18M3 7l5-4 5 4M11 17l5 4 5-4"/>',
  moon: '<path d="M21 13A9 9 0 0 1 11 3a9 9 0 1 0 10 10z"/>',
  heart: '<path d="m12 21-8-8a5 5 0 0 1 8-6 5 5 0 0 1 8 6z"/>',
  box: '<path d="m3 7 9-5 9 5v11l-9 5-9-5zM3 7l9 5 9-5M12 12v11"/>',
  home: '<path d="m2 11 10-9 10 9M5 9v13h14V9M10 22v-8h4v8"/>',
  close: '<path d="m5 5 14 14M19 5 5 19"/>',
  trash: '<path d="M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/>',
  sliders: '<path d="M4 6h16M4 18h16M8 3v6M16 15v6M4 12h16M12 9v6"/>',
  lock: '<rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V6a5 5 0 0 1 10 0v4"/>',
  chart: '<path d="M3 3v18h18M7 17v-5M12 17V7M17 17v-9"/>',
  logout: '<path d="M9 3H3v18h6M10 12h12m-5-5 5 5-5 5"/>',
  print: '<path d="M6 8V2h12v6M6 18H3V8h18v10h-3M6 14h12v8H6z"/>',
};
const icon = (n) =>
  `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[n] || paths.speaker}</svg>`;
function toast(message, error = false) {
  const node = document.createElement("div");
  node.className = "toast" + (error ? " error" : "");
  node.textContent = message;
  $("#toast").append(node);
  setTimeout(() => node.remove(), 6000);
}
async function api(path, options = {}) {
  const method = options.method || "GET";
  let response;
  try {
    response = await fetch("/api/" + path, {
      method,
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        ...(method === "GET"
          ? {}
          : {
              "Content-Type": "application/json",
              "X-CSRF-Token": boot?.csrf || "",
            }),
        ...(options.key ? { "Idempotency-Key": options.key } : {}),
      },
      ...(options.body !== undefined
        ? { body: JSON.stringify(options.body) }
        : {}),
    });
  } catch {
    throw Object.assign(
      new Error("ارتباط برقرار نشد. اتصال اینترنت را بررسی کنید."),
      { network: true },
    );
  }
  const r = await response.json().catch(() => null);
  if (!response.ok || !r || r.status !== "success") {
    if (response.status === 419) {
      boot = await api("bootstrap");
    }
    throw Object.assign(
      new Error(r?.message || "پاسخ معتبر از فروشگاه دریافت نشد."),
      { status: response.status, requestId: r?.request_id },
    );
  }
  if (r.data?.csrf && boot) boot.csrf = r.data.csrf;
  return r.data;
}
function saveCart() {
  preferences.setItem("amir_cart_v2", JSON.stringify(cart));
  $$("[data-cart-badge]").forEach((x) => {
    x.textContent = num(cart.reduce((n, i) => n + i.qty, 0));
  });
}
function addCart(pid, qty = 1, kind = "product") {
  const old = cart.find((x) => x.id === pid && x.kind === kind);
  if (old) old.qty = Math.min(99, old.qty + qty);
  else cart.push({ id: pid, qty, kind });
  saveCart();
  toast("به سبد خرید اضافه شد.");
}
const statuses = {
  pending_payment: "در انتظار پرداخت",
  confirmed: "تأییدشده",
  processing: "در حال آماده‌سازی",
  shipped: "ارسال‌شده",
  delivered: "تحویل‌شده",
  cancelled: "لغوشده",
  expired: "مهلت پایان‌یافته",
  payment_review: "نیازمند بررسی پرداخت",
  refunded: "وجه برگشت داده شده",
  paid: "پرداخت‌شده",
  unpaid: "پرداخت‌نشده",
  approved: "منتشرشده",
  pending: "در انتظار بررسی",
  rejected: "ردشده",
  new: "جدید",
  read: "خوانده‌شده",
  answered: "پاسخ داده‌شده",
  active: "فعال",
  archived: "غیرفعال",
};
const paymentLabels = { online: "درگاه آنلاین", cod: "پرداخت در محل", transfer: "واریز بانکی" };
const stockReasons = {
  initial: "موجودی اولیه", manual_in: "ورود کالا", manual_out: "خروج کالا",
  manual_adjust: "اصلاح شمارش", order_reserved: "رزرو سفارش",
  order_cancelled: "آزادسازی بعد از لغو", order_expired: "آزادسازی بعد از انقضا",
  refund_returned: "بازگشت کالا به انبار",
};
const userRoles = { admin: "مدیر اصلی", manager: "مدیر فروش", customer: "مشتری" };
const tag = (s) =>
  `<span class="tag ${["paid", "delivered", "approved"].includes(s) ? "good" : ["pending_payment", "pending", "new"].includes(s) ? "orange" : ""}">${esc(statuses[s] || s)}</span>`;
function header() {
  const count = cart.reduce((n, i) => n + i.qty, 0);
  return `<div class="announce">تجهیزات صوتی خودرو، با مشخصات شفاف و انتخاب آگاهانه</div><header class="header"><div class="container"><div class="header-top"><a class="brand" href="/"><span class="brand-mark">${icon("speaker")}</span><span><b>${esc(boot.store.name)}</b><small>تجهیزات صوتی خودرو</small></span></a><form class="search" id="site-search" role="search"><input name="q" type="search" aria-label="جستجوی محصول" placeholder="نام محصول، مدل یا برند را جستجو کنید…" value="${esc(new URLSearchParams(location.search).get("q") || "")}"><button aria-label="جستجو">${icon("search")}</button></form><div class="header-actions"><button class="icon-btn" data-action="theme" aria-label="تغییر تم">${icon("moon")}</button><a class="icon-btn" href="${boot.user ? "/profile" : "/login"}" aria-label="${boot.user ? "حساب کاربری" : "ورود به حساب"}">${icon("user")}</a><a class="icon-btn" href="/cart" aria-label="سبد خرید">${icon("cart")}<span class="badge" data-cart-badge>${num(count)}</span></a></div></div><nav class="nav" aria-label="ناوبری اصلی">${[
    ["/", "صفحه اصلی"],
    ["/category", "محصولات"],
    ["/configurator", "سیستم‌یاب خودرو"],
    ["/blog", "مجله"],
    ["/warranty", "گارانتی"],
    ["/contact", "تماس با ما"],
  ]
    .map(
      ([href, label]) =>
        `<a class="${location.pathname === href ? "active" : ""}" href="${href}">${label}</a>`,
    )
    .join(
      "",
    )}<span class="nav-note">${icon("headphone")} انتخاب تخصصی، صدای متناسب</span></nav></div></header>`;
}
function footer() {
  const s = boot.store;
  return `<footer class="footer"><div class="container"><div class="footer-grid"><div><a class="brand" href="/"><span class="brand-mark">${icon("speaker")}</span><span><b>${esc(s.name)}</b><small>سیستم صوتی خودرو</small></span></a><p>تجهیزات صوتی خودرو برای سلیقه‌ها و بودجه‌های متفاوت.<br>پیش از خرید، مشخصات و سازگاری قطعات را بررسی کنید.</p></div><div><h3>دسترسی سریع</h3><div class="links"><a href="/category">فروشگاه محصولات</a><a href="/about">درباره فروشگاه</a><a href="/warranty">استعلام گارانتی</a><a href="/profile">حساب و سفارش‌ها</a>${boot.user?.role === "admin" || boot.user?.role === "manager" ? '<a href="/admin">مدیریت فروشگاه</a>' : ""}</div></div><div><h3>ارتباط با فروشگاه</h3>${s.phone ? `<p><a href="tel:${esc(s.phone)}">${esc(s.phone)}</a></p>` : ""}${s.email ? `<p>${esc(s.email)}</p>` : ""}${s.address ? `<p>${esc(s.address)}</p>` : ""}${s.hours ? `<p>${esc(s.hours)}</p>` : ""}<a class="text-link" href="/contact">فرم تماس و پشتیبانی ${icon("arrow")}</a></div></div><div class="footer-bottom"><span>© ${new Intl.DateTimeFormat("fa-IR", { year: "numeric" }).format(new Date())} ${esc(s.name)} — تمامی حقوق محفوظ است.</span><span>انتخاب دقیق، تجربه شنیداری بهتر</span></div></div></footer><nav class="bottom-nav" aria-label="منوی موبایل">${[
    ["/", "home", "خانه"],
    ["/category", "box", "محصولات"],
    ["/configurator", "sliders", "سیستم‌یاب"],
    ["/cart", "cart", "سبد خرید"],
    ["/profile", "user", "حساب من"],
  ]
    .map(
      ([href, i, t]) =>
        `<a href="${href}" class="${location.pathname === href ? "active" : ""}">${icon(i)}<span>${t}</span></a>`,
    )
    .join("")}</nav>`;
}
function head(title, subtitle = "فروشگاه امیر سیستم", extra = "", heading = "h1") {
  return `<div class="page-head"><div><div class="crumbs"><a href="/">خانه</a><span>/</span><span>${esc(subtitle)}</span></div><${heading}>${esc(title)}</${heading}></div>${extra}</div>`;
}
function empty(title, message, href = "/category", label = "مشاهده محصولات") {
  return `<div class="empty">${icon("box")}<h2>${esc(title)}</h2><p>${esc(message)}</p>${href ? `<a class="btn" href="${href}">${esc(label)} ${icon("arrow")}</a>` : ""}</div>`;
}
function field(
  name,
  label,
  value = "",
  type = "text",
  required = false,
  extra = "",
) {
  return `<div class="field ${extra}"><label for="f-${esc(name)}">${esc(label)}${required ? " *" : ""}</label>${type === "textarea" ? `<textarea id="f-${esc(name)}" name="${esc(name)}" ${required ? "required" : ""}>${esc(value)}</textarea>` : `<input id="f-${esc(name)}" name="${esc(name)}" type="${type}" value="${esc(value)}" ${required ? "required" : ""} ${type === "password" ? 'minlength="10" maxlength="200" autocomplete="new-password"' : ""}>`}</div>`;
}
function select(name, label, options, value = "", required = false) {
  return `<div class="field"><label for="f-${name}">${label}</label><select name="${name}" id="f-${name}" ${required ? "required" : ""}>${options.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(value) ? "selected" : ""}>${esc(l)}</option>`).join("")}</select></div>`;
}
function imageURL(src, width = 640) {
  return imageVariants[src]?.[width] || src || "/images/placeholder.svg";
}
function productMedia(src, alt) {
  const variants = imageVariants[src];
  return `<img src="${esc(imageURL(src, 320))}" ${variants ? `srcset="${esc(variants[320])} 320w, ${esc(variants[640])} 600w" sizes="(max-width: 540px) 46vw, (max-width: 1150px) 30vw, 320px"` : ""} width="600" height="600" alt="${esc(alt)}" loading="lazy" decoding="async">`;
}
function productCard(p) {
  const specs = Object.values(p.specs || {}).slice(0, 2);
  return `<article class="card product-card"><div class="product-visual"><a class="product-image" href="/product/${encodeURIComponent(p.slug)}">${productMedia(p.image, p.title)}<span class="product-label">${p.stock > 0 ? "موجود" : "ناموجود"}</span></a><button class="product-compare" data-compare="${esc(p.id)}" aria-label="افزودن به مقایسه">${icon("compare")}</button></div><div class="product-info"><span class="product-brand">${esc(p.brand_name || "")}</span><h3><a href="/product/${encodeURIComponent(p.slug)}">${esc(p.title)}</a></h3><div class="spec-tags">${specs.map((v) => `<span>${esc(v)}</span>`).join("")}</div>${p.reviews_count ? `<p class="rating">★ ${num(p.rating.toFixed(1))} <span class="muted">(${num(p.reviews_count)} دیدگاه)</span></p>` : ""}${p.fit_verified !== undefined ? `<span class="tag ${p.fit_verified ? "good" : "orange"}">${p.fit_verified ? "سازگاری تأییدشده" : "نیازمند بررسی نصاب"}</span>` : ""}<div class="product-bottom"><div class="price">${num(p.price)}<small>تومان</small></div><button class="btn small" data-add="${esc(p.id)}" ${p.stock < 1 ? "disabled" : ""}>${p.stock > 0 ? "افزودن" : "ناموجود"}</button></div></div></article>`;
}
async function home() {
  const { products, articles, bundles } = boot.home || (await api("home"));
  delete boot.home;
  return `<section class="hero" aria-labelledby="hero-title"><div class="hero-copy"><span class="eyebrow">تجهیزات صوتی خودرو / انتخاب تخصصی</span><h1 id="hero-title">صدای خوب،<br><em>اتفاقی نیست.</em></h1><p>صدایی که دوست دارید، از انتخاب درست شروع می‌شود. قطعات را کشف کنید، کنار هم بسنجید و سیستم خودتان را بسازید.</p><div class="button-row hero-actions"><a class="btn" href="/category">کشف تجهیزات ${icon("arrow")}</a><a class="hero-secondary" href="/configurator">سیستم مناسب من ${icon("sliders")}</a></div><div class="hero-footnote">${icon("headphone")} برای مسیرهای روزمره، با جزئیات شنیدنی‌تر.</div></div><div class="hero-art" aria-hidden="true"><div class="hero-art-top"><b>AMIR SYSTEM</b><span>THE SOUND EDIT — 01</span></div><div class="speaker-stage"><div class="speaker-orbit"></div><div class="hero-driver"><div class="driver-cone"><div class="driver-cap"></div></div><i class="driver-bolt bolt-one"></i><i class="driver-bolt bolt-two"></i><i class="driver-bolt bolt-three"></i><i class="driver-bolt bolt-four"></i></div><div class="hero-tweeter"><i></i></div><span class="art-caption">PRECISION<br>IN EVERY NOTE.</span><div class="frequency-bars">${Array.from({ length: 19 }, (_, i) => `<i class="bar-${i % 5}"></i>`).join("")}</div></div><div class="hero-art-bottom"><span>صدای شما. مسیر شما.</span><span class="sound-dot">BUILT AROUND YOU</span></div></div></section><section class="trust" aria-label="خدمات فروشگاه">${[
    ["shield", "مشخصات شفاف", "شرایط گارانتی برای هر کالا"],
    ["truck", "ارسال سفارش", "هزینه روشن پیش از پرداخت"],
    ["tool", "خدمات نصب", "با هماهنگی و بررسی فنی"],
    ["headphone", "انتخاب آگاهانه", "بررسی محصول و مقایسه قطعات"],
  ]
    .map(
      ([i, t, d]) =>
        `<div class="trust-item">${icon(i)}<div><strong>${t}</strong><span>${d}</span></div></div>`,
    )
    .join(
      "",
    )}</section><section class="section"><div class="section-head"><div><span class="eyebrow">از کجا شروع کنیم؟</span><h2>جهان صدای خودرو</h2></div><a href="/category" class="text-link">همه دسته‌ها ${icon("arrow")}</a></div><div class="categories">${boot.categories.map((c) => `<a class="cat" href="/category/${encodeURIComponent(c.slug)}"><span class="cat-icon">${icon(c.slug.includes("amp") ? "sliders" : c.slug.includes("head") ? "box" : "speaker")}</span>${esc(c.title.split("(")[0].trim())}</a>`).join("")}</div></section><section class="section"><div class="section-head"><div><span class="eyebrow">انتخاب‌های فروشگاه</span><h2>تجهیزات برای صدای دلخواه شما</h2></div><a class="text-link" href="/category">مشاهده همه ${icon("arrow")}</a></div><div class="product-grid">${products.items.map(productCard).join("")}</div></section><section class="section split-banner"><div><span class="eyebrow">خودرو و بودجه شما</span><h2>انتخاب سیستم را ساده‌تر کنید</h2><p>بودجه و خودروی خود را مشخص کنید؛ پیشنهادها را همراه با محدودیت‌های فنی ببینید.</p></div><a href="/configurator" class="btn">شروع سیستم‌یاب ${icon("arrow")}</a></section>${bundles.length ? `<section class="section"><div class="section-head"><h2>پکیج‌های هماهنگ</h2></div><div class="article-grid">${bundles.map((b) => `<article class="card padded"><span class="eyebrow">پکیج آماده</span><h3>${esc(b.title)}</h3><p class="small">${esc(b.description)}</p><div class="price">${b.available ? money(b.price) : "فعلاً قابل سفارش نیست"}</div><button class="btn mt" ${!b.available ? "disabled" : ""} data-add="${esc(b.id)}" data-kind="bundle">افزودن پکیج</button></article>`).join("")}</div></section>` : ""}<section class="section"><div class="section-head"><h2>برندهای کاتالوگ</h2></div><div class="brand-strip">${boot.brands.map((b) => `<a href="/category?brand=${encodeURIComponent(b.id)}">${esc(b.title)}</a>`).join("")}</div></section><section class="section"><div class="section-head"><div><span class="eyebrow">بیشتر بدانید</span><h2>مجله انتخاب و نصب</h2></div><a class="text-link" href="/blog">همه مطالب ${icon("arrow")}</a></div><div class="article-grid">${articles
    .slice(0, 3)
    .map(
      (a, i) =>
        `<article class="card article-card"><div class="article-num">0${i + 1}</div><span class="eyebrow">${esc(a.category)}</span><h3 class="mt">${esc(a.title)}</h3><p>${esc(a.summary)}</p><a class="text-link" href="/article/${encodeURIComponent(a.slug)}">مطالعه راهنما ${icon("arrow")}</a></article>`,
    )
    .join("")}</div></section>`;
}
async function catalog() {
  const params = new URLSearchParams(location.search);
  const path = decodeURIComponent(location.pathname).split("/");
  if (path[2]) params.set("cat", path[2]);
  const data = await api("products?" + params);
  const chosen = boot.categories.find(
    (c) => c.slug === params.get("cat") || c.id === params.get("cat"),
  );
  return `${head(chosen?.title || "فروشگاه تجهیزات صوتی", "محصولات", `<button class="btn secondary small" data-action="compare">مقایسه (${num(compare.length)})</button>`)}<div class="catalog-layout"><aside class="card filters" id="filters"><details class="filter-disclosure" ${matchMedia("(min-width: 801px)").matches ? "open" : ""}><summary>فیلتر و انتخاب دقیق‌تر ${icon("sliders")}</summary><form id="filter-form"><h3>انتخاب دقیق‌تر</h3>${field("q", "جستجو", params.get("q") || "", "search")}${select("cat", "دسته محصول", [["", "همه دسته‌ها"], ...boot.categories.map((x) => [x.id, x.title])], chosen?.id || "")}${select("brand", "برند", [["", "همه برندها"], ...boot.brands.map((x) => [x.id, x.title])], boot.brands.find((b) => b.id === params.get("brand") || b.slug === params.get("brand"))?.id || params.get("brand") || "")}${select("vehicle", "خودروی سازگار", [["", "همه خودروها"], ...boot.vehicles.map((x) => [x.id, x.title])], params.get("vehicle") || "")}<hr class="filter-divider"><div class="filter-inputs">${field("min", "از قیمت (تومان)", params.get("min") || "", "number")}${field("max", "تا قیمت (تومان)", params.get("max") || "", "number")}</div>${select(
    "size",
    "سایز اسپیکر",
    [
      ["", "همه سایزها"],
      ["4", "۴ اینچ"],
      ["5", "۵ اینچ"],
      ["6.5", "۶.۵ اینچ"],
      ["6x9", "۶×۹ اینچ"],
      ["12", "۱۲ اینچ"],
    ],
    params.get("size") || "",
  )}${select(
    "ohm",
    "امپدانس درج‌شده",
    [
      ["", "همه"],
      ["2", "۲ اهم"],
      ["4", "۴ اهم"],
      ["8", "۸ اهم"],
    ],
    params.get("ohm") || "",
  )}${select(
    "rms",
    "توان RMS",
    [
      ["", "همه توان‌ها"],
      ["0-50", "تا ۵۰ وات"],
      ["50-100", "۵۰ تا ۱۰۰ وات"],
      ["100-200", "۱۰۰ تا ۲۰۰ وات"],
      ["200-100000", "بیش از ۲۰۰ وات"],
    ],
    params.get("rms") || "",
  )}<label class="check"><input type="checkbox" name="instock" value="1" ${params.get("instock") ? "checked" : ""}>فقط کالاهای موجود</label><button class="btn block">اعمال فیلترها</button><a class="text-link mt" href="/category">پاک‌کردن فیلترها</a></form></details></aside><section><div class="sort-bar"><span>${data.total ? `نمایش ${num((data.page - 1) * data.limit + 1)} تا ${num(Math.min(data.page * data.limit, data.total))} از ${num(data.total)} محصول` : "محصولی پیدا نشد"}</span><button class="btn secondary small mobile-filter" data-action="filters">${icon("sliders")} فیلترها</button><select id="sort" aria-label="مرتب‌سازی">${[
    ["newest", "جدیدترین"],
    ["price_asc", "ارزان‌ترین"],
    ["price_desc", "گران‌ترین"],
    ["rating", "بیشترین امتیاز"],
  ]
    .map(
      ([v, t]) =>
        `<option value="${v}" ${params.get("sort") === v ? "selected" : ""}>${t}</option>`,
    )
    .join(
      "",
    )}</select></div>${data.items.length ? `<div class="product-grid">${data.items.map(productCard).join("")}</div>` : empty("محصول مطابقی پیدا نشد", "محدوده قیمت یا فیلترهای انتخاب‌شده را تغییر دهید.", "/category", "نمایش همه محصولات")}<div class="pagination">${Array.from(
    { length: data.pages },
    (_, i) => {
      const q = new URLSearchParams(params);
      q.set("page", i + 1);
      return `<a class="icon-btn ${data.page === i + 1 ? "current" : ""}" href="/category?${esc(q)}" aria-label="صفحه ${num(i + 1)}">${num(i + 1)}</a>`;
    },
  ).join("")}</div></section></div>`;
}
async function productPage(key) {
  const p = await api("products/" + encodeURIComponent(key));
  const actualReviews = await api(
    "reviews?product_id=" + encodeURIComponent(p.id),
  );
  return `${head(p.category_name, "محصولات", "", "h2")}<section class="product-detail"><div class="gallery"><img id="main-product-img" width="640" height="640" decoding="async" fetchpriority="high" src="${esc(imageURL(p.image))}" alt="${esc(p.title)}"><div class="thumbs">${p.images.map((src) => `<button data-thumb="${esc(src)}" aria-label="نمایش تصویر محصول"><img width="80" height="80" loading="lazy" decoding="async" src="${esc(imageURL(src, 320))}" alt="نمای دیگر ${esc(p.title)}"></button>`).join("")}</div></div><div class="detail-info"><a class="product-brand" href="/category?brand=${encodeURIComponent(p.brand_id)}">${esc(p.brand_name)}</a><h1>${esc(p.title)}</h1>${p.reviews_count ? `<p class="rating">★ ${num(p.rating.toFixed(1))} از ${num(p.reviews_count)} دیدگاه</p>` : '<p class="small muted">هنوز دیدگاهی برای این محصول منتشر نشده است.</p>'}<table class="spec-table"><tbody>${Object.entries(
    p.specs,
  )
    .slice(0, 5)
    .map(([k, v]) => `<tr><th>${esc(k)}</th><td>${esc(v)}</td></tr>`)
    .join(
      "",
    )}</tbody></table><div class="button-row mt"><button class="btn secondary small" data-compare="${esc(p.id)}">${icon("compare")} مقایسه</button><button class="btn secondary small" data-wish="${esc(p.id)}">${icon("heart")} علاقه‌مندی</button></div></div><aside class="card purchase"><span class="eyebrow">فروشنده: ${esc(boot.store.name)}</span><div class="price">${num(p.price)}<small>تومان</small></div><p class="stock-note">${p.stock > 0 ? `${num(p.stock)} عدد موجود` : "در حال حاضر ناموجود"}</p><div class="stepper"><button data-qty-detail="-1" aria-label="کاهش تعداد">−</button><span id="detail-qty" data-max="${p.stock}">۱</span><button data-qty-detail="1" aria-label="افزایش تعداد">+</button></div><button class="btn block mt" data-add-detail="${esc(p.id)}" ${p.stock < 1 ? "disabled" : ""}>${icon("cart")} افزودن به سبد</button><p class="small muted mt">${p.warranty_months ? `گارانتی درج‌شده: ${num(p.warranty_months)} ماه` : "شرایط گارانتی این کالا هنوز ثبت نشده است."}</p><p class="small muted">هزینه ارسال و خدمات پیش از ثبت سفارش نمایش داده می‌شود.</p></aside></section><section class="section card padded"><nav class="detail-tabs"><a href="#description">معرفی محصول</a><a href="#specifications">مشخصات فنی</a><a href="#reviews">دیدگاه‌ها</a></nav><div id="description" class="prose"><h2>نگاه نزدیک‌تر</h2><p>${esc(p.description || "توضیحات تکمیلی این محصول هنوز ثبت نشده است.")}</p></div><h2 id="specifications" class="mt">مشخصات محصول</h2><table class="spec-table"><tbody>${Object.entries(
    p.specs,
  )
    .map(([k, v]) => `<tr><th>${esc(k)}</th><td>${esc(v)}</td></tr>`)
    .join(
      "",
    )}</tbody></table><div class="notice">سازگاری ابعادی و الکتریکی با خودرو باید پیش از نصب بررسی شود. عددی که ثبت نشده، به‌صورت فرضی نمایش داده نمی‌شود.</div><h2 id="reviews" class="mt">دیدگاه خریداران</h2>${actualReviews.map((r) => `<article class="review"><div class="row"><strong>${esc(r.name)}</strong>${r.verified ? '<span class="tag good">خریدار تأییدشده</span>' : ""}<span class="rating">★ ${num(r.rating)}</span><span class="small muted">${stamp(r.created_at)}</span></div><p>${esc(r.text)}</p></article>`).join("") || '<p class="small muted">شما می‌توانید اولین دیدگاه را ثبت کنید.</p>'}${
    boot.user
      ? `<form id="review-form" class="mt" data-product="${esc(p.id)}">${select(
          "rating",
          "امتیاز شما",
          [
            [5, "۵ — بسیار خوب"],
            [4, "۴ — خوب"],
            [3, "۳ — متوسط"],
            [2, "۲ — ضعیف"],
            [1, "۱ — بسیار ضعیف"],
          ],
        )}${field("text", "تجربه شما", "", "textarea", true)}<button class="btn">ثبت دیدگاه برای بررسی</button></form>`
      : '<a class="btn secondary mt" href="/login">ورود برای ثبت دیدگاه</a>'
  }</section>`;
}
function summary(q, button = true) {
  return `<aside class="card summary"><h2>خلاصه سفارش</h2>${[
    ["بهای کالاها", q.subtotal],
    ["تخفیف کوپن", -q.coupon_amount],
    ["هزینه ارسال", q.shipping_amount],
    ["خدمات نصب", q.service_amount],
  ]
    .map(
      ([k, v]) =>
        `<div class="summary-row"><span>${k}</span><b>${money(v)}</b></div>`,
    )
    .join(
      "",
    )}<div class="summary-total"><span class="small">مبلغ نهایی</span><div class="price">${num(q.total_amount)}<small>تومان</small></div></div>${button ? '<a class="btn block" href="/cart?step=delivery">ادامه و اطلاعات تحویل ' + icon("arrow") + "</a>" : ""}<p class="small muted mt">تمام مبالغ به تومان است. هزینه ارسال و خدمات در مبلغ نهایی لحاظ شده است.</p></aside>`;
}
let coupon = "",
  serviceId = "",
  detailQty = 1,
  deliveryAddresses = [];
async function cartPage() {
  const stored = sessionStorage.getItem("amir_pending_order");
  if (stored) {
    const command = JSON.parse(stored);
    try {
      const recovered = await api("orders/recover", { method: "POST", body: { key: command.key } });
      if (recovered.id) {
        completeCheckout();
        history.replaceState({}, "", "/order/" + encodeURIComponent(recovered.id));
        return orderPage(recovered.id);
      }
    } catch (e) {
      if (e.status !== 419 && e.status && e.status < 500) toast(e.message, true);
    }
    if (command.scope !== boot.checkout_scope)
      return `${head("بررسی درخواست قبلی")}<div class="notice error">نشست خرید تغییر کرده است. سفارش‌های حساب یا نتیجه درخواست با شناسه <bdi dir="ltr">${esc(command.key)}</bdi> را با پشتیبانی بررسی کنید.</div><div class="button-row"><a class="btn" href="/profile">بررسی سفارش‌ها</a><button class="btn secondary" data-action="clear-pending">نتیجه را بررسی کردم</button></div>`;
    return `${head("ادامه ثبت سفارش")}<form id="retry-checkout-form" class="card padded"><p>پاسخ ثبت سفارش هنوز دریافت نشده است. با بررسی دوباره، همان درخواست و همان شماره عملیات به فروشگاه ارسال می‌شود.</p><div class="form-error" role="alert"></div><button class="btn">بررسی و ادامه سفارش</button></form>`;
  }
  if (!cart.length)
    return empty(
      "سبد شما منتظر انتخاب شماست",
      "از بین تجهیزات فروشگاه، قطعات مورد نیاز خود را انتخاب کنید.",
    );
  try {
    quote = await api("cart/quote", {
      method: "POST",
      body: { items: cart, coupon, service_id: serviceId || undefined },
    });
  } catch (e) {
    return `${head("بررسی سبد خرید")}<div class="notice error">${esc(e.message)}</div><div class="button-row">${coupon ? '<button class="btn secondary" data-action="remove-coupon">حذف کد تخفیف</button>' : ""}${serviceId ? '<button class="btn secondary" data-action="remove-service">حذف خدمات نصب</button>' : ""}</div><div class="card padded">${cart.map((c, i) => `<div class="row mt"><span>قلم ${num(i + 1)} — تعداد ${num(c.qty)}</span><button class="btn secondary small" data-cart-qty="${i}" data-delta="-1">کاهش تعداد</button><button class="btn secondary small" data-remove="${i}">حذف از سبد</button></div>`).join("")}</div>`;
  }
  const delivery =
    new URLSearchParams(location.search).get("step") === "delivery";
  deliveryAddresses =
    delivery && boot.user ? (await api("auth/profile")).addresses : [];
  return `${head("سبد خرید و تکمیل سفارش")}<div class="steps"><span class="${!delivery ? "active" : ""}">۱. بررسی سبد</span><span class="${delivery ? "active" : ""}">۲. اطلاعات تحویل</span><span>۳. ثبت و پرداخت</span></div><div class="cart-layout"><section>${
    !delivery
      ? `${quote.items.map((p, i) => `<article class="card cart-line"><img src="${esc(p.image || "/images/placeholder.svg")}" alt="${esc(p.title)}"><div><h3>${esc(p.title)}</h3><span class="small muted">${money(p.unit_price)} برای هر واحد</span></div><div class="stepper"><button data-cart-qty="${cart.findIndex((c) => c.id === p.id && (c.kind || "product") === p.kind)}" data-delta="-1" aria-label="کاهش تعداد">−</button><span>${num(p.qty)}</span><button data-cart-qty="${cart.findIndex((c) => c.id === p.id && (c.kind || "product") === p.kind)}" data-delta="1" aria-label="افزایش تعداد">+</button></div><div class="price line-price">${num(p.line_total)}<small>تومان</small></div><button class="icon-btn remove" data-remove="${cart.findIndex((c) => c.id === p.id && (c.kind || "product") === p.kind)}" aria-label="حذف ${esc(p.title)}">${icon("trash")}</button></article>`).join("")}<form id="coupon-form" class="card padded"><label class="small" for="coupon">کد تخفیف دارید؟</label><div class="coupon mt"><input id="coupon" name="coupon" value="${esc(coupon)}" placeholder="کد تخفیف" dir="ltr"><button class="btn secondary">بررسی کد</button></div>${quote.coupon_code ? `<p class="small stock-note mt">کد ${esc(quote.coupon_code)} معتبر است؛ تخفیف در مبلغ نهایی لحاظ شده است.</p>` : ""}<div class="form-error" role="alert"></div></form><form id="service-form" class="card padded mt">${select("service_id", "خدمات نصب", [["", "بدون خدمات نصب"], ...boot.services.map((s) => [s.id, `${s.title} — ${money(s.price_toman)}`])], serviceId)}<button class="btn secondary small">محاسبه خدمات</button><p class="small muted mt">درخواست نصب پس از ثبت، نیازمند هماهنگی زمان با فروشگاه است.</p></form>`
      : `<form id="checkout-form" class="card padded"><h2>اطلاعات تحویل</h2>${deliveryAddresses.length ? select("saved_address", "انتخاب از آدرس‌های شما", [["", "آدرس جدید"], ...deliveryAddresses.map((a) => [a.id, a.name + " — " + a.city + "، " + a.address])]) : ""}<div class="form-grid">${field("customer_name", "نام و نام خانوادگی تحویل‌گیرنده", boot.user?.name || "", "text", true)}${field("mobile", "شماره همراه", boot.user?.phone || "", "tel", true)}${field("province", "استان", "", "text", true)}${field("city", "شهر", "", "text", true)}${field("address", "آدرس کامل", "", "textarea", true, "wide")}${field("postal_code", "کد پستی ۱۰ رقمی", "", "text", true)}</div>${!boot.user ? '<p class="small muted">برای دسترسی به سفارش از دستگاه‌های دیگر، وارد حساب شوید یا پس از خرید در همین مرورگر حساب بسازید.</p>' : ""}<h3>روش پرداخت</h3><div class="payment-options">${[
          ["online", "درگاه آنلاین", boot.payment.online],
          ["cod", "پرداخت در محل", boot.payment.cod],
          ["transfer", "واریز بانکی", boot.payment.transfer],
        ]
          .filter((x) => x[2])
          .map(
            ([v, t], i) =>
              `<label class="payment-option"><input type="radio" name="payment_method" value="${v}" ${i === 0 ? "checked" : ""}>${t}</label>`,
          )
          .join(
            "",
          )}</div>${!Object.values(boot.payment).some(Boolean) ? '<div class="notice error">هیچ روش پرداختی توسط فروشگاه فعال نشده است.</div>' : ""}<div class="notice">با ثبت سفارش، موجودی رزرو و شماره پیگیری ایجاد می‌شود. پرداخت آنلاین فقط پس از تأیید درگاه نهایی خواهد شد.</div><div class="form-error"></div><div class="button-row"><button class="btn" ${!Object.values(boot.payment).some(Boolean) ? "disabled" : ""}>ثبت سفارش و ادامه ${icon("arrow")}</button><a class="btn secondary" href="/cart">بازگشت به سبد</a></div></form>`
  }</section>${summary(quote, !delivery)}</div>`;
}
async function authPage(register = false, setup = false) {
  if (boot.user && !setup)
    return `<div class="empty"><h2>شما وارد حساب شده‌اید</h2><a class="btn" href="${["admin", "manager"].includes(boot.user.role) ? "/admin" : "/profile"}">ادامه به حساب</a></div>`;
  return `<section class="card form-card"><span class="eyebrow">${setup ? "راه‌اندازی امن فروشگاه" : "به امیر سیستم خوش آمدید"}</span><h1 class="mt">${setup ? "ساخت مدیر اصلی" : register ? "ایجاد حساب کاربری" : "ورود به حساب"}</h1><p>${setup ? "کلید نصب را از تنظیمات سرور وارد کنید. هیچ رمز پیش‌فرضی در برنامه وجود ندارد." : register ? "برای مشاهده سفارش‌ها و ثبت دیدگاه حساب بسازید." : "با شماره همراه و رمز عبور خود وارد شوید."}</p><form id="auth-form" data-mode="${setup ? "setup" : register ? "register" : "login"}">${setup ? field("setup_token", "کلید نصب", "", "password", true) : ""}${register || setup ? field("name", "نام و نام خانوادگی", "", "text", true) : ""}${field("phone", "شماره همراه", "", "tel", true)}${field("password", "رمز عبور", "", "password", true)}<div class="form-error"></div><button class="btn block">${setup ? "ساخت مدیر و ورود" : register ? "ثبت‌نام" : "ورود به حساب"}</button></form>${!setup ? `<p class="mt">${register ? "حساب دارید؟" : "حساب ندارید؟"} <a class="text-link" href="${register ? "/login" : "/register"}">${register ? "وارد شوید" : "ثبت‌نام کنید"}</a></p>` : ""}</section>`;
}
function shipmentSummary(o) {
  const ship = o.shipment;
  if (!ship) return "";
  return `<section class="notice shipment-summary"><h3>اطلاعات ارسال</h3><p>${esc(ship.carrier)} — کد رهگیری <bdi dir="ltr">${esc(ship.tracking_code)}</bdi></p>${ship.shipped_at || ship.delivered_at ? `<p class="small">${ship.shipped_at ? "ارسال " + stamp(ship.shipped_at, true) : ""}${ship.shipped_at && ship.delivered_at ? " · " : ""}${ship.delivered_at ? "تحویل " + stamp(ship.delivered_at, true) : ""}</p>` : ""}</section>`;
}
function customerOrderActions(o) {
  const transferOpen = o.payment_method === "transfer" && o.payment_status === "unpaid" &&
    ["pending_payment", "confirmed"].includes(o.status);
  return `${o.status === "payment_review" ? '<p class="notice" role="status">وجه دریافت شده و سفارش برای بررسی پرداخت به فروشگاه ارجاع شده است.</p>' : ""}
  ${o.status === "pending_payment" && o.expires_at ? `<p class="small muted">مهلت رزرو تا ${stamp(o.expires_at, true)}</p>` : ""}
  ${transferOpen ? `<form id="transfer-receipt-form" class="card padded mt" data-id="${esc(o.id)}"><h3>واریز بانکی</h3><p class="transfer-instructions">${esc(o.quote.transfer_instructions || "برای دریافت اطلاعات واریز با فروشگاه تماس بگیرید.")}</p>${o.transfer_receipt ? '<p class="notice">مرجع رسید ثبت شده و در انتظار تأیید فروشگاه است.</p>' : ""}${field("reference", "شماره پیگیری رسید بانکی", o.transfer_receipt?.reference || "", "text", true)}<div class="form-error" role="alert"></div><button class="btn secondary">ثبت مرجع واریز</button></form>` : ""}
  ${o.can_cancel ? `<button type="button" class="btn secondary mt" data-cancel-order="${esc(o.id)}">لغو سفارش</button>` : ""}`;
}
async function orderPage(oid, invoice = false) {
  const o = await api("orders/" + encodeURIComponent(oid));
  return `<section class="card padded receipt">${invoice ? `<div class="invoice-head"><div><span class="eyebrow">صورت‌حساب سفارش</span><h1>${esc(boot.store.name)}</h1></div><b>${esc(o.tracking_code)}</b></div>` : `<span class="eyebrow">سفارش شما ثبت شده است</span><h1 class="mt tracking-title">${esc(o.tracking_code)}</h1>`}<div class="row">${tag(o.status)}${tag(o.payment_status)}<span class="small muted">${stamp(o.created_at)}</span></div><div class="notice">${o.payment_status === "refunded" ? "برگشت وجه این سفارش ثبت شده است." : o.payment_status === "paid" ? "پرداخت این سفارش تأیید شده است." : ["cancelled", "expired"].includes(o.status) ? "رزرو این سفارش پایان یافته است." : o.payment_method === "online" ? "برای نهایی‌شدن پرداخت، از دکمه پرداخت آنلاین استفاده کنید." : "سفارش ثبت شده است و تأیید دریافت وجه در انتظار است."}</div><div class="form-grid small"><p><b>تحویل‌گیرنده:</b> ${esc(o.customer_name)}</p><p><b>شماره تماس:</b> ${esc(o.mobile)}</p><p class="wide"><b>آدرس:</b> ${esc(o.province)}، ${esc(o.city)}، ${esc(o.address)} — ${esc(o.postal_code)}</p></div><div class="table-scroll"><table class="data"><thead><tr><th>شرح کالا</th><th>تعداد</th><th>بهای واحد</th><th>جمع ردیف</th></tr></thead><tbody>${o.items.map((i) => `<tr><td class="wrap">${esc(i.title)}</td><td>${num(i.qty)}</td><td>${money(i.unit_price)}</td><td>${money(i.line_total)}</td></tr>`).join("")}</tbody></table></div><div class="mt">${[
    ["کالاها", o.subtotal],
    ["تخفیف", -o.coupon_amount],
    ["ارسال", o.shipping_amount],
    ["خدمات", o.service_amount],
    ["مبلغ نهایی", o.total_amount],
  ]
    .map(
      ([k, v]) =>
        `<div class="summary-row"><span>${k}</span><strong>${money(v)}</strong></div>`,
    )
    .join(
      "",
    )}</div>${shipmentSummary(o)}${!invoice ? `<div class="no-print">${customerOrderActions(o)}</div>` : ""}<div class="button-row mt no-print">${!invoice && o.payment_method === "online" && o.status === "pending_payment" && o.payment_status !== "paid" ? `<button class="btn" data-pay="${esc(o.id)}">پرداخت آنلاین ${icon("lock")}</button>` : ""}${!invoice && o.can_recheck_online ? `<button class="btn secondary" data-recheck-payment="${esc(o.id)}">استعلام از درگاه</button>` : ""}${invoice ? `<a class="btn secondary" href="/order/${encodeURIComponent(o.id)}">جزئیات سفارش</a>` : `<a class="btn secondary" href="/invoice/${encodeURIComponent(o.id)}">نمای صورت‌حساب</a>`}<button class="btn secondary" data-action="print">${icon("print")} چاپ</button><a class="text-link" href="/profile">حساب و سفارش‌ها</a></div></section>`;
}
async function profilePage() {
  if (!boot.user) return authPage();
  const user = await api("auth/profile");
  const tab = new URLSearchParams(location.search).get("tab") || "orders";
  let content = "";
  if (tab === "profile")
    content = `<form id="profile-form" class="card padded"><h2>اطلاعات حساب</h2>${field("name", "نام و نام خانوادگی", user.user.name, "text", true)}<p class="small muted">شماره ورود: ${esc(user.user.phone)}</p><button class="btn">ذخیره اطلاعات</button></form><form id="password-form" class="card padded mt"><h3>تغییر رمز عبور</h3>${field("current_password", "رمز فعلی", "", "password", true)}${field("password", "رمز جدید (حداقل ۱۰ نویسه)", "", "password", true)}<button class="btn secondary">تغییر رمز و پایان نشست‌های دیگر</button><div class="form-error"></div></form>`;
  else if (tab === "addresses")
    content = `<div class="card padded"><h2>آدرس‌های من</h2>${user.addresses.map((a) => `<article class="review"><strong>${esc(a.name)}</strong><p>${esc(a.province)}، ${esc(a.city)}، ${esc(a.address)}</p><p class="small">${esc(a.phone)} — ${esc(a.postal_code)}</p><button class="btn secondary small" data-delete-address="${esc(a.id)}">حذف آدرس</button></article>`).join("") || '<p class="small muted">هنوز آدرسی ذخیره نشده است.</p>'}</div><form id="address-form" class="card padded mt"><h3>افزودن آدرس</h3><div class="form-grid">${field("name", "نام تحویل‌گیرنده", user.user.name, "text", true)}${field("phone", "شماره همراه", user.user.phone, "tel", true)}${field("province", "استان", "", "text", true)}${field("city", "شهر", "", "text", true)}${field("address", "آدرس کامل", "", "textarea", true, "wide")}${field("postal_code", "کد پستی", "", "text", true)}</div><button class="btn">ذخیره آدرس</button></form>`;
  else if (tab === "wishlist") {
    const items = await Promise.all(
      wishlist.map((id) =>
        api("products/" + encodeURIComponent(id)).catch(() => null),
      ),
    );
    content = `<div class="product-grid">${items.filter(Boolean).map(productCard).join("")}</div>`;
    if (!items.filter(Boolean).length)
      content = empty(
        "هنوز علاقه‌مندی ندارید",
        "در صفحه محصول، روی دکمه علاقه‌مندی بزنید.",
      );
  } else {
    const orders = await api("orders");
    content = `<div class="card padded"><h2>سفارش‌های من</h2>${orders.length ? `<div class="table-scroll"><table class="data"><thead><tr><th>شماره پیگیری</th><th>تاریخ</th><th>مبلغ</th><th>وضعیت</th><th></th></tr></thead><tbody>${orders.map((o) => `<tr><td>${esc(o.tracking_code)}</td><td>${stamp(o.created_at)}</td><td>${money(o.total_amount)}</td><td>${tag(o.status)}</td><td><a class="text-link" href="/order/${o.id}">جزئیات</a></td></tr>`).join("")}</tbody></table></div>` : empty("هنوز سفارشی ثبت نکرده‌اید", "انتخاب‌های شما اینجا قابل پیگیری خواهند بود.", "/category", "شروع خرید")}</div>`;
  }
  return `${head("حساب کاربری")}<div class="account-layout"><aside class="card account-nav">${[
    ["orders", "cart", "سفارش‌ها"],
    ["profile", "user", "اطلاعات حساب"],
    ["addresses", "truck", "آدرس‌ها"],
    ["wishlist", "heart", "علاقه‌مندی‌ها"],
  ]
    .map(
      ([v, i, t]) =>
        `<a href="/profile?tab=${v}" class="${v === tab ? "active" : ""}">${icon(i)}${t}</a>`,
    )
    .join(
      "",
    )}${["admin", "manager"].includes(boot.user.role) ? '<a href="/admin">مدیریت فروشگاه</a>' : ""}<button data-action="logout">${icon("logout")}خروج از حساب</button></aside><section>${content}</section></div>`;
}
async function configuratorPage() {
  return `${head("سیستم مناسب خودروی شما")}<div class="card padded"><span class="eyebrow">خودرو، بودجه، سبک شنیداری</span><h2 class="mt">از نیازتان شروع کنیم</h2><p class="small muted">پیشنهادها سقف بودجه را رعایت می‌کنند. سازگاری فنیِ ثبت‌نشده به‌صورت قطعی وعده داده نمی‌شود.</p><form id="configurator-form" class="fit-bar">${select("vehicle_id", "خودرو", [["", "خودرو را انتخاب کنید"], ...boot.vehicles.map((v) => [v.id, v.title])], "", true)}${select(
    "profile",
    "هدف شما",
    [
      ["oem", "ارتقای ساده"],
      ["sq", "وضوح و تفکیک"],
      ["spl", "بیس و قدرت"],
    ],
  )}${field("budget", "سقف بودجه (تومان)", "", "number", true)}<button class="btn">پیشنهاد قطعات ${icon("arrow")}</button></form></div><div id="config-results" class="section"></div>`;
}
async function contactPage() {
  const s = boot.store;
  return `${head("کنار شما هستیم", "تماس با ما")}<div class="contact-layout"><section class="card contact-info"><span class="eyebrow">ارتباط مستقیم</span><h2 class="mt">پرسشی درباره انتخاب یا سفارش دارید؟</h2><p class="small">پیام شما در پنل پشتیبانی فروشگاه ثبت می‌شود.</p>${[
    ["تلفن", s.phone],
    ["ایمیل", s.email],
    ["نشانی فروشگاه", s.address],
    ["ساعت پاسخ‌گویی", s.hours],
  ]
    .filter((x) => x[1])
    .map(([k, v]) => `<h3>${k}</h3><p>${esc(v)}</p>`)
    .join(
      "",
    )}${s.latitude != null && s.longitude != null ? `<a class="btn secondary mt" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(s.latitude + "," + s.longitude)}" target="_blank" rel="noopener noreferrer">مسیریابی فروشگاه ${icon("arrow")}</a>` : ""}</section><form id="contact-form" class="card padded"><h2>ارسال پیام به پشتیبانی</h2><div class="form-grid">${field("name", "نام شما", boot.user?.name || "", "text", true)}${field("phone", "شماره همراه", boot.user?.phone || "", "tel", true)}${field("email", "ایمیل (اختیاری)", "", "email")}${select(
    "subject",
    "موضوع",
    [
      ["انتخاب محصول", "انتخاب محصول"],
      ["پیگیری سفارش", "پیگیری سفارش"],
      ["هماهنگی نصب", "هماهنگی نصب"],
      ["سایر", "سایر"],
    ],
  )}${field("message", "پیام شما", "", "textarea", true, "wide")}</div><div class="form-error"></div><button class="btn">ثبت پیام ${icon("arrow")}</button></form></div>`;
}
async function warrantyPage() {
  return `${head("استعلام گارانتی")}<section class="card padded"><span class="eyebrow">شرایط ثبت‌شده برای کالای شما</span><h2 class="mt">شماره سریال را بررسی کنید</h2><p class="small muted">شماره سریال درج‌شده روی کارت گارانتی فروشگاه را وارد کنید. اطلاعات شخصی خریدار نمایش داده نمی‌شود.</p><form id="warranty-form" class="coupon">${field("serial", "شماره سریال", "", "text", true)}<button class="btn">استعلام</button></form><div id="warranty-result"></div></section><section class="section card padded prose"><h2>پیش از ارسال کالا برای بررسی</h2><p>مدت و پوشش گارانتی برای هر کالا باید در صفحه همان محصول و مدارک خرید بررسی شود. درباره ایراد مشاهده‌شده و شماره سفارش، با پشتیبانی هماهنگ کنید.</p><p>شرایطی که برای محصول ثبت نشده است، به‌صورت گارانتی عمومی یا مدت فرضی نمایش داده نمی‌شود.</p><a class="text-link" href="/contact">ارتباط با پشتیبانی ${icon("arrow")}</a></section>`;
}
async function blogPage(key) {
  if (key) {
    const a = await api("articles/" + encodeURIComponent(key));
    return `${head(a.title, "مجله")}<article class="card padded prose"><span class="eyebrow">${esc(a.category)} — ${stamp(a.created_at)}</span><p class="mt"><strong>${esc(a.summary)}</strong></p><p>${esc(a.body)}</p><a href="/category" class="text-link">مشاهده تجهیزات ${icon("arrow")}</a></article>`;
  }
  const articles = await api("articles");
  return `${head("مجله انتخاب و نصب")}<div class="article-grid">${articles.map((a, i) => `<article class="card article-card"><div class="article-num">${String(i + 1).padStart(2, "0")}</div><span class="eyebrow">${esc(a.category)}</span><h2 class="mt">${esc(a.title)}</h2><p>${esc(a.summary)}</p><a class="text-link" href="/article/${encodeURIComponent(a.slug)}">مطالعه راهنما ${icon("arrow")}</a></article>`).join("")}</div>`;
}
const adminSections = [
  ["overview", "داشبورد"],
  ["products", "محصولات"],
  ["orders", "سفارش‌ها و فاکتورها"],
  ["stock_movements", "انبار و رسیدها"],
  ["categories", "دسته‌بندی‌ها"],
  ["brands", "برندها"],
  ["vehicles", "خودروها"],
  ["bundles", "پکیج‌های قطعه‌محور"],
  ["coupons", "کدهای تخفیف"],
  ["reviews", "دیدگاه‌ها"],
  ["contacts", "پیام‌های پشتیبانی"],
  ["warranties", "گارانتی‌ها"],
  ["services", "خدمات نصب"],
  ["articles", "مجله و راهنماها"],
  ["users", "کاربران و دسترسی"],
  ["ledger_entries", "اسناد دریافت و برگشت"],
  ["audit_logs", "سوابق تغییرات"],
  ["health", "سلامت و آمادگی نصب"],
  ["settings", "تنظیمات فروشگاه"],
];
let adminRows = [],
  adminKind = "overview",
  adminProducts = [];
const actionButton = (action, id, label, cls = "secondary") =>
  `<button class="btn ${cls} small" data-admin-action="${action}" data-id="${esc(id)}">${label}</button>`;
async function loadAdminProducts() {
  if (adminProducts.length) return adminProducts;
  let p = 1,
    pages = 1;
  do {
    const data = await api("admin/products?limit=60&page=" + p);
    adminProducts.push(...data.items);
    pages = data.pages;
    p++;
  } while (p <= pages);
  return adminProducts;
}
const adminFilterOptions = {
  orders: [
    ...["pending_payment", "confirmed", "processing", "shipped", "delivered", "cancelled", "expired", "payment_review", "refunded"].map((k) => [k, statuses[k]]),
    ["transfer_review", "رسید واریز در انتظار بررسی"],
    ["unknown_payments", "پرداخت آنلاین نامشخص"],
    ["return_expected", "مرجوعی در انتظار انبار"],
  ],
  users: [...Object.entries(userRoles), ["inactive", "حساب غیرفعال"]],
  stock_movements: Object.entries(stockReasons),
  products: [["in", "دارای موجودی"], ["low", "موجودی کم"], ["out", "ناموجود"]],
};
const adminSearchHints = {
  products: "نام، مدل یا برند کالا",
  orders: "شماره سفارش، رهگیری ارسال، نام یا موبایل",
  users: "نام یا موبایل",
  stock_movements: "نام کالا، شناسه کالا یا شماره سفارش",
};
function adminFilters(kind, params) {
  if (!adminFilterOptions[kind]) return "";
  const filterName = kind === "products" ? "stock" : "status";
  return `<form id="admin-filter-form" class="card padded admin-filters"><input type="hidden" name="section" value="${esc(kind)}">
    ${params.get("user_id") && kind === "orders" ? `<input type="hidden" name="user_id" value="${esc(params.get("user_id"))}"><p class="small muted wide">نمایش سفارش‌های حساب انتخاب‌شده</p>` : ""}
    ${field("q", adminSearchHints[kind], params.get("q") || "", "search")}
    ${select(filterName, kind === "stock_movements" ? "نوع گردش" : "فیلتر", [["", "همه"], ...adminFilterOptions[kind]], params.get(filterName) || "")}
    <div class="button-row"><button class="btn secondary">جستجو</button><a class="text-link" href="/admin?section=${kind}">پاک کردن فیلترها</a></div></form>`;
}
function adminPagination(result, params) {
  if (!result.pages || result.pages < 2) return "";
  const link = (page, label) => {
    const query = new URLSearchParams(params);
    query.set("page", page);
    return `<a class="btn secondary small" href="/admin?${esc(query.toString())}">${label}</a>`;
  };
  return `<nav class="pagination" aria-label="صفحه‌بندی">${result.page > 1 ? link(result.page - 1, "قبلی") : ""}<span class="small">صفحه ${num(result.page)} از ${num(result.pages)}</span>${result.page < result.pages ? link(result.page + 1, "بعدی") : ""}</nav>`;
}
async function adminPage() {
  if (!boot.user) return authPage();
  if (!["admin", "manager"].includes(boot.user.role))
    return empty(
      "این بخش برای مدیر فروشگاه است",
      "حساب شما دسترسی مدیریتی ندارد.",
      "/profile",
      "حساب من",
    );
  const params = new URLSearchParams(location.search);
  adminKind = params.get("section") || "overview";
  if (!adminSections.some(([key]) => key === adminKind)) adminKind = "overview";
  const name =
    adminSections.find((x) => x[0] === adminKind)?.[1] || "مدیریت فروشگاه";
  let content = "";
  if (adminKind === "overview") {
    const data = await api("admin/overview");
    content = `<div class="welcome-box"><div><span class="eyebrow">اتاق کنترل فروشگاه</span><h2 class="mt">${esc(boot.user.name)}، خوش آمدید</h2><p>سفارش‌ها، موجودی و دریافت‌های تأییدشده را از همین‌جا دنبال کنید.</p></div>${icon("chart")}</div><div class="stats-grid">${[
      ["محصولات ثبت‌شده", num(data.products), "کاتالوگ فروشگاه"],
      ["سفارش‌ها", num(data.orders), "همه وضعیت‌ها"],
      ["دریافت تأییدشده", money(data.paid_amount), "بر مبنای پرداخت ثبت‌شده"],
      ["موجودی کم", num(data.low_stock), "کالاهای فعال با کمتر از ۳ عدد"],
    ]
      .map(
        ([t, v, d]) =>
          `<article class="card stat"><span class="label">${t}</span><div class="value">${v}</div><span class="hint">${d}</span></article>`,
      )
      .join(
        "",
      )}</div><section class="card notice-list"><h3>نیازمند رسیدگی</h3><p>${num(data.new_messages)} پیام تازه در پشتیبانی</p><p>${num(data.pending_reviews)} دیدگاه در انتظار بررسی</p><ul class="order-history"><li>${num(data.ready_to_pack)} سفارش در صف آماده‌سازی</li><li><a class="text-link" href="/admin?section=orders&status=transfer_review">${num(data.transfer_review)} رسید واریز در انتظار تأیید</a></li><li><a class="text-link" href="/admin?section=orders&status=unknown_payments">${num(data.unknown_payments)} پرداخت آنلاین نامشخص</a></li><li><a class="text-link" href="/admin?section=orders&status=payment_review">${num(data.payment_review)} دریافت دیرهنگام نیازمند رسیدگی</a></li><li><a class="text-link" href="/admin?section=orders&status=return_expected">${num(data.returns_expected)} مرجوعی در انتظار دریافت انبار</a></li></ul><div class="button-row"><a class="btn secondary small" href="/admin?section=orders">مدیریت سفارش‌ها</a><a class="btn secondary small" href="/admin?section=stock_movements">بررسی انبار</a></div></section><section class="card notice-list"><h3>کنترل‌های فروشگاه</h3><p>مبلغ سفارش در سرور محاسبه می‌شود. موجودی و سندهای مالی با عملیات مربوط به خود ثبت می‌شوند. برای قیمت، گارانتی و مشخصات کالا اطلاعات تأییدشده وارد کنید.</p></section>`;
  } else if (adminKind === "health") {
    const data = await api("admin/health");
    const rows = [...data.checks, ...data.deployment_checks];
    const labels = {
      pass: "سالم",
      warn: "نیازمند تنظیم",
      fail: "نیازمند رسیدگی",
    };
    content = `<section class="card padded"><h2>سلامت داده و تنظیمات استقرار</h2><p class="muted mt">موتور دیتابیس: ${esc(data.database)} — نسخهٔ ساختار: ${num(data.schema_version)}</p><div class="notice ${data.integrity_ok ? "" : "error"}">${data.integrity_ok ? "در بررسی‌های انجام‌شده ناسازگاری داده پیدا نشد." : "ناسازگاری داده پیدا شد؛ پیش از اصلاح، پشتیبان بگیرید و علت را بررسی کنید."}</div><div class="table-scroll"><table class="data"><thead><tr><th>بررسی</th><th>نتیجه</th><th>تعداد مغایرت</th></tr></thead><tbody>${rows.map((c) => `<tr><td class="wrap">${esc(c.label)}</td><td><span class="tag ${c.status === "pass" ? "good" : "orange"}">${labels[c.status] || esc(c.status)}</span></td><td>${c.count === undefined ? "—" : num(c.count)}</td></tr>`).join("")}</tbody></table></div><p class="mt">${num(data.pending_payment_reviews)} سفارش نیازمند بررسی پرداخت؛ ${num(data.unknown_payments)} درخواست پرداخت با نتیجهٔ نامشخص.</p><p class="small muted">${data.online_payment_configured ? "اطلاعات درگاه تنظیم شده است؛ انجام تراکنش واقعی هنوز باید تأیید شود." : "درگاه آنلاین تنظیم نشده است."}</p><div class="notice">${esc(data.notice)}</div><button class="btn secondary" data-action="refresh-health">بررسی دوباره</button></section>`;
  } else if (adminKind === "settings") {
    const data = await api("admin/settings");
    content = `<form id="settings-form" class="card padded"><div class="form-grid">${field("name", "نام فروشگاه", data.name, "text", true)}${field("tagline", "معرفی کوتاه", data.tagline)}${field("phone", "تلفن پاسخ‌گویی", data.phone, "tel")}${field("email", "ایمیل پاسخ‌گویی", data.email, "email")}${field("address", "آدرس دقیق فروشگاه", data.address, "textarea", false, "wide")}${field("hours", "ساعت کاری", data.hours)}${field("currency_rate", "نرخ هر دلار (تومان)", data.currency_rate, "number", true)}${field("shipping_fee", "هزینه ارسال عادی (تومان)", data.shipping_fee, "number", true)}${field("free_shipping_from", "ارسال رایگان از مبلغ (تومان)", data.free_shipping_from, "number", true)}${field("installation_free_from", "نصب رایگان از مبلغ (تومان)", data.installation_free_from, "number", true)}${field("latitude", "عرض جغرافیایی", data.latitude ?? "", "number")}${field("longitude", "طول جغرافیایی", data.longitude ?? "", "number")}${field("about", "درباره فروشگاه", data.about, "textarea", false, "wide")}${field("transfer_instructions", "راهنمای واریز بانکی برای خریدار", data.transfer_instructions || "", "textarea", false, "wide")}</div><label class="check"><input name="cod_enabled" type="checkbox" ${data.cod_enabled ? "checked" : ""}>پرداخت در محل فعال باشد</label><label class="check"><input name="transfer_enabled" type="checkbox" ${data.transfer_enabled ? "checked" : ""}>واریز بانکی با تأیید دستی فعال باشد</label><p class="small muted">نرخ دستی به‌صورت پایدار ذخیره می‌شود و فقط قیمت‌های دلاری را تغییر می‌دهد. اطلاعات محرمانه درگاه در تنظیمات سرور قرار می‌گیرد.</p><div class="form-error"></div><button class="btn">ذخیره تنظیمات</button></form>`;
  } else {
    const query = new URLSearchParams(params);
    query.delete("section");
    if (adminKind === "products") query.set("limit", "30");
    const result = await api("admin/" + adminKind + "?" + query.toString());
    adminRows = Array.isArray(result) ? result : result.items;
    const columns =
      adminKind === "products"
        ? ["محصول", "قیمت", "قابل فروش", "رزرو ارسال", "وضعیت", "عملیات"]
        : adminKind === "orders"
          ? ["شماره و تاریخ سفارش", "خریدار", "مبلغ", "وضعیت سفارش", "پرداخت", "عملیات"]
          : adminKind === "stock_movements"
            ? ["کالا", "تغییر تعداد", "موجودی بعد", "نوع و مرجع", "مسئول و زمان"]
            : adminKind === "contacts"
              ? ["نام", "تماس", "موضوع و پیام", "وضعیت", "عملیات"]
              : adminKind === "reviews"
                ? ["کالا", "امتیاز", "دیدگاه", "وضعیت", "عملیات"]
                : adminKind === "users"
                  ? ["نام", "شماره همراه", "دسترسی", "وضعیت", "سفارش‌ها", "عملیات"]
                  : adminKind === "ledger_entries"
                    ? ["شرح سند", "بدهکار", "بستانکار", "تاریخ"]
                    : adminKind === "audit_logs"
                      ? ["عملیات", "شناسه", "مدیر", "تاریخ"]
                      : adminKind === "coupons"
                        ? ["کد", "عنوان", "نوع و مقدار", "مصرف", "عملیات"]
                        : adminKind === "warranties"
                          ? ["سریال", "کالا", "شروع", "پایان", "عملیات"]
                          : ["عنوان", "شناسه", "وضعیت", "عملیات"];
    let rows = adminRows
      .map((r) => {
        let cells = [];
        if (adminKind === "products")
          cells = [
            `<strong>${esc(r.title)}</strong>`,
            money(r.price),
            num(r.stock),
            num(r.reserved),
            tag(r.active ? "active" : "archived"),
            actionButton("edit", r.id, "ویرایش") + actionButton("stock", r.id, "رسید انبار"),
          ];
        else if (adminKind === "orders")
          cells = [
            `<bdi dir="ltr">${esc(r.tracking_code)}</bdi><small class="muted cell-note">${stamp(r.created_at)}</small>`,
            `${esc(r.customer_name)}<small class="muted cell-note">${esc(r.mobile)}</small>`,
            money(r.total_amount),
            `${tag(r.status)}${r.shipment_tracking ? `<small class="muted cell-note">رهگیری <bdi dir="ltr">${esc(r.shipment_tracking)}</bdi></small>` : ""}`,
            `${tag(r.payment_status)}<small class="muted cell-note">${paymentLabels[r.payment_method]}${r.transfer_reference && r.payment_status === "unpaid" ? " · رسید ثبت شده" : ""}</small>`,
            actionButton("order", r.id, "جزئیات"),
          ];
        else if (adminKind === "stock_movements")
          cells = [
            esc(r.product_title),
            `<bdi>${r.delta > 0 ? "+" : ""}${num(r.delta)}</bdi>`,
            num(r.balance),
            `${esc(stockReasons[r.reason] || r.reason)}${r.order_tracking ? `<button class="btn secondary small cell-note" data-admin-action="order" data-id="${esc(r.order_id)}">${esc(r.order_tracking)}</button>` : ""}${r.notes ? `<small class="muted cell-note">${esc(r.notes)}</small>` : ""}`,
            `${esc(r.actor || "سیستم")}<small class="muted cell-note">${stamp(r.created_at)}</small>`,
          ];
        else if (adminKind === "contacts")
          cells = [
            esc(r.name),
            esc(r.phone),
            `<b>${esc(r.subject)}</b><p>${esc(r.message)}</p>`,
            tag(r.status),
            actionButton("contact-read", r.id, "خوانده شد") +
              actionButton("contact-answered", r.id, "پاسخ داده شد"),
          ];
        else if (adminKind === "reviews")
          cells = [
            esc(r.product_id),
            num(r.rating),
            esc(r.text),
            tag(r.status),
            actionButton("approve", r.id, "انتشار") +
              actionButton("reject", r.id, "رد"),
          ];
        else if (adminKind === "users")
          cells = [
            esc(r.name),
            esc(r.phone),
            esc(userRoles[r.role]),
            tag(r.active ? "active" : "archived"),
            `<a class="text-link" href="/admin?section=orders&user_id=${encodeURIComponent(r.id)}">${num(r.order_count)}</a>`,
            actionButton("edit", r.id, "ویرایش") + (r.id !== boot.user.id ? actionButton("revoke", r.id, "پایان نشست‌ها") : ""),
          ];
        else if (adminKind === "ledger_entries")
          cells = [
            esc(r.description),
            money((r.lines || []).reduce((s, l) => s + Number(l.debit), 0)),
            money((r.lines || []).reduce((s, l) => s + Number(l.credit), 0)),
            stamp(r.created_at),
          ];
        else if (adminKind === "audit_logs")
          cells = [
            esc(r.action),
            esc(r.entity_id),
            esc(r.actor_id || "سیستم"),
            stamp(r.created_at),
          ];
        else if (adminKind === "coupons")
          cells = [
            esc(r.code),
            esc(r.title),
            `${r.type === "percentage" ? num(r.value) + "٪" : money(r.value)}`,
            `${num(r.used_count)} از ${num(r.usage_limit)}`,
            actionButton("edit", r.id, "ویرایش"),
          ];
        else if (adminKind === "warranties")
          cells = [
            esc(r.serial),
            esc(r.product_id),
            esc(r.starts_at),
            esc(r.expires_at),
            actionButton("edit", r.id, "ویرایش"),
          ];
        else
          cells = [
            esc(r.title),
            esc(r.slug || r.id),
            r.active === undefined ? "—" : r.active ? "فعال" : "غیرفعال",
            actionButton("edit", r.id, "ویرایش"),
          ];
        return `<tr>${cells.map((v, i) => `<td class="${i === 0 || (adminKind === "contacts" && i === 2) ? "wrap" : ""}" data-label="${esc(columns[i])}"><div class="cell-value">${v}</div></td>`).join("")}</tr>`;
      })
      .join("");
    const inventoryLinks = adminKind === "stock_movements"
      ? '<div class="button-row mt"><a class="btn secondary small" href="/admin?section=products">موجودی کالاها</a><a class="btn secondary small" href="/admin?section=products&stock=low">موجودی کم</a><a class="btn secondary small" href="/admin?section=products&stock=out">ناموجودها</a></div>' : "";
    content = `${inventoryLinks}${adminFilters(adminKind, params)}
      <div class="card"><div class="table-scroll"><table class="data${adminFilterOptions[adminKind] ? " operational-table" : ""}"><thead><tr>${columns.map((x) => `<th scope="col">${x}</th>`).join("")}</tr></thead><tbody>${rows || `<tr><td colspan="${columns.length}"><div class="empty"><p>رکوردی برای نمایش پیدا نشد.</p></div></td></tr>`}</tbody></table></div></div>
      ${adminPagination(result, params)}<div id="admin-editor"></div>`;

  }
  const canCreate = [
    "products",
    "categories",
    "brands",
    "vehicles",
    "bundles",
    "coupons",
    "warranties",
    "articles",
    "services",
  ].includes(adminKind);
  return `${head("مدیریت فروشگاه", "پنل مدیریت", `<a class="text-link" href="/">نمای فروشگاه ${icon("arrow")}</a>`)}<div class="admin"><aside class="card admin-nav">${adminSections
    .filter(
      ([key]) =>
        boot.user.role === "admin" ||
        !["users", "settings", "coupons", "warranties", "services"].includes(
          key,
        ),
    )
    .map(
      ([key, t]) =>
        `<a href="/admin?section=${key}" class="${adminKind === key ? "active" : ""}">${t}</a>`,
    )
    .join(
      "",
    )}</aside><section><div class="admin-top"><h2>${name}</h2><div class="button-row">${canCreate ? '<button class="btn small" data-admin-action="new">+ افزودن</button>' : ""}${adminKind === "stock_movements" ? '<button class="btn small" data-admin-action="stock">ثبت یا بررسی رسید انبار</button>' : ""}</div></div>${content}</section></div>`;
}
async function adminEditor(row = null) {
  let fields = "",
    kind = adminKind;
  if (kind === "products") {
    if (row) row = await api("admin/products/" + encodeURIComponent(row.id));
    fields = `<div class="form-grid">${field("title", "عنوان کامل", row?.title || "", "text", true)}${field("slug", "نشانی کوتاه", row?.slug || "", "text", true)}${select(
      "category_id",
      "دسته‌بندی",
      boot.categories.map((x) => [x.id, x.title]),
      row?.category_id,
      true,
    )}${select(
      "brand_id",
      "برند",
      boot.brands.map((x) => [x.id, x.title]),
      row?.brand_id,
      true,
    )}${field("price_toman", "قیمت فروش (تومان)", row?.price || 0, "number", true)}${select(
      "pricing_mode",
      "مبنای قیمت",
      [
        ["fixed", "قیمت ثابت تومانی"],
        ["usd", "قیمت دلاری"],
      ],
      row?.pricing_mode || "fixed",
    )}${field("price_usd", "قیمت دلاری", row?.price_usd || 0, "number")}${field("discount_usd", "کاهش قیمت دلاری", row?.discount_usd || 0, "number")}${!row ? field("stock", "موجودی اولیه", 0, "number") : ""}${field("warranty_months", "مدت گارانتی ثبت‌شده (ماه)", row?.warranty_months || "", "number")}${field("description", "معرفی و توضیحات", row?.description || "", "textarea", false, "wide")}${field("images_text", "آدرس تصاویر، هر تصویر در یک سطر", (row?.images || []).join("\n"), "textarea", false, "wide")}${field(
      "specs_text",
      "مشخصات، هر سطر به شکل «عنوان: مقدار»",
      Object.entries(row?.specs || {})
        .map(([k, v]) => `${k}: ${v}`)
        .join("\n"),
      "textarea",
      false,
      "wide",
    )}</div><label class="check"><input type="checkbox" name="active" ${!row || row.active ? "checked" : ""}>قابل نمایش و خرید</label><label class="check"><input type="checkbox" name="featured" ${row?.featured ? "checked" : ""}>محصول منتخب</label><h3 class="mt">سازگاری با خودرو</h3><p class="small muted">فقط تطابق بررسی‌شده را «تأییدشده» انتخاب کنید.</p><div class="form-grid">${boot.vehicles
      .map((v) =>
        select(
          "fit_" + v.id,
          v.title,
          [
            ["", "ثبت نشده"],
            ["unverified", "پیشنهادی؛ نیازمند بررسی نصب"],
            ["verified", "سازگاری تأییدشده"],
          ],
          (row?.vehicle_fits || []).find((f) => f.vehicle_id === v.id)?.verified
            ? "verified"
            : (row?.vehicle_fits || []).some((f) => f.vehicle_id === v.id)
              ? "unverified"
              : "",
        ),
      )
      .join(
        "",
      )}</div><p class="small muted">تغییر موجودی محصولات موجود از بخش رسید انبار انجام می‌شود. قیمت تومانی در حالت دلاری، بر اساس نرخ ذخیره‌شده محاسبه می‌شود.</p>`;
  } else if (["categories", "brands", "vehicles"].includes(kind))
    fields = `<div class="form-grid">${field("title", "عنوان", row?.title || "", "text", true)}${field("slug", "نشانی کوتاه", row?.slug || "", "text", true)}</div>`;
  else if (kind === "coupons")
    fields = `<div class="form-grid">${field("code", "کد تخفیف", row?.code || "", "text", true)}${field("title", "عنوان", row?.title || "", "text", true)}${select(
      "type",
      "نوع تخفیف",
      [
        ["percentage", "درصدی"],
        ["fixed", "مبلغ ثابت (تومان)"],
      ],
      row?.type || "percentage",
    )}${field("value", "مقدار تخفیف", row?.value || 0, "number", true)}${field("min_purchase", "حداقل خرید (تومان)", row?.min_purchase || 0, "number")}${field("max_discount", "حداکثر تخفیف (تومان)", row?.max_discount ?? "", "number")}${field("usage_limit", "سقف تعداد مصرف", row?.usage_limit || 100, "number", true)}${field("starts_at", "آغاز (میلادی YYYY-MM-DD HH:mm:ss)", row?.starts_at || "")}${field("expires_at", "پایان (میلادی YYYY-MM-DD HH:mm:ss)", row?.expires_at || "")}</div><label class="check"><input type="checkbox" name="active" ${!row || row.active ? "checked" : ""}>فعال</label>`;
  else if (kind === "bundles") {
    const products = await loadAdminProducts();
    fields = `<div class="form-grid">${field("title", "عنوان پکیج", row?.title || "", "text", true)}${field("slug", "نشانی کوتاه", row?.slug || "", "text", true)}${field("discount_percent", "تخفیف پکیج (درصد)", (row?.discount_bps || 0) / 100, "number", true)}${field("description", "شرح پکیج", row?.description || "", "textarea", false, "wide")}</div><h3>قطعات تشکیل‌دهنده</h3><div id="bundle-parts">${(row?.items?.length ? row.items : [{ qty: 1 }, { qty: 1 }]).map((part, i) => `<div class="form-grid">${select("part_" + i, "کالا", [["", "انتخاب کنید"], ...products.map((p) => [p.id, p.title])], part.product_id || "")}${field("part_qty_" + i, "تعداد هر کالا", part.qty, "number")}</div>`).join("")}</div><button type="button" class="btn secondary small" data-action="add-bundle-part">افزودن ردیف قطعه</button><label class="check mt"><input type="checkbox" name="active" ${row?.active ? "checked" : ""}>آماده فروش (پس از تعیین قطعات)</label>`;
  } else if (kind === "articles")
    fields = `<div class="form-grid">${field("title", "عنوان", row?.title || "", "text", true)}${field("slug", "نشانی کوتاه", row?.slug || "", "text", true)}${field("category", "دسته مطلب", row?.category || "راهنما")}${field("summary", "خلاصه", row?.summary || "", "textarea", false, "wide")}${field("body", "متن کامل", row?.body || "", "textarea", true, "wide")}</div><label class="check"><input type="checkbox" name="published" ${!row || row.published ? "checked" : ""}>منتشر شود</label>`;
  else if (kind === "services")
    fields = `${field("title", "نام خدمت", row?.title || "", "text", true)}${field("price_toman", "هزینه (تومان)", row?.price_toman || 0, "number", true)}<label class="check"><input name="active" type="checkbox" ${!row || row.active ? "checked" : ""}>فعال</label>`;
  else if (kind === "warranties") {
    const products = await loadAdminProducts();
    fields = `<div class="form-grid">${field("serial", "شماره سریال", row?.serial || "", "text", true)}${select(
      "product_id",
      "کالا",
      products.map((p) => [p.id, p.title]),
      row?.product_id,
      true,
    )}${field("order_id", "شناسه سفارش (اختیاری)", row?.order_id || "")}${field("starts_at", "شروع (میلادی)", row?.starts_at || "", "date", true)}${field("expires_at", "پایان (میلادی)", row?.expires_at || "", "date", true)}${select(
      "status",
      "وضعیت",
      [
        ["active", "فعال"],
        ["void", "باطل‌شده"],
      ],
      row?.status || "active",
    )}</div>`;
  } else if (kind === "users")
    fields = `${field("name", "نام کاربر", row.name, "text", true)}${select(
      "role",
      "سطح دسترسی",
      [
        ["customer", "مشتری"],
        ["manager", "مدیر فروش"],
        ["admin", "مدیر اصلی"],
      ],
      row.role,
    )}<label class="check"><input name="active" type="checkbox" ${row.active ? "checked" : ""}>حساب فعال باشد</label><div class="notice">تغییر نقش یا غیرفعال‌کردن حساب، نشست‌های آن کاربر را باطل می‌کند. رمز عبور از این فرم قابل مشاهده نیست.</div>`;
  $("#admin-editor").innerHTML =
    `<form id="admin-edit-form" class="card admin-editor" data-kind="${kind}" data-id="${esc(row?.id || "")}" data-version="${row?.version || ""}"><div class="admin-top"><h3>${row ? "ویرایش اطلاعات" : "افزودن رکورد"}</h3><button type="button" class="icon-btn" data-action="close-editor" aria-label="بستن فرم">${icon("close")}</button></div>${fields}<div class="form-error"></div><button class="btn mt">ذخیره اطلاعات</button></form>`;
  $("#admin-editor").scrollIntoView({ behavior: "smooth", block: "start" });
}
async function showStockReceipt(id = "") {
  const editor = $("#admin-editor");
  if (sessionStorage.getItem("amir_pending_stock")) {
    editor.innerHTML = `<form id="retry-stock-form" class="card admin-editor"><h3>بررسی رسید قبلی</h3><p>دریافت پاسخ ثبت رسید کامل نشده است. برای تعیین نتیجه، همان رسید دوباره بررسی می‌شود.</p><div class="form-error" role="alert"></div><button class="btn">بررسی رسید قبلی</button></form>`;
  } else {
    const products = await loadAdminProducts();
    const selected = products.find((p) => p.id === id);
    editor.innerHTML = `<form id="stock-form" class="card admin-editor" data-event="${commandId()}" data-expected-stock="${selected?.stock ?? 0}"><h3>ثبت رسید انبار</h3>
      <p class="small muted">ورود، خروج و شمارش بر مبنای موجودی قابل فروش ثبت می‌شود. اقلام رزروشده جداگانه نگهداری می‌شوند.</p>
      ${select("product_id", "کالا", [["", "انتخاب کالا"], ...products.map((p) => [p.id, p.title + " — " + num(p.stock) + " قابل فروش"])], id, true)}
      ${select("type", "نوع عملیات", [["in", "ورود کالا"], ["out", "خروج کالا"], ["adjust", "شمارش موجودی قابل فروش"]])}
      ${field("quantity", "تعداد / موجودی نهایی", 0, "number", true)}${field("notes", "توضیحات رسید")}
      <div class="form-error" role="alert"></div><button class="btn">ثبت رسید</button></form>`;
  }
  editor.scrollIntoView({ behavior: "smooth", block: "start" });
}
async function showAdminOrder(oid) {
  const o = await api("admin/orders/" + encodeURIComponent(oid));
  const target = $("#admin-editor");
  if (!target) return;
  const owner = boot.user.role === "admin";
  const next = o.next_statuses;
  const canRecord = owner && o.payment_status === "unpaid" &&
    (o.payment_method === "transfer" || (o.payment_method === "cod" && !["cancelled", "expired"].includes(o.status)));
  const shipped = o.shipment || ["shipped", "delivered"].includes(o.status);
  const orderEvents = o.events.map((e) => {
    const details = JSON.parse(e.details_json || "{}");
    const label = {
      "order.created": "ثبت سفارش",
      "order.status": statuses[details.to],
      "payment.manual": "ثبت دریافت وجه",
      "payment.unknown_reviewed": "تعیین تکلیف درخواست درگاه",
      "payment.verified": "تأیید پرداخت آنلاین",
      "payment.transfer_submitted": "ثبت مرجع واریز توسط مشتری",
      "payment.refunded": "ثبت بازپرداخت",
      "order.return_received": "دریافت کالا در انبار",
      "order.address_updated": "اصلاح اطلاعات تحویل",
      "order.shipment_updated": "اصلاح کد رهگیری ارسال",
    }[e.action];
    return label ? `<li>${esc(label)} <small class="muted">${stamp(e.created_at, true)}${e.actor ? " · " + esc(e.actor) : ""}</small></li>` : "";
  }).join("");
  target.innerHTML = `<section class="card admin-editor">
    <div class="admin-top"><h3>سفارش <bdi dir="ltr">${esc(o.tracking_code)}</bdi></h3><button type="button" class="icon-btn" data-action="close-editor" aria-label="بستن جزئیات">${icon("close")}</button></div>
    <div class="row">${tag(o.status)}${tag(o.payment_status)}<span class="small">${paymentLabels[o.payment_method]}</span></div>
    <p class="small mt">${esc(o.customer_name)} · ${esc(o.mobile)}</p><p class="small">${esc(o.province)}، ${esc(o.city)}، ${esc(o.address)} · ${esc(o.postal_code)}</p>
    ${shipmentSummary(o)}${o.transfer_receipt ? `<p class="notice">مرجع اعلام‌شده توسط مشتری <bdi dir="ltr">${esc(o.transfer_receipt.reference)}</bdi> · ${stamp(o.transfer_receipt.submitted_at)}</p>` : ""}
    ${o.status === "payment_review" ? '<p class="notice">وجه پس از آزاد شدن موجودی تأیید شده است. مرجع بازپرداخت را پس از بازگشت واقعی وجه ثبت کنید.</p>' : ""}
    <div class="table-scroll"><table class="data"><thead><tr><th>کالا</th><th>تعداد</th><th>جمع</th></tr></thead><tbody>${o.items.map((i) => `<tr><td class="wrap">${esc(i.title)}</td><td>${num(i.qty)}</td><td>${money(i.line_total)}</td></tr>`).join("")}</tbody></table></div>
    <div class="summary-row"><span>مبلغ نهایی</span><b>${money(o.total_amount)}</b></div>
    <div class="order-actions">
      ${["pending_payment", "confirmed", "processing"].includes(o.status) ? `<details class="order-action"><summary>اصلاح اطلاعات تحویل</summary><form id="order-address-form" data-id="${esc(oid)}"><div class="form-grid">${field("customer_name", "تحویل‌گیرنده", o.customer_name, "text", true)}${field("mobile", "شماره همراه", o.mobile, "tel", true)}${field("province", "استان", o.province, "text", true)}${field("city", "شهر", o.city, "text", true)}${field("address", "نشانی", o.address, "textarea", true, "wide")}${field("postal_code", "کد پستی", o.postal_code, "text", true)}</div><div class="form-error" role="alert"></div><button class="btn secondary">ذخیره اطلاعات تحویل</button></form></details>` : ""}
      ${o.status === "shipped" ? `<details class="order-action"><summary>${o.shipment ? "اصلاح رهگیری" : "تکمیل رهگیری ارسال قبلی"}</summary><form id="order-shipment-form" data-id="${esc(oid)}"><div class="form-grid">${field("carrier", "شرکت حمل یا روش ارسال", o.shipment?.carrier || "", "text", true)}${field("tracking_code", "کد رهگیری مرسوله", o.shipment?.tracking_code || "", "text", true)}</div><div class="form-error" role="alert"></div><button class="btn secondary">ذخیره رهگیری</button></form></details>` : ""}
      ${next.filter((x) => x !== "shipped").length ? `<form id="order-status-form" class="order-action" data-id="${esc(oid)}">${select("status", "تغییر وضعیت", next.filter((x) => x !== "shipped").map((x) => [x, statuses[x]]))}<div class="form-error" role="alert"></div><button class="btn">ثبت وضعیت</button></form>` : ""}
      ${next.includes("shipped") ? `<form id="ship-order-form" class="order-action" data-id="${esc(oid)}"><h3>ثبت ارسال</h3><div class="form-grid">${field("carrier", "شرکت حمل یا روش ارسال", "", "text", true)}${field("tracking_code", "کد رهگیری مرسوله", "", "text", true)}</div><div class="form-error" role="alert"></div><button class="btn">ثبت ارسال و کد رهگیری</button></form>` : ""}
      ${o.status === "shipped" && o.payment_status === "unpaid" ? '<p class="notice">پس از ثبت دریافت وجه، ثبت تحویل فعال می‌شود.</p>' : ""}
      ${canRecord ? `<form id="record-payment-form" class="order-action" data-id="${esc(oid)}"><h3>ثبت دریافت وجه</h3><p class="small muted">مرجع را پس از تطبیق با دریافت واقعی ثبت کنید.</p>${field("reference", "شماره رسید یا مرجع دریافت", o.transfer_receipt?.reference || "", "text", true)}<div class="form-error" role="alert"></div><button class="btn secondary">ثبت دریافت تأییدشده</button></form>` : ""}
      ${owner ? o.unresolved_payments.map((payment) => `<form class="order-action" data-payment-review data-id="${esc(oid)}"><h3>رسیدگی به پرداخت نامشخص</h3><p class="small muted">سفارش و مبلغ را با پنل پذیرنده تطبیق دهید. در صورت وجود تراکنش، شناسهٔ درگاه را وارد و سپس «استعلام از درگاه» را بزنید. برای بستن درخواست فاقد تراکنش، ابتدا سفارش را ببندید و شناسه را خالی بگذارید.</p><input type="hidden" name="attempt_id" value="${esc(payment.id)}">${field("authority", "شناسهٔ درگاه (Authority)") }<label class="check"><input type="checkbox" name="confirmed" required>نتیجه را در پنل پذیرنده بررسی کردم</label><div class="form-error" role="alert"></div><button class="btn secondary">ثبت نتیجه بررسی</button></form>`).join("") : ""}
      ${owner && o.payment_status === "paid" ? `<details class="order-action"><summary>ثبت برگشت کامل وجه</summary><form id="refund-form" data-id="${esc(oid)}"><p class="small muted">مرجع بازپرداخت انجام‌شده را وارد کنید.${shipped ? "" : " موجودی رزروشده به‌صورت خودکار آزاد می‌شود."}</p>${field("refund_reference", "مرجع بازپرداخت", "", "text", true)}${shipped && !o.stock_released ? '<label class="check"><input type="checkbox" name="restock">همه اقلام سالم دریافت شده و به موجودی فروش برمی‌گردند</label>' : ""}<label class="check"><input type="checkbox" name="confirmed" required>بازپرداخت کامل انجام شده است</label><div class="form-error" role="alert"></div><button class="btn secondary">ثبت سند برگشت وجه</button></form></details>` : ""}
      ${o.status === "refunded" && !o.stock_released ? `<form id="return-form" class="order-action" data-id="${esc(oid)}"><h3>دریافت مرجوعی در انبار</h3><label class="check"><input type="checkbox" name="confirmed" required>همه اقلام سالم تحویل انبار شده و قابل فروش هستند</label><div class="form-error" role="alert"></div><button class="btn secondary">بازگرداندن اقلام به موجودی</button></form>` : ""}
    </div>
    ${orderEvents ? `<details class="order-action"><summary>سوابق سفارش</summary><ol class="order-history">${orderEvents}</ol></details>` : ""}
    <div class="button-row mt">${o.can_recheck_online ? `<button type="button" class="btn secondary" data-recheck-payment="${esc(oid)}" data-admin-payment="1">استعلام از درگاه</button>` : ""}<a class="text-link" href="/invoice/${encodeURIComponent(oid)}">مشاهده صورت‌حساب ${icon("arrow")}</a></div></section>`;
  target.scrollIntoView({ behavior: "smooth", block: "start" });
}
async function refreshAdminOrder(id) {
  await route();
  return showAdminOrder(id);
}
async function compareView() {
  const products = (
    await Promise.all(
      compare.map((id) =>
        api("products/" + encodeURIComponent(id)).catch(() => null),
      ),
    )
  ).filter(Boolean);
  if (!products.length)
    return toast("ابتدا در کارت محصولات، کالاهای مقایسه را انتخاب کنید.");
  const specs = [...new Set(products.flatMap((p) => Object.keys(p.specs)))];
  $("#dialog").innerHTML =
    `<div class="dialog-head"><h2>مقایسه محصولات</h2><button data-action="close-dialog" aria-label="بستن">×</button></div><div class="table-scroll"><table class="data compare"><thead><tr><th>ویژگی</th>${products.map((p) => `<th><img src="${esc(p.image)}" alt="${esc(p.title)}"><a href="/product/${encodeURIComponent(p.slug)}">${esc(p.title)}</a><p>${money(p.price)}</p><button class="btn secondary small" data-compare="${esc(p.id)}">حذف از مقایسه</button></th>`).join("")}</tr></thead><tbody>${specs.map((k) => `<tr><th>${esc(k)}</th>${products.map((p) => `<td>${esc(p.specs[k] ?? "ثبت نشده")}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`;
  $("#dialog").showModal();
}
function completeCheckout() {
  sessionStorage.removeItem("amir_pending_order");
  cart = [];
  coupon = "";
  serviceId = "";
  saveCart();
}
async function submitCheckout(command, retry = false) {
  if (!command || command.scope !== boot.checkout_scope) return route();
  try {
    const o = await api("orders", { method: "POST", key: command.key, body: command.body });
    completeCheckout();
    return navigate("/order/" + encodeURIComponent(o.id));
  } catch (e) {
    if (e.requestId && [400, 404, 409, 422].includes(e.status)) {
      sessionStorage.removeItem("amir_pending_order");
      if (retry) {
        await route();
        toast(e.message, true);
        return;
      }
    }
    throw e;
  }
}
function formData(form) {
  return Object.fromEntries(new FormData(form));
}
async function formTask(form, task) {
  const btn = $("button[type=submit], button:not([type])", form);
  if (btn?.disabled) return;
  const label = btn?.innerHTML;
  if (btn) {
    btn.disabled = true;
    btn.textContent = "در حال بررسی…";
  }
  try {
    const box = $(".form-error", form);
    if (box) {
      box.classList.remove("notice", "error");
      box.replaceChildren();
    }
    await task();
  } catch (e) {
    const box = $(".form-error", form);
    if (box) {
      box.classList.add("notice", "error");
      box.textContent = e.message;
    } else toast(e.message, true);
  } finally {
    if (btn?.isConnected) {
      btn.disabled = false;
      btn.innerHTML = label;
    }
  }
}
async function route() {
  const current = ++seq;
  document.documentElement.classList.add("is-navigating");
  $("#app").setAttribute("aria-busy", "true");
  let path; try { path = decodeURIComponent(location.pathname); } catch { path = "/not-found"; }
  const parts = path.split("/").filter(Boolean);
  let content;
  try {
    if (path === "/") content = await home();
    else if (parts[0] === "category") content = await catalog();
    else if (parts[0] === "product" && parts[1]) {
      detailQty = 1;
      content = await productPage(parts.slice(1).join("/"));
    } else if (path === "/cart") content = await cartPage();
    else if (path === "/login") content = await authPage();
    else if (path === "/register") content = await authPage(true);
    else if (path === "/setup") content = await authPage(true, true);
    else if (parts[0] === "order" || parts[0] === "invoice")
      content = await orderPage(parts[1], parts[0] === "invoice");
    else if (path === "/profile") content = await profilePage();
    else if (path === "/configurator") content = await configuratorPage();
    else if (path === "/contact") content = await contactPage();
    else if (path === "/warranty") content = await warrantyPage();
    else if (path === "/blog") content = await blogPage();
    else if (parts[0] === "article") content = await blogPage(parts[1]);
    else if (path === "/admin") content = await adminPage();
    else if (path === "/about")
      content = `${head("درباره فروشگاه")}<article class="card padded prose"><span class="eyebrow">${esc(boot.store.name)}</span><h2 class="mt">انتخاب دقیق، تجربه شنیداری بهتر</h2><p>${esc(boot.store.about)}</p><a class="btn" href="/contact">ارتباط با فروشگاه</a></article>`;
    else
      content = empty(
        "صفحه پیدا نشد",
        "نشانی را بررسی کنید.",
        "/",
        "صفحه اصلی",
      );
  } catch (e) {
    content = `<div class="notice error mt">${esc(e.message)}</div>${empty("این بخش فعلاً در دسترس نیست", "اگر اتصال شما برقرار است، کمی بعد دوباره تلاش کنید.", "/", "بازگشت به فروشگاه")}`;
  }
  if (current !== seq) return;
  $("#app").innerHTML =
    header() +
    `<main id="main" tabindex="-1" class="container">${content}</main>` +
    footer();
  document.documentElement.classList.remove("is-navigating");
  $("#app").removeAttribute("aria-busy");
  document.title =
    ($("h1")?.innerText.replace(/\s+/g, " ") || "فروشگاه") +
    " | " +
    boot.store.name;
  if (path.startsWith("/invoice/")) document.title = "صورت‌حساب سفارش | امیر سیستم";
  saveCart();
}
async function navigate(url, replace = false) {
  const dest = new URL(url, location.origin);
  if (dest.origin !== location.origin) {
    location.href = dest.href;
    return;
  }
  $("#dialog").open && $("#dialog").close();
  history[replace ? "replaceState" : "pushState"](
    {},
    "",
    dest.pathname + dest.search + dest.hash,
  );
  await route();
  $("#main")?.focus({ preventScroll: true });
  if (dest.hash)
    document
      .getElementById(decodeURIComponent(dest.hash.slice(1)))
      ?.scrollIntoView();
  else scrollTo({ top: 0, behavior: "instant" });
}
document.addEventListener("click", async (e) => {
  const link = e.target.closest("a");
  if (
    link &&
    !link.target &&
    !link.hasAttribute("download") &&
    link.href.startsWith(location.origin) &&
    !e.ctrlKey &&
    !e.metaKey &&
    !e.shiftKey
  ) {
    const u = new URL(link.href);
    if (
      u.pathname === location.pathname &&
      u.search === location.search &&
      u.hash
    )
      return;
    e.preventDefault();
    navigate(link.href);
    return;
  }
  const b = e.target.closest("button");
  if (!b || b.disabled) return;
  try {
    if (b.dataset.compare) {
      e.preventDefault();
      const id = b.dataset.compare;
      if (compare.includes(id)) compare = compare.filter((x) => x !== id);
      else {
        if (compare.length >= 4)
          return toast("حداکثر چهار محصول را می‌توانید مقایسه کنید.", true);
        compare.push(id);
      }
      preferences.setItem("amir_compare_v2", JSON.stringify(compare));
      if ($("#dialog").open) {
        $("#dialog").close();
        if (compare.length) await compareView();
      } else toast("فهرست مقایسه به‌روزرسانی شد.");
    } else if (b.dataset.add) {
      e.preventDefault();
      addCart(b.dataset.add, 1, b.dataset.kind || "product");
    } else if (b.dataset.addDetail) {
      addCart(b.dataset.addDetail, detailQty);
    } else if (b.dataset.qtyDetail) {
      const max = Number($("#detail-qty").dataset.max);
      detailQty = Math.max(
        1,
        Math.min(max, detailQty + Number(b.dataset.qtyDetail)),
      );
      $("#detail-qty").textContent = num(detailQty);
    } else if (b.dataset.thumb) {
      $("#main-product-img").src = imageURL(b.dataset.thumb);
    } else if (b.dataset.wish) {
      const id = b.dataset.wish;
      wishlist = wishlist.includes(id)
        ? wishlist.filter((x) => x !== id)
        : [...wishlist, id];
      preferences.setItem("amir_wishlist_v2", JSON.stringify(wishlist));
      toast("فهرست علاقه‌مندی به‌روزرسانی شد.");
    } else if (b.dataset.remove !== undefined) {
      cart.splice(Number(b.dataset.remove), 1);
      saveCart();
      await route();
    } else if (b.dataset.cartQty !== undefined) {
      const i = Number(b.dataset.cartQty);
      if (!cart[i]) return;
      const previous = cart[i].qty;
      const delta = Number(b.dataset.delta);
      cart[i].qty = Math.max(1, Math.min(99, previous + delta));
      if (delta > 0) {
        try {
          await api("cart/quote", {
            method: "POST",
            body: { items: cart, coupon, service_id: serviceId || undefined },
          });
        } catch (e) {
          cart[i].qty = previous;
          throw e;
        }
      }
      saveCart();
      await route();
    } else if (b.dataset.deleteAddress) {
      await api(
        "auth/addresses/" + encodeURIComponent(b.dataset.deleteAddress),
        { method: "DELETE" },
      );
      await route();
    } else if (b.dataset.cancelOrder) {
      if (!confirm("سفارش لغو و موجودی رزروشده آزاد شود؟")) return;
      b.disabled = true;
      try {
        await api("orders/" + encodeURIComponent(b.dataset.cancelOrder) + "/cancel", { method: "POST", body: {} });
        toast("سفارش لغو شد.");
        await route();
      } finally {
        b.disabled = false;
      }
    } else if (b.dataset.recheckPayment) {
      b.disabled = true;
      try {
        await api("payment/recheck", { method: "POST", body: { order_id: b.dataset.recheckPayment } });
        toast("وضعیت پرداخت از درگاه دریافت شد.");
        if (b.dataset.adminPayment) await refreshAdminOrder(b.dataset.recheckPayment);
        else await route();
      } finally {
        b.disabled = false;
      }
    } else if (b.dataset.pay) {
      b.disabled = true;
      try {
        const pay = await api("payment/request", {
          method: "POST",
          body: { order_id: b.dataset.pay },
        });
        location.href = pay.url;
      } finally {
        b.disabled = false;
      }
    } else if (b.dataset.adminAction) {
      const action = b.dataset.adminAction,
        id = b.dataset.id;
      const r = adminRows.find((x) => x.id === id);
      if (action === "new") await adminEditor();
      else if (action === "edit") await adminEditor(r);
      else if (action === "order") await showAdminOrder(id);
      else if (action === "approve" || action === "reject") {
        await api("admin/reviews/" + id, {
          method: "PUT",
          body: { status: action === "approve" ? "approved" : "rejected" },
        });
        await route();
      } else if (action.startsWith("contact-")) {
        await api("admin/contacts/" + id, {
          method: "PUT",
          body: { status: action === "contact-read" ? "read" : "answered" },
        });
        await route();
      } else if (action === "revoke") {
        if (!confirm("همه نشست‌های فعال این کاربر پایان یابد؟")) return;
        const result = await api("admin/users/" + encodeURIComponent(id) + "/revoke-sessions", { method: "POST", body: {} });
        toast(`${num(result.revoked)} نشست پایان یافت.`);
      } else if (action === "stock") {
        await showStockReceipt(id);
      }
    } else if (b.dataset.action === "theme") {
      const dark = document.documentElement.dataset.theme !== "dark";
      document.documentElement.dataset.theme = dark ? "dark" : "light";
      preferences.setItem("amir_theme", dark ? "dark" : "light");
    } else if (b.dataset.action === "remove-coupon" || b.dataset.action === "remove-service") {
      if (b.dataset.action === "remove-coupon") coupon = "";
      else serviceId = "";
      await route();
    } else if (b.dataset.action === "clear-pending") {
      sessionStorage.removeItem("amir_pending_order");
      await route();
    } else if (b.dataset.action === "compare") await compareView();
    else if (b.dataset.action === "close-dialog") $("#dialog").close();
    else if (b.dataset.action === "filters")
      $(".filter-disclosure").open = !$(".filter-disclosure").open;
    else if (b.dataset.action === "print") window.print();
    else if (b.dataset.action === "refresh-health") await route();
    else if (b.dataset.action === "close-editor")
      $("#admin-editor").replaceChildren();
    else if (b.dataset.action === "logout") {
      await api("auth/logout", { method: "POST", body: {} });
      boot = await api("bootstrap");
      await navigate("/login");
    } else if (b.dataset.action === "add-bundle-part") {
      const n = $$("#bundle-parts select").length;
      const products = await loadAdminProducts();
      $("#bundle-parts").insertAdjacentHTML(
        "beforeend",
        `<div class="form-grid">${select("part_" + n, "کالا", [["", "انتخاب کنید"], ...products.map((p) => [p.id, p.title])])}${field("part_qty_" + n, "تعداد هر کالا", 1, "number")}</div>`,
      );
    }
  } catch (error) {
    toast(error.message, true);
  }
});
document.addEventListener("change", (e) => {
  if (e.target.name === "product_id" && e.target.form?.id === "stock-form") {
    e.target.form.dataset.expectedStock = adminProducts.find((p) => p.id === e.target.value)?.stock ?? 0;
  }
  if (e.target.id === "sort") {
    const params = new URLSearchParams(location.search);
    params.set("sort", e.target.value);
    params.delete("page");
    navigate("/category?" + params);
  }
});
document.addEventListener("submit", (e) => {
  const form = e.target;
  if (!form.matches("form")) return;
  e.preventDefault();
  formTask(form, async () => {
    const data = formData(form);
    switch (form.matches("[data-payment-review]") ? "resolve-payment-form" : form.id) {
      case "site-search":
        return navigate("/category?q=" + encodeURIComponent(data.q));
      case "filter-form": {
        const params = new URLSearchParams();
        for (const [k, v] of Object.entries(data)) if (v) params.set(k, v);
        return navigate("/category?" + params);
      }
      case "auth-form": {
        const mode = form.dataset.mode;
        await api(mode === "setup" ? "setup" : "auth/" + mode, {
          method: "POST",
          body: data,
        });
        boot = await api("bootstrap");
        return navigate(
          ["admin", "manager"].includes(boot.user.role) ? "/admin" : "/profile",
        );
      }
      case "profile-form":
        await api("auth/profile", { method: "PUT", body: data });
        boot = await api("bootstrap");
        toast("اطلاعات حساب ذخیره شد.");
        return route();
      case "password-form":
        await api("auth/password", { method: "POST", body: data });
        boot = await api("bootstrap");
        toast("رمز تغییر کرد و نشست‌های دیگر پایان یافت.");
        return route();
      case "address-form":
        await api("auth/addresses", { method: "POST", body: data });
        toast("آدرس ذخیره شد.");
        return route();
      case "coupon-form": {
        const old = coupon;
        coupon = data.coupon.trim();
        try {
          await api("cart/quote", {
            method: "POST",
            body: { items: cart, coupon, service_id: serviceId || undefined },
          });
        } catch (e) {
          coupon = old;
          throw e;
        }
        return route();
      }
      case "service-form":
        serviceId = data.service_id;
        return route();
      case "checkout-form": {
        const stored = sessionStorage.getItem("amir_pending_order");
        if (stored) return submitCheckout(JSON.parse(stored));
        delete data.saved_address;
        const command = {
          key: commandId(),
          scope: boot.checkout_scope,
          body: { ...data, items: cart.map((item) => ({ ...item })), coupon,
            service_id: serviceId || undefined, expected_total: quote.total_amount },
        };
        try { sessionStorage.setItem("amir_pending_order", JSON.stringify(command)); }
        catch { throw new Error("ذخیره درخواست در مرورگر ممکن نیست. سفارشی ارسال نشد."); }
        return submitCheckout(command);
      }
      case "retry-checkout-form":
        return submitCheckout(JSON.parse(sessionStorage.getItem("amir_pending_order")), true);
      case "transfer-receipt-form":
        await api("orders/" + encodeURIComponent(form.dataset.id) + "/transfer-receipt", { method: "POST", body: data });
        toast("مرجع واریز برای بررسی ثبت شد.");
        return route();
      case "review-form":
        await api("reviews", {
          method: "POST",
          body: {
            ...data,
            product_id: form.dataset.product,
            rating: Number(data.rating),
          },
        });
        toast("دیدگاه برای بررسی ثبت شد.");
        form.reset();
        return;
      case "contact-form":
        await api("contact", { method: "POST", body: data });
        form.reset();
        toast("پیام در پنل پشتیبانی ثبت شد.");
        return;
      case "warranty-form": {
        const result = await api("warranty/lookup", {
          method: "POST",
          body: data,
        });
        $("#warranty-result").innerHTML = result.found
          ? `<div class="notice ${result.valid ? "success" : ""}"><b>${esc(result.title)}</b><p>سریال: ${esc(result.serial)}<br>شروع: ${esc(result.starts_at)} — پایان: ${esc(result.expires_at)}<br>${result.valid ? "گارانتی طبق رکورد فروشگاه فعال است." : "گارانتی فعال نیست یا زمان آن پایان یافته است."}</p></div>`
          : '<div class="notice">رکوردی با این سریال پیدا نشد. شماره را بررسی کنید یا با پشتیبانی تماس بگیرید.</div>';
        return;
      }
      case "configurator-form": {
        const result = await api("configurator", {
          method: "POST",
          body: { ...data, budget: Number(data.budget) },
        });
        $("#config-results").innerHTML = result.items.length
          ? `<div class="section-head"><h2>پیشنهاد در محدوده بودجه</h2><strong>${money(result.total_amount)}</strong></div><div class="notice">${esc(result.note)} ${!result.complete ? "با این بودجه، همه اجزای یک سیستم کامل پیدا نشد." : ""}</div><div class="product-grid">${result.items.map(productCard).join("")}</div>`
          : empty(
              "پیشنهاد قابل اتکایی پیدا نشد",
              "برای این خودرو، قطعه سازگار در بودجه انتخاب‌شده ثبت نشده است. از پشتیبانی برای بررسی فنی کمک بگیرید.",
              "/contact",
              "درخواست راهنمایی",
            );
        return;
      }
      case "admin-edit-form": {
        let out = { ...data };
        for (const input of $$("input[type=checkbox]", form))
          out[input.name] = input.checked;
        if (form.dataset.kind === "products") {
          out.version = Number(form.dataset.version);
          out.price_toman = Number(data.price_toman);
          out.price_usd = Number(data.price_usd);
          out.discount_usd = Number(data.discount_usd);
          out.stock = Number(data.stock || 0);
          out.images = (data.images_text || "")
            .split("\n")
            .map((x) => x.trim())
            .filter(Boolean);
          out.specs = Object.fromEntries(
            (data.specs_text || "")
              .split("\n")
              .filter((x) => x.includes(":"))
              .map((x) => [
                x.slice(0, x.indexOf(":")).trim(),
                x.slice(x.indexOf(":") + 1).trim(),
              ]),
          );
          out.warranty_months = data.warranty_months
            ? Number(data.warranty_months)
            : null;
          out.vehicle_fits = boot.vehicles
            .filter((v) => data["fit_" + v.id])
            .map((v) => ({
              vehicle_id: v.id,
              verified: data["fit_" + v.id] === "verified",
              notes: "",
            }));
          for (const k of Object.keys(out))
            if (k.startsWith("fit_")) delete out[k];
          delete out.images_text;
          delete out.specs_text;
        }
        if (form.dataset.kind === "bundles") {
          out.discount_bps = Number(data.discount_percent) * 100;
          out.items = Object.keys(data)
            .filter((k) => /^part_\d+$/.test(k) && data[k])
            .map((k) => ({
              product_id: data[k],
              qty: Number(data["part_qty_" + k.slice(5)]),
            }));
        }
        const key = form.dataset.id;
        await api(
          "admin/" +
            form.dataset.kind +
            (key ? "/" + encodeURIComponent(key) : ""),
          { method: key ? "PUT" : "POST", body: out },
        );
        adminProducts = [];
        boot = await api("bootstrap");
        toast("اطلاعات ذخیره شد.");
        return route();
      }
      case "admin-filter-form": {
        const query = new URLSearchParams();
        for (const [key, value] of Object.entries(data)) if (value.trim()) query.set(key, value.trim());
        return navigate("/admin?" + query.toString());
      }
      case "stock-form":
      case "retry-stock-form": {
        const stored = sessionStorage.getItem("amir_pending_stock");
        const command = stored ? JSON.parse(stored) : {
          ...data, quantity: Number(data.quantity), event_key: form.dataset.event,
          expected_stock: Number(form.dataset.expectedStock),
        };
        if (!stored) sessionStorage.setItem("amir_pending_stock", JSON.stringify(command));
        try {
          await api("admin/stock", { method: "POST", body: command });
        } catch (error) {
          if (error.requestId && [400, 404, 409, 422].includes(error.status)) {
            sessionStorage.removeItem("amir_pending_stock");
            adminProducts = [];
            await showStockReceipt(command.product_id);
            toast(error.message, true);
            return;
          }
          throw error;
        }
        sessionStorage.removeItem("amir_pending_stock");
        adminProducts = [];
        toast("رسید انبار ثبت شد.");
        return route();
      }
      case "order-address-form":
      case "order-shipment-form": {
        const endpoint = form.id === "order-address-form" ? "address" : "shipment";
        await api("admin/orders/" + form.dataset.id + "/" + endpoint, { method: "PUT", body: data });
        toast("اطلاعات سفارش ذخیره شد.");
        return refreshAdminOrder(form.dataset.id);
      }
      case "order-status-form":
      case "ship-order-form": {
        if (form.id === "ship-order-form") data.status = "shipped";
        if (data.status === "cancelled" && !confirm("سفارش لغو و موجودی رزروشده آزاد شود؟")) return;
        await api("admin/orders/" + form.dataset.id + "/status", { method: "POST", body: data });
        toast("وضعیت سفارش ثبت شد.");
        return refreshAdminOrder(form.dataset.id);
      }
      case "refund-form":
        data.reference = data.refund_reference;
        delete data.refund_reference;
        data.restock = form.elements.restock?.checked ?? false;
        data.confirmed = form.elements.confirmed.checked;
        await api("admin/orders/" + form.dataset.id + "/refund", { method: "POST", body: data });
        toast("سند برگشت وجه ثبت شد.");
        return refreshAdminOrder(form.dataset.id);
      case "return-form":
        await api("admin/orders/" + form.dataset.id + "/return", {
          method: "POST", body: { confirmed: form.elements.confirmed.checked },
        });
        toast("اقلام مرجوعی به انبار برگشت.");
        return refreshAdminOrder(form.dataset.id);
      case "resolve-payment-form":
        data.confirmed = form.elements.confirmed.checked;
        await api("admin/orders/" + form.dataset.id + "/resolve-payment", { method: "POST", body: data });
        toast("نتیجه بررسی ثبت شد.");
        return refreshAdminOrder(form.dataset.id);
      case "record-payment-form":
        await api("admin/orders/" + form.dataset.id + "/record-payment", { method: "POST", body: data });
        toast("دریافت تأییدشده ثبت شد.");
        return refreshAdminOrder(form.dataset.id);
      case "settings-form":
        for (const input of $$("input[type=checkbox]", form))
          data[input.name] = input.checked;
        for (const k of [
          "shipping_fee",
          "free_shipping_from",
          "installation_free_from",
          "currency_rate",
        ])
          data[k] = Number(data[k]);
        for (const k of ["latitude", "longitude"])
          data[k] = data[k] === "" ? null : Number(data[k]);
        await api("admin/settings", { method: "PUT", body: data });
        boot = await api("bootstrap");
        toast("تنظیمات فروشگاه ذخیره شد.");
        return route();
    }
  });
});
document.addEventListener(
  "error",
  (e) => {
    if (
      e.target instanceof HTMLImageElement &&
      !e.target.src.endsWith("/images/placeholder.svg")
    )
      e.target.src = "/images/placeholder.svg";
  },
  true,
);
window.addEventListener("popstate", () => route());
document.documentElement.dataset.theme =
  preferences.getItem("amir_theme") || "light";
async function start() {
  try {
    boot = await api(
      "bootstrap" + (location.pathname === "/" ? "?home=1" : ""),
    );
    await route();
  } catch (e) {
    $("#app").innerHTML =
      `<main class="container">${empty("فروشگاه در دسترس نیست", e.message, "/", "تلاش دوباره")}</main>`;
  }
}
start();
if ("serviceWorker" in navigator)
  window.addEventListener("load", () => {
    navigator.serviceWorker
      .register("/sw.js")
      .then((reg) => {
        reg.addEventListener("updatefound", () => {
          const worker = reg.installing;
          worker?.addEventListener("statechange", () => {
            if (
              worker.state === "installed" &&
              navigator.serviceWorker.controller
            )
              toast(
                "نسخه تازه آماده است؛ پس از پایان کار، برنامه را ببندید و باز کنید.",
              );
          });
        });
      })
      .catch(() => {});
  });
window.addEventListener("beforeinstallprompt", (e) => {
  e.preventDefault();
  installPrompt = e;
  showInstall(false);
});
function showInstall(ios) {
  if (
    sessionStorage.getItem("amir_install_dismissed") ||
    matchMedia("(display-mode: standalone)").matches ||
    navigator.standalone
  )
    return;
  const div = document.createElement("aside");
  div.className = "install";
  div.innerHTML = `<b>امیر سیستم روی صفحه اصلی</b><p>${ios ? "در منوی اشتراک‌گذاری Safari، «افزودن به صفحه اصلی» را انتخاب کنید." : "برای دسترسی سریع‌تر، برنامه فروشگاه را نصب کنید."}</p><div class="button-row">${!ios ? '<button class="btn small" id="install-now">نصب برنامه</button>' : ""}<button class="btn secondary small" id="install-dismiss">بعداً</button></div>`;
  document.body.append(div);
  $("#install-dismiss").onclick = () => {
    sessionStorage.setItem("amir_install_dismissed", "1");
    div.remove();
  };
  if (!ios)
    $("#install-now").onclick = async () => {
      await installPrompt?.prompt();
      div.remove();
    };
}
if (/iPhone|iPad|iPod/.test(navigator.userAgent))
  setTimeout(() => showInstall(true), 4000);

document.addEventListener("change", (e) => {
  if (e.target.name !== "saved_address") return;
  const a = deliveryAddresses.find((a) => a.id === e.target.value);
  const source = a || { name: boot.user?.name, phone: boot.user?.phone };
  const form = e.target.form;
  for (const [field, key] of [
    ["customer_name", "name"],
    ["mobile", "phone"],
    ["province", "province"],
    ["city", "city"],
    ["address", "address"],
    ["postal_code", "postal_code"],
  ])
    form.elements[field].value = source[key] || "";
});
