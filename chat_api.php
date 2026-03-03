<?php
// chat_api.php
session_start();
require_once 'config/database/conexao.php'; 

date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

$myId = $_SESSION['usuario_id'];
$acao = $_POST['acao'] ?? '';

try {

    // 1. BUSCAR USUÁRIOS
    if ($acao == 'buscar_usuarios') {
        $termo = $_POST['termo'] ?? '';
        $termoSql = "%$termo%";
        
        $sql = "SELECT 
                    u.id, 
                    u.nome, 
                    u.email,
                    (SELECT COUNT(*) FROM chat_mensagens cm WHERE cm.id_de = u.id AND cm.id_para = :myId AND cm.lida = 0) as nao_lidas,
                    (SELECT mensagem FROM chat_mensagens cm2 WHERE (cm2.id_de = u.id AND cm2.id_para = :myId) OR (cm2.id_de = :myId AND cm2.id_para = u.id) ORDER BY cm2.id DESC LIMIT 1) as ultima_msg,
                    (SELECT data_envio FROM chat_mensagens cm3 WHERE (cm3.id_de = u.id AND cm3.id_para = :myId) OR (cm3.id_de = :myId AND cm3.id_para = u.id) ORDER BY cm3.id DESC LIMIT 1) as data_ultima_msg,
                    (SELECT id FROM chat_mensagens cm4 WHERE (cm4.id_de = u.id AND cm4.id_para = :myId) OR (cm4.id_de = :myId AND cm4.id_para = u.id) LIMIT 1) as tem_conversa
                FROM usuarios u
                WHERE u.id != :myId AND u.ativo = 1 AND (u.nome LIKE :termo OR u.email LIKE :termo)";

        if (empty($termo)) {
            $sql .= " HAVING tem_conversa IS NOT NULL"; 
            $sql .= " ORDER BY nao_lidas DESC, data_ultima_msg DESC";
        } else {
            $sql .= " ORDER BY u.nome ASC";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':myId' => $myId, ':termo' => $termoSql]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // 2. CARREGAR CONVERSA
    if ($acao == 'carregar_conversa') {
        $outroId = intval($_POST['id_usuario']);

        $sqlUpdate = $pdo->prepare("UPDATE chat_mensagens SET lida = 1 WHERE id_de = ? AND id_para = ? AND lida = 0");
        $sqlUpdate->execute([$outroId, $myId]);

        // Seleciona colunas exceto o arquivo_dados (pesado)
        $sql = "SELECT id, id_de, id_para, mensagem, arquivo_nome, arquivo_tamanho, lida, data_envio 
                FROM chat_mensagens 
                WHERE (id_de = ? AND id_para = ?) OR (id_de = ? AND id_para = ?) 
                ORDER BY data_envio ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$myId, $outroId, $outroId, $myId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // 3. ENVIAR MENSAGEM (BLOB NO BANCO)
    if ($acao == 'enviar') {
        $idPara = intval($_POST['id_para']);
        $mensagem = trim($_POST['mensagem']);
        
        $arquivoNome = null;
        $arquivoDados = null; // Conteúdo binário
        $arquivoTam = 0;
        $arquivoTipo = null;

        if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
            $arquivoNome = $_FILES['arquivo']['name'];
            $arquivoTam = $_FILES['arquivo']['size'];
            $arquivoTipo = $_FILES['arquivo']['type']; // Mime type
            $arquivoDados = file_get_contents($_FILES['arquivo']['tmp_name']); // Lê o binário
        }

        if (!empty($mensagem) || $arquivoNome) {
            // Insere no banco com o binário
            $sql = "INSERT INTO chat_mensagens (id_de, id_para, mensagem, arquivo_nome, arquivo_dados, arquivo_tamanho, arquivo_tipo, lida, data_envio) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$myId, $idPara, $mensagem, $arquivoNome, $arquivoDados, $arquivoTam, $arquivoTipo]);
            
            echo json_encode(['status' => 'sucesso']);
        } else {
            echo json_encode(['erro' => 'Mensagem vazia']);
        }
        exit;
    }

    // 4. CHECK NOTIFICAÇÕES
    if ($acao == 'check_notificacoes') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_mensagens WHERE id_para = ? AND lida = 0");
        $stmt->execute([$myId]);
        echo json_encode(['total_nao_lidas' => $stmt->fetchColumn()]);
        exit;
    }

    // 5. LIMPAR CONVERSA
    if ($acao == 'limpar_conversa') {
        $outroId = intval($_POST['id_outro']);
        $sql = "DELETE FROM chat_mensagens WHERE (id_de = ? AND id_para = ?) OR (id_de = ? AND id_para = ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$myId, $outroId, $outroId, $myId]);
        echo json_encode(['status' => 'limpo']);
        exit;
    }

    // 6. LIMPAR TUDO
    if ($acao == 'limpar_tudo') {
        $sql = "DELETE FROM chat_mensagens WHERE id_de = ? OR id_para = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$myId, $myId]);
        echo json_encode(['status' => 'limpo_tudo']);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['erro' => 'Erro no servidor: ' . $e->getMessage()]);
}
?>