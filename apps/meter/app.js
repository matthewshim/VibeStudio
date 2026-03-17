// ==========================================
// 👏 박수 측정기
// ==========================================
(function() {
    let audioCtx, analyser, microphone, animationId;
    let isRecording = false, maxScore = 0;
    
    const canvas = document.getElementById('meterCanvas');
    if (!canvas) return;
    const canvasCtx = canvas.getContext('2d');
    const startBtn = document.getElementById('startMeterBtn');
    const statusText = document.getElementById('meterStatus');
    const scoreDisplay = document.getElementById('currentScoreDisplay');
    const nameInput = document.getElementById('participantName');

    window.resizeMeterCanvas = function() {
        if (!canvas || !canvas.parentElement) return;
        canvas.width = canvas.parentElement.clientWidth;
        canvas.height = canvas.parentElement.clientHeight;
        if (!isRecording) {
            canvasCtx.clearRect(0, 0, canvas.width, canvas.height);
            canvasCtx.beginPath(); 
            canvasCtx.moveTo(0, canvas.height / 2); 
            canvasCtx.lineTo(canvas.width, canvas.height / 2);
            canvasCtx.strokeStyle = 'rgba(128, 128, 128, 0.3)'; 
            canvasCtx.stroke();
        }
    };

    window.startMeasurement = async function() {
        const participantName = nameInput.value.trim();
        if (!participantName) { 
            alert("도전자 이름을 입력해주세요!"); 
            nameInput.focus(); 
            return; 
        }
        
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            analyser = audioCtx.createAnalyser(); 
            microphone = audioCtx.createMediaStreamSource(stream);
            microphone.connect(analyser); 
            analyser.fftSize = 256;
            
            const bufferLength = analyser.frequencyBinCount; 
            const dataArray = new Uint8Array(bufferLength);

            isRecording = true; 
            maxScore = 0; 
            startBtn.disabled = true;
            startBtn.innerHTML = `<i data-lucide="mic" class="lucide-pulse" style="width:20px;"></i> 측정 중...`;
            statusText.innerText = `${participantName} 님 측정 중...`;
            statusText.style.color = 'var(--meter-primary)';
            nameInput.disabled = true; 
            if (window.lucide) window.lucide.createIcons();

            function drawWaveform() {
                if (!isRecording) return;
                animationId = requestAnimationFrame(drawWaveform);
                analyser.getByteFrequencyData(dataArray);

                let sumSquares = 0; 
                for (let i = 0; i < bufferLength; i++) sumSquares += dataArray[i] * dataArray[i];
                let currentVolume = Math.min(100, Math.round((Math.sqrt(sumSquares / bufferLength) / 120) * 100));
                
                if (currentVolume > maxScore) maxScore = currentVolume;
                scoreDisplay.innerText = maxScore;

                canvasCtx.clearRect(0, 0, canvas.width, canvas.height);
                const barWidth = (canvas.width / bufferLength) * 2; 
                const centerY = canvas.height / 2; 
                let x = 0;

                for (let i = 0; i < bufferLength; i++) {
                    let barHeight = (dataArray[i] / 255) * (canvas.height / 2) * 0.9;
                    if (barHeight < 2) barHeight = 2;
                    let r = 255, g = Math.max(0, 200 - (currentVolume * 2)), b = 50;
                    let color = `rgb(${r}, ${g}, ${b})`;

                    canvasCtx.shadowBlur = 10; 
                    canvasCtx.shadowColor = color; 
                    canvasCtx.fillStyle = color;
                    canvasCtx.fillRect(x, centerY - barHeight, barWidth - 2, barHeight * 2);
                    x += barWidth;
                }
                canvasCtx.shadowBlur = 0;
            }
            
            drawWaveform();
            
            // Stop after 5 seconds
            setTimeout(() => {
                isRecording = false;
                if (animationId) cancelAnimationFrame(animationId);
                if (stream) stream.getTracks().forEach(track => track.stop());
                
                startBtn.disabled = false;
                startBtn.innerHTML = `<i data-lucide="play-circle" style="width: 20px;"></i> 다시 측정`;
                statusText.innerText = "측정 완료!";
                nameInput.disabled = false;
                if (window.lucide) window.lucide.createIcons();
                
                // Save record logic could go here
            }, 5000);

        } catch (err) {
            console.error("Mic access denied:", err);
            alert("마이크 권한이 필요합니다.");
            startBtn.disabled = false;
            startBtn.innerHTML = `<i data-lucide="play-circle" style="width: 20px;"></i> 측정 시작`;
        }
    };

    // Cleanup registration
    window.appCleanups = window.appCleanups || {};
    window.appCleanups['meter'] = () => {
        isRecording = false;
        if (animationId) cancelAnimationFrame(animationId);
        if (audioCtx) {
            audioCtx.close().catch(()=>{});
            audioCtx = null;
        }
    };

    setTimeout(window.resizeMeterCanvas, 100);
})();
