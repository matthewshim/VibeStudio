        // 💻 [1] System Monitor 
        // ==========================================
        function updateSystemInfo() {
            document.getElementById('sys-window-res').innerText = `${window.innerWidth} x ${window.innerHeight}`;
            document.getElementById('sys-screen-res').innerText = `${screen.width} x ${screen.height}`;
            document.getElementById('sys-color-depth').innerText = `${screen.colorDepth}-bit`;
            document.getElementById('sys-pixel-ratio').innerText = window.devicePixelRatio || 1;
            document.getElementById('sys-os').innerText = navigator.platform || 'Unknown';

            let browser = "Unknown";
            if (navigator.userAgent.includes("Chrome")) browser = "Chrome (Blink)";
            else if (navigator.userAgent.includes("Safari")) browser = "Safari (WebKit)";
            else if (navigator.userAgent.includes("Firefox")) browser = "Firefox (Gecko)";
            document.getElementById('sys-browser').innerText = browser;

            document.getElementById('sys-lang').innerText = navigator.language || 'Unknown';
            document.getElementById('sys-timezone').innerText = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Unknown';
            document.getElementById('sys-cpu').innerText = navigator.hardwareConcurrency ? `${navigator.hardwareConcurrency} Cores` : 'Unknown';
            document.getElementById('sys-ram').innerText = navigator.deviceMemory ? `${navigator.deviceMemory} GB+` : 'Unknown';

            let net = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (net && net.effectiveType) {
                document.getElementById('sys-network').innerText = `${net.effectiveType.toUpperCase()} (약 ${net.downlink || 0} Mbps)`;
            } else { document.getElementById('sys-network').innerText = 'Not Supported'; }

            document.getElementById('sys-online').innerHTML = navigator.onLine ? '<span style="color:#27c93f;">Online 🟢</span>' : '<span style="color:#ff5f56;">Offline 🔴</span>';
            document.getElementById('sys-cookie').innerText = navigator.cookieEnabled ? 'Enabled' : 'Disabled';
            document.getElementById('sys-dnt').innerText = navigator.doNotTrack === '1' ? 'Enabled' : 'Disabled';

            const maxTouch = navigator.maxTouchPoints || 0;
            const hasTouch = ('ontouchstart' in window) || (maxTouch > 0);
            document.getElementById('sys-touch').innerText = hasTouch ? `Supported (${maxTouch} points)` : 'Not Supported';
            document.getElementById('sys-ua').innerText = navigator.userAgent;
        }

        window.addEventListener('resize', () => {
            if (document.getElementById('app-sys').classList.contains('active')) {
                document.getElementById('sys-window-res').innerText = `${window.innerWidth} x ${window.innerHeight}`;
            }
        });
        updateSystemInfo();

        // ==========================================
