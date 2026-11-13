<?php
declare(strict_types=1);

final class BusinessCalendar
{
    public const TIMEZONE = 'Europe/Lisbon';

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }

    public static function startOfDayUtc(string $date): string
    {
        return self::localDate($date)->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function startOfNextDayUtc(string $date): string
    {
        return self::localDate($date)->setTime(0, 0)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function rangeUtc(string $from, string $to): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException('O início do período não pode ser posterior ao fim.');
        }

        return [
            'start' => self::startOfDayUtc($from),
            'end' => self::startOfNextDayUtc($to),
        ];
    }

    public static function validDate(string $date): bool
    {
        return self::isDate($date);
    }

    private static function localDate(string $date): DateTimeImmutable
    {
        if (!self::isDate($date)) {
            throw new InvalidArgumentException('A data indicada não é válida.');
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $date, self::timezone());
    }

    private static function isDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, self::timezone());

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
