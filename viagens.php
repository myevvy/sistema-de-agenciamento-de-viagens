<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$tripId = (int) ($_GET['id'] ?? 0);

/* ------------------------------------------------------------------ POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $packageId = (int) ($_POST['package_id'] ?? 0);
    $back = $packageId > 0 ? 'viagens.php?id=' . $packageId : 'viagens.php';

    try {
        requireCsrf();
        $action = postString('action');

        switch ($action) {
            case 'set_trip_state':
                $newState = postString('state');
                if (!isset(TRIP_STATE_LABELS[$newState])) {
                    throw new RuntimeException('Situação de viagem inválida.');
                }
                $db->beginTransaction();
                try {
                    $stmt = $db->prepare('SELECT * FROM packages WHERE id = ? FOR UPDATE');
                    $stmt->execute([$packageId]);
                    $package = $stmt->fetch();
                    if (!$package) {
                        throw new RuntimeException('Viagem não encontrada.');
                    }
                    $stmt = $db->prepare('SELECT * FROM trip_control WHERE package_id = ? FOR UPDATE');
                    $stmt->execute([$packageId]);
                    $control = $stmt->fetch() ?: null;
                    $current = $control['state'] ?? 'open';

                    if ($newState === $current) {
                        throw new RuntimeException('A viagem já está nessa situação.');
                    }
                    $now = today();
                    $stmt = $db->prepare("SELECT COALESCE(SUM(travelers), 0) FROM bookings WHERE package_id = ? AND status <> 'cancelled'");
                    $stmt->execute([$packageId]);
                    $reserved = (int) $stmt->fetchColumn();
                    $originalCapacity = $control['original_capacity'] ?? null;
                    $note = '';

                    if ($newState === 'suspended') {
                        if ($current !== 'open' || $package['departure_date'] <= $now) {
                            throw new RuntimeException('Só é possível suspender as vendas de uma viagem aberta que ainda não partiu.');
                        }
                        $originalCapacity = (int) $package['seat_capacity'];
                        $db->prepare('UPDATE packages SET seat_capacity = ? WHERE id = ?')->execute([$reserved, $packageId]);
                        $blocked = $reserved;
                        $note = 'Vendas suspensas. Novas reservas bloqueadas até a reabertura.';
                    } elseif ($newState === 'cancelled') {
                        if ($package['return_date'] < $now) {
                            throw new RuntimeException('Não é possível cancelar uma viagem já concluída.');
                        }
                        $originalCapacity = $originalCapacity ?? (int) $package['seat_capacity'];
                        $cancel = $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE package_id = ? AND status <> 'cancelled'");
                        $cancel->execute([$packageId]);
                        $count = $cancel->rowCount();
                        $db->prepare('UPDATE packages SET seat_capacity = 0 WHERE id = ?')->execute([$packageId]);
                        $blocked = 0;
                        $note = "Viagem cancelada. {$count} reserva(s) cancelada(s); confira estornos em Pagamentos.";
                    } else { // open
                        if ($originalCapacity !== null && (int) $package['seat_capacity'] === (int) $control['blocked_capacity']) {
                            $db->prepare('UPDATE packages SET seat_capacity = ? WHERE id = ?')->execute([(int) $originalCapacity, $packageId]);
                        }
                        $originalCapacity = null;
                        $blocked = null;
                        $note = $current === 'cancelled'
                            ? 'Viagem reaberta. A capacidade foi restaurada; as reservas canceladas continuam canceladas.'
                            : 'Vendas reabertas. Capacidade restaurada.';
                    }

                    $db->prepare(
                        'INSERT INTO trip_control (package_id, state, original_capacity, blocked_capacity) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE state = VALUES(state), original_capacity = VALUES(original_capacity), blocked_capacity = VALUES(blocked_capacity)'
                    )->execute([$packageId, $newState, $originalCapacity, $blocked]);
                    $db->prepare('INSERT INTO trip_notes (package_id, note) VALUES (?, ?)')->execute([$packageId, $note]);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
                flash('success', 'Situação da viagem atualizada.');
                break;

            case 'add_note':
                $note = postString('note');
                if ($note === '' || (function_exists('mb_strlen') ? mb_strlen($note) : strlen($note)) > 500) {
                    throw new RuntimeException('Escreva uma anotação de até 500 caracteres.');
                }
                $stmt = $db->prepare('SELECT 1 FROM packages WHERE id = ?');
                $stmt->execute([$packageId]);
                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException('Viagem não encontrada.');
                }
                $db->prepare('INSERT INTO trip_notes (package_id, note) VALUES (?, ?)')->execute([$packageId, $note]);
                flash('success', 'Anotação registrada.');
                break;

            case 'delete_note':
                $db->prepare('DELETE FROM trip_notes WHERE id = ? AND package_id = ?')->execute([(int) ($_POST['id'] ?? 0), $packageId]);
                flash('success', 'Anotação removida.');
                break;

            default:
                throw new RuntimeException('Ação inválida.');
        }
    } catch (PDOException $exception) {
        flash('error', dbErrorMessage($exception));
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
    }
    redirectTo($back);
}

/** Dias até a partida (negativo se já partiu). */
function daysUntil(string $date): int
{
    return (int) (new DateTimeImmutable(today()))->diff(new DateTimeImmutable($date))->format('%r%a');
}

/* ------------------------------------------------------------------ DETALHE */
if ($tripId > 0) {
    $stmt = $db->prepare(tripOverviewSql('p.id = ?'));
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch();
    if (!$trip) {
        flash('error', 'Viagem não encontrada.');
        redirectTo('viagens.php');
    }
    [$tripClass, $tripLabel] = tripSituation($trip);

    $stmt = $db->prepare(bookingFinanceSql('b.package_id = ?', "FIELD(b.status, 'confirmed', 'pending', 'cancelled'), c.name"));
    $stmt->execute([$tripId]);
    $rows = $stmt->fetchAll();

    $expected = 0;
    $received = 0;
    $toRefund = 0;
    foreach ($rows as $r) {
        if ($r['status'] !== 'cancelled') {
            $expected += toCents($r['total']);
            $received += toCents($r['paid']);
        } else {
            $toRefund += toCents($r['paid']);
        }
    }
    $stmt = $db->prepare('SELECT * FROM trip_notes WHERE package_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$tripId]);
    $notes = $stmt->fetchAll();

    $capacity = (int) $trip['seat_capacity'];
    $reserved = (int) $trip['reserved'];
    $days = daysUntil($trip['departure_date']);
    $state = $trip['state'];

    renderHeader('viagens', $trip['title'], 'Situação das viagens / ' . $trip['title']);
    ?>
    <section class="page-heading"><div><p class="eyebrow"><a href="viagens.php">← SITUAÇÃO DAS VIAGENS</a></p><h1><?= h($trip['title']) ?> <?= badge($tripClass, $tripLabel) ?></h1><p><?= h($trip['destination']) ?> · <?= h(brDate($trip['departure_date'])) ?> a <?= h(brDate($trip['return_date'])) ?></p></div><a class="button secondary" href="index.php?page=packages&amp;edit=<?= (int) $trip['id'] ?>">✎ Editar pacote</a></section>

    <section class="stats four">
        <article class="stat-card"><span class="stat-icon blue">♙</span><div><p>Vagas ocupadas</p><strong><?= $reserved ?><?= $state === 'open' ? ' / ' . $capacity : '' ?></strong></div><small><?= $state === 'open' ? (int) $trip['remaining_seats'] . ' disponíveis' : 'vendas bloqueadas' ?></small></article>
        <article class="stat-card"><span class="stat-icon violet">▤</span><div><p>Reservas ativas</p><strong><?= (int) $trip['confirmed_bookings'] + (int) $trip['pending_bookings'] ?></strong></div><small><?= (int) $trip['confirmed_bookings'] ?> confirmadas · <?= (int) $trip['pending_bookings'] ?> pendentes</small></article>
        <article class="stat-card"><span class="stat-icon orange">＄</span><div><p>Previsto</p><strong class="money"><?= h(moneyCents($expected)) ?></strong></div><small>reservas ativas</small></article>
        <article class="stat-card"><span class="stat-icon green">✓</span><div><p>Recebido</p><strong class="money"><?= h(moneyCents($received)) ?></strong></div><small>faltam <?= h(moneyCents(max(0, $expected - $received))) ?></small></article>
    </section>

    <div class="stack">
    <?php if ($toRefund > 0): ?>
        <div class="alert-box danger" style="margin:0">Há <strong><?= h(moneyCents($toRefund)) ?></strong> recebidos em reservas canceladas desta viagem. Registre o estorno em <a href="pagamentos.php">Pagamentos</a>.</div>
    <?php endif; ?>

    <div class="two-cols">
        <section class="panel"><div class="panel-heading"><div><h2>Controle da viagem</h2><p>
            <?php if ($tripClass === 'done'): ?>Viagem concluída: nenhuma ação operacional disponível.
            <?php elseif ($tripClass === 'running'): ?>Viagem em andamento (os passageiros já embarcaram).
            <?php else: ?>Embarque <?= $days === 0 ? 'hoje' : ($days === 1 ? 'amanhã' : "em {$days} dias") ?>.<?php endif; ?></p></div></div>
            <div class="trip-meta"><span>Situação atual: <b><?= h($tripLabel) ?></b></span><span>Ocupação: <b><?= $state === 'open' ? occupationPercent($reserved, $capacity) . '%' : '—' ?></b></span></div>
            <?php if ($state === 'open' && $tripClass !== 'done'): ?><div class="trip-meta"><?= progressBar(occupationPercent($reserved, $capacity)) ?></div><?php endif; ?>
            <div class="panel-body"><div class="actions-wrap">
                <?php if ($state !== 'open'): ?>
                    <form method="post" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="set_trip_state"><input type="hidden" name="package_id" value="<?= (int) $trip['id'] ?>"><input type="hidden" name="state" value="open"><button class="button success" type="submit">Reabrir vendas</button></form>
                <?php endif; ?>
                <?php if ($state === 'open' && $trip['departure_date'] > today()): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Suspender as vendas desta viagem?')"><?= csrfField() ?><input type="hidden" name="action" value="set_trip_state"><input type="hidden" name="package_id" value="<?= (int) $trip['id'] ?>"><input type="hidden" name="state" value="suspended"><button class="button secondary" type="submit">Suspender vendas</button></form>
                <?php endif; ?>
                <?php if ($state !== 'cancelled' && $trip['return_date'] >= today()): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Cancelar esta viagem? Todas as reservas ativas serão canceladas.')"><?= csrfField() ?><input type="hidden" name="action" value="set_trip_state"><input type="hidden" name="package_id" value="<?= (int) $trip['id'] ?>"><input type="hidden" name="state" value="cancelled"><button class="button danger" type="submit">Cancelar viagem</button></form>
                <?php endif; ?>
            </div>
            <p class="muted" style="font-size:11px;line-height:1.6;margin:14px 0 0">Ao suspender ou cancelar, a capacidade do pacote é zerada para novas vendas (o valor original fica guardado e é restaurado ao reabrir). Assim a tela de reservas existente também deixa de aceitar novas reservas para esta viagem.</p></div>
        </section>

        <section class="panel"><div class="panel-heading"><div><h2>Acompanhamento</h2><p>Registre ocorrências e decisões desta viagem.</p></div></div>
            <div class="panel-body"><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="add_note"><input type="hidden" name="package_id" value="<?= (int) $trip['id'] ?>">
                <label>Nova anotação<textarea name="note" rows="2" maxlength="500" required placeholder="Ex.: Voo confirmado, hotel fechou bloqueio de 10 quartos..."></textarea></label>
                <div class="form-actions"><button class="button primary small" type="submit">Registrar</button></div></form></div>
            <?php if ($notes): ?><ul class="timeline">
                <?php foreach ($notes as $n): ?><li><time><?= h(date('d/m/Y H:i', strtotime($n['created_at']))) ?></time><?= h($n['note']) ?>
                    <form method="post" onsubmit="return confirm('Remover esta anotação?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_note"><input type="hidden" name="package_id" value="<?= (int) $trip['id'] ?>"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button type="submit">remover</button></form></li><?php endforeach; ?>
            </ul><?php endif; ?>
        </section>
    </div>

    <section class="panel list-panel"><div class="panel-heading"><div><h2>Passageiros e reservas</h2><p><?= count($rows) ?> reserva(s) nesta viagem</p></div></div>
        <?php if (!$rows): ?><div class="empty-state compact"><span>▤</span><strong>Nenhuma reserva ainda</strong><p>As reservas feitas para este pacote aparecerão aqui.</p></div>
        <?php else: ?><div class="table-wrap"><table><thead><tr><th>CLIENTE</th><th>PESSOAS</th><th>RESERVA</th><th class="num">TOTAL</th><th class="num">RECEBIDO</th><th>PAGAMENTO</th></tr></thead><tbody>
            <?php foreach ($rows as $r): [$cls, $label] = paymentSituation($r); ?>
            <tr><td><strong><?= h($r['client_name']) ?></strong><small class="cell-sub"><?= h($r['client_email']) ?></small></td><td><?= (int) $r['travelers'] ?></td><td><span class="badge <?= h($r['status']) ?>"><?= h(BOOKING_LABELS[$r['status']] ?? $r['status']) ?></span></td><td class="num"><?= h(money($r['total'])) ?></td><td class="num pos"><?= h(money($r['paid'])) ?></td><td><?= badge($cls, $label) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
    </div>
    <?php
    renderFooter();
    exit;
}

/* ------------------------------------------------------------------ LISTA */
$filters = [
    'all' => 'Todas', 'sale' => 'Em venda', 'full' => 'Lotadas', 'running' => 'Em andamento',
    'done' => 'Concluídas', 'suspended' => 'Suspensas', 'cancelled' => 'Canceladas',
];
$filter = (string) ($_GET['situacao'] ?? 'all');
if (!isset($filters[$filter])) {
    $filter = 'all';
}

$trips = $db->query(tripOverviewSql())->fetchAll();
$receivedMap = receivedByPackage($db);
$expectedMap = [];
foreach ($db->query(
    "SELECT b.package_id, SUM(ROUND(p.price * b.travelers, 2)) AS expected
     FROM bookings b JOIN packages p ON p.id = b.package_id
     WHERE b.status <> 'cancelled' GROUP BY b.package_id"
)->fetchAll() as $row) {
    $expectedMap[(int) $row['package_id']] = toCents($row['expected']);
}

$counts = array_fill_keys(array_keys($filters), 0);
foreach ($trips as $i => $t) {
    [$cls, $label] = tripSituation($t);
    $trips[$i]['_cls'] = $cls;
    $trips[$i]['_label'] = $label;
    $counts['all']++;
    $counts[$cls]++;
}
$visible = array_values(array_filter($trips, static fn(array $t): bool => $filter === 'all' || $t['_cls'] === $filter));

renderHeader('viagens', 'Situação das viagens', 'Situação das viagens');
?>
<section class="page-heading"><div><p class="eyebrow">ACOMPANHAMENTO</p><h1>Situação das viagens</h1><p>Ocupação, financeiro e andamento de cada pacote em um só lugar.</p></div><a class="button primary" href="index.php?page=packages">＋ Novo pacote</a></section>

<section class="panel list-panel"><div class="panel-heading"><div><h2>Viagens</h2><p><?= count($visible) ?> de <?= $counts['all'] ?> viagem(ns)</p></div></div>
    <div class="tabs"><?php foreach ($filters as $key => $label): ?><a class="<?= $filter === $key ? 'active' : '' ?>" href="viagens.php<?= $key === 'all' ? '' : '?situacao=' . h($key) ?>"><?= h($label) ?> (<?= (int) $counts[$key] ?>)</a><?php endforeach; ?></div>
    <?php if (!$visible): ?>
        <div class="empty-state compact"><span>✈</span><strong>Nenhuma viagem nesta situação</strong><p>Cadastre pacotes ou escolha outro filtro.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>VIAGEM</th><th>PERÍODO</th><th>OCUPAÇÃO</th><th>RESERVAS</th><th class="num">RECEBIDO / PREVISTO</th><th>SITUAÇÃO</th><th></th></tr></thead><tbody>
        <?php foreach ($visible as $t):
            $capacity = (int) $t['seat_capacity'];
            $reserved = (int) $t['reserved'];
            $pid = (int) $t['id'];
            $days = daysUntil($t['departure_date']);
            $isOpen = $t['state'] === 'open';
        ?>
        <tr>
            <td><strong><?= h($t['title']) ?></strong><small class="cell-sub"><?= h($t['destination']) ?></small></td>
            <td><?= h(brDate($t['departure_date'])) ?><small class="cell-sub">até <?= h(brDate($t['return_date'])) ?><?= ($days >= 0 && $days <= 30 && $t['_cls'] !== 'cancelled') ? ' · embarca em ' . ($days === 0 ? 'hoje' : $days . ' dia(s)') : '' ?></small></td>
            <td><?= $isOpen ? progressBar(occupationPercent($reserved, $capacity)) : '' ?><span class="progress-label"><?= $reserved ?><?= $isOpen ? ' de ' . $capacity . ' vagas' : ' viajante(s)' ?></span></td>
            <td><?= (int) $t['confirmed_bookings'] ?> confirmada(s)<small class="cell-sub"><?= (int) $t['pending_bookings'] ?> pendente(s)</small></td>
            <td class="num"><span class="pos"><?= h(moneyCents($receivedMap[$pid] ?? 0)) ?></span><small class="cell-sub">de <?= h(moneyCents($expectedMap[$pid] ?? 0)) ?></small></td>
            <td><?= badge($t['_cls'], $t['_label']) ?></td>
            <td><a class="button secondary small" href="viagens.php?id=<?= $pid ?>">Detalhes</a></td>
        </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php renderFooter();
