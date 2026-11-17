<?php
declare(strict_types=1);

define('PUBLIC_PAGE', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (authCurrentUser()) {
    redirectTo('painel.php');
}
$isFirst = authUserCount() === 0;

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
        $created = authRegister($name, $email, $password);
        if ($created['first']) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $created['id'];
            flash('success', 'Conta de administrador criada. Bem-vindo(a)!');
            redirectTo('painel.php');
        }
        flash('success', 'Cadastro enviado! Um administrador precisa aprovar sua conta antes do primeiro acesso.');
        redirectTo('login.php');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = str_contains(strtolower($exception->getMessage()), 'duplicate')
            ? 'Este e-mail já está cadastrado.'
            : 'Não foi possível concluir o cadastro. Tente novamente.';
    }
}

renderAuthHeader('Cadastro');
?>
        <h1><?= $isFirst ? 'Criar conta de administrador' : 'Criar conta' ?></h1>
        <p class="auth-sub"><?= $isFirst
            ? 'Nenhum usuário cadastrado ainda. Esta primeira conta será a administradora do sistema.'
            : 'Após o cadastro, um administrador precisa aprovar o seu acesso.' ?></p>
        <?php if ($error !== ''): ?><div class="flash error" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form method="post"><?= csrfField() ?>
            <label>Nome completo<input name="name" required maxlength="120" autofocus autocomplete="name" placeholder="Ex.: Ana Silva" value="<?= h($name) ?>"></label>
            <label>E-mail<input name="email" type="email" required maxlength="190" autocomplete="username" placeholder="voce@agencia.com" value="<?= h($email) ?>"></label>
            <label>Senha<input name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="Mínimo de 8 caracteres"></label>
            <label>Confirmar senha<input name="password_confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
            <div class="form-actions"><button class="button primary" type="submit"><?= $isFirst ? 'Criar administrador' : 'Cadastrar' ?></button></div>
        </form>
        <?php if (!$isFirst): ?><p class="auth-links">Já tem conta? <a href="login.php">Entrar</a></p><?php else: ?><p class="auth-links"></p><?php endif; ?>
<?php renderAuthFooter();
