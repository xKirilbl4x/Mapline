<?php

namespace App\Services\YandexMaps;

use App\Exceptions\YandexSourceChangedException;
use App\Exceptions\YandexSourceException;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class YandexMapsParser
{
    private const PAGE_SIZE = 50;

    private const REVIEW_BODY_KEYS = [
        'reviewText',
        'comment',
        'body',
        'content',
        'text',
        'description',
    ];

    private const REVIEW_DATE_KEYS = [
        'datePublished',
        'publishedAt',
        'createdAt',
        'updatedTime',
        'date',
        'time',
    ];

    public function parse(string $url, ?Closure $onProgress = null): array
    {
        $normalizedUrl = YandexUrl::normalize($url);
        $reviewUrl = YandexUrl::reviewsUrl($normalizedUrl);
        $response = $this->http()->get($reviewUrl);

        if ($response->failed()) {
            throw new YandexSourceException(
                "Яндекс.Карты вернули HTTP {$response->status()} при загрузке карточки.",
            );
        }

        $html = $response->body();

        if ($this->looksBlocked($response, $html)) {
            throw new YandexSourceException(
                'Источник ответил защитой от автоматических запросов. Попробуйте повторить позже.',
            );
        }

        $documents = $this->extractDocuments($html);
        $organization = $this->extractOrganization($documents, $html);
        $reviews = $this->extractReviews($documents, $reviewUrl);
        $this->notify($onProgress, count($reviews), $organization['reviews_count'] ?? null);

        $maxReviews = max(self::PAGE_SIZE, (int) config('services.yandex.max_reviews', 600));
        $maxPages = (int) ceil($maxReviews / self::PAGE_SIZE);

        for ($page = 2; $page <= $maxPages && count($reviews) < $maxReviews; $page++) {
            $this->pauseBetweenRequests();

            $pageResponse = $this->http()->get(YandexUrl::reviewsUrl($normalizedUrl, $page));

            if ($pageResponse->failed()) {
                break;
            }

            $pageHtml = $pageResponse->body();

            if ($this->looksBlocked($pageResponse, $pageHtml)) {
                throw new YandexSourceException(
                    'Источник ответил защитой от автоматических запросов. Попробуйте повторить позже.',
                );
            }

            $pageDocuments = $this->extractDocuments($pageHtml);
            $pageReviews = $this->extractReviews($pageDocuments, $reviewUrl);

            if ($pageReviews === []) {
                break;
            }

            $organization = $this->mergeOrganization(
                $organization,
                $this->extractOrganization($pageDocuments, $pageHtml),
            );

            foreach ($pageReviews as $review) {
                $reviews[$review['external_id']] = $review;
            }

            $reviews = array_slice($reviews, 0, $maxReviews, true);
            $this->notify($onProgress, count($reviews), $organization['reviews_count'] ?? null);
        }

        $organization = $this->mergeOrganization(
            $organization,
            $this->extractOrganizationFromHtml($html),
        );

        $organization['reviews_count'] = $organization['reviews_count'] ?: count($reviews);
        $organization['source_hash'] = sha1($html);

        if (
            $organization['name'] === null
            && $organization['rating'] === null
            && $organization['ratings_count'] === 0
            && $organization['reviews_count'] === 0
            && $reviews === []
        ) {
            throw new YandexSourceChangedException(
                'Не удалось распознать карточку организации. Вероятно, изменилась разметка Яндекс.Карт.',
            );
        }

        return [
            'organization' => $organization,
            'reviews' => array_values($reviews),
        ];
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.7',
            'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
        ])->connectTimeout(10)->timeout(25)->retry(2, 500, null, false);
    }

    private function pauseBetweenRequests(): void
    {
        $delay = max(0, (int) config('services.yandex.request_delay_ms', 350));

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    private function looksBlocked(Response $response, string $html): bool
    {
        if ($response->status() === 403 || $response->status() === 429) {
            return true;
        }

        $html = mb_strtolower($html);

        return str_contains($html, 'smartcaptcha')
            || str_contains($html, 'проверка браузера')
            || str_contains($html, 'доступ ограничен');
    }

    private function extractDocuments(string $html): array
    {
        $documents = [];
        preg_match_all(
            '/<script\b(?=[^>]*(?:type=["\']application\/(?:ld\+json|json)["\']|id=["\'](?:state|__PRELOADED_STATE__)["\']))[^>]*>(.*?)<\/script>/is',
            $html,
            $matches,
        );

        foreach ($matches[1] ?? [] as $raw) {
            $decoded = $this->decodeJson($raw);

            if ($decoded !== null) {
                $documents[] = $decoded;
            }
        }

        return $documents;
    }

    private function decodeJson(string $raw): mixed
    {
        $raw = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($raw === '') {
            return null;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    private function extractOrganization(array $documents, ?string $html = null): array
    {
        $best = [
            'score' => 0,
            'name' => null,
            'address' => null,
            'external_id' => null,
            'rating' => null,
            'ratings_count' => 0,
            'reviews_count' => 0,
            'source_hash' => null,
        ];

        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }

            foreach ($this->walk($document) as $node) {
                $name = $this->textField($node, ['name', 'title', 'displayName', 'businessName']);
                $ratingData = is_array($node['ratingData'] ?? null) ? $node['ratingData'] : [];
                $rating = $this->numberField($node, ['ratingValue', 'rating', 'score'])
                    ?? $this->numberField($ratingData, ['ratingValue', 'rating', 'score']);
                $ratingsCount = $this->integerField($node, ['ratingCount', 'ratingsCount', 'votesCount'])
                    ?: $this->integerField($ratingData, ['ratingCount', 'ratingsCount', 'votesCount']);
                $reviewsCount = $this->integerField($node, ['reviewCount', 'reviewsCount', 'commentsCount'])
                    ?: $this->integerField($ratingData, ['reviewCount', 'reviewsCount', 'commentsCount']);

                if ($name === null && $rating === null && $ratingsCount === 0 && $reviewsCount === 0) {
                    continue;
                }

                $score = ($name !== null ? 2 : 0)
                    + ($rating !== null ? 3 : 0)
                    + ($ratingsCount > 0 ? 2 : 0)
                    + ($reviewsCount > 0 ? 2 : 0)
                    + (array_key_exists('aggregateRating', $node) || $ratingData !== [] ? 3 : 0)
                    + ($this->organizationId($node) !== null ? 2 : 0);

                if ($score < $best['score']) {
                    continue;
                }

                $best = [
                    'score' => $score,
                    'name' => $name,
                    'address' => $this->textField($node, ['fullAddress', 'address', 'formattedAddress', 'addressText']),
                    'external_id' => $this->organizationId($node),
                    'rating' => $rating !== null && $rating >= 0 && $rating <= 5 ? $rating : null,
                    'ratings_count' => $ratingsCount,
                    'reviews_count' => $reviewsCount,
                    'source_hash' => null,
                ];
            }
        }

        unset($best['score']);

        if ($html !== null) {
            $best = $this->mergeOrganization($best, $this->extractOrganizationFromHtml($html));
        }

        return $best;
    }

    private function extractOrganizationFromHtml(string $html): array
    {
        $name = null;

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $match)) {
            $name = $this->normalizeText($match[1]);
        }

        if ($name === null && preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', $html, $match)) {
            $name = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $rating = null;
        if (preg_match('/itemprop=["\']ratingValue["\'][^>]+content=["\']([0-5](?:[.,]\d+)?)/i', $html, $match)) {
            $rating = (float) str_replace(',', '.', $match[1]);
        }

        $ratingsCount = $this->htmlInteger($html, 'ratingCount');
        $reviewsCount = $this->htmlInteger($html, 'reviewCount');

        return [
            'name' => $name ?: null,
            'address' => null,
            'external_id' => null,
            'rating' => $rating,
            'ratings_count' => $ratingsCount,
            'reviews_count' => $reviewsCount,
            'source_hash' => null,
        ];
    }

    private function extractReviews(array $documents, string $sourceUrl): array
    {
        $reviews = [];

        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }

            foreach ($this->walk($document) as $node) {
                $body = null;

                foreach (self::REVIEW_BODY_KEYS as $key) {
                    if (! array_key_exists($key, $node)) {
                        continue;
                    }

                    $body = $this->textValue($node[$key]);

                    if ($body !== null && mb_strlen($body) >= 2) {
                        break;
                    }
                }

                $rating = $this->numberField($node, ['ratingValue', 'rating', 'score', 'stars']);

                if ($body === null || $rating === null || $rating < 1 || $rating > 5) {
                    continue;
                }

                $author = $this->textField($node, ['authorName', 'userName', 'author', 'user', 'name'])
                    ?: 'Неизвестный автор';
                $publishedAt = $this->dateValue($node, self::REVIEW_DATE_KEYS);
                $externalId = $this->textField($node, ['reviewId', 'uid', 'id', 'url'])
                    ?: sha1($author.'|'.$publishedAt.'|'.$body);

                $reviews[$externalId] = [
                    'external_id' => $externalId,
                    'author' => mb_substr($author, 0, 255),
                    'author_avatar_url' => $this->textField($node, ['avatar', 'avatarUrl', 'authorAvatar']),
                    'published_at' => $publishedAt,
                    'body' => $body,
                    'rating' => (int) round($rating),
                    'source_url' => $sourceUrl,
                    'content_hash' => sha1($author.'|'.$publishedAt.'|'.$body),
                ];
            }
        }

        return array_values($reviews);
    }

    private function mergeOrganization(array $current, array $incoming): array
    {
        foreach (['name', 'address', 'external_id', 'rating', 'source_hash'] as $key) {
            if (($current[$key] ?? null) === null && ($incoming[$key] ?? null) !== null) {
                $current[$key] = $incoming[$key];
            }
        }

        foreach (['ratings_count', 'reviews_count'] as $key) {
            if ((int) ($current[$key] ?? 0) === 0 && (int) ($incoming[$key] ?? 0) > 0) {
                $current[$key] = (int) $incoming[$key];
            }
        }

        return $current;
    }

    private function notify(?Closure $onProgress, int $processed, ?int $total): void
    {
        if ($onProgress !== null) {
            $onProgress($processed, $total);
        }
    }

    private function walk(array $value): iterable
    {
        yield $value;

        foreach ($value as $child) {
            if (! is_array($child)) {
                continue;
            }

            foreach ($this->walk($child) as $node) {
                yield $node;
            }
        }
    }

    private function textField(array $node, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $node)) {
                continue;
            }

            $value = $this->textValue($node[$key]);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function textValue(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $value = $this->normalizeText((string) $value);

            return $value === '' ? null : $value;
        }

        if (is_array($value)) {
            foreach (['text', 'value', 'content', 'name', 'formatted', 'title', 'url', 'avatarUrl'] as $key) {
                if (array_key_exists($key, $value)) {
                    $text = $this->textValue($value[$key]);

                    if ($text !== null) {
                        return $text;
                    }
                }
            }
        }

        return null;
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function numberField(array $node, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $node)) {
                continue;
            }

            $value = $node[$key];

            if (is_array($value)) {
                foreach (['value', 'ratingValue', 'score', 'count'] as $nestedKey) {
                    if (isset($value[$nestedKey])) {
                        $value = $value[$nestedKey];
                        break;
                    }
                }
            }

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function integerField(array $node, array $keys): int
    {
        return (int) round($this->numberField($node, $keys) ?? 0);
    }

    private function organizationId(array $node): ?string
    {
        foreach (['businessId', 'oid', 'id'] as $key) {
            $value = $this->textField($node, [$key]);

            if ($value !== null && preg_match('/^\d+$/', $value)) {
                return $value;
            }
        }

        $uri = $this->textField($node, ['uri']);

        if ($uri !== null && preg_match('/oid=(\d+)/', $uri, $match)) {
            return $match[1];
        }

        return null;
    }

    private function htmlInteger(string $html, string $property): int
    {
        if (! preg_match('/itemprop=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([\d\s\xa0]+)/iu', $html, $match)) {
            return 0;
        }

        return (int) preg_replace('/\D+/u', '', $match[1]);
    }

    private function dateValue(array $node, array $keys): ?string
    {
        $value = $this->textField($node, $keys);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
