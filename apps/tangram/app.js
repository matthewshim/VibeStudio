        (function () {
            'use strict';

            // confetti 라이브러리 동적 로드
            let tangramConfettiLib = null;
            function loadTangramConfetti(cb) {
                if (tangramConfettiLib) { cb(); return; }
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js';
                s.onload = () => { tangramConfettiLib = true; cb(); };
                document.head.appendChild(s);
            }

            // 상태 변수
            let tgPlayerName = '', tgStartTime = 0, tgElapsedBeforeStage = 0, tgIsTimerRunning = false;
            let tgCurrentStage = 0, tgIsGameClear = false, tgIsHintVisible = false;
            let tgDraggingShape = null, tgSelectedShape = null, tgOffsetX = 0, tgOffsetY = 0;
            let tgaudioCtx = null, tgBgmInterval = null, tgIsBgmPlaying = false;
            let tgCanvas = null, tgCtx = null, tgConfettiCanvas = null, tgCustomConfetti = null;
            let tgInitialized = false;
            let tgAnimFrame = null;

            const tgStages = [
                { name: "🧩 1단계: 다이아몬드", silhouette: [{ x: 550, y: 50 }, { x: 750, y: 250 }, { x: 550, y: 450 }, { x: 350, y: 250 }], hints: [[{ x: 350, y: 250 }, { x: 750, y: 250 }], [{ x: 550, y: 50 }, { x: 550, y: 250 }], [{ x: 450, y: 350 }, { x: 650, y: 350 }], [{ x: 450, y: 250 }, { x: 450, y: 350 }], [{ x: 550, y: 250 }, { x: 550, y: 350 }], [{ x: 650, y: 250 }, { x: 550, y: 350 }]] },
                { name: "🧩 2단계: 가로 직사각형", silhouette: [{ x: 350, y: 150 }, { x: 750, y: 150 }, { x: 750, y: 350 }, { x: 350, y: 350 }], hints: [[{ x: 550, y: 150 }, { x: 550, y: 350 }], [{ x: 350, y: 350 }, { x: 550, y: 150 }], [{ x: 550, y: 250 }, { x: 650, y: 250 }], [{ x: 650, y: 250 }, { x: 650, y: 350 }], [{ x: 650, y: 250 }, { x: 550, y: 150 }], [{ x: 650, y: 250 }, { x: 750, y: 150 }], [{ x: 650, y: 350 }, { x: 750, y: 250 }]] },
                { name: "🧩 3단계: 세로 직사각형", silhouette: [{ x: 450, y: 50 }, { x: 650, y: 50 }, { x: 650, y: 450 }, { x: 450, y: 450 }], hints: [[{ x: 450, y: 250 }, { x: 650, y: 250 }], [{ x: 450, y: 50 }, { x: 650, y: 250 }], [{ x: 550, y: 250 }, { x: 550, y: 350 }], [{ x: 550, y: 350 }, { x: 650, y: 350 }], [{ x: 550, y: 350 }, { x: 450, y: 250 }], [{ x: 550, y: 350 }, { x: 450, y: 450 }], [{ x: 650, y: 350 }, { x: 550, y: 450 }]] },
                { name: "🧩 4단계: 집", silhouette: [{ x: 550, y: 50 }, { x: 750, y: 250 }, { x: 650, y: 250 }, { x: 650, y: 450 }, { x: 450, y: 450 }, { x: 450, y: 250 }, { x: 350, y: 250 }], hints: [[{ x: 550, y: 50 }, { x: 550, y: 250 }], [{ x: 450, y: 250 }, { x: 650, y: 250 }], [{ x: 550, y: 250 }, { x: 550, y: 350 }], [{ x: 550, y: 350 }, { x: 650, y: 350 }], [{ x: 550, y: 350 }, { x: 450, y: 250 }], [{ x: 550, y: 350 }, { x: 450, y: 450 }], [{ x: 650, y: 350 }, { x: 550, y: 450 }]] },
                { name: "🧩 5단계: 로켓", silhouette: [{ x: 550, y: 50 }, { x: 650, y: 150 }, { x: 650, y: 250 }, { x: 750, y: 350 }, { x: 750, y: 450 }, { x: 650, y: 350 }, { x: 600, y: 350 }, { x: 600, y: 450 }, { x: 500, y: 450 }, { x: 500, y: 350 }, { x: 450, y: 350 }, { x: 350, y: 450 }, { x: 350, y: 350 }, { x: 450, y: 250 }, { x: 450, y: 150 }], hints: [[{ x: 450, y: 150 }, { x: 650, y: 150 }], [{ x: 450, y: 150 }, { x: 650, y: 350 }], [{ x: 450, y: 250 }, { x: 450, y: 350 }], [{ x: 350, y: 350 }, { x: 450, y: 350 }], [{ x: 650, y: 250 }, { x: 650, y: 350 }], [{ x: 450, y: 350 }, { x: 650, y: 350 }]] }
            ];

            function tgShapesDefault() {
                return [
                    { id: 'red', type: 'triangle', points: [{ x: 0, y: 0 }, { x: 200, y: 0 }, { x: 0, y: 200 }], color: '#FF5252', x: 50, y: 50, angle: 0, flipX: 1 },
                    { id: 'blue', type: 'triangle', points: [{ x: 0, y: 0 }, { x: 200, y: 0 }, { x: 0, y: 200 }], color: '#448AFF', x: 200, y: 50, angle: 90, flipX: 1 },
                    { id: 'green', type: 'triangle', points: [{ x: 0, y: 0 }, { x: 141.4, y: 0 }, { x: 0, y: 141.4 }], color: '#34C759', x: 350, y: 50, angle: 45, flipX: 1 },
                    { id: 'yellow', type: 'triangle', points: [{ x: 0, y: 0 }, { x: 100, y: 0 }, { x: 0, y: 100 }], color: '#FFCC00', x: 50, y: 300, angle: 0, flipX: 1 },
                    { id: 'purple', type: 'triangle', points: [{ x: 0, y: 0 }, { x: 100, y: 0 }, { x: 0, y: 100 }], color: '#AF52DE', x: 150, y: 300, angle: -90, flipX: 1 },
                    { id: 'orange', type: 'square', points: [{ x: 0, y: 0 }, { x: 100, y: 0 }, { x: 100, y: 100 }, { x: 0, y: 100 }], color: '#FF9500', x: 250, y: 300, angle: 45, flipX: 1 },
                    { id: 'cyan', type: 'parallelogram', points: [{ x: 0, y: 0 }, { x: 100, y: 0 }, { x: 200, y: 100 }, { x: 100, y: 100 }], color: '#5AC8FA', x: 350, y: 300, angle: 0, flipX: 1 }
                ];
            }

            let tgShapes = tgShapesDefault();
            let tgTargetSilhouette = tgStages[0].silhouette;

            function tgFormatTime(ms) {
                const m = Math.floor(ms / 60000), s = Math.floor((ms % 60000) / 1000), cs = Math.floor((ms % 1000) / 10);
                return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}.${cs.toString().padStart(2, '0')}`;
            }

            function tgUpdateTimer() {
                if (!tgIsTimerRunning) return;
                const el = document.getElementById('tg-timerDisplay');
                if (el) el.innerText = tgFormatTime(tgElapsedBeforeStage + (Date.now() - tgStartTime));
                tgAnimFrame = requestAnimationFrame(tgUpdateTimer);
            }

            function tgStopTimer() {
                tgIsTimerRunning = false;
                if (tgAnimFrame) cancelAnimationFrame(tgAnimFrame);
                tgAnimFrame = null;
                return tgElapsedBeforeStage + (Date.now() - tgStartTime);
            }

            function tgSaveRanking(timeMs) {
                let ranks = JSON.parse(localStorage.getItem('tangramRanksFinal') || '[]');
                ranks.push({ name: tgPlayerName, time: timeMs, date: new Date().toLocaleDateString() });
                ranks.sort((a, b) => a.time - b.time); ranks = ranks.slice(0, 10);
                localStorage.setItem('tangramRanksFinal', JSON.stringify(ranks));
                tgRenderRanking();
            }

            function tgRenderRanking() {
                const ranks = JSON.parse(localStorage.getItem('tangramRanksFinal') || '[]');
                const tbody = document.getElementById('tg-rankBody'); if (!tbody) return;
                tbody.innerHTML = '';
                if (ranks.length === 0) { tbody.innerHTML = '<tr><td colspan="3" style="color:var(--text-muted);font-weight:normal;">아직 기록이 없습니다.</td></tr>'; return; }
                ranks.forEach((r, idx) => { tbody.innerHTML += `<tr><td>${idx + 1}</td><td>${r.name}</td><td>${tgFormatTime(r.time)}</td></tr>`; });
            }

            function tgIsDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }

            function tgDrawTarget(context, isMask) {
                if (!context || !tgTargetSilhouette || tgTargetSilhouette.length === 0) return;
                const W = tgCanvas.width, H = tgCanvas.height;
                const scale = Math.min(W / 800, H / 600);
                const offsetX = (W - 800 * scale) / 2;
                const offsetY = (H - 600 * scale) / 2;

                context.beginPath();
                context.moveTo(offsetX + tgTargetSilhouette[0].x * scale, offsetY + tgTargetSilhouette[0].y * scale);
                for (let i = 1; i < tgTargetSilhouette.length; i++) {
                    context.lineTo(offsetX + tgTargetSilhouette[i].x * scale, offsetY + tgTargetSilhouette[i].y * scale);
                }
                context.closePath();
                if (isMask) { context.fillStyle = '#000000'; }
                else {
                    context.fillStyle = tgIsDark() ? '#3a3a3c' : '#e5e5ea';
                    context.shadowColor = tgIsDark() ? 'rgba(0,0,0,0.6)' : 'rgba(0,0,0,0.1)';
                    context.shadowBlur = 12;
                }
                context.fill(); context.shadowBlur = 0;
                if (tgIsHintVisible && !isMask) {
                    const stage = tgStages[tgCurrentStage];
                    context.save();
                    context.strokeStyle = tgIsDark() ? 'rgba(255,255,255,0.4)' : 'rgba(255,255,255,0.9)';
                    context.lineWidth = 2.5; context.setLineDash([5, 5]);
                    context.beginPath();
                    stage.hints.forEach(line => {
                        context.moveTo(offsetX + line[0].x * scale, offsetY + line[0].y * scale);
                        context.lineTo(offsetX + line[1].x * scale, offsetY + line[1].y * scale);
                    });
                    context.stroke(); context.restore();
                }
            }

            function tgDraw() {
                if (!tgCanvas || !tgCtx) return;
                const W = tgCanvas.width, H = tgCanvas.height;
                const scale = Math.min(W / 800, H / 600);
                const offsetX = (W - 800 * scale) / 2;
                const offsetY = (H - 600 * scale) / 2;

                tgCtx.clearRect(0, 0, W, H);
                tgDrawTarget(tgCtx, false);

                tgShapes.forEach(shape => {
                    tgCtx.save();
                    tgCtx.translate(offsetX + shape.x * scale, offsetY + shape.y * scale);
                    tgCtx.rotate(shape.angle * Math.PI / 180);
                    tgCtx.scale(shape.flipX * scale, scale);
                    tgCtx.beginPath();
                    tgCtx.moveTo(shape.points[0].x, shape.points[0].y);
                    for (let i = 1; i < shape.points.length; i++) tgCtx.lineTo(shape.points[i].x, shape.points[i].y);
                    tgCtx.closePath();
                    tgCtx.fillStyle = shape.color; tgCtx.fill();
                    if (shape === tgSelectedShape && !tgIsGameClear) {
                        tgCtx.strokeStyle = tgIsDark() ? '#ffffff' : '#1d1d1f'; tgCtx.lineWidth = 4 / scale;
                    } else {
                        tgCtx.strokeStyle = tgIsDark() ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.15)'; tgCtx.lineWidth = 1.5 / scale;
                    }
                    tgCtx.stroke(); tgCtx.restore();
                });
                tgUpdateButtons();
            }

            function tgUpdateButtons() {
                const rl = document.getElementById('tg-btnRotLeft'), rr = document.getElementById('tg-btnRotRight'), fl = document.getElementById('tg-btnFlip');
                if (!rl) return;
                if (tgSelectedShape && !tgIsGameClear) { rl.disabled = false; rr.disabled = false; fl.disabled = tgSelectedShape.type !== 'parallelogram'; }
                else { rl.disabled = true; rr.disabled = true; fl.disabled = true; }
            }

            function tgCheckSuccess() {
                if (tgIsGameClear || !tgCanvas) return;

                // 고정 800×600 해상도로 비교
                const COMP_W = 800, COMP_H = 600;

                function drawShapeComp(ctx, sh) {
                    ctx.save();
                    ctx.translate(sh.x, sh.y);
                    ctx.rotate(sh.angle * Math.PI / 180);
                    ctx.scale(sh.flipX, 1);
                    ctx.beginPath();
                    ctx.moveTo(sh.points[0].x, sh.points[0].y);
                    for (let j = 1; j < sh.points.length; j++) ctx.lineTo(sh.points[j].x, sh.points[j].y);
                    ctx.closePath();
                    ctx.fillStyle = '#000'; ctx.fill();
                    ctx.restore();
                }

                // ── 실루엣 마스크 ──────────────────────────────────
                const silCanvas = document.createElement('canvas');
                silCanvas.width = COMP_W; silCanvas.height = COMP_H;
                const silCtx = silCanvas.getContext('2d');
                silCtx.beginPath();
                silCtx.moveTo(tgTargetSilhouette[0].x, tgTargetSilhouette[0].y);
                for (let i = 1; i < tgTargetSilhouette.length; i++)
                    silCtx.lineTo(tgTargetSilhouette[i].x, tgTargetSilhouette[i].y);
                silCtx.closePath();
                silCtx.fillStyle = '#000'; silCtx.fill();
                const silData = silCtx.getImageData(0, 0, COMP_W, COMP_H).data;

                // 실루엣 픽셀 수
                let silPx = 0;
                for (let i = 3; i < silData.length; i += 4)
                    if (silData[i] > 128) silPx++;
                if (silPx === 0) return;

                // ── 개별 도형 픽셀 데이터 수집 + Condition C ──────
                // (각 도형의 97% 이상이 실루엣 내부에 있어야 함)
                const piecePxCounts = [];  // 실제 렌더 픽셀 수
                const pieceDatas = [];     // 픽셀 배열 (overlap 계산용)
                for (const sh of tgShapes) {
                    const pc = document.createElement('canvas');
                    pc.width = COMP_W; pc.height = COMP_H;
                    const pCtx = pc.getContext('2d');
                    drawShapeComp(pCtx, sh);
                    const pd = pCtx.getImageData(0, 0, COMP_W, COMP_H).data;

                    let piecePx = 0, pieceInSilPx = 0;
                    for (let i = 3; i < pd.length; i += 4) {
                        if (pd[i] > 128) {
                            piecePx++;
                            if (silData[i] > 128) pieceInSilPx++;
                        }
                    }
                    if (piecePx === 0) return; // 도형이 화면 밖
                    // ★ 조건 C: 개별 도형 97%+ 실루엣 내부
                    if (pieceInSilPx / piecePx < 0.97) return;

                    piecePxCounts.push(piecePx);
                    pieceDatas.push(pd);
                }

                // ── 도형 합집합(union) 마스크 ─────────────────────
                const shpCanvas = document.createElement('canvas');
                shpCanvas.width = COMP_W; shpCanvas.height = COMP_H;
                const shpCtx = shpCanvas.getContext('2d');
                tgShapes.forEach(sh => drawShapeComp(shpCtx, sh));
                const shpData = shpCtx.getImageData(0, 0, COMP_W, COMP_H).data;

                let unionPx = 0;
                for (let i = 3; i < shpData.length; i += 4)
                    if (shpData[i] > 128) unionPx++;

                // ★ 조건 B: 도형 간 실제 픽셀 겹침 < 1%
                // totalIndivPx: 각 도형 렌더 픽셀의 단순 합 (겹침 전)
                const totalIndivPx = piecePxCounts.reduce((a, b) => a + b, 0);
                const overlapPx = Math.max(0, totalIndivPx - unionPx);
                if (overlapPx / silPx >= 0.01) return;

                // ★ 조건 A: XOR 오차 < 2% (실루엣 ↔ 도형 합집합)
                let errPx = 0;
                for (let i = 3; i < silData.length; i += 4) {
                    const inSil = silData[i] > 128;
                    const inShp = shpData[i] > 128;
                    if (inSil !== inShp) errPx++;
                }
                if (errPx / silPx >= 0.02) return;

                // ── 3중 강화 조건 모두 통과 → 클리어! ─────────────
                tgIsGameClear = true; tgSelectedShape = null; tgIsHintVisible = false; tgDraw();
                const finalTimeMs = tgStopTimer();
                tgPlaySfxClear();
                if (tgCustomConfetti) tgCustomConfetti({ particleCount: 150, spread: 100, origin: { y: 0.5 }, ticks: 80, gravity: 1.0, colors: ['#FF5252', '#007AFF', '#34C759', '#FFCC00', '#FF9500'] });
                const btnNext = document.getElementById('tg-btnNextStage');
                const clearTitle = document.getElementById('tg-clearTitle');
                const wittyMsg = document.getElementById('tg-wittyMessage');
                if (tgCurrentStage >= tgStages.length - 1) {
                    clearTitle.innerHTML = "🎉 ALL CLEAR";
                    wittyMsg.innerHTML = `모든 스테이지 정복!<br><span style="color:var(--tangram-primary); font-size:24px;">최종 기록: ${tgFormatTime(finalTimeMs)}</span>`;
                    btnNext.innerText = "명예의 전당 등록";
                    tgSaveRanking(finalTimeMs);
                } else {
                    clearTitle.innerHTML = "CLEAR!";
                    wittyMsg.innerText = "정확합니다! 다음 단계로 가시죠 🏃";
                    btnNext.innerText = "다음 단계로 계속";
                }
                document.getElementById('tg-clearOverlay').classList.add('show');
            }

            function tgInitAudio() { if (!tgaudioCtx) tgaudioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
            function tgPlaySfxRotate() { if (!tgaudioCtx) return; const osc = tgaudioCtx.createOscillator(), g = tgaudioCtx.createGain(); osc.frequency.setValueAtTime(600, tgaudioCtx.currentTime); osc.frequency.exponentialRampToValueAtTime(1200, tgaudioCtx.currentTime + 0.05); g.gain.setValueAtTime(0.3, tgaudioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.01, tgaudioCtx.currentTime + 0.1); osc.connect(g); g.connect(tgaudioCtx.destination); osc.start(); osc.stop(tgaudioCtx.currentTime + 0.1); }
            function tgPlaySfxFlip() { if (!tgaudioCtx) return; const osc = tgaudioCtx.createOscillator(), g = tgaudioCtx.createGain(); osc.type = 'triangle'; osc.frequency.setValueAtTime(800, tgaudioCtx.currentTime); osc.frequency.exponentialRampToValueAtTime(200, tgaudioCtx.currentTime + 0.15); g.gain.setValueAtTime(0.3, tgaudioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.01, tgaudioCtx.currentTime + 0.15); osc.connect(g); g.connect(tgaudioCtx.destination); osc.start(); osc.stop(tgaudioCtx.currentTime + 0.15); }
            function tgPlaySfxClear() { if (!tgaudioCtx) return;[440, 554, 659, 880].forEach((freq, i) => { const osc = tgaudioCtx.createOscillator(), g = tgaudioCtx.createGain(); osc.type = 'square'; osc.frequency.value = freq; g.gain.setValueAtTime(0, tgaudioCtx.currentTime + i * 0.15); g.gain.linearRampToValueAtTime(0.2, tgaudioCtx.currentTime + i * 0.15 + 0.05); g.gain.exponentialRampToValueAtTime(0.01, tgaudioCtx.currentTime + i * 0.15 + 0.4); osc.connect(g); g.connect(tgaudioCtx.destination); osc.start(tgaudioCtx.currentTime + i * 0.15); osc.stop(tgaudioCtx.currentTime + i * 0.15 + 0.5); }); }

            function tgResizeCanvas() {
                if (!tgCanvas) return;
                const wrapper = document.getElementById('tg-gameWrapper');
                if (!wrapper) return;
                
                // Ensure layout is stable
                const rect = wrapper.getBoundingClientRect();
                if (rect.width === 0 || rect.height === 0) {
                    setTimeout(tgResizeCanvas, 200);
                    return;
                }
                
                tgCanvas.width = rect.width;
                tgCanvas.height = rect.height;
                tgConfettiCanvas.width = rect.width;
                tgConfettiCanvas.height = rect.height;
                
                // Forces redraw with new dimensions
                requestAnimationFrame(tgDraw);
            }

            function tgGetMousePos(e) {
                const rect = tgCanvas.getBoundingClientRect();
                const scaleX = tgCanvas.width / rect.width, scaleY = tgCanvas.height / rect.height;
                const clientX = e.touches ? e.touches[0].clientX : e.clientX;
                const clientY = e.touches ? e.touches[0].clientY : e.clientY;
                return { x: (clientX - rect.left) * scaleX, y: (clientY - rect.top) * scaleY };
            }

            function tgHandleDown(e) {
                tgInitAudio();
                if (tgIsGameClear || !tgIsTimerRunning) return;
                e.preventDefault();
                const { x: mouseX, y: mouseY } = tgGetMousePos(e);
                const scale = Math.min(tgCanvas.width / 800, tgCanvas.height / 600);
                const offsetX = (tgCanvas.width - 800 * scale) / 2;
                const offsetY = (tgCanvas.height - 600 * scale) / 2;

                tgSelectedShape = null;
                for (let i = tgShapes.length - 1; i >= 0; i--) {
                    const shape = tgShapes[i];
                    tgCtx.save();
                    tgCtx.translate(offsetX + shape.x * scale, offsetY + shape.y * scale);
                    tgCtx.rotate(shape.angle * Math.PI / 180);
                    tgCtx.scale(shape.flipX * scale, scale);
                    tgCtx.beginPath(); tgCtx.moveTo(shape.points[0].x, shape.points[0].y);
                    for (let j = 1; j < shape.points.length; j++) tgCtx.lineTo(shape.points[j].x, shape.points[j].y);
                    tgCtx.closePath();
                    if (tgCtx.isPointInPath(mouseX, mouseY)) {
                        tgSelectedShape = shape; tgDraggingShape = shape;
                        tgOffsetX = (mouseX - offsetX) / scale - shape.x; 
                        tgOffsetY = (mouseY - offsetY) / scale - shape.y;
                        tgShapes.splice(i, 1); tgShapes.push(shape); tgCtx.restore(); break;
                    }
                    tgCtx.restore();
                }
                tgDraw();
            }

            function tgHandleMove(e) {
                if (tgDraggingShape && !tgIsGameClear) {
                    e.preventDefault();
                    const { x, y } = tgGetMousePos(e);
                    const scale = Math.min(tgCanvas.width / 800, tgCanvas.height / 600);
                    const offsetX = (tgCanvas.width - 800 * scale) / 2;
                    const offsetY = (tgCanvas.height - 600 * scale) / 2;
                    tgDraggingShape.x = (x - offsetX) / scale - tgOffsetX;
                    tgDraggingShape.y = (y - offsetY) / scale - tgOffsetY;
                    tgDraw();
                }
            }

            function tgHandleUp() {
                if (tgDraggingShape && !tgIsGameClear) {
                    tgDraggingShape.x = Math.round(tgDraggingShape.x / 10) * 10;
                    tgDraggingShape.y = Math.round(tgDraggingShape.y / 10) * 10;
                    tgDraggingShape = null; tgDraw(); setTimeout(tgCheckSuccess, 50);
                }
            }

            window.initTangramApp = function () {
                tgCanvas = document.getElementById('tangramCanvas');
                tgConfettiCanvas = document.getElementById('tangramConfettiCanvas');
                if (!tgCanvas || !tgConfettiCanvas) return;
                tgCtx = tgCanvas.getContext('2d');

                // confetti 초기화
                loadTangramConfetti(() => {
                    if (window.confetti) tgCustomConfetti = window.confetti.create(tgConfettiCanvas, { resize: false });
                });

                if (tgInitialized) { tgResizeCanvas(); return; }
                tgInitialized = true;

                // 캔버스 크기 초기 설정
                setTimeout(tgResizeCanvas, 50);
                window.addEventListener('resize', tgResizeCanvas);

                // 게임 시작 버튼
                document.getElementById('tg-btnStartGame').addEventListener('click', () => {
                    const nameInput = document.getElementById('tg-playerName').value.trim();
                    if (!nameInput) { alert('닉네임을 입력해주세요!'); return; }
                    tgPlayerName = nameInput;
                    document.getElementById('tg-startScreen').classList.remove('show');
                    document.getElementById('tg-gameToolbar').classList.add('show');
                    tgStartTime = Date.now(); tgElapsedBeforeStage = 0; tgIsTimerRunning = true;
                    if (tgAnimFrame) cancelAnimationFrame(tgAnimFrame);
                    tgUpdateTimer();
                });

                // 랭킹 보기
                document.getElementById('tg-btnShowRank').addEventListener('click', () => {
                    tgRenderRanking(); document.getElementById('tg-rankingScreen').classList.add('show');
                });

                // 랭킹 닫기
                document.getElementById('tg-btnCloseRank').addEventListener('click', () => {
                    document.getElementById('tg-rankingScreen').classList.remove('show');
                    if (!tgIsTimerRunning && tgCurrentStage === 0) {
                        document.getElementById('tg-startScreen').classList.add('show');
                        document.getElementById('tg-gameToolbar').classList.remove('show');
                    }
                });

                // 다음 스테이지
                document.getElementById('tg-btnNextStage').addEventListener('click', () => {
                    if (tgCurrentStage >= tgStages.length - 1) {
                        document.getElementById('tg-clearOverlay').classList.remove('show');
                        document.getElementById('tg-rankingScreen').classList.add('show');
                        tgCurrentStage = 0;
                        document.getElementById('tg-playerName').value = '';
                        document.getElementById('tg-timerDisplay').innerText = '00:00.00';
                        document.getElementById('tg-startScreen').classList.add('show');
                        document.getElementById('tg-gameToolbar').classList.remove('show');
                    } else {
                        tgCurrentStage++;
                        tgElapsedBeforeStage += (Date.now() - tgStartTime); tgStartTime = Date.now();
                        tgIsTimerRunning = true; if (tgAnimFrame) cancelAnimationFrame(tgAnimFrame); tgUpdateTimer();
                        document.getElementById('tg-clearOverlay').classList.remove('show');
                    }
                    tgTargetSilhouette = tgStages[tgCurrentStage].silhouette;
                    document.getElementById('tg-stageTitle').innerText = tgStages[tgCurrentStage].name;
                    const defaults = [{ x: 50, y: 50, a: 0 }, { x: 200, y: 50, a: 90 }, { x: 350, y: 50, a: 45 }, { x: 50, y: 300, a: 0 }, { x: 150, y: 300, a: -90 }, { x: 250, y: 300, a: 45 }, { x: 350, y: 300, a: 0 }];
                    tgShapes.forEach((s, i) => { s.x = defaults[i].x; s.y = defaults[i].y; s.angle = defaults[i].a; s.flipX = 1; });
                    tgIsGameClear = false; tgIsHintVisible = false;
                    const hintBtn = document.getElementById('tg-btnHint'); if (hintBtn) hintBtn.innerText = '💡 힌트 보기';
                    if (tgCustomConfetti) tgCustomConfetti.reset();
                    tgDraw();
                });

                // 힌트
                document.getElementById('tg-btnHint').addEventListener('click', () => {
                    if (tgIsGameClear) return;
                    tgIsHintVisible = !tgIsHintVisible;
                    document.getElementById('tg-btnHint').innerText = tgIsHintVisible ? '💡 힌트 끄기' : '💡 힌트 보기';
                    tgDraw();
                });

                // 회전/뒤집기
                document.getElementById('tg-btnRotLeft').addEventListener('click', () => { if (tgSelectedShape) { tgSelectedShape.angle -= 45; tgDraw(); tgPlaySfxRotate(); setTimeout(tgCheckSuccess, 50); } });
                document.getElementById('tg-btnRotRight').addEventListener('click', () => { if (tgSelectedShape) { tgSelectedShape.angle += 45; tgDraw(); tgPlaySfxRotate(); setTimeout(tgCheckSuccess, 50); } });
                document.getElementById('tg-btnFlip').addEventListener('click', () => { if (tgSelectedShape && tgSelectedShape.type === 'parallelogram') { tgSelectedShape.flipX *= -1; tgDraw(); tgPlaySfxFlip(); setTimeout(tgCheckSuccess, 50); } });

                // BGM
                document.getElementById('tg-btnBgm').addEventListener('click', () => {
                    tgInitAudio();
                    const btn = document.getElementById('tg-btnBgm');
                    if (tgIsBgmPlaying) {
                        clearInterval(tgBgmInterval); tgIsBgmPlaying = false; btn.innerText = '🎵 BGM 켜기';
                    } else {
                        tgIsBgmPlaying = true; btn.innerText = '🔇 BGM 끄기';
                        const notes = [261.63, 329.63, 392.00, 523.25, 392.00, 329.63]; let noteIdx = 0;
                        tgBgmInterval = setInterval(() => {
                            const osc = tgaudioCtx.createOscillator(), g = tgaudioCtx.createGain(); osc.type = 'sine'; osc.frequency.value = notes[noteIdx];
                            g.gain.setValueAtTime(0.1, tgaudioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.01, tgaudioCtx.currentTime + 0.2);
                            osc.connect(g); g.connect(tgaudioCtx.destination); osc.start(); osc.stop(tgaudioCtx.currentTime + 0.2);
                            noteIdx = (noteIdx + 1) % notes.length;
                        }, 200);
                    }
                });

                // 마우스/터치 이벤트
                tgCanvas.addEventListener('mousedown', tgHandleDown);
                tgCanvas.addEventListener('mousemove', tgHandleMove);
                tgCanvas.addEventListener('touchstart', tgHandleDown, { passive: false });
                tgCanvas.addEventListener('touchmove', tgHandleMove, { passive: false });
                window.addEventListener('mouseup', tgHandleUp);
                window.addEventListener('touchend', tgHandleUp);

                // 초기 도형 초기화
                tgShapes = tgShapesDefault();
                tgTargetSilhouette = tgStages[0].silhouette;
                tgCurrentStage = 0;
                tgDraw();

                // Cleanup registration
                window.appCleanups['tangram'] = () => {
                    tgStopTimer();
                    if (tgBgmInterval) {
                        clearInterval(tgBgmInterval);
                        tgBgmInterval = null;
                        tgIsBgmPlaying = false;
                    }
                    if (tgaudioCtx) {
                        tgaudioCtx.close();
                        tgaudioCtx = null;
                    }
                    tgInitialized = false;
                    window.removeEventListener('resize', tgResizeCanvas);
                    window.removeEventListener('mouseup', tgHandleUp);
                    window.removeEventListener('touchend', tgHandleUp);
                };
            };
        })();
