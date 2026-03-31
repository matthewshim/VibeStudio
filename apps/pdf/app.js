// ==========================================
// 📄 PDF Splitter - Full Implementation
// ==========================================

pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

let currentPdfFile = null;
let totalPages = 0;

// ── 파일 선택 → 썸네일 렌더링 ─────────────────
document.getElementById('pdfInput').addEventListener('change', async function(e) {
    const file = e.target.files[0];
    if (!file) return;
    currentPdfFile = file;

    document.getElementById('fileNameDisplay').innerText = file.name;

    const previewGrid   = document.getElementById('previewGrid');
    const previewHeader = document.getElementById('previewHeader');
    const extractBtn    = document.getElementById('extractBtn');

    previewGrid.innerHTML   = '<div style="grid-column:1/-1;padding:12px;font-size:13px;color:var(--text-muted);">⏳ 페이지 로딩 중...</div>';
    previewGrid.style.display = 'grid';
    previewHeader.style.display = 'flex';
    extractBtn.disabled = true;

    try {
        const arr = await file.arrayBuffer();
        const pdf = await pdfjsLib.getDocument({ data: arr }).promise;
        totalPages = pdf.numPages;

        previewGrid.innerHTML = '';

        for (let i = 1; i <= totalPages; i++) {
            const page    = await pdf.getPage(i);
            const viewport = page.getViewport({ scale: 0.5 });

            const canvas  = document.createElement('canvas');
            const ctx     = canvas.getContext('2d');
            canvas.width  = viewport.width;
            canvas.height = viewport.height;

            await page.render({ canvasContext: ctx, viewport }).promise;

            // 페이지 아이템 래퍼
            const item = document.createElement('div');
            item.className   = 'page-item selected'; // 기본 전체 선택
            item.dataset.page = i;

            // 체크 배지
            const badge = document.createElement('div');
            badge.className = 'page-badge';
            badge.textContent = '✓';

            // 페이지 번호
            const num = document.createElement('div');
            num.className   = 'page-num';
            num.textContent = `${i}`;

            item.appendChild(canvas);
            item.appendChild(badge);
            item.appendChild(num);

            item.addEventListener('click', function() {
                this.classList.toggle('selected');
                refreshBadge(this);
                updateExtractBtn();
            });

            previewGrid.appendChild(item);
        }

        updateExtractBtn();
    } catch (err) {
        console.error('PDF 로드 오류:', err);
        previewGrid.innerHTML = '<div style="grid-column:1/-1;padding:12px;color:var(--pdf-primary);">❌ PDF 로드에 실패했습니다.</div>';
    }
});

// ── 체크 배지 갱신 ─────────────────────────────
function refreshBadge(item) {
    const badge = item.querySelector('.page-badge');
    if (!badge) return;
    badge.style.opacity = item.classList.contains('selected') ? '1' : '0';
}

// ── 추출 버튼 활성화 여부 ─────────────────────
function updateExtractBtn() {
    const selected = document.querySelectorAll('.page-item.selected').length;
    const btn = document.getElementById('extractBtn');
    btn.disabled = selected === 0;
    btn.querySelector('span') && (btn.querySelector('span').textContent = selected > 0 ? ` (${selected}페이지)` : '');
}

// ── 전체 선택 / 해제 ──────────────────────────
function selectAllPages() {
    document.querySelectorAll('.page-item').forEach(item => {
        item.classList.add('selected');
        refreshBadge(item);
    });
    updateExtractBtn();
}

function deselectAllPages() {
    document.querySelectorAll('.page-item').forEach(item => {
        item.classList.remove('selected');
        refreshBadge(item);
    });
    updateExtractBtn();
}

// ── 선택 페이지 추출 & 다운로드 ──────────────
async function extractSelectedPages() {
    if (!currentPdfFile) return;

    const selectedItems = document.querySelectorAll('.page-item.selected');
    if (selectedItems.length === 0) {
        alert('추출할 페이지를 선택해주세요.');
        return;
    }

    // 선택된 페이지 인덱스 (0-based)
    const pageIndices = Array.from(selectedItems)
        .map(item => parseInt(item.dataset.page) - 1)
        .sort((a, b) => a - b);

    const btn = document.getElementById('extractBtn');
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader" style="width:18px;animation:spin 1s linear infinite;"></i> 처리 중...';

    try {
        const arr    = await currentPdfFile.arrayBuffer();
        const srcPdf = await PDFLib.PDFDocument.load(arr);
        const newPdf = await PDFLib.PDFDocument.create();

        const copied = await newPdf.copyPages(srcPdf, pageIndices);
        copied.forEach(p => newPdf.addPage(p));

        const bytes = await newPdf.save();
        const blob  = new Blob([bytes], { type: 'application/pdf' });
        const url   = URL.createObjectURL(blob);

        const baseName = currentPdfFile.name.replace(/\.pdf$/i, '');
        const a = document.createElement('a');
        a.href     = url;
        a.download = `${baseName}_split_p${pageIndices.map(i => i+1).join('-')}.pdf`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 3000);

        btn.innerHTML = '✅ 저장 완료!';
        btn.style.background = '#34d399';
        setTimeout(() => {
            btn.innerHTML = '<i data-lucide="download" style="width:20px;"></i> 추출하여 저장';
            btn.style.background = '';
            btn.disabled = false;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }, 2500);

    } catch (err) {
        console.error('PDF 추출 오류:', err);
        alert('PDF 추출에 실패했습니다: ' + err.message);
        btn.innerHTML = '<i data-lucide="download" style="width:20px;"></i> 추출하여 저장';
        btn.disabled = false;
    }
}
