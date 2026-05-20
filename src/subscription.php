<?php

declare(strict_types=1);

const AKINO_PLAN_CODE = 'akino-single';

function get_subscription_plan(): array
{
    $statement = db()->prepare('SELECT * FROM subscription_plans WHERE code = :code LIMIT 1');
    $statement->execute(['code' => AKINO_PLAN_CODE]);
    $plan = $statement->fetch();

    if (!$plan) {
        throw new RuntimeException('План подписки AKINO не найден.');
    }

    return $plan;
}

function get_active_subscription_row(int $userId): ?array
{
    $statement = db()->prepare(
        'SELECT us.*, sp.name AS plan_name, sp.description AS plan_description, sp.price AS plan_price, sp.duration_days
         FROM user_subscriptions us
         INNER JOIN subscription_plans sp ON sp.id = us.plan_id
         WHERE us.user_id = :user_id AND us.ends_at >= NOW()
         ORDER BY us.ends_at DESC
         LIMIT 1'
    );

    $statement->execute(['user_id' => $userId]);
    $subscription = $statement->fetch();

    return $subscription ?: null;
}

function activate_subscription(int $userId): array
{
    $plan = get_subscription_plan();
    $current = get_active_subscription_row($userId);
    $db = db();

    $db->beginTransaction();

    try {
        $now = new DateTimeImmutable();

        if ($current) {
            $baseDate = new DateTimeImmutable($current['ends_at']);
            $newEndDate = $baseDate->modify('+' . (int) $plan['duration_days'] . ' days');

            $update = $db->prepare(
                'UPDATE user_subscriptions
                 SET ends_at = :ends_at, updated_at = NOW()
                 WHERE id = :id'
            );

            $update->execute([
                'id' => (int) $current['id'],
                'ends_at' => $newEndDate->format('Y-m-d H:i:s'),
            ]);
        } else {
            $endDate = $now->modify('+' . (int) $plan['duration_days'] . ' days');

            $insert = $db->prepare(
                'INSERT INTO user_subscriptions (user_id, plan_id, started_at, ends_at, created_at, updated_at)
                 VALUES (:user_id, :plan_id, :started_at, :ends_at, NOW(), NOW())'
            );

            $insert->execute([
                'user_id' => $userId,
                'plan_id' => (int) $plan['id'],
                'started_at' => $now->format('Y-m-d H:i:s'),
                'ends_at' => $endDate->format('Y-m-d H:i:s'),
            ]);
        }

        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }

    return get_user_subscription_payload($userId);
}

function get_user_subscription_payload(int $userId): array
{
    $subscription = get_active_subscription_row($userId);

    if (!$subscription) {
        $plan = get_subscription_plan();

        return [
            'active' => false,
            'name' => $plan['name'],
            'description' => $plan['description'],
            'price' => (float) $plan['price'],
            'priceDisplay' => number_format((float) $plan['price'], 0, ',', ' ') . ' ₽',
            'durationDays' => (int) $plan['duration_days'],
            'endsAt' => null,
            'endsAtDisplay' => 'Не активна',
        ];
    }

    $endDate = new DateTimeImmutable($subscription['ends_at']);

    return [
        'active' => true,
        'name' => $subscription['plan_name'],
        'description' => $subscription['plan_description'],
        'price' => (float) $subscription['plan_price'],
        'priceDisplay' => number_format((float) $subscription['plan_price'], 0, ',', ' ') . ' ₽',
        'durationDays' => (int) $subscription['duration_days'],
        'endsAt' => $subscription['ends_at'],
        'endsAtDisplay' => $endDate->format('d.m.Y'),
    ];
}
