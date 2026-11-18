<?php
declare(strict_types=1);

define('PUBLIC_PAGE', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (authUserCount() === 0) {
    redirectTo('setup.php');
}
if ($user = authCurrentUser()) {
    redirectTo(authHomeUrl($user));
}

$error = '';
$name = '';
$email = '';
$phone = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf();
        $name = postString('name');
        $email = postString('email');
        $phone = postString('phone');
        $password = (string) ($_POST['password'] ?? '');
        $problem = authValidateNewUser($name, $email, $password, (string) ($_POST['password_confirm'] ?? ''));
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        if (strlen($phone) > 40) {
            throw new RuntimeException('O telefone pode ter no máximo 40 caracteres.');
        }
        authRegisterClient($name, $email, $phone, $password);
        flash('success', 'Cadastro de cliente concluído. Entre com seu e-mail e senha para continuar.');
        redirectTo('login.php');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Falha no cadastro de cliente: ' . $exception->getMessage());
        $error = str_contains(strtolower($exception->getMessage()), 'duplicate')
            ? 'Este e-mail já está cadastrado. Faça login ou entre em contato com a agência.'
            : 'Não foi possível concluir o cadastro. Tente novamente.';
    }
}

renderAuthHeader('Cadastro de cliente');
?>
        <h1>Crie sua conta</h1>
        <p class="auth-sub">Acesse os pacotes, consulte as vagas disponíveis e gerencie suas reservas.</p>
        <?php if ($error !== ''): ?><div class="flash error" role="alert"><?= h($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="on"><?= csrfField() ?>
            <label>Nome completo<input name="name" required minlength="2" maxlength="120" autofocus autocomplete="name" placeholder="Ex.: Ana Silva" value="<?= h($name) ?>"></label>
            <label>E-mail<input name="email" type="email" required maxlength="190" autocomplete="username" placeholder="voce@email.com" value="<?= h($email) ?>"></label>
            <label>Telefone <span class="optional">(opcional)</span><input name="phone" type="tel" maxlength="40" autocomplete="tel" placeholder="(11) 99999-9999" value="<?= h($phone) ?>"></label>
            <label>Senha<input name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
            <label>Confirmar senha<input name="password_confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
            <div class="form-actions"><button class="button primary" type="submit">Criar conta</button></div>
        </form>
        <p class="auth-links">Já tem conta? <a href="login.php">Entrar</a></p>
<?php renderAuthFooter();
