<?php
declare(strict_types=1);

function createBookingWithCapacity(
    PDO $db,
    int $clientId,
    int $packageId,
    int $travelers,
    string $status = 'pending',
    bool $requireOpenTrip = false
): int {
    if ($clientId < 1 || $packageId < 1 || $travelers < 1) {
        throw new RuntimeException('Selecione um cliente, um pacote e uma quantidade válida de viajantes.');
    }
    if (!in_array($status, ['pending', 'confirmed'], true)) {
        throw new RuntimeException('Estado inicial da reserva inválido.');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            $requireOpenTrip
                ? 'SELECT seat_capacity, departure_date FROM packages WHERE id = ? FOR UPDATE'
                : 'SELECT seat_capacity FROM packages WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$packageId]);
        $package = $stmt->fetch();
        if (!$package) {
            throw new RuntimeException('O pacote selecionado não foi encontrado.');
        }
        if ($requireOpenTrip) {
            $stmt = $db->prepare("SELECT state FROM trip_control WHERE package_id = ? FOR UPDATE");
            $stmt->execute([$packageId]);
            $tripState = $stmt->fetchColumn() ?: 'open';
            if ($tripState !== 'open' || $package['departure_date'] <= date('Y-m-d')) {
                throw new RuntimeException('Este pacote não está mais disponível para reserva.');
            }
        }

        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(travelers), 0)
             FROM bookings WHERE package_id = ? AND status <> 'cancelled'"
        );
        $stmt->execute([$packageId]);
        $reserved = (int) $stmt->fetchColumn();
        if ($reserved + $travelers > (int) $package['seat_capacity']) {
            throw new RuntimeException('Não há vagas disponíveis suficientes para essa reserva.');
        }

        $stmt = $db->prepare('INSERT INTO bookings (client_id, package_id, travelers, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$clientId, $packageId, $travelers, $status]);
        $bookingId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return $bookingId;
}
