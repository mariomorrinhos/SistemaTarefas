<?php
// chat/index.php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: ../index.php"); exit; }

// Regra de bloqueio de público
if ($_SESSION['usuario_nivel'] >= 7) { 
    echo "<body style='background:#0f172a; color:white; display:flex; justify-content:center; align-items:center; height:100vh; font-family:sans-serif;'>
            <div style='text-align:center'><h3>Acesso Restrito</h3><p>Usuários públicos não têm acesso ao chat interno.</p>
            <button onclick='window.close()' style='padding:10px 20px; cursor:pointer;'>Fechar</button></div>
          </body>"; 
    exit; 
}

$meu_id = $_SESSION['usuario_id'];
$meu_nome = explode(' ', $_SESSION['usuario_nome'])[0];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Chat Interno - Atlas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #e2e8f0; font-family: 'Inter', sans-serif; height: 100vh; overflow: hidden; margin: 0; }

        /* TELA DE LOGIN */
        #login-screen { position: fixed; top:0; left:0; width:100%; height:100%; background: #0f172a; z-index: 9999; display: flex; align-items: center; justify-content: center; }
        .login-card { background: white; padding: 2.5rem; border-radius: 24px; width: 100%; max-width: 420px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); }

        /* LAYOUT DO CHAT */
        .chat-container { display: flex; height: 100vh; max-width: 1920px; margin: 0 auto; background: white; }
        
        /* SIDEBAR (USUÁRIOS) */
        .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; border-right: 1px solid #1e293b; flex-shrink: 0; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); background: #020617; }
        .user-list { flex-grow: 1; overflow-y: auto; padding: 10px; }
        .user-item { padding: 10px 15px; border-radius: 8px; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 10px; color: #cbd5e1; font-size: 0.9rem; margin-bottom: 2px; }
        .user-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .user-item.active { background: #004d26; color: white; border: 1px solid #4ade80; }
        .status-dot { width: 8px; height: 8px; background: #22c55e; border-radius: 50%; box-shadow: 0 0 8px #22c55e; }

        /* ÁREA PRINCIPAL */
        .main-chat { flex-grow: 1; display: flex; flex-direction: column; background: #f1f5f9; position: relative; }
        
        /* HEADER */
        .chat-header { padding: 0 25px; background: white; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; height: 70px; flex-shrink: 0; }
        
        /* MENSAGENS */
        .messages-area { flex-grow: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 10px; scroll-behavior: smooth; }
        
        .msg-row { display: flex; flex-direction: column; max-width: 75%; }
        .msg-row.mine { align-self: flex-end; align-items: flex-end; }
        .msg-row.others { align-self: flex-start; align-items: flex-start; }
        .msg-row.system { align-self: center; align-items: center; max-width: 100%; margin: 15px 0; opacity: 0.8; }

        .msg-bubble { padding: 10px 16px; border-radius: 16px; position: relative; font-size: 0.95rem; line-height: 1.5; word-wrap: break-word; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        
        .msg-row.mine .msg-bubble { background: #004d26; color: white; border-bottom-right-radius: 2px; }
        .msg-row.others .msg-bubble { background: white; border: 1px solid #e2e8f0; color: #1e293b; border-bottom-left-radius: 2px; }
        
        /* Estilo Privado */
        .msg-row.private .msg-bubble { border: 2px solid #f59e0b; }
        .msg-row.private.others .msg-bubble { background: #fffbeb; } /* Fundo amarelado */

        .msg-info { font-size: 0.65rem; margin-top: 4px; opacity: 0.7; display: flex; align-items: center; gap: 5px; }
        .system-badge { background: #cbd5e1; color: #475569; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }

        /* INPUT AREA */
        .input-area { padding: 20px; background: white; border-top: 1px solid #e2e8f0; flex-shrink: 0; }
        .input-wrapper { display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 5px 10px 5px 20px; border-radius: 50px; border: 1px solid #cbd5e1; transition: border 0.2s; }
        .input-wrapper:focus-within { border-color: #004d26; box-shadow: 0 0 0 3px rgba(0, 77, 38, 0.1); }
        .chat-input { border: none; background: transparent; flex-grow: 1; outline: none; padding: 10px 0; font-size: 1rem; color: #334155; }
        .btn-send { background: #004d26; color: white; border: none; width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: 0.2s; flex-shrink: 0; }
        .btn-send:hover { transform: scale(1.05); background: #0f172a; }

        /* ALVO PRIVADO */
        .target-bar { display: flex; align-items: center; margin-bottom: 10px; font-size: 0.85rem; color: #64748b; }
        .target-tag { background: #e2e8f0; padding: 2px 10px; border-radius: 4px; margin-left: 5px; font-weight: bold; color: #334155; transition: 0.2s; cursor: pointer; }
        .target-tag.private { background: #fef3c7; color: #d97706; border: 1px solid #f59e0b; }
    </style>
</head>
<body>

    <div id="login-screen">
        <div class="login-card">
            <div class="mb-4">
                <i class="fa-solid fa-comments fa-3x text-success mb-3"></i>
                <h3 class="fw-bold text-dark">Acesso Seguro</h3>
                <p class="text-muted small">Selecione a sala e digite o token da unidade.</p>
            </div>
            
            <div class="mb-3 text-start">
                <label class="small fw-bold text-muted mb-1">Sala</label>
                <select id="sala_select" class="form-select rounded-3 py-2">
                    <option value="habitacao">Secretaria de Habitação</option>
                    <option value="posturas">Gerência de Posturas</option>
                </select>
            </div>

            <div class="mb-4 text-start">
                <label class="small fw-bold text-muted mb-1">Token de Acesso</label>
                <input type="password" id="token_input" class="form-control rounded-3 py-2" placeholder="Digite o token...">
            </div>

            <button onclick="entrarSala()" class="btn btn-success w-100 rounded-pill fw-bold py-2 shadow-sm">Entrar na Sala</button>
            <div id="login_error" class="text-danger small mt-3 fw-bold"></div>
        </div>
    </div>

    <div class="chat-container" id="chat-interface" style="display:none;">
        
        <div class="sidebar">
            <div class="sidebar-header">
                <h6 class="m-0 fw-bold text-success text-uppercase ls-1"><i class="fa-solid fa-wifi me-2"></i>Online</h6>
            </div>
            <div class="user-list" id="users_list">
                </div>
            <div class="p-3 border-top border-secondary bg-dark">
                <button onclick="sairSala()" class="btn btn-outline-danger btn-sm w-100 rounded-pill"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Sair</button>
            </div>
        </div>

        <div class="main-chat">
            <div class="chat-header">
                <div>
                    <h5 class="fw-bold m-0 text-dark" id="room_title">Carregando...</h5>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill">Tempo Real</span>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <div class="text-end d-none d-md-block">
                        <span class="d-block fw-bold small text-dark"><?= $_SESSION['usuario_nome'] ?></span>
                        <span class="text-muted small" style="font-size: 0.7rem;">Conectado</span>
                    </div>
                    <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center fw-bold" style="width: 40px; height: 40px;">
                        <?= strtoupper(substr($meu_nome, 0, 1)) ?>
                    </div>
                </div>
            </div>

            <div class="messages-area" id="msg_area">
                </div>

            <div class="input-area">
                <div class="target-bar">
                    Enviando para: 
                    <span id="target_label" class="target-tag" onclick="setDestinatario(0, 'Todos')">Todos</span>
                    <span id="cancel_private" style="display:none; cursor:pointer; color:#ef4444; margin-left:10px; font-size:0.7rem;" onclick="setDestinatario(0, 'Todos')">(Cancelar Privado)</span>
                </div>
                
                <form onsubmit="enviarMensagem(event)" class="input-wrapper">
                    <input type="hidden" id="destinatario_id" value="0">
                    <button type="button" class="btn btn-sm text-secondary border-0" onclick="document.getElementById('msg_input').focus()" title="Use Win+. para emojis">😃</button>
                    <input type="text" id="msg_input" class="chat-input" placeholder="Digite sua mensagem..." autocomplete="off">
                    <button type="submit" class="btn-send"><i class="fa-solid fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // CONFIGURAÇÕES GLOBAIS
        const MEU_ID = <?= $meu_id ?>;
        let lastMsgId = 0;
        let pollingInterval = null;
        let originalTitle = document.title;
        let isTabActive = true;
        let piscarInterval = null;

        // Detecta foco na aba para limpar notificações
        document.addEventListener("visibilitychange", () => {
            if (!document.hidden) {
                isTabActive = true;
                document.title = originalTitle;
                clearInterval(piscarInterval);
            } else {
                isTabActive = false;
            }
        });

        // 1. LOGIN NA SALA
        function entrarSala() {
            const sala = document.getElementById('sala_select').value;
            const token = document.getElementById('token_input').value;
            const btn = document.querySelector('#login-screen button');
            const errDiv = document.getElementById('login_error');

            if(!token) { errDiv.innerText = "Digite o token."; return; }

            btn.disabled = true;
            errDiv.innerText = '';

            const data = new FormData();
            data.append('acao', 'login');
            data.append('sala', sala);
            data.append('token', token);

            fetch('api.php', { method: 'POST', body: data })
            .then(res => res.json())
            .then(res => {
                if(res.status === 'ok') {
                    document.getElementById('login-screen').style.display = 'none';
                    document.getElementById('chat-interface').style.display = 'flex';
                    
                    const nomeSala = (sala === 'habitacao') ? 'Secretaria de Habitação' : 'Gerência de Posturas';
                    document.getElementById('room_title').innerHTML = `<i class="fa-solid fa-building-user me-2 text-success"></i>` + nomeSala;
                    
                    iniciarChat();
                } else {
                    errDiv.innerText = res.msg;
                    btn.disabled = false;
                }
            })
            .catch(() => { errDiv.innerText = "Erro de conexão."; btn.disabled = false; });
        }

        // 2. INICIAR POLLING (1 SEGUNDO)
        function iniciarChat() {
            carregarDados();
            pollingInterval = setInterval(carregarDados, 1000); 
            document.getElementById('msg_input').focus();
        }

        // 3. CARREGAR DADOS
        function carregarDados() {
            const data = new FormData();
            data.append('acao', 'atualizar');
            data.append('last_id', lastMsgId);

            fetch('api.php', { method: 'POST', body: data })
            .then(res => res.json())
            .then(res => {
                // Se der erro (ex: sessão expirou), recarrega
                if(res.error) { location.reload(); return; }

                renderUsuarios(res.users);
                
                if(res.msgs.length > 0) {
                    renderMensagens(res.msgs);
                    // Atualiza ID para não buscar repetidas
                    lastMsgId = res.msgs[res.msgs.length - 1].id;
                    scrollToBottom();
                }
            })
            .catch(e => console.error("Polling error", e));
        }

        // 4. RENDERIZAR USUÁRIOS
        function renderUsuarios(users) {
            const list = document.getElementById('users_list');
            const currentDest = document.getElementById('destinatario_id').value;
            
            // Mantém a lista limpa e recria
            let html = '';

            // Opção "Todos"
            let activeClass = (currentDest == 0) ? 'active' : '';
            html += `<div class="user-item ${activeClass}" onclick="setDestinatario(0, 'Todos')">
                        <div class="status-dot" style="background:white; border:2px solid #ccc;"></div> Todos (Público)
                     </div>`;

            users.forEach(u => {
                if(u.usuario_id != MEU_ID) {
                    let active = (currentDest == u.usuario_id) ? 'active' : '';
                    html += `<div class="user-item ${active}" onclick="setDestinatario(${u.usuario_id}, '${u.nome}')">
                                <div class="status-dot"></div> ${u.nome}
                             </div>`;
                }
            });
            list.innerHTML = html;
        }

        // 5. DEFINIR DESTINATÁRIO (PRIVADO)
        function setDestinatario(id, nome) {
            document.getElementById('destinatario_id').value = id;
            const label = document.getElementById('target_label');
            const cancelBtn = document.getElementById('cancel_private');
            
            if(id == 0) {
                label.innerHTML = "Todos";
                label.classList.remove('private');
                cancelBtn.style.display = 'none';
            } else {
                label.innerHTML = `<i class="fa-solid fa-lock me-1"></i> ${nome}`;
                label.classList.add('private');
                cancelBtn.style.display = 'inline';
            }
            document.getElementById('msg_input').focus();
        }

        // 6. RENDERIZAR MENSAGENS
        function renderMensagens(msgs) {
            const area = document.getElementById('msg_area');
            let novaPrivadaRecebida = false;

            msgs.forEach(m => {
                // MENSAGEM DE SISTEMA (Entrada/Saída)
                if(m.tipo === 'entrada') {
                    area.innerHTML += `<div class="msg-row system"><span class="system-badge"><i class="fa-solid fa-arrow-right-to-bracket text-success"></i> ${m.nome_remetente} entrou</span></div>`;
                } 
                else if (m.tipo === 'saida') {
                    area.innerHTML += `<div class="msg-row system"><span class="system-badge"><i class="fa-solid fa-arrow-right-from-bracket text-danger"></i> ${m.nome_remetente} saiu</span></div>`;
                } 
                else {
                    // MENSAGEM DE TEXTO
                    let classe = (m.id_remetente == MEU_ID) ? 'mine' : 'others';
                    let privateClass = (m.id_destinatario != 0) ? 'private' : '';
                    let iconLock = (m.id_destinatario != 0) ? '<i class="fa-solid fa-lock text-warning me-1"></i>' : '';
                    let nomeExibicao = (m.id_remetente == MEU_ID) ? 'Você' : m.nome_remetente;
                    let hora = new Date(m.data_envio).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

                    // Detecta se recebi privada
                    if (m.id_remetente != MEU_ID && m.id_destinatario == MEU_ID) {
                        novaPrivadaRecebida = true;
                    }

                    let html = `
                        <div class="msg-row ${classe} ${privateClass}">
                            <div class="msg-bubble">
                                ${m.mensagem}
                            </div>
                            <div class="msg-info">
                                ${iconLock} ${nomeExibicao} • ${hora}
                            </div>
                        </div>
                    `;
                    area.innerHTML += html;
                }
            });

            // Notifica se houver privada e a aba não estiver ativa
            if(novaPrivadaRecebida && !isTabActive) {
                notificarAba();
            }
        }

        // 7. ENVIAR MENSAGEM
        function enviarMensagem(e) {
            e.preventDefault();
            const input = document.getElementById('msg_input');
            const dest = document.getElementById('destinatario_id').value;
            const msg = input.value.trim();

            if(!msg) return;

            input.value = ''; // Limpa rápido para UX

            const data = new FormData();
            data.append('acao', 'enviar');
            data.append('mensagem', msg);
            data.append('destinatario', dest);

            fetch('api.php', { method: 'POST', body: data });
            // Não precisa renderizar aqui, o polling vai pegar em < 1s
        }

        // 8. SCROLL AUTOMÁTICO
        function scrollToBottom() {
            const area = document.getElementById('msg_area');
            area.scrollTop = area.scrollHeight;
        }

        // 9. ALERTA NA ABA
        function notificarAba() {
            let alternar = false;
            clearInterval(piscarInterval);
            piscarInterval = setInterval(() => {
                document.title = alternar ? "🔔 Nova Mensagem Privada!" : "Atlas Chat";
                alternar = !alternar;
            }, 1000);
        }

        // 10. SAIR
        function sairSala() {
            if(confirm("Deseja sair do chat? As mensagens serão perdidas.")) {
                const data = new FormData();
                data.append('acao', 'sair');
                navigator.sendBeacon('api.php', data); // Garante envio mesmo fechando
                window.close();
                window.location.href = '../dashboard.php';
            }
        }

        // Fecha conexão ao fechar janela
        window.addEventListener("beforeunload", function() {
            const data = new FormData();
            data.append('acao', 'sair');
            navigator.sendBeacon('api.php', data);
        });

    </script>
</body>
</html>