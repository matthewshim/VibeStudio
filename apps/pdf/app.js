        // ==========================================
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        let currentPdfFile = null;
        document.getElementById('pdfInput').addEventListener('change', async e => {
            currentPdfFile = e.target.files[0]; if (!currentPdfFile) return;
            document.getElementById('fileNameDisplay').innerText = currentPdfFile.name;
            document.getElementById('previewGrid').style.display = 'grid';
            document.getElementById('previewHeader').style.display = 'flex';
            document.getElementById('extractBtn').disabled = false;
            document.getElementById('previewGrid').innerHTML = "<div style='grid-column:1/-1; padding:10px; font-size:14px; font-weight:500;'>PDF가 준비되었습니다. (테스트용 간소화)</div>";
        });
        async function extractSelectedPages() {
            if (!currentPdfFile) return;
            try {
                const arr = await currentPdfFile.arrayBuffer();
                const pdf = await PDFLib.PDFDocument.load(arr);
                const newPdf = await PDFLib.PDFDocument.create();
                const pages = await newPdf.copyPages(pdf, [0]);
                newPdf.addPage(pages[0]);
                const bytes = await newPdf.save();
                const url = URL.createObjectURL(new Blob([bytes]));
                const a = document.createElement('a'); a.href = url; a.download = 'split.pdf'; a.click();
            } catch (e) { alert("추출 실패"); }
