(function () {
    // Build marker — lets you confirm in DevTools which widget version is loaded.
    // If this line is NOT printed in the console, the browser/CDN is serving a
    // cached/old widget.js and the de-dup fix is not yet live.
    console.log('[pt-widget] build: single-render-dedup (no optimistic copy)');

    // ── Configuration ──────────────────────────────────────────────────────
    // Values are read from the <script> tag's data-* attributes.
    // Embed: <script src="https://stand/widget/widget.js" data-domain="https://stand" data-key="pub_xxx" defer></script>
    const scriptTag = document.currentScript || document.querySelector('script[data-key]');

    if (!scriptTag) {
        console.error('[pt-widget] Cannot locate <script data-key> tag.');
        return;
    }

    const DOMAIN = (scriptTag.dataset.domain || '').replace(/\/+$/, '');
    const PUBLIC_KEY = scriptTag.dataset.key || '';

    if (!DOMAIN || !PUBLIC_KEY) {
        console.error('[pt-widget] Missing data-domain or data-key on the <script> tag.');
        return;
    }

    const STYLE_URL  = DOMAIN + '/widget/style.css';
    const AVATAR_URL = DOMAIN + '/widget/manager.png';

    const greetingText = scriptTag.dataset.greeting  || 'Напишите нам, мы онлайн!';
    const managerName  = scriptTag.dataset.manager   || 'Поддержка';

    // ── ExternalId (scoped per public key) ────────────────────────────────
    function getExternalId() {
        const storageKey = 'widget_eid_' + PUBLIC_KEY;
        let id = localStorage.getItem(storageKey);
        if (!id) {
            id = 'eid_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
            localStorage.setItem(storageKey, id);
        }
        return id;
    }

    const externalId = getExternalId();

    // ── HTTP transport ─────────────────────────────────────────────────────
    const API_BASE   = DOMAIN + '/api/widget/' + externalId;
    const JSON_HEADERS = { 'X-Widget-Key': PUBLIC_KEY, 'Content-Type': 'application/json' };

    async function sendMessageHttp(text) {
        const resp = await fetch(API_BASE + '/messages', {
            method:  'POST',
            headers: JSON_HEADERS,
            body:    JSON.stringify({ text }),
        });
        return resp.json();
    }

    async function sendFileHttp(file) {
        const fd = new FormData();
        fd.append('uploaded_file', file);
        const resp = await fetch(API_BASE + '/files', {
            method:  'POST',
            headers: { 'X-Widget-Key': PUBLIC_KEY }, // no Content-Type — multipart
            body:    fd,
        });
        return resp.json();
    }

    async function pollMessages(afterId) {
        const url = API_BASE + '/messages' + (afterId ? '?after=' + afterId : '');
        const resp = await fetch(url, { headers: { 'X-Widget-Key': PUBLIC_KEY } });
        return resp.json();
    }

    // ── Polling loop ───────────────────────────────────────────────────────
    let lastMessageId   = 0;
    let pollInterval    = null;
    let chatIsOpen      = false;
    let historyLoaded   = false;
    let fetchInFlight   = false;

    // Fetch and render messages newer than lastMessageId. Guarded so two overlapping
    // calls (the 3s tick and the post-send refresh) can't both fetch with the same
    // cursor and double-render; renderMessage's id-dedupe is the final backstop.
    async function fetchNewMessages() {
        if (fetchInFlight) return;
        fetchInFlight = true;
        try {
            const data = await pollMessages(lastMessageId);
            if (data && data.messages && data.messages.length) {
                data.messages.forEach(msg => renderMessage(msg));
                lastMessageId = data.messages[data.messages.length - 1].id;
                scrollBottom();
            }
        } catch (err) {
            // Network error — silently skip this tick
        } finally {
            fetchInFlight = false;
        }
    }

    function startPolling() {
        if (pollInterval) return;
        pollInterval = setInterval(fetchNewMessages, 3000);
    }

    function stopPolling() {
        clearInterval(pollInterval);
        pollInterval = null;
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopPolling();
        } else if (chatIsOpen) {
            startPolling();
        }
    });

    // ── Stylesheet ─────────────────────────────────────────────────────────
    if (STYLE_URL && !document.querySelector('link[data-pt-widget-styles]')) {
        const link = document.createElement('link');
        link.rel  = 'stylesheet';
        link.href = STYLE_URL;
        link.setAttribute('data-pt-widget-styles', '');
        document.head.appendChild(link);
    }

    // ── Guard against double-init ──────────────────────────────────────────
    const widgetId = 'prog-time-widget';
    if (document.getElementById(widgetId)) return;

    // ── Toggle button ──────────────────────────────────────────────────────
    const butWidget = document.createElement('div');
    butWidget.setAttribute('class', 'ptw-butWidget');
    butWidget.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 60 60" fill="none">
            <rect width="60" height="60" rx="30" fill="#0BA5EC"/>
            <path d="M24.6665 30H24.6785M29.9878 30H29.9998M35.3212 30H35.3332" stroke="white" stroke-width="2.66667" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M29.9998 43.3334C37.3638 43.3334 43.3332 37.364 43.3332 30C43.3332 22.636 37.3638 16.6667 29.9998 16.6667C22.6358 16.6667 16.6665 22.636 16.6665 30C16.6665 32.1334 17.1678 34.1494 18.0572 35.9374C18.2945 36.412 18.3732 36.9547 18.2358 37.468L17.4425 40.436C17.3639 40.7297 17.364 41.039 17.4427 41.3327C17.5214 41.6263 17.676 41.8942 17.8909 42.1092C18.1059 42.3243 18.3736 42.479 18.6672 42.5579C18.9608 42.6368 19.2701 42.6371 19.5638 42.5587L22.5318 41.764C23.047 41.6339 23.592 41.6969 24.0638 41.9414C25.9078 42.8594 27.94 43.336 29.9998 43.3334Z" stroke="white" stroke-width="2"/>
        </svg>
    `;
    document.body.appendChild(butWidget);

    // ── Chat container ─────────────────────────────────────────────────────
    const container = document.createElement('div');
    container.id = widgetId;
    container.setAttribute('role', 'region');
    container.setAttribute('aria-label', 'Онлайн-чат поддержки');

    container.innerHTML = `
        <header class="ptw_header">
            <div class="ptw_block_logo">
                <img src="${AVATAR_URL}" class="ptw_logo" alt="">
            </div>
            <div class="ptw_text">
                <div class="ptw_dop_heading">${greetingText}</div>
                <div class="ptw_name_manager">${managerName}</div>
            </div>
            <button class="ptw_close_but" type="button" aria-label="Закрыть чат">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M12 22C17.5 22 22 17.5 22 12C22 6.5 17.5 2 12 2C6.5 2 2 6.5 2 12C2 17.5 6.5 22 12 22Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M7.91992 12H15.9199" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </header>

        <main class="ptw_content_block" id="ptw_messages"></main>

        <footer class="ptw_footer">
            <div class="ptw_input_row">
                <label for="ptw_file_field" class="ptw_attach_but" aria-label="Прикрепить файл">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M13.3643 8.53806L7.14889 14.7535C6.56765 15.3347 6.56765 16.2771 7.14889 16.8584V16.8584C7.73014 17.4396 8.67252 17.4396 9.25376 16.8584L18.0481 8.06397C19.1894 6.92272 19.1899 5.07254 18.0493 3.93065V3.93065C16.9078 2.78785 15.0558 2.78732 13.9137 3.92949L4.93981 12.9033C3.23957 14.6036 3.23957 17.3602 4.93981 19.0604V19.0604C6.64004 20.7607 9.39668 20.7607 11.0969 19.0604L17.7556 12.4018" stroke="#999EA1" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </label>
                <input id="ptw_file_field" class="ptw_file_field" type="file" multiple aria-label="Прикрепить файл">
                <textarea id="ptw_text_field" class="ptw_text_field" rows="1" placeholder="Введите сообщение" aria-label="Поле ввода сообщения"></textarea>
                <button disabled class="ptw_send_but" type="submit" aria-label="Отправить сообщение">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <rect width="48" height="48" rx="8" fill="#0BA5EC"/>
                        <path d="M21.9252 17.5251L29.0586 21.0918C32.2586 22.6918 32.2586 25.3084 29.0586 26.9084L21.9252 30.4751C17.1252 32.8751 15.1669 30.9084 17.5669 26.1168L18.2919 24.6751C18.4752 24.3084 18.4752 23.7001 18.2919 23.3334L17.5669 21.8834C15.1669 17.0918 17.1336 15.1251 21.9252 17.5251Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
            <div id="ptw_attachments" class="ptw_attachments"></div>
        </footer>
    `;
    document.body.appendChild(container);

    // ── Lightbox ───────────────────────────────────────────────────────────
    const lightbox = document.createElement('div');
    lightbox.className = 'ptw_lightbox';
    lightbox.style.display = 'none';
    lightbox.innerHTML = `
        <button class="ptw_lightbox_close" aria-label="Закрыть">&times;</button>
        <img alt="Просмотр изображения">
    `;
    document.body.appendChild(lightbox);

    const lightboxImg = lightbox.querySelector('img');

    function openLightbox(src) {
        lightboxImg.src = src;
        lightbox.style.display = 'flex';
    }

    function closeLightbox() {
        lightbox.style.display = 'none';
        lightboxImg.src = '';
    }

    lightbox.addEventListener('click', (e) => {
        if (!e.target.closest('img')) {
            closeLightbox();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeLightbox();
    });

    // ── Utilities ──────────────────────────────────────────────────────────
    function scrollBottom() {
        setTimeout(() => {
            const messagesContainer = container.querySelector('#ptw_messages');
            messagesContainer.scrollTo({ top: messagesContainer.scrollHeight, behavior: 'smooth' });
        }, 50);
    }

    function getFileIconColor(ext) {
        const map = {
            pdf: '#E53935',
            doc: '#1565C0', docx: '#1565C0',
            xls: '#2E7D32', xlsx: '#2E7D32', csv: '#2E7D32',
            ppt: '#E64A19', pptx: '#E64A19',
            zip: '#6A1B9A', rar: '#6A1B9A', '7z': '#6A1B9A',
            mp3: '#AD1457', wav: '#AD1457', ogg: '#AD1457', m4a: '#AD1457',
            mp4: '#1565C0', avi: '#1565C0', mov: '#1565C0', mkv: '#1565C0',
            txt: '#546E7A',
        };
        return map[ext.toLowerCase()] || '#42A5F5';
    }

    function createFileCardHTML(fileName, fileUrl) {
        const ext = fileName && fileName.includes('.')
            ? fileName.split('.').pop().toUpperCase().slice(0, 4)
            : 'DOC';
        const iconColor  = getFileIconColor(ext);
        const displayName = fileName || 'Документ';
        const label      = fileName ? ext : 'Файл';
        const safeName   = displayName.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const safeUrl    = (fileUrl || '').replace(/"/g, '&quot;');
        const safeDl     = displayName.replace(/"/g, '&quot;');
        return `<div class="ptw_file_card" data-file-url="${safeUrl}" data-file-name="${safeDl}">
                    <div class="ptw_file_icon" style="background:${iconColor}">${ext}</div>
                    <div class="ptw_file_info">
                        <div class="ptw_file_name">${safeName}</div>
                        <div class="ptw_file_label">${label}</div>
                    </div>
                </div>`;
    }

    function isDifferentDay(date1, date2) {
        if (!date1 || !date2) return true;
        const d1 = new Date(date1);
        const d2 = new Date(date2);
        d1.setHours(0, 0, 0, 0);
        d2.setHours(0, 0, 0, 0);
        return d1.getTime() !== d2.getTime();
    }

    function formatDate(inputDate) {
        const date      = new Date(inputDate);
        const today     = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);

        const dateOnly      = new Date(date.getFullYear(),      date.getMonth(),      date.getDate());
        const todayOnly     = new Date(today.getFullYear(),     today.getMonth(),     today.getDate());
        const yesterdayOnly = new Date(yesterday.getFullYear(), yesterday.getMonth(), yesterday.getDate());

        if (dateOnly.getTime() === todayOnly.getTime())     return 'Сегодня';
        if (dateOnly.getTime() === yesterdayOnly.getTime()) return 'Вчера';

        const day   = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year  = date.getFullYear();
        return `${day}.${month}.${year}`;
    }

    function createLineBlock(date) {
        const messagesContainer = container.querySelector('#ptw_messages');
        const el = document.createElement('section');
        el.innerHTML = `<div class="date-line"><hr class="line"><div class="date-text">${formatDate(date)}</div></div>`;
        messagesContainer.appendChild(el);
    }

    // ── Message rendering (server format) ─────────────────────────────────
    // Server response shape:
    // { id, text, direction, created_at, attachments: [] }
    // direction: "in" = sent by user (right/outgoing), "out" = from support (left/incoming)
    let lastRenderedDate = null;

    function renderMessage(msg) {
        const messagesContainer = container.querySelector('#ptw_messages');

        // Single source of truth: every bubble carries its server id, and the
        // same id is never rendered twice. This is the only place messages enter
        // the DOM, so there is no optimistic copy to collide with — the duplicate
        // (optimistic render + the poll re-fetching the same row) cannot occur.
        if (msg.id != null && document.getElementById('messageBlock_' + msg.id)) {
            return;
        }

        // Date separator
        const msgDate = msg.created_at ? new Date(msg.created_at) : new Date();
        if (isDifferentDay(lastRenderedDate, msgDate)) {
            createLineBlock(msgDate);
            lastRenderedDate = msgDate;
        }

        const messageEl = document.createElement('section');
        // direction "in" = user sent it → outgoing (right side)
        // direction "out" = support sent it → incoming (left side)
        messageEl.className = msg.direction === 'in'
            ? 'ptw_message_block ptw_client_message_block'
            : 'ptw_message_block ptw_manager_message_block';

        messageEl.id = 'messageBlock_' + msg.id;

        let content = `<div class="contentBlock">`;

        // Attachments from server
        if (msg.attachments && msg.attachments.length) {
            msg.attachments.forEach(att => {
                const isImage = att.mime_type && att.mime_type.startsWith('image/');
                if (isImage) {
                    const safeUrl = (att.url || '').replace(/"/g, '&quot;');
                    content += `<img class="imageMessage" src="${safeUrl}" alt="" />`;
                } else {
                    content += createFileCardHTML(att.file_name || att.name || '', att.url || '');
                }
            });
        }

        if (msg.text) {
            const safeText = msg.text.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            content += `<span>${safeText}</span>`;
        }

        content += `</div>`;

        const timeSend = msgDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        content += `<div class="ptw_message_date">${timeSend}</div>`;

        messageEl.innerHTML = content;
        messagesContainer.appendChild(messageEl);
    }

    // ── File attachment staging ────────────────────────────────────────────
    let attachedFiles = [];

    function updateSendButton() {
        const input = container.querySelector('#ptw_text_field');
        const hasText = input.value.trim().length > 0;
        container.querySelector('.ptw_send_but').disabled = !hasText && attachedFiles.length === 0;
    }

    function renderAttachments() {
        const attachmentsEl = container.querySelector('#ptw_attachments');
        attachmentsEl.innerHTML = '';
        attachedFiles.forEach((file, index) => {
            const item = document.createElement('div');
            item.className = 'ptw_attachment_item';
            item.innerHTML = `
                <span class="ptw_attachment_name">${file.name}</span>
                <button type="button" class="ptw_attachment_remove" data-index="${index}" aria-label="Удалить файл">&times;</button>
            `;
            attachmentsEl.appendChild(item);
        });
        attachmentsEl.style.display = attachedFiles.length > 0 ? 'flex' : 'none';
        updateSendButton();
    }

    // ── Send ───────────────────────────────────────────────────────────────
    async function sendMessage() {
        const input = container.querySelector('#ptw_text_field');
        const text  = input.value.trim();

        if (!text && attachedFiles.length === 0) return;

        const filesToSend = [...attachedFiles];
        attachedFiles = [];
        renderAttachments();

        input.value = '';
        container.querySelector('.ptw_send_but').disabled = true;

        // Upload files
        for (const file of filesToSend) {
            try {
                await sendFileHttp(file);
            } catch (err) {
                console.error('[pt-widget] File upload failed', err);
            }
        }

        // Send text
        if (text) {
            try {
                await sendMessageHttp(text);
            } catch (err) {
                console.error('[pt-widget] Message send failed', err);
            }
        }

        // Render the just-sent message(s) immediately via the single render path
        // instead of drawing a local optimistic copy — no duplicate is possible
        // because every bubble is keyed by its server id.
        await fetchNewMessages();
        scrollBottom();
    }

    // ── Initial history load (first open only) ────────────────────────────
    async function loadHistory() {
        if (historyLoaded) return;
        historyLoaded = true;

        try {
            const data = await pollMessages(0);
            if (data && data.messages && data.messages.length) {
                const messagesContainer = container.querySelector('#ptw_messages');
                messagesContainer.innerHTML = '';
                lastRenderedDate = null;
                data.messages.forEach(msg => renderMessage(msg));
                lastMessageId = data.messages[data.messages.length - 1].id;
                scrollBottom();
            }
        } catch (err) {
            console.error('[pt-widget] Failed to load history', err);
        }
    }

    // ── Chat open / close ─────────────────────────────────────────────────
    butWidget.addEventListener('click', () => {
        butWidget.classList.add('ptw--hidden');
        container.classList.add('ptw--open');
        chatIsOpen = true;
        loadHistory().then(() => {
            startPolling();
            scrollBottom();
        });
    });

    container.querySelector('.ptw_close_but').addEventListener('click', () => {
        container.classList.remove('ptw--open');
        butWidget.classList.remove('ptw--hidden');
        chatIsOpen = false;
        stopPolling();
    });

    // ── Input events ───────────────────────────────────────────────────────
    container.querySelector('.ptw_send_but').addEventListener('click', sendMessage);

    container.querySelector('#ptw_text_field').addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    container.querySelector('.ptw_text_field').addEventListener('input', updateSendButton);

    container.querySelector('#ptw_file_field').addEventListener('change', (e) => {
        const newFiles = Array.from(e.target.files);
        const remaining = 5 - attachedFiles.length;
        attachedFiles = [...attachedFiles, ...newFiles.slice(0, remaining)];
        renderAttachments();
        e.target.value = '';
    });

    container.querySelector('#ptw_attachments').addEventListener('click', (e) => {
        const removeBtn = e.target.closest('.ptw_attachment_remove');
        if (removeBtn) {
            const index = parseInt(removeBtn.dataset.index, 10);
            attachedFiles.splice(index, 1);
            renderAttachments();
        }
    });

    // ── Messages click: lightbox + file download ──────────────────────────
    container.querySelector('#ptw_messages').addEventListener('click', (e) => {
        const img = e.target.closest('img.imageMessage');
        if (img && img.src) {
            openLightbox(img.src);
            return;
        }

        const card = e.target.closest('.ptw_file_card');
        if (card && card.dataset.fileUrl) {
            const a = document.createElement('a');
            a.href     = card.dataset.fileUrl;
            a.download = card.dataset.fileName || 'file';
            a.click();
        }
    });
})();
