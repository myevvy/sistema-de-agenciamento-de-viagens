<?php
declare(strict_types=1);

define('CLIENT_PAGE', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$clientId = (int) $currentUser['client_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf();
        $action = postString('action');
        $bookingId = (int) ($_POST['booking_id'] ?? 0);

        if ($action === 'create_booking') {
            $packageId = filter_var($_POST['package_id'] ?? null, FILTER_VALIDATE_INT);
            $travelers = filter_var($_POST['travelers'] ?? null, FILTER_VALIDATE_INT);
            if ($packageId === false || $packageId === null || $travelers === false || $travelers === null) {
                throw new RuntimeException('Selecione um pacote e a quantidade de viajantes.');
            }
            $newBookingId = createBookingWithCapacity(
                $db,
                $clientId,
                (int) $packageId,
                (int) $travelers,
                'confirmed',
                true
            );
            flash('success', "Reserva #{$newBookingId} confirmada. O pagamento não é processado pelo site nesta etapa.");
            redirectTo('cliente.php#reservas');
        }

        if ($action === 'cancel_booking') {
            if ($bookingId < 1) {
                throw new RuntimeException('Reserva inválida.');
            }
            $db->beginTransaction();
            try {
                $stmt = $db->prepare(
                    'SELECT b.status, p.departure_date
                     FROM bookings b JOIN packages p ON p.id = b.package_id
                     WHERE b.id = ? AND b.client_id = ? FOR UPDATE'
                );
                $stmt->execute([$bookingId, $clientId]);
                $booking = $stmt->fetch();
                if (!$booking) {
                    throw new RuntimeException('Reserva não encontrada.');
                }
                if ($booking['status'] === 'cancelled') {
                    throw new RuntimeException('Esta reserva já foi cancelada.');
                }
                if ($booking['departure_date'] <= today()) {
                    throw new RuntimeException('Não é possível cancelar uma viagem que já começou.');
                }

                $stmt = $db->prepare('SELECT COUNT(*) FROM payments WHERE booking_id = ?');
                $stmt->execute([$bookingId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new RuntimeException('Esta reserva possui pagamentos lançados. Entre em contato com a agência para solicitar o cancelamento.');
                }
                $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND client_id = ?")
                    ->execute([$bookingId, $clientId]);
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            flash('success', 'Reserva cancelada. As vagas foram liberadas.');
            redirectTo('cliente.php#reservas');
        }

        throw new RuntimeException('Ação inválida.');
    } catch (PDOException $exception) {
        flash('error', dbErrorMessage($exception));
    } catch (RuntimeException $exception) {
        flash('error', $exception->getMessage());
    }
    redirectTo('cliente.php');
}

$availableTrips = array_values(array_filter(
    $db->query(tripOverviewSql("p.departure_date > '" . today() . "'", 'p.departure_date, p.id'))->fetchAll(),
    static fn(array $trip): bool => $trip['state'] === 'open' && (int) $trip['remaining_seats'] > 0
));

$stmt = $db->prepare(bookingFinanceSql('b.client_id = ?'));
$stmt->execute([$clientId]);
$bookings = $stmt->fetchAll();

renderClientHeader('Minha área');
?>
<section class="page-heading">
    <div><p class="eyebrow">ÁREA DO VIAJANTE</p><h1>Olá, <?= h($currentUser['name']) ?></h1><p>Escolha uma viagem e acompanhe suas reservas por aqui.</p></div>
</section>

<section id="pacotes">
    <div class="section-heading"><div><p class="eyebrow">PRÓXIMOS DESTINOS</p><h2>Pacotes disponíveis</h2><p>As vagas são atualizadas ao carregar a página e confirmadas novamente no momento da reserva.</p></div></div>
    <?php if (!$availableTrips): ?>
        <div class="panel empty-state"><span>✈</span><strong>Nenhum pacote disponível no momento</strong><p>Volte mais tarde para conferir novas viagens.</p></div>
    <?php else: ?>
        <div class="two-cols">
            <?php foreach ($availableTrips as $trip): ?>
            <article class="panel package-card">
                <div class="panel-heading"><div><h2><?= h($trip['title']) ?></h2><p><?= h($trip['destination']) ?></p></div><?= badge('sale', 'Disponível') ?></div>
                <div class="panel-body">
                    <div class="package-meta"><span>Partida<strong><?= h(brDate($trip['departure_date'])) ?></strong></span><span>Retorno<strong><?= h(brDate($trip['return_date'])) ?></strong></span><span>Vagas livres<strong><?= (int) $trip['remaining_seats'] ?></strong></span></div>
                    <?php if ($trip['description'] !== ''): ?><p class="package-description"><?= nl2br(h($trip['description'])) ?></p><?php endif; ?>
                    <p class="package-price"><?= h(money($trip['price'])) ?> <small>por viajante</small></p>
                    <form method="post" class="booking-form"><?= csrfField() ?><input type="hidden" name="action" value="create_booking"><input type="hidden" name="package_id" value="<?= (int) $trip['id'] ?>">
                        <label>Viajantes<input name="travelers" type="number" min="1" max="<?= (int) $trip['remaining_seats'] ?>" value="1" required></label>
                        <button class="button primary" type="submit">Confirmar reserva</button>
                        <small class="booking-note">A confirmação é imediata. O pagamento não é realizado pelo site nesta etapa.</small>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section id="reservas" class="panel list-panel client-bookings">
    <div class="panel-heading"><div><p class="eyebrow">SUAS VIAGENS</p><h2>Minhas reservas</h2><p><?= count($bookings) ?> reserva(s)</p></div></div>
    <?php if (!$bookings): ?>
        <div class="empty-state compact"><span>▤</span><strong>Você ainda não tem reservas</strong><p>Quando reservar uma viagem, ela aparecerá aqui.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>VIAGEM</th><th>PARTIDA</th><th>VIAJANTES</th><th class="num">TOTAL</th><th>STATUS</th><th>AÇÕES</th></tr></thead><tbody>
            <?php foreach ($bookings as $booking):
                $statusClass = match ($booking['status']) {
                    'confirmed' => 'confirmed',
                    'cancelled' => 'cancelled',
                    default => 'pending',
                };
                $canCancel = $booking['status'] !== 'cancelled'
                    && $booking['departure_date'] > today()
                    && (int) $booking['payment_count'] === 0;
            ?>
            <tr>
                <td><strong><?= h($booking['package_title']) ?></strong><small class="cell-sub"><?= h($booking['destination']) ?></small></td>
                <td><?= h(brDate($booking['departure_date'])) ?><small class="cell-sub">retorno <?= h(brDate($booking['return_date'])) ?></small></td>
                <td><?= (int) $booking['travelers'] ?></td>
                <td class="num"><?= h(money($booking['total'])) ?><small class="cell-sub">recebido: <?= h(money($booking['paid'])) ?></small></td>
                <td><?= badge($statusClass, BOOKING_LABELS[$booking['status']] ?? $booking['status']) ?></td>
                <td><?php if ($canCancel): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('Cancelar esta reserva? As vagas serão liberadas.')"><?= csrfField() ?><input type="hidden" name="action" value="cancel_booking"><input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>"><button class="button danger small" type="submit">Cancelar</button></form>
                <?php elseif ($booking['status'] !== 'cancelled' && $booking['departure_date'] > today()): ?>
                    <span class="muted">Para cancelar, fale com a agência</span>
                <?php else: ?>—<?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php renderFooter();
