        // ==========================================
        // ✍️ Spell Check App Logic
        // ==========================================
        let lastSpellResults = [];

        function initSpellCheck() {
            const spellInput = document.getElementById('spellInputText');
            if (spellInput) {
                spellInput.removeEventListener('input', updateCharCount);
                spellInput.addEventListener('input', updateCharCount);
            }
        }

        function updateCharCount() {
            const text = document.getElementById('spellInputText').value;
            const withSpace = text.length;
            const withoutSpace = text.replace(/\s/g, '').length;
            const bytes = new TextEncoder().encode(text).length;
            document.getElementById('charCount').innerText =
                `공백 포함: ${withSpace}자 | 공백 제외: ${withoutSpace}자 | ${bytes} bytes`;
        }

        window.clearSpellInput = function () {
            const spellInput = document.getElementById('spellInputText');
            spellInput.value = '';
            updateCharCount();
            document.getElementById('spellResultBox').innerHTML = `
                <div class="spell-empty">
                    <i data-lucide="check-circle" style="width: 48px; height: 48px; opacity: 0.2;"></i>
                    <p>내용을 입력하고 검사하기를 눌러주세요.</p>
                </div>`;
            document.getElementById('spellErrorCount').innerText = '오류 0개';
            document.getElementById('allFixBtn').style.display = 'none';
            document.getElementById('spellColorGuide').style.display = 'none';
            document.getElementById('spellNotice').style.display = 'none';
            lucide.createIcons();
        };

        window.runSpellCheck = async function () {
            const text = document.getElementById('spellInputText').value.trim();
            if (!text) return;

            const btn = document.getElementById('spellCheckBtn');
            const resultBox = document.getElementById('spellResultBox');

            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader-2" class="lucide-pulse" style="width:18px;"></i> 검사 중...';
            lucide.createIcons();
            resultBox.innerHTML = '<div class="spell-empty"><div class="mac-loader"></div><p>고성능 검사 엔진으로 분석 중입니다...</p></div>';

            try {
                const response = await fetch('spell_proxy.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'text=' + encodeURIComponent(text)
                });
                const data = await response.json();
                renderSpellResults(data);
            } catch (error) {
                console.error('Spell check failed:', error);
                resultBox.innerHTML = '<div class="spell-empty"><i data-lucide="alert-circle" style="color: #ef4444; width:48px; height:48px;"></i><p>서버 통신 중 오류가 발생했습니다.<br>잠시 후 다시 시도해 주세요.</p></div>';
                lucide.createIcons();
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="zap" style="width: 18px;"></i> 검사하기';
                lucide.createIcons();
            }
        };

        function renderSpellResults(data) {
            const resultBox = document.getElementById('spellResultBox');
            const errorCountLabel = document.getElementById('spellErrorCount');
            const allFixBtn = document.getElementById('allFixBtn');
            const colorGuide = document.getElementById('spellColorGuide');

            if (!data || !data.message || !data.message.result) {
                resultBox.innerHTML = `
                    <div class="spell-empty">
                        <i data-lucide="check-circle" style="width: 48px; height: 48px; color: var(--server-primary);"></i>
                        <p style="color: var(--text-main); font-weight: 600;">완벽합니다!</p>
                        <p>발견된 오류가 없거나 분석할 수 없습니다.</p>
                    </div>`;
                errorCountLabel.innerText = '오류 0개';
                allFixBtn.style.display = 'none';
                colorGuide.style.display = 'none';
                lucide.createIcons();
                return;
            }

            const resultHtml = data.message.result.html;
            const errCount = data.message.result.err_cnt;

            errorCountLabel.innerText = `오류 ${errCount}개`;
            allFixBtn.style.display = errCount > 0 ? 'block' : 'none';
            colorGuide.style.display = errCount > 0 ? 'flex' : 'none';
            document.getElementById('spellNotice').style.display = errCount > 0 ? 'flex' : 'none';

            if (errCount === 0) {
                resultBox.innerHTML = `
                    <div class="spell-empty">
                        <i data-lucide="check-circle" style="width: 48px; height: 48px; color: var(--server-primary);"></i>
                        <p style="color: var(--text-main); font-weight: 600;">완벽합니다!</p>
                        <p>맞춤법 오류가 발견되지 않았습니다.</p>
                    </div>`;
                lucide.createIcons();
                return;
            }

            // Show corrected text with highlighting
            resultBox.innerHTML = `
                <div style="line-height: 2.2; font-size: 16px; margin-bottom: 20px; padding: 15px; background: rgba(0,0,0,0.03); border-radius: 12px; min-height: 100px;">
                    ${resultHtml.replace(/_text/g, '_result_text')}
                </div>
                <div id="spellErrorList"></div>
            `;

            // Inject result text coloring styles
            const style = document.createElement('style');
            style.innerHTML = `
                .red_result_text    { color: #ff5757; font-weight: 700; border-bottom: 2px solid #ff5757; }
                .green_result_text  { color: #02c73c; font-weight: 700; border-bottom: 2px solid #02c73c; }
                .violet_result_text { color: #b22af8; font-weight: 700; border-bottom: 2px solid #b22af8; }
                .blue_result_text   { color: #2facea; font-weight: 700; border-bottom: 2px solid #2facea; }
            `;
            resultBox.appendChild(style);

            // Extract individual errors
            const errorList = document.getElementById('spellErrorList');
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = resultHtml;
            const emTags = tempDiv.getElementsByTagName('em');

            lastSpellResults = [];

            for (let i = 0; i < emTags.length; i++) {
                const em = emTags[i];
                const cand = em.innerText;
                const typeClass = em.className;
                let typeName = '기타';
                let typeColor = 'var(--text-muted)';

                if (typeClass.includes('red'))      { typeName = '맞춤법';    typeColor = '#ff5757'; }
                else if (typeClass.includes('green'))  { typeName = '띄어쓰기';  typeColor = '#02c73c'; }
                else if (typeClass.includes('violet') || typeClass.includes('purple')) { typeName = '표준어 의심'; typeColor = '#b22af8'; }
                else if (typeClass.includes('blue'))   { typeName = '통계적 교정'; typeColor = '#2facea'; }

                const div = document.createElement('div');
                div.className = 'spell-error-item';
                div.innerHTML = `
                    <div class="spell-error-header">
                        <span style="color: ${typeColor}">${typeName}</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span class="spell-suggestion" onclick="applyNaverCorrection('${cand}')">${cand}</span>
                        <span style="font-size: 12px; color: var(--text-muted);">(교정됨)</span>
                    </div>
                `;
                errorList.appendChild(div);
                lastSpellResults.push(cand);
            }

            lucide.createIcons();
        }

        window.applyNaverCorrection = function (corrected) {
            const input = document.getElementById('spellInputText');
            if (!input) return;
            input.value = corrected;
            updateCharCount();
        };

        window.applyAllCorrections = function () {
            const input = document.getElementById('spellInputText');
            if (!input || lastSpellResults.length === 0) return;
            input.value = lastSpellResults.join(' ');
            updateCharCount();
        };

        // Auto-initialize
        initSpellCheck();
