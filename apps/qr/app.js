// ==========================================
// 📱 QR 마스터
// ==========================================

window.switchQrTab = function(event, tab) {
    document.querySelectorAll('.qr-tab-content').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('.qr-tab-btn').forEach(b => b.classList.remove('active'));
    
    if (event && event.currentTarget) {
        event.currentTarget.classList.add('active');
    }
    
    const target = document.getElementById('tab-' + tab);
    if (target) target.classList.add('active');
};

window.generateQR = function() {
    const text = document.getElementById("qrTextInput").value;
    const container = document.getElementById("qrcode");
    if (!container) return;
    
    container.innerHTML = "";
    if (!text) {
        alert("텍스트를 입력해주세요.");
        return;
    }
    
    try {
        new QRCode(container, {
            text: text,
            width: 160,
            height: 160,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
    } catch (e) {
        console.error("QR Generation failed:", e);
    }
};

let qrInitialized = false;
window.initQrScanner = function() {
    if (qrInitialized) return;
    qrInitialized = true;
    
    const fileInput = document.getElementById('actualFileInput');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            const resultDiv = document.getElementById('scanResult');
            resultDiv.innerHTML = '<div style="color:var(--text-muted);">⏳ 분석 중...</div>';
            
            const reader = new FileReader();
            reader.onload = function(event) {
                const img = new Image();
                img.onload = function() {
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    
                    // Too large images can cause issues, resize if needed but for scanning original is usually better
                    canvas.width = img.width;
                    canvas.height = img.height;
                    context.drawImage(img, 0, 0);
                    
                    try {
                        const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                        const code = jsQR(imageData.data, imageData.width, imageData.height, {
                            inversionAttempts: "dontInvert",
                        });
                        
                        if (code) {
                            const isUrl = /^https?:\/\//i.test(code.data);
                            const safeData = code.data.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                            const linkHtml = isUrl
                                ? `<a href="${safeData}" target="_blank" rel="noopener" style="display:block;margin-top:8px;font-size:12px;color:var(--qr-primary);text-decoration:none;opacity:.8;">🔗 링크 열기 →</a>`
                                : '';
                            resultDiv.innerHTML = `
                                <div style="
                                    background: linear-gradient(135deg, rgba(0,200,100,.12), rgba(0,122,255,.08));
                                    border: 1px solid rgba(0,200,100,.3);
                                    border-radius: 14px;
                                    padding: 16px;
                                    text-align: left;
                                ">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                                        <span style="font-size:20px;">🎯</span>
                                        <span style="font-weight:700;font-size:14px;color:#34d399;">해독 완료!</span>
                                        <span style="font-size:11px;color:var(--text-muted);margin-left:auto;">QR 코드 분석됨</span>
                                    </div>
                                    <div style="
                                        background: var(--input-bg);
                                        border: 1px solid rgba(255,255,255,.08);
                                        border-radius: 10px;
                                        padding: 12px 14px;
                                        font-family: monospace;
                                        font-size: 13px;
                                        word-break: break-all;
                                        line-height: 1.6;
                                        color: var(--text-main);
                                    ">${safeData}</div>
                                    ${linkHtml}
                                    <button id="qrCopyBtn" onclick="copyQrResult()" style="
                                        display: flex;
                                        align-items: center;
                                        justify-content: center;
                                        gap: 6px;
                                        width: 100%;
                                        margin-top: 12px;
                                        padding: 10px;
                                        border: none;
                                        border-radius: 10px;
                                        background: var(--qr-primary, #007aff);
                                        color: #ffffff;
                                        font-size: 13px;
                                        font-weight: 700;
                                        cursor: pointer;
                                        transition: opacity .2s;
                                    " onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">📋 복사하기</button>
                                </div>
                            `;
                            window._lastQrResult = code.data;
                        } else {
                            resultDiv.innerHTML = `<div style="color:var(--pdf-primary);">❌ QR코드를 찾을 수 없습니다.<br><span style="font-size:12px; font-weight:normal;">이미지가 선명한지 확인해주세요.</span></div>`;
                        }
                    } catch (err) {
                        console.error("QR Scan error:", err);
                        resultDiv.innerHTML = `<div style="color:var(--pdf-primary);">⚠️ 처리 중 오류가 발생했습니다.</div>`;
                    }
                };
                img.src = event.target.result;
            };
            reader.readAsDataURL(file);
        });
    }
};

window.copyQrResult = function() {
    if (!window._lastQrResult) return;
    navigator.clipboard.writeText(window._lastQrResult).then(() => {
        const btn = document.getElementById('qrCopyBtn');
        if (btn) {
            btn.textContent = '✅ 복사됐어요!';
            btn.style.background = '#34d399';
            setTimeout(() => {
                btn.innerHTML = '📋 복사하기';
                btn.style.background = 'var(--qr-primary, #007aff)';
            }, 2000);
        }
    }).catch(() => {
        const btn = document.getElementById('qrCopyBtn');
        if (btn) btn.textContent = '⚠️ 복사 실패';
    });
};
