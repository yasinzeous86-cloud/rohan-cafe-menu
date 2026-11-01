/**
 * ============================================================================
 * RASTCHIN CAFE & RESTAURANT MENU ENGINE (Core Preset v1.0)
 * ============================================================================
 *
 * This JavaScript module decouples the backend API and database from any custom
 * HTML frontend design. You can design ANY custom HTML layout and hook it into
 * this engine using standardized `data-*` attributes.
 *
 * DATA-ATTRIBUTE CONTRACT HOOKS:
 * ----------------------------------------------------------------------------
 * 1. Global / Shop Info:
 *    - `[data-shop-name]`      : Populated with settings.shop_name
 *    - `[data-tagline]`        : Populated with settings.tagline
 *    - `[data-footer-note]`    : Populated with settings.footer_note
 *    - `[data-today-date]`     : Populated with current Persian date
 *    - `[data-table-badge]`    : Shows table number if detected from ?table=X
 *
 * 2. Categories & Navigation:
 *    - `[data-category-nav]`   : Container for category navigation tabs/pills
 *    - `[data-category-list]`  : Container where category sections are mounted
 *    - `[data-category-section]`: Section wrapper for a category (carries data-category-id)
 *    - `[data-category-title]` : Element displaying category title
 *    - `[data-category-icon]`  : Image element for category icon/avatar
 *    - `.photo-circle-frame`   : Frame containing image that spins when items are clicked
 *
 * 3. Items & Cards:
 *    - `[data-item-list]`      : Container inside category holding item cards
 *    - `[data-item-card]`      : Item card container
 *    - `[data-item-title]`     : Element displaying item name
 *    - `[data-item-desc]`      : Element displaying item description / ingredients
 *    - `[data-item-price]`     : Element displaying formatted price or "تماس / استعلام"
 *    - `[data-item-img]`       : Image element displaying item thumbnail/preset
 *    - `[data-cart-btn]`       : Button to add item to cart (hidden in catalog-only mode)
 *
 * 4. Cart & Checkout Modal:
 *    - `[data-cart-bar]`       : Floating bar showing cart summary
 *    - `[data-cart-count]`     : Badge displaying total items count
 *    - `[data-cart-total]`     : Element displaying formatted total cart amount
 *    - `[data-cart-checkout]`  : Button that triggers opening checkout modal
 *    - `[data-cart-modal]`     : Modal container for checkout
 *    - `[data-modal-close]`    : Elements that close checkout modal on click
 *    - `[data-table-input]`    : Input or Select for table number (locked if ?table=X)
 *    - `[data-table-locked-badge]` : Badge indicating table is locked via QR
 *    - `[data-order-items-list]`   : List container in modal displaying cart items with +/-
 *    - `[data-order-final-price]`  : Final price summary inside modal
 *    - `[data-order-note]`     : Textarea for customer notes
 *    - `[data-order-submit]`   : Submit button to send order to barista
 *    - `[data-toast]`          : Notification container
 *
 * 5. URL Query Parameter Support:
 *    - `?table=X` (e.g., `?table=5` or `?table=VIP-1`) automatically locks table number.
 *
 * 6. Order System Mode (Catalog-Only):
 *    - Controlled by `settings.orders_enabled` (1 = cart active, 0 = catalog-only).
 * ============================================================================
 */

const API_URL = new URL('api.php', document.baseURI).href;

// Persian number formatter
const faNumber = new Intl.NumberFormat('fa-IR', {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});

/**
 * Format raw number or string to Persian currency string or badge.
 */
export function formatPrice(value, currency = 'تومان') {
  if (value === null || value === '' || value === undefined) {
    return 'تماس / استعلام';
  }
  const price = Number(value);
  if (!Number.isFinite(price) || price < 0) {
    return 'تماس / استعلام';
  }
  const amount = faNumber.format(price);
  return `${amount} ${currency}`;
}

/**
 * Fetch menu data from api.php
 */
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

/**
 * Default preset assets mapping for seamless zero-config visual appearance
 */
export const DEFAULT_ASSET_MAP = {
  'اسپرسو': 'assets/presets/espresso.png',
  'لاته': 'assets/presets/latte.png',
  'کاپوچینو': 'assets/presets/cappuccino.png',
  'موکا': 'assets/presets/mocha.png',
  'فلت وایت': 'assets/presets/flat-white.png',
  'کلد برو': 'assets/presets/cold-brew.png',
  'وی‌۶۰': 'assets/presets/v60.png',
  'وی 60': 'assets/presets/v60.png',
  'آمریکانو': 'assets/presets/americano.png',
  'لیموناد نعنایی': 'assets/presets/lemonade.png',
  'سودای گلاب': 'assets/presets/rose-soda.png',
  'شیک نوتلا': 'assets/presets/vanilla-shake.png',
  'کروسان کره‌ای': 'assets/presets/croissant.png',
  'کیک خرمایی': 'assets/presets/date-cake.png',
  'کیک شکلاتی': 'assets/presets/chocolate-cake.png',
  'چیزکیک': 'assets/presets/cheesecake.png',
  'تست هلومی': 'assets/presets/halloumi-toast.png',
  'شکشوعا': 'assets/presets/shakshuka.png',
  'اسموکی برگر': 'assets/presets/smoky-burger.png',
  'فرایز ترافل': 'assets/presets/french-fries.png',
  'پیتزا گوشت و قارچ': 'assets/presets/pizza-meat-mushroom.png',
  'پیتزا پپرونی': 'assets/presets/pizza-pepperoni.png',
  'پیتزا سبزیجات': 'assets/presets/pizza-vegetable.png',
  'سالاد سزار': 'assets/presets/caesar-salad.png',
  'وافل': 'assets/presets/waffle.png',
  'پنکیک': 'assets/presets/pancakes.png',
  'کوکی': 'assets/presets/cookies.png',
  'مافین بلوبری': 'assets/presets/blueberry-muffin.png',
  'تست آووکادو': 'assets/presets/avocado-toast.png',
  'سینامون رول': 'assets/presets/cinnamon-roll.png',
  'براونی': 'assets/presets/brownie.png',
  'بیگل': 'assets/presets/bagel.png',
};

export const CATEGORY_DEFAULT_ASSETS = {
  'قهوه': 'assets/presets/cappuccino.png',
  'نوشیدنی سرد': 'assets/presets/cold-brew.png',
  'نان و شیرینی': 'assets/presets/croissant.png',
  'آشپزخانه': 'assets/presets/smoky-burger.png',
  'پیتزا': 'assets/presets/pizza-pepperoni.png',
};

export function defaultAssetForItem(name) {
  if (!name) return 'assets/presets/espresso.png';
  const trimmed = name.trim();
  if (DEFAULT_ASSET_MAP[trimmed]) return DEFAULT_ASSET_MAP[trimmed];
  for (const [k, v] of Object.entries(DEFAULT_ASSET_MAP)) {
    if (trimmed.includes(k) || k.includes(trimmed)) return v;
  }
  return 'assets/presets/espresso.png';
}

export function defaultAssetForCategory(name) {
  if (!name) return 'assets/presets/cappuccino.png';
  const trimmed = name.trim();
  if (CATEGORY_DEFAULT_ASSETS[trimmed]) return CATEGORY_DEFAULT_ASSETS[trimmed];
  for (const [k, v] of Object.entries(CATEGORY_DEFAULT_ASSETS)) {
    if (trimmed.includes(k) || k.includes(trimmed)) return v;
  }
  return 'assets/presets/cappuccino.png';
}

/* ---------------------------------------------------------------- *
 *  Category Icon Pop-Spin Animation Engine
 * ---------------------------------------------------------------- */
window.swapCategoryIcon = function(categorySection, newSrc) {
  if (!categorySection || !newSrc) return;
  const sectionEl = typeof categorySection === 'string'
    ? document.getElementById(categorySection)
    : categorySection;
  if (!sectionEl) return;

  const sectionId = sectionEl.id || sectionEl.getAttribute('data-category-id');
  const navImg = document.querySelector(`[data-category-nav] [data-target="${sectionId}"] img`);
  const targets = [
    ...sectionEl.querySelectorAll('.category-main-icon, .category-main-photo, .photo-circle-frame img, [data-category-icon]')
  ];
  if (navImg) targets.push(navImg);

  targets.forEach((img) => {
    if (img._spinT1) clearTimeout(img._spinT1);
    if (img._spinT2) clearTimeout(img._spinT2);

    img.classList.remove('animate-icon-swap', 'icon-spin-swap');
    void img.offsetWidth; // Force DOM reflow to restart keyframe
    img.classList.add('animate-icon-swap');

    // Swap src at midpoint plateau (250ms)
    img._spinT1 = setTimeout(() => {
      img.src = newSrc;
    }, 250);

    // Remove animation class after finish
    img._spinT2 = setTimeout(() => {
      img.classList.remove('animate-icon-swap', 'icon-spin-swap');
    }, 560);
  });
};

window.revertCategoryIcon = function(categorySection) {
  if (!categorySection) return;
  const sectionEl = typeof categorySection === 'string'
    ? document.getElementById(categorySection)
    : categorySection;
  if (!sectionEl) return;

  const sectionId = sectionEl.id || sectionEl.getAttribute('data-category-id');
  const navImg = document.querySelector(`[data-category-nav] [data-target="${sectionId}"] img`);
  const targets = [
    ...sectionEl.querySelectorAll('.category-main-icon, .category-main-photo, .photo-circle-frame img, [data-category-icon]')
  ];
  if (navImg) targets.push(navImg);

  targets.forEach((img) => {
    const defaultSrc = img.dataset.defaultSrc;
    if (defaultSrc) {
      if (img._spinT1) clearTimeout(img._spinT1);
      if (img._spinT2) clearTimeout(img._spinT2);

      img.classList.remove('animate-icon-swap', 'icon-spin-swap');
      void img.offsetWidth;
      img.classList.add('animate-icon-swap');

      img._spinT1 = setTimeout(() => {
        img.src = defaultSrc;
      }, 250);

      img._spinT2 = setTimeout(() => {
        img.classList.remove('animate-icon-swap', 'icon-spin-swap');
      }, 560);
    }
  });
};

/* ---------------------------------------------------------------- *
 *  Shopping Cart & Order Engine
 * ---------------------------------------------------------------- */
export const cart = {}; // { [itemName]: { name, price, qty } }

export function getCartCount() {
  return Object.values(cart).reduce((sum, item) => sum + item.qty, 0);
}

export function getCartTotal() {
  return Object.values(cart).reduce((sum, item) => sum + (item.qty * item.price), 0);
}

export function showNotification(msg) {
  const toast = document.querySelector('[data-toast]') || document.getElementById('toast');
  if (!toast) return;
  const textEl = toast.querySelector('[data-toast-text]') || document.getElementById('toast-message') || toast;
  textEl.textContent = msg;

  toast.classList.remove('opacity-0', '-translate-y-4', 'pointer-events-none');
  toast.classList.add('opacity-100', 'translate-y-0');

  setTimeout(() => {
    toast.classList.add('opacity-0', '-translate-y-4', 'pointer-events-none');
    toast.classList.remove('opacity-100', 'translate-y-0');
  }, 2400);
}

export function updateCartUI() {
  const count = getCartCount();
  const total = getCartTotal();

  const countElements = document.querySelectorAll('[data-cart-count], #cart-counter');
  countElements.forEach((el) => {
    el.textContent = count.toLocaleString('fa-IR');
    el.classList.add('scale-125');
    setTimeout(() => el.classList.remove('scale-125'), 180);
  });

  const totalElements = document.querySelectorAll('[data-cart-total], #cart-total-price');
  totalElements.forEach((el) => {
    el.textContent = `${total.toLocaleString('fa-IR')} تومان`;
  });
}

window.addToCart = function(itemName, price) {
  if (window.__ordersEnabled === false) return;
  if (price === null || price === undefined || isNaN(Number(price))) return;
  if (!cart[itemName]) {
    cart[itemName] = { name: itemName, price: Number(price) || 0, qty: 0 };
  }
  cart[itemName].qty += 1;
  updateCartUI();
  showNotification(`${itemName} به سفارش اضافه شد`);
};

window.changeCartQty = function(itemName, delta) {
  if (!cart[itemName]) return;
  cart[itemName].qty += delta;
  if (cart[itemName].qty <= 0) {
    delete cart[itemName];
  }
  updateCartUI();
  renderCheckoutList();
};

export function openCheckoutModal() {
  if (window.__ordersEnabled === false) {
    showNotification('منو در حالت کاتالوگ نمایشی است.');
    return;
  }
  const count = getCartCount();
  if (count === 0) {
    showNotification('سبد سفارش خالی است. لطفاً آیتمی انتخاب کنید.');
    return;
  }
  renderCheckoutList();
  const modal = document.querySelector('[data-cart-modal]') || document.getElementById('checkout-modal');
  if (modal) {
    modal.hidden = false;
    modal.classList.remove('hidden');
  }
  document.body.style.overflow = 'hidden';
}

export function closeCheckoutModal() {
  const modal = document.querySelector('[data-cart-modal]') || document.getElementById('checkout-modal');
  if (modal) {
    modal.hidden = true;
    modal.classList.add('hidden');
  }
  document.body.style.overflow = '';
  const err = document.querySelector('[data-checkout-error]') || document.getElementById('checkout-error');
  if (err) err.hidden = true;
}

export function renderCheckoutList() {
  const list = document.querySelector('[data-order-items-list]') || document.getElementById('checkout-items-list');
  const countEl = document.querySelector('[data-order-items-count]') || document.getElementById('checkout-items-count');
  const finalPriceEl = document.querySelector('[data-order-final-price]') || document.getElementById('checkout-final-price');
  if (!list) return;

  list.innerHTML = '';
  const items = Object.values(cart);
  const totalCount = getCartCount();
  const totalPrice = getCartTotal();

  if (countEl) countEl.textContent = `${totalCount.toLocaleString('fa-IR')} آیتم`;
  if (finalPriceEl) finalPriceEl.textContent = `${totalPrice.toLocaleString('fa-IR')} تومان`;

  if (items.length === 0) {
    list.innerHTML = '<p class="text-stone-500 py-3 text-center">سبد سفارش خالی است.</p>';
    return;
  }

  items.forEach((item) => {
    const row = document.createElement('div');
    row.className = 'flex items-center justify-between bg-[#22140c] p-2.5 rounded-xl border border-[#3d251a] mb-2';
    row.innerHTML = `
      <div class="min-w-0 flex-1">
        <p class="font-bold text-stone-100 truncate">${item.name}</p>
        <p class="text-[11px] text-amber-200/70 font-mono">${(item.price * item.qty).toLocaleString('fa-IR')} تومان</p>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" class="w-6 h-6 rounded-lg bg-[#381f13] hover:bg-[#4a2a1b] text-amber-200 font-black flex items-center justify-center text-xs active:scale-90 transition-transform" onclick="changeCartQty('${item.name.replace(/'/g, "\\'")}', -1)">-</button>
        <span class="font-bold text-xs w-4 text-center text-stone-100">${item.qty.toLocaleString('fa-IR')}</span>
        <button type="button" class="w-6 h-6 rounded-lg bg-[#381f13] hover:bg-[#4a2a1b] text-amber-200 font-black flex items-center justify-center text-xs active:scale-90 transition-transform" onclick="changeCartQty('${item.name.replace(/'/g, "\\'")}', 1)">+</button>
      </div>
    `;
    list.appendChild(row);
  });
}

export async function submitOrder() {
  const items = Object.values(cart);
  if (items.length === 0) {
    showNotification('سبد سفارش خالی است.');
    return;
  }

  const tableSelect = document.querySelector('[data-table-input]') || document.getElementById('checkout-table');
  const tableNumber = window.__currentTable || (tableSelect ? tableSelect.value : 'میز عمومی');
  const noteInput = document.querySelector('[data-order-note]') || document.getElementById('checkout-note');
  const note = noteInput ? noteInput.value.trim() : '';
  const totalPrice = getCartTotal();

  const btn = document.querySelector('[data-order-submit]') || document.getElementById('submit-order-btn');
  const errBox = document.querySelector('[data-checkout-error]') || document.getElementById('checkout-error');
  if (errBox) errBox.hidden = true;

  var originalText = 'تأیید و ارسال سفارش';
  if (btn) {
    btn.disabled = true;
    originalText = btn.textContent;
    btn.textContent = 'در حال ارسال به باریستا…';
  }

  try {
    const payload = {
      table_number: tableNumber,
      customer_note: note,
      total_price: totalPrice,
      items: items.map((i) => ({ name: i.name, qty: i.qty, price: i.price })),
    };

    const res = await fetch('api.php?path=/orders', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(payload),
    });

    const data = await res.json();
    if (!res.ok || data?.ok === false) {
      throw new Error(data?.error || `خطا در ثبت سفارش (${res.status})`);
    }

    // Clear cart
    for (const k in cart) delete cart[k];
    updateCartUI();
    closeCheckoutModal();

    showNotification(`🎉 سفارش ${tableNumber} با موفقیت ثبت شد و به باریستا ارسال گردید!`);
  } catch (err) {
    if (errBox) {
      errBox.textContent = err.message || 'خطا در برقراری ارتباط با سرور.';
      errBox.hidden = false;
    }
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = originalText;
    }
  }
}

// Global modal triggers
window.openCheckoutModal = openCheckoutModal;
window.closeCheckoutModal = closeCheckoutModal;
window.submitOrder = submitOrder;

/* ---------------------------------------------------------------- *
 *  URL Query Table Auto-Detection (?table=X)
 * ---------------------------------------------------------------- */
export function initTableDetection() {
  const urlParams = new URLSearchParams(window.location.search);
  const tableParam = urlParams.get('table');
  if (!tableParam) return;

  const rawNum = tableParam.trim();
  const tableLabel = rawNum.startsWith('میز') ? rawNum : `میز ${rawNum}`;
  window.__currentTable = tableLabel;

  // 1. Update table badge / label in header and cart bar
  const tableBadges = document.querySelectorAll('[data-table-badge], #cart-table-label');
  tableBadges.forEach((el) => {
    el.textContent = `سفارش ${tableLabel}`;
  });

  // 2. Lock table input in modal
  const tableInput = document.querySelector('[data-table-input]') || document.getElementById('checkout-table');
  if (tableInput) {
    if (tableInput.tagName.toLowerCase() === 'select') {
      let matched = false;
      for (const opt of tableInput.options) {
        if (opt.value === tableLabel) {
          opt.selected = true;
          matched = true;
          break;
        }
      }
      if (!matched) {
        const newOpt = new Option(tableLabel, tableLabel, true, true);
        tableInput.add(newOpt);
      }
    }
    tableInput.value = tableLabel;
    tableInput.disabled = true;
    tableInput.classList.add('opacity-80', 'cursor-not-allowed', 'border-amber-500/60');
    tableInput.title = `شماره میز از روی کیوآر (${tableLabel}) قفل شده است.`;
  }

  // 3. Show locked badge
  const lockedBadges = document.querySelectorAll('[data-table-locked-badge], #checkout-table-locked-badge');
  lockedBadges.forEach((badge) => {
    badge.textContent = `🔒 قفل‌شده روی ${tableLabel}`;
    badge.hidden = false;
    badge.classList.remove('hidden');
  });
}

/* ---------------------------------------------------------------- *
 *  Accordion Toggle & Category Icon Revert
 * ---------------------------------------------------------------- */
window.toggleItemDrawer = function(id) {
  const drawer = document.getElementById(id);
  const arrow = document.getElementById(`${id}-arrow`);
  if (!drawer) return;

  const isOpen = drawer.classList.contains('open');
  if (isOpen) {
    drawer.classList.remove('open');
    if (arrow) arrow.textContent = '▾';
    const section = drawer.closest('[data-category-section]') || drawer.closest('section');
    if (section && typeof window.revertCategoryIcon === 'function') {
      window.revertCategoryIcon(section);
    }
  } else {
    drawer.classList.add('open');
    if (arrow) arrow.textContent = '▴';
  }
};

/* ---------------------------------------------------------------- *
 *  Decoupled Renderer Engine
 * ---------------------------------------------------------------- */
const slugify = (text, index) =>
  `cat-${String(index + 1).padStart(2, '0')}-${String(text)
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '') || 'section'}`;

export function renderMenu(data) {
  const { settings = {}, categories = [], items = [] } = data;
  const currency = settings.currency || 'تومان';

  // Catalog-only mode toggle
  const ordersEnabled = (settings.orders_enabled ?? '1') !== '0';
  window.__ordersEnabled = ordersEnabled;

  const cartBars = document.querySelectorAll('[data-cart-bar], #floating-cart-bar');
  cartBars.forEach((bar) => {
    bar.style.display = ordersEnabled ? '' : 'none';
  });

  // Shop Info Hooks
  const shopNameEls = document.querySelectorAll('[data-shop-name], #shop-name');
  shopNameEls.forEach((el) => { el.textContent = settings.shop_name || 'منو'; });
  if (settings.shop_name) document.title = `${settings.shop_name} — منوی دیجیتال کافه`;

  const taglineEls = document.querySelectorAll('[data-tagline], #tagline');
  taglineEls.forEach((el) => { el.textContent = settings.tagline || ''; });

  const footerEls = document.querySelectorAll('[data-footer-note], #footer-note');
  footerEls.forEach((el) => { el.textContent = settings.footer_note || ''; });

  const dateEls = document.querySelectorAll('[data-today-date], #today');
  const nowStr = new Date().toLocaleDateString('fa-IR', { weekday: 'short', day: 'numeric', month: 'short' });
  dateEls.forEach((el) => { el.textContent = nowStr; });

  // Filter visible items and group by category
  const visibleItems = items.filter((i) => i.status !== 'hidden');
  const activeCategories = categories
    .map((cat, idx) => ({
      ...cat,
      domId: slugify(cat.name, idx),
      items: visibleItems
        .filter((item) => Number(item.category_id) === Number(cat.id))
        .sort((a, b) => Number(a.sort_order) - Number(b.sort_order) || Number(a.id) - Number(b.id)),
    }))
    .filter((cat) => cat.items.length > 0);

  // Render Category Navigation
  const navContainer = document.querySelector('[data-category-nav] ul') || document.querySelector('#cat-nav ul');
  if (navContainer) {
    navContainer.innerHTML = '';
    activeCategories.forEach((cat) => {
      const defaultIcon = defaultAssetForCategory(cat.name);
      const li = document.createElement('li');
      li.className = 'shrink-0';
      li.innerHTML = `
        <a href="#${cat.domId}" class="cat-link nav-pill px-3.5 py-2 text-xs sm:text-sm font-bold rounded-full bg-black/40 hover:bg-black/60 text-stone-200 border border-amber-200/30 whitespace-nowrap transition-all flex items-center gap-2" data-target="${cat.domId}">
          <img class="w-5 h-5 rounded-full object-cover shrink-0" src="${defaultIcon}" alt="${cat.name}" data-default-src="${defaultIcon}">
          <span>${cat.name}</span>
        </a>
      `;
      navContainer.appendChild(li);
    });
  }

  // Render Main Categories List
  const listContainer = document.querySelector('[data-category-list]') || document.getElementById('sections');
  if (listContainer) {
    listContainer.innerHTML = '';

    if (activeCategories.length === 0) {
      listContainer.innerHTML = `
        <div class="border border-stone-800 p-8 text-center rounded-2xl bg-black/30">
          <p class="text-xl font-bold mb-2 text-stone-200">هنوز چیزی در منو ثبت نشده است</p>
          <p class="text-sm text-stone-400">دسته‌بندی و آیتم‌ها را از پنل مدیریت اضافه نمایید.</p>
        </div>
      `;
    } else {
      activeCategories.forEach((cat, index) => {
        const drawerId = `drawer-${String(index + 1).padStart(2, '0')}`;
        const defaultPhoto = defaultAssetForCategory(cat.name);

        const pricedItems = cat.items.filter(
          (i) => i.price !== null && i.price !== '' && i.price !== undefined && !isNaN(Number(i.price))
        );
        const minPriceText = pricedItems.length > 0
          ? formatPrice(Math.min(...pricedItems.map((i) => Number(i.price))), currency)
          : 'تماس / استعلام';

        const section = document.createElement('section');
        section.id = cat.domId;
        section.className = 'menu-section relative z-20 mb-8 scroll-mt-[180px]';
        section.setAttribute('data-category-section', '');
        section.setAttribute('data-category-id', cat.domId);

        // Build item rows
        const itemRowsHtml = cat.items.map((item) => {
          const hasPrice = item.price !== null && item.price !== '' && item.price !== undefined && !isNaN(Number(item.price));
          const priceText = hasPrice ? formatPrice(item.price, currency) : 'تماس / استعلام';
          const soldOut = item.status === 'sold_out';
          const itemImg = item.image_url || defaultAssetForItem(item.name);

          let actionBtn = '';
          if (hasPrice && ordersEnabled) {
            actionBtn = `
              <button type="button" class="px-2.5 py-1 rounded-lg bg-amber-200 text-[#1c120c] font-black text-xs hover:bg-amber-100 active:scale-90 transition-all ${soldOut ? 'opacity-40 pointer-events-none' : ''}" data-cart-btn onclick="event.stopPropagation(); addToCart('${item.name.replace(/'/g, "\\'")}', ${Number(item.price)}); swapCategoryIcon('${cat.domId}', '${itemImg}');">
                + افزودن
              </button>
            `;
          }

          return `
            <div class="item-card flex items-center justify-between p-2.5 rounded-xl hover:bg-white/5 active:scale-[0.99] transition-all cursor-pointer border-b border-white/5 last:border-b-0"
                 data-item-card
                 data-item-id="${item.id}"
                 data-item-name="${item.name}"
                 data-item-price="${item.price ?? ''}"
                 data-item-image="${itemImg}"
                 data-item-status="${item.status}"
                 onclick="swapCategoryIcon('${cat.domId}', '${itemImg}')">
              <div class="flex items-center gap-3">
                <img src="${itemImg}" alt="${item.name}" class="w-10 h-10 aspect-square object-cover object-center rounded-full border border-amber-300/30 shrink-0" data-item-img>
                <div>
                  <div class="font-bold text-sm text-stone-100 flex items-center gap-2">
                    <span data-item-title>${item.name}</span>
                    ${soldOut ? '<span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-rose-950 text-rose-300 border border-rose-800/60" data-item-badge>تمام شد</span>' : ''}
                  </div>
                  ${item.description ? `<p class="text-[11px] text-stone-400 mt-0.5 line-clamp-1" data-item-desc>${item.description}</p>` : ''}
                </div>
              </div>
              <div class="flex items-center gap-2.5 shrink-0">
                <span class="font-extrabold text-xs text-amber-300" data-item-price>${priceText}</span>
                ${actionBtn}
              </div>
            </div>
          `;
        }).join('');

        // Section structure
        section.innerHTML = `
          <div class="bg-[#1c120c]/90 border border-amber-200/25 rounded-2xl p-4 shadow-xl backdrop-blur-md">
            <div class="flex items-center justify-between cursor-pointer select-none" onclick="toggleItemDrawer('${drawerId}')">
              <div class="flex items-center gap-3.5">
                <div class="photo-circle-frame w-14 h-14 rounded-full p-1 bg-gradient-to-tr from-amber-700 to-amber-300 shadow-md shrink-0">
                  <img src="${defaultPhoto}" alt="${cat.name}" class="category-main-photo w-full h-full object-cover rounded-full" data-category-icon data-default-src="${defaultPhoto}">
                </div>
                <div>
                  <h3 class="text-base font-black text-amber-100" data-category-title>${cat.name}</h3>
                  <p class="text-xs text-amber-300/80 font-medium mt-0.5">شروع از ${minPriceText}</p>
                </div>
              </div>
              <button type="button" class="w-9 h-9 rounded-full bg-white/5 hover:bg-white/10 flex items-center justify-center text-amber-200 font-bold transition-all">
                <span id="${drawerId}-arrow">▾</span>
              </button>
            </div>
            <div id="${drawerId}" class="drawer-content mt-3 pt-2 border-t border-white/10" data-item-list>
              ${itemRowsHtml}
            </div>
          </div>
        `;

        listContainer.appendChild(section);
      });
    }
  }

  // Bind Checkout Triggers
  const checkoutTriggers = document.querySelectorAll('[data-cart-checkout], [onclick="handleCheckout()"]');
  checkoutTriggers.forEach((btn) => {
    btn.onclick = openCheckoutModal;
  });

  const closeTriggers = document.querySelectorAll('[data-modal-close], [onclick="closeCheckoutModal()"]');
  closeTriggers.forEach((btn) => {
    btn.onclick = closeCheckoutModal;
  });

  const submitBtn = document.querySelector('[data-order-submit], #submit-order-btn');
  if (submitBtn) {
    submitBtn.onclick = submitOrder;
  }

  // Initialize URL Query Table Detection (?table=X)
  initTableDetection();
}

/**
 * Initialize Menu
 */
async function init() {
  try {
    const data = await fetchMenu();
    renderMenu(data);
  } catch (err) {
    console.error('Menu load error:', err);
    const list = document.querySelector('[data-category-list]') || document.getElementById('sections');
    if (list) {
      list.innerHTML = `
        <div class="p-6 rounded-2xl bg-red-950/40 border border-red-800 text-center">
          <p class="text-red-300 font-bold mb-2">خطا در بارگذاری منو</p>
          <p class="text-xs text-red-200">${err.message}</p>
        </div>
      `;
    }
  }
}

// Auto-run on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
