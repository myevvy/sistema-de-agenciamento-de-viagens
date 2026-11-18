<?php
declare(strict_types=1);

define('PUBLIC_PAGE', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if ($user = authCurrentUser()) {
    redirectTo(authHomeUrl($user));
}
if (authUserCount() > 0) {
    redirectTo('login.php');
}

$error = '';
$name = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf();
        $name = postString('name');
        $email = postString('email');
        $password = (string) ($_POST['password'] ?? '');
        $problem = authValidateNewUser($name, $email, $password, (string) ($_POST['password_confirm'] ?? ''));
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        $id = authRegisterInitialAdmin($name, $email, $password);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        flash('success', 'Configuração inicial concluída. Sua conta de administrador está ativa.');
        redirectTo('painel.php');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Falha na configuração inicial: ' . $exception->getMessage());
        $error = str_contains(strtolower($exception->getMessage()), 'duplicate')
            ? 'Este e-mail já está cadastrado.'
            : 'Não foi possível concluir a configuração. Tente novamente.';
    }
}

renderAuthHeader('Configuração inicial');
?>
        <h1>Criar conta administradora</h1>
        <p class="auth-sub">Disponível apenas antes do primeiro usuário. Configure o administrador antes de publicar o site.</p>
        <?php if ($error !== ''): ?><div class="flash error" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on"><?= csrfField() ?>
            <label>Nome completo<input name="name" required minlength="2" maxlength="120" autofocus autocomplete="name" placeholder="Ex.: Ana Silva" value="<?= h($name) ?>"></label>
            <label>E-mail<input name="email" type="email" required maxlength="190" autocomplete="username" placeholder="voce@agencia.com" value="<?= h($email) ?>"></label>
            <label>Senha<input name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
            <label>Confirmar senha<input name="password_confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
            <div class="form-actions"><button class="button primary" type="submit">Criar administrador</button></div>
        </form>
<?php renderAuthFooter();
