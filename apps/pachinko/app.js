        // 🎰 Pachinko Game Logic - Ultimate Touch Support
        // ==========================================
        (function () {
            let rankings = [];
            try {
                rankings = JSON.parse(localStorage.getItem('pachinkoRankings')) || [];
            } catch (e) { rankings = []; }

            let gameState = 'ready';
            let currentNickname = '';
            const MAX_BALLS = 3;
            let score = 0;
            let ballCount = 3;

            let bestScore = 0;
            if (rankings.length > 0) {
                bestScore = Math.max(...rankings.map(r => r.score));
            }
            document.getElementById('best-score').innerText = bestScore;

            let timeLeft = 60;
            let timerInterval = null;

            const nicknameInput = document.getElementById('nickname');
            const startBtn = document.getElementById('start-btn');
            const rankBtn = document.getElementById('rank-btn');
            const readyOverlay = document.getElementById('pachinko-ready-overlay');
            const rankingModal = document.getElementById('ranking-modal');
            const modalOverlay = document.getElementById('modal-overlay');
            const rankListUI = document.getElementById('rank-list');
            const closeRankBtn = document.getElementById('close-rank-btn');

            // Mobile Controls - Removed as requested in favor of touch/drag

            startBtn.addEventListener('click', () => {
                const name = nicknameInput.value.trim();
                if (!name) {
                    alert("🤔 잠깐! 이름 없는 영웅은 명예의 전당에 오를 수 없습니다.\n닉네임을 먼저 입력하고 당당하게 플레이해 주세요!");
                    nicknameInput.focus();
                    return;
                }

                currentNickname = name;
                gameState = 'playing';
                score = 0;
                ballCount = MAX_BALLS;
                timeLeft = 60;
                balls.length = 0;
                invader.width = 80;

                document.getElementById('score').innerText = score;
                document.getElementById('balls').innerText = ballCount;

                readyOverlay.classList.remove('active');

                if (timerInterval) clearInterval(timerInterval);
                timerInterval = setInterval(() => {
                    if (gameState === 'playing' && balls.length > 0) {
                        timeLeft--;
                        if (timeLeft <= 0) {
                            balls.length = 0;
                            if (ballCount === 0) {
                                checkGameOver(false);
                            } else {
                                timeLeft = 60;
                                invader.width = 80;
                            }
                        }
                    }
                }, 1000);
            });

            function showRanking() {
                rankListUI.innerHTML = '';
                rankings.sort((a, b) => b.score - a.score);

                const topScores = rankings.slice(0, 5); 
                if (topScores.length === 0) {
                    rankListUI.innerHTML = '<li class="modal-rank-item" style="justify-content:center; color:#8e8e93;">아직 기록이 없습니다.</li>';
                } else {
                    const medals = ['🥇', '🥈', '🥉', '🏅', '🏅'];
                    topScores.forEach((rank, index) => {
                        const li = document.createElement('li');
                        li.className = 'modal-rank-item';
                        li.innerHTML = `<span><span class="rank-medal">${medals[index] || ''}</span>${rank.name}</span> <span style="color:#ff2d55">${rank.score}</span>`;
                        rankListUI.appendChild(li);
                    });
                }

                rankingModal.style.display = 'block';
                modalOverlay.style.display = 'block';
            }

            rankBtn.addEventListener('click', showRanking);
            closeRankBtn.addEventListener('click', () => {
                rankingModal.style.display = 'none';
                modalOverlay.style.display = 'none';
            });
            modalOverlay.addEventListener('click', () => {
                rankingModal.style.display = 'none';
                modalOverlay.style.display = 'none';
            });

            function checkGameOver(force = false) {
                if (gameState === 'playing' && ((ballCount === 0 && balls.length === 0) || force)) {
                    gameState = 'gameover';
                    clearInterval(timerInterval);
                    balls.length = 0;

                    rankings.push({ name: currentNickname, score: score });
                    try { localStorage.setItem('pachinkoRankings', JSON.stringify(rankings)); } catch (e) { }

                    bestScore = Math.max(...rankings.map(r => r.score));
                    document.getElementById('best-score').innerText = bestScore;

                    setTimeout(() => {
                        readyOverlay.classList.add('active');
                        startBtn.innerText = "다시 도전하기 🚀";
                        showRanking();
                    }, 800);
                }
            }

            const canvas = document.getElementById('gameCanvas');
            const ctx = canvas.getContext('2d');

            const launcher = { x: canvas.width / 2, y: canvas.height - 85 };
            const balls = [];
            const fixedPins = [];
            const particles = [];
            const floatingScores = [];
            const gravity = 0.25;
            const bounce = 0.55;

            const invader = {
                x: canvas.width / 2 - 40, y: canvas.height - 120,
                width: 80, height: 16, speed: 7, alpha: 0
            };

            const keys = { left: false, right: false };
            
            // Interaction State
            let isCharging = false;
            let power = 0;
            let mouseX = canvas.width / 2;
            let mouseY = canvas.height / 2;

            const setInput = (key, value) => {
                if (key === 'left') keys.left = value;
                if (key === 'right') keys.right = value;
            };

            // Helpers for relative coordinate mapping
            function updateMousePos(clientX, clientY) {
                const rect = canvas.getBoundingClientRect();
                const scaleX = canvas.width / rect.width;
                const scaleY = canvas.height / rect.height;
                mouseX = (clientX - rect.left) * scaleX;
                mouseY = (clientY - rect.top) * scaleY;
            }

            // Keyboard
            window.addEventListener('keydown', (e) => {
                if (document.getElementById('app-pachinko').classList.contains('active')) {
                    if (e.key === 'ArrowLeft') { setInput('left', true); e.preventDefault(); }
                    if (e.key === 'ArrowRight') { setInput('right', true); e.preventDefault(); }
                }
            });
            window.addEventListener('keyup', (e) => {
                if (e.key === 'ArrowLeft') setInput('left', false);
                if (e.key === 'ArrowRight') setInput('right', false);
            });

            // Mobile Pad Buttons - Removed as requested

            // --- Enhanced Touch/Drag Firing Logic ---
            canvas.addEventListener('touchstart', (e) => {
                if (gameState !== 'playing') return;
                const touch = e.touches[0];
                updateMousePos(touch.clientX, touch.clientY);

                if (ballCount > 0 && balls.length === 0) {
                    isCharging = true; // Start charging on touch
                }
                e.preventDefault();
            }, { passive: false });

            canvas.addEventListener('touchmove', (e) => {
                if (gameState !== 'playing') return;
                const touch = e.touches[0];
                updateMousePos(touch.clientX, touch.clientY);

                if (isCharging) {
                    // Just updating direction via updateMousePos
                } else if (balls.length > 0) {
                    // If ball is in flight, touch acts as invader control (catching mode)
                    invader.x = mouseX - invader.width / 2;
                }
                e.preventDefault();
            }, { passive: false });

            canvas.addEventListener('touchend', (e) => {
                if (isCharging) {
                    fireBall();
                    isCharging = false;
                    power = 0;
                }
                e.preventDefault();
            }, { passive: false });

            // --- Mouse Support for PC ---
            canvas.addEventListener('mousemove', (e) => {
                updateMousePos(e.clientX, e.clientY);
            });

            canvas.addEventListener('mousedown', () => {
                if (gameState === 'playing' && ballCount > 0 && balls.length === 0) {
                    isCharging = true;
                }
            });

            window.addEventListener('mouseup', () => {
                if (isCharging) {
                    if (gameState === 'playing') fireBall();
                    isCharging = false;
                    power = 0;
                }
            });

            const pocketWidth = 80;
            const pockets = [
                { x: 10, w: pocketWidth, score: 10, border: '#c7c7cc', bg: 'rgba(199, 199, 204, 0.1)', textColor: '#8e8e93' },
                { x: 96, w: pocketWidth, score: 50, border: '#007aff', bg: 'rgba(0, 122, 255, 0.1)', textColor: '#007aff' },
                { x: 182, w: 76, score: 200, border: '#ff2d55', bg: 'rgba(255, 45, 85, 0.1)', textColor: '#ff2d55' },
                { x: 264, w: pocketWidth, score: 50, border: '#007aff', bg: 'rgba(0, 122, 255, 0.1)', textColor: '#007aff' },
                { x: 350, w: pocketWidth, score: 10, border: '#c7c7cc', bg: 'rgba(199, 199, 204, 0.1)', textColor: '#8e8e93' }
            ];

            const poringImg = new Image();
            let isImageLoaded = false;
            poringImg.onload = () => { isImageLoaded = true; };
            poringImg.src = 'image_947061.png';

            function initPins() {
                fixedPins.length = 0;
                const addPin = (x, y, r, hitScore, color, hasGlow = false) => {
                    fixedPins.push({ x, y, r, hitScore, color, hasGlow });
                };
                addPin(220, 215, 10, 10, '#ff2d55', true);
                addPin(180, 225, 6, 5, '#ff9500', false); addPin(145, 245, 6, 5, '#ff9500', false);
                addPin(115, 275, 6, 5, '#ff9500', false); addPin(95, 315, 6, 5, '#ff9500', false);
                addPin(110, 355, 6, 5, '#ff9500', false); addPin(145, 380, 6, 5, '#ff9500', false);
                addPin(185, 390, 6, 5, '#ff9500', false); addPin(260, 225, 6, 5, '#ff9500', false);
                addPin(295, 245, 6, 5, '#ff9500', false); addPin(325, 275, 6, 5, '#ff9500', false);
                addPin(345, 315, 6, 5, '#ff9500', false); addPin(330, 355, 6, 5, '#ff9500', false);
                addPin(295, 380, 6, 5, '#ff9500', false); addPin(255, 390, 6, 5, '#ff9500', false);
                addPin(220, 395, 6, 5, '#ff9500', false);
                addPin(155, 290, 18, 20, '#5856d6', true); addPin(285, 290, 18, 20, '#5856d6', true);
                for (let row = 0; row < 4; row++) {
                    let cols = (row % 2 === 0) ? 5 : 4;
                    let startX = (row % 2 === 0) ? 60 : 100;
                    for (let col = 0; col < cols; col++) {
                        addPin(startX + (col * 80), 60 + (row * 45), 5, 2, '#5ac8fa', false);
                    }
                }
                const guideColor = '#d1d1d6';
                addPin(0, 180, 25, 1, guideColor, false); addPin(0, 320, 25, 1, guideColor, false); addPin(0, 480, 45, 1, guideColor, false);
                addPin(440, 180, 25, 1, guideColor, false); addPin(440, 320, 25, 1, guideColor, false); addPin(440, 480, 45, 1, guideColor, false);
            }
            initPins();

            function fireBall() {
                if (ballCount <= 0) return;
                ballCount--;
                document.getElementById('balls').innerText = ballCount;
                
                let targetX = mouseX;
                let targetY = mouseY;
                // Avoid firing downwards
                if (mouseY > launcher.y - 30) { 
                    targetX = launcher.x;
                    targetY = launcher.y - 300;
                }

                const dx = targetX - launcher.x;
                const dy = targetY - launcher.y;
                const angle = Math.atan2(dy, dx);
                const speed = 8 + (power / 6);
                balls.push({ x: launcher.x, y: launcher.y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed, r: 11 });
            }

            function createParticles(x, y, color, amount = 12) {
                for (let i = 0; i < amount; i++) particles.push({ x, y, vx: (Math.random() - 0.5) * 8, vy: (Math.random() - 0.5) * 8, life: 1.0, color });
            }

            function createFloatingScore(x, y, amount, color) {
                floatingScores.push({ x, y, amount, color, life: 1.0, vy: -1.5 });
            }

            function roundRect(ctx, x, y, width, height, radius) {
                ctx.beginPath();
                ctx.moveTo(x + radius, y); ctx.lineTo(x + width - radius, y);
                ctx.quadraticCurveTo(x + width, y, x + width, y + radius); ctx.lineTo(x + width, y + height - radius);
                ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height); ctx.lineTo(x + radius, y + height);
                ctx.quadraticCurveTo(x, y + height, x, y + height - radius); ctx.lineTo(x, y + radius);
                ctx.quadraticCurveTo(x, y, x + radius, y); ctx.closePath();
            }

            function updateScore(amount) {
                score += amount;
                const scoreEl = document.getElementById('score');
                if (scoreEl) {
                    scoreEl.innerText = score;
                    scoreEl.style.transform = 'scale(1.2)';
                    setTimeout(() => { scoreEl.style.transform = 'scale(1)'; }, 100);
                }
                if (score > bestScore) {
                    bestScore = score;
                    document.getElementById('best-score').innerText = bestScore;
                }
            }

            function render() {
                if (!document.getElementById('app-pachinko').classList.contains('active')) {
                    pachinkoAnimFrame = requestAnimationFrame(render);
                    return;
                }

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                if (isCharging) power = Math.min(power + 2, 100);

                if (balls.length > 0) invader.alpha = Math.min(invader.alpha + 0.1, 1);
                else invader.alpha = Math.max(invader.alpha - 0.1, 0);

                if (keys.left) invader.x -= invader.speed;
                if (keys.right) invader.x += invader.speed;
                if (invader.x < 0) invader.x = 0;
                if (invader.x + invader.width > canvas.width) invader.x = canvas.width - invader.width;

                if (gameState === 'playing') {
                    ctx.fillStyle = timeLeft <= 10 ? "#ff2d55" : (document.documentElement.getAttribute('data-theme') === 'dark' ? "rgba(255,255,255,0.7)" : "rgba(0,0,0,0.5)");
                    ctx.font = "bold 18px -apple-system, sans-serif";
                    ctx.textAlign = "center";
                    ctx.fillText(`⏱ ${timeLeft}s`, canvas.width / 2, 30);
                }

                if (isImageLoaded) {
                    ctx.globalAlpha = 0.4;
                    ctx.drawImage(poringImg, 70, 200, 300, 220 * 0.8);
                    ctx.globalAlpha = 1.0;
                }

                // Launcher
                ctx.fillStyle = "#c7c7cc";
                ctx.beginPath(); ctx.arc(launcher.x, launcher.y, 18, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = "#ff2d55";
                ctx.beginPath(); ctx.arc(launcher.x, launcher.y, 7, 0, Math.PI * 2); ctx.fill();

                if (balls.length === 0 && gameState === 'playing' && ballCount > 0) {
                    let tx = mouseX, ty = mouseY;
                    if (mouseY > launcher.y - 30) { tx = launcher.x; ty = launcher.y - 300; }
                    
                    ctx.strokeStyle = "rgba(255, 45, 85, 0.4)"; ctx.setLineDash([5, 5]); ctx.lineWidth = 2;
                    ctx.beginPath(); ctx.moveTo(launcher.x, launcher.y); ctx.lineTo(tx, ty); ctx.stroke();
                    ctx.setLineDash([]);
                    
                    if (isCharging) {
                        ctx.beginPath(); ctx.arc(tx, ty, 25, -Math.PI / 2, -Math.PI / 2 + (Math.PI * 2 * (power / 100)));
                        ctx.strokeStyle = "#ff2d55"; ctx.lineWidth = 5; ctx.lineCap = "round"; ctx.stroke();
                    }
                }

                pockets.forEach(p => {
                    ctx.fillStyle = p.bg; roundRect(ctx, p.x, canvas.height - 45, p.w, 35, 12); ctx.fill();
                    ctx.strokeStyle = p.border; ctx.lineWidth = 2; ctx.stroke();
                    ctx.fillStyle = p.textColor; ctx.font = "bold 16px -apple-system, sans-serif"; ctx.textAlign = "center";
                    ctx.fillText(p.score, p.x + p.w / 2, canvas.height - 21);
                });

                fixedPins.forEach(p => {
                    ctx.fillStyle = p.color;
                    ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2); ctx.fill();
                });

                if (invader.alpha > 0) {
                    ctx.globalAlpha = invader.alpha;
                    ctx.fillStyle = "#ff2d55";
                    roundRect(ctx, invader.x, invader.y, invader.width, invader.height, 8); ctx.fill();
                    ctx.globalAlpha = 1.0;
                }

                for (let i = balls.length - 1; i >= 0; i--) {
                    let b = balls[i];
                    b.vy += gravity; b.x += b.vx; b.y += b.vy;
                    if (b.x < b.r || b.x > canvas.width - b.r) { b.vx *= -bounce; b.x = b.x < b.r ? b.r : canvas.width - b.r; }
                    if (b.y < b.r) { b.vy *= -bounce; b.y = b.r; }

                    if (invader.alpha > 0.5 && b.vy > 0) {
                        if (b.x > invader.x && b.x < invader.x + invader.width && b.y + b.r > invader.y && b.y < invader.y + invader.height) {
                            b.vy = -Math.abs(b.vy) * 0.8 - 6;
                            b.vx = ((b.x - (invader.x + invader.width / 2)) / (invader.width / 2)) * 7;
                            b.y = invader.y - b.r;
                            if (invader.width > 24) { invader.width -= 8; invader.x += 4; }
                        }
                    }

                    fixedPins.forEach(p => {
                        let dx = b.x - p.x, dy = b.y - p.y;
                        let dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < b.r + p.r) {
                            let angle = Math.atan2(dy, dx);
                            let speed = Math.sqrt(b.vx ** 2 + b.vy ** 2);
                            b.vx = Math.cos(angle) * (speed + 0.6) * bounce;
                            b.vy = Math.sin(angle) * (speed + 0.6) * bounce;
                            b.x = p.x + Math.cos(angle) * (b.r + p.r + 0.5);
                            b.y = p.y + Math.sin(angle) * (b.r + p.r + 0.5);
                            updateScore(p.hitScore);
                            createFloatingScore(p.x, p.y - 10, p.hitScore, p.color);
                        }
                    });

                    if (b.y > canvas.height - 50) {
                        let hit = pockets.find(p => b.x >= p.x && b.x <= p.x + p.w);
                        if (hit) { updateScore(hit.score); createFloatingScore(b.x, b.y - 20, hit.score, hit.textColor); }
                        balls.splice(i, 1);
                        if (gameState === 'playing') { timeLeft = 60; invader.width = 80; }
                    } else {
                        ctx.fillStyle = "#ff2d55";
                        ctx.beginPath(); ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2); ctx.fill();
                    }
                }

                for (let i = floatingScores.length - 1; i >= 0; i--) {
                    let fs = floatingScores[i]; fs.y += fs.vy; fs.life -= 0.025;
                    if (fs.life <= 0) { floatingScores.splice(i, 1); continue; }
                    ctx.globalAlpha = fs.life; ctx.fillStyle = fs.color;
                    ctx.font = "bold 16px -apple-system, sans-serif";
                    ctx.fillText(`+${fs.amount}`, fs.x, fs.y);
                }
                ctx.globalAlpha = 1.0;

                checkGameOver();
                pachinkoAnimFrame = requestAnimationFrame(render);
            }
            let pachinkoAnimFrame = requestAnimationFrame(render);

            window.appCleanups['pachinko'] = () => {
                cancelAnimationFrame(pachinkoAnimFrame);
                if (timerInterval) clearInterval(timerInterval);
            };
        })();
