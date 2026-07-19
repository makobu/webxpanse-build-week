const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');
const { marked } = require('marked');

const DIR = __dirname;
const CHROME_PATH = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

// ── Shared styles ──────────────────────────────────────────────────────────

const baseCSS = `
  @page { size: A4; margin: 20mm 18mm; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    font-size: 10.5pt;
    line-height: 1.65;
    color: #1a1a2e;
  }
  .doc-header {
    border-bottom: 2px solid #2563eb;
    padding-bottom: 10px;
    margin-bottom: 24px;
  }
  .doc-header h1 {
    font-size: 22pt;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0 0 4px 0;
    letter-spacing: -0.3px;
  }
  .doc-header .subtitle {
    font-size: 9pt;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 1.2px;
  }
  h1 { font-size: 20pt; font-weight: 700; color: #1a1a2e; margin: 28px 0 12px 0; }
  h2 {
    font-size: 14pt;
    font-weight: 600;
    color: #2563eb;
    margin: 24px 0 10px 0;
    padding-bottom: 4px;
    border-bottom: 1px solid #e2e8f0;
  }
  h3 { font-size: 11.5pt; font-weight: 600; color: #374151; margin: 18px 0 8px 0; }
  h4 { font-size: 10.5pt; font-weight: 600; color: #4b5563; margin: 14px 0 6px 0; }
  p { margin: 0 0 10px 0; }
  ul, ol { margin: 0 0 12px 18px; }
  li { margin-bottom: 4px; }
  li > ul, li > ol { margin-top: 4px; margin-bottom: 4px; }
  a { color: #2563eb; text-decoration: none; }
  strong { font-weight: 600; }

  /* Callout boxes for key statements (replaces inline code used as quotes) */
  .callout {
    background: #f0f4ff;
    border-left: 4px solid #2563eb;
    padding: 12px 16px;
    margin: 12px 0 16px 0;
    font-size: 10.5pt;
    font-style: italic;
    color: #1e3a5f;
    border-radius: 0 6px 6px 0;
  }

  /* Regular inline code (not callouts) */
  code {
    font-family: 'Cascadia Code', 'Consolas', monospace;
    font-size: 9pt;
    background: #f1f5f9;
    padding: 1px 5px;
    border-radius: 3px;
  }

  /* Tables */
  table {
    width: 100%;
    border-collapse: collapse;
    margin: 14px 0;
    font-size: 9.5pt;
  }
  th {
    background: #2563eb;
    color: #fff;
    font-weight: 600;
    text-align: left;
    padding: 8px 10px;
  }
  td {
    padding: 7px 10px;
    border-bottom: 1px solid #e2e8f0;
  }
  tr:nth-child(even) td { background: #f8f9fa; }

  /* Email template cards */
  .email-card {
    background: #fafbfc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px 20px;
    margin: 12px 0 18px 0;
  }
  .email-card p { margin-bottom: 8px; }

  .page-break { page-break-before: always; }
`;

const onePagerCSS = `
  @page { size: A4; margin: 7mm 10mm; }
  body { font-size: 7.2pt; line-height: 1.3; }
  .doc-header { margin-bottom: 5px; padding-bottom: 3px; border-bottom-width: 1.5px; }
  .doc-header h1 { font-size: 13pt; margin-bottom: 1px; }
  .doc-header .subtitle { font-size: 6.5pt; }
  h2 { font-size: 8.5pt; margin: 4px 0 1px 0; padding-bottom: 0; border-bottom: none; }
  h3 { font-size: 7.5pt; margin: 3px 0 1px 0; }
  p { margin: 0 0 1px 0; }
  ul, ol { margin: 0 0 1px 10px; }
  li { margin-bottom: 0; font-size: 7.2pt; }
  .callout { padding: 3px 6px; margin: 2px 0 4px 0; font-size: 7.2pt; }
  .two-col { display: flex; gap: 10px; margin: 2px 0; }
  .two-col .col { flex: 1; min-width: 0; }
  .three-col { display: flex; gap: 10px; margin: 2px 0; }
  .three-col .col { flex: 1; min-width: 0; }
`;

const pitchDeckCSS = `
  .slide-marker {
    display: inline-block;
    background: #2563eb;
    color: #fff;
    font-size: 8pt;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 10px;
    margin-bottom: 6px;
    letter-spacing: 0.5px;
  }
`;

const landscapeCSS = `
  @page { size: A4 landscape; margin: 15mm; }
  body { font-size: 8.5pt; }
  .doc-header { margin-bottom: 16px; }
  .doc-header h1 { font-size: 18pt; }
  .group-header td {
    background: #1e3a5f !important;
    color: #fff;
    font-weight: 700;
    font-size: 9pt;
    padding: 6px 10px;
    letter-spacing: 0.3px;
  }
  .summary { margin-bottom: 16px; font-size: 9pt; color: #4b5563; }
`;

// ── Helpers ────────────────────────────────────────────────────────────────

function wrapHTML(title, bodyHTML, extraCSS = '') {
  return `<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>${baseCSS}\n${extraCSS}</style></head>
<body>
<div class="doc-header">
  <h1>${title}</h1>
  <div class="subtitle">Confidential &mdash; Pre-Seed Fundraising Materials</div>
</div>
${bodyHTML}
</body></html>`;
}

/** Convert standalone <p><code>…</code></p> into callout boxes */
function convertCallouts(html) {
  return html.replace(/<p><code>([^<]+)<\/code><\/p>/g, '<div class="callout">$1</div>');
}

/** Clean Windows file paths from fundraising-package.md links */
function cleanFilePaths(html) {
  // Convert links with full paths to just the document name
  return html.replace(
    /<a href="[^"]*">([^<]+\.(?:md|csv))<\/a>/g,
    (_, name) => {
      const pretty = name.replace(/\.\w+$/, '').replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
      return `<strong>${pretty}</strong>`;
    }
  );
}

function titleFromFilename(filename) {
  return filename
    .replace(/\.\w+$/, '')
    .replace(/-/g, ' ')
    .replace(/\b\w/g, c => c.toUpperCase());
}

// ── File processors ────────────────────────────────────────────────────────

function processInvestorOnePager(md) {
  // Remove the top-level H1 (we use the doc-header instead)
  md = md.replace(/^# .+\n+/, '');

  // Split into sections by H2
  const sections = [];
  let current = { heading: '', body: '' };
  for (const line of md.split('\n')) {
    if (line.startsWith('## ')) {
      if (current.heading || current.body) sections.push(current);
      current = { heading: line, body: '' };
    } else {
      current.body += line + '\n';
    }
  }
  if (current.heading || current.body) sections.push(current);

  // Render each section
  const rendered = sections.map(s => {
    const h = marked.parse(s.heading + '\n' + s.body);
    return convertCallouts(h);
  });

  // Build layout: Company+Problem+Solution at top, then 3-col grid, then 2-col, then Closing
  // Sections: 0=Company, 1=Problem, 2=Solution, 3=Why Now, 4=Why This Can Win, 5=Product Proof, 6=GTM, 7=What We Need, 8=Raise, 9=Closing
  const top = rendered.slice(0, 3).join('\n');
  const col1 = rendered.slice(3, 5).join('\n');  // Why Now + Why This Can Win
  const col2 = rendered.slice(5, 7).join('\n');  // Product Proof + GTM
  const col3 = rendered.slice(7, 9).join('\n');  // What We Need + Raise
  const closing = rendered.slice(9).join('\n');

  const bodyHTML = `
    ${top}
    <div class="three-col">
      <div class="col">${col1}</div>
      <div class="col">${col2}</div>
      <div class="col">${col3}</div>
    </div>
    ${closing}
  `;

  return wrapHTML('Investor One-Pager', bodyHTML, onePagerCSS);
}

function processFundraisingPackage(md) {
  md = md.replace(/^# .+\n+/, '');
  let html = marked.parse(md);
  html = convertCallouts(html);
  html = cleanFilePaths(html);
  return wrapHTML('Fundraising Package', html);
}

function processPitchDeckOutline(md) {
  md = md.replace(/^# .+\n+/, '');

  // Add page breaks before each "## Slide N:" heading (except the first)
  let slideCount = 0;
  md = md.replace(/^## (Slide \d+):/gm, (match, label) => {
    slideCount++;
    const prefix = slideCount > 1 ? '\n---PAGE_BREAK---\n' : '';
    return `${prefix}## ${label}:`;
  });
  // Also break before Demo Flow
  md = md.replace(/^## Demo Flow/m, '\n---PAGE_BREAK---\n## Demo Flow');

  let html = marked.parse(md);
  html = convertCallouts(html);

  // Add slide markers
  let slideIdx = 0;
  html = html.replace(/<h2>(Slide (\d+)):/g, (_, full, num) => {
    slideIdx++;
    return `<div class="slide-marker">SLIDE ${num} OF 9</div>\n<h2>${full}:`;
  });

  // Convert page break markers
  html = html.replace(/<p>---PAGE_BREAK---<\/p>/g, '<div class="page-break"></div>');

  return wrapHTML('Pitch Deck Outline', html, pitchDeckCSS);
}

function processPositioningAndWedge(md) {
  md = md.replace(/^# .+\n+/, '');
  let html = marked.parse(md);
  html = convertCallouts(html);
  return wrapHTML('Positioning & Wedge', html);
}

function processInvestorOutreachSequence(md) {
  md = md.replace(/^# .+\n+/, '');
  let html = marked.parse(md);
  html = convertCallouts(html);

  // Wrap email bodies (sections between Subject/Body headers) in email cards
  // Find consecutive backtick-converted callout divs that form email content
  html = html.replace(
    /(<h[34]>(?:Subject|Body):?<\/h[34]>\s*)((?:<div class="callout">[\s\S]*?<\/div>\s*)+)/g,
    (match, heading, content) => `${heading}<div class="email-card">${content}</div>`
  );

  return wrapHTML('Investor Outreach Sequence', html);
}

function processDesignPartnerOutreach(md) {
  md = md.replace(/^# .+\n+/, '');
  let html = marked.parse(md);
  html = convertCallouts(html);
  return wrapHTML('Design Partner Outreach', html);
}

function processInvestorPipelineCSV(csvText) {
  const lines = csvText.trim().split('\n');
  const headers = lines[0].split(',');
  const rows = lines.slice(1).map(line => {
    const vals = [];
    let current = '';
    let inQuote = false;
    for (const ch of line) {
      if (ch === '"') { inQuote = !inQuote; }
      else if (ch === ',' && !inQuote) { vals.push(current.trim()); current = ''; }
      else { current += ch; }
    }
    vals.push(current.trim());
    return vals;
  });

  // Columns to display (skip source_link=8, notes=9, next_step=10)
  const displayCols = [0, 1, 2, 3, 4, 5, 6, 7]; // name, type, stage, geo, thesis, why, priority, status
  const prettyHeaders = ['Name', 'Type', 'Stage', 'Geography', 'Thesis Fit', 'Why They Fit', 'Priority', 'Status'];

  // Group by investor_type (col 1)
  const groups = {};
  const groupOrder = [];
  for (const row of rows) {
    const type = row[1] || 'other';
    if (!groups[type]) {
      groups[type] = [];
      groupOrder.push(type);
    }
    groups[type].push(row);
  }

  const prettyType = (t) => t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

  // Summary
  let summaryHTML = '<div class="summary"><strong>Pipeline Summary:</strong> ';
  summaryHTML += groupOrder.map(g => `${prettyType(g)} (${groups[g].length})`).join(' &bull; ');
  summaryHTML += ` &bull; <strong>Total: ${rows.length}</strong></div>`;

  // Table
  let tableHTML = '<table><thead><tr>';
  for (const ci of displayCols) {
    tableHTML += `<th>${prettyHeaders[displayCols.indexOf(ci)]}</th>`;
  }
  tableHTML += '</tr></thead><tbody>';

  for (const groupKey of groupOrder) {
    tableHTML += `<tr class="group-header"><td colspan="${displayCols.length}">${prettyType(groupKey)}</td></tr>`;
    for (const row of groups[groupKey]) {
      tableHTML += '<tr>';
      for (const ci of displayCols) {
        let val = row[ci] || '';
        if (ci === 6) val = ['', '1 — High', '2 — Medium', '3 — Later'][parseInt(val)] || val;
        if (ci === 7) val = val.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        tableHTML += `<td>${val}</td>`;
      }
      tableHTML += '</tr>';
    }
  }
  tableHTML += '</tbody></table>';

  return wrapHTML('Investor Pipeline', summaryHTML + tableHTML, landscapeCSS);
}

// ── Main ───────────────────────────────────────────────────────────────────

const FILES = [
  { src: 'investor-one-pager.md',           out: 'investor-one-pager.pdf',           process: processInvestorOnePager },
  { src: 'fundraising-package.md',          out: 'fundraising-package.pdf',          process: processFundraisingPackage },
  { src: 'pitch-deck-outline.md',           out: 'pitch-deck-outline.pdf',           process: processPitchDeckOutline },
  { src: 'positioning-and-wedge.md',        out: 'positioning-and-wedge.pdf',        process: processPositioningAndWedge },
  { src: 'investor-outreach-sequence.md',   out: 'investor-outreach-sequence.pdf',   process: processInvestorOutreachSequence },
  { src: 'design-partner-outreach.md',      out: 'design-partner-outreach.pdf',      process: processDesignPartnerOutreach },
  { src: 'investor-pipeline-template.csv',  out: 'investor-pipeline-template.pdf',   process: processInvestorPipelineCSV },
];

(async () => {
  console.log('Launching Chrome...');
  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  for (const file of FILES) {
    const srcPath = path.join(DIR, file.src);
    const outPath = path.join(DIR, file.out);
    const content = fs.readFileSync(srcPath, 'utf-8');

    console.log(`  ${file.src} -> ${file.out}`);
    const html = file.process(content);

    const page = await browser.newPage();
    await page.setContent(html, { waitUntil: 'networkidle0' });

    const isLandscape = file.src.endsWith('.csv');
    const isOnePager = file.src === 'investor-one-pager.md';
    const pdfOpts = {
      path: outPath,
      format: 'A4',
      landscape: isLandscape,
      printBackground: true,
    };
    if (isOnePager) {
      pdfOpts.margin = { top: '8mm', bottom: '8mm', left: '12mm', right: '12mm' };
    } else {
      pdfOpts.displayHeaderFooter = true;
      pdfOpts.headerTemplate = '<span></span>';
      pdfOpts.footerTemplate = `<div style="width:100%;text-align:center;font-size:7pt;color:#9ca3af;font-family:Segoe UI,sans-serif;">${titleFromFilename(file.src)}</div>`;
      pdfOpts.margin = isLandscape
        ? { top: '15mm', bottom: '18mm', left: '15mm', right: '15mm' }
        : { top: '20mm', bottom: '22mm', left: '18mm', right: '18mm' };
    }
    await page.pdf(pdfOpts);

    await page.close();
  }

  await browser.close();
  console.log('Done! Generated 7 PDFs.');
})();
