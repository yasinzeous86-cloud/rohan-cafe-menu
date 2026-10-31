/**
 * Automated Asset Extraction Script for Rohan Cafe Menu
 *
 * Takes raw grid images of cafe items, detects geometric center and radius
 * of each item using contour/edge detection, crops a perfect square,
 * applies a strict circular mask, and exports high-quality transparent PNGs.
 */

const sharp = require('sharp');
const fs = require('fs');
const path = require('path');

const itemsDir = 'c:/app dev/RAST CHIN/items';
const outDir = 'c:/app dev/RAST CHIN/assets/defaults';

if (!fs.existsSync(outDir)) {
  fs.mkdirSync(outDir, { recursive: true });
}

// Configuration of the grid images
const grids = [
  // 1. Drinks & Juices (2 rows x 4 cols, white bg)
  {
    file: '023b303f-0e9f-4f03-a2c4-73ccbdaeb43a.jpe',
    rows: 2,
    cols: 4,
    type: 'white',
    items: [
      { row: 0, col: 0, name: 'espresso.png' },
      { row: 0, col: 1, name: 'cappuccino.png' },
      { row: 0, col: 2, name: 'caramel-macchiato.png' },
      { row: 0, col: 3, name: 'frappe.png' },
      { row: 1, col: 0, name: 'cold-brew.png' },
      { row: 1, col: 1, name: 'mojito.png' },
      { row: 1, col: 2, name: 'lemonade.png' },
      { row: 1, col: 3, name: 'orange-juice.png' },
    ]
  },
  // 2. Specialty Coffees (2 rows x 4 cols, white bg)
  {
    file: '08c3e271-505c-47bf-88f0-7b9f0de8293c.jpe',
    rows: 2,
    cols: 4,
    type: 'white',
    items: [
      { row: 0, col: 0, name: 'v60.png' },
      { row: 0, col: 1, name: 'iced-chocolate.png' },
      { row: 0, col: 2, name: 'vanilla-shake.png' },
      { row: 0, col: 3, name: 'americano.png' },
      { row: 1, col: 0, name: 'latte.png' },
      { row: 1, col: 1, name: 'flat-white.png' },
      { row: 1, col: 2, name: 'espresso-doppio.png' },
      { row: 1, col: 3, name: 'mocha.png' },
    ]
  },
  // 3. Hot Food, Pizzas & Desserts (3 rows x 4 cols, checkered bg)
  {
    file: 'cf6dae98-2d42-4a52-9bc9-803386f12ca4.jpe',
    rows: 3,
    cols: 4,
    type: 'checkered',
    items: [
      { row: 0, col: 0, name: 'pizza-pepperoni.png' },
      { row: 0, col: 1, name: 'pizza-vegetable.png' },
      { row: 0, col: 2, name: 'pizza-meat-mushroom.png' },
      { row: 0, col: 3, name: 'croissant.png' },
      { row: 1, col: 0, name: 'caesar-salad.png' },
      { row: 1, col: 1, name: 'french-fries.png' },
      { row: 1, col: 2, name: 'shakshuka.png' },
      { row: 1, col: 3, name: 'smoky-burger.png' },
      { row: 2, col: 0, name: 'chocolate-cake.png' },
      { row: 2, col: 1, name: 'date-cake.png' },
      { row: 2, col: 2, name: 'cheesecake.png' },
      { row: 2, col: 3, name: 'waffle.png' },
    ]
  },
  // 4. Bakery & Toasts (2 rows x 4 cols, white bg)
  {
    file: '768ac40e-823e-4150-8cab-d713cf8a25a4.jpe',
    rows: 2,
    cols: 4,
    type: 'white',
    items: [
      { row: 0, col: 0, name: 'bagel.png' },
      { row: 0, col: 1, name: 'halloumi-toast.png' },
      { row: 0, col: 2, name: 'avocado-toast.png' },
      { row: 0, col: 3, name: 'pancakes.png' },
      { row: 1, col: 0, name: 'brownie.png' },
      { row: 1, col: 1, name: 'cinnamon-roll.png' },
      { row: 1, col: 2, name: 'blueberry-muffin.png' },
      { row: 1, col: 3, name: 'cookies.png' },
    ]
  },
  // 5. Teas & Coolers (3 rows x 4 cols, checkered bg)
  {
    file: 'b9110972-b581-4e90-97b4-6f42c2cabe67.jpe',
    rows: 3,
    cols: 4,
    type: 'checkered',
    items: [
      { row: 0, col: 2, name: 'iced-latte.png' },
      { row: 0, col: 3, name: 'matcha-latte.png' },
      { row: 1, col: 1, name: 'rose-soda.png' },
      { row: 1, col: 2, name: 'pink-latte.png' },
      { row: 2, col: 1, name: 'hibiscus-tea.png' },
      { row: 2, col: 2, name: 'mint-tea.png' },
      { row: 2, col: 3, name: 'black-tea.png' },
    ]
  }
];

function isBackgroundPixel(r, g, b, type) {
  if (type === 'white') {
    return (r > 246 && g > 246 && b > 246);
  } else {
    // Checkered background has light grays and whites
    const isGray = (r > 195 && r < 240 && Math.abs(r - g) < 6 && Math.abs(r - b) < 6);
    const isWhite = (r > 246 && g > 246 && b > 246);
    return isGray || isWhite;
  }
}

async function processAll() {
  console.log('=== Starting Automated Asset Extraction ===');
  let totalExtracted = 0;

  for (const grid of grids) {
    const filePath = path.join(itemsDir, grid.file);
    if (!fs.existsSync(filePath)) {
      console.warn(`File not found: ${filePath}`);
      continue;
    }

    console.log(`\nProcessing grid: ${grid.file} (${grid.type})`);
    const { data, info } = await sharp(filePath).raw().toBuffer({ resolveWithObject: true });
    const { width, height, channels } = info;

    const cellW = width / grid.cols;
    const cellH = height / grid.rows;

    for (const item of grid.items) {
      const approxCx = item.col * cellW + cellW / 2;
      const approxCy = item.row * cellH + cellH / 2;

      // Scan inside the cell to detect object contour and geometric center
      const searchRx = Math.round(cellW * 0.44);
      const searchRy = Math.round(cellH * 0.44);

      // Search horizontal bounds from approx center
      let minX = Math.round(approxCx);
      let maxX = Math.round(approxCx);
      const cyInt = Math.round(approxCy);

      while (minX > approxCx - searchRx) {
        const idx = (cyInt * width + minX) * channels;
        if (isBackgroundPixel(data[idx], data[idx + 1], data[idx + 2], grid.type)) break;
        minX--;
      }
      while (maxX < approxCx + searchRx) {
        const idx = (cyInt * width + maxX) * channels;
        if (isBackgroundPixel(data[idx], data[idx + 1], data[idx + 2], grid.type)) break;
        maxX++;
      }

      // Search vertical bounds
      let minY = Math.round(approxCy);
      let maxY = Math.round(approxCy);
      const cxInt = Math.round(approxCx);

      while (minY > approxCy - searchRy) {
        const idx = (minY * width + cxInt) * channels;
        if (isBackgroundPixel(data[idx], data[idx + 1], data[idx + 2], grid.type)) break;
        minY--;
      }
      while (maxY < approxCy + searchRy) {
        const idx = (maxY * width + cxInt) * channels;
        if (isBackgroundPixel(data[idx], data[idx + 1], data[idx + 2], grid.type)) break;
        maxY++;
      }

      let detectedCx = Math.round((minX + maxX) / 2);
      let detectedCy = Math.round((minY + maxY) / 2);
      let rx = (maxX - minX) / 2;
      let ry = (maxY - minY) / 2;

      // Fallback if detection didn't trigger
      if (rx < 30 || ry < 30) {
        detectedCx = Math.round(approxCx);
        detectedCy = Math.round(approxCy);
        rx = searchRx * 0.85;
        ry = searchRy * 0.85;
      }

      // Calculate radius (tight circle around plate/cup)
      let radius = Math.round(Math.min(rx, ry));
      // For plates where the food doesn't touch the edge (e.g. croissant or cake on plate),
      // make sure we capture the entire plate
      if (grid.type === 'checkered') {
        if (radius < 65) {
          detectedCx = Math.round(approxCx);
          detectedCy = Math.round(approxCy);
          radius = 78;
        } else {
          radius = Math.max(20, radius - 1);
        }
      }

      // Square crop bounds
      const size = radius * 2;
      const left = Math.max(0, Math.min(width - size, Math.round(detectedCx - radius)));
      const top = Math.max(0, Math.min(height - size, Math.round(detectedCy - radius)));

      // Extract raw pixels for crop
      const cropped = await sharp(filePath)
        .extract({ left, top, width: size, height: size })
        .ensureAlpha()
        .raw()
        .toBuffer({ resolveWithObject: true });

      const cData = cropped.data;
      const cW = cropped.info.width;
      const cH = cropped.info.height;
      const cX = cW / 2;
      const cY = cH / 2;
      const rMask = (size / 2) - 1.0;

      for (let y = 0; y < cH; y++) {
        for (let x = 0; x < cW; x++) {
          const idx = (y * cW + x) * 4;
          const dx = x - cX;
          const dy = y - cY;
          const dist = Math.sqrt(dx * dx + dy * dy);

          if (dist > rMask) {
            cData[idx + 3] = 0; // Pure transparent outside circle
          } else if (dist > rMask - 1.5) {
            // Anti-aliased outer edge
            cData[idx + 3] = Math.round(255 * (rMask - dist) / 1.5);
          } else {
            // Inside the circle: for white background, make outer white transparent
            if (grid.type === 'white') {
              const r = cData[idx];
              const g = cData[idx + 1];
              const b = cData[idx + 2];
              if (r > 248 && g > 248 && b > 248) {
                // Background white
                cData[idx + 3] = 0;
              } else if (r > 240 && g > 240 && b > 240) {
                const alpha = (255 - r) / 15;
                cData[idx + 3] = Math.round(255 * Math.max(0, Math.min(1, alpha)));
              }
            }
          }
        }
      }

      // Resize all icons uniformly to 300x300 for crisp rendering on retina screens
      const targetSize = 300;
      const outPath = path.join(outDir, item.name);

      await sharp(cData, { raw: { width: cW, height: cH, channels: 4 } })
        .resize(targetSize, targetSize, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
        .png({ compressionLevel: 9 })
        .toFile(outPath);

      totalExtracted++;
      console.log(`  ✓ Extracted ${item.name} (${targetSize}x${targetSize}) [Center: ${detectedCx},${detectedCy}, R: ${radius}]`);
    }
  }

  console.log(`\n🎉 Successfully extracted ${totalExtracted} cafe item assets into assets/defaults/!`);
}

processAll().catch(err => {
  console.error('Fatal extraction error:', err);
  process.exit(1);
});
