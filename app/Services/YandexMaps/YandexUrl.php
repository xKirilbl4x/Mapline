<?php

namespace App\Services\YandexMaps;

use InvalidArgumentException;

final class YandexUrl
{
    private const DOMAINS = [
        'yandex.ru',
        'yandex.com',
        'yandex.kz',
        'yandex.by',
        'yandex.uz',
        'yandex.com.tr',
    ];

    public static function normalize(string $value): string
    {
        $value = trim($value);
        $parts = parse_url($value);

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Нужна корректная ссылка на Яндекс.Карты.');
        }

        $host = strtolower($parts['host'] ?? '');
        $isYandexHost = collect(self::DOMAINS)->contains(
            fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain),
        );

        if (! $isYandexHost || ! str_contains(strtolower($parts['path'] ?? ''), '/maps')) {
            throw new InvalidArgumentException('Ссылка должна вести на карточку организации в Яндекс.Картах.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new InvalidArgumentException('Ссылка содержит недопустимые параметры.');
        }

        parse_str($parts['query'] ?? '', $query);
        $query = collect($query)
            ->reject(function (mixed $value, string|int $key): bool {
                $key = strtolower((string) $key);

                return str_starts_with($key, 'utm_')
                    || in_array($key, ['clid', 'from', 'page', 'source', 'tab'], true);
            })
            ->all();

        $path = $parts['path'] ?: '/maps';
        $normalized = strtolower($parts['scheme']).'://'.$host.$path;

        if ($query !== []) {
            $normalized .= '?'.http_build_query($query);
        }

        return $normalized;
    }

    public static function reviewsUrl(string $value, int $page = 1): string
    {
        $normalized = self::normalize($value);
        $parts = parse_url($normalized);

        if ($parts === false) {
            throw new InvalidArgumentException('Нужна корректная ссылка на Яндекс.Карты.');
        }

        $path = rtrim($parts['path'] ?? '/maps', '/');

        if (! str_ends_with($path, '/reviews')) {
            $path .= '/reviews';
        }

        $query = [];

        if ($page > 1) {
            $query['page'] = $page;
        }

        $url = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'yandex.ru').$path.'/';

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public static function isValid(string $value): bool
    {
        try {
            self::normalize($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
