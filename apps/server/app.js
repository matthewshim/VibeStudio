        // ==========================================
        async function fetchServerStats() {
            try {
                const response = await fetch('api.php');
                const data = await response.json();

                const cpuEl = document.getElementById('srv-cpu');
                if (cpuEl) cpuEl.innerText = data.cpu;
                const cpuBar = document.getElementById('srv-cpu-bar');
                if (cpuBar) cpuBar.style.width = data.cpu + '%';

                const memEl = document.getElementById('srv-mem');
                if (memEl) memEl.innerText = data.memory.percentage;
                const memBar = document.getElementById('srv-mem-bar');
                if (memBar) memBar.style.width = data.memory.percentage + '%';
                const memDet = document.getElementById('srv-mem-detail');
                if (memDet) memDet.innerText = `${data.memory.used} / ${data.memory.total}`;

                const diskEl = document.getElementById('srv-disk');
                if (diskEl) diskEl.innerText = data.disk.percentage;
                const diskBar = document.getElementById('srv-disk-bar');
                if (diskBar) diskBar.style.width = data.disk.percentage + '%';
                const diskDet = document.getElementById('srv-disk-detail');
                if (diskDet) diskDet.innerText = `${data.disk.used} / ${data.disk.total}`;

                const uptEl = document.getElementById('srv-uptime');
                if (uptEl) uptEl.innerText = data.uptime.replace('up ', '');
                const updEl = document.getElementById('srv-updated');
                if (updEl) updEl.innerText = data.timestamp;
            } catch (error) {
                console.error('Failed to fetch server stats:', error);
            }
        }

        const statsInterval = setInterval(() => {
            if (document.getElementById('app-server')?.classList.contains('active')) {
                fetchServerStats();
            }
        }, 5000);

        window.appCleanups = window.appCleanups || {};
        window.appCleanups['server'] = () => {
            clearInterval(statsInterval);
        };

        fetchServerStats();
