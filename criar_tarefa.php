<?php
// criar_tarefa.php
session_start();
require_once 'config/database/conexao.php';

// Verifica login
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$msg = "";
$erro = "";

// Buscar usuários da equipe (Para tarefas e eventos terceirizados)
$usuarios_equipe = [];
if ($_SESSION['usuario_nivel'] <= 4) {
    $stmt = $pdo->query("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC");
    $usuarios_equipe = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Buscar Categorias (TI primeiro, depois alfabético)
$categorias = $pdo->query("
    SELECT * FROM categorias 
    ORDER BY 
        CASE WHEN id = 13 THEN 0 ELSE 1 END, 
        nome ASC
")->fetchAll();

// -------------------------------------------------------------------------
// PROCESSAMENTO DO FORMULÁRIO
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tipo_cadastro = $_POST['tipo_cadastro']; // 'tarefa' ou 'evento'
    $titulo = trim($_POST['titulo']);
    $descricao = trim($_POST['descricao']);
    $data_hora = $_POST['prazo']; // Usado tanto para prazo da tarefa quanto inicio do evento

    if (empty($titulo) || empty($data_hora)) {
        $erro = "Preencha o título e a data obrigatória.";
    } elseif ($tipo_cadastro == 'tarefa' && empty($_POST['categoria_id'])) {
        // Validação back-end da categoria obrigatória
        $erro = "A categoria é obrigatória para o cadastro de tarefas.";
    } else {
        
        // =======================================================
        // 1: CRIAR EVENTO
        // =======================================================
        if ($tipo_cadastro == 'evento') {
            
            $termino = !empty($_POST['termino_evento']) ? $_POST['termino_evento'] : null;
            $numero_tarefa = !empty($_POST['numero_tarefa_evento']) ? trim($_POST['numero_tarefa_evento']) : null;
            
            // Define quem receberá o evento
            $participantes = [];
            if ($_SESSION['usuario_nivel'] <= 4 && isset($_POST['participantes_evento'])) {
                $participantes = $_POST['participantes_evento'];
            } else {
                // Se não for nível superior ou vier vazio, é apenas para ele mesmo
                $participantes = [$_SESSION['usuario_id']];
            }

            if (empty($participantes)) {
                $erro = "Selecione pelo menos um participante para o evento.";
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    foreach ($participantes as $uid_evento) {
                        $stmt = $pdo->prepare("INSERT INTO eventos (usuario_id, titulo, inicio, termino, numero_tarefa, descricao, status) VALUES (?, ?, ?, ?, ?, ?, 'pendente')");
                        $stmt->execute([$uid_evento, $titulo, $data_hora, $termino, $numero_tarefa, $descricao]);
                        
                        // LÓGICA DE NOTIFICAÇÃO (Caso o evento seja para um terceiro)
                        if ($uid_evento != $_SESSION['usuario_id']) {
                            $nome_criador = explode(' ', $_SESSION['usuario_nome'])[0];
                            $data_formatada = date('d/m/Y \à\s H:i', strtotime($data_hora));
                            $msg_notificacao = "$nome_criador agendou um compromisso com você: $titulo ($data_formatada)";
                            
                            $pdo->prepare("INSERT INTO notificacoes (usuario_id, mensagem, link) VALUES (?, ?, 'minhas_tarefas.php')")
                                ->execute([$uid_evento, $msg_notificacao]);
                        }
                    }

                    $pdo->commit();
                    $msg = "Evento agendado com sucesso! <a href='minhas_tarefas.php' class='alert-link'>Ver Agenda</a>";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $erro = "Erro ao salvar evento: " . $e->getMessage();
                }
            }
        } 
        
        // =======================================================
        // 2: CRIAR TAREFA
        // =======================================================
        else {
            $prioridade = $_POST['prioridade'];
            $categoria_id = intval($_POST['categoria_id']);
            $numero_prodata = !empty($_POST['numero_prodata']) ? trim($_POST['numero_prodata']) : null;
            $nome_interessado = !empty($_POST['nome_interessado']) ? trim($_POST['nome_interessado']) : null;
            $endereco = !empty($_POST['endereco']) ? trim($_POST['endereco']) : null;
            $cci = !empty($_POST['cci']) ? trim($_POST['cci']) : null;
            
            // Responsável
            $responsavel_id = $_SESSION['usuario_id'];
            if (isset($_POST['responsavel_id']) && !empty($_POST['responsavel_id']) && $_SESSION['usuario_nivel'] <= 4) {
                $responsavel_id = $_POST['responsavel_id'];
            }

            try {
                $pdo->beginTransaction(); 

                // Gerar Protocolo
                $anoAtual = date('Y');
                $stmt = $pdo->prepare("SELECT protocolo FROM tarefas WHERE protocolo LIKE ? ORDER BY protocolo DESC LIMIT 1");
                $stmt->execute([$anoAtual . '%']);
                $ultimoProtocolo = $stmt->fetchColumn();

                $sequencia = $ultimoProtocolo ? intval(substr($ultimoProtocolo, 4)) + 1 : 1;
                $novoProtocolo = $anoAtual . str_pad($sequencia, 4, '0', STR_PAD_LEFT);

                // Insert Tarefa
                $sql = "INSERT INTO tarefas (protocolo, titulo, descricao, prazo, prioridade, usuario_id, criado_por, categoria_id, numero_prodata, nome_interessado, endereco, cci) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $novoProtocolo, $titulo, $descricao, $data_hora, $prioridade, $responsavel_id, $_SESSION['usuario_id'],
                    $categoria_id, $numero_prodata, $nome_interessado, $endereco, $cci
                ]);
                
                $id_tarefa_criada = $pdo->lastInsertId();

                // Upload de Anexos
                if (isset($_FILES['anexos']) && count($_FILES['anexos']['name']) > 0) {
                    $totalArquivos = count($_FILES['anexos']['name']);
                    for ($i = 0; $i < $totalArquivos; $i++) {
                        if ($_FILES['anexos']['error'][$i] === UPLOAD_ERR_OK) {
                            $nomeArquivo = $_FILES['anexos']['name'][$i];
                            $tmpName = $_FILES['anexos']['tmp_name'][$i];
                            $tamanho = $_FILES['anexos']['size'][$i];
                            
                            if ($tamanho > 4194304) throw new Exception("Arquivo '$nomeArquivo' excede 4MB.");

                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mimeType = finfo_file($finfo, $tmpName);
                            finfo_close($finfo);

                            $permitidos = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
                            if (!in_array($mimeType, $permitidos)) throw new Exception("Arquivo '$nomeArquivo' inválido. Use PDF ou Imagem.");

                            $conteudoBinario = file_get_contents($tmpName);
                            
                            try {
                                $stmtAnexo = $pdo->prepare("INSERT INTO tarefa_anexos (tarefa_id, nome_arquivo, tamanho, dados_arquivo, tipo_arquivo) VALUES (?, ?, ?, ?, ?)");
                                $stmtAnexo->execute([$id_tarefa_criada, $nomeArquivo, $tamanho, $conteudoBinario, $mimeType]);
                            } catch(PDOException $e) {
                                $stmtAnexo = $pdo->prepare("INSERT INTO tarefa_anexos (tarefa_id, nome_arquivo, tamanho, dados_arquivo) VALUES (?, ?, ?, ?)");
                                $stmtAnexo->execute([$id_tarefa_criada, $nomeArquivo, $tamanho, $conteudoBinario]);
                            }
                        }
                    }
                }

                $pdo->commit(); 
                $msg = "Tarefa criada! Protocolo: <strong>#" . $novoProtocolo . "</strong>";
            
            } catch (Exception $e) {
                $pdo->rollBack(); 
                $erro = "Erro ao salvar: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Cadastro - HabitaNet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        
        body { background-color: #f0f2f5; font-family: 'Inter', sans-serif; min-height: 100vh; }

        .navbar-glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid rgba(0, 77, 38, 0.1);
        }
        .navbar-brand { font-weight: 800; color: #004d26 !important; }

        .form-card {
            background: white; border-radius: 20px; padding: 2.5rem;
            box-shadow: 0 10px 40px -10px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.02); position: relative; overflow: hidden;
        }
        
        /* Indicador de Tipo no Topo do Card */
        .form-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 6px;
            background: linear-gradient(90deg, #2193b0, #6dd5ed); 
            transition: background 0.3s ease;
            z-index: 5;
        }
        .form-card.mode-evento::before {
            background: linear-gradient(90deg, #8b5cf6, #d946ef); 
        }

        label { font-weight: 600; font-size: 0.85rem; color: #4a5568; margin-bottom: 0.4rem; }
        .form-control, .form-select { border-radius: 12px; padding: 12px; border: 1px solid #e2e8f0; background-color: #f8fafc; }
        .form-control:focus, .form-select:focus { background-color: white; border-color: #2193b0; box-shadow: 0 0 0 3px rgba(33, 147, 176, 0.1); }

        .btn-criar {
            background: linear-gradient(90deg, #2193b0, #6dd5ed); border: none; color: white;
            padding: 12px 24px; border-radius: 12px; font-weight: 600; transition: all 0.3s;
        }
        .btn-criar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(33, 147, 176, 0.3); color: white; }
        
        /* Estilo Botão Evento */
        .btn-criar.btn-evento-mode {
            background: linear-gradient(90deg, #8b5cf6, #d946ef);
        }
        .btn-criar.btn-evento-mode:hover { box-shadow: 0 5px 15px rgba(139, 92, 246, 0.3); }

        .btn-voltar { color: #64748b; text-decoration: none; font-weight: 600; }
        .btn-voltar:hover { color: #004d26; }
        
        .section-title { font-size: 0.8rem; text-transform: uppercase; color: #94a3b8; font-weight: 700; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 5px; margin-top: 1.5rem; }

        /* Switcher Toggle */
        .type-switcher {
            background: #f1f5f9; border-radius: 50px; padding: 4px; display: flex;
            margin-bottom: 2rem; border: 1px solid #e2e8f0; width: 100%;
        }
        .type-option {
            flex: 1; text-align: center; padding: 10px; border-radius: 50px; cursor: pointer;
            font-weight: 700; font-size: 0.9rem; color: #64748b; transition: all 0.3s;
        }
        .type-option.active {
            background: white; color: #0f172a; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .type-option.active[data-type="tarefa"] { color: #0284c7; }
        .type-option.active[data-type="evento"] { color: #8b5cf6; }

        /* Estilos Participantes (Chips) */
        .participant-chip {
            background-color: #ede9fe; color: #6d28d9; padding: 5px 12px; border-radius: 50px;
            font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; border: 1px solid #ddd6fe;
        }
        .participant-chip .remove-chip {
            cursor: pointer; color: #8b5cf6; border-radius: 50%; display: flex; align-items: center; justify-content: center; width: 16px; height: 16px; transition: all 0.2s;
        }
        .participant-chip .remove-chip:hover { background-color: #8b5cf6; color: white; }

        .user-suggestions-box {
            position: absolute; width: 100%; z-index: 1000; background: white; 
            border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); 
            max-height: 200px; overflow-y: auto; display: none; margin-top: 5px;
        }
        .suggestion-item-event { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; }
        .suggestion-item-event:hover { background: #f8fafc; color: #8b5cf6; font-weight: bold; }
        .suggestion-item-event:last-child { border-bottom: none; }
    </style>
</head>
<body>

    <nav class="navbar navbar-glass fixed-top">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fa-solid fa-layer-group me-2"></i>HabitaNet Tarefas
            </a>
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class="fa-solid fa-times"></i> Fechar
            </a>
        </div>
    </nav>

    <div style="margin-top: 100px;"></div>

    <div class="container pb-5">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="fw-bold text-dark mb-0" id="page-title">Nova Tarefa</h3>
                    <a href="dashboard.php" class="btn-voltar"><i class="fa-solid fa-arrow-left me-2"></i> Voltar</a>
                </div>

                <?php if($msg): ?>
                    <div class="alert alert-success rounded-4 shadow-sm border-0 d-flex align-items-center mb-4">
                        <i class="fa-solid fa-check-circle fs-4 me-3"></i>
                        <div><?= $msg ?></div>
                    </div>
                <?php endif; ?>

                <?php if($erro): ?>
                    <div class="alert alert-danger rounded-4 shadow-sm border-0 mb-4">
                        <i class="fa-solid fa-circle-exclamation me-2"></i> <?= $erro ?>
                    </div>
                <?php endif; ?>

                <div class="form-card" id="mainCard">
                    <form method="POST" enctype="multipart/form-data" autocomplete="off">
                        
                        <div class="type-switcher">
                            <div class="type-option active" id="optTarefa" data-type="tarefa" onclick="setTipo('tarefa')">
                                <i class="fa-solid fa-clipboard-check me-2"></i> Tarefa Profissional
                            </div>
                            <div class="type-option" id="optEvento" data-type="evento" onclick="setTipo('evento')">
                                <i class="fa-solid fa-calendar-star me-2"></i> Evento / Compromisso
                            </div>
                        </div>
                        <input type="hidden" name="tipo_cadastro" id="tipo_cadastro" value="tarefa">

                        <div class="row g-3">
                            
                            <div class="col-12">
                                <label for="titulo" id="lblTitulo">O que precisa ser feito?</label>
                                <input type="text" name="titulo" id="titulo" class="form-control" placeholder="Digite um título..." required autofocus>
                            </div>

                            <div class="col-md-6">
                                <label for="prazo" id="lblPrazo">Prazo de Entrega</label>
                                <input type="datetime-local" name="prazo" id="prazo" class="form-control" required>
                            </div>

                            <div id="campos-tarefa" class="col-md-6 m-0 p-0" style="padding-left: calc(var(--bs-gutter-x) * .5) !important;">
                                <label for="categoria_id">Categoria <span class="text-danger">*</span></label>
                                <select name="categoria_id" id="categoria_id" class="form-select" required>
                                    <option value="" disabled selected>Selecione uma categoria...</option>
                                    <?php foreach($categorias as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="campos-evento" class="col-12 m-0 p-0 mt-3" style="display: none;">
                                <div class="p-3 bg-light rounded-4 border">
                                    
                                    <?php if($_SESSION['usuario_nivel'] <= 4): ?>
                                    <h6 class="fw-bold mb-3" style="color: #8b5cf6;"><i class="fa-solid fa-users me-2"></i>Participantes</h6>
                                    
                                    <div id="participantes_container" class="mb-3 d-flex flex-wrap gap-2">
                                        </div>
                                    
                                    <div class="position-relative mb-4">
                                        <input type="text" id="busca_usuario_evento" class="form-control form-control-sm rounded-pill" placeholder="Digite para adicionar participantes (3 letras)...">
                                        <div id="sugestoes_usuarios_evento" class="user-suggestions-box"></div>
                                    </div>
                                    
                                    <div id="inputs_hidden_participantes"></div>
                                    <?php endif; ?>

                                    <div class="row g-3 border-top pt-2">
                                        <div class="col-md-6">
                                            <label class="small fw-bold text-muted">Término (Opcional)</label>
                                            <input type="datetime-local" name="termino_evento" class="form-control form-control-sm rounded-3">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="small fw-bold text-muted">Nº da Tarefa Relacionada (Opc. )</label>
                                            <input type="text" name="numero_tarefa_evento" class="form-control form-control-sm rounded-3" placeholder="Ex: 20260001">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <label for="descricao">Detalhes / Descrição</label>
                                <textarea name="descricao" id="descricao" class="form-control" rows="4" placeholder="Descreva os detalhes..."></textarea>
                            </div>

                            <div id="campos-complementares-tarefa" class="w-100">
                                <div class="col-12"><div class="section-title">Dados do Processo (Opcional)</div></div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="numero_prodata">Nº Prodata</label>
                                        <input type="text" name="numero_prodata" id="numero_prodata" class="form-control" placeholder="0000/0000">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="cci">CCI</label>
                                        <input type="text" name="cci" id="cci" class="form-control" placeholder="Código CCI">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="prioridade">Prioridade</label>
                                        <select name="prioridade" id="prioridade" class="form-select">
                                            <option value="baixa">Baixa</option>
                                            <option value="media" selected>Média</option>
                                            <option value="alta">Alta</option>
                                            <option value="urgente">Urgente</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label for="nome_interessado">Nome do Interessado</label>
                                        <input type="text" name="nome_interessado" id="nome_interessado" class="form-control" placeholder="Nome completo">
                                    </div>
                                    <div class="col-12">
                                        <label for="endereco">Endereço</label>
                                        <input type="text" name="endereco" id="endereco" class="form-control" placeholder="Rua, Número, Bairro...">
                                    </div>

                                    <div class="col-12"><div class="section-title">Anexos & Execução</div></div>
                                    <div class="col-12">
                                        <label for="anexos" class="d-flex align-items-center">
                                            <i class="fa-solid fa-paperclip me-2 text-primary"></i> Anexar Arquivos
                                        </label>
                                        <input type="file" name="anexos[]" id="anexos" class="form-control" accept="application/pdf, image/jpeg, image/png" multiple>
                                        <div class="form-text text-muted"><i class="fa-solid fa-circle-info me-1"></i> Aceita <strong>PDF, JPG e PNG</strong>. Máx <strong>4MB</strong>.</div>
                                    </div>
                                    <div class="col-12">
                                        <label for="responsavel_id">Responsável pela execução</label>
                                        <?php if($_SESSION['usuario_nivel'] <= 4): ?>
                                            <select name="responsavel_id" id="responsavel_id" class="form-select border-primary">
                                                <option value="<?= $_SESSION['usuario_id'] ?>">Eu mesmo (<?= $_SESSION['usuario_nome'] ?>)</option>
                                                <?php foreach($usuarios_equipe as $u): ?>
                                                    <?php if($u['id'] != $_SESSION['usuario_id']): ?>
                                                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text text-primary"><i class="fa-solid fa-circle-info me-1"></i> Você pode delegar esta tarefa.</div>
                                        <?php else: ?>
                                            <input type="text" class="form-control bg-light" value="<?= $_SESSION['usuario_nome'] ?>" disabled>
                                            <input type="hidden" name="responsavel_id" value="<?= $_SESSION['usuario_id'] ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 mt-4">
                                <button type="submit" id="btnSubmit" class="btn btn-criar w-100">
                                    <i class="fa-solid fa-paper-plane me-2"></i> Gerar Protocolo e Salvar
                                </button>
                            </div>

                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Transição entre Tarefa e Evento
        function setTipo(tipo) {
            document.getElementById('tipo_cadastro').value = tipo;

            document.getElementById('optTarefa').classList.remove('active');
            document.getElementById('optEvento').classList.remove('active');
            
            const camposTarefa = document.getElementById('campos-tarefa');
            const camposEvento = document.getElementById('campos-evento');
            const camposComp = document.getElementById('campos-complementares-tarefa');
            const mainCard = document.getElementById('mainCard');
            const btnSubmit = document.getElementById('btnSubmit');
            const lblTitulo = document.getElementById('lblTitulo');
            const lblPrazo = document.getElementById('lblPrazo');
            const pageTitle = document.getElementById('page-title');
            
            // Obter a referência do select de categoria
            const categoriaSelect = document.getElementById('categoria_id');

            if (tipo === 'evento') {
                document.getElementById('optEvento').classList.add('active');
                
                if (camposTarefa) {
                    camposTarefa.style.display = 'none';
                    // Remove o required da categoria ao ir para Evento para não travar o form
                    if (categoriaSelect) categoriaSelect.required = false; 
                }
                
                if (camposComp) camposComp.style.display = 'none';
                if (camposEvento) camposEvento.style.display = 'block';

                mainCard.classList.add('mode-evento');
                btnSubmit.classList.add('btn-evento-mode');
                btnSubmit.innerHTML = '<i class="fa-solid fa-calendar-check me-2"></i> Agendar Evento';
                
                lblTitulo.innerText = "Nome do Evento";
                lblPrazo.innerText = "Data e Hora do Início";
                pageTitle.innerText = "Novo Evento";

            } else {
                document.getElementById('optTarefa').classList.add('active');
                
                if (camposEvento) camposEvento.style.display = 'none';
                
                if (camposTarefa) {
                    camposTarefa.style.display = 'block';
                    // Devolve o required para a categoria ao voltar para Tarefa
                    if (categoriaSelect) categoriaSelect.required = true; 
                }
                
                if (camposComp) camposComp.style.display = 'block';

                mainCard.classList.remove('mode-evento');
                btnSubmit.classList.remove('btn-evento-mode');
                btnSubmit.innerHTML = '<i class="fa-solid fa-paper-plane me-2"></i> Gerar Protocolo e Salvar';

                lblTitulo.innerText = "O que precisa ser feito?";
                lblPrazo.innerText = "Prazo de Entrega";
                pageTitle.innerText = "Nova Tarefa";
            }
        }

        // LÓGICA DE MULTI-PARTICIPANTES (Apenas Nível <= 4)
        <?php if($_SESSION['usuario_nivel'] <= 4): ?>
        
        const usuariosEquipe = <?= json_encode($usuarios_equipe) ?>;
        let participantes = [
            { id: <?= $_SESSION['usuario_id'] ?>, nome: 'Eu (<?= explode(' ', $_SESSION['usuario_nome'])[0] ?>)' }
        ];

        const containerChips = document.getElementById('participantes_container');
        const containerInputs = document.getElementById('inputs_hidden_participantes');
        const inputBusca = document.getElementById('busca_usuario_evento');
        const listaSugestoes = document.getElementById('sugestoes_usuarios_evento');

        function renderizarParticipantes() {
            containerChips.innerHTML = '';
            containerInputs.innerHTML = '';

            participantes.forEach((p, index) => {
                // Renderiza o Chip (Visual)
                const chip = document.createElement('div');
                chip.className = 'participant-chip';
                chip.innerHTML = `
                    <i class="fa-solid fa-user-check"></i> ${p.nome}
                    <div class="remove-chip" onclick="removerParticipante(${index})"><i class="fa-solid fa-xmark" style="font-size: 0.7rem;"></i></div>
                `;
                containerChips.appendChild(chip);

                // Renderiza o Input Hidden (Pro PHP)
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'participantes_evento[]';
                hidden.value = p.id;
                containerInputs.appendChild(hidden);
            });
        }

        function removerParticipante(index) {
            participantes.splice(index, 1);
            renderizarParticipantes();
        }

        inputBusca.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            listaSugestoes.innerHTML = '';

            if (term.length < 3) {
                listaSugestoes.style.display = 'none';
                return;
            }

            const matches = usuariosEquipe.filter(u => u.nome.toLowerCase().includes(term));

            if (matches.length > 0) {
                listaSugestoes.style.display = 'block';
                matches.forEach(u => {
                    // Ignora se já estiver na lista
                    const jaEstaNaLista = participantes.find(p => p.id == u.id);
                    if(!jaEstaNaLista) {
                        const div = document.createElement('div');
                        div.className = 'suggestion-item-event';
                        div.innerHTML = `<i class="fa-solid fa-user me-2 text-muted"></i>${u.nome}`;
                        div.onclick = () => {
                            participantes.push({ id: u.id, nome: u.nome.split(' ')[0] }); // Adiciona só o primeiro nome pro chip não ficar gigante
                            renderizarParticipantes();
                            inputBusca.value = '';
                            listaSugestoes.style.display = 'none';
                            inputBusca.focus();
                        };
                        listaSugestoes.appendChild(div);
                    }
                });
                
                // Se nenhum match sobrou (todos os encontrados já estão na lista)
                if(listaSugestoes.innerHTML === '') listaSugestoes.style.display = 'none';

            } else {
                listaSugestoes.style.display = 'none';
            }
        });

        document.addEventListener('click', function(e) {
            if (e.target !== inputBusca && e.target !== listaSugestoes) {
                listaSugestoes.style.display = 'none';
            }
        });

        // Chamada inicial
        renderizarParticipantes();
        <?php endif; ?>

    </script>
    <?php include 'chat_widget.php'; ?>
</body>
</html>