<div id="atlas-chat-widget" class="chat-minimized">
    
    <div class="chat-header" onclick="toggleChat()">
        <div class="d-flex align-items-center">
            <i class="fa-brands fa-whatsapp me-2" style="font-size: 1.2rem;"></i> 
            <span class="chat-title fw-bold">Chat Interno</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span id="chat-global-badge" class="badge bg-danger rounded-pill" style="display:none;">0</span>
            
            <div class="dropdown" onclick="event.stopPropagation()">
                <i class="fa-solid fa-ellipsis-vertical text-white" style="cursor:pointer; padding: 5px;" data-bs-toggle="dropdown"></i>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                    <li><button class="dropdown-item small text-danger" onclick="limparTudo()"><i class="fa-solid fa-trash me-2"></i>Apagar Todas as Conversas</button></li>
                </ul>
            </div>

            <i class="fa-solid fa-chevron-up" id="chat-toggle-icon"></i>
        </div>
    </div>

    <div class="chat-body">
        
        <div id="chat-screen-list">
            <div class="p-2 bg-light border-bottom">
                <input type="text" id="chat-search" class="form-control form-control-sm rounded-pill" placeholder="Buscar (3 letras)..." autocomplete="off">
            </div>
            <div id="chat-users-list" class="list-group list-group-flush small">
                <div class="text-center p-3 text-muted">Carregando contatos...</div>
            </div>
        </div>

        <div id="chat-screen-conversation" style="display:none; height: 100%; flex-direction: column;">
            
            <div class="p-2 bg-light border-bottom d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <button class="btn btn-sm btn-link text-decoration-none text-dark fw-bold px-0 me-2" onclick="voltarLista()">
                        <i class="fa-solid fa-arrow-left"></i>
                    </button>
                    <strong id="chat-current-user-name" class="small text-truncate text-dark" style="max-width: 140px;">...</strong>
                </div>
                
                <button class="btn btn-sm btn-light text-danger border-0" title="Apagar esta conversa" onclick="limparConversaAtual()">
                    <i class="fa-regular fa-trash-can"></i>
                </button>
            </div>
            
            <div id="chat-msgs-area" class="flex-grow-1 p-2" style="overflow-y: auto; background: #e5ddd5; display:flex; flex-direction:column; gap:8px;">
            </div>

            <div class="p-2 bg-white border-top">
                <div id="file-preview" class="bg-light border rounded p-1 mb-1 d-flex justify-content-between align-items-center" style="display:none; font-size:0.75rem;">
                    <div class="text-truncate" style="max-width: 200px;">
                        <i class="fa-solid fa-paperclip text-primary me-1"></i> <span id="file-name-display" class="fw-bold"></span>
                    </div>
                    <i class="fa-solid fa-times text-danger" style="cursor:pointer; padding:2px;" onclick="limparArquivo()"></i>
                </div>

                <form onsubmit="enviarMsg(event)" class="d-flex gap-1 align-items-center">
                    <input type="hidden" id="chat-id-destinatario">
                    <label class="btn btn-sm text-secondary rounded-circle" style="cursor:pointer;" title="Anexar Arquivo">
                        <i class="fa-solid fa-paperclip" style="font-size: 1.1rem;"></i>
                        <input type="file" id="chat-file-input" style="display:none;" onchange="arquivoSelecionado(this)">
                    </label>
                    <input type="text" id="chat-input-msg" class="form-control form-control-sm rounded-pill" placeholder="Digite..." autocomplete="off">
                    <button type="submit" class="btn btn-sm btn-success rounded-circle" style="width:35px; height:35px; display:flex; align-items:center; justify-content:center;">
                        <i class="fa-solid fa-paper-plane" style="font-size: 0.8rem;"></i>
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>

<style>
    #atlas-chat-widget {
        position: fixed; bottom: 0; right: 20px; width: 320px;
        background: white; border-radius: 12px 12px 0 0;
        box-shadow: 0 5px 25px rgba(0,0,0,0.2); z-index: 9999;
        font-family: 'Inter', sans-serif;
        transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    }
    .chat-minimized { transform: translateY(400px); } 
    .chat-minimized .chat-header { cursor: pointer; border-radius: 12px 12px 0 0; }
    
    .chat-header {
        background: #0f172a; color: white; padding: 12px 15px;
        display: flex; justify-content: space-between; align-items: center;
        height: 50px; border-radius: 12px 12px 0 0;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .chat-body { height: 400px; display: flex; flex-direction: column; background: white; border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; }
    .chat-user-item { cursor: pointer; transition: 0.2s; padding: 10px 15px; border-bottom: 1px solid #f1f5f9; }
    .chat-user-item:hover { background: #f8fafc; }
    
    .cb-row { display: flex; width: 100%; }
    .cb-row.me { justify-content: flex-end; }
    .cb-row.other { justify-content: flex-start; }
    .cb-bubble { padding: 8px 12px; border-radius: 12px; font-size: 0.85rem; max-width: 85%; word-wrap: break-word; position: relative; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
    .cb-row.me .cb-bubble { background: #dcf8c6; color: #111; border-bottom-right-radius: 0; }
    .cb-row.other .cb-bubble { background: white; color: #111; border: 1px solid #e2e8f0; border-bottom-left-radius: 0; }
    
    /* Ajuste para status de leitura na mesma linha da hora */
    .cb-meta { display: flex; align-items: center; justify-content: flex-end; margin-top: 4px; gap: 4px; }
    .cb-time { font-size: 0.65rem; color: #666; line-height: 1; }
    .cb-status i { font-size: 0.7rem; }
    .status-lido { color: #34b7f1; } /* Azul do WhatsApp */
    .status-enviado { color: #999; } /* Cinza */

    .cb-file-link { display: flex; align-items: center; gap: 8px; background: rgba(0,0,0,0.05); padding: 6px 10px; border-radius: 6px; text-decoration: none; color: #0f172a; font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; border: 1px solid rgba(0,0,0,0.05); }
    .cb-file-link:hover { background: rgba(0,0,0,0.1); }
</style>

<script>
    let chatAberto = localStorage.getItem('atlas_chat_open') === 'true';
    let chatUserAtual = null; 
    let pollingTimer = null;
    const myId = <?= $_SESSION['usuario_id'] ?>;

    const widget = document.getElementById('atlas-chat-widget');
    const icon = document.getElementById('chat-toggle-icon');
    const badge = document.getElementById('chat-global-badge');

    document.addEventListener("DOMContentLoaded", () => {
        if(chatAberto) {
            widget.classList.remove('chat-minimized');
            icon.className = 'fa-solid fa-chevron-down';
            carregarListaUsuarios();
        } else {
            iniciarPollingNotificacao();
        }
        document.getElementById('chat-search').addEventListener('input', (e) => {
            carregarListaUsuarios(e.target.value);
        });
    });

    function toggleChat() {
        if (widget.classList.contains('chat-minimized')) {
            widget.classList.remove('chat-minimized');
            icon.className = 'fa-solid fa-chevron-down';
            localStorage.setItem('atlas_chat_open', 'true');
            chatAberto = true;
            badge.style.display = 'none'; 
            if(chatUserAtual) {
                abrirConversa(chatUserAtual, document.getElementById('chat-current-user-name').innerText);
            } else {
                carregarListaUsuarios();
            }
        } else {
            widget.classList.add('chat-minimized');
            icon.className = 'fa-solid fa-chevron-up';
            localStorage.setItem('atlas_chat_open', 'false');
            chatAberto = false;
            clearInterval(pollingTimer);
            iniciarPollingNotificacao();
        }
    }

    function carregarListaUsuarios(termo = '') {
        const fd = new FormData();
        fd.append('acao', 'buscar_usuarios');
        fd.append('termo', termo);
        fetch('chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(users => {
            const lista = document.getElementById('chat-users-list');
            lista.innerHTML = '';
            if(users.length === 0) {
                lista.innerHTML = '<div class="p-3 text-center text-muted small">Nenhum usuário encontrado.</div>';
                return;
            }
            users.forEach(u => {
                let badgeHtml = '';
                // Exibe contador de não lidas e prévia da última mensagem (opcional)
                if(u.nao_lidas > 0) badgeHtml = `<span class="badge bg-success rounded-pill ms-auto">${u.nao_lidas}</span>`;
                
                lista.innerHTML += `
                    <div class="chat-user-item d-flex align-items-center" onclick="abrirConversa(${u.id}, '${u.nome}')">
                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2" style="width:32px; height:32px; font-weight:bold; font-size:0.8rem;">
                            ${u.nome.charAt(0).toUpperCase()}
                        </div>
                        <div class="text-truncate" style="max-width: 170px;">
                            <div class="fw-bold text-dark" style="font-size:0.85rem;">${u.nome}</div>
                            <div class="text-muted small text-truncate" style="font-size:0.7rem;">${u.ultima_msg || u.email}</div>
                        </div>
                        ${badgeHtml}
                    </div>`;
            });
        });
    }

    function abrirConversa(id, nome) {
        chatUserAtual = id;
        document.getElementById('chat-screen-list').style.display = 'none';
        document.getElementById('chat-screen-conversation').style.display = 'flex';
        document.getElementById('chat-current-user-name').innerText = nome;
        document.getElementById('chat-id-destinatario').value = id;
        carregarMensagens();
        clearInterval(pollingTimer);
        pollingTimer = setInterval(carregarMensagens, 3000); // Polling a cada 3s
    }

    function voltarLista() {
        chatUserAtual = null;
        clearInterval(pollingTimer);
        document.getElementById('chat-screen-conversation').style.display = 'none';
        document.getElementById('chat-screen-list').style.display = 'block';
        carregarListaUsuarios();
    }

    function carregarMensagens() {
        if(!chatUserAtual) return;
        const fd = new FormData();
        fd.append('acao', 'carregar_conversa');
        fd.append('id_usuario', chatUserAtual);
        
        fetch('chat_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(msgs => {
            const area = document.getElementById('chat-msgs-area');
            area.innerHTML = '';
            const agora = new Date();
            
            msgs.forEach(m => {
                let tipo = (m.id_de == myId) ? 'me' : 'other'; 
                let htmlArquivo = '';
                
                // Tratamento de Arquivo
                if (m.arquivo_nome) {
                    let dataEnvio = new Date(m.data_envio.replace(' ', 'T'));
                    let diffSegundos = (agora - dataEnvio) / 1000;
                    if (diffSegundos < 3600) { // Link válido por 1h (exemplo)
                        htmlArquivo = `
                            <a href="chat_download.php?id=${m.id}" target="_blank" class="cb-file-link">
                                <i class="fa-solid fa-file-arrow-down text-danger"></i> 
                                <div style="overflow:hidden;"><div class="text-truncate" style="max-width:180px;">${m.arquivo_nome}</div><small class="text-muted fw-normal" style="font-size:0.6rem;">(${formatBytes(m.arquivo_tamanho)})</small></div>
                            </a>`;
                    } else {
                        htmlArquivo = `
                            <div class="cb-file-link" style="opacity: 0.6; cursor: not-allowed; background: #fee2e2; border-color: #fca5a5;">
                                <i class="fa-solid fa-ban text-secondary"></i> 
                                <div style="overflow:hidden;"><div class="text-truncate text-decoration-line-through text-muted" style="max-width:180px;">${m.arquivo_nome}</div><small class="text-danger fw-bold" style="font-size:0.6rem;">Arquivo Expirado</small></div>
                            </div>`;
                    }
                }

                // STATUS DE LEITURA (Só aparece nas mensagens que EU enviei)
                let statusIcon = '';
                if(tipo === 'me') {
                    // Se lida=1 mostra azul, senão cinza
                    let colorClass = (m.lida == 1) ? 'status-lido' : 'status-enviado';
                    statusIcon = `<span class="cb-status ${colorClass} ms-1"><i class="fa-solid fa-check-double"></i></span>`;
                }

                let html = `
                    <div class="cb-row ${tipo}">
                        <div class="cb-bubble">
                            ${htmlArquivo}
                            ${m.mensagem}
                            <div class="cb-meta">
                                <span class="cb-time">${m.data_envio.substring(11, 16)}</span>
                                ${statusIcon}
                            </div>
                        </div>
                    </div>`;
                area.innerHTML += html;
            });
            // Rola para o fim
            area.scrollTop = area.scrollHeight;
        });
    }

    function enviarMsg(e) {
        e.preventDefault();
        const input = document.getElementById('chat-input-msg');
        const fileInput = document.getElementById('chat-file-input');
        const idPara = document.getElementById('chat-id-destinatario').value;
        const msg = input.value;
        if(!msg.trim() && !fileInput.files.length) return;
        
        const fd = new FormData();
        fd.append('acao', 'enviar');
        fd.append('id_para', idPara);
        fd.append('mensagem', msg);
        if (fileInput.files.length > 0) fd.append('arquivo', fileInput.files[0]);

        input.value = '';
        limparArquivo();
        
        // Feedback visual imediato
        const area = document.getElementById('chat-msgs-area');
        area.innerHTML += `<div class="cb-row me"><div class="cb-bubble" style="opacity:0.6"><i class="fa-solid fa-spinner fa-spin"></i> Enviando...</div></div>`;
        area.scrollTop = area.scrollHeight;

        fetch('chat_api.php', { method: 'POST', body: fd }).then(r => r.json()).then(res => {
            if(res.erro) alert(res.erro);
            carregarMensagens(); // Recarrega para pegar o status e ID real
        });
    }

    // --- FUNÇÕES DE EXCLUSÃO ---
    function limparConversaAtual() {
        if(!confirm('Tem certeza que deseja apagar o histórico desta conversa? Essa ação é somente para você.')) return;
        const fd = new FormData();
        fd.append('acao', 'limpar_conversa');
        fd.append('id_outro', chatUserAtual);
        fetch('chat_api.php', { method: 'POST', body: fd }).then(() => carregarMensagens());
    }

    function limparTudo() {
        if(!confirm('Tem certeza que deseja apagar TODAS as suas conversas? Essa ação é irreversível.')) return;
        const fd = new FormData();
        fd.append('acao', 'limpar_tudo');
        fetch('chat_api.php', { method: 'POST', body: fd }).then(() => {
            alert('Histórico limpo.');
            if(chatUserAtual) carregarMensagens(); else carregarListaUsuarios();
        });
    }

    // UTILS
    function arquivoSelecionado(input) {
        if (input.files && input.files[0]) {
            document.getElementById('file-preview').style.display = 'flex';
            document.getElementById('file-name-display').innerText = input.files[0].name;
            document.getElementById('chat-input-msg').focus();
        }
    }
    function limparArquivo() {
        document.getElementById('chat-file-input').value = '';
        document.getElementById('file-preview').style.display = 'none';
    }
    function formatBytes(bytes, decimals = 0) {
        if (!+bytes) return '0 B';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['B', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
    }
    function iniciarPollingNotificacao() {
        if(!chatAberto) {
            setInterval(() => {
                if(chatAberto) return;
                const fd = new FormData();
                fd.append('acao', 'check_notificacoes');
                fetch('chat_api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if(d.total_nao_lidas > 0) {
                        badge.innerText = d.total_nao_lidas;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                });
            }, 5000);
        }
    }
</script>