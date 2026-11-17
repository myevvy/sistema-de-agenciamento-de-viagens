<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$me = $currentUser;
$isAdmin = authIsAdmin($me);

/** Quantos administradores ativos existem além do usuário informado. */
function otherActiveAdmins(PDO $db, int $exceptId): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?");
    $stmt->execute([$exceptId]);
    return (int) $stmt->fetchColumn();
}

/* ------------------------------------------------------------------ POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf();
        $action = postString('action');
        $targetId = (int) ($_POST['id'] ?? 0);

        if ($action === 'change_own_password') {
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([(int) $me['id']]);
            $hash = (string) $stmt->fetchColumn();
            if (!password_verify((string) ($_POST['current_password'] ?? ''), $hash)) {
                throw new RuntimeException('A senha atual está incorreta.');
            }
            $problem = authValidatePassword((string) ($_POST['new_password'] ?? ''), (string) ($_POST['new_password_confirm'] ?? ''));
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash((string) $_POST['new_password'], PASSWORD_DEFAULT), (int) $me['id']]);
            session_regenerate_id(true);
            flash('success', 'Senha alterada com sucesso.');
            redirectTo('usuarios.php');
        }

        if (!$isAdmin) {
            throw new RuntimeException('Somente administradores podem gerenciar usuários.');
        }

        switch ($action) {
            case 'create_user':
                $name = postString('name');
                $email = postString('email');
                $role = postString('role');
                $password = (string) ($_POST['password'] ?? '');
                $problem = authValidateNewUser($name, $email, $password, (string) ($_POST['password_confirm'] ?? ''));
                if ($problem !== null) {
                    throw new RuntimeException($problem);
                }
                if (!isset(AUTH_ROLES[$role])) {
                    throw new RuntimeException('Perfil inválido.');
                }
                $db->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)')
                    ->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT), $role]);
                flash('success', 'Usuário criado e ativo.');
                break;

            case 'set_active':
                $active = (int) ($_POST['active'] ?? 0) === 1 ? 1 : 0;
                $stmt = $db->prepare('SELECT role, active FROM users WHERE id = ?');
                $stmt->execute([$targetId]);
                $target = $stmt->fetch();
                if (!$target) {
                    throw new RuntimeException('Usuário não encontrado.');
                }
                if ($active === 0 && $targetId === (int) $me['id']) {
                    throw new RuntimeException('Você não pode desativar a sua própria conta.');
                }
                if ($active === 0 && $target['role'] === 'admin' && (int) $target['active'] === 1 && otherActiveAdmins($db, $targetId) < 1) {
                    throw new RuntimeException('É preciso manter pelo menos um administrador ativo.');
                }
                $db->prepare('UPDATE users SET active = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$active, $targetId]);
                flash('success', $active === 1 ? 'Usuário ativado.' : 'Usuário desativado.');
                break;

            case 'set_role':
                $role = postString('role');
                if (!isset(AUTH_ROLES[$role])) {
                    throw new RuntimeException('Perfil inválido.');
                }
                $stmt = $db->prepare('SELECT role, active FROM users WHERE id = ?');
                $stmt->execute([$targetId]);
                $target = $stmt->fetch();
                if (!$target) {
                    throw new RuntimeException('Usuário não encontrado.');
                }
                if ($targetId === (int) $me['id']) {
                    throw new RuntimeException('Você não pode alterar o seu próprio perfil.');
                }
                if ($role !== 'admin' && $target['role'] === 'admin' && (int) $target['active'] === 1 && otherActiveAdmins($db, $targetId) < 1) {
                    throw new RuntimeException('É preciso manter pelo menos um administrador ativo.');
                }
                $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
                flash('success', 'Perfil atualizado.');
                break;

            case 'reset_password':
                $problem = authValidatePassword((string) ($_POST['password'] ?? ''), (string) ($_POST['password'] ?? ''));
                if ($problem !== null) {
                    throw new RuntimeException($problem);
                }
                $stmt = $db->prepare('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?');
                $stmt->execute([password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $targetId]);
                flash('success', 'Senha redefinida. Informe a nova senha ao usuário.');
                break;

            case 'delete_user':
                $stmt = $db->prepare('SELECT role, active FROM users WHERE id = ?');
                $stmt->execute([$targetId]);
                $target = $stmt->fetch();
                if (!$target) {
                    throw new RuntimeException('Usuário não encontrado.');
                }
                if ($targetId === (int) $me['id']) {
                    throw new RuntimeException('Você não pode remover a sua própria conta.');
                }
                if ($target['role'] === 'admin' && (int) $target['active'] === 1 && otherActiveAdmins($db, $targetId) < 1) {
                    throw new RuntimeException('É preciso manter pelo menos um administrador ativo.');
                }
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
                flash('success', 'Usuário removido.');
                break;

            default:
                throw new RuntimeException('Ação inválida.');
        }
    } catch (PDOException $exception) {
        flash('error', str_contains(strtolower($exception->getMessage()), 'duplicate')
            ? 'Este e-mail já está cadastrado.'
            : 'Não foi possível salvar os dados. Tente novamente.');
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
    }
    redirectTo('usuarios.php');
}

$users = $isAdmin ? $db->query('SELECT * FROM users ORDER BY active ASC, name')->fetchAll() : [];
$pending = count(array_filter($users, static fn(array $u): bool => (int) $u['active'] === 0));

renderHeader('usuarios', $isAdmin ? 'Usuários' : 'Minha conta', $isAdmin ? 'Usuários' : 'Minha conta');
?>
<section class="page-heading"><div><p class="eyebrow">ACESSO AO SISTEMA</p><h1><?= $isAdmin ? 'Usuários' : 'Minha conta' ?></h1><p><?= $isAdmin ? 'Aprove cadastros, defina perfis e gerencie quem acessa a agência.' : 'Altere a sua senha de acesso.' ?></p></div></section>

<div class="stack">
<?php if ($isAdmin): ?>
<div class="management-grid">
    <section class="panel form-panel"><div class="panel-heading"><div><h2>Novo usuário</h2><p>A conta criada aqui já nasce ativa.</p></div></div>
        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create_user">
            <label>Nome completo<input name="name" required maxlength="120" placeholder="Ex.: Carlos Souza"></label>
            <label>E-mail<input name="email" type="email" required maxlength="190" placeholder="carlos@agencia.com"></label>
            <label>Perfil<select name="role"><?php foreach (AUTH_ROLES as $key => $label): ?><option value="<?= h($key) ?>" <?= $key === 'staff' ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
            <div class="field-row"><label>Senha<input name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
                <label>Confirmar<input name="password_confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label></div>
            <div class="form-actions"><button class="button primary" type="submit">Criar usuário</button></div>
        </form>
    </section>

    <section class="panel list-panel"><div class="panel-heading"><div><h2>Usuários cadastrados</h2><p><?= count($users) ?> usuário(s)<?= $pending > 0 ? ' · ' . $pending . ' aguardando aprovação' : '' ?></p></div></div>
        <div class="table-wrap"><table><thead><tr><th>USUÁRIO</th><th>PERFIL</th><th>SITUAÇÃO</th><th>ÚLTIMO ACESSO</th><th>AÇÕES</th></tr></thead><tbody>
            <?php foreach ($users as $u): $isSelf = (int) $u['id'] === (int) $me['id']; $active = (int) $u['active'] === 1; ?>
            <tr>
                <td><strong><?= h($u['name']) ?><?= $isSelf ? ' (você)' : '' ?></strong><small class="cell-sub"><?= h($u['email']) ?></small></td>
                <td><?php if ($isSelf): ?><?= badge('running', AUTH_ROLES[$u['role']] ?? $u['role']) ?><?php else: ?>
                    <form method="post" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <select name="role" class="role-select" aria-label="Perfil" onchange="this.form.submit()"><?php foreach (AUTH_ROLES as $key => $label): ?><option value="<?= h($key) ?>" <?= $u['role'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></form><?php endif; ?></td>
                <td><?= $active ? badge('paid', 'Ativo') : badge('partial', 'Aguardando / inativo') ?></td>
                <td><?= $u['last_login_at'] ? h(date('d/m/Y H:i', strtotime($u['last_login_at']))) : '—' ?></td>
                <td><div class="actions-wrap">
                    <?php if (!$active): ?>
                        <form method="post" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="set_active"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="active" value="1"><button class="button success small" type="submit">Aprovar</button></form>
                    <?php elseif (!$isSelf): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('Desativar este usuário?')"><?= csrfField() ?><input type="hidden" name="action" value="set_active"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="active" value="0"><button class="button secondary small" type="submit">Desativar</button></form>
                    <?php endif; ?>
                    <details class="reset"><summary>Redefinir senha</summary>
                        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input name="password" type="password" required minlength="8" maxlength="72" placeholder="Nova senha" autocomplete="new-password"><button class="button primary small" type="submit">Salvar</button></form></details>
                    <?php if (!$isSelf): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('Remover este usuário?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="icon-button danger" aria-label="Remover usuário">×</button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php endif; ?>

<section class="panel form-panel" style="max-width:420px"><div class="panel-heading"><div><h2>Alterar minha senha</h2><p><?= h($me['name']) ?> · <?= h($me['email']) ?></p></div></div>
    <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="change_own_password">
        <label>Senha atual<input name="current_password" type="password" required autocomplete="current-password"></label>
        <label>Nova senha<input name="new_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
        <label>Confirmar nova senha<input name="new_password_confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
        <div class="form-actions"><button class="button primary" type="submit">Alterar senha</button></div>
    </form>
</section>
</div>
<?php renderFooter();
