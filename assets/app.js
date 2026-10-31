/**
 * Menu front-end — fetches the single JSON payload from api.php and paints it
 * into index.html. No framework, no build step.
 *
 * Rules the dashboard writes into the database:
 *   status "hidden"    -> the item is not rendered at all
 *   status "sold_out"  -> the item stays visible, with a "Sold out" stamp
 *   status "available" -> the item renders normally
 */

const API_URL = new URL('api.php', document.baseURI).href;

/** Fetch and sanity-check the payload. */
export async function fetchMenu(url = API_URL) {
  const res = await fetch(url, {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  });
  let data = null;
  try {
    data = await res.json();
  } catch {
    throw new Error('پاسخ سرور منو قابل خواندن نیست.');
  }
  if (!res.ok || data?.ok === false) {
    throw new Error(data?.error || `منو بارگذاری نشد (${res.status}).`);
  }
  if (!Array.isArray(data.items) || !Array.isArray(data.categories)) {
    throw new Error('پاسخ سرور آیتم‌ها یا دسته‌بندی‌ها را ندارد.');
  }
  return data;
}

/* ---------------------------------------------------------------- *
 *  Dashboard customisation (settings written from the admin panel)
 * ---------------------------------------------------------------- */

/** Font stacks offered in the dashboard's `font_family` dropdown.
 *  Dana / YekanBakh are used when installed on the device and fall back to
 *  Vazirmatn otherwise — no extra webfont download either way. */
const FONT_STACKS = {
  Vazirmatn: "'Vazirmatn', system-ui, -apple-system, sans-serif",
  Lalezar: "'Lalezar', 'Vazirmatn', system-ui, sans-serif",
  Dana: "'Dana', 'Vazirmatn', system-ui, sans-serif",
  YekanBakh: "'YekanBakh', 'Vazirmatn', system-ui, sans-serif",
};

/** Apply primary_color / font_family / custom_bg_url from settings. */
export function applyCustomization(settings = {}) {
  const root = document.documentElement;

  const color = String(settings.primary_color || '').trim();
  if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(color)) {
    root.style.setProperty('--brand', color);
  }

  const stack = FONT_STACKS[settings.font_family];
  if (stack) root.style.setProperty('--body-font', stack);

  const bg = String(settings.custom_bg_url || '').trim().replace(/["'()\\]/g, '');
  const menu = document.getElementById('menu');
  if (menu && bg) menu.style.backgroundImage = `url("${bg}")`;
}

/** Paint the selected theme onto the document. */
export function applyTheme(themes, slug) {
  const list = Array.isArray(themes) ? themes : [];
  const theme = list.find((t) => t.slug === slug) || list.find((t) => t.slug === 'espresso') || list[0];
  if (!theme) return null;

  const root = document.documentElement;
  root.style.setProperty('--bg', theme.bg);
  root.style.setProperty('--surface', theme.surface);
  root.style.setProperty('--text', theme.text);
  root.style.setProperty('--muted', theme.muted);
  root.style.setProperty('--accent', theme.accent);
  root.style.setProperty('--font-display', theme.font);
  root.style.colorScheme = isDark(theme.bg) ? 'dark' : 'light';
  return theme;
}

/** Luminance check so native scrollbars/inputs follow the chosen theme. */
export function isDark(hex) {
  const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex || '').trim());
  if (!match) return true;
  let hex6 = match[1];
  if (hex6.length === 3) hex6 = hex6.split('').map((c) => c + c).join('');
  const r = parseInt(hex6.slice(0, 2), 16);
  const g = parseInt(hex6.slice(2, 4), 16);
  const b = parseInt(hex6.slice(4, 6), 16);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b < 140;
}

/** Persian numerals with thousand separators: ۴۸٬۰۰۰ / ۴٫۷۵ */
const faNumber = new Intl.NumberFormat('fa-IR', {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});

/** Format a price with the currency from settings. */
export function formatPrice(value, currency = 'تومان') {
  const price = Number(value);
  const amount = faNumber.format(Number.isFinite(price) ? price : 0);

  if (/^[A-Z]{3}$/i.test(currency)) {
    // ISO codes read after the amount: ۴۸٬۰۰۰ USD
    return `${amount} ${currency.toUpperCase()}`;
  }
  if (currency.length > 2) {
    // word currencies read after the amount: ۴۸٬۰۰۰ تومان
    return `${amount} ${currency}`;
  }
  // symbols stay in front: $۴٫۷۵
  return `${currency}${amount}`;
}

/* ---------------------------------------------------------------- *
 *  Rendering
 * ---------------------------------------------------------------- */

const el = (tag, props = {}, children = []) => {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(props)) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key === 'text') node.textContent = String(value);
    else if (key.startsWith('on') && typeof value === 'function') {
      node.addEventListener(key.slice(2).toLowerCase(), value);
    }
    else node.setAttribute(key, value === true ? '' : String(value));
  }
  for (const child of [].concat(children)) {
    if (child) node.append(child);
  }
  return node;
};

/** Categories that still show at least one item. */
function liveCategories(categories, items) {
  const visible = items.filter((i) => i.status !== 'hidden');
  return categories
    .map((category) => ({
      ...category,
      items: visible
        .filter((item) => Number(item.category_id) === Number(category.id))
        .sort((a, b) => Number(a.sort_order) - Number(b.sort_order) || Number(a.id) - Number(b.id)),
    }))
    .filter((category) => category.items.length > 0);
}

const slugify = (text, index) =>
  `cat-${String(index + 1).padStart(2, '0')}-${String(text)
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '') || 'section'}`;

/* Photos shown until an item carries its own image_url from the dashboard. */
const FALLBACK_PHOTOS = [
  'https://lh3.googleusercontent.com/aida-public/AB6AXuAEdqP0Q1tcID-HD4rG3kHF-5b1EWdLeMNs3wgUMSkpzB-jleVeYdWWecJjoYNLIookNUCiUONg7D15X3H7Hg8HyAuFtRI3VWT55LnJOl2kR1PbAH0ixOfseJ0U7peQ8tWrmyrz9HAgfM6GFfq3G0Tb1hoC_sU_pRKDDbipwBy2oQ9tYeqpmoGV-zRRMcHRN55HxksFdPLuGajEzS33vcUq256wk6Q8HMBFP9EduhWm',
  'https://lh3.googleusercontent.com/aida-public/AB6AXuDYsa4EOwD8NlFAw2BD-KNXWpAceX0vmUnHcS-bx6tomQqKQbK1V-HYit0JvSHeUbdw8YTGln42NGGHYPMfbUwSiXi0U8MoEhEm-LyWV4Pm9lIa_lEuyKU0TxIYRwX9noGIdyQ4cnvPHZLNk771cvO2mAr1pkmh1L3ikECP63NzYoE-kDG7YVQHtA3iADYv2zl-ul9Wp-vv2mpWxXo301ovpEVsv1W42dBJBibyAsqz',
  'https://lh3.googleusercontent.com/aida-public/AB6AXuDciB8--OnUVsaYSpeGJPqnUlUfuFsyZFzzNgJZ8M6oTPBwtlOjWPHh4J25xWhIXVoHtC8HmPE7TE9OyKag7XI-1Zu9VpGXrdO2HBKRMt2kEYAYZRQyV4MMDYp2jV1tS3aiqm0Rc5fsqEu1Z7OtXqBfLpGSfLhS9V1M7HU_rUBE_fW92j0BbjaSS-UaccxkjQMcIQPAqk55-9cAHImdhBLQ-Het9kEAPVWES6joHUmx',
  'https://lh3.googleusercontent.com/aida-public/AB6AXuBJeRZNSaUvZV22rIKFGrSST7wYvsxVswj_e_D4xvalNEw545EsBTiw8aibpxLkTkil9J0mInxV73qmBpwZbc-98wwcMd6O4ScCsFBqO8X3_eweNBlRIp763sVTKkrCbO3OYejWBZ1AsKh6_tKQC9ubMXK6ZqUd1QPIaxweYhenU8swIS8A59-20dq6OzmgYfUS2DHhuVWoFr03ieMGLUJ2Wx5KqGSlRxzJ7CUcOG16',
];

const DEFAULT_ASSET_MAP = {
  'اسپرسو': 'assets/defaults/espresso.png',
  'لاته': 'assets/defaults/latte.png',
  'کاپوچینو': 'assets/defaults/cappuccino.png',
  'موکا': 'assets/defaults/mocha.png',
  'فلت وایت': 'assets/defaults/flat-white.png',
  'کلد برو': 'assets/defaults/cold-brew.png',
  'وی‌۶۰': 'assets/defaults/v60.png',
  'وی 60': 'assets/defaults/v60.png',
  'آمریکانو': 'assets/defaults/americano.png',
  'لیموناد نعنایی': 'assets/defaults/lemonade.png',
  'سودای گلاب': 'assets/defaults/rose-soda.png',
  'شیک نوتلا': 'assets/defaults/vanilla-shake.png',
  'کروسان کره‌ای': 'assets/defaults/croissant.png',
  'کیک خرمایی': 'assets/defaults/date-cake.png',
  'کیک شکلاتی': 'assets/defaults/chocolate-cake.png',
  'چیزکیک': 'assets/defaults/cheesecake.png',
  'تست هلومی': 'assets/defaults/halloumi-toast.png',
  'شکشوعا': 'assets/defaults/shakshuka.png',
  'اسموکی برگر': 'assets/defaults/smoky-burger.png',
  'فرایز ترافل': 'assets/defaults/french-fries.png',
  'پیتزا گوشت و قارچ': 'assets/defaults/pizza-meat-mushroom.png',
  'پیتزا پپرونی': 'assets/defaults/pizza-pepperoni.png',
  'پیتزا سبزیجات': 'assets/defaults/pizza-vegetable.png',
  'سالاد سزار': 'assets/defaults/caesar-salad.png',
  'وافل': 'assets/defaults/waffle.png',
  'پنکیک': 'assets/defaults/pancakes.png',
  'کوکی': 'assets/defaults/cookies.png',
  'مافین بلوبری': 'assets/defaults/blueberry-muffin.png',
  'تست آووکادو': 'assets/defaults/avocado-toast.png',
  'سینامون رول': 'assets/defaults/cinnamon-roll.png',
  'براونی': 'assets/defaults/brownie.png',
  'بیگل': 'assets/defaults/bagel.png',
};

function defaultAssetForItem(name) {
  if (!name) return '';
  const trimmed = name.trim();
  if (DEFAULT_ASSET_MAP[trimmed]) return DEFAULT_ASSET_MAP[trimmed];
  for (const [k, v] of Object.entries(DEFAULT_ASSET_MAP)) {
    if (trimmed.includes(k) || k.includes(trimmed)) return v;
  }
  return '';
}

const CATEGORY_DEFAULT_ASSETS = {
  'قهوه': 'assets/defaults/cappuccino.png',
  'نوشیدنی سرد': 'assets/defaults/cold-brew.png',
  'نان و شیرینی': 'assets/defaults/croissant.png',
  'آشپزخانه': 'assets/defaults/smoky-burger.png',
  'پیتزا': 'assets/defaults/pizza-pepperoni.png',
};

window.swapCategoryIcon = function(categorySection, newSrc) {
  if (!categorySection || !newSrc) return;
  const sectionEl = typeof categorySection === 'string'
    ? document.getElementById(categorySection)
    : categorySection;
  if (!sectionEl) return;

  const sectionId = sectionEl.id;
  const navImg = document.querySelector(`.cat-link[data-target="${sectionId}"] .cat-thumb`);
  const targets = [...sectionEl.querySelectorAll('.category-main-icon, .category-main-photo')];
  if (navImg) targets.push(navImg);

  targets.forEach((img) => {
    img.classList.remove('icon-spin-swap');
    void img.offsetWidth; // Force DOM reflow to re-trigger transition smoothly
    img.classList.add('icon-spin-swap');
    setTimeout(() => {
      img.src = newSrc;
      img.classList.remove('icon-spin-swap');
    }, 250);
  });
};

window.revertCategoryIcon = function(categorySection) {
  if (!categorySection) return;
  const sectionEl = typeof categorySection === 'string'
    ? document.getElementById(categorySection)
    : categorySection;
  if (!sectionEl) return;

  const sectionId = sectionEl.id;
  const navImg = document.querySelector(`.cat-link[data-target="${sectionId}"] .cat-thumb`);
  const targets = [...sectionEl.querySelectorAll('.category-main-icon, .category-main-photo')];
  if (navImg) targets.push(navImg);

  targets.forEach((img) => {
    const defaultSrc = img.dataset.defaultSrc;
    if (defaultSrc) {
      img.classList.remove('icon-spin-swap');
      void img.offsetWidth; // Force DOM reflow
      img.classList.add('icon-spin-swap');
      setTimeout(() => {
        img.src = defaultSrc;
        img.classList.remove('icon-spin-swap');
      }, 250);
    }
  });
};

function photoFor(category, index) {
  if (CATEGORY_DEFAULT_ASSETS[category.name]) {
    return CATEGORY_DEFAULT_ASSETS[category.name];
  }
  for (const [catKey, assetPath] of Object.entries(CATEGORY_DEFAULT_ASSETS)) {
    if (category.name.includes(catKey) || catKey.includes(category.name)) {
      return assetPath;
    }
  }
  const custom = category.items.find((item) => item.image_url);
  if (custom && custom.image_url) return custom.image_url;
  for (const it of category.items) {
    const matched = defaultAssetForItem(it.name);
    if (matched) return matched;
  }
  return FALLBACK_PHOTOS[index % FALLBACK_PHOTOS.length];
}

/* One mockup row matching user reference exactly: circular photo + dual-capsule card + expandable price drawer. */
function renderSection(category, currency, index) {
  const drawerId = `drawer-${String(index + 1).padStart(2, '0')}`;
  const arrowId = `${drawerId}-arrow`;
  const minPrice = formatPrice(
    Math.min(...category.items.map((item) => Number(item.price) || 0)),
    currency,
  );

  const isEven = index % 2 === 0;
  const defaultPhoto = photoFor(category, index);

  // Category Section Header Pill (above each row, matching reference image)
  const categoryBadge = el('div', { class: 'flex justify-center mb-6' }, [
    el('div', {
      class: 'px-5 py-2 rounded-full bg-[#1e0f09]/92 text-[#dfcaa8] font-bold text-xs shadow-xl border-[1.5px] border-[#dfcaa8]/40 flex items-center gap-2.5 backdrop-blur-sm',
    }, [
      el('span', { class: 'w-5 h-5 rounded-full overflow-hidden border border-[#dfcaa8]/60 inline-flex items-center justify-center shrink-0' }, [
        el('img', { 
          src: defaultPhoto, 
          class: 'category-main-icon w-full h-full object-cover rounded-full', 
          alt: category.name,
          'data-default-src': defaultPhoto,
        }),
      ]),
      el('span', { text: category.name }),
    ]),
  ]);

  // Photo Circle Frame with centered bottom badge
  const photo = el('div', { class: 'flex justify-center items-center relative' }, [
    el('div', {
      class: 'relative group cursor-pointer',
      'data-drawer-open': drawerId,
      onclick: () => window.toggleItemDrawer(drawerId),
    }, [
      el('div', { class: 'photo-circle-frame' }, [
        el('img', {
          alt: category.name,
          class: 'category-main-photo w-full h-full object-cover rounded-full',
          src: defaultPhoto,
          'data-default-src': defaultPhoto,
        }),
      ]),
      el('div', {
        class: 'absolute -bottom-2.5 left-1/2 -translate-x-1/2 bg-[#1c120c] text-[#dfcaa8] text-[10px] font-black px-3 py-0.5 rounded-full border border-[#dfcaa8]/60 shadow-md whitespace-nowrap',
        'data-drawer-open': drawerId,
        text: 'لمس منو ▾',
      }),
    ]),
  ]);

  // Card Inner Content: High-contrast beige card
  const innerCard = el('div', {
    class: 'card-beige cursor-pointer group',
    onclick: () => window.toggleItemDrawer(drawerId),
  }, [
    el('span', {
      class: `inline-block text-[9px] font-black px-2.5 py-0.5 rounded-full ${isEven ? 'bg-[#241208] text-[#dfcaa8]' : 'bg-[#3f0f18] text-rose-200'} shadow-sm mb-2`,
      text: isEven ? 'تخصصی ۱۰۰٪ عربیکا' : 'سرد و عصاره‌گیری طولانی',
    }),
    el('h3', { class: 'text-base font-black text-[#1c120c] leading-snug', text: category.name }),
    category.items[0]?.description
      ? el('p', { class: 'text-[11px] font-semibold text-[#402619] mt-1 leading-relaxed line-clamp-2', text: category.items[0].description })
      : null,
    el('div', { class: 'mt-3 pt-2.5 border-t border-[#1c120c]/15 flex items-center justify-between' }, [
      el('div', {}, [
        el('span', { class: 'text-[10px] text-[#402619] block font-medium', text: 'شروع از' }),
        el('span', { class: 'text-sm font-black text-[#1c120c]', text: minPrice }),
      ]),
      (() => {
        const btn = el('button', {
          type: 'button',
          class: 'px-4 py-2 text-xs font-black rounded-xl bg-[#241208] text-[#dfcaa8] hover:bg-black active:scale-95 transition-all shadow flex items-center gap-1.5',
        }, [
          el('span', { text: 'انتخاب' }),
          el('span', { id: arrowId, text: '▾' }),
        ]);
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          window.toggleItemDrawer(drawerId);
        });
        return btn;
      })(),
    ]),
  ]);

  // Card Wrapper with Connector Bridge (Row 1 is direct beige card with right neck; Row 2 is wrapped in dark capsule with left neck)
  let card;
  if (isEven) {
    card = el('div', { class: 'flex relative' }, [
      el('div', { class: 'w-full relative' }, [
        innerCard,
        el('div', { class: 'connector-neck-right' }),
      ]),
    ]);
  } else {
    card = el('div', { class: 'flex relative' }, [
      el('div', { class: 'w-full relative card-dark-wrapper' }, [
        innerCard,
        el('div', { class: 'connector-neck-left' }),
      ]),
    ]);
  }

  // Row layout: Row 1 is Card on Left, Photo on Right; Row 2 is Photo on Left, Card on Right
  const row = isEven
    ? el('div', { class: 'menu-row' }, [card, photo])
    : el('div', { class: 'menu-row' }, [photo, card]);

  const rows = category.items.map((item) => {
    const price = formatPrice(item.price, currency);
    const soldOut = item.status === 'sold_out';
    const itemImg = item.image_url || defaultAssetForItem(item.name);

    const triggerSwap = () => {
      const section = document.getElementById(category.domId);
      if (section && itemImg) {
        window.swapCategoryIcon(section, itemImg);
      }
    };

    const add = el('button', {
      type: 'button',
      class: 'px-2.5 py-1 rounded-lg bg-[#dfcaa8] text-[#1c120c] font-black text-xs hover:bg-amber-200 active:scale-90 transition-all' + (soldOut ? ' opacity-40 pointer-events-none' : ''),
      text: '+ افزودن',
    });
    if (!soldOut) {
      add.addEventListener('click', (e) => {
        e.stopPropagation();
        window.addToCart(item.name, Number(item.price) || 0);
        triggerSwap();
      });
    }

    const itemRow = el('div', { 
      class: 'item-card flex items-center justify-between pt-2 cursor-pointer transition-all hover:bg-white/5 p-1.5 rounded-lg active:scale-[0.98]',
      'data-item-name': item.name,
      'data-item-image': itemImg,
      onclick: () => {
        triggerSwap();
      }
    }, [
      el('div', { class: 'flex items-center gap-2.5' }, [
        itemImg ? el('img', {
          src: itemImg,
          alt: item.name,
          class: 'w-7 h-7 aspect-square object-cover object-center rounded-full border border-amber-300/40 shrink-0'
        }) : null,
        el('div', {}, [
          el('div', { class: 'font-extrabold text-sm text-stone-50' }, [
            el('span', { text: item.name }),
            soldOut ? el('span', { class: 'ms-2 text-[9px] font-black px-2 py-0.5 rounded-full bg-rose-950 text-rose-300 border border-rose-800/60', text: 'تمام شد' }) : null,
          ]),
          item.description
            ? el('div', { class: 'text-[11px] text-amber-200/80 font-medium', text: item.description })
            : null,
        ]),
      ]),
      el('div', { class: 'flex items-center gap-2' }, [
        el('span', { class: 'font-black text-sm text-amber-300', text: price }),
        add,
      ]),
    ]);

    return itemRow;
  });

  const drawer = el('div', {
    id: drawerId,
    class: 'drawer-content mt-3 bg-[#170e0a]/95 border-[1.5px] border-[#dfcaa8]/35 rounded-2xl p-3 text-stone-100 shadow-2xl backdrop-blur-md',
  }, [el('div', { class: 'space-y-2.5 text-xs divide-y divide-white/10' }, rows)]);

  return el('section', {
    id: category.domId,
    class: 'menu-section relative z-20 mb-12 scroll-mt-[228px]',
    'aria-labelledby': `${category.domId}-title`,
  }, [
    categoryBadge,
    row,
    drawer,
  ]);
}

function renderNav(categories) {
  const list = document.querySelector('#cat-nav ul');
  list.textContent = '';
  for (const [index, category] of categories.entries()) {
    list.append(
      el('li', { class: 'shrink-0' }, [
        el('a', {
          href: `#${category.domId}`,
          class: 'cat-link nav-pill px-3 py-2 text-sm font-bold rounded-full bg-black/35 hover:bg-black/50 text-stone-200 border-[1.5px] border-[#dfcaa8]/40 whitespace-nowrap transition-all flex items-center gap-2',
          'data-target': category.domId,
        }, [
          (() => {
            const navPhoto = photoFor(category, index);
            return el('img', { 
              class: 'cat-thumb', 
              alt: category.name, 
              src: navPhoto,
              'data-default-src': navPhoto,
            });
          })(),
          el('span', { text: category.name }),
        ]),
      ]),
    );
  }
}

function watchSections() {
  const links = [...document.querySelectorAll('.cat-link')];
  if (!('IntersectionObserver' in window) || links.length === 0) return;

  const setActive = (id) => {
    for (const link of links) {
      link.setAttribute('aria-current', link.dataset.target === id ? 'true' : 'false');
    }
  };

  const observer = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
      if (visible[0]) setActive(visible[0].target.id);
    },
    { rootMargin: '-30% 0px -60% 0px', threshold: 0 },
  );

  for (const section of document.querySelectorAll('#sections section')) {
    observer.observe(section);
  }
  setActive(links[0].dataset.target);
}

function showError(message) {
  const status = document.getElementById('status');
  status.textContent = '';
  const isFile = window.location.protocol === 'file:';
  const detail = isFile
    ? 'شما صفحه را مستقیماً از روی فایل (file://) باز کرده‌اید. این منو برای ارتباط با دیتابیس و پردازش نیازمند وب‌سرور PHP (مانند cPanel، DirectAdmin، XAMPP یا دستور php -S) است.'
    : message;

  status.append(
    el('div', { class: 'max-w-md mx-auto p-6 rounded-2xl border text-center', style: 'border-color:var(--line);background:#17140F' }, [
      el('p', {
        class: 'font-display text-2xl mb-3 text-red-400',
        text: 'منو بارگذاری نشد',
      }),
      el('p', { class: 'text-sm mb-6 leading-relaxed', style: 'color:var(--muted)', text: detail }),
      el('button', {
        type: 'button',
        class: 'mono text-xs uppercase tracking-[0.1em] px-5 py-2.5 rounded-lg border cursor-pointer',
        style: 'border-color:var(--accent);color:var(--accent)',
        text: 'تلاش دوباره',
        onclick: () => location.reload(),
      }),
    ]),
  );
  status.hidden = false;
}

function showEmpty() {
  const main = document.getElementById('sections');
  main.append(
    el('div', { class: 'border p-8 text-center', style: 'border-color:var(--line)' }, [
      el('p', { class: 'font-display text-2xl mb-2', text: 'هنوز چیزی در منو نیست' }),
      el('p', {
        class: 'text-sm',
        style: 'color:var(--muted)',
        text: 'اولین دسته‌بندی و آیتم خود را از پنل مدیریت اضافه کنید.',
      }),
    ]),
  );
}

/* ---------------------------------------------------------------- *
 *  Bottom card sections — settings.highlights / settings.reviews
 * ---------------------------------------------------------------- */

/** Decode a JSON card setting. null = "leave the markup in index.html alone". */
function parseCards(raw) {
  if (typeof raw !== 'string' || raw.trim() === '') return null;
  try {
    const cards = JSON.parse(raw);
    return Array.isArray(cards) ? cards : null;
  } catch {
    return null;
  }
}

const CARD_BOX =
  'aspect-square rounded-[24px] shadow-xl p-4 flex flex-col justify-between text-right';
const CARD_BADGE =
  'text-[9px] font-black px-2.5 py-0.5 rounded-full shadow-sm';

/** One square box of the highlights row (variant "brand" is the filled cream card). */
function highlightCard(card) {
  const dark = card.variant !== 'brand';
  return el(
    'div',
    {
      class: `${CARD_BOX} ${
        dark
          ? 'bg-[#22130c]/90 text-stone-100 border-[1.5px] border-[#dfcaa8]/40 backdrop-blur-sm'
          : 'bg-[#dfcaa8] text-[#1c120c] border-[1.5px] border-[#1c120c]/25'
      }`,
    },
    [
      el('div', { class: 'flex items-center justify-between' }, [
        el('span', {
          class: `${CARD_BADGE} ${
            dark ? 'bg-[#dfcaa8] text-[#1c120c]' : 'bg-[#261208] text-[#dfcaa8]'
          }`,
          text: String(card.badge || ''),
        }),
        el('span', { class: 'text-lg', text: String(card.icon || '') }),
      ]),
      el('div', {}, [
        el('p', { class: 'text-sm font-black leading-snug', text: String(card.title || '') }),
        el('p', {
          class: `text-[10px] font-semibold mt-1 ${dark ? 'text-stone-300' : 'text-[#402619]'}`,
          text: String(card.text || ''),
        }),
      ]),
    ],
  );
}

/** One square review: stars, item tag, quote and who said it. */
function reviewCard(card) {
  const filled = Math.min(5, Math.max(1, Number(card.stars) || 5));
  const stars = el('div', { class: 'text-amber-700 text-sm tracking-widest font-bold' });
  for (let i = 1; i <= 5; i += 1) {
    stars.append(
      i <= filled
        ? document.createTextNode('★')
        : el('span', { class: 'text-[#1c120c]/30', text: '★' }),
    );
  }

  return el(
    'div',
    {
      class: `${CARD_BOX} bg-[#dfcaa8] text-[#1c120c] border-[1.5px] border-[#1c120c]/25`,
    },
    [
      el('div', {}, [
        stars,
        el('span', {
          class: 'inline-block text-[9px] font-black px-2 py-0.5 rounded-full bg-[#261208] text-[#dfcaa8] mt-2',
          text: String(card.tag || ''),
        }),
      ]),
      el('p', {
        class: 'text-[11px] font-semibold text-[#402619] leading-relaxed line-clamp-3',
        text: String(card.text || ''),
      }),
      el('p', { class: 'text-[10px] font-black text-[#1c120c]', text: String(card.author || '') }),
    ],
  );
}

/** Highlights row: the daily pick leads, then the admin's own cards. */
function renderHighlights(settings) {
  const section = document.getElementById('highlights-section');
  if (!section) return;
  section.hidden = settings.highlights_enabled === '0';
  if (section.hidden) return;

  const dailyText = document.getElementById('daily-special');
  if (dailyText && settings.daily_special) {
    dailyText.textContent = settings.daily_special;
  }

  const cards = parseCards(settings.highlights);
  if (!cards || cards.length === 0) return; // Keep existing rich markup in HTML if empty!

  const grid = document.getElementById('highlights-grid');
  const daily = document.getElementById('daily-card');
  grid.textContent = '';
  if (daily) grid.append(daily);
  for (const card of cards) {
    if (card && typeof card === 'object') grid.append(highlightCard(card));
  }
}

/** Reviews row under the «مشتری‌ها درباره ما چی میگن» heading. */
function renderReviews(settings) {
  const section = document.getElementById('reviews-section');
  if (!section) return;
  section.hidden = settings.reviews_enabled === '0';
  if (section.hidden) return;

  const title = document.getElementById('reviews-title');
  if (title && settings.reviews_title) title.textContent = settings.reviews_title;

  const cards = parseCards(settings.reviews);
  if (!cards || cards.length === 0) return; // Keep existing rich markup in HTML if empty!

  const grid = document.getElementById('reviews-grid');
  grid.textContent = '';
  for (const card of cards) {
    if (card && typeof card === 'object') grid.append(reviewCard(card));
  }
}

/* ---------------------------------------------------------------- *
 *  Real JavaScript-Driven Parallax Engine
 * ---------------------------------------------------------------- */
export function initParallax() {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const spineBeans = document.querySelector('.center-spine-beans');
  const centerBand = document.querySelector('.center-band');
  const waveLayer = document.querySelector('.wave-layer');
  const beanNodes = Array.from(document.querySelectorAll('.bean'));
  const crumbNodes = Array.from(document.querySelectorAll('.crumb'));

  // Pre-parse beans for max scroll performance without regex in render loop
  const beans = beanNodes.map((el) => {
    const speed = parseFloat(el.getAttribute('data-speed') || '-0.05');
    const style = el.getAttribute('style') || '';
    const rotMatch = style.match(/--rot:\s*(-?\d+(?:\.\d+)?deg)/);
    const rot = rotMatch ? rotMatch[1] : '0deg';
    const sclMatch = style.match(/--scl:\s*(-?\d+(?:\.\d+)?)/);
    const scl = sclMatch ? sclMatch[1] : '1';
    const txMatch = style.match(/--tx:\s*([^;]+)/);
    const tx = txMatch ? txMatch[1].trim() : '0px';
    const blrMatch = style.match(/--blr:\s*([^;]+)/);
    if (blrMatch) {
      el.style.filter = `blur(${blrMatch[1].trim()}) drop-shadow(0 12px 18px rgba(0, 0, 0, 0.65))`;
    }
    return { el, speed, rot, scl, tx };
  });

  const crumbs = crumbNodes.map((el) => {
    const speed = parseFloat(el.getAttribute('data-speed') || '-0.05');
    const style = el.getAttribute('style') || '';
    const rotMatch = style.match(/--rot:\s*(-?\d+(?:\.\d+)?deg)/);
    const rot = rotMatch ? rotMatch[1] : '0deg';
    return { el, speed, rot };
  });

  let ticking = false;

  function update() {
    const y = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;

    // 1. Center Spine Beans layer: differential parallax
    if (spineBeans) {
      const speed = parseFloat(spineBeans.getAttribute('data-speed') || '0.12');
      spineBeans.style.transform = `translateX(-50%) translateY(${(y * speed).toFixed(1)}px)`;
    }

    // 2. Center Texture Band: background position and subtle vertical drift
    if (centerBand) {
      const speed = parseFloat(centerBand.getAttribute('data-speed') || '0.04');
      centerBand.style.backgroundPositionY = `${(y * speed * 0.8).toFixed(1)}px`;
      centerBand.style.transform = `translateX(-50%) translateY(${(y * speed * 0.4).toFixed(1)}px)`;
    }

    // 3. Background Wave Layer
    if (waveLayer) {
      const speed = parseFloat(waveLayer.getAttribute('data-speed') || '0.10');
      waveLayer.style.transform = `translateY(${(y * speed).toFixed(1)}px)`;
    }

    // 4. Floating Coffee Beans: direct hardware-accelerated translateY
    for (let i = 0; i < beans.length; i++) {
      const b = beans[i];
      const dy = (y * b.speed).toFixed(1);
      b.el.style.transform = `translate3d(${b.tx}, ${dy}px, 0) rotate(${b.rot}) scale(${b.scl})`;
    }

    // 5. Crumbs
    for (let i = 0; i < crumbs.length; i++) {
      const c = crumbs[i];
      const dy = (y * c.speed).toFixed(1);
      c.el.style.transform = `translate3d(0, ${dy}px, 0) rotate(${c.rot})`;
    }

    ticking = false;
  }

  function onScroll() {
    if (!ticking) {
      ticking = true;
      requestAnimationFrame(update);
    }
  }

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  update(); // Initial run
}

/** Render the whole payload into index.html. */
export function renderMenu(data) {
  const { settings = {}, themes = [] } = data;
  applyTheme(themes, settings.theme);
  applyCustomization(settings);

  const currency = settings.currency || 'تومان';
  const categories = liveCategories(data.categories, data.items).map((category, index) => ({
    ...category,
    domId: slugify(category.name, index),
  }));

  document.title = `${settings.shop_name || 'منو'} — منوی دیجیتال کافه`;
  document.getElementById('shop-name').textContent = settings.shop_name || 'منو';
  document.getElementById('tagline').textContent = settings.tagline || '';
  document.getElementById('footer-note').textContent = settings.footer_note || '';
  const daily = document.getElementById('daily-special');
  if (daily && settings.daily_special) daily.textContent = settings.daily_special;
  renderHighlights(settings);
  renderReviews(settings);
  document.getElementById('today').textContent = new Date().toLocaleDateString('fa-IR', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  });

  renderNav(categories);

  const main = document.getElementById('sections');
  main.textContent = '';
  if (categories.length === 0) {
    showEmpty();
  } else {
    for (const [index, category] of categories.entries()) {
      main.append(renderSection(category, currency, index));
    }
    const totalItems = categories.reduce((sum, category) => sum + category.items.length, 0);
    document.getElementById('credit').textContent =
      `${faNumber.format(totalItems)} آیتم · قیمت‌ها بر اساس ${currency}`;
    watchSections();
  }

  document.getElementById('status').hidden = true;
  document.getElementById('menu').hidden = false;
  initParallax();
}

async function init() {
  try {
    renderMenu(await fetchMenu());
  } catch (error) {
    showError(error instanceof Error ? error.message : 'مشکلی پیش آمد.');
  }
}

init();
