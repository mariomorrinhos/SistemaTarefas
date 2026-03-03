<?php
// download_anexo.php
session_start();
require_once 'config/database/conexao.php';

// 1. Segurança: Apenas usuários logados podem ver
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    die("Acesso negado.");
}

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);

    // 2. Busca o binário do arquivo no banco
    $stmt = $pdo->prepare("SELECT nome_arquivo, tamanho, dados_arquivo FROM tarefa_anexos WHERE id = ?");
    $stmt->execute([$id]);
    $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($arquivo) {
        $nome = $arquivo['nome_arquivo'];
        $dados = $arquivo['dados_arquivo'];
        $tamanho = $arquivo['tamanho'];

        // 3. Descobre o tipo do arquivo (Mime Type) pela extensão
        // Como não salvamos o tipo na tabela de anexos de tarefas, detectamos aqui
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        $mime_type = 'application/octet-stream'; // Padrão genérico

        $mimes = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp'
        ];

        if (array_key_exists($ext, $mimes)) {
            $mime_type = $mimes[$ext];
        }

        // 4. Configura os cabeçalhos HTTP
        header("Content-Type: " . $mime_type);
        header("Content-Length: " . $tamanho);
        
        // 'inline' permite que o navegador mostre a imagem/pdf em vez de forçar download
        header("Content-Disposition: inline; filename=\"" . $nome . "\"");

        // 5. Imprime o conteúdo do arquivo
        echo $dados;
        exit;
    }
}

// Se não achou
http_response_code(404);
echo "Arquivo não encontrado.";
?>