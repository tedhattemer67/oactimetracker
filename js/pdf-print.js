// pdf-print.js (Step B — capture → jsPDF image, controlled width)
console.log('🧩 pdf-print.js loaded (Step B)');

// Tweakables
const WIDTH_FACTOR = 0.96; // 0.90 = slightly smaller, 0.75 = smaller, 0.60 = much smaller
const MARGINS_MM   = { top: 2, right: 2, bottom: 2, left: 2 };
const ORIENTATION  = 'portrait'; // or 'landscape'

// ---------- Ensure libs ----------
function ensureJsPDF() {
  return new Promise((resolve, reject) => {
    let JsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
    if (typeof JsPDF === 'function') return resolve(JsPDF);

    console.warn('jsPDF not found; loading from CDN…');
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
    s.onload = () => {
      JsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
      if (typeof JsPDF === 'function') resolve(JsPDF);
      else reject(new Error('jsPDF failed to load'));
    };
    s.onerror = () => reject(new Error('Could not load jsPDF from CDN'));
    document.head.appendChild(s);
  });
}

function ensureHtml2canvas() {
  return new Promise((resolve, reject) => {
    if (typeof window.html2canvas === 'function') return resolve(window.html2canvas);

    console.warn('html2canvas not found; loading from CDN…');
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
    s.onload = () => {
      if (typeof window.html2canvas === 'function') resolve(window.html2canvas);
      else reject(new Error('html2canvas failed to load'));
    };
    s.onerror = () => reject(new Error('Could not load html2canvas from CDN'));
    document.head.appendChild(s);
  });
}

// ---------- Clone off-screen for full capture ----------
function cloneOffscreen(el) {
  const rect  = el.getBoundingClientRect();
  const fullW = Math.ceil(Math.max(el.scrollWidth,  rect.width))  + 8; // safety pad
  const fullH = Math.ceil(Math.max(el.scrollHeight, rect.height)) + 8;

  const wrap = document.createElement('div');
  wrap.style.position = 'fixed';
  wrap.style.left = '-99999px';
  wrap.style.top = '0';
  wrap.style.background = '#ffffff';
  wrap.style.paddingRight = '8px';   // avoid last-character clipping
  wrap.style.overflow = 'visible';
  wrap.style.width = fullW + 'px';

  const style = document.createElement('style');
  style.textContent = `
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table { border-collapse: collapse; }
    th, td { padding: 4px; }
    .no-print { display: none !important; }
    tr, td, th { page-break-inside: avoid; break-inside: avoid; }
  `;
  wrap.appendChild(style);

  const clone = el.cloneNode(true);
  clone.style.margin = '0';
  clone.style.width  = fullW + 'px';
  wrap.appendChild(clone);

  document.body.appendChild(wrap);
  return { wrap, fullW, fullH };
}

// ---------- Render with html2canvas at exact size ----------
function renderToCanvas(node, widthPx, heightPx) {
  return window.html2canvas(node, {
    scale: 2,
    backgroundColor: '#ffffff',
    useCORS: true,
    width: widthPx,
    height: heightPx,
    windowWidth: widthPx,
    windowHeight: heightPx,
    scrollX: 0,
    scrollY: 0
  });
}

// ---------- Put canvas into PDF at controlled width ----------
function canvasToPdf(canvas, filename, margins, orientation) {
  const JsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : window.jsPDF;
  const pdf   = new JsPDF({ unit: 'mm', format: 'a4', orientation });

  const pageW = pdf.internal.pageSize.getWidth();
  const pageH = pdf.internal.pageSize.getHeight();

  const maxW  = pageW - margins.left - margins.right;
  const maxH  = pageH - margins.top  - margins.bottom;

  const aspect = canvas.height / canvas.width;

  // SAFETY: back off 0.5% so we don't hit the edge due to rounding
  const SAFETY = 1.7;

  // Start by filling width; if too tall, fit height instead.
  let targetW = maxW * SAFETY;
  let targetH = targetW * aspect;

  if (targetH > maxH * SAFETY) {
    targetH = maxH * SAFETY;
    targetW = targetH / aspect;
  }

  // Center horizontally; align top with margin
  const x = margins.left + (maxW - targetW) / 2;
  const y = margins.top;

  pdf.addImage(canvas.toDataURL('image/png'), 'PNG', x, y, targetW, targetH, undefined, 'FAST');
  const base = (window.MyPluginPDF && MyPluginPDF.filename ? MyPluginPDF.filename.replace(/\.pdf$/i,'') : 'document');
  pdf.save(`${base}-${Date.now()}.pdf`);
  console.log(`🧮 Auto-fit used: targetW=${targetW.toFixed(2)}mm, targetH=${targetH.toFixed(2)}mm`);
}


// ---------- Main ----------
document.addEventListener('DOMContentLoaded', async () => {
  console.log('📘 DOMContentLoaded fired (Step B)');

  const btn = document.getElementById('pdf-button-v2') || document.getElementById('pdf-button');
  if (!btn) { console.error('❌ No #pdf-button-v2 or #pdf-button found'); return; }

  btn.addEventListener('click', async (e) => {
    e.preventDefault();

    const el = document.getElementById('printable-area');
    if (!el) { console.error('❌ #printable-area not found'); return; }

    try {
      await ensureJsPDF();
      await ensureHtml2canvas();

      const { wrap, fullW, fullH } = cloneOffscreen(el);
      console.log('🖼️ Rendering canvas at', fullW, 'x', fullH, 'px…');

      const canvas = await renderToCanvas(wrap, fullW, fullH);
      console.log('📄 Converting canvas to PDF at controlled width…');

      canvasToPdf(canvas, (window.MyPluginPDF && MyPluginPDF.filename) || 'document.pdf', MARGINS_MM, ORIENTATION);
      document.body.removeChild(wrap);
      console.log('🧹 Wrapper removed');
    } catch (err) {
      console.error('⚠️ PDF error:', err);
      alert('PDF error: ' + err.message); // visible feedback
    }
  });
});
