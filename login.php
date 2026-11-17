<?php
declare(strict_types=1);

define('PUBLIC_PAGE', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (authCurrentUser()) {
    redirectTo('painel.php');
}
if (authUserCount() === 0) {
    redirectTo('cadastro.php');
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf();
        $email = postString('email');
        $result = authLogin($email, (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            redirectTo('painel.php');
        }
        $error = $result['message'];
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Falha no login: ' . $exception->getMessage());
        $error = 'Não foi possível entrar agora. Tente novamente.';
    }
}

renderAuthHeader('Entrar');
?>
        <h1>Entrar</h1>
        <p class="auth-sub">Acesse o painel administrativo da agência.</p>
        <?php if ($error !== ''): ?><div class="flash error" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on"><?= csrfField() ?>
            <label>E-mail<input name="email" type="email" required maxlength="190" autofocus autocomplete="username" placeholder="voce@agencia.com" value="<?= h($email) ?>"></label>
            <label>Senha<input name="password" type="password" required autocomplete="current-password" placeholder="Sua senha"></label>
            <div class="form-actions"><button class="button primary" type="submit">Entrar</button></div>
        </form>
        <p class="auth-links">Ainda não tem conta? <a href="cadastro.php">Cadastre-se</a></p>
<?php renderAuthFooter();
