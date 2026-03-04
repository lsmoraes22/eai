<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Voga2b - Tecnologia e Inovação</title>
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
      color: #fff;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      text-align: center;
    }
    .container {
      animation: fadeIn 2s ease-in-out;
    }
    h1 {
      font-size: 3em;
      margin-bottom: 0.5em;
      color: #00d4ff;
    }
    p {
      font-size: 1.2em;
      margin-bottom: 1.5em;
    }
    .loader {
      border: 6px solid #f3f3f3;
      border-top: 6px solid #00d4ff;
      border-radius: 50%;
      width: 50px;
      height: 50px;
      animation: spin 1s linear infinite;
      margin: 0 auto;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
  </style>
  <script>
    // Redireciona após 3 segundos
    setTimeout(function(){
      window.location.href = "{{ url('/admin') }}";
    }, 3000);
  </script>
</head>
<body>
  <div class="container">
    <h1>Bem-vindo à Voga2b</h1>
    <p>Inovação e tecnologia para transformar o futuro.</p>
    <div class="loader"></div>
    <p>Redirecionando para o painel administrativo...</p>
  </div>
</body>
</html>
