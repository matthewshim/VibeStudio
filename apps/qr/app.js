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
                            resultDiv.innerHTML = `
                                <div style="color:var(--server-primary); margin-bottom:10px;">✅ 해독 성공!</div>
                                <div style="background:var(--input-bg); padding:12px; border-radius:8px; border:1px solid var(--qr-primary); word-break:break-all; text-align:left; font-family:monospace; font-size:14px;">
                                    ${code.data.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")}
                                </div>
                                <button class="mac-btn" style="margin-top:10px; padding:8px; font-size:12px;" onclick="copyQrResult()">📋 복사하기</button>
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
    if (window._lastQrResult) {
        navigator.clipboard.writeText(window._lastQrResult);
        alert("복사되었습니다!");
    }
};
