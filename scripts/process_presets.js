/**
 * process_presets.js
 *
 * Automated High-Precision Centering and Cropping Script for Cafe Presets.
 *
 * Scans transparent PNG grids in items-nobg/:
 * 1. Detects exact item bounding box via alpha channel (alpha > 15).
 * 2. Calculates true geometric center: cx = (min_x + max_x)/2, cy = (min_y + max_y)/2.
 * 3. Adds 6% uniform padding: canvas_size = int(max(w, h) * 1.12).
 * 4. Centers item perfectly on transparent square canvas.
 * 5. Exports standardized 512x512 PNGs into assets/presets/.
 */

const sharp = require('sharp');
const fs = require('fs');
const path = require('path');

const inputDir = fs.existsSync(path.join(__dirname, '..', 'items-nobg'))
  ? path.join(__dirname, '..', 'items-nobg')
  : path.join(__dirname, '..', 'items no background');
const outputDir = path.join(__dirname, '..', 'assets', 'presets');

if (!fs.existsSync(outputDir)) {
  fs.mkdirSync(outputDir, { recursive: true });
}

// Complete Grid Definitions mapping to all items in items-nobg/
const grids = [
  // 1. Classic Coffees & Juices (2 rows x 4 cols)
  {
    file: '023b303f-0e9f-4f03-a2c4-73ccbdaeb43a-Photoroom.png',
    rows: 2,
    cols: 4,
    category: 'نوشیدنی سرد و گرم',
    items: [
      { row: 0, col: 0, name: 'espresso.png', titleFa: 'اسپرسو' },
      { row: 0, col: 1, name: 'cappuccino.png', titleFa: 'کاپوچینو' },
      { row: 0, col: 2, name: 'caramel-macchiato.png', titleFa: 'کارامل ماکیاتو' },
      { row: 0, col: 3, name: 'frappe.png', titleFa: 'فراپه' },
      { row: 1, col: 0, name: 'cold-brew.png', titleFa: 'کلد برو' },
      { row: 1, col: 1, name: 'mojito.png', titleFa: 'موهیتو' },
      { row: 1, col: 2, name: 'lemonade.png', titleFa: 'لیموناد' },
      { row: 1, col: 3, name: 'orange-juice.png', titleFa: 'آب پرتقال' },
    ]
  },
  // 2. Specialty Brews & Shakes (2 rows x 4 cols)
  {
    file: '08c3e271-505c-47bf-88f0-7b9f0de8293c-Photoroom.png',
    rows: 2,
    cols: 4,
    category: 'قهوه و شیک',
    items: [
      { row: 0, col: 0, name: 'v60.png', titleFa: 'قهوه دمی وی ۶۰' },
      { row: 0, col: 1, name: 'iced-chocolate.png', titleFa: 'آیس چاکلت' },
      { row: 0, col: 2, name: 'vanilla-shake.png', titleFa: 'میلک شیک وانیل' },
      { row: 0, col: 3, name: 'americano.png', titleFa: 'آمریکانو' },
      { row: 1, col: 0, name: 'latte.png', titleFa: 'کافه لاته' },
      { row: 1, col: 1, name: 'flat-white.png', titleFa: 'فلت وایت' },
      { row: 1, col: 2, name: 'espresso-doppio.png', titleFa: 'اسپرسو دوپیو' },
      { row: 1, col: 3, name: 'mocha.png', titleFa: 'کافه موکا' },
    ]
  },
  // 3. Hot Kitchen, Pizzas & Cakes (3 rows x 4 cols)
  {
    file: 'cf6dae98-2d42-4a52-9bc9-803386f12ca4-Photoroom.png',
    rows: 3,
    cols: 4,
    category: 'غذا و دسر',
    items: [
      { row: 0, col: 0, name: 'pizza-pepperoni.png', titleFa: 'پیتزا پپرونی' },
      { row: 0, col: 1, name: 'pizza-vegetable.png', titleFa: 'پیتزا سبزیجات' },
      { row: 0, col: 2, name: 'pizza-meat-mushroom.png', titleFa: 'پیتزا گوشت و قارچ' },
      { row: 0, col: 3, name: 'croissant.png', titleFa: 'کروسان' },
      { row: 1, col: 0, name: 'caesar-salad.png', titleFa: 'سالاد سزار' },
      { row: 1, col: 1, name: 'french-fries.png', titleFa: 'سیب‌زمینی سرخ‌کرده' },
      { row: 1, col: 2, name: 'shakshuka.png', titleFa: 'شکشوکا' },
      { row: 1, col: 3, name: 'smoky-burger.png', titleFa: 'اسموکی برگر' },
      { row: 2, col: 0, name: 'chocolate-cake.png', titleFa: 'کیک شکلاتی' },
      { row: 2, col: 1, name: 'date-cake.png', titleFa: 'کیک خرما' },
      { row: 2, col: 2, name: 'cheesecake.png', titleFa: 'چیزکیک' },
      { row: 2, col: 3, name: 'waffle.png', titleFa: 'وافل بلژیکی' },
    ]
  },
  // 4. Teas, Coolers & Herbal Infusions (3 rows x 4 cols)
  {
    file: 'b9110972-b581-4e90-97b4-6f42c2cabe67-Photoroom.png',
    rows: 3,
    cols: 4,
    category: 'دمنوش و چای',
    items: [
      { row: 0, col: 0, name: 'tea-pot.png', titleFa: 'قوری چای مخصوص' },
      { row: 0, col: 1, name: 'chai-latte.png', titleFa: 'چای لاته ماسالا' },
      { row: 0, col: 2, name: 'iced-latte.png', titleFa: 'آیس لاته' },
      { row: 0, col: 3, name: 'matcha-latte.png', titleFa: 'ماچا لاته' },
      { row: 1, col: 0, name: 'green-tea.png', titleFa: 'چای سبز' },
      { row: 1, col: 1, name: 'rose-soda.png', titleFa: 'سودای گلاب و زعفران' },
      { row: 1, col: 2, name: 'pink-latte.png', titleFa: 'پینک لاته' },
      { row: 1, col: 3, name: 'earl-grey-tea.png', titleFa: 'چای ارل گری' },
      { row: 2, col: 0, name: 'citrus-tea.png', titleFa: 'دمنوش مرکبات' },
      { row: 2, col: 1, name: 'hibiscus-tea.png', titleFa: 'چای ترش' },
      { row: 2, col: 2, name: 'mint-tea.png', titleFa: 'دمنوش نعنا' },
      { row: 2, col: 3, name: 'black-tea.png', titleFa: 'چای سیاه اصیل' },
    ]
  },
  // 5. Bakery, Toasts & Breakfast (2 rows x 4 cols)
  {
    file: '15fc5765-ed76-424f-81eb-3651b23ebabb-Photoroom.png',
    rows: 2,
    cols: 4,
    category: 'نان و شیرینی',
    items: [
      { row: 0, col: 0, name: 'bagel.png', titleFa: 'بیگل کنجدی' },
      { row: 0, col: 1, name: 'halloumi-toast.png', titleFa: 'تست هالومی' },
      { row: 0, col: 2, name: 'avocado-toast.png', titleFa: 'تست آووکادو' },
      { row: 0, col: 3, name: 'pancakes.png', titleFa: 'پنکیک با سیروپ' },
      { row: 1, col: 0, name: 'brownie.png', titleFa: 'براونی شکلاتی' },
      { row: 1, col: 1, name: 'cinnamon-roll.png', titleFa: 'سینامون رول' },
      { row: 1, col: 2, name: 'blueberry-muffin.png', titleFa: 'مافین بلوبری' },
      { row: 1, col: 3, name: 'cookies.png', titleFa: 'کوکی شکلات تکه‌ای' },
    ]
  },
  // 6. Brunch, Bowls & Specialty Sandwiches (2 rows x 4 cols)
  {
    file: 'e3739fff-2964-4323-b470-d87b1cde3d70-Photoroom.png',
    rows: 2,
    cols: 4,
    category: 'صبحانه و برانچ',
    items: [
      { row: 0, col: 0, name: 'english-breakfast.png', titleFa: 'صبحانه انگلیسی' },
      { row: 0, col: 1, name: 'scrambled-eggs.png', titleFa: 'اسکرامبل اگ' },
      { row: 0, col: 2, name: 'granola-bowl.png', titleFa: 'گرانولا بول' },
      { row: 0, col: 3, name: 'acai-bowl.png', titleFa: 'آسای بول میوه‌ای' },
      { row: 1, col: 0, name: 'club-sandwich.png', titleFa: 'کلاب ساندویچ' },
      { row: 1, col: 1, name: 'panini.png', titleFa: 'پانینی مرغ گریل' },
      { row: 1, col: 2, name: 'chicken-salad.png', titleFa: 'سالاد مرغ ویژه' },
      { row: 1, col: 3, name: 'pasta.png', titleFa: 'پاستا آلفردو' },
    ]
  }
];

async function processAll() {
  console.log('=== Starting Perfect Square & Centering Cropping ===');
  const manifest = [];
  let totalProcessed = 0;

  for (const grid of grids) {
    const inputPath = path.join(inputDir, grid.file);
    if (!fs.existsSync(inputPath)) {
      console.warn(`[WARN] File not found: ${inputPath}`);
      continue;
    }

    console.log(`\nProcessing Grid: ${grid.file} (${grid.rows}x${grid.cols}) - ${grid.category}`);
    const { data, info } = await sharp(inputPath).raw().toBuffer({ resolveWithObject: true });
    const { width, height, channels } = info;

    const cellW = width / grid.cols;
    const cellH = height / grid.rows;

    for (const item of grid.items) {
      // 1. Determine cell search boundary with safe margins
      const xStart = Math.max(0, Math.round(item.col * cellW));
      const xEnd = Math.min(width, Math.round((item.col + 1) * cellW));
      const yStart = Math.max(0, Math.round(item.row * cellH));
      const yEnd = Math.min(height, Math.round((item.row + 1) * cellH));

      // 2. Bounding Box Detection via Alpha Channel (alpha > 15)
      let min_x = width;
      let max_x = 0;
      let min_y = height;
      let max_y = 0;
      let nonZeroCount = 0;

      for (let y = yStart; y < yEnd; y++) {
        for (let x = xStart; x < xEnd; x++) {
          const a = data[(y * width + x) * channels + 3];
          if (a > 15) {
            if (x < min_x) min_x = x;
            if (x > max_x) max_x = x;
            if (y < min_y) min_y = y;
            if (y > max_y) max_y = y;
            nonZeroCount++;
          }
        }
      }

      if (nonZeroCount < 100 || min_x >= max_x || min_y >= max_y) {
        console.warn(`  [SKIP] No pixels detected for ${item.name} at [${item.row}, ${item.col}]`);
        continue;
      }

      // 3. True Geometric Center Calculation
      const cx = (min_x + max_x) / 2;
      const cy = (min_y + max_y) / 2;
      const itemW = max_x - min_x + 1;
      const itemH = max_y - min_y + 1;
      const diameter = Math.max(itemW, itemH);

      // 4. Square Canvas with Uniform 6% Padding: canvas_size = int(diameter * 1.12)
      const canvas_size = Math.round(diameter * 1.12);

      // 5. Extract item bounding box from source image
      const itemBuffer = await sharp(inputPath)
        .extract({ left: min_x, top: min_y, width: itemW, height: itemH })
        .png()
        .toBuffer();

      // 6. Paste item so that its center (cx, cy) aligns with (canvas_size/2, canvas_size/2)
      const paste_x = Math.round((canvas_size - itemW) / 2);
      const paste_y = Math.round((canvas_size - itemH) / 2);

      const centeredSquare = await sharp({
        create: {
          width: canvas_size,
          height: canvas_size,
          channels: 4,
          background: { r: 0, g: 0, b: 0, alpha: 0 }
        }
      })
      .composite([{
        input: itemBuffer,
        left: paste_x,
        top: paste_y
      }])
      .png()
      .toBuffer();

      // 7. Standardization & Output: Resize to high-res 512x512 PNG
      const outPath = path.join(outputDir, item.name);
      await sharp(centeredSquare)
        .resize(512, 512, {
          fit: 'contain',
          background: { r: 0, g: 0, b: 0, alpha: 0 },
          kernel: sharp.kernel.lanczos3
        })
        .png({ compressionLevel: 9, quality: 95 })
        .toFile(outPath);

      totalProcessed++;
      console.log(`  ✓ [${totalProcessed}] ${item.name.padEnd(24)} -> Centered 512x512 (Diam: ${diameter}px, Canvas: ${canvas_size}px)`);

      manifest.push({
        id: item.name.replace('.png', ''),
        name: item.titleFa,
        file: item.name,
        path: `assets/presets/${item.name}`,
        category: grid.category
      });
    }
  }

  // Save manifest for admin UI consumption
  const manifestPath = path.join(outputDir, 'presets_manifest.json');
  fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2), 'utf8');
  console.log(`\n🎉 Successfully processed and exported ${totalProcessed} presets to assets/presets/!`);
  console.log(`Manifest written to: ${manifestPath}`);
}

processAll().catch(err => {
  console.error('[ERROR] Failed processing presets:', err);
  process.exit(1);
});
