        // ==========================================
        // 🚀 Floating Registration Panel Logic v3
        // Google OAuth (Popup) — Session Persist
        // ==========================================

        let timerInterval;

        // ── 현재 flow 상태 ──────────────────────────────────────
        // 'idle' | 'google_pending' | 'google_done'
        let _fanFlowState = 'idle';

        // ── 알림 영역 헬퍼 ──────────────────────────────────────
        function setNotify(msg, type = '') {
            const area = document.getElementById('regNotify');
            const text = document.getElementById('regNotifyText');
            if (!area || !text) return;
            area.className = 'notify-area' + (type ? ' ' + type : '');
            text.innerText = msg;

            const icon = area.querySelector('i[data-lucide]');
            if (icon) {
                if (type === 'success') icon.setAttribute('data-lucide', 'check-circle');
                else if (type === 'error') icon.setAttribute('data-lucide', 'alert-circle');
                else if (type === 'info') icon.setAttribute('data-lucide', 'loader-2');
                else icon.setAttribute('data-lucide', 'info');
                if (window.lucide) lucide.createIcons();
            }
        }

        // ── 패널 토글 ────────────────────────────────────────────
        window.toggleRegPanel = function () {
            const panel    = document.getElementById('regPanel');
            const bookmark = document.getElementById('regBookmark');
            const isOpen   = panel.classList.toggle('active');

            const container = panel.closest('.floating-container');
            if (container) container.classList.toggle('panel-active', isOpen);
            if (bookmark) bookmark.style.display = isOpen ? 'none' : '';

            if (isOpen) {
                // 패널 오픈 시:
                // - google_done 상태면 Step 1(정보 입력) 유지
                // - 그 외에는 Step 0(Google 로그인 유도) 표시
                if (_fanFlowState === 'google_done') {
                    showStep('reg-webapp');
                } else if (_fanFlowState === 'idle') {
                    showStep('reg-step0');
                    // 서버 세션에 Google 로그인이 남아있는지 확인
                    checkFanGoogleSession();
                }
                if (window.lucide) lucide.createIcons();
            }
        };

        window.openAboutPage = function () {
            window.open('about.html', '_blank');
        };

        // ── 뷰 전환 헬퍼 ─────────────────────────────────────────
        function showStep(viewId) {
            document.querySelectorAll('.reg-view').forEach(v => v.classList.remove('active'));
            const el = document.getElementById(viewId);
            if (el) el.classList.add('active');
            if (window.lucide) lucide.createIcons();
        }

        // ── 서버 Google 세션 확인 (패널 오픈 시 호출) ───────────
        async function checkFanGoogleSession() {
            try {
                const res  = await fetch('admin.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    'action=fan_google_session_check'
                });
                const data = await res.json();
                if (data.success && data.logged_in) {
                    _fanFlowState = 'google_done';
                    setEmailVerifyMode('google', data.email);
                    // 기존 신청값이 있으면 칩/동의 복원
                    if (data.already_registered && data.reg_data) {
                        applyRegData(data.reg_data);
                        setNotify('이미 Vibe Studio Fan으로 등록되어 있어요! 신청 항목은 언제든 변경할 수 있습니다. ✨', 'success');
                    } else {
                        setNotify('Google 인증이 완료되었습니다. 아래 항목을 선택 후 신청해주세요.', 'success');
                    }
                    showStep('reg-webapp');
                }
            } catch (e) {
                // 세션 확인 실패 시 무시 (Step 0 유지)
            }
        }

        // ── 다른 구글 계정으로 신청하기 (로그아웃 후 Step 0 복귀) ─
        window.switchFanGoogleAccount = async function () {
            try {
                await fetch('admin.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    'action=fan_google_session_clear'
                });
            } catch (e) { /* 무시 */ }

            _fanFlowState = 'idle';
            resetStep1();

            // Google 버튼 텍스트 복원
            const btn  = document.getElementById('regGoogleBtn');
            const text = document.getElementById('regGoogleBtnText');
            if (btn)  btn.disabled = false;
            if (text) text.textContent = 'Google로 시작하기';

            showStep('reg-step0');
        };

        // ── Google 인증 UI 설정 ─────────────────────────────────
        // Google OAuth 완료 시 배지/계정전환 표시, 초기화 시 숨김
        function setEmailVerifyMode(mode, email) {
            const badge       = document.getElementById('step1GoogleBadge');
            const badgeEmail  = document.getElementById('step1BadgeEmail');
            const switchBtn   = document.getElementById('step1SwitchBtn');

            if (mode === 'google') {
                if (badge)      badge.style.display      = 'flex';
                if (badgeEmail) badgeEmail.textContent   = email || 'Google 인증 완료';
                if (switchBtn)  switchBtn.style.display  = 'flex';
            } else {
                // 로그아웃 / 초기화 시
                if (badge)     badge.style.display     = 'none';
                if (switchBtn) switchBtn.style.display = 'none';
            }
        }





        // ── 기존 신청값 복원 ───────────────────────────────
        // reg_data: { webapp, content, coffee, marketing }
        function applyRegData(reg_data) {
            if (!reg_data) return;

            const setChip = (type, val) => {
                const input = document.getElementById('apply' + type.charAt(0).toUpperCase() + type.slice(1));
                const chip  = document.getElementById('chip-' + type);
                if (input) input.checked = !!val;
                if (chip)  chip.classList.toggle('active', !!val);
            };

            setChip('webapp',   reg_data.webapp);
            setChip('content',  reg_data.content);
            setChip('coffee',   reg_data.coffee);

            // 마케팅 동의
            const marketing = document.getElementById('agreeMarketing');
            if (marketing) marketing.checked = !!reg_data.marketing;

            // 필수 동의는 사용자가 직접 체크해야 함 (보안 정체성)
            // 전체 동의 체크박스 업데이트
            const agreeAll = document.getElementById('agreeAll');
            const agreeAge = document.getElementById('agreeAge');
            const agreePrivacy = document.getElementById('agreePrivacy');
            if (agreeAll && agreeAge && agreePrivacy && marketing) {
                agreeAll.checked = agreeAge.checked && agreePrivacy.checked && marketing.checked;
            }
        }

        function resetStep1() {
            clearInterval(timerInterval);
            // 배지 / 계정전환 버튼 숨김
            setEmailVerifyMode('reset', '');
            document.querySelectorAll('.agree-item, #agreeAll').forEach(i => i.checked = false);
            // 기본 항목 복원 (웹앱만 선택)
            const webapp = document.getElementById('applyWebapp');
            const chip   = document.getElementById('chip-webapp');
            if (webapp) webapp.checked = true;
            if (chip)   chip.classList.add('active');
            document.getElementById('chip-content')?.classList.remove('active');
            document.getElementById('chip-coffee')?.classList.remove('active');

            const btn = document.getElementById('regCtaBtn');
            if (btn) {
                btn.innerHTML = '<i data-lucide="zap" style="width:15px;height:15px;"></i> Vibe Studio Fan 신청하기';
                btn.disabled = false;
                btn.style.background = '';
                btn.style.boxShadow = '';
            }
        }

        // ── Google OAuth 팝업 시작 ────────────────────────────────
        window.startFanGoogleLogin = async function () {
            const btn  = document.getElementById('regGoogleBtn');
            const text = document.getElementById('regGoogleBtnText');

            btn.disabled = true;
            if (text) text.textContent = 'Google 연결 중...';
            _fanFlowState = 'google_pending';

            const popupUrl    = '/google_fan_oauth.php';
            const popupWidth  = 500;
            const popupHeight = 620;
            const left = Math.max(0, (screen.width  - popupWidth)  / 2);
            const top  = Math.max(0, (screen.height - popupHeight) / 2);

            const popup = window.open(
                popupUrl,
                'vibeGoogleFanAuth',
                `width=${popupWidth},height=${popupHeight},left=${left},top=${top},scrollbars=yes,resizable=yes`
            );

            if (!popup || popup.closed) {
                // 팝업 차단
                btn.disabled = false;
                if (text) text.textContent = 'Google로 시작하기';
                _fanFlowState = 'idle';
                alert('팝업이 차단되었습니다. 브라우저 팝업 허용 후 다시 시도해주세요.');
                return;
            }

            // 팝업 닫힘 감지 (15분 타임아웃)
            const checkClosed = setInterval(() => {
                if (popup.closed) {
                    clearInterval(checkClosed);
                    if (_fanFlowState === 'google_pending') {
                        // 팝업이 postMessage 없이 닫힌 경우 (취소)
                        btn.disabled = false;
                        if (text) text.textContent = 'Google로 시작하기';
                        _fanFlowState = 'idle';
                    }
                }
            }, 800);

            // 팝업 타임아웃 (15분)
            setTimeout(() => {
                if (_fanFlowState === 'google_pending') {
                    clearInterval(checkClosed);
                    if (!popup.closed) popup.close();
                    btn.disabled = false;
                    if (text) text.textContent = 'Google로 시작하기';
                    _fanFlowState = 'idle';
                }
            }, 900000);
        };

        // ── postMessage 수신 (Google OAuth 콜백 팝업 → 부모) ──────
        window.addEventListener('message', async function (event) {
            // Origin 검증
            const allowedOrigin = window.location.origin;
            if (event.origin !== allowedOrigin) return;

            const data = event.data?.vibe_fan_oauth;
            if (!data) return;

            const btn  = document.getElementById('regGoogleBtn');
            const text = document.getElementById('regGoogleBtnText');

            if (data.status === 'success') {
                const { email, google_id, name } = data;
                _fanFlowState = 'google_done';

                // 서버 세션에 저장
                let result;
                try {
                    const res = await fetch('admin.php', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    `action=fan_google_session_set&email=${encodeURIComponent(email)}&google_id=${encodeURIComponent(google_id || '')}&name=${encodeURIComponent(name || '')}`
                    });
                    result = await res.json();
                    if (!result.success) throw new Error(result.message);
                } catch (e) {
                    console.error('[FanOAuth] 세션 저장 실패:', e);
                    if (btn) btn.disabled = false;
                    if (text) text.textContent = 'Google로 시작하기';
                    _fanFlowState = 'idle';
                    alert('Google 인증 처리 중 오류가 발생했습니다. 다시 시도해주세요.');
                    return;
                }

                // Step 1으로 이동 + Google 인증 완료 표시
                setEmailVerifyMode('google', email);
                showStep('reg-webapp');

                // 이미 등록된 회원이면 안내 알림 + 기존값 적용
                if (result.already_registered) {
                    if (result.reg_data) applyRegData(result.reg_data);
                    setNotify('이미 Vibe Studio Fan으로 등록되어 있어요! 신청 항목은 언제든 변경할 수 있습니다. ✨', 'success');
                } else {
                    setNotify('Google 인증이 완료되었습니다. 아래 항목을 선택 후 신청해주세요.', 'success');
                }

                if (btn) { btn.disabled = false; }
                if (text) text.textContent = 'Google로 시작하기';


            } else if (data.status === 'cancelled') {
                _fanFlowState = 'idle';
                if (btn) btn.disabled = false;
                if (text) text.textContent = 'Google로 시작하기';
                setNotify && setNotify('Google 로그인을 취소하셨습니다.', '');

            } else if (data.status === 'no_email') {
                // Google 계정에 이메일이 없음 → Step 0 유지, 안내만 표시
                _fanFlowState = 'idle';
                if (btn) btn.disabled = false;
                if (text) text.textContent = 'Google로 시작하기';
                setNotify && setNotify('Google 계정에서 이메일을 가져오지 못했습니다. 다른 계정으로 다시 시도해주세요.', 'error');

            } else {
                // 기타 에러
                _fanFlowState = 'idle';
                if (btn) btn.disabled = false;
                if (text) text.textContent = 'Google로 시작하기';
            }
        });

        // ── 소식 선택 칩 토글 ────────────────────────────────────
        window.toggleFanChip = function (type, checkbox) {
            const chip = document.getElementById('chip-' + type);
            if (!chip) return;
            chip.classList.toggle('active', checkbox.checked);
        };



        // ── 전체 동의 토글 ────────────────────────────────────────
        window.toggleAllAgree = function (el) {
            // 동의 항목만 토글 (구독 층 칩 제외)
            ['agreeAge', 'agreePrivacy', 'agreeMarketing'].forEach(id => {
                const item = document.getElementById(id);
                if (item) item.checked = el.checked;
            });
        };

        // ── 신청 제출 ─────────────────────────────────────────────
        window.submitReg = async function () {
            // 필수 동의 확인 (agreeAge, agreePrivacy만 - 구독 칩과 분리)
            const reqs = [document.getElementById('agreeAge'), document.getElementById('agreePrivacy')];
            const allChecked = reqs.every(r => r && r.checked);
            if (!allChecked) { setNotify('필수 항목에 동의해주세요.', 'error'); return; }

            // 이메일 인증 확인 (Google 완료 flow 제외)
            if (_fanFlowState !== 'google_done') {
                const timerEl = document.getElementById('timer');
                const timerText = timerEl?.innerText || '';
                if (!timerText.includes('완료') && !timerText.includes('✓') && !timerText.includes('기존')) {
                    setNotify('이메일 주소 인증을 먼저 완료해주세요.', 'error'); return;
                }
            }

            const marketingChecked = document.getElementById('agreeMarketing').checked;
            const webappChecked    = document.getElementById('applyWebapp').checked;
            const contentChecked   = document.getElementById('applyContent').checked;
            const coffeeChecked    = document.getElementById('applyCoffee').checked;

            const btn = document.getElementById('regCtaBtn');
            btn.disabled = true;
            btn.innerText = '처리 중...';

            try {
                const response = await fetch('mail_auth.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    `action=register&marketing=${marketingChecked}&webapp=${webappChecked}&content=${contentChecked}&coffee=${coffeeChecked}`
                });
                const data = await response.json();

                if (data.success) {
                    btn.innerText = '신청 완료! 🎉';
                    btn.style.background  = 'linear-gradient(135deg, #34d399, #10b981)';
                    btn.style.boxShadow   = '0 4px 20px rgba(52,211,153,0.4)';
                    setNotify('Vibe Studio Fan 신청이 완료되었습니다!', 'success');

                    // 2.5초 후: 패널 닫기, google_done 상태 유지 → 재오픈 시 Step 1 바로 표시
                    setTimeout(() => {
                        toggleRegPanel();
                        // _fanFlowState = 'google_done' 유지 (변경하지 않음)
                        // 서버 세션도 유지 → 패널 재오픈 시 Step 1 즉시 복귀
                        // 알림 메시지를 기존 회원 안내로 업데이트
                        setNotify('이미 Vibe Studio Fan으로 등록되어 있어요! 신청 항목은 언제든 변경할 수 있습니다. ✨', 'success');
                        // CTA 버튼 복원
                        const ctaBtn = document.getElementById('regCtaBtn');
                        if (ctaBtn) {
                            ctaBtn.innerHTML = '<i data-lucide="zap" style="width:15px;height:15px;"></i> Vibe Studio Fan 신청하기';
                            ctaBtn.disabled = false;
                            ctaBtn.style.background = '';
                            ctaBtn.style.boxShadow = '';
                        }
                        if (window.lucide) lucide.createIcons();
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
                btn.innerText = 'Vibe Studio Fan 신청하기 🚀';
            }
        };

        window.switchRegTab = function (tab, el) {
            document.querySelectorAll('.reg-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.reg-view').forEach(v => v.classList.remove('active'));
            if (el) el.classList.add('active');
            document.getElementById('reg-' + tab)?.classList.add('active');
            if (window.lucide) lucide.createIcons();
        };

