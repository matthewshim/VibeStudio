        // --- Vibe Canvas Logic (from ex9.html) ---
        (function () {
            const canvas = document.getElementById('vibe-canvas');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');

            const themes = {
                dark: {
                    node: '139, 92, 246',
                    nodeOpacity: 0.3,
                    highlight: '255, 255, 255',
                    active: '192, 132, 252'
                },
                light: {
                    node: '109, 40, 217',
                    nodeOpacity: 0.4,
                    highlight: '139, 92, 246',
                    active: '124, 58, 237'
                }
            };

            let currentColors = document.documentElement.getAttribute('data-theme') === 'dark' ? themes.dark : themes.light;

            window.updateCanvasTheme = function (theme) {
                currentColors = themes[theme];
            };

            let nodes = [];
            let nodeCount = 0;

            function resizeCanvas() {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;

                // 모션 경량화: 노드 개수를 기존 대비 약 절반으로 축소 (CPU 부하 대폭 감소)
                if (window.innerWidth < 768) nodeCount = 40;
                else if (window.innerWidth < 1024) nodeCount = 70;
                else nodeCount = 110;

                initNodes();
            }

            window.addEventListener('resize', resizeCanvas);

            let targetMouse = { x: -1000, y: -1000 };
            let mouse = { x: -1000, y: -1000 };

            window.addEventListener('mousemove', (e) => {
                targetMouse.x = e.clientX;
                targetMouse.y = e.clientY;
            });

            window.addEventListener('mouseout', () => {
                targetMouse.x = -1000;
                targetMouse.y = -1000;
            });

            class Node {
                constructor() {
                    this.x = Math.random() * canvas.width;
                    this.y = Math.random() * canvas.height;
                    this.vx = (Math.random() - 0.5) * 0.8;
                    this.vy = (Math.random() - 0.5) * 0.8;
                    this.baseVx = this.vx;
                    this.baseVy = this.vy;
                    this.radius = Math.random() * 1.5 + 1.2;
                }

                update() {
                    let dx = this.x - mouse.x;
                    let dy = this.y - mouse.y;
                    let distance = Math.sqrt(dx * dx + dy * dy);
                    let interactionRadius = 220;

                    if (distance < interactionRadius) {
                        let force = (interactionRadius - distance) / interactionRadius;
                        let pushX = (dx / distance) * force * 4;
                        let pushY = (dy / distance) * force * 4;
                        this.vx += pushX * 0.1;
                        this.vy += pushY * 0.1;
                    } else {
                        this.vx += (this.baseVx - this.vx) * 0.05;
                        this.vy += (this.baseVy - this.vy) * 0.05;
                    }

                    let speed = Math.sqrt(this.vx * this.vx + this.vy * this.vy);
                    if (speed > 4) {
                        this.vx = (this.vx / speed) * 4;
                        this.vy = (this.vy / speed) * 4;
                    }

                    this.x += this.vx;
                    this.y += this.vy;

                    if (this.x < 0) this.x = canvas.width;
                    if (this.x > canvas.width) this.x = 0;
                    if (this.y < 0) this.y = canvas.height;
                    if (this.y > canvas.height) this.y = 0;
                }

                draw() {
                    ctx.beginPath();
                    ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(${currentColors.node}, ${currentColors.nodeOpacity})`;
                    ctx.fill();
                }
            }

            function initNodes() {
                nodes = [];
                for (let i = 0; i < nodeCount; i++) {
                    nodes.push(new Node());
                }
            }

            let activationLevel = 0;
            function activateNetwork() {
                activationLevel = 1.0;
            }

            function drawNetwork() {
                mouse.x += (targetMouse.x - mouse.x) * 0.15;
                mouse.y += (targetMouse.y - mouse.y) * 0.15;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                if (activationLevel > 0) {
                    activationLevel -= 0.012;
                }
                if (activationLevel < 0) activationLevel = 0;

                nodes.forEach(node => {
                    node.update();
                    node.draw();
                });

                const baseMaxDist = window.innerWidth < 768 ? 85 : 120;

                for (let i = 0; i < nodes.length; i++) {
                    for (let j = i + 1; j < nodes.length; j++) {
                        let dx = nodes[i].x - nodes[j].x;
                        let dy = nodes[i].y - nodes[j].y;
                        let distance = Math.sqrt(dx * dx + dy * dy);

                        let maxDist = baseMaxDist + (activationLevel * 50);

                        if (distance < maxDist) {
                            ctx.beginPath();
                            ctx.moveTo(nodes[i].x, nodes[i].y);
                            ctx.lineTo(nodes[j].x, nodes[j].y);

                            let opacity = 1 - (distance / maxDist);
                            let distToMouseI = Math.hypot(nodes[i].x - mouse.x, nodes[i].y - mouse.y);
                            let distToMouseJ = Math.hypot(nodes[j].x - mouse.x, nodes[j].y - mouse.y);
                            let isNearMouse = (distToMouseI < 200 || distToMouseJ < 200);

                            if (activationLevel > 0.05) {
                                ctx.strokeStyle = `rgba(${currentColors.active}, ${opacity * activationLevel * 0.9})`;
                                ctx.lineWidth = 1.5 + (activationLevel * 1);
                                ctx.shadowBlur = 15 * activationLevel;
                                ctx.shadowColor = `rgba(${currentColors.active}, 0.9)`;
                            }
                            else if (isNearMouse) {
                                let mouseIntensity = 1 - (Math.min(distToMouseI, distToMouseJ) / 200);
                                ctx.strokeStyle = `rgba(${currentColors.highlight}, ${opacity * (0.4 + mouseIntensity * 0.6)})`;
                                ctx.lineWidth = 1 + mouseIntensity * 1.5;
                                ctx.shadowBlur = mouseIntensity * 15;
                                ctx.shadowColor = `rgba(${currentColors.highlight}, ${mouseIntensity})`;
                            } else {
                                ctx.strokeStyle = `rgba(${currentColors.node}, ${opacity * (currentColors.nodeOpacity - 0.15)})`;
                                ctx.lineWidth = 0.8;
                                ctx.shadowBlur = 0;
                            }

                            ctx.stroke();
                            ctx.shadowBlur = 0;
                        }
                    }
                }
            }

            // --- Typing Effect Logic ---
            const prompts = [
                "Establishing Vibe Neural Link...",
                "> prompt: initialize creative engine",
                "Synchronizing workspace nodes...",
                "Vibe Studio ⚡ Advanced Edition"
            ];

            let promptIndex = 0;
            let charIndex = 0;
            let isTyping = true;
            const textElement = document.getElementById('prompt-text');
            const containerElement = document.getElementById('type-box');

            function typeWriter() {
                if (!textElement) return;
                if (promptIndex >= prompts.length) promptIndex = 0;

                const currentText = prompts[promptIndex];

                if (isTyping) {
                    textElement.textContent = currentText.substring(0, charIndex + 1);
                    charIndex++;

                    if (charIndex === currentText.length) {
                        isTyping = false;
                        if (containerElement) containerElement.classList.add('glow');
                        activateNetwork();
                        setTimeout(typeWriter, 3000);
                    } else {
                        setTimeout(typeWriter, 30 + Math.random() * 30);
                    }
                } else {
                    if (containerElement) containerElement.classList.remove('glow');
                    textElement.textContent = currentText.substring(0, charIndex - 1);
                    charIndex--;

                    if (charIndex === 0) {
                        isTyping = true;
                        promptIndex++;
                        setTimeout(typeWriter, 600);
                    } else {
                        setTimeout(typeWriter, 15);
                    }
                }
            }

            function animate() {
                const macWindow = document.getElementById('macWindow');
                // 앱(macWindow)이 열려있으면 백그라운드 애니메이션 렌더링을 완전히 중단(Clear)하여 리소스 확보
                if (macWindow && !macWindow.classList.contains('hidden')) {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                } else {
                    drawNetwork();
                }
                requestAnimationFrame(animate);
            }

            resizeCanvas();
            animate();
            setTimeout(typeWriter, 1000);
        })();
