<?php
// detalhes_tarefa.php
session_start();
require_once 'config/database/conexao.php';

// Ativar exibição de erros para debug (Remova em produção)
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

if (!isset($_SESSION['usuario_id']) || !isset($_GET['id'])) {
    header("Location: minhas_tarefas.php");
    exit;
}

$tarefa_id = intval($_GET['id']);
$usuario_logado = $_SESSION['usuario_id'];
$nivel_logado = $_SESSION['usuario_nivel']; 
$msg = "";
$erro = "";
$transferencia_sucesso = false; 
$dados_recibo = []; 

// -------------------------------------------------------------------------
// LÓGICA DE PROCESSAMENTO (POST)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. EDITAR TAREFA (MODAL COMPLETO)
    if (isset($_POST['acao']) && $_POST['acao'] == 'editar_tarefa') {
        $titulo = trim($_POST['titulo']);
        $descricao = trim($_POST['descricao']);
        $prioridade = $_POST['prioridade'];
        
        $categoria_id = !empty($_POST['categoria_id']) ? intval($_POST['categoria_id']) : null;
        $num_prodata = trim($_POST['numero_prodata']);
        $ci = trim($_POST['ci'] ?? '');
        $nome_interessado = trim($_POST['nome_interessado']);
        $endereco = trim($_POST['endereco']);
        $cci = trim($_POST['cci']);

        if (!empty($titulo)) {
            $sql = "UPDATE tarefas SET 
                    titulo = ?, descricao = ?, prioridade = ?, 
                    categoria_id = ?, numero_prodata = ?, ci = ?, nome_interessado = ?, endereco = ?, cci = ? 
                    WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$titulo, $descricao, $prioridade, $categoria_id, $num_prodata, $ci, $nome_interessado, $endereco, $cci, $tarefa_id])) {
                $msg = "Dados atualizados com sucesso.";
                $desc_hist = "Atualizou dados gerais da tarefa (Título: $titulo)";
                $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (?, ?, 'edicao', ?)")
                    ->execute([$tarefa_id, $usuario_logado, $desc_hist]);
            } else {
                $erro = "Erro ao atualizar tarefa.";
            }
        } else {
            $erro = "O título é obrigatório.";
        }
    }

    // 1.5. EDIÇÃO RÁPIDA (DADOS DO PROCESSO INLINE)
    if (isset($_POST['acao']) && $_POST['acao'] == 'editar_processo') {
        $num_prodata = trim($_POST['numero_prodata']);
        $ci = trim($_POST['ci']);
        $cci = trim($_POST['cci']);
        $nome_interessado = trim($_POST['nome_interessado']);
        $endereco = trim($_POST['endereco']);

        $sql = "UPDATE tarefas SET numero_prodata = ?, ci = ?, cci = ?, nome_interessado = ?, endereco = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$num_prodata, $ci, $cci, $nome_interessado, $endereco, $tarefa_id])) {
            $msg = "Dados do processo atualizados.";
            $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (?, ?, 'edicao', ?)")
                ->execute([$tarefa_id, $usuario_logado, "Atualizou os dados do processo (CI, Prodata, etc) rapidamente pelo painel."]);
        } else {
            $erro = "Erro ao atualizar dados do processo.";
        }
    }

    // 2. ADICIONAR OBSERVAÇÃO (COM UPLOAD SEGURO)
    if (isset($_POST['acao']) && $_POST['acao'] == 'comentar') {
        $obs = trim($_POST['observacao']);
        $anexo_id = null;
        $tipo_arquivo = null;
        $nomeArquivo = null;

        try {
            if (isset($_FILES['anexo_msg']) && $_FILES['anexo_msg']['error'] === UPLOAD_ERR_OK) {
                $nomeArquivo = $_FILES['anexo_msg']['name'];
                $tmpName = $_FILES['anexo_msg']['tmp_name'];
                $tamanho = $_FILES['anexo_msg']['size'];
                
                // Validação MIME
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tmpName);
                finfo_close($finfo);

                // Permite PDF, Imagens e Documentos Word
                $permitidos = [
                    'application/pdf', 
                    'image/jpeg', 
                    'image/png', 
                    'image/webp', 
                    'image/gif',
                    'application/msword', // .doc
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' // .docx
                ];

                if ($tamanho > 4194304) { 
                    throw new Exception("O arquivo excede o limite de 4MB.");
                } elseif (!in_array($mimeType, $permitidos)) {
                    throw new Exception("Formato inválido. Apenas PDF, Imagens e Word.");
                } else {
                    $conteudo = file_get_contents($tmpName);
                    
                    try {
                        $stmt = $pdo->prepare("INSERT INTO tarefa_anexos (tarefa_id, nome_arquivo, tamanho, dados_arquivo, tipo_arquivo) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$tarefa_id, $nomeArquivo, $tamanho, $conteudo, $mimeType]);
                    } catch (PDOException $e) {
                         $stmt = $pdo->prepare("INSERT INTO tarefa_anexos (tarefa_id, nome_arquivo, tamanho, dados_arquivo) VALUES (?, ?, ?, ?)");
                         $stmt->execute([$tarefa_id, $nomeArquivo, $tamanho, $conteudo]);
                    }
                    
                    $anexo_id = $pdo->lastInsertId();
                    $tipo_arquivo = (strpos($mimeType, 'image') !== false) ? 'imagem' : 'arquivo';
                }
            }

            if (!empty($obs) || $anexo_id) {
                if ($tipo_arquivo == 'imagem') {
                    $obs .= "\n[IMG_ID: $anexo_id]"; 
                } elseif ($anexo_id) {
                    $obs .= "\n[Anexo: $nomeArquivo]";
                }

                $stmt = $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (?, ?, 'comentario', ?)");
                $stmt->execute([$tarefa_id, $usuario_logado, $obs]);
                $msg = "Movimentação registrada.";
            }

        } catch (Exception $e) {
            $erro = "Erro: " . $e->getMessage();
        }
    }

    // 3. ATUALIZAR PRAZO
    if (isset($_POST['acao']) && $_POST['acao'] == 'atualizar_prazo') {
        $nova_data = $_POST['data_prazo'];
        $motivo = trim($_POST['motivo_prazo']);
        
        if (empty($nova_data) || empty($motivo)) {
            $erro = "Data e motivo são obrigatórios.";
        } else {
            $stmt = $pdo->prepare("SELECT prazo FROM tarefas WHERE id = ?");
            $stmt->execute([$tarefa_id]);
            $dataAntiga = $stmt->fetchColumn();
            $fmtAntiga = date('d/m/Y H:i', strtotime($dataAntiga));
            $fmtNova = date('d/m/Y H:i', strtotime($nova_data));
            $pdo->prepare("UPDATE tarefas SET prazo = ? WHERE id = ?")->execute([$nova_data, $tarefa_id]);
            $desc = "Alterou o prazo de: $fmtAntiga para: $fmtNova.\nMotivo: $motivo";
            $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (?, ?, 'prazo', ?)")
                ->execute([$tarefa_id, $usuario_logado, $desc]);
            $msg = "Prazo atualizado.";
        }
    }

    // 4. TRANSFERIR TAREFA
    if (isset($_POST['acao']) && $_POST['acao'] == 'transferir_confirmado') {
        $novo_dono_id = intval($_POST['novo_usuario_id']); 
        $motivo = trim($_POST['obs_transferencia']); 
        $novo_prazo = $_POST['novo_prazo']; 

        if (empty($motivo) || $novo_dono_id == 0) {
            $erro = "Selecione um usuário válido e justifique.";
        } else {
            $stmtTarefa = $pdo->prepare("SELECT titulo, protocolo, prazo FROM tarefas WHERE id = ?");
            $stmtTarefa->execute([$tarefa_id]);
            $tarefaAtual = $stmtTarefa->fetch();

            $stmtUser = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ?");
            $stmtUser->execute([$novo_dono_id]);
            $nomeNovo = $stmtUser->fetchColumn();

            $prazoFinal = !empty($novo_prazo) ? $novo_prazo : $tarefaAtual['prazo'];

            $pdo->prepare("UPDATE tarefas SET usuario_id = ?, prazo = ? WHERE id = ?")->execute([$novo_dono_id, $prazoFinal, $tarefa_id]);

            $data_hist = date('d/m/Y H:i', strtotime($prazoFinal));
            $desc = "Transferiu para: " . $nomeNovo . ".\nNovo Prazo: " . $data_hist . ".\nMotivo: " . $motivo;
            $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (?, ?, 'transferencia', ?)")
                ->execute([$tarefa_id, $usuario_logado, $desc]);

            $msgNotif = "Nova tarefa recebida (Prazo: $data_hist). Transferida por " . $_SESSION['usuario_nome'];
            $pdo->prepare("INSERT INTO notificacoes (usuario_id, mensagem, link) VALUES (?, ?, ?)")
                ->execute([$novo_dono_id, $msgNotif, "detalhes_tarefa.php?id=$tarefa_id"]);

            $transferencia_sucesso = true;
            $dados_recibo = [
                'protocolo' => $tarefaAtual['protocolo'], 'titulo' => $tarefaAtual['titulo'],
                'de' => $_SESSION['usuario_nome'], 'para' => $nomeNovo,
                'motivo' => $motivo, 'prazo' => $data_hist, 'data_transf' => date('d/m/Y H:i')
            ];
        }
    }

    // 5. PAINEL DE CONTROLE
    if (isset($_POST['acao']) && $_POST['acao'] == 'atualizar_controle') {
        $num_interno = trim($_POST['num_interno']);
        $num_conecta = trim($_POST['num_conecta']);
        $historico_texto = "";

        if (!empty($num_interno)) $historico_texto .= "Controle Interno: " . $num_interno . ".\n";
        if (!empty($num_conecta)) $historico_texto .= "Processo Conecta: " . $num_conecta . ".\n";

        if (!empty($historico_texto)) {
            $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (?, ?, 'info', ?)")
                ->execute([$tarefa_id, $usuario_logado, trim($historico_texto)]);
            $msg = "Controle atualizado.";
        } else {
            $erro = "Preencha ao menos um campo.";
        }
    }

    // 6. CONCLUIR
    if (isset($_POST['acao'])) {
        if ($_POST['acao'] == 'concluir') {
            $pdo->prepare("UPDATE tarefas SET status = 'concluido' WHERE id = ?")->execute([$tarefa_id]);
            $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (?, ?, 'status', 'Concluiu a tarefa')")->execute([$tarefa_id, $usuario_logado]);
            
            $pdo->prepare("DELETE FROM tarefa_anexos WHERE tarefa_id = ?")->execute([$tarefa_id]);
             $pdo->query("UPDATE historico_tarefas SET descricao = REPLACE(descricao, '[IMG_ID:', '[Anexo removido - ID:') WHERE tarefa_id = $tarefa_id");
             $pdo->query("UPDATE historico_tarefas SET descricao = REPLACE(descricao, '[Anexo:', '[Anexo removido: ') WHERE tarefa_id = $tarefa_id");

            $msg = "Tarefa concluída!";
        }
    }

    // 7. UPLOAD DE ANEXO (PAINEL)
    if (isset($_POST['acao']) && $_POST['acao'] == 'upload_anexo') {
        try {
            if (isset($_FILES['anexo_novo']) && $_FILES['anexo_novo']['error'] === UPLOAD_ERR_OK) {
                $nomeArquivo = $_FILES['anexo_novo']['name'];
                $tmpName = $_FILES['anexo_novo']['tmp_name'];
                $tamanho = $_FILES['anexo_novo']['size'];
                
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tmpName);
                finfo_close($finfo);

                // Permite PDF, Imagens e Documentos Word
                $permitidos = [
                    'application/pdf', 
                    'image/jpeg', 
                    'image/png', 
                    'image/webp', 
                    'image/gif',
                    'application/msword', // .doc
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' // .docx
                ];

                if ($tamanho > 4194304) {
                    throw new Exception("Arquivo > 4MB.");
                } elseif (!in_array($mimeType, $permitidos)) {
                    throw new Exception("Apenas PDF, Imagens ou arquivos Word.");
                } else {
                    $conteudo = file_get_contents($tmpName);
                    
                    try {
                        $stmt = $pdo->prepare("INSERT INTO tarefa_anexos (tarefa_id, nome_arquivo, tamanho, dados_arquivo, tipo_arquivo) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$tarefa_id, $nomeArquivo, $tamanho, $conteudo, $mimeType]);
                    } catch(PDOException $e) {
                        $stmt = $pdo->prepare("INSERT INTO tarefa_anexos (tarefa_id, nome_arquivo, tamanho, dados_arquivo) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$tarefa_id, $nomeArquivo, $tamanho, $conteudo]);
                    }

                    $msg = "Anexo adicionado.";
                    $pdo->prepare("INSERT INTO historico_tarefas (tarefa_id, usuario_id, acao, descricao) VALUES (?, ?, 'anexo', ?)")
                        ->execute([$tarefa_id, $usuario_logado, "Adicionou anexo: " . $nomeArquivo]);
                }
            } else { throw new Exception("Selecione um arquivo válido."); }
        } catch (Exception $e) {
            $erro = "Erro: " . $e->getMessage();
        }
    }

    // 8. EXCLUIR ANEXO
    if (isset($_POST['acao']) && $_POST['acao'] == 'excluir_anexo') {
        $id_anexo = intval($_POST['id_anexo']);
        $pdo->prepare("DELETE FROM tarefa_anexos WHERE id = ? AND tarefa_id = ?")->execute([$id_anexo, $tarefa_id]);
        $msg = "Anexo removido.";
    }
}

// -------------------------------------------------------------------------
// BUSCAR DADOS
// -------------------------------------------------------------------------
if (!$transferencia_sucesso) {
    $stmt = $pdo->prepare("SELECT t.*, u.nome as responsavel, c.nome as criador, cat.nome as categoria_nome, cat.cor as categoria_cor 
                           FROM tarefas t 
                           LEFT JOIN usuarios u ON t.usuario_id = u.id
                           LEFT JOIN usuarios c ON t.criado_por = c.id
                           LEFT JOIN categorias cat ON t.categoria_id = cat.id
                           WHERE t.id = ?");
    $stmt->execute([$tarefa_id]);
    $tarefa = $stmt->fetch();

    if (!$tarefa) { die("Tarefa não encontrada."); }

    // TRAVA DE SEGURANÇA
    if ($nivel_logado > 4) {
        if ($tarefa['usuario_id'] != $usuario_logado && $tarefa['criado_por'] != $usuario_logado) {
            ?>
            <!DOCTYPE html>
            <html lang="pt-br">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Acesso Negado</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>body { background-color: #f0f2f5; height: 100vh; display: flex; align-items: center; justify-content: center; }</style>
            </head>
            <body>
                <div class="card border-0 shadow text-center p-5 rounded-4">
                    <h4 class="fw-bold mb-3 text-danger">Acesso Restrito</h4>
                    <p class="text-muted mb-4">Esta tarefa encontra-se com outro responsável.</p>
                    <a href="minhas_tarefas.php" class="btn btn-dark rounded-pill fw-bold w-100">Voltar</a>
                </div>
            </body>
            </html>
            <?php
            exit; 
        }
    }

    $stmt = $pdo->prepare("SELECT h.*, u.nome as autor 
                           FROM historico_tarefas h 
                           JOIN usuarios u ON h.usuario_id = u.id 
                           WHERE h.tarefa_id = ? 
                           ORDER BY h.data_acao DESC");
    $stmt->execute([$tarefa_id]);
    $historico = $stmt->fetchAll();

    $stmtAnexos = $pdo->prepare("SELECT id, nome_arquivo, tamanho, criado_em FROM tarefa_anexos WHERE tarefa_id = ? ORDER BY criado_em DESC");
    $stmtAnexos->execute([$tarefa_id]);
    $lista_anexos = $stmtAnexos->fetchAll();

    $stmtUsers = $pdo->query("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC");
    $usersData = json_encode($stmtUsers->fetchAll(PDO::FETCH_ASSOC));

    $todasCategorias = $pdo->query("SELECT * FROM categorias ORDER BY nome ASC")->fetchAll();
}

function getCorPrioridade($p) {
    switch($p) { case 'urgente': return '#dc3545'; case 'alta': return '#fd7e14'; case 'media': return '#0dcaf0'; default: return '#198754'; }
}

function formatBytes($bytes, $precision = 2) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= pow(1024, $pow); 
    return round($bytes, $precision) . ' ' . $units[$pow]; 
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes da Tarefa - HabitaNet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');
        body { background-color: #f0f2f5; font-family: 'Inter', sans-serif; min-height: 100vh; display: flex; flex-direction: column; }

        .navbar-glass { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05); border-bottom: 1px solid rgba(0, 77, 38, 0.1); }
        
        .card-details { background: white; border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 20px; }
        .timeline { border-left: 2px solid #e2e8f0; margin-left: 10px; padding-left: 20px; margin-top: 20px; }
        .timeline-item { position: relative; margin-bottom: 25px; }
        .timeline-dot { width: 12px; height: 12px; background: #004d26; border-radius: 50%; position: absolute; left: -26px; top: 5px; border: 2px solid white; box-shadow: 0 0 0 2px #e2e8f0; }
        .timeline-content { background: #f8fafc; border-radius: 12px; padding: 15px; border: 1px solid #f1f5f9; }
        .timeline-meta { font-size: 0.75rem; color: #94a3b8; margin-bottom: 5px; }
        .badge-protocol { background: #e2e8f0; color: #475569; font-family: monospace; font-size: 0.9rem; }
        .status-header { background: linear-gradient(90deg, #f8f9fa, #ffffff); padding: 1.5rem; border-bottom: 1px solid #edf2f7; }

        #user-suggestions { position: absolute; width: 92%; z-index: 1050; background: white; border: 1px solid #e2e8f0; border-radius: 0 0 10px 10px; box-shadow: 0 10px 20px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto; display: none; }
        .user-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f8f9fa; font-size: 0.9rem; }
        .user-item:hover { background: #f0fdf4; color: #004d26; font-weight: 600; }

        .anexo-item { display: flex; align-items: center; justify-content: space-between; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 8px; transition: background 0.2s; }
        .anexo-item:hover { background: #f8fafc; }
        .anexo-info { display: flex; align-items: center; gap: 10px; }
        .anexo-icon { font-size: 1.5rem; color: #dc2626; } 

        .hist-img { max-width: 100%; height: auto; border-radius: 8px; margin-top: 10px; border: 1px solid #e2e8f0; display: block; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .hist-img-link { display: inline-block; transition: transform 0.2s; }
        .hist-img-link:hover { transform: scale(1.02); }

        .receipt-container { max-width: 600px; margin: 50px auto; background: white; padding: 40px; border-radius: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-top: 5px solid #004d26; }
        .receipt-title { text-transform: uppercase; letter-spacing: 2px; font-weight: 800; color: #004d26; border-bottom: 2px solid #004d26; padding-bottom: 10px; margin-bottom: 20px; }
        .receipt-row { display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 5px; }
        .receipt-label { font-weight: bold; color: #64748b; font-size: 0.85rem; text-transform: uppercase; }
        .receipt-value { font-weight: 600; color: #1e293b; }

        .list-data-item { padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; flex-direction: column; }
        .list-data-item:last-child { border-bottom: none; }
        .list-data-label { font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .list-data-value { font-size: 0.95rem; color: #1e293b; font-weight: 500; }

        .hist-title-box { background-color: #000; color: white; padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }

        /* --- ESTILO RODAPÉ --- */
        .footer-custom {
            background-color: #ffffff;
            border-top: 4px solid #198754; 
            padding: 1.5rem 0;
            margin-top: auto;
        }
        .footer-dev-label {
            font-size: 0.7rem;
            font-weight: 800;
            color: #1e3a8a; 
            text-transform: uppercase;
            margin-bottom: 0.2rem;
            letter-spacing: 0.5px;
        }
        .footer-dev-name {
            font-size: 1.1rem;
            font-weight: 800;
            color: #0f766e; 
            text-decoration: none;
        }
        .footer-dev-name:hover {
            color: #047857;
        }
        .footer-social-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            text-decoration: none;
            transition: transform 0.2s;
            margin: 0 0.5rem;
        }
        .footer-social-btn:hover {
            transform: scale(1.1);
            color: white;
        }
        .footer-whatsapp { background-color: #25D366; box-shadow: 0 4px 15px rgba(37, 211, 102, 0.4); }
        .footer-instagram { background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); box-shadow: 0 4px 15px rgba(220, 39, 67, 0.4); }
        .footer-version { background-color: #111827; color: white; font-weight: 700; font-size: 0.85rem; padding: 6px 16px; border-radius: 50px; display: inline-block; }

        @media print {
            @page { margin: 0; size: A4; }
            body { background: white; -webkit-print-color-adjust: exact; }
            .no-print, .navbar, .btn { display: none !important; }
            .container { max-width: 100% !important; padding: 0 !important; }
            .card-details { box-shadow: none !important; border: 1px solid #ccc !important; }
            .receipt-container { box-shadow: none !important; border: 1px solid #000 !important; margin: 0 !important; width: 100% !important; }
            .footer-custom { display: none; }
        }
    </style>
</head>
<body>

    <?php if($transferencia_sucesso): ?>
        <div class="receipt-container flex-grow-1">
            <div class="text-center mb-4">
                <i class="fa-solid fa-circle-check text-success fa-3x mb-3 no-print"></i>
                <h4 class="fw-bold text-success no-print">Transferência Realizada!</h4>
                <p class="text-muted small no-print">A tarefa foi repassada com sucesso.</p>
            </div>
            <div class="receipt-title text-center">Recibo de Transferência</div>
            <div class="receipt-row"><span class="receipt-label">Protocolo</span><span class="receipt-value">#<?= $dados_recibo['protocolo'] ?></span></div>
            <div class="receipt-row"><span class="receipt-label">Tarefa</span><span class="receipt-value"><?= htmlspecialchars($dados_recibo['titulo']) ?></span></div>
            <div class="receipt-row"><span class="receipt-label">De (Origem)</span><span class="receipt-value"><?= $dados_recibo['de'] ?></span></div>
            <div class="receipt-row"><span class="receipt-label">Para (Destino)</span><span class="receipt-value"><?= $dados_recibo['para'] ?></span></div>
            <div class="receipt-row"><span class="receipt-label">Novo Prazo</span><span class="receipt-value"><?= $dados_recibo['prazo'] ?></span></div>
            <div class="receipt-row"><span class="receipt-label">Data Operação</span><span class="receipt-value"><?= $dados_recibo['data_transf'] ?></span></div>
            <div class="mt-4 p-3 bg-light border rounded">
                <span class="receipt-label d-block mb-1">Motivo / Observação</span>
                <span class="receipt-value" style="font-weight: 400;"><?= nl2br(htmlspecialchars($dados_recibo['motivo'])) ?></span>
            </div>
            <div class="mt-5 text-center no-print">
                <button onclick="window.print()" class="btn btn-dark rounded-pill px-4 me-2"><i class="fa-solid fa-print me-2"></i>Imprimir</button>
                <a href="minhas_tarefas.php" class="btn btn-outline-secondary rounded-pill px-4">Voltar para Lista</a>
            </div>
        </div>
    <?php else: ?>

    <nav class="navbar navbar-glass fixed-top no-print">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="minhas_tarefas.php" class="btn btn-outline-dark rounded-pill fw-bold px-3">
                <i class="fa-solid fa-arrow-left me-2"></i> Voltar
            </a>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill btn-sm fw-bold"><i class="fa-solid fa-print me-1"></i> Imprimir</button>
            </div>
        </div>
    </nav>
    <div style="margin-top: 80px;"></div>

    <div class="container pb-5 flex-grow-1">
        <?php if($msg): ?><div class="alert alert-success rounded-4 border-0 shadow-sm no-print"><i class="fa-solid fa-check me-2"></i> <?= $msg ?></div><?php endif; ?>
        <?php if($erro): ?><div class="alert alert-danger rounded-4 border-0 shadow-sm no-print"><i class="fa-solid fa-circle-exclamation me-2"></i> <?= $erro ?></div><?php endif; ?>

        <div class="row g-4">
            
            <div class="col-lg-8">
                <div class="card-details">
                    <div class="status-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <span class="badge badge-protocol px-3 py-2 rounded-pill mb-2">#<?= $tarefa['protocolo'] ?></span>
                            <?php if($tarefa['categoria_nome']): ?>
                                <span class="badge bg-secondary rounded-pill px-2 py-1 mb-2 ms-1" style="background-color: <?= $tarefa['categoria_cor'] ?? '#6c757d' ?> !important;"><?= htmlspecialchars($tarefa['categoria_nome']) ?></span>
                            <?php endif; ?>
                            <div class="d-flex align-items-center gap-2">
                                <h4 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($tarefa['titulo']) ?></h4>
                                <button class="btn btn-sm btn-light border rounded-circle text-primary no-print" title="Editar Tarefa" data-bs-toggle="modal" data-bs-target="#modalEditarTarefa">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge rounded-pill bg-light text-dark border px-3 py-2"><i class="fa-solid fa-flag me-1" style="color: <?= getCorPrioridade($tarefa['prioridade']) ?>"></i> <?= ucfirst($tarefa['prioridade']) ?></span>
                            <span class="badge rounded-pill bg-dark px-3 py-2 ms-1"><?= ucfirst($tarefa['status']) ?></span>
                        </div>
                    </div>
                    <div class="p-4">
                        <h6 class="text-muted text-uppercase small fw-bold mb-3 no-print">Descrição</h6>
                        <p class="text-secondary text-justify" style="line-height: 1.6;"><?= nl2br(htmlspecialchars($tarefa['descricao'])) ?></p>

                        <div class="mt-4 pt-3 border-top no-print">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="text-muted text-uppercase small fw-bold mb-0">Anexos</h6>
                                <button class="btn btn-sm btn-outline-primary rounded-pill fw-bold" data-bs-toggle="collapse" data-bs-target="#areaUploadAnexo">
                                    <i class="fa-solid fa-plus me-1"></i> Novo Anexo
                                </button>
                            </div>
                            
                            <div class="collapse mb-3" id="areaUploadAnexo">
                                <div class="card card-body bg-light border-0 shadow-sm">
                                    <form method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="acao" value="upload_anexo">
                                        <div class="input-group">
                                            <input type="file" name="anexo_novo" class="form-control" accept="application/pdf, image/*, application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                                            <button class="btn btn-primary" type="submit">Enviar</button>
                                        </div>
                                        <div class="form-text small mt-1">Máximo 4MB. PDF, Imagens e Word (.doc, .docx).</div>
                                    </form>
                                </div>
                            </div>

                            <?php if(count($lista_anexos) > 0): ?>
                                <?php foreach($lista_anexos as $anexo): ?>
                                    <div class="anexo-item">
                                        <div class="anexo-info">
                                            <i class="fa-solid fa-file-contract anexo-icon"></i>
                                            <div>
                                                <div class="fw-bold text-dark small"><?= htmlspecialchars($anexo['nome_arquivo']) ?></div>
                                                <div class="text-muted" style="font-size: 0.65rem;">
                                                    <?= formatBytes($anexo['tamanho']) ?> • <?= date('d/m H:i', strtotime($anexo['criado_em'])) ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <a href="download_anexo.php?id=<?= $anexo['id'] ?>" class="btn btn-sm btn-light border text-secondary" title="Baixar" target="_blank">
                                                <i class="fa-solid fa-download"></i>
                                            </a>
                                            <form method="POST" onsubmit="return confirm('Excluir este anexo permanentemente?')">
                                                <input type="hidden" name="acao" value="excluir_anexo">
                                                <input type="hidden" name="id_anexo" value="<?= $anexo['id'] ?>">
                                                <button class="btn btn-sm btn-light border text-danger" title="Excluir"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted small fst-italic">Nenhum anexo disponível.</p>
                            <?php endif; ?>
                        </div>

                        <div class="row mt-4 pt-4 border-top no-print">
                            <div class="col-md-4 mb-3"><small class="text-muted d-block">Responsável Atual</small><span class="fw-bold text-dark"><i class="fa-solid fa-user-circle me-1"></i> <?= $tarefa['responsavel'] ?></span></div>
                            <div class="col-md-4 mb-3"><small class="text-muted d-block">Criado por</small><span class="fw-bold text-dark"><?= $tarefa['criador'] ?></span></div>
                            <div class="col-md-4 mb-3">
                                <small class="text-muted d-block">Prazo</small>
                                <div class="d-flex align-items-center">
                                    <?php $prazo = new DateTime($tarefa['prazo']); $atrasado = ($prazo < new DateTime() && $tarefa['status'] != 'concluido'); ?>
                                    <span class="fw-bold me-2 <?= $atrasado ? 'text-danger' : 'text-success' ?>"><?= $prazo->format('d/m/Y H:i') ?></span>
                                    <button class="btn btn-sm btn-outline-secondary rounded-circle no-print" style="width: 25px; height: 25px; display: inline-flex; align-items: center; justify-content: center;" data-bs-toggle="modal" data-bs-target="#modalPrazo" title="Alterar Prazo"><i class="fa-solid fa-pencil" style="font-size: 0.7rem;"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-details p-4">
                    <div class="hist-title-box">
                        <h5 class="fw-bold mb-0 text-white"><i class="fa-solid fa-clock-rotate-left me-2"></i>Histórico de Movimentação</h5>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" class="mb-5 bg-light p-3 rounded-4 no-print">
                        <input type="hidden" name="acao" value="comentar">
                        <label class="small fw-bold text-muted mb-2">Adicionar Observação</label>
                        <textarea name="observacao" class="form-control border-0 shadow-sm mb-3" rows="2" placeholder="Digite aqui..." required></textarea>
                        
                        <div class="mb-3">
                            <label class="small text-muted mb-1"><i class="fa-solid fa-paperclip me-1"></i> Anexar Arquivo ou Foto (Opcional)</label>
                            <input type="file" name="anexo_msg" class="form-control form-control-sm" accept="application/pdf, image/*, application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                        </div>

                        <div class="text-end"><button type="submit" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">Salvar</button></div>
                    </form>

                    <div class="timeline">
                        <?php foreach($historico as $h): 
                            $tipoIcon = 'fa-comment';
                            if($h['acao'] == 'transferencia') $tipoIcon = 'fa-share';
                            if($h['acao'] == 'status') $tipoIcon = 'fa-check-circle';
                            if($h['acao'] == 'prazo') $tipoIcon = 'fa-calendar-days';
                            if($h['acao'] == 'anexo') $tipoIcon = 'fa-paperclip';
                            if($h['acao'] == 'edicao') $tipoIcon = 'fa-pen';
                            
                            $texto = htmlspecialchars($h['descricao']);
                            $texto = preg_replace_callback('/\[IMG_ID: (\d+)\]/', function($matches) {
                                return '<a href="download_anexo.php?id='.$matches[1].'" target="_blank" class="hist-img-link"><img src="download_anexo.php?id='.$matches[1].'" class="hist-img shadow-sm" alt="Imagem Anexada"></a>';
                            }, $texto);
                            $texto = preg_replace('/\[Anexo: (.*?)\]/', '<div class="mt-2"><span class="badge bg-secondary text-white"><i class="fa-solid fa-paperclip me-1"></i> $1</span></div>', $texto);
                        ?>
                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <div class="timeline-meta d-flex justify-content-between"><span><i class="fa-solid fa-user me-1 no-print"></i> <?= $h['autor'] ?></span><span><?= date('d/m/Y H:i', strtotime($h['data_acao'])) ?></span></div>
                            <div class="timeline-content">
                                <div class="mb-1 text-primary small text-uppercase fw-bold no-print"><i class="fa-solid <?= $tipoIcon ?> me-1"></i> <?= ucfirst($h['acao']) ?></div>
                                <div class="text-dark" style="white-space: pre-wrap;"><?= $texto ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="timeline-item"><div class="timeline-dot bg-secondary"></div><div class="timeline-content text-muted small">Tarefa criada em <?= date('d/m/Y H:i', strtotime($tarefa['criado_em'])) ?></div></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 no-print">
                <div class="sticky-top" style="top: 100px; z-index: 1020;">
                    
                    <div class="card-details p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                            <h6 class="fw-bold text-muted text-uppercase mb-0"><i class="fa-solid fa-circle-info me-2"></i>Dados do Processo</h6>
                            <button type="button" class="btn btn-sm btn-light border rounded-pill no-print text-primary fw-bold" onclick="toggleEditProcesso()" id="btnEditProcesso">
                                <i class="fa-solid fa-pen"></i> Editar
                            </button>
                        </div>
                        
                        <div id="view_processo" class="d-flex flex-column">
                            <div class="list-data-item">
                                <span class="list-data-label">Categoria</span>
                                <span class="list-data-value text-primary">
                                    <?= $tarefa['categoria_nome'] ? htmlspecialchars($tarefa['categoria_nome']) : '---' ?>
                                </span>
                            </div>
                            <div class="list-data-item">
                                <span class="list-data-label">Nº Prodata</span>
                                <span class="list-data-value"><?= !empty($tarefa['numero_prodata']) ? htmlspecialchars($tarefa['numero_prodata']) : '---' ?></span>
                            </div>
                            <div class="list-data-item">
                                <span class="list-data-label">Comunicação Interna (CI)</span>
                                <span class="list-data-value"><?= !empty($tarefa['ci']) ? htmlspecialchars($tarefa['ci']) : '---' ?></span>
                            </div>
                            <div class="list-data-item">
                                <span class="list-data-label">CCI</span>
                                <span class="list-data-value"><?= !empty($tarefa['cci']) ? htmlspecialchars($tarefa['cci']) : '---' ?></span>
                            </div>
                            <div class="list-data-item">
                                <span class="list-data-label">Interessado</span>
                                <span class="list-data-value text-truncate" title="<?= htmlspecialchars($tarefa['nome_interessado']) ?>">
                                    <?= !empty($tarefa['nome_interessado']) ? htmlspecialchars($tarefa['nome_interessado']) : '---' ?>
                                </span>
                            </div>
                            <div class="list-data-item">
                                <span class="list-data-label">Endereço</span>
                                <span class="list-data-value text-truncate" title="<?= htmlspecialchars($tarefa['endereco']) ?>">
                                    <?= !empty($tarefa['endereco']) ? htmlspecialchars($tarefa['endereco']) : '---' ?>
                                </span>
                            </div>
                        </div>

                        <div id="edit_processo" style="display: none;">
                            <form method="POST">
                                <input type="hidden" name="acao" value="editar_processo">
                                
                                <div class="mb-2">
                                    <label class="small fw-bold text-muted mb-1">Nº Prodata</label>
                                    <input type="text" name="numero_prodata" class="form-control form-control-sm rounded-3" value="<?= htmlspecialchars($tarefa['numero_prodata']) ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="small fw-bold text-muted mb-1">Comunicação Interna (CI)</label>
                                    <input type="text" name="ci" class="form-control form-control-sm rounded-3" value="<?= htmlspecialchars($tarefa['ci'] ?? '') ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="small fw-bold text-muted mb-1">CCI</label>
                                    <input type="text" name="cci" class="form-control form-control-sm rounded-3" value="<?= htmlspecialchars($tarefa['cci']) ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="small fw-bold text-muted mb-1">Interessado</label>
                                    <input type="text" name="nome_interessado" class="form-control form-control-sm rounded-3" value="<?= htmlspecialchars($tarefa['nome_interessado']) ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="small fw-bold text-muted mb-1">Endereço</label>
                                    <input type="text" name="endereco" class="form-control form-control-sm rounded-3" value="<?= htmlspecialchars($tarefa['endereco']) ?>">
                                </div>

                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-sm btn-light rounded-pill fw-bold border" onclick="toggleEditProcesso()">Cancelar</button>
                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill fw-bold px-3">Salvar</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card-details p-4 mb-4">
                        <h6 class="fw-bold text-muted text-uppercase mb-3"><i class="fa-solid fa-bolt me-2"></i>Ações</h6>
                        <div class="d-grid gap-3">
                            <?php if($tarefa['status'] != 'concluido'): ?>
                                <form method="POST" onsubmit="return confirm('Concluir esta tarefa? Atenção: Todos os anexos serão excluídos permanentemente.')">
                                    <input type="hidden" name="acao" value="concluir">
                                    <button class="btn btn-success w-100 py-2 rounded-3 fw-bold shadow-sm">
                                        <i class="fa-solid fa-flag-checkered me-2"></i> Concluir Tarefa
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <div class="accordion" id="accTransfer">
                                <div class="accordion-item border-0 shadow-sm rounded-3 overflow-hidden">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed fw-bold bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTrans"><i class="fa-solid fa-share me-2"></i> Transferir</button>
                                    </h2>
                                    <div id="collapseTrans" class="accordion-collapse collapse" data-bs-parent="#accTransfer">
                                        <div class="accordion-body p-3">
                                            <div class="mb-3 position-relative">
                                                <label class="small text-muted mb-1 fw-bold">Buscar Usuário:</label>
                                                <input type="text" id="busca_usuario" class="form-control form-control-sm" placeholder="Ex: Maria..." autocomplete="off">
                                                <input type="hidden" id="id_novo_dono"> <div id="user-suggestions"></div>
                                            </div>
                                            <label class="small text-muted mb-1 fw-bold">Novo Prazo (Opcional):</label>
                                            <input type="datetime-local" id="novo_prazo_temp" class="form-control form-control-sm mb-3">
                                            <label class="small text-muted mb-1 fw-bold">Motivo da Transferência:</label>
                                            <textarea id="motivo_transf_temp" class="form-control form-control-sm mb-3" rows="2" placeholder="Explique o motivo..."></textarea>
                                            <button type="button" class="btn btn-primary btn-sm w-100 rounded-pill" onclick="abrirConfirmacao()">Continuar</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            </div>
                    </div>

                    <div class="card-details overflow-hidden mb-4">
                        <div class="p-3 text-white fw-bold text-uppercase d-flex align-items-center" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
                            <i class="fa-solid fa-sliders me-2"></i> Controle Interno
                        </div>
                        <div class="p-4">
                            <form method="POST">
                                <input type="hidden" name="acao" value="atualizar_controle">
                                
                                <label class="small fw-bold text-muted mb-1">Controle Interno</label>
                                <input type="text" name="num_interno" class="form-control form-control-sm mb-3" placeholder="Nº Controle">
                                
                                <label class="small fw-bold text-muted mb-1">Processo Conecta</label>
                                <input type="text" name="num_conecta" class="form-control form-control-sm mb-3" placeholder="Nº Conecta">
                                
                                <div class="d-grid"><button type="submit" class="btn btn-dark btn-sm rounded-pill fw-bold"><i class="fa-solid fa-floppy-disk me-2"></i> Salvar no Histórico</button></div>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
    
    <div class="modal fade" id="modalConfirmarTransf" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header bg-warning bg-opacity-10 border-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-triangle-exclamation text-warning me-2"></i>Confirmar Transferência</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p>Confirme os dados da transferência:</p>
                    <ul class="list-group list-group-flush mb-3 small">
                        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Novo Responsável:</span><strong id="conf_nome_dono" class="text-primary">---</strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Novo Prazo:</span><strong id="conf_prazo">Mantido o atual</strong></li>
                    </ul>
                    <div class="alert alert-light border small"><strong>Motivo:</strong> <span id="conf_motivo">---</span></div>
                    <form method="POST" id="formTransferirFinal">
                        <input type="hidden" name="acao" value="transferir_confirmado">
                        <input type="hidden" name="novo_usuario_id" id="final_id_dono">
                        <input type="hidden" name="novo_prazo" id="final_prazo">
                        <input type="hidden" name="obs_transferencia" id="final_motivo">
                        <div class="d-grid gap-2"><button type="submit" class="btn btn-dark fw-bold rounded-pill">Sim, Transferir Agora</button><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Cancelar</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalPrazo" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold">Alterar Prazo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body p-4">
                    <form method="POST">
                        <input type="hidden" name="acao" value="atualizar_prazo">
                        <div class="mb-3"><label class="small fw-bold text-muted">Novo Prazo</label><input type="datetime-local" name="data_prazo" class="form-control rounded-3" required></div>
                        <div class="mb-3"><label class="small fw-bold text-muted">Motivo</label><textarea name="motivo_prazo" class="form-control rounded-3" rows="3" required></textarea></div>
                        <div class="d-grid"><button type="submit" class="btn btn-primary rounded-pill fw-bold">Confirmar</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEditarTarefa" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Editar Tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST">
                        <input type="hidden" name="acao" value="editar_tarefa">
                        
                        <div class="mb-3">
                            <label class="small fw-bold text-muted">Título da Tarefa</label>
                            <input type="text" name="titulo" class="form-control rounded-3" value="<?= htmlspecialchars($tarefa['titulo']) ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="small fw-bold text-muted">Descrição / Detalhes</label>
                            <textarea name="descricao" class="form-control rounded-3" rows="4"><?= htmlspecialchars($tarefa['descricao']) ?></textarea>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="small fw-bold text-muted">Categoria</label>
                                <select name="categoria_id" class="form-select rounded-3">
                                    <option value="">Sem categoria</option>
                                    <?php foreach($todasCategorias as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= $tarefa['categoria_id'] == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="small fw-bold text-muted">Prioridade</label>
                                <select name="prioridade" class="form-select rounded-3">
                                    <option value="baixa" <?= $tarefa['prioridade'] == 'baixa' ? 'selected' : '' ?>>Baixa</option>
                                    <option value="media" <?= $tarefa['prioridade'] == 'media' ? 'selected' : '' ?>>Média</option>
                                    <option value="alta" <?= $tarefa['prioridade'] == 'alta' ? 'selected' : '' ?>>Alta</option>
                                    <option value="urgente" <?= $tarefa['prioridade'] == 'urgente' ? 'selected' : '' ?>>Urgente</option>
                                </select>
                            </div>
                        </div>

                        <h6 class="small text-muted fw-bold border-bottom pb-2 mt-4">Dados Complementares</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="small text-muted">Nº Prodata</label>
                                <input type="text" name="numero_prodata" class="form-control form-control-sm" value="<?= htmlspecialchars($tarefa['numero_prodata']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted">CI (Comunicação Interna)</label>
                                <input type="text" name="ci" class="form-control form-control-sm" value="<?= htmlspecialchars($tarefa['ci'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted">CCI</label>
                                <input type="text" name="cci" class="form-control form-control-sm" value="<?= htmlspecialchars($tarefa['cci']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="small text-muted">Interessado</label>
                                <input type="text" name="nome_interessado" class="form-control form-control-sm" value="<?= htmlspecialchars($tarefa['nome_interessado']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="small text-muted">Endereço</label>
                                <input type="text" name="endereco" class="form-control form-control-sm" value="<?= htmlspecialchars($tarefa['endereco']) ?>">
                            </div>
                        </div>

                        <div class="d-grid mt-4"><button type="submit" class="btn btn-primary rounded-pill fw-bold">Salvar Alterações</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer-custom">
        <div class="container">
            <div class="row align-items-center text-center text-md-start">
                
                <div class="col-md-6 mb-3 mb-md-0">
                    <div class="footer-dev-label">Sistemas desenvolvidos e cedidos por</div>
                    <a href="https://www.mhos.com.br" target="_blank" class="footer-dev-name d-block">Mário Henrique Inácio de Paula</a>
                    <div style="font-size: 0.75rem; color: #adb5bd; font-weight: normal; margin-top: 3px;">Versão 10.3</div>
                </div>

                <div class="col-md-6 text-center text-md-end mb-3 mb-md-0">
                    <a href="https://wa.me/5564992238703" target="_blank" class="footer-social-btn footer-whatsapp" title="WhatsApp">
                        <i class="fa-brands fa-whatsapp"></i>
                    </a>
                    <a href="https://instagram.com/mariomorrinhos" target="_blank" class="footer-social-btn footer-instagram" title="Instagram">
                        <i class="fa-brands fa-instagram"></i>
                    </a>
                </div>

            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const usuarios = <?= $usersData ?>;
        const buscaInput = document.getElementById('busca_usuario');
        const listaSugestoes = document.getElementById('user-suggestions');
        const hiddenId = document.getElementById('id_novo_dono');

        buscaInput.addEventListener('input', function() {
            const termo = this.value.toLowerCase();
            listaSugestoes.innerHTML = '';
            hiddenId.value = ''; 
            if(termo.length < 2) { listaSugestoes.style.display = 'none'; return; }
            const filtrados = usuarios.filter(u => u.nome.toLowerCase().includes(termo));
            if(filtrados.length > 0) {
                listaSugestoes.style.display = 'block';
                filtrados.forEach(u => {
                    const div = document.createElement('div');
                    div.className = 'user-item';
                    div.innerHTML = `<i class="fa-solid fa-user me-2 text-muted"></i>${u.nome}`;
                    div.onclick = () => { buscaInput.value = u.nome; hiddenId.value = u.id; listaSugestoes.style.display = 'none'; };
                    listaSugestoes.appendChild(div);
                });
            } else { listaSugestoes.style.display = 'none'; }
        });

        document.addEventListener('click', (e) => { if(e.target !== buscaInput && e.target !== listaSugestoes) { listaSugestoes.style.display = 'none'; } });

        function abrirConfirmacao() {
            const id = document.getElementById('id_novo_dono').value;
            const nome = document.getElementById('busca_usuario').value;
            const prazo = document.getElementById('novo_prazo_temp').value;
            const motivo = document.getElementById('motivo_transf_temp').value;

            if(!id || !motivo) { alert("Por favor, selecione um usuário da lista e escreva o motivo."); return; }

            document.getElementById('conf_nome_dono').innerText = nome;
            document.getElementById('conf_prazo').innerText = prazo ? prazo.replace('T', ' ') : 'Manter prazo original';
            document.getElementById('conf_motivo').innerText = motivo;
            document.getElementById('final_id_dono').value = id;
            document.getElementById('final_prazo').value = prazo;
            document.getElementById('final_motivo').value = motivo;

            var myModal = new bootstrap.Modal(document.getElementById('modalConfirmarTransf'));
            myModal.show();
        }

        // Função para alternar a edição inline dos Dados do Processo
        function toggleEditProcesso() {
            const view = document.getElementById('view_processo');
            const edit = document.getElementById('edit_processo');
            const btn = document.getElementById('btnEditProcesso');

            if (view.style.display === 'none') {
                view.style.display = 'flex';
                edit.style.display = 'none';
                btn.style.display = 'inline-block';
            } else {
                view.style.display = 'none';
                edit.style.display = 'block';
                btn.style.display = 'none';
            }
        }
    </script>
    <?php endif; ?>
    <?php include 'chat_widget.php'; ?>
</body>
</html>