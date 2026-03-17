        // 🚀 Floating Registration Panel Logic
        // ==========================================
        let timerInterval;

        // 알림 영역 헬퍼
        function setNotify(msg, type = '') {
            const area = document.getElementById('regNotify');
            const text = document.getElementById('regNotifyText');
            area.className = 'notify-area' + (type ? ' ' + type : '');
            if (text) text.innerText = msg;

            // 아이콘 변경
            const icon = area.querySelector('i[data-lucide]');
            if (icon) {
                if (type === 'success') icon.setAttribute('data-lucide', 'check-circle');
                else if (type === 'error') icon.setAttribute('data-lucide', 'alert-circle');
                else if (type === 'info') icon.setAttribute('data-lucide', 'loader-2');
                else icon.setAttribute('data-lucide', 'info');
                lucide.createIcons();
            }
        }

        window.toggleRegPanel = function () {
            const panel = document.getElementById('regPanel');
            const container = panel.closest('.floating-container');
            const bookmark = document.getElementById('regBookmark');
            const isOpen = panel.classList.toggle('active');

            if (container) container.classList.toggle('panel-active', isOpen);

            // 패널 열리면 PRE-REGISTER 버튼 숨김 / 닫히면 복원
            if (bookmark) bookmark.style.display = isOpen ? 'none' : '';

            if (isOpen) {
                lucide.createIcons();
            }
        };

        // About Vibe Studio 페이지 연결
        window.openAboutPage = function () {
            window.open('about.html', '_blank');
        };

        window.switchRegTab = function (tab, el) {
            document.querySelectorAll('.reg-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.reg-view').forEach(v => v.classList.remove('active'));
            if (el) el.classList.add('active');
            document.getElementById('reg-' + tab).classList.add('active');
            lucide.createIcons();
        };

        // 소식 선택 칩 토글
        window.toggleFanChip = function (type, checkbox) {
            const chip = document.getElementById('chip-' + type);
            if (!chip) return;
            if (checkbox.checked) {
                chip.classList.add('active');
            } else {
                chip.classList.remove('active');
            }
        };

        window.sendCode = async function () {
            const email = document.getElementById('emailAddr').value.trim();
            if (!email || !email.includes('@')) {
                setNotify('올바른 이메일 주소를 입력해주세요.', 'error');
                return;
            }
            setNotify('인증 메일을 발송 중입니다...', 'info');

            try {
                const response = await fetch('mail_auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=send&email=' + encodeURIComponent(email)
                });
                const data = await response.json();
                if (data.success) {
                    setNotify(data.message, 'success');
                    document.getElementById('timer').style.color = '';
                    startTimer(600); // 10 minutes
                } else {
                    setNotify(data.message, 'error');
                }
            } catch (error) {
                console.error('Email send failed:', error);
                setNotify('서버 통신 중 오류가 발생했습니다.', 'error');
            }
        };

        function startTimer(duration) {
            clearInterval(timerInterval);
            let timer = duration;
            const display = document.getElementById('timer');
            display.style.color = '#ff453a';

            timerInterval = setInterval(() => {
                const minutes = Math.floor(timer / 60);
                const seconds = timer % 60;
                display.innerText = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

                if (--timer < 0) {
                    clearInterval(timerInterval);
                    display.innerText = '만료';
                    setNotify('인증 시간이 만료되었습니다. 다시 발송해주세요.', 'error');
                }
            }, 1000);
        }

        window.verifyCode = async function () {
            const code = document.getElementById('verifyCode').value.trim();
            if (!code) { setNotify('인증번호를 입력해주세요.', 'error'); return; }

            try {
                const response = await fetch('mail_auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=verify&code=' + encodeURIComponent(code)
                });
                const data = await response.json();
                if (data.success) {
                    clearInterval(timerInterval);
                    const display = document.getElementById('timer');
                    display.innerText = '✓ 완료';
                    display.style.color = '#34d399';
                    setNotify('인증 성공! 이제 사전예약을 완료해주세요.', 'success');
                } else {
                    setNotify(data.message, 'error');
                }
            } catch (error) {
                console.error('Verification failed:', error);
                setNotify('서버 통신 중 오류가 발생했습니다.', 'error');
            }
        };

        window.toggleAllAgree = function (el) {
            document.querySelectorAll('.agree-item').forEach(item => item.checked = el.checked);
        };

        window.submitReg = async function () {
            const reqs = document.querySelectorAll('.agree-item.req');
            let allChecked = true;
            reqs.forEach(r => { if (!r.checked) allChecked = false; });

            if (!allChecked) {
                setNotify('필수 항목에 동의해주세요.', 'error');
                return;
            }

            const timerEl = document.getElementById('timer');
            if (!timerEl.innerText.includes('완료') && !timerEl.innerText.includes('✓')) {
                setNotify('이메일 주소 인증을 먼저 완료해주세요.', 'error');
                return;
            }

            const marketingChecked = document.getElementById('agreeMarketing').checked;
            const webappChecked = document.getElementById('applyWebapp').checked;
            const contentChecked = document.getElementById('applyContent').checked;
            const coffeeChecked = document.getElementById('applyCoffee').checked;

            const btn = document.getElementById('regCtaBtn');
            btn.disabled = true;
            btn.innerText = '처리 중...';

            try {
                const response = await fetch('mail_auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=register&marketing=${marketingChecked}&webapp=${webappChecked}&content=${contentChecked}&coffee=${coffeeChecked}`
                });
                const data = await response.json();

                if (data.success) {
                    btn.innerText = '신청 완료! 🎉';
                    btn.style.background = 'linear-gradient(135deg, #34d399, #10b981)';
                    btn.style.boxShadow = '0 4px 20px rgba(52,211,153,0.4)';
                    setNotify('Vibe Studio Fan 신청이 완료되었습니다!', 'success');

                    setTimeout(() => {
                        toggleRegPanel();
                        // 초기화
                        document.getElementById('emailAddr').value = '';
                        document.getElementById('verifyCode').value = '';
                        document.getElementById('timer').innerText = '10:00';
                        document.getElementById('timer').style.color = '';
                        document.querySelectorAll('.agree-item, #agreeAll').forEach(i => i.checked = false);
                        document.getElementById('applyWebapp').checked = true; // 기본값
                        document.getElementById('chip-webapp').classList.add('active');
                        document.getElementById('chip-content').classList.remove('active');
                        document.getElementById('chip-coffee').classList.remove('active');
                        setNotify('이메일 주소를 입력하세요.', '');
                        btn.innerText = 'Vibe Studio Fan 신청하기 🚀';
                        btn.style.background = '';
                        btn.style.boxShadow = '';
                        btn.disabled = false;
                    }, 2500);
                } else {
                    setNotify(data.message, 'error');
                    btn.disabled = false;
                    btn.innerText = 'Vibe Studio Fan 신청하기 🚀';
                }
            } catch (error) {
                console.error('Registration failed:', error);
                setNotify('서버 통신 중 오류가 발생했습니다.', 'error');
                btn.disabled = false;
                btn.innerText = '웹앱 사전예약하기 🚀';
            }
        };
